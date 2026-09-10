/**
 * Holding places back — the two ways, driven in Chromium.
 *
 * `ChannelQuotaTest` holds up the arithmetic. What a browser adds is the pair of promises a person
 * actually makes: an organiser keeps two named chairs for the production and caps what the website
 * may sell, and a buyer then finds those chairs simply not on offer and is stopped — with a
 * sentence, not a number — when the website's allowance runs out.
 *
 * The second refusal is the interesting one. The *same* code says two different things depending
 * on whether anything is left, and this check reads both sentences to be sure the person on the
 * end of them is being told which situation they are in.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node quota_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/quota-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

// The theatre, not the warehouse: house seats are named chairs, and the demo has a room with none.
const night = await seatedEvent( BASE, 'quota-smoke' );

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

/*
 * The buyer, opened first so the hall can be read before and after with one pair of eyes.
 *
 * Asked from inside the page rather than from node: the site answers on a hostname only a browser
 * resolves, and going round it — a Host header on a request to the API — would be asking a
 * different question than the one a buyer's browser asks.
 */
const buyer = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
buyer.on( 'pageerror', ( e ) => errors.push( e.message ) );

await buyer.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

/** What the public picker would be offered right now, asked the way the picker asks. */
const onOffer = () => buyer.evaluate( async ( publicId ) => {
	const body = await ( await fetch( `/_store/availability/${ publicId }`, {
		headers: { Accept: 'application/json' },
	} ) ).json();

	return ( body.seats || [] ).filter( ( seat ) => 'available' === seat.state ).map( ( seat ) => seat.seat_id );
}, night.public_id );

const before = await onOffer();

console.log( `The night opens with ${ before.length } chairs on public sale` );

const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1100 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Two chairs are kept for the production' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( `[data-prices="${ night.id }"]` );
await page.locator( `[data-prices="${ night.id }"]` ).click();
await page.waitForSelector( '#pricing-seats' );
await page.click( '#pricing-seats' );

await page.waitForSelector( '[data-section]' );
await page.locator( '[data-section]' ).first().click();
await page.waitForSelector( '[data-seat]' );

/*
 * Two chairs that are actually on sale right now.
 *
 * Not "the first two in the row": the demo arrives with an evening's worth of sales in it, and a
 * chair that was already sold would make "this seat left public sale" true before anybody held it
 * back. The public hall read a moment ago is what decides which two.
 */
const targets = ( await page.$$eval( '[data-seat]', ( chips ) => chips.map( ( chip ) => chip.dataset.seat ) ) )
	.filter( ( id ) => before.includes( id ) )
	.slice( 0, 2 );

if ( 2 > targets.length ) {
	throw new Error( 'The first section has fewer than two chairs on sale; re-seed before running this.' );
}

await page.locator( `[data-seat="${ targets[ 0 ] }"]` ).click();
await page.locator( `[data-seat="${ targets[ 1 ] }"]` ).click();

check( 'the seats screen offers holding a chair back, beside blocking it outright',
	await page.locator( '#seat-house' ).isVisible() && await page.locator( '#seat-block' ).isVisible() );

await page.click( '#seat-house' );
await page.waitForSelector( '#seat-held-for' );
await page.fill( '#seat-held-for', 'Production' );
await page.screenshot( { path: `${ SHOTS }/01-hold-back.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 300 );

check( 'the two chairs are marked as kept for somebody, not merely blocked',
	2 === await page.locator( '.chip--house' ).count(),
	`${ await page.locator( '.chip--house' ).count() } house, ${ await page.locator( '.chip--blocked' ).count() } blocked` );

check( 'and the name is what the chair now reads as',
	( await page.locator( '.chip--house' ).first().getAttribute( 'title' ) || '' ).includes( 'Production' ),
	await page.locator( '.chip--house' ).first().getAttribute( 'title' ) );

await page.click( '#seats-save' );
await page.waitForTimeout( 900 );

const kept = await api( 'GET', `/v1/events/${ night.id }/seat-prices` );
const house = kept.sections
	.flatMap( ( section ) => section.rows )
	.flatMap( ( row ) => row.seats )
	.filter( ( seat ) => 'Production' === seat.held_for );

check( 'the server kept both, blocked and labelled',
	2 === house.length && house.every( ( seat ) => true === seat.blocked ),
	JSON.stringify( house.map( ( seat ) => [ seat.label, seat.blocked, seat.held_for ] ) ) );

console.log( 'The website is promised a small allowance' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( `[data-quotas="${ night.id }"]` );
await page.locator( `[data-quotas="${ night.id }"]` ).click();
await page.waitForSelector( '.quota-row', { timeout: 15000 } );

const channels = ( await api( 'GET', `/v1/events/${ night.id }/quotas` ) ).data;
const website = channels.find( ( channel ) => 'storefront' === channel.kind );

check( 'every channel is listed, the ones with no limit too',
	channels.length >= 2 && !! website,
	channels.map( ( channel ) => `${ channel.name } (${ channel.kind })` ).join( ', ' ) );
check( 'the organiser’s own website is one of them before it has sold anything',
	null === website.places && 0 === website.taken,
	JSON.stringify( { places: website.places, taken: website.taken } ) );

// Room for exactly one place, so a check can watch the allowance run out.
await page.fill( `[data-channel="${ website.api_client_id }"]`, '1' );
await page.screenshot( { path: `${ SHOTS }/02-quotas.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 900 );

const saved = ( await api( 'GET', `/v1/events/${ night.id }/quotas` ) ).data
	.find( ( channel ) => channel.api_client_id === website.api_client_id );

check( 'and it sticks', 1 === saved.places, JSON.stringify( { places: saved.places, left: saved.left } ) );

console.log( 'The buyer never sees the chairs that were kept' );
const after = await onOffer();

/*
 * Asked as two sets rather than as a difference of two counts.
 *
 * The demo's own seeded holds expire while this runs, so the hall's total moves on its own — and a
 * check that subtracted one number from another would fail for a reason that has nothing to do
 * with what it is testing. What matters is these two chairs, by name.
 */
check( 'the two chairs were on public sale to start with',
	house.every( ( seat ) => before.includes( seat.id ) ),
	`${ before.length } chairs before, ${ after.length } after` );
check( 'and are not any more', house.every( ( seat ) => ! after.includes( seat.id ) ),
	house.map( ( seat ) => seat.label ).join( ', ' ) );

// Freshly drawn, so the picture in front of the buyer is the one the organiser has just changed.
await buyer.reload( { waitUntil: 'networkidle' } );
await buyer.waitForSelector( '.seatmap-widget' );

const drawn = await buyer.evaluate( ( ids ) => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;

	return widget.seats.filter( ( seat ) => ids.includes( seat.id ) && 'available' === seat.state ).length;
}, house.map( ( seat ) => seat.id ) );

check( 'the picker draws neither of them as something to click', 0 === drawn, `${ drawn } offered` );

console.log( 'And the allowance is what stops the rest' );

/**
 * Ask for places the way the picker asks, with the page's own token.
 *
 * Through the site rather than the API, because the whole point of counting holds is that the
 * website is a channel like any other and had until now no way of being told it had had enough.
 */
const ask = ( quantity ) => buyer.evaluate( async ( wanted ) => {
	// The picker's own settings, off the widget it booted: `window.seatmapBoot` is a queue the
	// widget replaces with a function once it has drunk it, and is no longer a list to index into.
	const boot = document.querySelector( '.seatmap-widget' ).seatmapWidget.config;
	const response = await fetch( '/_store/hold', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			[ boot.nonceHeader ]: boot.nonce,
		},
		body: JSON.stringify( {
			event_public_id: boot.eventPublicId,
			best_available: { quantity: wanted },
		} ),
	} );

	return { status: response.status, body: await response.json() };
}, quantity );

const two = await ask( 2 );

check( 'two places are refused, because one is all that was promised',
	409 === two.status && 'channel_quota_reached' === two.body.error?.code,
	`${ two.status } ${ two.body.error?.code }` );
check( 'and the refusal says how many are left rather than merely no',
	/1/.test( two.body.error?.message || '' ), two.body.error?.message );
check( 'with the numbers a screen could show', 1 === two.body.error?.details?.left &&
	2 === two.body.error?.details?.wanted, JSON.stringify( two.body.error?.details ) );

const one = await ask( 1 );

check( 'one place is allowed', 201 === one.status, `${ one.status } ${ one.body.error?.code || '' }` );

const third = await ask( 1 );

check( 'and the next buyer through the same website is stopped',
	409 === third.status && 'channel_quota_reached' === third.body.error?.code,
	`${ third.status } ${ third.body.error?.code }` );
check( 'told the allocation is gone, which is a different sentence from “one left”',
	third.body.error?.message !== two.body.error?.message && ! /\d/.test( third.body.error?.message || '' ),
	third.body.error?.message );

await buyer.screenshot( { path: `${ SHOTS }/03-buyer.png` } );

console.log( 'The counter can still sell what the website cannot' );
const hall = await api( 'GET', `/v1/events/${ night.id }/counter` );
const atTheWindow = hall.sections
	.flatMap( ( section ) => section.rows )
	.flatMap( ( row ) => row.seats )
	.filter( ( seat ) => house.some( ( kept ) => kept.id === seat.id ) );

check( 'the window is the one hall that has them in it',
	2 === atTheWindow.length && atTheWindow.every( ( seat ) => 'available' === seat.state &&
		'Production' === seat.held_for ),
	JSON.stringify( atTheWindow.map( ( seat ) => [ seat.state, seat.held_for ] ) ) );

const sold = await api( 'POST', `/v1/events/${ night.id }/sell`, {
	seat_ids: [ house[ 0 ].id ],
	buyer: { name: 'The director’s mother' },
	payment: 'comp',
} );

check( 'and a house seat is handed over there', !! sold.reference,
	JSON.stringify( sold.error || { reference: sold.reference, payment: sold.payment } ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
