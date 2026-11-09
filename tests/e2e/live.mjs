#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet) with a
 * real SUPERTEXT_API_KEY. The demo's product editor opens every sample product and clicks
 * "Translate with Supertext" (the action button on the product page), then the database is
 * checked: every multilingual text field (name, description, long description, the attribute
 * "Tasting notes") is filled in every additional language, at least 80 % of them changed, no
 * protocol leftovers, line breaks, formatting and links kept, the text is in the target
 * language (de-CH without "ß"), the products' status unchanged. Translating again keeps the
 * translations (the demo's action doesn't overwrite) and creates nothing new.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/atropim_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.BASE_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what', 'from', 'a'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'unserer', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was', 'aus', 'bei'],
	fr: ['le', 'la', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que', 'au', 'aux'],
	it: ['il', 'lo', 'la', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'dei', 'di', 'che', 'chi', 'da'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
// What the HTML fields must keep (counted), and the line breaks of plain-text fields.
const MARKUP = [['links', '<a '], ['bold', '<strong'], ['italics', '<em'], ['paragraphs', '<p'], ['list items', '<li']];

// The multilingual text fields of the demo's products: column of the main language → kind.
const PRODUCT_FIELDS = { name: 'text', description: 'text', long_description: 'html' };

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];

/** Everything the plugin may write, per product: product row and its attribute values. */
async function snapshot() {
	const products = await q('SELECT * FROM product WHERE deleted = 0 ORDER BY number');
	const values = products.length ? await q('SELECT * FROM product_attribute_value WHERE deleted = 0 AND product_id IN (?) ORDER BY id', [products.map((p) => p.id)]) : [];
	return { products, values };
}

/** Every translatable field of one product as [label, source, columnOf(locale), row, kind]. */
function fieldsOf(snap, product) {
	const out = Object.entries(PRODUCT_FIELDS).map(([col, kind]) => [`${product.number}.${col}`, product[col], (l) => `${col}_${l}`, product, kind]);
	for (const v of snap.values.filter((x) => x.product_id === product.id && String(x.text_value ?? '').trim())) {
		out.push([`${product.number}.attribute#${v.attribute_id}`, v.text_value, (l) => `text_value_${l}`, v, 'text']);
	}
	return out;
}

// --- Setup -------------------------------------------------------------------------------
// The additional languages (AtroCore codes, e.g. de_CH), from the product's language columns
// (name_de_ch); E2E_LANGUAGES overrides. The main language is the source.
const targets = process.env.E2E_LANGUAGES
	? process.env.E2E_LANGUAGES.split(',').map((l) => l.trim()).filter(Boolean)
	: (await q("SHOW COLUMNS FROM product LIKE 'name\\_%'"))
		.map((c) => c.Field.match(/^name_([a-z]{2,3})_([a-z0-9]+)$/))
		.filter(Boolean)
		.map(([, lang, region]) => `${lang}_${region.toUpperCase()}`)
		.sort();
const before = await snapshot();
console.log(`Products: ${before.products.map((p) => p.number).join(', ')}; attribute values: ${before.values.length}; targets: ${targets.join(', ')}`);
check('demo has additional languages', targets.length > 0);
check('demo has sample products', before.products.length > 0);
const [action] = await q("SELECT id FROM action WHERE type = 'supertextTranslate' AND deleted = 0");
check('demo has the action "Translate with Supertext"', !!action);
const untranslated = before.products.flatMap((p) => fieldsOf(before, p)).every(([, , col, row]) => targets.every((l) => !String(row[col(l.toLowerCase())] ?? '').trim()));
check('fresh demo (nothing translated yet)', untranslated);
if (failures.length) throw new Error(failures.join('\n'));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(60_000);
page.on('pageerror', (e) => console.log(`note page error: ${e.message}`));

/** Opens the product and clicks the action button; returns the action's answer. */
async function translateProduct(product, label) {
	await page.goto(`${B}/#Product/view/${product.id}`);
	const button = page.getByRole('button', { name: 'Translate with Supertext' });
	await button.first().waitFor();
	await page.waitForTimeout(1500);
	const started = Date.now();
	const [response] = await Promise.all([
		page.waitForResponse((r) => r.url().includes('/supertextTranslate') && r.request().method() === 'POST', { timeout: 900_000 }),
		button.first().click(),
	]);
	let body = {};
	try { body = await response.json(); } catch { body = { message: await response.text().catch(() => '') }; }
	await page.waitForTimeout(1500);
	const errorToasts = await page.locator('.toastify.toast-error, .toastify.toast-danger').allTextContents();
	await page.screenshot({ path: `${artifacts}${label}-${product.number}.png`, fullPage: true });
	const message = String(body.message ?? '');
	console.log(`${label} ${product.number}: ${Math.round((Date.now() - started) / 1000)} s, HTTP ${response.status()}: ${message}`);
	check(`${label} ${product.number}: action answered without error`, response.ok() && body.success === true, `HTTP ${response.status()} ${message}`);
	check(`${label} ${product.number}: no error message in the UI`, errorToasts.length === 0, errorToasts.join(' | '));
	return message;
}

/** "de_CH, fr_CH, it_CH: translated (4 fields)" → every target with the expected outcome. */
function checkSummary(label, product, message, expected) {
	const parts = message.split(/;|\n/).map((s) => s.trim()).filter(Boolean);
	for (const l of targets) {
		const part = parts.find((p) => p.split(':')[0].split(',').map((s) => s.trim()).includes(l));
		check(`${label} ${product.number}: ${l} ${expected.source}`, !!part && expected.test(part), part ?? 'not in the message');
	}
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/`);
await page.fill('#field-username', need('EDITOR_EMAIL'));
await page.fill('#field-password', need('EDITOR_PASSWORD'));
await page.keyboard.press('Enter');
const loggedIn = await page.locator('#field-username').waitFor({ state: 'detached', timeout: 60_000 }).then(() => true, () => false);
if (!check('editor can log in (DEMO_EDITOR_*)', loggedIn)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}
await page.waitForTimeout(3000);

// --- First translation -------------------------------------------------------------------
for (const product of before.products) {
	checkSummary('first-run', product, await translateProduct(product, 'first-run'), /: translated \(\d+ fields?\)$/);
}
const first = await snapshot();

for (const lang of targets) {
	const l = lang.toLowerCase();
	const short = l.split('_')[0];
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const product of first.products) {
		const old = before.products.find((p) => p.id === product.id);
		check(`${lang}: ${product.number} status unchanged`, product.status === old.status, `${old.status} → ${product.status}`);
		if ('is_active' in product) check(`${lang}: ${product.number} activity unchanged`, product.is_active === old.is_active);
		for (const [name, source, col, row, kind] of fieldsOf(first, product)) {
			if (!plain(source).trim()) continue;
			fields++;
			const v = String(row[col(l)] ?? '');
			if (!check(`${lang}: ${name} filled`, plain(v).trim() !== '')) continue;
			if (v.trim() !== String(source).trim()) changed++;
			else console.log(`note ${lang}: ${name} unchanged ("${v}")`);
			text += ` ${plain(v)}`;
			const left = LEFTOVERS.filter((x) => v.includes(x));
			check(`${lang}: ${name} has no protocol leftovers`, left.length === 0, left.join(', '));
			if (kind === 'html') {
				for (const [what, needle] of MARKUP) {
					if (count(source, needle)) check(`${lang}: ${name} keeps its ${what}`, count(v, needle) === count(source, needle), `${count(v, needle)}/${count(source, needle)}`);
				}
				for (const href of String(source).match(/href="[^"]*"/g) ?? []) check(`${lang}: ${name} keeps the link ${href}`, v.includes(href));
			} else {
				check(`${lang}: ${name} has no HTML tags`, !/<\/?[a-z][^>]*>/i.test(v), v);
				if (count(source, '\n')) check(`${lang}: ${name} keeps its line breaks`, count(v.trim(), '\n') === count(String(source).trim(), '\n'), `${count(v.trim(), '\n')}/${count(String(source).trim(), '\n')}`);
			}
		}
	}
	check(`${lang}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	if (STOPWORDS[short]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((x) => [x, score(text, x)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${lang}: text is in the target language`, best === short, JSON.stringify(scores));
	}
	if (l === 'de_ch') check(`${lang}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}

// --- Translating again: the demo's action keeps existing translations ----------------------
for (const product of first.products) {
	checkSummary('second-run', product, await translateProduct(product, 'second-run'), /: kept, already translated$/);
}
const second = await snapshot();
check('translating again creates no products', second.products.length === first.products.length, `${first.products.length} → ${second.products.length}`);
check('translating again creates no attribute values', second.values.length === first.values.length, `${first.values.length} → ${second.values.length}`);
for (const product of second.products) {
	const prev = first.products.find((p) => p.id === product.id);
	for (const lang of targets) {
		const l = lang.toLowerCase();
		const now = fieldsOf(second, product);
		const was = fieldsOf(first, prev);
		const same = now.every(([name, , col, row]) => {
			const old = was.find(([n]) => n === name);
			return old && String(row[col(l)] ?? '') === String(old[3][old[2](l)] ?? '');
		});
		check(`second-run ${product.number}: ${lang} translations kept as they were`, same);
	}
}

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
