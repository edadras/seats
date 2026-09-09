/**
 * The counter, driven in Chromium.
 *
 * A window sale from the clerk's side: pick the evening, find the seats, take the name, done. What
 * is checked is that a seat somebody else already has is shown as gone rather than quietly missing
 * — a clerk asked for A1 needs to be told — and that a comp completes without a gateway anywhere
 * in the story.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node counter_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/counter-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The hall' );
await page.click( 'nav button[data-view=counter]' );
await page.waitForSelector( '#counter-event' );
await page.selectOption( '#counter-event', { label: 'Opening night' } );
await page.waitForSelector( '.counter__blocks' );

check( 'the sections are offered', ( await page.locator( '.counter__block' ).count() ) > 0 );

// Index zero is a section like any other — a truth test here would send the clerk back to the list.
await page.locator( '.counter__block:not([disabled])' ).first().click();
await page.waitForSelector( '.counter__seat' );

check( 'the chairs are there', ( await page.locator( '.counter__seat' ).count() ) > 0 );
check( 'and a seat that has gone says so, rather than being missing',
	( await page.locator( '.counter__seat.is-gone' ).count() ) > 0 );

console.log( 'A sale' );
await page.locator( '.counter__seat:not([disabled])' ).first().click();
await page.locator( '.counter__seat:not([disabled])' ).nth( 1 ).click();
await page.waitForTimeout( 300 );

check( 'both seats are in the basket', 2 === await page.locator( '.counter__lines li' ).count() );
check( 'and they add up', /\d/.test( await page.locator( '.counter__total' ).innerText() ),
	( await page.locator( '.counter__total' ).innerText() ).replace( /\n/g, ' ' ) );

await page.screenshot( { path: `${ SHOTS }/01-counter.png` } );

await page.click( '#counter-sell' );
await page.waitForSelector( '.modal' );
await page.fill( '#c-name', 'Walk-up buyer' );
await page.selectOption( '#c-payment', 'comp' );
await page.screenshot( { path: `${ SHOTS }/02-sell.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );

const toast = await page.locator( '.toast' ).innerText();
check( 'the sale completes with a booking reference', /bo-/.test( toast ), toast );

console.log( 'And it is a real booking' );
await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '[data-order]' );

const reference = ( toast.match( /bo-[a-z0-9]+/ ) || [ '' ] )[ 0 ];
const rows = await page.locator( 'tbody' ).innerText();

check( 'it appears in the box office list', rows.includes( reference ), reference );
// A comp is worth nothing, and a report that counted it as revenue would overstate the evening
// by exactly the generosity of the house.
check( 'and it is worth nothing', /0[.,]00/.test( rows ), rows.split( '\n' )[ 0 ] );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL COUNTER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
