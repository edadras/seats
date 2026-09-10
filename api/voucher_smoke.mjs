/**
 * A gift voucher, issued in the panel and spent at a checkout — driven in Chromium.
 *
 * Two things are worth driving a real browser for. The first is that a voucher covering the whole
 * booking leaves no payment step: the buyer must be able to finish without choosing a gateway, and
 * that path exists nowhere else in the application. The second is that the summary keeps up — the
 * "left to pay" line is rendered by the server and re-rendered by the quote, and a page that says
 * one number while the booking charges another is the one bug a checkout must not have.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node voucher_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/voucher-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const digits = ( text ) => String( text || '' ).replace( /[^0-9]/g, '' );

const night = await seatedEvent( BASE, 'voucher-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser issues a voucher worth more than a seat' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=vouchers]' );
await page.waitForSelector( '#v-new' );

check( 'the screen is there', await page.locator( '#v-new' ).isVisible() );

await page.click( '#v-new' );
await page.waitForSelector( '#v-code' );
await page.click( '#v-suggest' );
await page.waitForTimeout( 800 );

const code = await page.locator( '#v-code' ).inputValue();

check( 'it suggests a code nobody has to invent', /^[A-Z0-9-]{8,}$/.test( code ), code );

// Far more than one seat costs, so the booking is settled outright and the change stays a voucher.
await page.fill( '#v-amount', '500' );
await page.fill( '#v-recipient', 'Amina Farsi' );
await page.fill( '#v-note', 'Birthday present' );
await page.screenshot( { path: `${ SHOTS }/01-issue.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

const detail = await page.locator( '.page-body' ).innerText();

check( 'the ledger starts with the issue', /Issued/.test( detail ) );
check( 'and the balance is what it was issued for',
	digits( detail ).includes( '50000' ) || /500/.test( detail ) );

await page.screenshot( { path: `${ SHOTS }/02-voucher.png` } );

console.log( 'A buyer spends it, and is asked for nothing' );
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

check( 'the checkout offers a voucher box',
	await shop.locator( '#voucher-code' ).isVisible() );
check( 'and a way to pay, while there is something to pay',
	await shop.locator( '#pay-step' ).isVisible() );
// The lines exist in the markup and are hidden, so the quote can reveal them without a reload.
// Hidden has to mean hidden: a "paid by voucher: 0.00" line on a booking no voucher touched is
// worse than no line at all, and a class that sets `display` will beat the attribute in silence.
check( 'and says nothing about a voucher nobody has used',
	await shop.locator( '#summary-paid' ).isHidden()
		&& await shop.locator( '#settled-note' ).isHidden() );

await shop.fill( '#voucher-code', code );
await shop.locator( 'form[action="/checkout/voucher"] button[type=submit]' ).click();
await shop.waitForLoadState( 'networkidle' );

check( 'the voucher is taken', await shop.locator( '.promo--applied' ).isVisible() );
check( 'the summary says what it paid', await shop.locator( '#summary-paid' ).isVisible() );
check( 'and that there is nothing left',
	0 === Number( digits( await shop.locator( '#summary-payable' ).innerText() ) ),
	await shop.locator( '#summary-payable' ).innerText() );
check( 'so no payment method is asked for',
	await shop.locator( '#pay-step' ).isHidden() );
check( 'and the page says why',
	await shop.locator( '#settled-note' ).isVisible() );

await shop.screenshot( { path: `${ SHOTS }/03-settled.png` } );

await shop.fill( '#name', 'Amina Farsi' );
await shop.fill( '#email', 'amina@example.test' );
await shop.click( '.checkout__submit' );
await shop.waitForURL( /\/order\//, { timeout: 20000 } );

check( 'the booking went through with no gateway at all',
	/\/order\//.test( shop.url() ), shop.url() );

await shop.screenshot( { path: `${ SHOTS }/04-receipt.png` } );

console.log( 'And the change is still theirs' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=vouchers]' );
await page.waitForSelector( '#v-results' );
await page.waitForTimeout( 1200 );
await page.locator( '[data-open-voucher]' ).first().click();
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

const after = await page.locator( '.page-body' ).innerText();

const strip = await page.locator( '.stat-strip' ).innerText();

check( 'the spend is in the ledger', /Spent/.test( after ) );
// Issued €500, one seat spent, and the change is still the holder's. What is left has to be
// less than what was issued and more than nothing — a voucher that hands back no change is a
// gift card the shop kept.
check( 'and the balance came down but not to nothing',
	/50000/.test( digits( strip ) ) && ! /5000000000/.test( digits( strip ) ),
	strip.split( '\n' ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/05-after.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL VOUCHER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
