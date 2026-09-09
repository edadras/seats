/**
 * The report builder, driven in Chromium.
 *
 * Build one from the sales the seeder made, look at it as a chart, save it, put it on a page, and
 * take the CSV. What is being checked is that the same definition means the same thing on the
 * screen, on the page and in the file — two implementations of "what this report means" is how a
 * report and its export start disagreeing.
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
await page.locator( '[data-field=dimension][value=section]' ).check();
await page.locator( '[data-field=measure][value=seats]' ).check();
await page.locator( '[data-field=measure][value=revenue]' ).check();
await page.waitForTimeout( 700 );

const table = await page.locator( '#report-result' ).innerText();
check( 'the rows arrive', ( await page.locator( '#report-result tbody tr' ).count() ) >= 2,
	table.replace( /\n/g, ' | ' ) );
check( 'money is written as money', /€|EUR/.test( table ), table.replace( /\n/g, ' | ' ) );

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
await page.waitForSelector( '#page-add' );
await page.click( '#page-add' );
await page.waitForSelector( '.modal' );
await page.selectOption( '#w-type', 'bar' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 900 );

check( 'the widget is on the page', 1 === await page.locator( '.widget' ).count() );
check( 'and drawn as bars', ( await page.locator( '.spark__bar' ).count() ) > 0,
	`${ await page.locator( '.spark__bar' ).count() } bars` );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/report-page.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL REPORT CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
