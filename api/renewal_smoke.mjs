/**
 * First refusal on the same chairs, driven in Chromium.
 *
 * The claim worth a real browser is the one an organiser has to be able to see: after a round is
 * opened, last season's subscribers hold their seats and *nobody else can buy them* — and after
 * somebody says no, that chair is back on the public map at once. Both of those are things the
 * seat picker either shows or does not.
 *
 * Last season's sale and the second run are made through the domain rather than by driving three
 * more screens: what is being checked here is the renewal, not how a run gets built.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node renewal_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/renewal-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const tinker = ( php ) => execFileSync(
	'php', [ 'artisan', 'tinker', '--execute', php ], { encoding: 'utf8' }
).trim();

const last = await seatedEvent( BASE, 'renewal-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + last.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( ( response ) => response.json() );

console.log( 'Last season, and a subscriber who sat in it' );
const later = ( weeks ) => new Date( Date.now() + weeks * 7 * 86400000 ).toISOString();
const more = await api( 'POST', `/v1/events/${ last.id }/repeat`, {
	dates: [ later( 4 ) ],
	series_name: 'Last season',
} );

for ( const copy of more.data || [] ) {
	await api( 'PATCH', `/v1/events/${ copy.id }`, { status: 'published' } );
}

/*
 * Last season's sale and the new run, made through the domain.
 *
 * Scaffolding, and deliberately not driven: three more screens between here and the thing under
 * test would make this a check of how a run gets built rather than of what a renewal does.
 */
const setUp = tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () use ($tenant) {
		$night = \\App\\Models\\Event::where('public_id', '${ last.public_id }')->firstOrFail();
		// A chair the demo has not already sold: the seeder fills part of the house, and the
		// first seat by name is one of the ones it took.
		$free = null;

		foreach (app(\\App\\Domain\\Availability\\AvailabilityService::class)->forEvent($night) as $row) {
			if ('available' === $row['state']) { $free = $row['seat_id']; break; }
		}

		$seat = \\App\\Models\\Seat::findOrFail($free);

		// Somebody who sat in that chair last season.
		$client = \\App\\Models\\ApiClient::firstOrFail();
		$hold = app(\\App\\Domain\\Inventory\\HoldService::class)
			->create($night, [$seat->id], 'renewal-smoke', $client->id, '127.0.0.1');
		$buyer = ['name' => 'Dana Scully', 'email' => 'dana@example.test'];
		[$order] = app(\\App\\Domain\\Orders\\OrderService::class)
			->register($client, 'renewal-smoke-1', $hold->token, $buyer);
		app(\\App\\Domain\\Orders\\OrderService::class)->confirm($order, $buyer);

		// The new run: two nights on the same plan, so the same chairs exist to be offered.
		$next = \\App\\Models\\EventSeries::create([
			'tenant_id' => $tenant->id,
			'name' => 'Next season',
			'slug' => 'next-season-'.\\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(6)),
		]);

		foreach ([20, 21] as $index => $weeks) {
			$made = \\App\\Models\\Event::create([
				'venue_id' => $night->venue_id,
				'series_id' => $next->id,
				'seat_map_id' => $night->seat_map_id,
				'seat_map_version_id' => $night->seat_map_version_id,
				'public_id' => 'evt_'.\\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(20)),
				'name' => 'Next season night '.($index + 1),
				'status' => 'published',
				'starts_at' => now()->addWeeks($weeks),
				'timezone' => $night->timezone,
				'currency' => $night->currency,
			]);

			foreach (\\App\\Models\\EventPriceZone::where('event_id', $night->id)->get() as $zone) {
				\\App\\Models\\EventPriceZone::create($zone->only(['key', 'name', 'amount']) + [
					'event_id' => $made->id,
				]);
			}
		}

		echo $next->id.'|'.$seat->id.'|'.\\App\\Models\\Event::where('series_id', $next->id)
			->orderBy('starts_at')->firstOrFail()->id;
	});
` );

const [ nextSeriesId, seatId, nextNightId ] = setUp.split( '|' );

check( 'last season sold a seat, and a new run exists on the same plan',
	!! nextSeriesId && !! seatId && !! nextNightId );

/** What the public can do with that chair on the first night of the new run. */
const stateOfSeat = () => tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$night = \\App\\Models\\Event::findOrFail('${ nextNightId }');

		foreach (app(\\App\\Domain\\Availability\\AvailabilityService::class)->forEvent($night) as $row) {
			if ($row['seat_id'] === '${ seatId }') { echo $row['state']; return; }
		}

		echo 'missing';
	});
` );

check( 'and that chair is on general sale in the new run', 'available' === stateOfSeat(), stateOfSeat() );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser offers next season to last season' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=seasons]' );
await page.waitForSelector( '#s-new', { timeout: 15000 } );

// A run needs a season ticket before anything can be renewed into it: a renewal is a subscription,
// and this platform has exactly one way of pricing one.
await page.click( '#s-new' );
await page.waitForSelector( '#s-name' );
await page.selectOption( '#s-run', nextSeriesId );
await page.fill( '#s-name', 'Next season' );
await page.fill( '#s-discount-value', '20' );
await page.locator( '.modal button[type=submit]' ).click();
await page.waitForTimeout( 1500 );

await page.click( 'nav button[data-view=seasons]' );
await page.waitForSelector( '#s-renewals', { timeout: 15000 } );
await page.selectOption( '#s-series', nextSeriesId );
await page.waitForTimeout( 800 );

await page.click( '#s-renewals' );
await page.waitForSelector( '#r-deadline', { timeout: 15000 } );

check( 'the form asks which run they came from and until when',
	await page.locator( '#r-from' ).isVisible() && await page.locator( '#r-deadline' ).isVisible() );

const deadline = new Date( Date.now() + 30 * 86400000 );

await page.fill( '#r-deadline', deadline.toISOString().slice( 0, 16 ) );
await page.screenshot( { path: `${ SHOTS }/01-open.png` } );
await page.locator( '.modal button[type=submit]' ).click();
await page.waitForSelector( '#r-list', { timeout: 15000 } );
await page.waitForTimeout( 600 );

const round = await page.locator( '.modal' ).last().innerText();

check( 'the subscriber is listed with the chairs they had',
	/dana@example.test/i.test( round ), round.split( '\n' ).slice( 0, 8 ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/02-round.png` } );

// The claim the whole feature rests on: those chairs are not for sale to anybody else.
check( 'and that chair is no longer on general sale', 'held' === stateOfSeat(), stateOfSeat() );

console.log( 'Everybody is written to, once' );
await page.locator( '#r-invite' ).click();
await page.waitForTimeout( 1500 );

const letters = () => tinker( `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		// Dana's own offer, not whichever sorts first: hers is the chair this check follows, and
		// the demo house has other subscribers in it.
		$offer = \\App\\Models\\RenewalOffer::where('email', 'dana@example.test')->firstOrFail();

		echo \\App\\Models\\MessageDelivery::where('kind', 'season.renewal')->count()
			.'|'.\\App\\Models\\RenewalOffer::count()
			.'|'.$offer->id.'|'.app(\\App\\Domain\\Renewals\\Renewals::class)->tokenFor($offer);
	});
` );

const [ written, offers, offerId, token ] = letters().split( '|' );

// One letter each, with a link of their own in it — never one letter about everybody.
check( 'every subscriber gets a letter of their own', written === offers && Number( offers ) > 0,
	`${ written } written to ${ offers } offered` );

await page.locator( '#r-invite' ).click();
await page.waitForTimeout( 1500 );

// Pressing it again must not write to anybody twice: an organiser checking whether it worked
// should not thereby send a second copy to four hundred people.
check( 'and pressing it again writes to nobody twice', written === letters().split( '|' )[ 0 ],
	letters().split( '|' )[ 0 ] );

console.log( 'The subscriber decides' );
const reader = await ( await browser.newContext() ).newPage();
reader.on( 'pageerror', ( e ) => errors.push( e.message ) );

await reader.goto( `${ SITE }/renewals/${ offerId }/${ token }`, { waitUntil: 'networkidle' } );

const offered = await reader.locator( 'main' ).innerText();

check( 'the page needs no sign-in and says which chairs and until when',
	/[0-9]/.test( offered ) && ! /sign in/i.test( offered ),
	offered.split( '\n' ).slice( 0, 4 ).join( ' | ' ) );

await reader.screenshot( { path: `${ SHOTS }/03-offer.png` } );

// A wrong signature must not confirm that the offer — and therefore the subscriber — exists.
const guess = await ( await browser.newContext() ).newPage();
const guessed = await guess.goto( `${ SITE }/renewals/${ offerId }/nonsense`,
	{ waitUntil: 'networkidle' } ).catch( () => null );

check( 'a wrong signature says nothing at all', ! guessed || 404 === guessed.status(),
	guessed ? String( guessed.status() ) : 'no response' );

await reader.locator( 'form[action$="/decline"] button' ).click();
await reader.waitForLoadState( 'networkidle' );

check( 'saying no puts the chair back at once rather than at the deadline',
	'available' === stateOfSeat(), stateOfSeat() );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL RENEWAL CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
