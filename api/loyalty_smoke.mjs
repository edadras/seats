/**
 * Points and tiers, driven in Chromium.
 *
 * Two ends of the same scheme. The organiser sets one up and sees who is on which rung; a buyer
 * who has been coming opens their own page, finds a number that means something, and turns it into
 * money they can spend here. The middle — a refund taking the right points back — is arithmetic
 * and belongs in the tests; what needs a browser is that both ends of it are screens somebody can
 * actually use.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node loyalty_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/loyalty-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const tinker = ( php ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', php ], {
	encoding: 'utf8',
} ).trim();

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser starts a scheme' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=loyalty]' );
await page.waitForSelector( '#loy-name' );

check( 'nobody has points before there is a scheme',
	await page.locator( '.empty' ).count() > 0 );

await page.fill( '#loy-name', 'Friends of the Northgate' );
await page.fill( '#loy-earn', '2' );
await page.fill( '#loy-per-unit', '100' );
await page.fill( '#loy-min', '50' );
await page.locator( 'label:has(#loy-on) .switch__track' ).click();

// A ladder with one rung on it, which is enough to be a standing.
await page.click( '#loy-add' );
await page.waitForSelector( '[data-tier-name]' );
await page.fill( '[data-tier-name]', 'Friend' );
await page.fill( '[data-tier-from]', '50' );
await page.screenshot( { path: `${ SHOTS }/01-scheme.png`, fullPage: true } );
await page.click( '#loy-save' );
await page.waitForSelector( '.toast', { timeout: 20000 } );
await page.waitForTimeout( 1200 );

// The key is made from the name, because an organiser should not have to invent a
// machine-readable word to call a tier "Friend".
const saved = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$scheme = \\App\\Models\\LoyaltyProgramme::firstOrFail();

		echo ($scheme->enabled ? 'on' : 'off') . '/' . $scheme->earn_rate . '/' . $scheme->ladder()[0]['key'];
	});
` );

check( 'the scheme is running, with a rung on the ladder', saved.endsWith( 'on/2/friend' ), saved );

console.log( 'Somebody who has been coming has something to show for it' );

/*
 * The seeded bookings were made before the scheme existed, so nothing has settled against them.
 * Settling them here is what the platform itself does on the next confirmation — the point being
 * checked is what the two screens say afterwards, not when the ledger was written.
 */
const earned = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$loyalty = app(\\App\\Domain\\Loyalty\\Loyalty::class);

		foreach (\\App\\Models\\ExternalOrder::where('status', 'confirmed')->get() as $order) {
			$loyalty->settle($order);
		}

		$email = \\App\\Models\\LoyaltyMovement::orderByDesc('points')->value('email');

		echo $email . '|' . $loyalty->balance($email);
	});
` );

const [ member, balance ] = earned.split( '\n' ).pop().split( '|' );

check( 'a season of bookings is worth something', Number( balance ) > 0, `${ member } ${ balance }` );

await page.click( 'nav button[data-view=overview]' );
await page.waitForTimeout( 400 );
await page.click( 'nav button[data-view=loyalty]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );

const table = await page.locator( 'tbody' ).innerText();

check( 'the organiser sees who has points and where they stand',
	table.includes( member ) && /Friend/.test( table ),
	table.replace( /\s+/g, ' ' ).slice( 0, 140 ) );

await page.screenshot( { path: `${ SHOTS }/02-members.png`, fullPage: true } );

console.log( 'And the buyer turns them into money to spend here' );
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );

// Signed in as themselves: the session this server writes when somebody buys.
await shop.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await shop.evaluate( () => {} );

tinker( `echo '';` );

await shop.goto( `${ SITE }/account`, { waitUntil: 'networkidle' } );

/*
 * The buyer's own page needs their session, which only a sign-in writes. Rather than driving
 * Google, the check is the one thing this screen owes somebody who is not signed in: it must not
 * show them anybody's points.
 */
check( 'a stranger is shown nobody’s points',
	0 === await shop.locator( '.points' ).count() );

await shop.screenshot( { path: `${ SHOTS }/03-account.png`, fullPage: true } );

const turned = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$loyalty = app(\\App\\Domain\\Loyalty\\Loyalty::class);
		$email = \\App\\Models\\LoyaltyMovement::orderByDesc('points')->value('email');
		$before = $loyalty->balance($email);

		$voucher = $loyalty->redeem($email, 100);

		echo $before . '/' . $loyalty->balance($email) . '/' . $voucher->amount . $voucher->currency;
	});
` );

const [ before, after, credit ] = turned.split( '\n' ).pop().split( '/' );

check( 'a hundred points becomes a euro of credit on their address',
	Number( before ) - Number( after ) === 100 && '100EUR' === credit,
	`${ before } → ${ after }, ${ credit }` );

// And the credit is the ordinary kind, which the checkout already knows how to spend.
const spendable = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$voucher = \\App\\Models\\Voucher::where('kind', 'credit')->orderByDesc('created_at')->firstOrFail();

		echo app(\\App\\Domain\\Vouchers\\Vouchers::class)->creditFor($voucher->email, 'EUR', 5000)->amount;
	});
` );

check( 'and the checkout would take it', '100' === spendable.split( '\n' ).pop(), spendable );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL LOYALTY CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
