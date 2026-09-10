/**
 * "Four together, please", driven in Chromium.
 *
 * The check that matters is the one a buyer would notice: press a number, get that many seats side
 * by side in one row, held and priced, without touching the plan. And that the box office window
 * gets the same answer from the same code.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node together_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/together-shots';

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

console.log( 'From the website, without touching the plan' );
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await page.locator( '.event-card' ).first().click();
await page.waitForSelector( '.seatmap-widget' );
await page.waitForTimeout( 800 );

check( 'the shortcut is offered before the plan',
	await page.locator( '.seatmap-widget__together-go' ).isVisible() );

await page.screenshot( { path: `${ SHOTS }/01-picker.png` } );

await page.selectOption( '.seatmap-widget__together-count', '4' );
await page.click( '.seatmap-widget__together-go' );
await page.waitForURL( /checkout/, { timeout: 20000 } );

const lines = await page.locator( '.summary-lines li' ).allInnerTexts();
const seats = lines.filter( ( line ) => /·/.test( line ) );

check( 'four seats came back', 4 === seats.length, `${ seats.length } lines` );

// "Stalls · A · 5" — one row, and consecutive numbers in it. Together means adjacent, not
// merely in the same row.
const parts = seats.map( ( line ) => line.split( '\n' )[ 0 ].split( '·' ).map( ( s ) => s.trim() ) );
const rows = new Set( parts.map( ( p ) => p.slice( 0, 2 ).join( ' ' ) ) );
const numbers = parts.map( ( p ) => Number( p[ 2 ] ) ).sort( ( a, b ) => a - b );
const run = numbers.every( ( n, i ) => 0 === i || n === numbers[ i - 1 ] + 1 );

check( 'all in one row', 1 === rows.size, [ ...rows ].join( ' / ' ) );
check( 'and side by side', run, numbers.join( ', ' ) );

await page.screenshot( { path: `${ SHOTS }/02-checkout.png` } );

console.log( 'And at the window' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=counter]' );
await page.waitForSelector( '#counter-event' );
await page.selectOption( '#counter-event', { label: 'Opening night' } );
await page.waitForSelector( '#counter-find' );

await page.fill( '#counter-together', '3' );
await page.click( '#counter-find' );
await page.waitForTimeout( 1200 );

const basket = await page.locator( '.counter__lines li' ).allInnerTexts();

check( 'the counter fills the basket in one press', 3 === basket.length,
	basket.map( ( line ) => line.split( '\n' )[ 0 ] ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/03-counter.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SEATS-TOGETHER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
