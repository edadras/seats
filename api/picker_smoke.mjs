/**
 * The buyer's picker, driven in Chromium.
 *
 * The venue arrives as blocks; one is opened; its chairs appear and can be chosen; and there is a
 * way back out. That last one matters as much as the rest: a buyer who zooms into the wrong block
 * and cannot leave it has lost the whole venue.
 *
 * Needs the API and the plugin's preview page:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   (cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)
 *   node picker_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const PREVIEW = process.env.SEATMAP_PREVIEW || 'http://127.0.0.1:8200';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const token = await ( await fetch( BASE + '/v1/auth/login', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( { email: 'owner@northgate.test', password: 'password', device_name: 'picker' } ),
} ) ).json().then( ( body ) => body.token );

const events = await ( await fetch( BASE + '/v1/events', {
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
} ) ).json();

const eventId = events.data[ 0 ].public_id;

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1200, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The preview page has no favicon; that 404 is the page's, not the picker's.
	if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() );
} );

await page.goto( `${ PREVIEW }/tools/preview.html?api=${ BASE }&event=${ eventId }`,
	{ waitUntil: 'networkidle' } );

console.log( 'The venue, as blocks' );
await page.waitForSelector( '.seatmap-widget__block' );
check( 'every block is offered', ( await page.locator( '.seatmap-widget__block' ).count() ) >= 2,
	( await page.locator( '.seatmap-widget__block-name' ).allInnerTexts() ).join( ', ' ) );
check( 'no chairs yet', 0 === await page.locator( '.seatmap-widget__seat' ).count() );
check( 'each block says what it costs to sit there',
	/From/.test( await page.locator( '.seatmap-widget__block-meta' ).first().innerText() ),
	await page.locator( '.seatmap-widget__block-meta' ).first().innerText() );
check( 'and there is no way back from where nobody has gone',
	await page.locator( '.seatmap-widget__back' ).isHidden() );

console.log( 'Into a block, from the plan itself' );
const box = await page.locator( '.seatmap-widget__canvas' ).boundingBox();
await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
await page.waitForSelector( '.seatmap-widget__seat' );
check( 'the chairs are there', ( await page.locator( '.seatmap-widget__seat' ).count() ) > 20,
	`${ await page.locator( '.seatmap-widget__seat' ).count() } seats` );
check( 'one block’s chairs, not the building’s',
	1 === await page.locator( '.seatmap-widget__seats h4' ).count(),
	( await page.locator( '.seatmap-widget__seats h4' ).allInnerTexts() ).join( ', ' ) );
check( 'the way back appeared', await page.locator( '.seatmap-widget__back' ).isVisible() );

console.log( 'Choosing' );
await page.locator( '.seatmap-widget__seat:not([disabled])' ).first().click();
await page.waitForTimeout( 250 );
check( 'the seat is in the summary',
	( await page.locator( '.seatmap-widget__selection li' ).count() ) === 1,
	await page.locator( '.seatmap-widget__selection' ).innerText() );

console.log( 'Back out' );
await page.click( '.seatmap-widget__back-button' );
await page.waitForSelector( '.seatmap-widget__block' );
check( 'the venue is back', ( await page.locator( '.seatmap-widget__block' ).count() ) >= 2 );
check( 'and the choice survived the trip',
	( await page.locator( '.seatmap-widget__selection li' ).count() ) === 1 );

console.log( 'Escape' );
await page.locator( '.seatmap-widget__block:not([disabled])' ).first().click();
await page.waitForSelector( '.seatmap-widget__seat' );
await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 200 );
check( 'Escape leaves the block too',
	( await page.locator( '.seatmap-widget__block' ).count() ) >= 2 );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL PICKER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
