/**
 * The report builder, driven in Chromium.
 *
 * Build one by dragging fields into shelves, look at it as a chart, save it, arrange it on a page,
 * and take the CSV. What is being checked is that the same definition means the same thing on the
 * screen, on the page and in the file — two implementations of "what this report means" is how a
 * report and its export start disagreeing — and that every drag has a button that does the same
 * thing, because a screen that can only be used with a mouse is a screen somebody cannot use.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node reports_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( {
	viewport: { width: 1500, height: 1000 },
	acceptDownloads: true,
} );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Building one' );
await page.click( 'nav button[data-view=reports]' );
await page.waitForSelector( '#report-new' );
await page.click( '#report-new' );
await page.waitForSelector( '#report-source' );

await page.selectOption( '#report-source', 'seats_sold' );
await page.waitForTimeout( 400 );

// Dragged, the way the screen is meant to be used.
await page.dragAndDrop( '[data-add=dimension][data-key=section]', '.shelf[data-shelf=dimension]' );
await page.waitForTimeout( 300 );
check( 'a dragged field lands in its shelf',
	1 === await page.locator( '.shelf[data-shelf=dimension] .pill' ).count() );

// A measure dragged onto "group by" is a mistake, and the screen refuses it rather than the
// server refusing it a second later.
await page.dragAndDrop( '[data-add=measure][data-key=seats]', '.shelf[data-shelf=dimension]' );
await page.waitForTimeout( 200 );
check( 'and a measure cannot be dropped into group-by',
	1 === await page.locator( '.shelf[data-shelf=dimension] .pill' ).count() );

// Pressed, the way somebody without a mouse uses it.
await page.click( '[data-add=measure][data-key=seats]' );
await page.waitForTimeout( 300 );
await page.click( '[data-add=measure][data-key=revenue]' );
await page.waitForTimeout( 700 );

check( 'pressing a field adds it too',
	2 === await page.locator( '.shelf[data-shelf=measure] .pill' ).count() );

const table = await page.locator( '#report-result' ).innerText();
check( 'the rows arrive', ( await page.locator( '#report-result tbody tr' ).count() ) >= 2,
	table.replace( /\n/g, ' | ' ) );
check( 'money is written as money', /€|EUR/.test( table ), table.replace( /\n/g, ' | ' ) );

console.log( 'Sorting by a column' );
const before = await page.locator( '#report-result tbody tr td' ).first().innerText();

// Once for descending, again for ascending — so the check is that the rows actually moved, not
// that a class was added to a heading.
await page.click( '#report-result th .th-sort >> nth=0' );
await page.waitForTimeout( 600 );
await page.click( '#report-result th .th-sort >> nth=0' );
await page.waitForTimeout( 600 );

const after = await page.locator( '#report-result tbody tr td' ).first().innerText();
check( 'a heading sorts the report', before !== after && 'Balcony' === after,
	`first cell was ${ before }, now ${ after }` );
check( 'and says which column is doing it',
	1 === await page.locator( '#report-result .th-sort.is-active' ).count() );

console.log( 'Saving it' );
await page.click( '#report-save' );
await page.waitForSelector( '.modal' );
await page.fill( '#r-name', 'Seats by section' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 800 );
check( 'and it can be exported once saved', await page.locator( '#report-export' ).isVisible() );

console.log( 'The CSV' );
const download = await Promise.all( [
	page.waitForEvent( 'download' ),
	page.click( '#report-export' ),
] ).then( ( [ d ] ) => d );
const path = await download.path();
const { readFileSync } = await import( 'node:fs' );
const csv = readFileSync( path, 'utf8' );
// The screen's first row, in the file. Same definition, same runner, same answer.
const firstSection = await page.locator( '#report-result tbody tr td' ).first().innerText();
check( 'the file says what the screen said', csv.includes( firstSection ),
	`${ firstSection } / ${ csv.split( '\n' )[ 1 ] }` );

console.log( 'On a page' );
await page.click( '#report-back' );
await page.waitForSelector( '#report-new-page' );
await page.click( '#report-new-page' );
await page.waitForSelector( '.modal' );
await page.fill( '#p-name', 'Monday morning' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '#page-canvas' );

// A report dragged from the palette onto the empty page.
await page.dragAndDrop( '[data-widget-add]:not([data-widget-add=note])', '#page-canvas' );
await page.waitForTimeout( 1200 );
check( 'a dragged report becomes a card', 1 === await page.locator( '.widget' ).count() );

await page.selectOption( '[data-type="0"]', 'bar' );
await page.waitForTimeout( 400 );
check( 'and can be drawn as bars', ( await page.locator( '.spark__bar' ).count() ) > 0,
	`${ await page.locator( '.spark__bar' ).count() } bars` );

await page.selectOption( '[data-width="0"]', 'half' );
await page.waitForTimeout( 400 );
check( 'and made half-width', 1 === await page.locator( '.widget--half' ).count() );

// A note is the organiser's own words: there is nothing to run, so it appears at once.
await page.click( '[data-widget-add=note]' );
await page.waitForTimeout( 300 );
await page.fill( '[data-note="1"]', 'Chase the unpaid orders before Friday.' );
await page.waitForTimeout( 1400 );

// Read the page back from the server: what is on screen is only half the claim.
await page.click( '#page-back' );
await page.waitForSelector( '[data-page]' );
await page.click( '[data-page]' );
await page.waitForSelector( '#page-canvas' );

check( 'the arrangement was saved without a save button',
	2 === await page.locator( '.widget' ).count() );
check( 'including the note',
	'Chase the unpaid orders before Friday.' === await page.locator( '[data-note="1"]' ).inputValue() );
check( 'and the width it was given', 1 === await page.locator( '.widget--half' ).count() );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/report-page.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL REPORT CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
