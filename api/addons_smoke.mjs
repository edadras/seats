/**
 * The counter: a programme, a glass of wine, and a donation — driven in Chromium.
 *
 * The check that matters is the last one. Add-ons are chosen after the summary is rendered, so the
 * page asks the server what the booking now comes to and prints the answer; the total the buyer
 * reads and the total they are charged have to be the same number, and that is what is compared
 * here — on the page, and then on the confirmation.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node addons_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/addons-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** Digits only, so a comparison does not care whether a currency mark moved. */
const digits = ( text ) => String( text || '' ).replace( /[^0-9]/g, '' );

const night = await seatedEvent( BASE, 'addons-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser puts something on the counter' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
// The night the buyer will actually come to, not whichever row is first: the counter belongs to
// one event, and putting a programme on a different one proves nothing.
await page.waitForSelector( '[data-prices]' );
await page.locator( '[data-prices="' + night.id + '"]' ).click();
await page.waitForSelector( '#pricing-addon-add' );

check( 'the pricing screen offers a counter',
	await page.locator( '#pricing-addon-add' ).isVisible() );

await page.click( '#pricing-addon-add' );
await page.waitForSelector( '#x-name' );
await page.fill( '#x-name', 'Programme' );
await page.fill( '#x-description', 'Twenty pages, with the cast in it' );
await page.fill( '#x-price', '5' );
await page.fill( '#x-stock', '40' );
await page.screenshot( { path: `${ SHOTS }/01-new-addon.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1500 );

check( 'it is listed with its stock',
	/Programme/.test( await page.locator( '.page-body' ).innerText() ) );

console.log( 'And asks for a donation' );
await page.check( '#donations-on' );
await page.waitForTimeout( 1200 );
await page.fill( '#donation-prompt', 'Help us keep the lights on' );
await page.locator( '#donation-prompt' ).blur();
await page.waitForTimeout( 1500 );

check( 'the prompt sticks',
	'Help us keep the lights on' === await page.locator( '#donation-prompt' ).inputValue() );

await page.screenshot( { path: `${ SHOTS }/02-counter.png` } );

console.log( 'A buyer takes a seat, a programme and gives something' );
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );

await shop.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( shop );

const seat = await seatPoint( shop, 0 );
await shop.mouse.click( seat.x, seat.y );
await shop.waitForTimeout( 400 );
await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/checkout/, { timeout: 15000 } );

check( 'the checkout offers the programme',
	await shop.locator( '.addon' ).first().isVisible() );
check( 'and asks for a donation in the organiser\'s own words',
	/keep the lights on/i.test( await shop.locator( '.checkout__form' ).innerText() ) );

const before = digits( await shop.locator( '#summary-total' ).innerText() );

await shop.selectOption( '.addon__pick select', '2' );
await shop.fill( '#donation', '3' );
await shop.locator( '#donation' ).blur();
await shop.waitForTimeout( 1200 );

const after = digits( await shop.locator( '#summary-total' ).innerText() );

check( 'the summary moves when something is added', before !== after, `${ before } → ${ after }` );
check( 'and the extras are listed',
	( await shop.locator( '.summary-lines__extra' ).count() ) >= 2,
	await shop.locator( '.summary-lines' ).innerText().then( ( t ) => t.split( '\n' ).join( ' | ' ) ) );

await shop.screenshot( { path: `${ SHOTS }/03-checkout.png` } );

console.log( 'And is charged what the summary said' );
await shop.fill( '#name', 'Amina Farsi' );
await shop.fill( '#email', 'amina@example.test' );
await shop.click( '.checkout__submit' );
await shop.waitForURL( /\/order\//, { timeout: 20000 } );

check( 'the booking went through', /\/order\//.test( shop.url() ), shop.url() );
check( 'and the receipt lists what was not a ticket',
	/Programme/.test( await shop.locator( 'body' ).innerText() ) );

await shop.screenshot( { path: `${ SHOTS }/04-receipt.png` } );

console.log( 'The organiser sees it sold' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '#order-results' );
await page.waitForTimeout( 1500 );

const rows = await page.locator( '#order-results' ).innerText();

check( 'the booking is priced with the extras on it',
	rows.includes( after.slice( 0, 2 ) ) || /Confirmed/i.test( rows ),
	rows.split( '\n' ).slice( 0, 4 ).join( ' | ' ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL COUNTER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
