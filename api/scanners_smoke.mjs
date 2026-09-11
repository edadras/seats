/**
 * Putting a scanner in somebody's hand, driven in Chromium.
 *
 * `DoorListTest` holds up the endpoints. What a browser adds here is the thing that was missing
 * from this platform entirely: a screen. `devices.manage` had existed since permissions did,
 * granted to four roles and referred to by nothing, so the scanner could be built, translated,
 * tested, deployed — and never paired, because no page on the platform could issue a code.
 *
 * So this drives the whole of a duty manager's six o'clock: make a scanner, read the code off the
 * screen, pair a phone with it, take the door list, and watch the panel say so.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node scanners_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/scanners-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const night = await seatedEvent( BASE, 'scanners-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const desk = await ( await browser.newContext( { viewport: { width: 1500, height: 1100 } } ) ).newPage();

desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'What is at the doors already' );
await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );

check( 'the doors have a screen of their own', await desk.locator( 'nav button[data-view=scanners]' ).count() > 0 );

await desk.click( 'nav button[data-view=scanners]' );
await desk.waitForSelector( '#scanner-add', { timeout: 20000 } );

// The demo venue has one already, which is the honest starting point: this screen reads the
// devices that exist rather than being a form that writes them.
const first = await desk.locator( '.table' ).first().innerText();

check( 'the devices that exist are on it', /Front door scanner/.test( first ),
	first.split( '\n' )[ 1 ] );
check( 'and one that has never taken a copy says so', /Never taken/.test( first ) );

await desk.screenshot( { path: `${ SHOTS }/01-the-doors.png` } );

console.log( 'The manager makes one for the front door' );
await desk.click( '#scanner-add' );
await desk.waitForSelector( '#sc-name' );
await desk.fill( '#sc-name', 'Front of house' );

const nights = await desk.locator( '.modal input[name="event_ids[]"]' ).count();

check( 'every night can be given to it', nights > 0, `${ nights } nights` );

await desk.locator( '.modal .perms__row' ).first().click();
await desk.screenshot( { path: `${ SHOTS }/02-making-one.png` } );
await desk.locator( '.modal button[type=submit]' ).click();

// The code is shown in a dialogue of its own, because somebody has to read it across a foyer.
await desk.waitForSelector( '.credentials', { timeout: 20000 } );

const code = ( await desk.locator( '.credentials' ).innerText() ).trim();

check( 'a pairing code is shown, once', /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/.test( code ), code );

await desk.screenshot( { path: `${ SHOTS }/03-the-code.png` } );
await desk.locator( '.modal button[data-close]' ).last().click();
await settle( 800 );

/** The row for the scanner this check made, rather than whichever row happens to be first. */
const mine = () => desk.locator( 'tr', { hasText: 'Front of house' } ).first();

check( 'and the scanner is on the list, waiting',
	/Waiting to pair/.test( await mine().innerText() ), await mine().innerText() );
check( 'with nothing taken yet', /Never taken/.test( await mine().innerText() ) );

console.log( 'A volunteer pairs a phone with it' );
const paired = await ( await fetch( `${ BASE }/v1/checkin/auth/token`, {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( { pairing_code: code, device_name: 'Front of house' } ),
} ) ).json();

check( 'the code off the screen is the code the scanner accepts', !! paired.token );
check( 'and it arrives knowing which night it is on', ( paired.events || [] ).length > 0 );

console.log( 'And takes the list it will work from when the wifi goes' );
const list = await ( await fetch(
	`${ BASE }/v1/checkin/events/${ paired.events[ 0 ].id }/door-list`,
	{ headers: { Accept: 'application/json', Authorization: 'Bearer ' + paired.token } }
) ).json();

check( 'the whole house comes down', list.count > 0, `${ list.count } tickets` );
check( 'as hashes, never as codes',
	( list.tickets || [] ).every( ( row ) => /^[a-f0-9]{64}$/.test( row.h ) ) );

await desk.reload( { waitUntil: 'networkidle' } );
await desk.click( 'nav button[data-view=scanners]' );
await desk.waitForSelector( '#scanner-add', { timeout: 20000 } );

const after = await mine().innerText();

check( 'and the panel now says the device is paired', /Paired/.test( after ), after );
// The one fact a tablet cannot tell you without switching the wifi off first.
check( 'and when it last took a copy', ! /Never taken/.test( after ), after );

await desk.screenshot( { path: `${ SHOTS }/04-armed.png`, fullPage: true } );

console.log( 'A lost tablet is signed out by issuing another code' );
await mine().locator( '[data-scanner-code]' ).click();
await desk.waitForSelector( '.modal button[type=submit]' );
await desk.locator( '.modal button[type=submit]' ).click();
await desk.waitForSelector( '.credentials', { timeout: 20000 } );
await desk.locator( '.modal button[data-close]' ).last().click();
await settle( 600 );

const stale = await ( await fetch( `${ BASE }/v1/checkin/events`, {
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + paired.token },
} ) ).status;

check( 'the phone in the drawer stops working', 401 === stale, String( stale ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SCANNER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
