#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly set-up demo (no translations yet) whose module talks
 * to stand-in.mjs (SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/) and reports to it too
 * (SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report: never the live Commando Center). See
 * docs/DEVELOPER.md → Docs screenshots.
 *
 *   BASE_URL (default http://127.0.0.1:8095)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     action, connection, languages (administrator)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (the demo's product editor)
 */
import { chromium } from 'playwright'

const B = process.env.BASE_URL || 'http://127.0.0.1:8095'
const OUT = new URL('../../docs/images/', import.meta.url).pathname
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const MAIN = { x: 300, width: 680 } // the record's main column at 1280 px

const browser = await chromium.launch()

async function session(email, password) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, locale: 'en-US' })
  const page = await context.newPage()
  page.on('pageerror', (e) => console.error('page error:', e.message))
  await page.goto(`${B}/`)
  await page.fill('#field-username', email)
  await page.fill('#field-password', password)
  await page.keyboard.press('Enter')
  await page.locator('#field-username').waitFor({ state: 'detached', timeout: 60_000 })
  await page.waitForTimeout(3000)
  return page
}

async function go(page, hash, ready) {
  await page.goto(`${B}/#${hash}`)
  await page.locator(ready).first().waitFor({ timeout: 60_000 })
  await page.waitForTimeout(2500)
  await page.addStyleTag({ content: '*{caret-color:transparent!important} .toastify:not(.toast-success){display:none!important}' })
}

async function shot(page, r, name, margin = 0) {
  const clip = { x: Math.max(0, r.x - margin), y: Math.max(0, r.y - margin), width: r.width + 2 * margin, height: r.height + 2 * margin }
  await page.screenshot({ path: OUT + name, clip })
  console.log('wrote', name)
}

/** Bounding box around several elements, widened to the main column. */
async function around(page, selectors, padding = 12) {
  await page.locator(selectors[0]).first().scrollIntoViewIfNeeded()
  await page.waitForTimeout(600)
  const boxes = []
  for (const s of selectors) boxes.push(await page.locator(s).first().boundingBox())
  const top = Math.min(...boxes.map((b) => b.y)) - padding
  const bottom = Math.max(...boxes.map((b) => b.y + b.height)) + padding
  return { x: MAIN.x, y: top, width: MAIN.width, height: bottom - top }
}

/**
 * Screenshot of the success message containing text. AtroCore hides it after a few seconds, so
 * a copy is pinned where the message appears.
 */
async function successToast(page, text, name) {
  await page.waitForFunction((t) => [...document.querySelectorAll('.toastify.toast-success')].some((e) => e.textContent.includes(t)), text, { timeout: 300_000, polling: 50 })
  await page.evaluate((t) => {
    const toast = [...document.querySelectorAll('.toastify.toast-success')].find((e) => e.textContent.includes(t))
    const copy = toast.cloneNode(true)
    copy.id = 'pinned-toast'
    Object.assign(copy.style, { position: 'fixed', left: '50%', bottom: '24px', top: 'auto', transform: 'translateX(-50%)', transition: 'none', opacity: '1', zIndex: 100000 })
    toast.remove()
    document.body.appendChild(copy)
  }, text)
  await page.waitForTimeout(300)
  await shot(page, await page.locator('#pinned-toast').boundingBox(), name, 8)
}

async function closeToasts(page) {
  await page.evaluate(() => document.querySelectorAll('.toastify, #pinned-toast').forEach((t) => t.remove()))
}

async function idOf(page, entity, field, value) {
  return page.evaluate(async ([e, f, v]) => {
    const r = await fetch(`api/${e}?select=id&where[0][type]=equals&where[0][attribute]=${f}&where[0][value]=${encodeURIComponent(v)}`)
    return (await r.json()).list[0].id
  }, [entity, field, value])
}

// --- Editor: translate a product ----------------------------------------------------------
const editor = await session(need('DEMO_EDITOR_EMAIL'), need('DEMO_EDITOR_PASSWORD'))
const praline = await idOf(editor, 'Product', 'number', 'praline-box-16')
await go(editor, `Product/view/${praline}`, 'button:has-text("Translate with Supertext")')

const names = ['.header-breadcrumbs', '.cell[data-name=name]', '.cell[data-name=nameItCh] .field']
await shot(editor, await around(editor, names), 'product-before.png')

await editor.getByRole('button', { name: 'Translate with Supertext' }).click()
await successToast(editor, 'translated', 'translate-done.png')
await closeToasts(editor)
await shot(editor, await around(editor, names), 'product-translated.png')

await shot(editor, await around(editor, ['.cell[data-name=description]', '.cell[data-name=descriptionItCh]', '.cell[data-name=longDescriptionDeCh]']), 'product-description.png')
await shot(editor, await around(editor, ['.panel[data-name=attributeValues]'], 0), 'product-attribute.png')

// Translating again keeps what is there (the demo's action doesn't overwrite).
await editor.locator('.header-breadcrumbs').scrollIntoViewIfNeeded()
await editor.getByRole('button', { name: 'Translate with Supertext' }).click()
await successToast(editor, 'kept', 'translate-kept.png')
await closeToasts(editor)

// Mass action in the product list.
await go(editor, 'Product', '.list table.full-table')
await editor.locator('.list table.full-table thead input[type=checkbox]').first().check()
await editor.waitForTimeout(500)
await editor.getByRole('button', { name: 'Actions' }).first().click()
await editor.locator('a.mass-action:visible', { hasText: 'Translate with Supertext' }).first().waitFor()
await editor.waitForTimeout(500)
await shot(editor, { x: 300, y: 120, width: 954, height: 380 }, 'mass-action.png')

// --- Administrator: action, connection, languages -----------------------------------------
const admin = await session(need('DEMO_ADMIN_EMAIL'), need('DEMO_ADMIN_PASSWORD'))

const action = await idOf(admin, 'Action', 'type', 'supertextTranslate')
await go(admin, `Action/view/${action}`, '.cell[data-name=supertextConnection]')
await shot(admin, await around(admin, ['.header-breadcrumbs', '.cell[data-name=supertextOverwrite]']), 'action-settings.png')

const connection = await idOf(admin, 'Connection', 'type', 'supertext')
await go(admin, `Connection/view/${connection}`, '.cell[data-name=supertextApiKey]')
await shot(admin, await around(admin, ['.header-breadcrumbs', '.cell[data-name=supertextLanguageStatus]']), 'connection-settings.png')

await go(admin, 'Language', '.list table.full-table')
const table = await admin.locator('.list table.full-table').first().boundingBox()
await shot(admin, { x: 300, y: 120, width: 980, height: table.y + table.height - 120 + 8 }, 'languages.png')

await browser.close()
