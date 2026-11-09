#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo (no translations
 * yet) whose module talks to stand-in.mjs, like screenshots.mjs. The demo editor opens the
 * sample product in English, clicks "Translate with Supertext" and checks the German texts.
 * See docs/DEVELOPER.md → Demo movie.
 *
 *   BASE_URL=http://127.0.0.1:8095 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node movie.mjs
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * movie/out/AtroPIM-Supertext-Translation.mp4, git-ignored; the MP4 is not committed).
 * CHROMIUM_PATH: another Chromium. Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8095').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/AtroPIM-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo's product editor (not recorded).
await page.goto(`${B}/`)
await page.fill('#field-username', need('DEMO_EDITOR_EMAIL'))
await page.fill('#field-password', need('DEMO_EDITOR_PASSWORD'))
await page.keyboard.press('Enter')
await page.locator('#field-username').waitFor({ state: 'detached', timeout: 60_000 })
await page.waitForTimeout(3000)

const praline = await page.evaluate(async () => {
  const r = await fetch('api/Product?select=id&where[0][type]=equals&where[0][attribute]=number&where[0][value]=praline-box-16')
  return (await r.json()).list[0].id
})
const button = page.getByRole('button', { name: 'Translate with Supertext' })
await page.goto(`${B}/#Product/view/${praline}`)
await button.waitFor({ timeout: 60_000 })
await page.waitForTimeout(2500)
await page.addStyleTag({ content: '*{caret-color:transparent!important}' })

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'atropim-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const cell = (name) => page.locator(`.cell[data-name=${name}]`).first()

// 1. The product in English.
await kit.say("Here's a product in AtroPIM, in English: a box of handmade pralines.", {
  caption: 'A product in English',
  during: () => kit.moveTo(cell('name').locator('.field')),
})
await kit.say('Its German, French and Italian names and descriptions are still empty.', {
  caption: 'German, French and Italian: still empty',
  during: () => kit.moveTo(cell('nameDeCh').locator('.field')),
})

// 2. Translate with Supertext. The stand-in answers at once: the request goes out only after
// the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/\/api\//, async (route) => {
  if (route.request().method() !== 'GET') await released
  await route.continue()
})
await kit.say('At the top, click Translate with Supertext.', {
  caption: 'Click <b>Translate with Supertext</b>',
  during: () => kit.moveTo(button),
  after: 0,
})
const done = page.waitForFunction(() => [...document.querySelectorAll('.toastify')].some((e) => /translated/i.test(e.textContent)), null, { timeout: 300_000, polling: 100 })
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
await kit.say('Supertext translates the product into German, French and Italian.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await button.click()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await done
await page.unroute(/\/api\//)
await page.waitForTimeout(1500)
kit.resume()
await kit.sync()

// 3. The result, right in the product.
await kit.say('A few seconds later, the translations are in the product.', {
  caption: 'Done: German, French and Italian are filled in',
  during: async () => {
    await kit.hold(600)
    await kit.moveTo(cell('nameDeCh').locator('.field'))
  },
})
await kit.say("Here's the German description. Review it, and set the product live as usual.", {
  caption: 'The German version is ready – review it',
  during: async () => {
    await page.evaluate(() => document.querySelectorAll('.toastify').forEach((t) => t.remove()))
    await cell('descriptionDeCh').evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(cell('descriptionDeCh').locator('label'))
  },
  after: 0,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'AtroPIM', repo: 'AtroPIM-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
