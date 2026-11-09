#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH demo whose module
 * talks to stand-in.mjs, like movie.mjs. An administrator installs the module (terminal: the
 * repository in composer.json, AtroCore's installer), sees it under Updates & Modules and the
 * languages, enters the API key in the Supertext connection, checks the German language mapping,
 * saves (API key check), looks at the action and shows where editors find "Translate with
 * Supertext". See docs/DEVELOPER.md → Demo movie.
 *
 *   BASE_URL=http://127.0.0.1:8095 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… node movie-install.mjs
 *
 * The key typed into the connection is made up (st-demo-0000-0000-0000; the stand-in accepts any
 * key). MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * movie/out/AtroPIM-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8095').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/AtroPIM-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await page.goto(`${B}/`)
await page.fill('#field-username', need('DEMO_ADMIN_EMAIL'))
await page.fill('#field-password', need('DEMO_ADMIN_PASSWORD'))
await page.keyboard.press('Enter')
await page.locator('#field-username').waitFor({ state: 'detached', timeout: 60_000 })
await page.waitForTimeout(3000)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'atropim-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
const cell = (name) => page.locator(`.cell[data-name=${name}]`).first()
const hideToasts = () => page.addStyleTag({ content: '*{caret-color:transparent!important} .toastify:not(.toast-success){display:none!important}' })

/** The entry for #hash in the Administration menu on the left (a jqTree item, not a link). */
async function menu(hash) {
  return page.locator(`.jqtree-title[data-id="#${hash}"]:visible`).first()
}
/** Opens a page from the Administration menu and waits for `ready`. */
async function open(hash, ready) {
  await kit.click(await menu(hash))
  await page.locator(ready).first().waitFor({ timeout: 60_000 })
  await page.waitForTimeout(1200)
  await kit.sync()
}

/** More commands in the terminal kit.terminal() opened (same look). */
async function typeMore(lines) {
  await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove())
  for (const l of lines) {
    if (l.cmd !== undefined) {
      await page.evaluate(() => {
        const x = document.getElementById('x')
        const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p)
        const c = document.createElement('span'); c.className = 'c'; x.appendChild(c)
      })
      for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch }, ch); await page.waitForTimeout(38) }
      await page.waitForTimeout(450)
      await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n') })
    } else {
      await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s) }, l.out ?? '')
      await page.waitForTimeout(l.wait ?? 180)
    }
  }
  await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c) })
}

// 1. Terminal: the steps from docs/INSTALLATION.md (output shortened).
await kit.say("First, add the module's repository to your AtroCore project's composer dot json.", {
  caption: 'Add the repository to <b>composer.json</b>',
  during: () => kit.terminal({ title: 'my-site', lines: [
    { cmd: 'cat composer.json' },
    { out: '  "repositories": [' },
    { out: '    {"type": "composer", "url": "https://packagist.atrocore.com/…"},' },
    { out: '    {"type": "vcs",' },
    { out: '     "url": "https://github.com/Supertext/AtroPIM-Supertext-Translation"}' },
    { out: '  ],' },
  ] }),
})
await kit.say("Then install it with AtroCore's installer. It registers the module, updates the database, and clears the cache.", {
  caption: 'Install it with <b>atrocore-installer.phar require</b>',
  during: () => typeMore([
    { cmd: 'php atrocore-installer.phar require supertext/atropim-supertext-translation:dev-main' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/atropim-supertext-translation (dev-main)' },
    { out: 'Generating autoload files' },
    { out: 'Copying frontend files … Done!' },
  ]),
})
await kit.hold(300)

/** Clicks (the cursor is already there when `moved`), then cuts the time until `ready` is there. */
async function clickAndLoad(locator, ready, moved = false) {
  if (moved) {
    await ripple()
    await locator.click({ noWaitAfter: true })
  } else await kit.click(locator, { noWaitAfter: true })
  kit.pause()
  await ready.waitFor({ timeout: 60_000 })
  await page.waitForTimeout(1500)
  await kit.sync()
  kit.resume()
}
// 2. The back end as administrator: the module and the languages (loading time cut).
kit.pause()
await page.goto(`${B}/#SoftwarePackage`)
await page.getByText('Supertext Translation', { exact: true }).first().waitFor({ timeout: 60_000 })
await page.waitForTimeout(2000)
await hideToasts()
await kit.sync()
kit.resume()
await kit.say('Reload AtroPIM: Updates and Modules now lists Supertext Translation.', {
  caption: '<b>Administration → Updates & Modules</b>: Supertext Translation',
  during: async () => {
    await kit.hold(500)
    await kit.moveTo(page.getByText('Supertext Translation', { exact: true }).first())
    await kit.hold(1200)
    await kit.moveTo(await menu('Language'))
  },
  after: 0,
})
await clickAndLoad(await menu('Language'), page.locator('.list table a', { hasText: 'Deutsch (Schweiz)' }).first(), true)
await kit.say('Supertext translates between your AtroCore languages: here English, German, French and Italian.', {
  caption: 'Your languages: <b>Administration → Languages</b>',
  during: async () => {
    await kit.moveTo(page.locator('.list table a', { hasText: 'Deutsch (Schweiz)' }).first())
    await kit.hold(1200)
    await kit.moveTo(await menu('Connection'))
  },
  after: 0,
})

// 3. The Supertext connection: API key and language mapping (loading time cut).
const supertextRow = page.locator('.list table a', { hasText: /^Supertext$/ }).first()
await clickAndLoad(await menu('Connection'), supertextRow, true)
await kit.say('The API key goes into a connection of type Supertext, under Connections.', {
  caption: '<b>Administration → Connections</b> → <b>Supertext</b>',
  during: () => kit.moveTo(supertextRow),
  after: 0,
})
await clickAndLoad(supertextRow, cell('supertextKeyCheck'), true)
const keyInput = cell('supertextApiKey').locator('input[name=supertextApiKey]')
await kit.say('Click Edit. Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com. Then paste the key here.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b> (Admin role)<br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => {
    await kit.click(page.getByRole('button', { name: 'Edit', exact: true }).first())
    await cell('supertextApiKey').locator('a[data-action=change]').waitFor()
    await kit.sync()
    await kit.click(cell('supertextApiKey').locator('a[data-action=change]'))
    await keyInput.waitFor()
    await keyInput.pressSequentially(FAKE_KEY, { delay: 45 })
  },
})
const deCombo = cell('supertextCodeDeCh').locator('.selectize-input')
await kit.say('Each language has its Supertext language, already preselected. Open one to change it; we keep German, Switzerland.', {
  caption: '<b>Supertext language for Deutsch (Schweiz)</b>: <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await cell('supertextCodeDeCh').evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await kit.hold(900)
    await kit.click(deCombo)
    await kit.hold(900)
    const option = page.locator('.selectize-dropdown:visible .option', { hasText: '(de-CH)' }).first()
    await kit.moveTo(option)
    await option.hover()
    await kit.hold(400)
    await ripple()
    await option.click()
    await kit.hold(300)
  },
})
await kit.say('Translation review can be ticked per language. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per language (left off)',
  during: async () => { await kit.hold(300); await kit.moveTo(cell('supertextReviewDeCh').locator('input')) },
})
const save = page.getByRole('button', { name: 'Save', exact: true }).first()
await kit.say('Save. Saving checks the API key with Supertext.', {
  caption: 'Click <b>Save</b>',
  during: () => kit.moveTo(save),
  after: 0,
})
await clickAndLoad(save, page.getByRole('button', { name: 'Edit', exact: true }).first(), true)
await kit.say('The API key check shows that the connection to Supertext works.', {
  caption: 'API key check: <b>the connection to Supertext works</b>',
  during: async () => {
    await cell('supertextKeyCheck').evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(cell('supertextKeyCheck').locator('label'))
  },
})

// 4. The action editors use.
await kit.say('Last, create an action of type Translate with Supertext for products, under Actions.', {
  caption: '<b>Administration → Actions</b>: type <b>Translate with Supertext</b>, for products',
  during: async () => {
    await open('Action', '.list table')
    await kit.moveTo(page.locator('.list table a', { hasText: /^Translate with Supertext$/ }).first())
  },
})

// 5. Where editors find it.
kit.pause()
const praline = await page.evaluate(async () => {
  const r = await fetch('api/Product?select=id&where[0][type]=equals&where[0][attribute]=number&where[0][value]=praline-box-16')
  return (await r.json()).list[0].id
})
await page.goto(`${B}/#Product/view/${praline}`)
const button = page.getByRole('button', { name: 'Translate with Supertext' })
await button.waitFor({ timeout: 60_000 })
await page.waitForTimeout(2000)
await hideToasts()
await kit.sync()
kit.resume()
await kit.say("That's it. Editors now find Translate with Supertext on every product.", {
  caption: 'Done: editors find <b>Translate with Supertext</b> on every product',
  during: async () => { await kit.hold(400); await kit.moveTo(button); await button.hover() },
  after: 0,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'AtroPIM', repo: 'AtroPIM-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
