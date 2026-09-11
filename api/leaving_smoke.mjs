/**
 * Taking an account's data and closing the account, driven in Chromium.
 *
 * The parts worth a real browser are the parts that only exist end to end: that the archive comes
 * down the wire as a file somebody can actually open, that the refusal names the nights standing in
 * the way rather than saying "no", and — the one that matters most — that the download link still
 * works once the door is shut, when there is no panel left to sign in to.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node leaving_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/leaving-shots';

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
page.on( 'console', ( m ) => {
	/*
	 * The wrong name below is typed on purpose, and a browser logs the 422 it is handed as a
	 * console error. What this check is for is a screen that threw; a refusal the panel showed in
	 * the form it came from is a screen that worked.
	 */
	if ( 'error' === m.type() && ! /422/.test( m.text() ) ) {
		errors.push( m.text() );
	}
} );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The organiser takes a copy of everything' );
await page.click( 'nav button[data-view=leaving]' );
await page.waitForSelector( '#leave-export' );

check( 'there is nothing taken yet', await page.locator( '.empty' ).count() > 0 );

await page.click( '#leave-export' );
await page.waitForSelector( 'tbody tr', { timeout: 60000 } );

const row = await page.locator( 'tbody tr' ).first().innerText();

check( 'the copy is listed with what is in it', /\d+/.test( row ), row.replace( /\s+/g, ' ' ) );
check( 'and it is offered as a download', await page.locator( 'a.btn[href*="accounts/exports"]' ).count() > 0 );

await page.screenshot( { path: `${ SHOTS }/01-copy.png`, fullPage: true } );

const link = await page.locator( 'a.btn[href*="accounts/exports"]' ).first().getAttribute( 'href' );

/*
 * Fetched from inside the page, so this is the same request a browser would make: the signature is
 * the whole of the authorisation, and a file that only works with a session cookie would be exactly
 * the failure this feature exists to avoid.
 */
const fetched = await page.evaluate( async ( href ) => {
	const response = await fetch( href );
	const body = await response.arrayBuffer();

	return {
		status: response.status,
		type: response.headers.get( 'content-type' ),
		bytes: body.byteLength,
		// "PK" — the first two bytes of every zip ever written.
		zip: new Uint8Array( body.slice( 0, 2 ) ).join( ',' ),
	};
}, link );

check( 'the link hands over a real archive', 200 === fetched.status && '80,75' === fetched.zip,
	`${ fetched.status } ${ fetched.type } ${ fetched.bytes } bytes` );

console.log( 'The door will not open while people are holding tickets' );

check( 'closing is refused, and the nights are named',
	await page.locator( '.issue--error' ).isVisible() );
check( 'with the tickets counted', /\d/.test( await page.locator( '.issue--error' ).innerText() ),
	( await page.locator( '.issue--error' ).innerText() ).replace( /\s+/g, ' ' ).slice( 0, 140 ) );
check( 'and no way to close it', 0 === await page.locator( '#leave-close' ).count() );

await page.screenshot( { path: `${ SHOTS }/02-blocked.png`, fullPage: true } );

// Everybody gets their money back and their seats go back on sale. Done here rather than through
// twelve refunds in the browser: what is being checked is the refusal lifting, not the refunding.
tinker( `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		\\App\\Models\\Allocation::where('status', 'active')->update(['status' => 'released', 'released_at' => now()]);
	});
` );

await page.click( 'nav button[data-view=overview]' );
await page.waitForTimeout( 400 );
await page.click( 'nav button[data-view=leaving]' );
await page.waitForSelector( '#leave-close', { timeout: 15000 } );

check( 'with the tickets handed back, the door opens',
	await page.locator( '#leave-close' ).isVisible() );

console.log( 'And closing it means it' );
await page.click( '#leave-close' );
await page.waitForSelector( '#leave-name' );

// The wrong name is not a near miss: this is the one action a mis-click must not complete.
await page.fill( '#leave-name', 'Northgate' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1200 );

check( 'a name that is not quite right is refused',
	await page.locator( '#leave-name' ).count() > 0 );

await page.fill( '#leave-name', 'Northgate Theatre' );
await page.fill( '#leave-why', 'The theatre is closing.' );
await page.screenshot( { path: `${ SHOTS }/03-confirm.png`, fullPage: true } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached', timeout: 20000 } );
await page.waitForTimeout( 1200 );

const after = await page.locator( '.page-body' ).innerText();

check( 'the screen says the account is closed', /closed/i.test( after ),
	after.replace( /\s+/g, ' ' ).slice( 0, 160 ) );

await page.screenshot( { path: `${ SHOTS }/04-closed.png`, fullPage: true } );

/*
 * A shop with nobody behind it is worse than no shop.
 *
 * The check is that the venue's site is no longer there, not that the address returns an error: an
 * address the platform still answers on falls through to the platform's own front door, which is a
 * perfectly good 200 and is not a box office. What matters is that nothing of theirs is on it —
 * and that this is true at once rather than when a resolution cache expires.
 */
const shop = await context.newPage();

await shop.goto( `${ SITE }/`, { waitUntil: 'domcontentloaded' } );

const front = await shop.locator( 'body' ).innerText();

check( 'the website is down', ! /Northgate Theatre/i.test( front ),
	front.replace( /\s+/g, ' ' ).slice( 0, 80 ) );
check( 'and nothing of theirs is on sale at its address',
	0 === await shop.locator( '.event-card' ).count() );

/*
 * The one thing an organiser is most entitled to, at the moment they can no longer sign in to ask.
 *
 * Fetched through the browser's own request context rather than from inside a page: the link is on
 * the platform's host and the only page still standing is the site's, so a `fetch()` there would be
 * refused by the browser for being cross-origin and would say nothing about the link.
 */
const afterClosing = await context.request.get( link );

check( 'and the copy of their data still comes down', afterClosing.ok(),
	`${ afterClosing.status() } ${ ( await afterClosing.body() ).length } bytes` );

const state = tinker( `
	$tenant = \\App\\Models\\Tenant::withTrashed()->where('slug', 'northgate')->firstOrFail();

	echo $tenant->status . '/' . ($tenant->erase_after ? 'dated' : 'undated');
` );

check( 'closed, with a date after which there is nothing left to ask for',
	state.endsWith( 'cancelled/dated' ), state );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL LEAVING CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
