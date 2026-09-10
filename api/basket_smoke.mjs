/**
 * A buyer who got as far as their own name, and the one message they get about it — in Chromium.
 *
 * The mechanics are held up by `BasketRecoveryTest`; what a browser adds is the two surfaces a
 * person actually touches. The organiser's screen has to say what came of writing to people —
 * including, on a healthy account, that nobody has left anything — and the link at the bottom of
 * the message has to put the same seats back in a basket and land the buyer on a checkout.
 *
 * The abandoned state itself is arranged over artisan rather than by pretending: the seeded site
 * settles offline payments inline, so no browser can produce a booking that was left half paid.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node basket_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/basket-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const artisan = ( ...args ) => execFileSync( 'php', [ 'artisan', ...args ], {
	cwd: import.meta.dirname,
	encoding: 'utf8',
} );

/** Run the hourly pass by hand. `--after=0` means "now", which is what a check needs. */
const sweep = () => artisan( 'baskets:recover', '--after=0' );

const night = await seatedEvent( BASE, 'basket-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	/*
	 * One 422 is provoked on purpose below — pressing "write now" while the message is switched
	 * off must refuse — and the browser logs every non-2xx as a console error. Everything else is
	 * a real fault and still fails this check.
	 */
	if ( 'error' === m.type() && ! /422/.test( m.text() ) ) {
		errors.push( m.text() );
	}
} );

console.log( 'An empty list is the good outcome, and the screen says so' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=baskets]' );
await page.waitForSelector( '#b-results', { timeout: 15000 } );
await page.waitForTimeout( 800 );

check( 'the screen is there', await page.locator( '#b-search' ).isVisible() );
check( 'and an account nobody has abandoned reads as such',
	/empty|nobody|left/i.test( await page.locator( '#b-results' ).innerText() ),
	( await page.locator( '#b-results' ).innerText() ).split( '\n' )[ 0 ] );

console.log( 'A buyer picks seats, gives their name, and never pays' );
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

await shop.fill( '#name', 'Amina Farsi' );
await shop.fill( '#email', 'amina@example.test' );
await shop.click( '.checkout__submit' );
await shop.waitForURL( /\/order\//, { timeout: 20000 } );

const reference = shop.url().split( '/order/' )[ 1 ];

// Put that booking back where a redirect gateway would have left it: registered, waiting to be
// paid for, its seats long since back on sale.
artisan( 'tinker', '--execute', `
	$order = App\\Models\\ExternalOrder::withoutGlobalScopes()
		->where('external_order_id', '${ reference }')->firstOrFail();

	app(App\\Support\\Tenancy\\TenantContext::class)->runAs(
		App\\Models\\Tenant::findOrFail($order->tenant_id),
		function () use ($order) {
			$ids = App\\Models\\Allocation::where('external_order_row_id', $order->id)->pluck('id');
			App\\Models\\Ticket::whereIn('allocation_id', $ids)->delete();
			App\\Models\\Allocation::whereIn('id', $ids)
				->update(['status' => 'released', 'released_at' => now()]);
			App\\Models\\HoldItem::where('hold_id', $order->hold_id)
				->update(['released_at' => now()]);
			App\\Models\\Hold::whereKey($order->hold_id)
				->update(['status' => 'expired', 'expires_at' => now()->subHour()]);
			$order->forceFill(['status' => 'pending', 'confirmed_at' => null])->save();
		}
	);
` );

console.log( 'Nothing is sent until the organiser turns it on' );
sweep();

await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=baskets]' );
await page.waitForSelector( '#b-results' );
await page.waitForTimeout( 1200 );

const noticed = await page.locator( '#b-results' ).innerText();

check( 'the basket is noticed', /Amina Farsi/.test( noticed ) );
check( 'and nobody has been written to', /not written to yet/i.test( noticed ),
	noticed.split( '\n' ).join( ' | ' ).slice( 0, 160 ) );
check( 'the tiles count it', /1/.test( await page.locator( '.stat-strip' ).innerText() ) );

await page.screenshot( { path: `${ SHOTS }/01-noticed.png` } );

console.log( 'Pressing "write now" while it is switched off says so rather than doing nothing' );
await page.locator( '[data-send]' ).first().click();
await page.waitForTimeout( 1200 );

// It refused, so the row has not moved: still waiting, still offering to be written to.
check( 'nothing was sent and the row did not move',
	/not written to yet/i.test( await page.locator( '#b-results' ).innerText() )
		&& 1 === await page.locator( '[data-send]' ).count() );

console.log( 'So the organiser turns it on, and writes to them once' );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( 'button.kind[data-kind="order.unfinished"]', { timeout: 15000 } );

// The kinds are a list of closed drawers; the channels are inside one. Open it the way a person
// does, by pressing the message they came to change.
await page.locator( 'button.kind[data-kind="order.unfinished"]' ).click();
await page.waitForTimeout( 1200 );

// The switch is the whole of an organiser's control over this message, so it is turned on the way
// an organiser turns it on rather than over the API.
const toggle = page.locator( 'input[data-for="order.unfinished"][data-channel="email"]' );

check( 'the message has a switch of its own', 1 === await toggle.count() );

await toggle.check();
await page.waitForTimeout( 1500 );

check( 'and it stays on', await toggle.isChecked() );

await page.click( 'nav button[data-view=baskets]' );
await page.waitForSelector( '#b-results' );
await page.waitForTimeout( 1200 );
await page.locator( '[data-send]' ).first().click();
await page.waitForTimeout( 1500 );

const written = await page.locator( '#b-results' ).innerText();

check( 'the row says so', /Written to/i.test( written ) );
check( 'and the offer to write is gone', 0 === await page.locator( '[data-send]' ).count() );

// And the hourly pass does not write again.
sweep();
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=baskets]' );
await page.waitForSelector( '#b-results' );
await page.waitForTimeout( 1200 );

check( 'the sweeper does not write a second time',
	1 === ( await page.locator( '#b-results tbody tr' ).count() )
		&& /Written to/i.test( await page.locator( '#b-results' ).innerText() ) );

await page.screenshot( { path: `${ SHOTS }/02-written.png` } );

console.log( 'And the link in the message puts the seats back' );
const token = artisan( 'tinker', '--execute',
	`echo App\\Models\\BasketRecovery::withoutGlobalScopes()->orderByDesc('created_at')->first()->token;`
).trim().split( '\n' ).pop().trim();

check( 'the message carries a token', /^[a-z0-9]{40,}$/.test( token ), token.slice( 0, 12 ) + '…' );

const mail = await context.newPage();
mail.on( 'pageerror', ( e ) => errors.push( e.message ) );

await mail.goto( `${ SITE }/basket/${ token }`, { waitUntil: 'networkidle' } );

check( 'following it lands on a checkout', /\/checkout/.test( mail.url() ), mail.url() );
check( 'with the same seats in it',
	1 === await mail.locator( '.summary-lines li' ).count(),
	await mail.locator( '.summary-lines' ).innerText() );

await mail.screenshot( { path: `${ SHOTS }/03-back-again.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL BASKET CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
