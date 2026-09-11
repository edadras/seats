/**
 * The queue for a sold-out night, driven in Chromium.
 *
 * What is checked here is the part that used to be a comment rather than a behaviour: a place in
 * the queue moves. Somebody is told, their turn runs out and they go back into the line, somebody
 * buys and comes off it without ever saying so — and the organiser's screen says all three.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node waitlist_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { openHall, chooseSeats, startSale } from './counter-hall.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/waitlist-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const tinker = ( php ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', php ], {
	encoding: 'utf8',
} ).trim();

/** Two people join the queue for the seeded night, a minute apart so the order is not a tie. */
const joined = tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		$event = \\App\\Models\\Event::where('name', 'Opening night')->firstOrFail();
		$list = app(\\App\\Domain\\Waitlist\\WaitingList::class);

		$list->join($event, ['name' => 'Asleep', 'email' => 'asleep@example.test', 'quantity' => 1]);
		$list->join($event, ['name' => 'Awake', 'email' => 'awake@example.test', 'quantity' => 1]);

		echo \\App\\Models\\WaitingListEntry::count();
	});
` );

check( 'two people are queueing for the night', joined.endsWith( '2' ), joined );

// The scheduled round, run by hand. Seats are free on the seeded night, so both get a turn.
execFileSync( 'php', [ 'artisan', 'waitlist:notify' ], { encoding: 'utf8' } );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The queue, from the organiser’s side' );
await page.click( 'nav button[data-view=waitlist]' );
await page.waitForSelector( '#wait-event' );

// The screen opens on whichever night sorted first; this queue is for a named one.
await page.selectOption( '#wait-event', { label: 'Opening night' } );
await page.waitForTimeout( 900 );

const told = await page.locator( '.page-body' ).innerText();

check( 'both names are on it', /asleep@example\.test/.test( told ) && /awake@example\.test/.test( told ) );
check( 'and each has a live turn', 2 === await page.locator( '.badge--warn' ).count(),
	`${ await page.locator( '.badge--warn' ).count() } claiming` );

await page.screenshot( { path: `${ SHOTS }/01-told.png`, fullPage: true } );

console.log( 'One of them sleeps through their turn' );

/*
 * Their window is pushed into the past, which is the only part of two hours a browser cannot wait
 * for. Everything after this is the platform's own behaviour.
 */
tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		\\App\\Models\\WaitingListEntry::where('email', 'asleep@example.test')
			->update(['claim_expires_at' => now()->subMinute()]);
	});
` );

await page.click( 'nav button[data-view=overview]' );
await page.waitForTimeout( 400 );
await page.click( 'nav button[data-view=waitlist]' );
await page.waitForSelector( '#wait-event' );
await page.selectOption( '#wait-event', { label: 'Opening night' } );
await page.waitForTimeout( 900 );

// Their own row, not the table: the person beside them still has a live turn, and a check that
// read the whole table would pass on their badge instead.
const asleepRow = await page.locator( 'tbody tr' )
	.filter( { hasText: 'asleep@example.test' } )
	.innerText();
const awakeRow = await page.locator( 'tbody tr' )
	.filter( { hasText: 'awake@example.test' } )
	.innerText();

// Back in the line, not stuck at "told" for ever, which is what this row used to do.
check( 'the turn that ran out puts them back in the queue', /Waiting/.test( asleepRow ),
	asleepRow.replace( /\s+/g, ' ' ) );
check( 'and the one still inside their window is left alone', /Their turn/.test( awakeRow ),
	awakeRow.replace( /\s+/g, ' ' ) );

console.log( 'The other one rings the box office instead of clicking the link' );
await page.click( 'nav button[data-view=counter]' );
await openHall( page, 'Opening night' );
await chooseSeats( page, 1 );
await startSale( page );
await page.fill( '#c-name', 'Awake' );
await page.fill( '#c-email', 'awake@example.test' );
await page.selectOption( '#c-payment', 'paid' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );

await page.click( 'nav button[data-view=waitlist]' );
await page.waitForSelector( '#wait-event' );
await page.selectOption( '#wait-event', { label: 'Opening night' } );
await page.waitForTimeout( 900 );

const after = await page.locator( '.page-body' ).innerText();

/*
 * Sold at a counter, under the address they queued with — and the queue knows.
 *
 * `converted` was a status this platform would let an organiser filter by and set nowhere, so the
 * honest answer to "did keeping this list sell anything" was an empty page whatever happened.
 */
check( 'buying takes them off the queue without their saying so', /Bought/.test( after ),
	after.replace( /\s+/g, ' ' ).slice( 0, 120 ) );

await page.selectOption( '#wait-status', 'converted' );
await page.waitForTimeout( 800 );

check( 'and the filter that answered nothing now answers',
	1 === await page.locator( 'tbody tr' ).count(),
	`${ await page.locator( 'tbody tr' ).count() } row(s)` );
check( 'with the right person on it',
	/awake@example\.test/.test( await page.locator( 'tbody' ).innerText() ) );

await page.screenshot( { path: `${ SHOTS }/02-bought.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL WAITING LIST CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
