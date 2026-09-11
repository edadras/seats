/**
 * The Friends scheme, driven in Chromium.
 *
 * `MembershipTest` holds up the arithmetic and the rules. What a browser adds is the two things an
 * organiser will actually do on a Tuesday morning: set up a scheme, and add the person standing at
 * the window — and then the thing that makes the fee worth paying, which is a buyer seeing their own
 * discount named on the checkout page rather than as an anonymous reduction.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node memberships_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/memberships-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const night = await seatedEvent( BASE, 'memberships-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const desk = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'A venue with no Friends scheme' );
await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );
await desk.click( 'nav button[data-view=memberships]' );
await desk.waitForSelector( '#mem-add', { timeout: 20000 } );

check( 'the screen says what one is rather than showing an empty table',
	await desk.locator( '.empty__title' ).first().isVisible() );

await desk.screenshot( { path: `${ SHOTS }/01-nothing-yet.png` } );

console.log( 'The organiser sets one up' );
await desk.click( '#mem-add' );
await desk.waitForSelector( '#mem-name' );

await desk.fill( '#mem-name', 'Friends of Northgate' );
await desk.fill( '#mem-desc', 'A fifth off every seat, and first refusal.' );
await desk.fill( '#mem-price', '3000' );
await desk.fill( '#mem-months', '12' );
await desk.fill( '#mem-percent', '20' );
await desk.locator( '.modal label:has(input[name=presale]) .switch__track' ).click();
await desk.locator( '.modal label:has(input[name=sell_online]) .switch__track' ).click();
await desk.screenshot( { path: `${ SHOTS }/02-the-scheme.png` } );
await desk.click( '.modal button[type=submit]' );
await desk.waitForSelector( '#mem-join', { timeout: 20000 } );

const listed = await desk.locator( '.table' ).first().innerText();

check( 'the scheme is listed with what it is worth', /20/.test( listed ) && /Friends of Northgate/.test( listed ),
	listed.split( '\n' )[ 1 ] );

const schemes = ( await api( 'GET', '/v1/memberships' ) ).body.data;

check( 'and something to sell it with was made for it', true === schemes[ 0 ].sell_online );

console.log( 'Somebody joins at the window' );
await desk.click( '#mem-join' );
await desk.waitForSelector( '#mem-email' );
await desk.fill( '#mem-email', 'lotte@example.test' );
await desk.fill( '#mem-person', 'Lotte Weber' );
await desk.click( '.modal button[type=submit]' );
await settle( 1200 );

const people = await desk.locator( '.table' ).last().innerText();

check( 'they are on the list, with the date it runs to', /Lotte Weber/.test( people ),
	people.split( '\n' ).slice( 1, 3 ).join( ' | ' ) );

await desk.screenshot( { path: `${ SHOTS }/03-a-member.png`, fullPage: true } );

console.log( 'And the nights they book first' );
const early = await desk.locator( '[data-mem-event]' ).count();

check( 'every published night can let members in early', early > 0, `${ early } nights` );

if ( early ) {
	await desk.locator( 'label:has([data-mem-event]) .switch__track' ).first().click();
	await settle( 900 );

	const events = ( await api( 'GET', '/v1/events?per_page=50' ) ).body.data || [];

	check( 'and the switch is remembered',
		events.some( ( event ) => true === event.member_presale ) );
}

console.log( 'And a buyer can join while buying a ticket' );
const guest = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();

guest.on( 'pageerror', ( e ) => errors.push( e.message ) );
guest.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await guest.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

// The seat map is a canvas; the hold is made the way the picker makes it, through the store.
const held = await guest.evaluate( async ( publicId ) => {
	const availability = await ( await fetch( '/_store/availability/' + publicId, {
		headers: { Accept: 'application/json' },
	} ) ).json();

	const free = ( availability.seats || [] ).filter( ( seat ) => 'available' === seat.state )
		.slice( 0, 2 ).map( ( seat ) => seat.seat_id );

	const response = await fetch( '/_store/hold', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			// The same token the picker sends. It arrives in the boot payload rather than in a
			// meta tag, because the widget is booted rather than rendered.
			'X-CSRF-TOKEN': ( ( window.seatmapBoot || [] )[ 0 ] || {} ).nonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { event_public_id: publicId, seat_ids: free } ),
	} );

	return { status: response.status, body: await response.json() };
}, night.public_id );

check( 'seats are held', 201 === held.status, JSON.stringify( held.body ).slice( 0, 120 ) );

await guest.goto( `${ SITE }/checkout`, { waitUntil: 'networkidle' } );

const page = await guest.locator( 'main' ).innerText();

/*
 * The scheme is offered beside the ticket, because that is how it is sold: an add-on, through the
 * same order, the same gateway and the same refund path as a programme or a glass of wine.
 */
check( 'the scheme is offered beside the ticket', /Friends of Northgate/.test( page ),
	( page.match( /Friends of Northgate[^\n]*/ ) || [ 'not offered' ] )[ 0 ] );
check( 'and priced at the joining fee', /30\.00/.test( page ) );

/*
 * And a stranger gets no discount. The member's own price is proved in `MembershipTest`, where a
 * signed-in session can be made: it is for the address this checkout already knows the buyer by,
 * and driving a real buyer sign-in here would be driving somebody else's OAuth.
 */
check( 'a stranger sees no membership discount', ! /Friends of Northgate discount/.test( page ) );

await guest.screenshot( { path: `${ SHOTS }/04-join-while-buying.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL MEMBERSHIP CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
