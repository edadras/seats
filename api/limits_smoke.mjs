/**
 * "Four per person", and the two questions a script gets wrong — driven in Chromium.
 *
 * `PurchaseLimitTest` holds up the arithmetic. What a browser adds is the shape of the promise as a
 * buyer meets it: the limit is written above the seat map, before anybody chooses, because a
 * refusal at the checkout is correct and is also a wasted evening.
 *
 * Then the defences, from the outside: a form filled in by something that fills every field it
 * finds, and a form sent faster than a person can fill one in. Neither is a CAPTCHA, and no part of
 * this sends a buyer's behaviour anywhere to be scored.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node limits_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/limits-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'limits-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

// What the demo already has in it, so this run counts only what it books itself.
const ordersBefore = ( ( await api( 'GET', '/v1/orders?per_page=100' ) ).body.data || [] ).length;

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

console.log( 'The organiser sets a limit' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( `[data-event-edit="${ night.id }"]` );
await page.locator( `[data-event-edit="${ night.id }"]` ).click();
// Attached rather than visible: the part it lives in is closed until somebody opens it.
await page.waitForSelector( '#e-per-buyer', { state: 'attached' } );

// Both live in the part of the form about limits at the checkout.
await page.locator( '.modal .form-group', { has: page.locator( '#e-per-buyer' ) } )
	.locator( 'summary' ).click();
await page.waitForTimeout( 200 );

check( 'the event form asks how many one person may buy',
	await page.locator( '#e-per-buyer' ).isVisible() );
check( 'and how long is too fast for a checkout',
	await page.locator( '#e-min-seconds' ).isVisible() );
check( 'with no limit as the placeholder, because that is the ordinary case',
	'' === await page.locator( '#e-per-buyer' ).inputValue(),
	await page.locator( '#e-per-buyer' ).getAttribute( 'placeholder' ) );

await page.fill( '#e-per-buyer', '1' );
await page.screenshot( { path: `${ SHOTS }/01-form.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1500 );

const saved = ( await api( 'GET', `/v1/events/${ night.id }` ) ).body;

check( 'and it sticks', 1 === saved.max_per_buyer, JSON.stringify( { per_buyer: saved.max_per_buyer } ) );

console.log( 'A buyer is told before they choose' );
const buyer = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
buyer.on( 'pageerror', ( e ) => errors.push( e.message ) );

await buyer.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await buyer.waitForSelector( '.seatmap-widget' );

const limit = await buyer.locator( '.booking__limit' ).innerText();

check( 'the limit is above the seat map, not at the checkout', /1|one/i.test( limit ), limit );

/** Choose one seat and go through to the checkout page. */
const toCheckout = async () => {
	await openASection( buyer );
	const point = await seatPoint( buyer, 0 );
	await buyer.mouse.click( point.x, point.y );
	await buyer.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
	await buyer.click( '.seatmap-widget__submit' );
	await buyer.waitForURL( /checkout/, { timeout: 20000 } );
	await buyer.waitForSelector( '#name' );
};

await toCheckout();

console.log( 'The field that is not there' );

check( 'the checkout carries a field a person cannot see',
	1 === await buyer.locator( '#website' ).count() );
check( 'and cannot tab to', '-1' === await buyer.locator( '#website' ).getAttribute( 'tabindex' ) );
check( 'and no screen reader ever meets',
	'true' === await buyer.locator( '.nowhere' ).getAttribute( 'aria-hidden' ) );

// A script fills every input it finds, and says so about itself in the process.
await buyer.fill( '#name', 'A script' );
await buyer.fill( '#email', 'script@example.test' );
await buyer.evaluate( () => { document.getElementById( 'website' ).value = 'https://buy-cheap.example'; } );
await buyer.click( '.checkout__submit' );
await buyer.waitForSelector( '.checkout__refusal', { timeout: 20000 } );

check( 'a form that filled it is refused, in a sentence',
	( await buyer.locator( '.checkout__refusal' ).innerText() ).length > 10,
	await buyer.locator( '.checkout__refusal' ).innerText() );

const after = ( ( await api( 'GET', '/v1/orders?per_page=100' ) ).body.data || [] ).length;

check( 'and nothing was booked', after === ordersBefore, `${ ordersBefore } → ${ after }` );

/*
 * Somebody the demo has never sold to.
 *
 * The seeded evening already has six buyers in it, and one of them booking again would be refused
 * for the right reason at the wrong moment — which would prove nothing about the checkout.
 */
console.log( 'The buyer, doing it properly' );
await buyer.fill( '#name', 'A new buyer' );
await buyer.fill( '#email', 'a-new-buyer@example.test' );
await buyer.evaluate( () => { document.getElementById( 'website' ).value = ''; } );
await buyer.click( '.checkout__submit' );
await buyer.waitForLoadState( 'networkidle' );
await buyer.waitForTimeout( 1200 );

check( 'the booking goes through', /\/order\//.test( buyer.url() ), buyer.url() );

console.log( 'And the same person, coming back for one more' );
const second = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
second.on( 'pageerror', ( e ) => errors.push( e.message ) );

await second.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await second.waitForSelector( '.seatmap-widget' );
await openASection( second );
const point = await seatPoint( second, 0 );
await second.mouse.click( point.x, point.y );
await second.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
await second.click( '.seatmap-widget__submit' );
await second.waitForURL( /checkout/, { timeout: 20000 } );
await second.fill( '#name', 'A new buyer' );
await second.fill( '#email', 'a-new-buyer@example.test' );
await second.click( '.checkout__submit' );
await second.waitForSelector( '.checkout__refusal', { timeout: 20000 } );

const refusal = await second.locator( '.checkout__refusal' ).innerText();

check( 'they are told they already have their share, with the number on it',
	/1/.test( refusal ), refusal );

await second.screenshot( { path: `${ SHOTS }/02-refused.png` } );

const orders = ( ( await api( 'GET', '/v1/orders?per_page=100' ) ).body.data || [] ).length;

check( 'and the second booking was never made', orders === ordersBefore + 1,
	`${ ordersBefore } → ${ orders }` );

console.log( 'Too fast to be a person' );
await api( 'PATCH', `/v1/events/${ night.id }`, { checkout_min_seconds: 30 } );

const quick = await ( await browser.newContext() ).newPage();
quick.on( 'pageerror', ( e ) => errors.push( e.message ) );

await quick.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await openASection( quick );
const spot = await seatPoint( quick, 0 );
await quick.mouse.click( spot.x, spot.y );
await quick.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
await quick.click( '.seatmap-widget__submit' );
await quick.waitForURL( /checkout/, { timeout: 20000 } );
await quick.fill( '#name', 'Very Fast' );
await quick.fill( '#email', 'fast@example.test' );
await quick.click( '.checkout__submit' );
await quick.waitForSelector( '.checkout__refusal', { timeout: 20000 } );

check( 'a checkout sent in two seconds is refused, and says why',
	( await quick.locator( '.checkout__refusal' ).innerText() ).length > 10,
	await quick.locator( '.checkout__refusal' ).innerText() );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
