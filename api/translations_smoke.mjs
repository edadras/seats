/**
 * An event's own words in six languages, driven in Chromium.
 *
 * The check that matters is the fallback: an organiser who writes Persian has not thereby broken
 * their French page. So this writes one language, reads the site in it, and reads the site in a
 * language nobody wrote.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node translations_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/translations-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Writing it in Persian' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-words]' );

const row = page.locator( 'tbody tr' ).filter( { has: page.locator( '[data-words]' ) } ).first();
const publicId = ( await row.locator( 'code' ).innerText() ).trim();

await row.locator( '[data-words]' ).click();
await page.waitForSelector( '#tr-locale' );

await page.selectOption( '#tr-locale', 'fa' );
await page.waitForTimeout( 200 );
await page.fill( '#tr-name', 'شب افتتاحیه' );
await page.fill( '#tr-description', 'آغاز فصل تازه.' );

// Move away and back: what was typed has to survive the picker, or nobody uses it twice.
await page.selectOption( '#tr-locale', 'de' );
await page.waitForTimeout( 200 );
await page.fill( '#tr-name', 'Premierenabend' );
await page.selectOption( '#tr-locale', 'fa' );
await page.waitForTimeout( 200 );

check( 'what was typed survives switching language',
	'شب افتتاحیه' === await page.locator( '#tr-name' ).inputValue() );

await page.screenshot( { path: `${ SHOTS }/01-editor.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1200 );

console.log( 'Reading it back on the website' );
await page.goto( `${ SITE }/events/${ publicId }?lang=fa`, { waitUntil: 'networkidle' } );

const persian = await page.locator( 'body' ).innerText();

check( 'the page is in the reader’s language', /شب افتتاحیه/.test( persian ) );
check( 'and so is the description', /آغاز فصل تازه/.test( persian ) );
await page.screenshot( { path: `${ SHOTS }/02-site-fa.png` } );

await page.goto( `${ SITE }/events/${ publicId }?lang=de`, { waitUntil: 'networkidle' } );
check( 'German too', /Premierenabend/.test( await page.locator( 'body' ).innerText() ) );

await page.goto( `${ SITE }/events/${ publicId }?lang=fr`, { waitUntil: 'networkidle' } );

const french = await page.locator( 'body' ).innerText();

// Nobody wrote French. The page is still a page.
check( 'a language nobody wrote falls back to the original',
	/Opening night|Late night/.test( french ) && ! /undefined/.test( french ) );
await page.screenshot( { path: `${ SHOTS }/03-site-fr.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL TRANSLATION CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
