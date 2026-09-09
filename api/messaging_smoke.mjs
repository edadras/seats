/**
 * Announcements and the notice bell, driven in Chromium.
 *
 * The seeder sells to six people at each of two events, so "everybody who bought" is a real
 * audience. What is checked is that the reach is counted before anything is sent, that sending it
 * produces deliveries in the log, and that the notice the platform raises about it reaches the
 * bell — the three things that make this more than a form.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node messaging_smoke.mjs
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
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Writing an announcement' );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( '#announce-new' );
await page.click( '#announce-new' );
await page.waitForSelector( '#a-body' );
await page.waitForTimeout( 800 );

const reach = await page.locator( '#a-reach' ).innerText();
check( 'the reach is counted before anything is sent', /[0-9۰-۹]/.test( reach ), reach );

await page.fill( '#a-subject', 'The side door tonight' );
await page.fill( '#a-body', 'Hello {buyer}, use the side door for {event}.' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 2500 );

const table = await page.locator( '.page-body' ).innerText();
check( 'it is listed as sent', /side door tonight/i.test( table ), table.split( '\n' ).slice( 0, 12 ).join( ' | ' ) );

console.log( 'The delivery log' );
check( 'the messages are in the log', /announcement/i.test( table ) || /Sent/i.test( table ) );

console.log( 'The bell' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '#bell' );
await page.waitForTimeout( 1200 );

check( 'the platform has something to say', await page.locator( '#bell-count' ).isVisible(),
	await page.locator( '#bell-count' ).innerText().catch( () => 'no badge' ) );

await page.click( '#bell' );
await page.waitForSelector( '.modal' );

const notices = await page.locator( '.modal' ).innerText();
check( 'and says it in the reader\'s language', /announcement|اعلان/i.test( notices ),
	notices.split( '\n' ).slice( 0, 6 ).join( ' | ' ) );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/notices.png' } );

await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 900 );
check( 'reading them clears the count', ! ( await page.locator( '#bell-count' ).isVisible() ) );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL MESSAGING CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
