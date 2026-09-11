/**
 * "Can I have my money back?", from the box office's side, driven in Chromium.
 *
 * The buyer's own half of this needs a signed-in buyer, and buyers sign in with Google — which a
 * smoke test cannot do and should not pretend to. That half is covered by the feature tests. What
 * is driven here is the half a browser can honestly reach: the organiser writing the terms, and
 * the queue of people waiting for an answer being answered.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node refund_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/refund-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** A buyer asking, made where a buyer would make it: through the domain, as that buyer. */
const buyerAsks = ( howMany ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$orders = \\App\\Models\\ExternalOrder::where('status', 'confirmed')
			->orderBy('created_at')->limit(${ howMany })->get();

		foreach ($orders as $order) {
			app(\\App\\Domain\\Refunds\\RefundRequests::class)->ask($order, [], 'I cannot come.');
		}

		echo $orders->count();
	});
` ], { encoding: 'utf8' } ).trim();

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser writes the terms' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-event-edit]' );

await page.locator( '[data-event-edit]' ).first().click();
await page.waitForSelector( '#e-refunds' );

check( 'the terms belong to the night, not to the account',
	await page.locator( '#e-refunds' ).isVisible() );

await page.selectOption( '#e-refunds', 'never' );
await page.screenshot( { path: `${ SHOTS }/01-terms.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1200 );

console.log( 'Two buyers ask, outside the terms' );
const asked = buyerAsks( 2 );

check( 'both are recorded rather than refused', '2' === asked, `${ asked } asked` );

await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '#order-results' );
await page.waitForTimeout( 1500 );

check( 'and the box office sees them above the list',
	2 === await page.locator( '.asked li' ).count(),
	`${ await page.locator( '.asked li' ).count() } waiting` );
check( 'with the buyer’s own reason on the row',
	/cannot come/i.test( await page.locator( '.asked' ).innerText() ) );

await page.screenshot( { path: `${ SHOTS }/02-queue.png` } );

console.log( 'One is refunded, one is refused' );
await page.locator( '[data-grant]' ).first().click();
await page.waitForTimeout( 1800 );

check( 'saying yes hands the money back',
	1 === await page.locator( '.asked li' ).count(),
	`${ await page.locator( '.asked li' ).count() } left` );

await page.locator( '[data-decline]' ).first().click();
await page.waitForSelector( '#decline-why' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 700 );

check( 'saying no without a reason is refused', await page.locator( '#decline-why' ).isVisible() );

await page.fill( '#decline-why', 'Within a week of the show.' );
await page.screenshot( { path: `${ SHOTS }/03-decline.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1500 );

check( 'and with one, nobody is left waiting',
	0 === await page.locator( '.asked li' ).count() );

await page.waitForTimeout( 800 );

const rows = await page.locator( '#order-results tbody tr' ).allInnerTexts();

check( 'the granted booking reads as refunded',
	rows.some( ( text ) => /Refunded/i.test( text ) ), `${ rows.length } bookings` );

await page.screenshot( { path: `${ SHOTS }/04-orders.png` } );

console.log( 'And the booking says where the money went' );

// The refunded one, whichever row it landed on.
const refunded = page.locator( '#order-results tbody tr' ).filter( { hasText: /Refunded/i } ).first();

await refunded.locator( '[data-order]' ).click();
await page.waitForSelector( '#order-back' );
await page.waitForTimeout( 600 );

const detail = await page.locator( '.page-body' ).innerText();

check( 'the booking carries a money-back table', /Money sent back/i.test( detail ) );

// The seed pays through the offline gateway — cash, a transfer — which cannot send anything back,
// so the honest answer is that somebody owes this buyer money in person. Said in words on the row,
// not left looking like a refund that silently worked.
check( 'and says plainly that it is owed in person rather than sent',
	/Owed in person/i.test( detail ) );

await page.screenshot( { path: `${ SHOTS }/05-money-back.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL REFUND CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
