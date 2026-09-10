/**
 * A price with a date on it — driven in Chromium, from both ends.
 *
 * `PriceTierTest` holds up the rule: one price at any moment, the same number on the plan as in
 * the hold. What a browser adds is the half that decides whether anybody ever uses it — an
 * organiser has to be able to see, on the screen where the prices are, which window is charging
 * money right now and what it is doing to the numbers directly above it.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node tiers_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/tier-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'tiers-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const quoted = async () => ( await ( await fetch(
	`${ BASE }/v1/embed/events/${ night.public_id }/availability`
) ).json() );

console.log( 'The demo is already selling at an early price' );
const seeded = await quoted();

check( 'the buyer is told which price this is', !! seeded.price_tier,
	seeded.price_tier ? seeded.price_tier.name : 'none' );
check( 'and when it stops', !! ( seeded.price_tier && seeded.price_tier.ends_at ),
	seeded.price_tier ? seeded.price_tier.ends_at : '' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1200 } } ) ).newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser opens the prices' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-prices]', { timeout: 20000 } );
// This night, not whichever is listed first: the demo has a warehouse in it too, and its prices
// are a different screen with different rows.
await page.click( `[data-prices="${ night.id }"]` );
await page.waitForSelector( '#pricing-currency', { timeout: 20000 } );
await page.waitForTimeout( 900 );

check( 'the tier that is charging money says so, on the screen with the prices',
	await page.locator( '#tier-live' ).isVisible(),
	await page.locator( '#tier-live' ).innerText().catch( () => 'no notice' ) );
check( 'and the row for it is marked as the live one',
	1 === await page.locator( 'tr.is-live' ).count() );

await page.screenshot( { path: `${ SHOTS }/01-tiers.png` } );

console.log( 'And adds a second window for the last week' );
await page.click( '#tier-add' );
await page.waitForTimeout( 400 );

const rows = await page.locator( '[data-tier-name]' ).count();

check( 'a second tier can be added', 2 === rows, `${ rows } rows` );

// Fill in the new row: from the day the first one ends, and dearer.
const until = new Date( seeded.price_tier.ends_at );

// The row arrives already beginning where the first one ends, so only the name and the number
// need typing — which is also what stops it overlapping the tier it was added after.
await page.fill( '[data-tier-name="1"]', 'Last week' );
await page.fill( '[data-tier-value="1"]', '25' );
await page.locator( '[data-tier-value="1"]' ).blur();
await page.waitForTimeout( 1400 );

const saved = ( await api( 'GET', `/v1/events/${ night.id }/price-tiers` ) ).body;

check( 'and it is saved with the first one', 2 === ( saved.data || [] ).length,
	( saved.data || [] ).map( ( tier ) => tier.name ).join( ', ' ) );
check( 'with the early one still the live one', 'Early bird' === ( saved.active || {} ).name,
	saved.active ? saved.active.name : 'none' );

await page.screenshot( { path: `${ SHOTS }/02-two-tiers.png` } );

console.log( 'A window that would charge two prices at once is refused' );
const clash = await api( 'PUT', `/v1/events/${ night.id }/price-tiers`, {
	tiers: [
		{ name: 'A', starts_at: null, ends_at: until.toISOString(), kind: 'percent', value: -15 },
		{ name: 'B', starts_at: null, ends_at: null, kind: 'percent', value: 25 },
	],
} );

check( 'because one ticket cannot have two prices', 422 === clash.status, String( clash.status ) );
check( 'and it says which two clashed', 'tier_windows_overlap' === ( clash.body.error || {} ).code,
	JSON.stringify( clash.body.error || {} ).slice( 0, 120 ) );

console.log( 'What the buyer pays follows what the organiser set' );
const before = ( await quoted() ).seats.find( ( seat ) => 'available' === seat.state );

await api( 'PUT', `/v1/events/${ night.id }/price-tiers`, {
	tiers: [ { name: 'Half price hour', starts_at: null, ends_at: null, kind: 'percent', value: -50 } ],
} );

const after = ( await quoted() ).seats.find( ( seat ) => seat.seat_id === before.seat_id );

check( 'the plan quotes the new price at once, with nothing run in between',
	after.amount < before.amount, `${ before.amount } → ${ after.amount }` );

// And the hold agrees with the plan, which is the whole point.
const held = await ( await fetch( `${ BASE }/v1/embed/events/${ night.public_id }/holds`, {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( { seat_ids: [ after.seat_id ], session_id: 'tier-smoke-session' } ),
} ) ).json();

check( 'and a basket is priced at the same number the plan showed',
	held.total_amount === after.amount, `${ held.total_amount } held, ${ after.amount } quoted` );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
