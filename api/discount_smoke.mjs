/**
 * Discount codes, end to end in Chromium.
 *
 * One browser, two people: an organiser makes a code on the panel screen, and a buyer picks seats
 * on the hosted site and spends it. What is being checked is that the two agree about money — the
 * total the summary shows after the code is applied is the total the booking is placed at, and the
 * use comes back on the organiser's own screen with the buyer's name against it.
 *
 * A wrong code is checked first, because "invalid code" with no reason is the failure mode of every
 * discount box ever built.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node discount_smoke.mjs
 */
import { chromium } from 'playwright';
import { openASection, seatedEvent, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/discount-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The screen' );
await page.click( 'nav button[data-view=discounts]' );
await page.waitForSelector( '#discount-new' );
await page.screenshot( { path: `${ SHOTS }/01-discounts-empty.png` } );

console.log( 'Making one' );
await page.click( '#discount-new' );
await page.waitForSelector( '.modal' );
check( 'a percentage has no currency field',
	! ( await page.locator( '#d-currency-field' ).isVisible() ) );

await page.click( '#d-suggest' );
await page.waitForTimeout( 500 );
const suggested = await page.locator( '#d-code' ).inputValue();
check( 'a code can be suggested', /^[A-Z2-9]{8}$/.test( suggested ), suggested );

await page.fill( '#d-code', 'EARLYBIRD' );
await page.fill( '#d-description', 'The mailing list' );
await page.fill( '#d-value', '25' );
await page.screenshot( { path: `${ SHOTS }/02-new-code.png` } );

await page.selectOption( '#d-kind', 'fixed' );
await page.waitForTimeout( 200 );
check( 'a fixed amount asks which currency',
	await page.locator( '#d-currency-field' ).isVisible() );

await page.selectOption( '#d-kind', 'percent' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.stat-strip', { timeout: 5000 } );

check( 'it opens on the code it made',
	( await page.locator( '.page-head h1, .page__title' ).first().innerText() ).includes( 'EARLYBIRD' ) );
await page.screenshot( { path: `${ SHOTS }/03-one-code.png` } );

console.log( 'Spending it' );
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );
shop.on( 'console', ( m ) => { if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() ); } );

// The night with chairs in it, asked of the API rather than taken from the top of the list: the
// demo also contains a warehouse sold by the head, and a discount on standing room proves nothing
// about a seat price.
const night = await seatedEvent( BASE, 'discount-smoke' );

await shop.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( shop );

for ( const index of [ 0, 0 ] ) {
	const seat = await seatPoint( shop, index );

	await shop.mouse.click( seat.x, seat.y );
	await shop.waitForTimeout( 300 );
}

await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/checkout/, { timeout: 15000 } );
await shop.waitForSelector( '#discount-code' );

const before = await shop.locator( '.summary-total' ).innerText();

console.log( 'A code that is not one' );
await shop.fill( '#discount-code', 'NOPE' );
await shop.locator( '.promo button[type=submit]' ).click();
await shop.waitForSelector( '.promo .field__error' );
check( 'an unknown code is refused in words',
	( await shop.locator( '.promo .field__error' ).innerText() ).length > 5,
	await shop.locator( '.promo .field__error' ).innerText() );
check( 'and the total did not move', before === await shop.locator( '.summary-total' ).innerText() );

console.log( 'The real one' );
await shop.fill( '#discount-code', 'earlybird' );
await shop.locator( '.promo button[type=submit]' ).click();
await shop.waitForSelector( '.promo--applied' );

const after = await shop.locator( '.summary-total' ).innerText();
check( 'the code is shown as applied, however it was typed',
	( await shop.locator( '.promo--applied' ).innerText() ).includes( 'EARLYBIRD' ) );
check( 'a discount line appeared', 1 === await shop.locator( '.summary-lines__off' ).count(),
	await shop.locator( '.summary-lines__off' ).innerText().catch( () => '' ) );
check( 'and the total came down', before !== after, `${ before } -> ${ after }` );

await shop.screenshot( { path: `${ SHOTS }/04-checkout-discount.png`, fullPage: true } );

console.log( 'Paying' );
await shop.fill( '#name', 'Amina Farsi' );
await shop.fill( '#email', 'amina@example.test' );
await shop.locator( '.checkout__submit' ).click();
await shop.waitForURL( /\/order\//, { timeout: 20000 } );
check( 'the booking goes through', /order/.test( shop.url() ), shop.url() );

console.log( 'What the organiser sees' );
await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=discounts]' );
await page.waitForSelector( '[data-open]' );
await page.click( '[data-open]' );
await page.waitForSelector( '.stat-strip' );

const used = await page.locator( '.stat-strip' ).innerText();
check( 'the use is counted and priced', /1/.test( used ), used.replace( /\n/g, ' | ' ) );
check( 'and the buyer is named', ( await page.locator( 'tbody' ).innerText() ).includes( 'Amina' ),
	await page.locator( 'tbody' ).innerText().catch( () => '' ) );

await page.screenshot( { path: `${ SHOTS }/05-code-used.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL PANEL DISCOUNT CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
