/**
 * The door list, driven in Chromium.
 *
 * What is checked is the thing the list exists for: that somebody standing at a door with a queue
 * in front of them can find one person by name in one box, see at a glance how many are still to
 * come, and take the whole thing away as a file before the Wi-Fi lets them down.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node door_smoke.mjs
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/door-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 }, acceptDownloads: true } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Tonight' );
await page.click( 'nav button[data-view=doorlist]' );
await page.waitForSelector( '#door-event' );
await page.selectOption( '#door-event', { label: 'Opening night' } );
await page.waitForTimeout( 1000 );

const tally = ( await page.locator( '.stat-strip' ).innerText() ).replace( /\n/g, ' ' );

check( 'the night is counted', /\d/.test( tally ), tally );
check( 'and everybody expected has a row',
	( await page.locator( 'tbody tr' ).count() ) > 1,
	`${ await page.locator( 'tbody tr' ).count() } rows` );
check( 'with the ones already inside marked',
	( await page.locator( '.door-row.is-in' ).count() ) > 0 );

console.log( 'Finding one person' );
await page.fill( '#door-search', 'dana' );
await page.waitForTimeout( 700 );

check( 'one name, one row', 1 === await page.locator( 'tbody tr' ).count(),
	await page.locator( 'tbody' ).innerText().catch( () => '' ) );

await page.fill( '#door-search', '' );
await page.waitForTimeout( 700 );
await page.screenshot( { path: `${ SHOTS }/01-door.png` } );

console.log( 'The file' );
const download = await Promise.all( [
	page.waitForEvent( 'download' ),
	page.click( '#door-export' ),
] ).then( ( [ file ] ) => file );

const csv = readFileSync( await download.path(), 'utf8' );
const lines = csv.trim().split( '\n' );

check( 'the file has a heading and everybody under it', lines.length > 2, `${ lines.length } lines` );
check( 'and names the people', /Dana Scully/.test( csv ) );
// The door needs names and seats. What the evening took is nobody's business at the door.
check( 'and says nothing about money', ! /€|EUR/.test( csv ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL DOOR LIST CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
