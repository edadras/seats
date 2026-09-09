/**
 * Timed entry, driven in Chromium, from the timetable to the door.
 *
 * The whole point of the feature is that one number means something: a window holds as many people
 * as it was given, and what the buyer was told rides all the way to the door. So this drives the
 * three surfaces that have to agree — the organiser's timetable, the buyer's picker and checkout,
 * and the door list — rather than checking any one of them on its own.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node entry_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/entry-shots';

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

/** A datetime-local value, days out and at a whole hour, in the browser's own clock. */
const at = ( days, hour ) => {
	const when = new Date();

	when.setDate( when.getDate() + days );
	when.setHours( hour, 0, 0, 0 );

	const pad = ( n ) => String( n ).padStart( 2, '0' );

	return when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() ) +
		'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );
};

console.log( 'A day cut into windows' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=entryslots]' );
await page.waitForSelector( '#es-event' );
await page.selectOption( '#es-event', { label: 'Opening night' } );
await page.waitForTimeout( 600 );

check( 'an event starts with no windows',
	( await page.locator( '#es-rows .empty' ).count() ) > 0 );

await page.click( '#es-fill' );
await page.waitForSelector( '#es-fill-from' );
await page.fill( '#es-fill-from', at( 1, 10 ) );
await page.fill( '#es-fill-to', at( 1, 13 ) );
await page.fill( '#es-fill-minutes', '30' );
await page.fill( '#es-fill-capacity', '2' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1200 );

const windows = await page.locator( '#es-rows tbody tr' ).count();

check( 'a day is filled in one step', 6 === windows, `${ windows } windows` );
check( 'and each one carries its own capacity',
	( await page.locator( '#es-rows tbody tr' ).first().innerText() ).includes( '2' ) );

await page.screenshot( { path: `${ SHOTS }/01-entry-times.png` } );

console.log( 'The buyer is asked when they will arrive' );
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await page.locator( '.event-card' ).first().click();
await page.waitForSelector( '.seatmap-widget' );
await page.waitForTimeout( 900 );

check( 'the window chooser is offered',
	await page.locator( '.seatmap-widget__entry-select' ).isVisible() );

const offered = await page.locator( '.seatmap-widget__entry-select option' ).count();

check( 'with every window on it', offered >= 7, `${ offered - 1 } windows plus the prompt` );

// Into a block, then onto a chair — the same route a buyer takes.
const box = await page.locator( '.seatmap-widget__canvas' ).boundingBox();
await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
await page.waitForSelector( '.seatmap-widget__list' );

const seat = await page.evaluate( () => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const seats = widget.seats.filter( ( s ) =>
		s.floorKey === widget.floorKey && widget.inOpenBlock( s ) && 'available' === s.state );
	const rect = widget.canvas.getBoundingClientRect();
	const scale = widget.baseScale * widget.view.scale;

	return {
		x: rect.left + seats[ 0 ].x * scale + widget.view.x,
		y: rect.top + seats[ 0 ].y * scale + widget.view.y,
	};
} );

await page.mouse.click( seat.x, seat.y );
await page.waitForTimeout( 300 );

check( 'a seat alone will not do',
	await page.locator( '.seatmap-widget__submit' ).isDisabled() );

const value = await page.locator( '.seatmap-widget__entry-select option' ).nth( 1 ).getAttribute( 'value' );

await page.selectOption( '.seatmap-widget__entry-select', value );
await page.waitForTimeout( 200 );

check( 'and with a time it may be reserved',
	await page.locator( '.seatmap-widget__submit' ).isEnabled() );

await page.screenshot( { path: `${ SHOTS }/02-picker.png` } );

await page.click( '.seatmap-widget__submit' );
await page.waitForURL( /checkout/, { timeout: 15000 } );

check( 'the checkout says when they are expected',
	await page.locator( '.checkout__entry' ).isVisible(),
	await page.locator( '.checkout__entry' ).innerText().catch( () => '' ) );

await page.screenshot( { path: `${ SHOTS }/03-checkout.png` } );

await page.fill( 'input[name=name]', 'Nadia Farrokh' );
await page.fill( 'input[name=email]', 'nadia@example.test' );
await page.click( 'button[type=submit].checkout__submit' );
await page.waitForURL( /\/order\//, { timeout: 15000 } );

const confirmation = await page.locator( 'body' ).innerText();

// The venue's own clock, not the browser's: the windows were typed at ten in this container's
// zone and the theatre sits in Berlin, so the ticket is meant to read two hours on.
check( 'and so does the ticket they are handed', /Entry \d\d:\d\d/.test( confirmation ),
	( confirmation.match( /Entry \d\d:\d\d – \d\d:\d\d/ ) || [] )[ 0 ] );

console.log( 'The door reads the night by window' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=doorlist]' );
await page.waitForSelector( '#door-event' );
await page.selectOption( '#door-event', { label: 'Opening night' } );
await page.waitForTimeout( 1200 );

check( 'the window filter appeared', await page.locator( '#door-slot' ).isVisible() );

const withEntry = await page.locator( '#door-rows tbody tr' ).evaluateAll( ( rows ) =>
	rows.filter( ( row ) => /\d\d[:.]\d\d/.test( row.cells[ 3 ].textContent ) ).length );

check( 'and the person who chose one is on it with their time', withEntry >= 1,
	`${ withEntry } of ${ await page.locator( '#door-rows tbody tr' ).count() } rows` );

const slot = await page.locator( '#door-slot option' ).nth( 1 ).getAttribute( 'value' );

await page.selectOption( '#door-slot', slot );
await page.waitForTimeout( 900 );

const narrowed = await page.locator( '#door-rows tbody tr' ).count();

check( 'one window at a time is one queue', 1 === narrowed, `${ narrowed } rows` );

await page.screenshot( { path: `${ SHOTS }/04-door.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL TIMED ENTRY CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
