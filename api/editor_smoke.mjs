/**
 * Browser smoke test for the tenant panel and seat map editor.
 *
 * Drives the real UI in Chromium: sign in, read stats, open a published map, add rows, undo, redo,
 * marquee-select, publish, and confirm the server refuses a map with an off-canvas seat.
 *
 * It edits and publishes the seeded map, so re-seed before each run:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node editor_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
let failures = 0;
const check = (label, ok, detail = '') => {
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? ' — ' + detail : ''}`);
  if (!ok) failures++;
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

console.log('Panel: sign in');
await page.goto(BASE, { waitUntil: 'networkidle' });
check('login form rendered', await page.locator('#login').isVisible());

await page.fill('input[name=email]', 'owner@northgate.test');
await page.fill('input[name=password]', 'password');
await page.click('#login button[type=submit]');
await page.waitForSelector('.topbar', { timeout: 10000 });
check('signed in, workspace shown', await page.locator('.topbar').isVisible());

console.log('Panel: events list');
await page.waitForSelector('table tbody tr');
const eventRows = await page.locator('table tbody tr').count();
check('events listed', eventRows >= 1, `${eventRows} row(s)`);
const publicId = (await page.locator('table tbody tr code').first().textContent()) || '';
check('public id shown for embedding', publicId.startsWith('evt_'), publicId);

await page.click('table tbody button[data-stats]');
await page.waitForSelector('#stats .stats li');
const seatsTotal = await page.locator('#stats .stats li').first().innerText();
check('stats load', /\d+/.test(seatsTotal), seatsTotal.replace('\n', ' '));

console.log('Editor: open a map');
await page.click('nav button[data-view=maps]');
await page.waitForSelector('button[data-map]');
await page.click('button[data-map]');
await page.waitForSelector('#editor-canvas');
check('editor canvas mounted', await page.locator('#editor-canvas').isVisible());

const seatCount = async () =>
  parseInt(await page.locator('#editor-status .stats li span').first().innerText(), 10);

const initial = await seatCount();
check('published geometry loaded into editor', initial > 100, `${initial} seats`);
check('validation panel reports clean', (await page.locator('#editor-validation .ok').count()) === 1);

console.log('Editor: add a section and rows');
page.once('dialog', d => d.accept('Gallery'));
await page.click('button[data-action=add-section]');
await page.waitForTimeout(200);

// Straight rows prompts for section, rows, seats-per-row and zone in sequence.
const answers = ['Gallery', '4', '12', 'balcony'];
let i = 0;
page.on('dialog', d => d.accept(answers[i++] ?? ''));
await page.click('button[data-action=add-rows]');
await page.waitForTimeout(500);

const afterRows = await seatCount();
check('48 seats added', afterRows === initial + 48, `${initial} -> ${afterRows}`);

console.log('Editor: undo and redo');
await page.click('button[data-action=undo]');
await page.waitForTimeout(200);
check('undo removed the rows', (await seatCount()) === initial, `${await seatCount()}`);

await page.click('button[data-action=redo]');
await page.waitForTimeout(200);
check('redo restored them', (await seatCount()) === afterRows);

console.log('Editor: canvas interaction');
const box = await page.locator('#editor-canvas').boundingBox();
// Marquee-drag across part of the map to select seats.
await page.mouse.move(box.x + 40, box.y + 40);
await page.mouse.down();
await page.mouse.move(box.x + box.width - 40, box.y + box.height - 40, { steps: 12 });
await page.mouse.up();
await page.waitForTimeout(200);

const selected = parseInt(await page.locator('#editor-status .stats li span').nth(2).innerText(), 10);
check('marquee selected seats', selected > 0, `${selected} selected`);

console.log('Editor: publish');
await page.click('button[data-action=publish]');
await page.waitForSelector('.toast', { timeout: 15000 });
const toast = await page.locator('.toast').innerText();
check('publish succeeded', /Published version \d+ with \d+ seats/.test(toast), toast);
check('published count includes the new rows', toast.includes(String(afterRows)), toast);

// From here on the test deliberately provokes a rejection, so the 422 that follows is the
// expected outcome rather than a defect. Anything logged before this point is not.
const errorsBeforeIntentionalFailure = errors.length;
check('no console errors during normal use', errorsBeforeIntentionalFailure === 0, errors.join(' | '));

console.log('Editor: validation blocks a broken publish');
await page.evaluate(() => {
  // Push a seat off the canvas, exactly as a mis-drag would.
  const g = window.__editor.geometry;
  g.sections[0].rows[0].seats[0].x = 99999;
  window.__editor.onChange(g);
  window.__editor.draw();
});
await page.waitForTimeout(200);
check('error surfaced in the side panel', (await page.locator('.issue--error').count()) > 0);

await page.click('button[data-action=publish]');
await page.waitForTimeout(1500);
const errToast = await page.locator('.toast').innerText();
check('server refused the broken map', /outside the canvas|cannot be published|errors/i.test(errToast), errToast);
check('the refusal is an error toast', (await page.locator('.toast--error').count()) === 1);

const unexpected = errors.slice(0, errorsBeforeIntentionalFailure);
console.log('\nUnexpected console errors: ' + (unexpected.length ? unexpected.join(' | ') : 'none'));
if (unexpected.length) failures++;

await page.screenshot({ path: '/tmp/editor.png', fullPage: false });
await browser.close();

console.log(failures === 0 ? '\nALL EDITOR CHECKS PASSED' : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
