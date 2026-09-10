/**
 * Money taken back after the tickets were sent — driven in Chromium, from the order screen.
 *
 * `ChargebackTest` holds up the rules. What a browser adds is the sequence a box office actually
 * lives through: a booking that looks perfectly good this morning, a letter from the bank this
 * afternoon, and — the part that matters — a QR code that goes red at the door tonight without
 * anybody remembering to void anything.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node chargeback_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/chargeback-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'chargeback-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json().catch( () => ( {} ) ) } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Somebody buys a seat, the ordinary way' );
await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.seatmap-widget' );
await openASection( page );

const point = await seatPoint( page, 0 );
await page.mouse.click( point.x, point.y );
await page.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
await page.click( '.seatmap-widget__submit' );
await page.waitForURL( /checkout/, { timeout: 20000 } );
await page.waitForSelector( '#name' );
await page.fill( '#name', 'Disputed Buyer' );
await page.fill( '#email', 'disputed@example.test' );
await page.click( '.checkout__submit' );
await page.waitForURL( /\/order\//, { timeout: 30000 } );

const reference = page.url().split( '/order/' )[ 1 ].split( /[?#]/ )[ 0 ];

check( 'the booking exists', !! reference, reference );

/** How many chairs the public plan says are free, asked the way a buyer's browser asks. */
const freeSeats = async () => {
	const answer = await ( await fetch(
		`${ BASE }/v1/embed/events/${ night.public_id }/availability`
	) ).json();

	return ( answer.seats || [] ).filter( ( seat ) => 'available' === seat.state ).length;
};

const seatsBefore = await freeSeats();

console.log( 'Weeks later, the bank takes the money back' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '[data-order]', { timeout: 20000 } );

// This booking, found by the reference the buyer was shown.
await page.fill( '#order-search', reference ).catch( () => {} );
await page.waitForTimeout( 1200 );
await page.locator( '[data-order]' ).first().click();
await page.waitForSelector( '#order-chargeback', { timeout: 20000 } );

check( 'the order screen offers it, and not as a refund',
	await page.locator( '#order-chargeback' ).isVisible() &&
	await page.locator( '#order-refund' ).isVisible() );

await page.screenshot( { path: `${ SHOTS }/01-order.png` } );

await page.click( '#order-chargeback' );
await page.waitForSelector( '#cb-reason' );
await page.fill( '#cb-reason', 'Cardholder does not recognise the payment' );
await page.fill( '#cb-fee', '15' );

check( 'barring the buyer is offered and starts unticked',
	! await page.locator( '#cb-block' ).isChecked() );

await page.screenshot( { path: `${ SHOTS }/02-chargeback.png` } );

await page.locator( '#cb-block' ).check();
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 2500 );

console.log( 'The seat is back on sale and the ticket has stopped working' );
const seatsAfter = await freeSeats();

check( 'the chair is sellable again tonight', seatsAfter === seatsBefore + 1,
	`${ seatsBefore } → ${ seatsAfter }` );

const order = ( await api( 'GET', '/v1/orders?per_page=100' ) ).body.data
	.filter( ( row ) => row.reference === reference )[ 0 ];

check( 'the booking says what happened, in its own word', order && 'charged_back' === order.status,
	order ? order.status : 'missing' );

// Searched by the reference rather than paged through: this hall has hundreds of chairs in it.
const tickets = ( await api(
	'GET',
	`/v1/tickets?event_id=${ night.id }&q=${ encodeURIComponent( reference ) }&per_page=100`
) ).body.data || [];
const theirs = tickets.filter( ( ticket ) => ticket.order && reference === ticket.order.reference );

check( 'and the code at the door is void',
	theirs.length > 0 && theirs.every( ( ticket ) => 'void' === ticket.status ),
	theirs.map( ( ticket ) => ticket.status ).join( ', ' ) || 'none found' );

console.log( 'And the buyer cannot simply book again' );
const blocked = ( await api( 'GET', '/v1/blocked-buyers' ) ).body.data || [];

check( 'they are on the list, with the reason somebody typed',
	blocked.some( ( row ) => 'disputed@example.test' === row.email && /recognise/.test( row.reason ) ),
	JSON.stringify( blocked ).slice( 0, 140 ) );

const again = await ( await browser.newContext() ).newPage();
await again.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await again.waitForSelector( '.seatmap-widget' );
await openASection( again );

const seat = await seatPoint( again, 0 );
await again.mouse.click( seat.x, seat.y );
await again.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
await again.click( '.seatmap-widget__submit' );
await again.waitForURL( /checkout/, { timeout: 20000 } );
await again.fill( '#name', 'Disputed Buyer' );
await again.fill( '#email', 'disputed@example.test' );
await again.click( '.checkout__submit' );
await again.waitForTimeout( 3000 );

const said = await again.locator( 'body' ).innerText();

check( 'the checkout refuses them', ! /\/order\//.test( again.url() ), again.url() );
// A sentence on the checkout page, not a page of JSON: a buyer who meets raw JSON telephones a
// box office that cannot see it either.
check( 'and tells them to ring rather than failing silently',
	/box office/i.test( said ) && ! /"error"/.test( said ),
	said.split( '\n' ).filter( Boolean ).slice( 0, 3 ).join( ' | ' ) );
// The note is somebody's record about a person, not a message to them.
check( 'without repeating what was written about them', ! /recognise/i.test( said ) );

await again.screenshot( { path: `${ SHOTS }/03-refused.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
