/**
 * Saved audiences, driven in Chromium.
 *
 * `SegmentTest` holds up the arithmetic. What a browser adds is the sentence the whole feature
 * exists for: an organiser builds "came last season and has not booked this one" out of two
 * controls, watches the number under it change as they do, saves it, and writes to it.
 *
 * It also drives the promise that matters more than any of that: the screen says how many people a
 * list reaches and never who they are. A segment is not a second customer directory.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node segments_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/segments-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'segments-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

/*
 * One of last season's buyers books the other night, so the sentence this feature exists for has
 * somebody to exclude.
 *
 * Sold at the counter rather than driven through a checkout: what matters here is that the same
 * person appears against two nights, and the window is one call away from arranging that.
 */
const otherNights = ( await api( 'GET', '/v1/events' ) ).body.data
	.filter( ( event ) => event.id !== night.id );
const quiet = otherNights[ 0 ];

const hall = ( await api( 'GET', `/v1/events/${ quiet.id }/counter` ) ).body;
const free = ( hall.sections || [] )
	.flatMap( ( section ) => section.rows )
	.flatMap( ( row ) => row.seats )
	.filter( ( seat ) => 'available' === seat.state )[ 0 ];

// The demo has two kinds of room on purpose — a theatre with named chairs and a warehouse sold by
// the head — and the other night may well be the warehouse. One place, either way.
const room = ( hall.areas || [] ).filter( ( area ) => area.remaining > 0 )[ 0 ];

const booked = await api( 'POST', `/v1/events/${ quiet.id }/sell`, {
	...( free
		? { seat_ids: [ free.id ] }
		: { areas: { [ room.capacity_object_id ]: 1 } } ),
	buyer: { name: 'Dana Scully', email: 'dana@example.test' },
	payment: 'paid',
} );

check( 'a returning buyer books the other night', !! booked.body.reference,
	JSON.stringify( booked.body.error || booked.body.reference ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1200 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The messaging screen offers saved audiences' );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( '#segment-new', { timeout: 20000 } );

check( 'there is a way to make one', await page.locator( '#segment-new' ).isVisible() );
check( 'and nothing saved yet says so plainly',
	( await page.locator( 'body' ).innerText() ).includes( 'No saved audiences yet' ) );

console.log( 'Building "came last season, has not booked this one"' );
await page.click( '#segment-new' );
await page.waitForSelector( '#s-name' );

await page.fill( '#s-name', 'Came last season' );
await page.fill( '#s-desc', 'The people to write to in March' );

// The count under the builder, before any clause narrows it.
await page.waitForFunction( () => /\d/.test( document.getElementById( 's-reach' ).textContent ),
	null, { timeout: 15000 } );

const everybody = Number( ( await page.locator( '#s-reach' ).innerText() ).replace( /\D/g, '' ) );

check( 'the builder says how many it reaches before anything is chosen', everybody > 0,
	await page.locator( '#s-reach' ).innerText() );

// The first clause: came last season.
await page.selectOption( '#s-bought', night.id );
await page.waitForTimeout( 900 );

const came = Number( ( await page.locator( '#s-reach' ).innerText() ).replace( /\D/g, '' ) );

check( 'everybody who came is everybody', came === everybody, `${ everybody } → ${ came }` );

// The second: and has not booked this one. One of them has, and the number says so.
await page.selectOption( '#s-not-bought', quiet.id );
await page.waitForTimeout( 900 );

const left = Number( ( await page.locator( '#s-reach' ).innerText() ).replace( /\D/g, '' ) );

check( 'and the one who has already booked drops out of the list', left === came - 1,
	`${ came } → ${ left }` );

await page.screenshot( { path: `${ SHOTS }/01-builder.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '[data-segment-edit]', { timeout: 15000 } );

check( 'the audience is saved and listed', 1 === await page.locator( '[data-segment-edit]' ).count() );

const row = await page.locator( 'tr:has([data-segment-edit])' ).innerText();

// What it means, in words, from the server's own reading of the rules.
check( 'the list says what it means rather than showing identifiers',
	! /[0-9a-f]{8}-[0-9a-f]{4}/.test( row ) && row.length > 20, row.replace( /\n/g, ' | ' ) );

console.log( 'A saved audience never hands back the people in it' );
const listed = await api( 'GET', '/v1/segments' );
const saved = listed.body.data[ 0 ];
const one = await api( 'GET', '/v1/segments/' + saved.id );

check( 'a single audience says how many', 'number' === typeof one.body.people,
	`${ one.body.people } people` );

const everything = JSON.stringify( [ listed.body, one.body ] );

check( 'and no address appears anywhere in either answer',
	! /@[a-z0-9.-]+\.[a-z]{2,}/i.test( everything ),
	( everything.match( /@[a-z0-9.-]+\.[a-z]{2,}/i ) || [ 'none' ] )[ 0 ] );

console.log( 'Writing to it' );
await page.click( '#announce-new' );
await page.waitForSelector( '#a-event' );

const groups = await page.$$eval( '#a-event optgroup', ( found ) =>
	found.map( ( group ) => group.label ) );

check( 'the composer offers saved audiences beside the events', groups.length >= 2, groups.join( ' | ' ) );

await page.selectOption( '#a-event', 'seg:' + saved.id );
await page.waitForTimeout( 900 );

const reach = await page.locator( '#a-reach' ).innerText();

/*
 * Not the same number the builder gave, and it should not be. An audience is who matches the
 * rules; a reach is who matches them *and* has agreed to hear from this organiser about something
 * they have not bought. The two differ by exactly the people nobody has asked yet — which the line
 * says out loud rather than quietly shrinking the count — so what is checked is that they add up.
 */
const counted = ( reach.match( /[0-9]+/g ) || [] ).map( Number );

check( 'and says what choosing one would reach, with the unasked named rather than hidden',
	counted.length >= 2 && counted[ 0 ] + counted[ counted.length - 1 ] === left,
	`${ reach } (audience of ${ left })` );

await page.fill( '#a-subject', 'We are back in March' );
await page.fill( '#a-body', 'Hello {buyer}, the new season opens in March.' );
await page.screenshot( { path: `${ SHOTS }/02-compose.png` } );
await page.click( '.modal button[type=submit]' );

/*
 * The second modal: the one that puts a number on it before anything is sent.
 *
 * Addressed as the last of the two on screen — the draft stays open behind the confirmation on
 * purpose, so backing out does not throw away what somebody just wrote.
 */
await page.waitForSelector( '.modal .btn--danger' );
await page.waitForTimeout( 300 );

const confirmText = await page.locator( '.modal' ).last().innerText();

check( 'sending asks once more, with the count on it', /\d/.test( confirmText ),
	confirmText.split( '\n' ).slice( 0, 2 ).join( ' | ' ) );

// The send is the dangerous button, which is the one the confirmation is made of.
await page.locator( '.modal .btn--danger' ).click();
await page.waitForTimeout( 2500 );

const announcements = await api( 'GET', '/v1/messaging/announcements' );
const sent = announcements.body.data[ 0 ];

check( 'the announcement records the audience it went to by name',
	'segment' === sent.audience && 'Came last season' === sent.segment,
	`${ sent.audience } / ${ sent.segment }` );
check( 'and it reached somebody', sent.total > 0, `${ sent.sent } of ${ sent.total }` );

console.log( 'An unknown audience is refused rather than widened' );
const refused = await api( 'POST', '/v1/messaging/announcements', {
	segment_id: '00000000-0000-4000-8000-000000000000',
	channels: [ 'email' ],
	body: 'Hello',
} );

check( 'because falling back to everybody cannot be unsent',
	422 === refused.status && 'unknown_segment' === refused.body.error?.code,
	`${ refused.status } ${ refused.body.error?.code }` );

console.log( 'Persian' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'fa' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( '[data-segment-edit]', { timeout: 20000 } );

const faRow = await page.locator( 'tr:has([data-segment-edit])' ).innerText();

check( 'what an audience means is written in Persian', /بلیت|خرید/.test( faRow ),
	faRow.replace( /\n/g, ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/03-persian.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
