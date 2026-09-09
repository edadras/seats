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
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';

// The theatre, not the warehouse: the last stretch of this prices individual chairs, and the demo
// deliberately contains a room that has none.
const seated = await seatedEvent( BASE, 'pricing' );
const priceButton = `[data-prices="${ seated.id }"]`;

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
await page.waitForSelector( priceButton );
check( 'a price column', ( await page.locator( 'th', { hasText: 'Prices' } ).count() ) === 1 );
console.log( '   row now reads:', ( await page.locator( 'tbody tr' ).first().innerText() ).replace( /\n/g, ' | ' ) );

console.log( 'Price screen' );
await page.locator( priceButton ).click();
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
await page.waitForSelector( priceButton );
await page.waitForTimeout( 400 );

const row = await page.locator( 'tbody tr' ).first().innerText();
check( 'the list shows the saved price, undivided', /500,000/.test( row ), row.replace( /\n/g, ' | ' ) );

console.log( 'Persian' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'fa' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( priceButton );
await page.waitForTimeout( 400 );

const dir = await page.evaluate( () => document.documentElement.getAttribute( 'dir' ) );
check( 'the panel turns round', dir === 'rtl', `dir=${ dir }` );

const faRow = await page.locator( 'tbody tr' ).first().innerText();
check( 'the price is written in Persian digits', /۵۰۰٬۰۰۰/.test( faRow ), faRow.replace( /\n/g, ' | ' ) );
check( 'the action button is Persian', ( await page.locator( priceButton ).innerText() ).includes( 'قیمت' ) );

await page.locator( priceButton ).click();
await page.waitForSelector( '#pricing-currency' );
const heading = await page.locator( '.page-head' ).innerText();
check( 'the price screen is Persian', heading.includes( 'قیمت' ), heading.replace( /\n/g, ' | ' ) );
console.log( 'Individual seats' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'en' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( priceButton );
await page.locator( priceButton ).click();
await page.waitForSelector( '#pricing-seats' );
await page.click( '#pricing-seats' );

await page.waitForSelector( '[data-section]' );
check( 'the hall arrives as blocks', ( await page.locator( '[data-section]' ).count() ) > 0,
	`${ await page.locator( '[data-section]' ).count() } sections` );

await page.locator( '[data-section]' ).first().click();
await page.waitForSelector( '[data-seat]' );
const seats = await page.locator( '[data-seat]' ).count();
check( 'the block opens onto its chairs', seats > 0, `${ seats } seats` );

const labels = ( await page.locator( '[data-seat]' ).allInnerTexts() ).slice( 0, 4 ).join( ',' );
check( 'a row is walked, not sorted as strings', labels === '1,2,3,4', labels );

// One chair, then a run of them: "12 to 18" is how a box office speaks.
await page.locator( '[data-seat]' ).first().click();
await page.locator( '[data-seat]' ).nth( 3 ).click( { modifiers: [ 'Shift' ] } );
check( 'shift takes the run in between',
	( await page.locator( '.seat-bar__count' ).innerText() ).includes( '4' ),
	await page.locator( '.seat-bar__count' ).innerText() );

await page.fill( '#seat-amount', '900000' );
await page.click( '#seat-apply' );
await page.waitForTimeout( 150 );
check( 'the chairs are marked as carrying their own price',
	( await page.locator( '.chip--own' ).count() ) === 4,
	`${ await page.locator( '.chip--own' ).count() } marked` );

await page.click( '#seats-save' );
await page.waitForTimeout( 900 );

const priced = await page.evaluate( async () => {
	const token = window.sessionStorage.getItem( 'seatmap_token' );
	const id = window.SeatmapSeatPrices.eventId;
	const body = await fetch( `/v1/events/${ id }/seat-prices`, {
		headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
	} ).then( ( r ) => r.json() );

	return body.sections
		.flatMap( ( s ) => s.rows )
		.flatMap( ( r ) => r.seats )
		.filter( ( s ) => s.own_amount === 900000 ).length;
} );
check( 'the server kept all four, and only those', priced === 4, `${ priced } seats` );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/seats.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
