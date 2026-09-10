/**
 * Offering a ticket back to the public, and moving one to another night, driven in Chromium.
 *
 * The buyer's own half of both needs a signed-in buyer, and buyers sign in with Google — which a
 * smoke test cannot do and should not pretend to. So the buyer's action is taken through the
 * domain, as that buyer, and what the browser drives is the half it can honestly reach: the
 * organiser switching it on, the seat coming back onto the public map while it is still the
 * seller's, and the box office taking a listing down for somebody who rang up.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node resale_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/resale-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const tinker = ( php ) => execFileSync(
	'php', [ 'artisan', 'tinker', '--execute', php ], { encoding: 'utf8' }
).trim();

/**
 * A buyer offering their seat back, made where a buyer would make it.
 *
 * Prints the seat's id and whether the public may buy it, before and after — which is the whole
 * claim this feature makes, in one line.
 */
const buyerOffers = ( eventId ) => tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$event = \\App\\Models\\Event::findOrFail('${ eventId }');
		$allocation = \\App\\Models\\Allocation::where('event_id', $event->id)
			->where('status', 'active')
			->whereNotNull('seat_id')
			->whereHas('ticket', fn ($t) => $t->where('status', 'issued'))
			->orderBy('created_at')->firstOrFail();

		$free = function () use ($event, $allocation) {
			foreach (app(\\App\\Domain\\Availability\\AvailabilityService::class)->forEvent($event) as $seat) {
				if ($seat['seat_id'] === $allocation->seat_id) { return $seat['state']; }
			}
			return 'missing';
		};

		$before = $free();
		app(\\App\\Domain\\Resale\\Resales::class)->list($allocation, 'dana@example.test', 'Dana Scully');

		echo $event->exchanges.'|'.(int) $event->exchange_fee_amount.'|'.(int) $event->resale
			.'|'.$before.'|'.$free().'|'.\\App\\Models\\Allocation::findOrFail($allocation->id)->status;
	});
` );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser decides what a buyer may do with a ticket they cannot use' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-event-edit]' );

/*
 * The night this is driven against is chosen by the one thing that matters here — that somebody
 * has actually bought a seat at it. The first row in the table is whichever night sorts first,
 * and a night with no sales has nothing to offer back.
 */
const eventId = tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		// A seat whose ticket has not been used: the demo scans two parties in on the night, and
		// somebody already through the door has nothing left to offer anybody.
		echo \\App\\Models\\Allocation::where('status', 'active')
			->whereNotNull('seat_id')
			->whereHas('ticket', fn ($t) => $t->where('status', 'issued'))
			->orderBy('created_at')->firstOrFail()->event_id;
	});
` );

await page.locator( `[data-event-edit="${ eventId }"]` ).click();
await page.waitForSelector( '#e-resale' );

// Both off until somebody says otherwise: a venue that has never thought about either should not
// discover it has been offering them.
check( 'both are offered on the night rather than in a settings screen',
	! ( await page.locator( '#e-resale' ).isChecked() ) &&
	'never' === await page.locator( '#e-exchanges' ).inputValue() );

/*
 * Turned on the way an organiser turns it on: by pressing the switch.
 *
 * The checkbox itself is visually hidden under the track — that is how every switch in the panel
 * is built, so that it announces itself to a screen reader as the checkbox it is — and pressing
 * the thing a person can actually see is the only honest way to drive it.
 */
await page.locator( '#e-resale' ).scrollIntoViewIfNeeded();
await page.locator( 'label:has(#e-resale) .switch__track' ).click();

check( 'the switch answers to the control a person can see',
	await page.locator( '#e-resale' ).isChecked() );

await page.selectOption( '#e-exchanges', 'always' );
await page.fill( '#e-exchange-fee', '300' );
await page.screenshot( { path: `${ SHOTS }/01-terms.png` } );
await page.locator( '.modal button[type=submit]' ).click();
await page.waitForTimeout( 1500 );

console.log( 'A buyer offers their seat back' );
const [ exchanges, fee, resale, before, after, status ] = buyerOffers( eventId ).split( '|' );

// What the form said, read back off the night itself: the terms are the event's, and nothing
// below this line would mean anything if the save had not landed.
check( 'the terms the organiser typed are what the night now carries',
	'always' === exchanges && '300' === fee && '1' === resale,
	`${ exchanges } · ${ fee } · ${ resale }` );

check( 'the seat was not for sale a moment ago', 'available' !== before, before );
check( 'and it is now', 'available' === after, after );
// The whole design in one assertion: the seat is offered without being taken away.
check( 'while still belonging to the person who paid for it', 'active' === status, status );

console.log( 'The box office can see what is on offer' );
await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-resale]' );

await page.locator( `[data-resale="${ eventId }"]` ).click();
await page.waitForSelector( '#resale-list .quota-row' );
await page.waitForTimeout( 400 );

const listed = await page.locator( '#resale-list' ).innerText();

check( 'the seat, the seller and what it costs are all on the row',
	/Dana Scully/.test( listed ) && /[0-9]/.test( listed ),
	listed.split( '\n' ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/02-listings.png` } );

console.log( 'And take one down for somebody who rang up' );
await page.locator( '#resale-list [data-unlist]' ).click();
await page.waitForTimeout( 1200 );

const afterWithdrawal = await page.locator( '#resale-list' ).innerText();

check( 'the listing is gone from the offer', ! /data-unlist/.test( afterWithdrawal ) &&
	0 === await page.locator( '#resale-list [data-unlist]' ).count(), afterWithdrawal.split( '\n' )[ 0 ] );

const settled = tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$listing = \\App\\Models\\ResaleListing::orderByDesc('listed_at')->firstOrFail();
		$allocation = \\App\\Models\\Allocation::findOrFail($listing->allocation_id);

		echo $listing->state.'|'.$allocation->status;
	});
` );

// Taking it down gives nothing back and takes nothing away: it was theirs the whole time.
check( 'and the ticket is exactly where it was', 'withdrawn|active' === settled, settled );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL RESALE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
