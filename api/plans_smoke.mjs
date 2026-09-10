/**
 * A school books forty seats on a deposit, driven in Chromium.
 *
 * `PaymentPlanTest` holds up the rules. What a browser adds is the arrangement as a box office
 * lives it: a party sold at the window on a deposit, a ticket column that stays empty because
 * nothing has been paid for yet, a chase list somebody works down on a Monday, and the moment the
 * balance is recorded and forty codes come into existence at once.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node plans_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/plans-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'plans-smoke' );

const api = ( method, path ) => fetch( BASE + path, {
	method,
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + night.token },
} ).then( async ( response ) => ( { status: response.status, body: await response.json().catch( () => ( {} ) ) } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1050 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

/** How many chairs the public plan says are free, asked the way a buyer's browser asks. */
const free = async () => {
	const answer = await ( await fetch(
		`${ BASE }/v1/embed/events/${ night.public_id }/availability`
	) ).json();

	return ( answer.seats || [] ).filter( ( seat ) => 'available' === seat.state ).length;
};

const seatsBefore = await free();

console.log( 'A party of four, on a deposit' );
await page.click( 'nav button[data-view=counter]' );
await page.waitForSelector( '#counter-event' );
await page.selectOption( '#counter-event', { label: 'Opening night' } );
await page.waitForSelector( '.counter__blocks' );
await page.locator( '.counter__block:not([disabled])' ).first().click();
await page.waitForSelector( '.counter__seat' );

for ( let seat = 0; seat < 4; seat++ ) {
	await page.locator( '.counter__seat:not([disabled])' ).nth( seat ).click();
}

await page.waitForTimeout( 300 );
await page.click( '#counter-sell' );
await page.waitForSelector( '.modal' );
await page.fill( '#c-name', 'Miss Fielding' );
await page.fill( '#c-email', 'office@stmarys.test' );
await page.fill( '#c-group', "St Mary's School" );

check( 'the plan fields are out of the way until a plan is what this is',
	await page.locator( '#c-plan-fields' ).isHidden() );

await page.selectOption( '#c-payment', 'plan' );
await page.waitForTimeout( 200 );

check( 'and appear when it is', await page.locator( '#c-plan-fields' ).isVisible() );
// A deposit is money taken now, so it wants a method as much as a paid sale does.
check( 'with somewhere to say how the deposit arrived',
	await page.locator( '#c-method-field' ).isVisible() );

await page.fill( '#c-deposit', '20' );
await page.fill( '#c-instalments', '2' );
await page.fill( '#c-every', '30' );
await page.selectOption( '#c-method', 'cash' );
await page.screenshot( { path: `${ SHOTS }/01-sell.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );

const toast = await page.locator( '.toast' ).innerText();
const reference = ( toast.match( /bo-[a-z0-9]+/ ) || [ '' ] )[ 0 ];

check( 'the sale completes with a booking reference', !! reference, toast );

console.log( 'The seats are theirs and the codes are not' );
const seatsLeft = await free();
const tickets = ( await api(
	'GET',
	`/v1/tickets?event_id=${ night.id }&q=${ encodeURIComponent( reference ) }&per_page=100`
) ).body.data || [];

check( 'four chairs have gone out of sale on a deposit', seatsLeft === seatsBefore - 4,
	`${ seatsBefore } → ${ seatsLeft }` );
check( 'and nothing that opens a door exists yet', 0 === tickets.length,
	tickets.length + ' tickets' );

await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '[data-order]', { timeout: 20000 } );
await page.fill( '#order-search', reference ).catch( () => {} );
await page.waitForTimeout( 1200 );
await page.locator( '[data-order]' ).first().click();
await page.waitForSelector( '[data-pay]', { timeout: 20000 } );

const detail = await page.locator( '#main' ).innerText();

check( 'the booking screen says what is still to come', /still to come/i.test( detail ),
	detail.split( '\n' ).filter( ( line ) => /still to come/i.test( line ) )[ 0 ] || '' );
check( 'and shows the party rather than only the teacher', detail.includes( "St Mary's School" ) );
check( 'the deposit is already down as paid',
	2 === await page.locator( '[data-pay]' ).count(), 'two of three left to pay' );

await page.screenshot( { path: `${ SHOTS }/02-plan.png` } );

console.log( 'The list somebody works down on a Monday' );
await page.click( 'nav button[data-view=plans]' );
await page.waitForSelector( '#plan-state', { timeout: 20000 } );

const list = await page.locator( '#main' ).innerText();

check( 'the party is on the chase list', list.includes( "St Mary's School" ) );
// The row carries the whole booking's balance beside its own amount: a party three payments
// behind is a different telephone call from one a week late.
const owed = ( await api( 'GET', '/v1/instalments' ) ).body.data
	.filter( ( row ) => row.reference === reference );
const money = ( minor ) => ( minor / 100 ).toFixed( 2 );

check( 'with the whole booking owing, not one line of it',
	owed.length > 0 && owed[ 0 ].balance > owed[ 0 ].amount &&
	list.includes( money( owed[ 0 ].balance ) ) && list.includes( money( owed[ 0 ].amount ) ),
	owed.length ? `${ money( owed[ 0 ].amount ) } of ${ money( owed[ 0 ].balance ) }` : 'not listed' );

await page.selectOption( '#plan-state', 'overdue' );
await page.waitForTimeout( 900 );

check( 'and nothing is late yet', /Nothing is owed|nothing/i.test(
	await page.locator( '#main' ).innerText()
) );

await page.screenshot( { path: `${ SHOTS }/03-chase.png` } );

console.log( 'The balance arrives, and the tickets exist' );
await page.selectOption( '#plan-state', 'all' );
await page.waitForTimeout( 900 );
await page.locator( '[data-booking]' ).first().click();
await page.waitForSelector( '[data-pay]', { timeout: 20000 } );

// Every remaining step, one after another: the codes are minted by the last of them.
for ( let round = 0; round < 4; round++ ) {
	const left = await page.locator( '[data-pay]' ).count();

	if ( ! left ) {
		break;
	}

	await page.locator( '[data-pay]' ).first().click();
	await page.waitForSelector( '#pay-method' );
	await page.selectOption( '#pay-method', 'transfer' );
	await page.click( '.modal button[type=submit]' );
	await page.waitForSelector( '.modal', { state: 'detached' } );
	await page.waitForTimeout( 1500 );
}

const settled = await page.locator( '#main' ).innerText();

check( 'the plan reads as paid in full', /paid in full/i.test( settled ),
	settled.split( '\n' ).filter( ( line ) => /paid in full/i.test( line ) )[ 0 ] || '' );

const after = ( await api(
	'GET',
	`/v1/tickets?event_id=${ night.id }&q=${ encodeURIComponent( reference ) }&per_page=100`
) ).body.data || [];

check( 'and four codes exist that did not before',
	4 === after.length && after.every( ( ticket ) => 'issued' === ticket.status ),
	after.length + ' tickets' );

await page.screenshot( { path: `${ SHOTS }/04-settled.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
