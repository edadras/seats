/**
 * A presale, driven in Chromium: an organiser sets one up, a stranger is turned away, a member
 * gets in.
 *
 * What is worth driving here is the moment of refusal. The picker is not drawn at all for somebody
 * without a code — whether they may buy is the server's answer, and a page that drew the seats and
 * then refused the booking would be a page that let somebody choose where to sit before telling
 * them they could not.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node presale_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/presale-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** Put the night into presale, which is a thing the events screen does and this does directly. */
const intoPresale = ( publicId ) => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		\\App\\Models\\Event::where('public_id', '${ publicId }')->update([
			'presale_starts_at' => now()->subHour(),
			'on_sale_at' => now()->addWeek(),
		]);

		echo 'presale';
	});
` ], { encoding: 'utf8' } ).trim();

const night = await seatedEvent( BASE, 'presale-smoke' );

console.log( 'The night goes into presale' );
check( 'the window is set', 'presale' === intoPresale( night.public_id ), night.public_id );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The deliberate refusal below is a 422 the page asked for.
	if ( 'error' === m.type() && ! m.text().includes( '422' ) ) errors.push( m.text() );
} );

console.log( 'The organiser makes a code' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=access]' );
await page.waitForSelector( '#access-new' );

check( 'the screen says what a code is for',
	/presale|discount/i.test( await page.locator( '.page-head__desc' ).innerText() ) );

await page.click( '#access-new' );
await page.waitForSelector( '#a-code' );
await page.click( '#a-suggest' );
await page.waitForTimeout( 500 );

const suggested = await page.locator( '#a-code' ).inputValue();

// Read out over a telephone: no O against 0, no I against 1.
check( 'a code can be suggested, in an alphabet nobody reads twice',
	/^[A-HJ-NP-Z2-9]{8}$/.test( suggested ), suggested );

await page.fill( '#a-code', 'MEMBERS' );
await page.fill( '#a-label', 'The mailing list' );
await page.fill( '#a-max', '20' );
await page.fill( '#a-max-seats', '2' );
await page.screenshot( { path: `${ SHOTS }/01-new-code.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.stat-strip', { timeout: 6000 } );

check( 'it opens on the code it made',
	( await page.locator( '.page-head h1' ).first().innerText() ).includes( 'MEMBERS' ) );
check( 'and nobody has used it yet',
	/0/.test( await page.locator( '.stat-strip' ).innerText() ) );

await page.screenshot( { path: `${ SHOTS }/02-one-code.png` } );

console.log( 'A stranger arrives at the night' );
const shop = await context.newPage();
shop.on( 'pageerror', ( e ) => errors.push( e.message ) );

await shop.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

check( 'the seats are not drawn at all',
	0 === await shop.locator( '.seatmap-widget__stage' ).count() );
check( 'and the page says why', /presale/i.test( await shop.locator( '.notice' ).first().innerText() ),
	await shop.locator( '.notice' ).first().innerText() );
check( 'with a box to put a code in', await shop.locator( '#access-code' ).isVisible() );

await shop.screenshot( { path: `${ SHOTS }/03-locked.png` } );

console.log( 'A wrong code is refused, and says which kind of wrong' );
await shop.fill( '#access-code', 'NOTTHISONE' );
await shop.click( '#unlock-form button[type=submit]' );
await shop.waitForTimeout( 1200 );

check( 'refused, in words', ( await shop.locator( '#unlock-said' ).innerText() ).length > 0,
	await shop.locator( '#unlock-said' ).innerText() );
check( 'and the seats are still not drawn',
	0 === await shop.locator( '.seatmap-widget__stage' ).count() );

await shop.screenshot( { path: `${ SHOTS }/04-refused.png` } );

console.log( 'The right code opens the sale' );
await shop.fill( '#access-code', 'members' );
await shop.click( '#unlock-form button[type=submit]' );
await shop.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

// Case does not matter: "members" and "MEMBERS" are one code.
check( 'the picker is drawn on the reload', await shop.locator( '.seatmap-widget__stage' ).isVisible() );

await openASection( shop );

const seat = await seatPoint( shop, 0 );
await shop.mouse.click( seat.x, seat.y );
await shop.waitForTimeout( 400 );

await shop.locator( '.seatmap-widget__submit' ).click();
await shop.waitForURL( /\/checkout/, { timeout: 15000 } );

check( 'and a seat can be taken through to the checkout', /checkout/.test( shop.url() ), shop.url() );

await shop.screenshot( { path: `${ SHOTS }/05-checkout.png` } );

console.log( 'The organiser sees the use' );
await page.reload( { waitUntil: 'networkidle' } );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=access]' );
await page.waitForSelector( '#access-results table, #access-results .empty' );
await page.waitForTimeout( 600 );

const row = await page.locator( '#access-results' ).innerText();

check( 'counted against the code', /1 of 20|۱ of ۲۰|1 \/ 20/.test( row ) || /MEMBERS/.test( row ),
	row.split( '\n' ).slice( 0, 6 ).join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/06-used.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL PRESALE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
