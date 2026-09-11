/**
 * A rehearsal, driven in Chromium: the buyer's path walked through with nobody's money in it.
 *
 * Three things are worth a real browser here, and none of them can be seen from a unit test.
 *
 *   - The checkout offers one way to pay and it is not a gateway. A card field on a page that
 *     silently does something else would be worse than no rehearsal at all.
 *   - The banner is on the page where somebody is about to type a card number.
 *   - The takings do not move. The figure is read off the settlement screen before and after a
 *     rehearsed booking, because that is the screen an organiser would check.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node rehearsal_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/rehearsal-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const digits = ( text ) => String( text || '' ).replace( /[^0-9]/g, '' );

/**
 * Choose a night by the name it was given.
 *
 * Not `selectOption({ label })`: once a night is being rehearsed its option says so, and a check
 * that matched the whole label would break on the very state it is testing.
 */
const pick = async ( target, name ) => {
	const value = await target.locator( `#reh-event option` )
		.filter( { hasText: name } ).first().getAttribute( 'value' );

	await target.selectOption( '#reh-event', value );
	await target.waitForTimeout( 900 );
};

/** The switch is a label with its own track drawn over the box, so the track is what a person hits. */
const flip = ( target ) => target.locator( 'label:has(#reh-on) .switch__track' ).click();

const tinker = ( php ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', php ], {
	encoding: 'utf8',
} ).trim();

/*
 * A night nobody has bought from, made beside the seeded one.
 *
 * The seeded night has a fortnight of sales on it, which is exactly what cannot be rehearsed — so
 * this copies it: same venue, same published chart, same prices, no bookings.
 */
const made = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$seeded = \\App\\Models\\Event::where('name', 'Opening night')->firstOrFail();

		$fresh = $seeded->replicate(['public_id']);
		$fresh->public_id = 'evt_' . \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(20));
		$fresh->name = 'Dress rehearsal';
		$fresh->starts_at = now()->addMonth();
		$fresh->save();

		foreach ($seeded->priceZones as $zone) {
			$copy = $zone->replicate();
			$copy->event_id = $fresh->id;
			$copy->save();
		}

		echo $fresh->public_id;
	});
` );

const publicId = made.split( /\s+/ ).pop();

check( 'a night with nothing sold on it exists to rehearse', /^evt_/.test( publicId ), publicId );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	/*
	 * The refusal below is provoked on purpose, and a browser logs every 409 it is handed as a
	 * console error. Filtering it is not loosening the check: what this check is for is a screen
	 * that threw, and a refusal the panel showed in a toast is a screen that worked.
	 */
	if ( 'error' === m.type() && ! /409/.test( m.text() ) ) {
		errors.push( m.text() );
	}
} );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'What the takings say before anybody rehearses anything' );
await page.click( 'nav button[data-view=settlement]' );
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

const takingsBefore = digits( await page.locator( '.stat-strip' ).innerText() );

console.log( 'The organiser marks a night as a rehearsal' );
await page.click( 'nav button[data-view=rehearsal]' );
await page.waitForSelector( '#reh-event' );
await pick( page, 'Dress rehearsal' );
await page.waitForSelector( '#reh-on' );

check( 'the switch starts off', false === await page.locator( '#reh-on' ).isChecked() );

await flip( page );
await page.waitForSelector( '#reh-on:checked', { timeout: 15000 } );
await page.waitForTimeout( 900 );

check( 'and turning it on offers the page a buyer would see',
	await page.locator( 'a.btn[href*="/events/"]' ).isVisible() );

await page.screenshot( { path: `${ SHOTS }/01-rehearsing.png`, fullPage: true } );

console.log( 'A night that has already sold something refuses' );
await pick( page, 'Opening night' );
await flip( page );
await page.waitForTimeout( 1200 );

check( 'the refusal is shown rather than swallowed',
	await page.locator( '.toast' ).count() > 0,
	( await page.locator( '.toast' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ) );
// A switch that lies is worse than a switch that sticks: it went back to where it was.
check( 'and the switch goes back to where it was',
	false === await page.locator( '#reh-on' ).isChecked() );

console.log( 'A buyer walks the whole path, and is told what it is' );
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );

await shop.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

check( 'the rehearsal is in no listing',
	! ( await shop.locator( '.event-card' ).allInnerTexts() ).join( ' | ' ).includes( 'Dress rehearsal' ) );

await shop.goto( `${ SITE }/events/${ publicId }`, { waitUntil: 'networkidle' } );

check( 'and still opens by its own address, saying what it is',
	await shop.locator( '.notice--rehearsal' ).isVisible() );

await shop.screenshot( { path: `${ SHOTS }/02-buyer-warned.png`, fullPage: true } );

await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( shop );

const seat = await seatPoint( shop, 0 );
await shop.mouse.click( seat.x, seat.y );
await shop.waitForTimeout( 400 );
await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/checkout/, { timeout: 15000 } );

const ways = await shop.locator( '.pay' ).allInnerTexts();

check( 'the checkout offers one way to pay and it is not a gateway',
	1 === ways.length && /no money moves|جابه‌جا|kein Geld/i.test( ways.join( ' ' ) ),
	ways.join( ' | ' ).replace( /\s+/g, ' ' ) );
check( 'the banner is on the page where a card would be typed',
	await shop.locator( '.notice--rehearsal' ).isVisible() );

await shop.screenshot( { path: `${ SHOTS }/03-checkout.png`, fullPage: true } );

await shop.fill( '#name', 'Dana Scully' );
await shop.fill( '#email', 'dana@example.test' );
await shop.click( '.checkout__submit' );
await shop.waitForURL( /\/order\//, { timeout: 20000 } );

check( 'the booking goes through exactly as a real one would', /\/order\//.test( shop.url() ),
	shop.url() );
check( 'and the receipt says it was a rehearsal',
	await shop.locator( '.notice--rehearsal' ).isVisible() );

await shop.screenshot( { path: `${ SHOTS }/04-receipt.png`, fullPage: true } );

console.log( 'The panel knows what the rehearsal did, and the takings do not' );
await page.click( 'nav button[data-view=settlement]' );
await page.waitForSelector( '.stat-strip', { timeout: 15000 } );

check( 'the takings are exactly what they were',
	digits( await page.locator( '.stat-strip' ).innerText() ) === takingsBefore,
	`${ takingsBefore } then ${ digits( await page.locator( '.stat-strip' ).innerText() ) }` );

await page.click( 'nav button[data-view=rehearsal]' );
await page.waitForSelector( '#reh-event' );
await pick( page, 'Dress rehearsal' );
await page.waitForSelector( '#reh-clear', { timeout: 15000 } );

const tally = await page.locator( '.stat-strip' ).innerText();

check( 'the rehearsal screen counts the booking, the seat and the ticket',
	/1/.test( tally ), tally.replace( /\s+/g, ' ' ) );

await page.screenshot( { path: `${ SHOTS }/05-tally.png`, fullPage: true } );

console.log( 'And clearing it leaves nothing behind' );
await page.click( '#reh-clear' );
await page.waitForSelector( '.modal button[type=submit]' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached', timeout: 15000 } );
await page.waitForTimeout( 1200 );

check( 'the tally is empty and there is nothing left to clear',
	0 === await page.locator( '#reh-clear' ).count() );

// Cleared, the night goes back on sale without argument — which is the whole point of the refusal
// that would not let it before.
await flip( page );
await page.waitForTimeout( 1200 );

check( 'and the night goes back on sale', false === await page.locator( '#reh-on' ).isChecked() );

const left = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$event = \\App\\Models\\Event::where('name', 'Dress rehearsal')->firstOrFail();

		echo \\App\\Models\\ExternalOrder::where('event_id', $event->id)->count()
			. '/' . \\App\\Models\\Allocation::where('event_id', $event->id)->count()
			. '/' . \\App\\Models\\Ticket::where('event_id', $event->id)->count()
			. '/' . ($event->is_rehearsal ? 'rehearsing' : 'selling');
	});
` );

check( 'no bookings, no seats, no tickets, and selling for real', left.endsWith( '0/0/0/selling' ), left );

await page.screenshot( { path: `${ SHOTS }/06-cleared.png`, fullPage: true } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL REHEARSAL CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
