/**
 * Concessions, in a browser.
 *
 * An organiser adds a child rate on the pricing screen, and a buyer chooses it for one of two
 * seats on the hosted site. What is checked is the thing that goes wrong when two implementations
 * price the same ticket: the total the picker shows before the hold, and the total the server
 * writes on the hold, have to be the same number.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node ticket_types_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/ticket-type-shots';

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

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

/*
 * Which event to work on.
 *
 * The panel and the site both list events and neither promises the same order, so the buyer's
 * event is chosen first and the organiser's row is then found by name — otherwise this check can
 * price one evening and try to buy another.
 */
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );
shop.on( 'console', ( m ) => { if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() ); } );

await shop.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await shop.locator( '.event-card a, .card a, a[href^="/events/"]' ).first().click();
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

const eventName = ( await shop.locator( 'h1' ).first().innerText() ).trim();
const eventUrl = shop.url();

console.log( 'Adding a child rate to', eventName );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-prices]' );
await page.locator( 'tr', { hasText: eventName } ).locator( '[data-prices]' ).first().click();
await page.waitForSelector( '#pricing-type-add' );

const add = async ( name, kind, value, isDefault ) => {
	await page.click( '#pricing-type-add' );
	await page.waitForSelector( '.modal' );
	await page.fill( '#t-name', name );
	await page.selectOption( '#t-kind', kind );

	if ( 'standard' !== kind ) {
		await page.fill( '#t-value', String( value ) );
	}

	if ( isDefault ) {
		await page.check( '#t-default' );
	} else {
		await page.uncheck( '#t-default' );
	}

	await page.click( '.modal button[type=submit]' );
	await page.waitForSelector( '.modal', { state: 'detached' } );
	await page.waitForTimeout( 400 );
};

await add( 'Adult', 'standard', 0, true );
await add( 'Child', 'percent_off', 50, false );

check( 'both types are listed', 2 === await page.locator( '[data-type-edit]' ).count() );
const badges = await page.locator( 'td .badge' ).allInnerTexts();
check( 'and one of them is the default', 1 === badges.length, badges.join( ', ' ) );
await page.screenshot( { path: `${ SHOTS }/01-ticket-types.png` } );

console.log( 'Choosing one as a buyer' );

// Reloaded, because the page was rendered before the ticket types existed.
await shop.goto( eventUrl, { waitUntil: 'networkidle' } );
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

if ( await shop.locator( '.seatmap-widget__block' ).count() ) {
	const canvas = await shop.locator( '.seatmap-widget__canvas' ).boundingBox();

	await shop.mouse.click( canvas.x + canvas.width / 2, canvas.y + canvas.height / 2 );
	await shop.waitForSelector( '.seatmap-widget__list' );
}

const seatPoint = ( index ) => shop.evaluate( ( i ) => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const seats = widget.seats.filter( ( seat ) =>
		seat.floorKey === widget.floorKey && widget.inOpenBlock( seat ) && 'available' === seat.state );
	const rect = widget.canvas.getBoundingClientRect();
	const scale = widget.baseScale * widget.view.scale;

	return { x: rect.left + seats[ i ].x * scale + widget.view.x, y: rect.top + seats[ i ].y * scale + widget.view.y };
}, index );

for ( const index of [ 0, 0 ] ) {
	const seat = await seatPoint( index );

	await shop.mouse.click( seat.x, seat.y );
	await shop.waitForTimeout( 300 );
}

check( 'every chosen seat asks who it is for',
	2 === await shop.locator( '.seatmap-widget__type' ).count() );

const before = await shop.locator( '.seatmap-widget__total' ).innerText();

await shop.locator( '.seatmap-widget__type' ).first().selectOption( { label: 'Child' } );
await shop.waitForTimeout( 300 );

const after = await shop.locator( '.seatmap-widget__total' ).innerText();
check( 'and the total comes down when one becomes a child', before !== after, `${ before } -> ${ after }` );
await shop.screenshot( { path: `${ SHOTS }/02-picker.png` } );

console.log( 'And the server agrees' );
await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/checkout/, { timeout: 15000 } );

const summary = await shop.locator( '.checkout__summary' ).innerText();

/*
 * The one number that matters.
 *
 * The picker's total is arithmetic done in the browser; the checkout's total is the hold the
 * server priced and the amount the buyer will actually be charged. Two implementations of "what a
 * child ticket costs" is how a buyer is shown one price and charged another, so this compares the
 * two strings rather than trusting either.
 */
const priced = ( after.match( /[\d][\d,. ]*/g ) || [] ).pop().trim();
check( 'the checkout charges what the picker promised',
	summary.includes( priced ), `picker ${ priced }, checkout ${ summary.replace( /\n/g, ' | ' ) }` );
check( 'and the checkout names the concession', /Child/.test( summary ) && /Adult/.test( summary ),
	summary.replace( /\n/g, ' | ' ) );
await shop.screenshot( { path: `${ SHOTS }/03-checkout.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL TICKET TYPE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
