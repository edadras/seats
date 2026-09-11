/**
 * The platform billing an organiser, driven in Chromium.
 *
 * Two screens and one sentence between them: what the platform says an account owes, and what the
 * account sees when it opens its own billing page. They must agree, because an invoice an organiser
 * cannot see is an invoice they will not pay.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node billing_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/billing-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const tinker = ( php ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', php ], {
	encoding: 'utf8',
} ).trim();

/*
 * A month of use, backdated.
 *
 * The seed signs everybody up today, so nothing is due yet — which is correct and useless for a
 * check. The subscription's start is moved back so a period has genuinely finished; everything
 * after this is the platform's own arithmetic.
 */
const prepared = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$subscription = \\App\\Models\\Subscription::orderByDesc('created_at')->firstOrFail();

		\\App\\Models\\Plan::whereKey($subscription->plan_id)->update(['price_amount' => 4900]);

		$subscription->forceFill([
			'status' => 'active',
			'trial_ends_at' => null,
			'current_period_start' => now()->subDays(40),
			'last_invoiced_to' => null,
		])->save();

		echo 'ready';
	});
` );

check( 'an account has a month of use behind it', prepared.endsWith( 'ready' ), prepared );

const raised = execFileSync( 'php', [ 'artisan', 'billing:run' ], { encoding: 'utf8' } ).trim();

check( 'the scheduled run raises what is owed', /1 invoice\(s\) raised/.test( raised ),
	raised.replace( /\s+/g, ' ' ) );
// Nothing refused: this deployment takes transfers, so the invoice is owed rather than declined.
check( 'and calls a transfer a transfer rather than a refusal',
	/1 awaiting transfer, 0 refused/.test( raised ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'What the organiser sees' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=billing]' );
await page.waitForSelector( '.page-body table' );

const theirs = await page.locator( '.page-body' ).innerText();

// The plan fee and the commission on what the seed actually sold, on one invoice.
check( 'their own bill, with what it is for',
	/Professional/.test( theirs ) && /Commission/.test( theirs ) && /€\d/.test( theirs ),
	theirs.replace( /\s+/g, ' ' ).slice( 0, 110 ) );
// This deployment configures no card gateway, so it says so instead of offering a button that
// could not work.
check( 'and a straight answer about how it gets paid', /transfer/i.test( theirs ) );

await page.screenshot( { path: `${ SHOTS }/01-billing.png`, fullPage: true } );

console.log( 'They ask to be invoiced rather than charged' );
await page.click( '#billing-invoice-me' );
await page.waitForSelector( '#bill-name' );
await page.fill( '#bill-name', 'Northgate Theatre Trust' );
await page.fill( '#bill-email', 'accounts@northgate.test' );
await page.fill( '#bill-vat', 'GB123456789' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );
await page.waitForTimeout( 900 );

check( 'and the screen says so rather than nagging for a card',
	/paid by transfer/i.test( await page.locator( '.page-body' ).innerText() ) );

console.log( 'And the platform settles it from the console' );
const console_ = await context.newPage();
console_.on( 'pageerror', ( e ) => errors.push( e.message ) );

await console_.goto( `${ BASE }/console`, { waitUntil: 'networkidle' } );
await console_.fill( '#c-email', 'operator@seatmap.test' );
await console_.fill( '#c-password', 'password' );
await console_.click( '#console-login button[type=submit]' );
await console_.waitForSelector( '.stat-grid' );

await console_.click( 'nav button[data-view=invoices]' );
await console_.waitForSelector( '[data-invoice-paid]' );

const owed = await console_.locator( '.page-head__desc' ).innerText();
const theirTotal = ( /€[\d.,]+/.exec( await page.locator( 'tbody' ).innerText() ) || [ '' ] )[ 0 ];

// The same figure from both sides. Two screens describing one debt must not disagree about it.
check( 'the console is owed exactly what the organiser was billed',
	theirTotal.length > 1 && owed.includes( theirTotal ), `${ owed } · ${ theirTotal }` );

await console_.screenshot( { path: `${ SHOTS }/02-console.png`, fullPage: true } );

console_.once( 'dialog', ( dialog ) => dialog.accept( 'SEPA-42' ) );
await console_.locator( '[data-invoice-paid]' ).first().click();
await console_.waitForTimeout( 1500 );

check( 'and marking it paid settles it', /SEPA-42/.test( await console_.locator( 'tbody' ).innerText() ),
	( await console_.locator( 'tbody' ).innerText() ).replace( /\s+/g, ' ' ).slice( 0, 90 ) );

await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=billing]' );
await page.waitForSelector( '.page-body table' );
await page.waitForTimeout( 600 );

// The two screens are the same relationship from two sides, and they must not disagree about it.
check( 'the organiser sees it settled too',
	/Paid/i.test( await page.locator( 'tbody' ).innerText() ),
	( await page.locator( 'tbody' ).innerText() ).replace( /\s+/g, ' ' ).slice( 0, 90 ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL BILLING CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
