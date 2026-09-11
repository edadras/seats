/**
 * The till, driven in Chromium.
 *
 * `TillTest` holds up the arithmetic. What a browser adds is the eleven o'clock question in the
 * form somebody actually asks it: a clerk opens a drawer with a float in it, sells one for cash and
 * one on a card, pays for a taxi out of the till, then counts what is in front of them and finds
 * out — on the screen, in words — whether it balanced.
 *
 * The card sale is the interesting one: it is money that never touched the drawer, and a till that
 * counted it would report every honest evening several hundred short.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node till_smoke.mjs
 */
import { chromium } from 'playwright';
import { openHall, chooseSeats, startSale } from './counter-hall.mjs';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/till-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'till-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const digits = ( text ) => Number( String( text ).replace( /[^\d]/g, '' ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'Opening the drawer' );
await page.click( 'nav button[data-view=tills]' );
await page.waitForSelector( '#till-open', { timeout: 20000 } );

check( 'a venue with no till open is told so plainly',
	( await page.locator( 'body' ).innerText() ).includes( 'No till open' ) );

await page.click( '#till-open' );
await page.waitForSelector( '#till-float' );
await page.fill( '#till-float', '10000' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '#till-close', { timeout: 15000 } );

const opened = ( await api( 'GET', '/v1/shifts/current' ) ).body.data;

check( 'the till starts with what is in the drawer',
	10000 === opened.opening_float && 10000 === opened.expected_cash,
	JSON.stringify( { float: opened.opening_float, expected: opened.expected_cash } ) );
check( 'and nothing has been counted yet, which is not the same as balancing',
	null === opened.counted_cash && null === opened.difference );

console.log( 'Selling at the window' );

/** One seat, sold at the counter, paid for the given way. */
const sell = async ( method ) => {
	await page.click( 'nav button[data-view=counter]' );
	await page.waitForSelector( '#counter-event', { timeout: 20000 } );
	await page.selectOption( '#counter-event', night.id );
	await openHall( page );
	await chooseSeats( page, 1 );
	await startSale( page );
	await page.waitForSelector( '#c-name' );
	await page.fill( '#c-name', 'At the window' );
	await page.selectOption( '#c-method', method );
	await page.click( '.modal button[type=submit]' );
	await page.waitForTimeout( 1500 );
};

await sell( 'cash' );
await sell( 'card' );

await page.click( 'nav button[data-view=tills]' );
await page.waitForSelector( '#till-close', { timeout: 20000 } );

const afterSales = ( await api( 'GET', '/v1/shifts/current' ) ).body.data;

check( 'the cash sale is in the drawer', afterSales.takings.cash > 0,
	`cash ${ afterSales.takings.cash }` );
check( 'the card sale is not — it never touched it', afterSales.takings.card > 0 &&
	afterSales.expected_cash === 10000 + afterSales.takings.cash,
	JSON.stringify( { card: afterSales.takings.card, expected: afterSales.expected_cash } ) );

const tiles = await page.locator( '.stat-grid' ).innerText();

check( 'and the screen leads with what should be in the drawer',
	digits( tiles.split( '\n' )[ 0 ] ) > 0, tiles.replace( /\n/g, ' | ' ) );

console.log( 'Money that is not a sale' );
await page.click( '#till-out' );
await page.waitForSelector( '#move-amount' );
await page.fill( '#move-amount', '1800' );
await page.fill( '#move-reason', 'Taxi for the sound engineer' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1200 );

const afterTaxi = ( await api( 'GET', '/v1/shifts/current' ) ).body.data;

check( 'a taxi out of the drawer comes off the total',
	afterTaxi.expected_cash === afterSales.expected_cash - 1800,
	`${ afterSales.expected_cash } → ${ afterTaxi.expected_cash }` );
check( 'and it is written down with its reason', 1800 === afterTaxi.movements.out );

await page.screenshot( { path: `${ SHOTS }/01-open.png`, fullPage: true } );

console.log( 'Counting it' );
await page.click( '#till-close' );
await page.waitForSelector( '#till-counted' );

const suggested = digits( await page.locator( '#till-counted' ).inputValue() );

check( 'the count starts from what the drawer should hold',
	suggested === afterTaxi.expected_cash, `${ suggested }` );

// Four short, which is the finding rather than a mistake to be corrected.
await page.fill( '#till-counted', String( afterTaxi.expected_cash - 400 ) );
await page.fill( '#till-note', 'Counted twice.' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '#till-open', { timeout: 15000 } );

const closed = ( await api( 'GET', '/v1/shifts' ) ).body.data[ 0 ];

check( 'the drawer is closed with the difference on it',
	-400 === closed.difference && false === closed.open,
	JSON.stringify( { expected: closed.expected_cash, counted: closed.counted_cash, difference: closed.difference } ) );

const history = await page.locator( 'table' ).last().innerText();

check( 'and the shift is listed with the shortfall shown, not hidden',
	history.includes( '−' ) || history.includes( '-' ), history.replace( /\n/g, ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/02-closed.png`, fullPage: true } );

console.log( 'A closed drawer stays closed' );
const again = await api( 'POST', `/v1/shifts/${ closed.id }/close`, { counted_cash: 99999 } );

check( 'because a discrepancy that can be edited afterwards is not one',
	409 === again.status && 'till_closed' === again.body.error?.code,
	`${ again.status } ${ again.body.error?.code }` );

console.log( 'Persian' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'fa' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=tills]' );
await page.waitForSelector( '#till-open', { timeout: 20000 } );

const fa = await page.locator( '.page-head' ).innerText();

check( 'the till screen is Persian', /صندوق/.test( fa ), fa.replace( /\n/g, ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/03-persian.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
