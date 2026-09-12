/**
 * The queue outside a big sale — driven in Chromium, with two browsers.
 *
 * The mechanics are held up by `WaitingRoomTest`; what a browser adds is the thing a person
 * actually experiences. One buyer walks in and gets the picker. The next gets a page with a number
 * on it and no seat map at all — and when the first one's lease runs out, the second's page lets
 * them in *by itself*, without anybody pressing anything.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node queue_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/queue-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'queue-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( ( response ) => response.json() );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

console.log( 'The organiser puts a queue in front of the sale' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1100 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-event-edit]' );
await page.locator( '[data-event-edit="' + night.id + '"]' ).click();
// Attached rather than visible: the part it lives in is closed until somebody opens it.
await page.waitForSelector( '#e-room', { state: 'attached' } );

// The door is part of when a night goes on sale, which is one of the form's named parts.
await page.locator( '.modal .form-group', { has: page.locator( '#e-room' ) } )
	.locator( 'summary' ).click();
await page.waitForTimeout( 200 );

check( 'the event form offers a door', await page.locator( '#e-room' ).isVisible() );

// The box itself is behind the switch's own track, which is what a person presses.
await page.locator( 'label:has(#e-room) .switch__track' ).click();
await page.waitForTimeout( 300 );

check( 'the switch turns on', await page.locator( '#e-room' ).isChecked() );

// Room for exactly one buyer, and a lease short enough for a check to watch it lapse.
await page.fill( '#e-room-capacity', '1' );
await page.fill( '#e-room-minutes', '1' );
await page.screenshot( { path: `${ SHOTS }/01-settings.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1800 );

const saved = await api( 'GET', `/v1/events/${ night.id }` );

check( 'and it sticks', true === saved.waiting_room && 1 === saved.waiting_room_capacity,
	JSON.stringify( { on: saved.waiting_room, room: saved.waiting_room_capacity } ) );

console.log( 'The first buyer walks straight in' );
const first = await ( await browser.newContext() ).newPage();
first.on( 'pageerror', ( e ) => errors.push( e.message ) );

await first.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

check( 'they get the seat map', await first.locator( '.seatmap-widget' ).count() > 0 );
check( 'and no queue', 0 === await first.locator( '#room' ).count() );

console.log( 'The second waits, and is told where they stand' );
const second = await ( await browser.newContext() ).newPage();
second.on( 'pageerror', ( e ) => errors.push( e.message ) );

await second.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

check( 'they get the queue', await second.locator( '#room' ).isVisible() );
check( 'and no seat map at all — the page the queue exists to protect is not drawn',
	0 === await second.locator( '.seatmap-widget' ).count() );

// The page asks for itself; nothing is pressed.
await second.waitForFunction(
	() => /\d/.test( document.getElementById( 'room-title' ).textContent ),
	null,
	{ timeout: 20000 },
);

check( 'the page finds them a number without being asked',
	/\d/.test( await second.locator( '#room-title' ).innerText() ),
	await second.locator( '#room-title' ).innerText() );

await second.screenshot( { path: `${ SHOTS }/02-waiting.png` } );

console.log( 'The organiser watches the door' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-queue]' );
await page.locator( '[data-queue="' + night.id + '"]' ).click();
await page.waitForSelector( '#queue-tiles .stat', { timeout: 15000 } );
await page.waitForTimeout( 800 );

const tiles = await page.locator( '#queue-tiles' ).innerText();

check( 'one inside and one waiting', /1/.test( tiles ), tiles.split( '\n' ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/03-door.png` } );
await page.locator( '.modal button' ).last().click();

console.log( 'And when the first one wanders off, the second is let in on their own' );
/*
 * The lease is a minute, and nothing here presses anything.
 *
 * The second buyer's page keeps asking where it stands, and the ask is what sweeps the lapsed
 * admission and opens the door — so this waits for the seat map to appear on a page nobody has
 * touched since it was opened.
 */
await second.waitForFunction(
	() => !! document.querySelector( '.seatmap-widget' ),
	null,
	{ timeout: 120000 },
);

check( 'the second buyer is inside, without pressing anything',
	await second.locator( '.seatmap-widget' ).count() > 0 );

await second.screenshot( { path: `${ SHOTS }/04-let-in.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL QUEUE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
