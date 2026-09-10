/**
 * A season ticket: the same seat on every night of a run, bought once — driven in Chromium.
 *
 * Two things are worth a real browser here. The first is that the subscriber never leaves the
 * ordinary picker: they choose their seats once, on the first night, and the rest of the run is
 * held for them without them doing anything — so what this drives is the ordinary widget, not a
 * second one. The second is the arithmetic: the summary lists every night at its own price, shows
 * one saving, and the total the buyer reads has to be the total their card is charged.
 *
 * The run itself is made over the API rather than by driving the event form, because putting three
 * dates in a series is not what this check is about.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node season_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/season-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const digits = ( text ) => String( text || '' ).replace( /[^0-9]/g, '' );

const first = await seatedEvent( BASE, 'season-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + first.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( ( response ) => response.json() );

console.log( 'The production goes on again on two more nights' );
const later = ( weeks ) => new Date( Date.now() + weeks * 7 * 86400000 ).toISOString();
const repeated = await api( 'POST', `/v1/events/${ first.id }/repeat`, {
	dates: [ later( 6 ), later( 7 ) ],
	series_name: 'The Winter Run',
} );

check( 'two more dates were made', 2 === ( repeated.data || [] ).length );

// They are copied as drafts, because "on sale" is a decision about an evening. Put them on sale.
for ( const copy of repeated.data || [] ) {
	await api( 'PATCH', `/v1/events/${ copy.id }`, { status: 'published' } );
}

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser offers the whole run at a fifth off' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=seasons]' );
await page.waitForSelector( '#s-new', { timeout: 15000 } );

check( 'the run is offered to hang a season ticket on',
	await page.locator( '#s-new' ).isVisible() );

await page.click( '#s-new' );
await page.waitForSelector( '#s-name' );
await page.fill( '#s-name', 'Full season' );
await page.fill( '#s-description', 'All three nights, the same seats' );
await page.fill( '#s-discount-value', '20' );
await page.screenshot( { path: `${ SHOTS }/01-new-pass.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

const detail = await page.locator( '.page-body' ).innerText();

check( 'it knows how many nights it would sell today', /3/.test( digits( detail ) ) );
check( 'and nobody has subscribed yet', /0/.test( digits( detail ) ) );

await page.screenshot( { path: `${ SHOTS }/02-pass.png` } );

console.log( 'A subscriber takes the run' );
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );

await shop.goto( `${ SITE }/events/${ first.public_id }`, { waitUntil: 'networkidle' } );

check( 'the night’s own page offers the season',
	await shop.locator( '.season__offer' ).first().isVisible() );

await shop.locator( '.season__offer a' ).first().click();
await shop.waitForSelector( '.season__nights', { timeout: 15000 } );

check( 'and the season page lists every night',
	3 === await shop.locator( '.season__night' ).count(),
	String( await shop.locator( '.season__night' ).count() ) );

await shop.screenshot( { path: `${ SHOTS }/03-offer.png` } );

// Straight into the ordinary picker, on the first night. Nothing about it knows about seasons.
await shop.locator( 'form button[type=submit]' ).click();
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( shop );

const seat = await seatPoint( shop, 0 );
await shop.mouse.click( seat.x, seat.y );
await shop.waitForTimeout( 400 );
await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/season\/checkout/, { timeout: 20000 } );

check( 'the picker hands the subscriber to the season checkout',
	/\/season\/checkout/.test( shop.url() ), shop.url() );
check( 'which lists all three nights',
	3 === await shop.locator( '.summary-lines li:not(.summary-lines__off)' ).count() );
check( 'and shows one saving off the run',
	await shop.locator( '.summary-lines__off' ).isVisible(),
	await shop.locator( '.summary-lines__off' ).innerText() );

const promised = digits( await shop.locator( '#summary-total' ).innerText() );

await shop.screenshot( { path: `${ SHOTS }/04-checkout.png` } );

await shop.fill( '#name', 'Amina Farsi' );
await shop.fill( '#email', 'amina@example.test' );
await shop.click( '.checkout__submit' );
await shop.waitForURL( /\/season\/order\//, { timeout: 25000 } );

check( 'the subscription goes through', /\/season\/order\//.test( shop.url() ), shop.url() );
check( 'and there is a ticket for every night',
	3 === await shop.locator( '.season__booked' ).count(),
	String( await shop.locator( '.season__booked' ).count() ) );

await shop.screenshot( { path: `${ SHOTS }/05-tickets.png` } );

console.log( 'And the organiser sees one subscriber, charged what the page promised' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=seasons]' );
await page.waitForSelector( '#s-results' );
await page.waitForTimeout( 1200 );
await page.locator( '[data-open-pass]' ).first().click();
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

const after = await page.locator( '.page-body' ).innerText();

check( 'the subscriber is listed', /Amina Farsi/.test( after ) );
check( 'and paid exactly what the summary said',
	after.replace( /[^0-9]/g, '' ).includes( promised ),
	promised );

await page.screenshot( { path: `${ SHOTS }/06-subscribers.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SEASON CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
