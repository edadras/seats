/**
 * Prices, in the browser, in two languages.
 *
 * Sets an event's currency to rials, types a price, saves it, and reads it back off the events
 * list — first in English, then in Persian. The number the list shows is the assertion: a rial
 * divided by a hundred is the failure this exists to catch, and it is the kind of failure that
 * every layer reports as a success on its own.
 *
 * It rewrites the seeded event's prices, so re-seed before each run:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node pricing_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( { executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' } );
const page = await browser.newPage( { viewport: { width: 1500, height: 950 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( m.type() === 'error' ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Events list' );
await page.waitForSelector( '[data-prices]' );
check( 'a price column', ( await page.locator( 'th', { hasText: 'Prices' } ).count() ) === 1 );
console.log( '   row now reads:', ( await page.locator( 'tbody tr' ).first().innerText() ).replace( /\n/g, ' | ' ) );

console.log( 'Price screen' );
await page.locator( '[data-prices]' ).first().click();
await page.waitForSelector( '#pricing-currency' );
check( 'zones seeded from the chart', ( await page.locator( '[data-amount]' ).count() ) > 0,
	`${ await page.locator( '[data-amount]' ).count() } zones` );

await page.fill( '#pricing-currency', 'IRR' );
await page.dispatchEvent( '#pricing-currency', 'change' );
await page.waitForTimeout( 200 );

const step = await page.locator( '[data-amount]' ).first().getAttribute( 'step' );
check( 'a rial has no decimal box', step === '1', `step=${ step }` );

await page.locator( '[data-amount]' ).first().fill( '500000' );
await page.dispatchEvent( '[data-amount]', 'input' );
await page.waitForTimeout( 150 );
const preview = await page.locator( '[data-preview="0"]' ).innerText();
check( 'preview keeps every zero', /500,000/.test( preview ), preview );

const rest = await page.locator( '[data-amount]' ).count();
for ( let i = 1; i < rest; i++ ) {
	await page.locator( '[data-amount]' ).nth( i ).fill( String( 250000 + i ) );
	await page.locator( '[data-amount]' ).nth( i ).dispatchEvent( 'input' );
}

await page.click( '#pricing-save' );
await page.waitForSelector( '[data-prices]' );
await page.waitForTimeout( 400 );

const row = await page.locator( 'tbody tr' ).first().innerText();
check( 'the list shows the saved price, undivided', /500,000/.test( row ), row.replace( /\n/g, ' | ' ) );

console.log( 'Persian' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'fa' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '[data-prices]' );
await page.waitForTimeout( 400 );

const dir = await page.evaluate( () => document.documentElement.getAttribute( 'dir' ) );
check( 'the panel turns round', dir === 'rtl', `dir=${ dir }` );

const faRow = await page.locator( 'tbody tr' ).first().innerText();
check( 'the price is written in Persian digits', /۵۰۰٬۰۰۰/.test( faRow ), faRow.replace( /\n/g, ' | ' ) );
check( 'the action button is Persian', ( await page.locator( '[data-prices]' ).first().innerText() ).includes( 'قیمت' ) );

await page.locator( '[data-prices]' ).first().click();
await page.waitForSelector( '#pricing-currency' );
const heading = await page.locator( '.page-head' ).innerText();
check( 'the price screen is Persian', heading.includes( 'قیمت' ), heading.replace( /\n/g, ' | ' ) );
check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
