/**
 * The platform console, driven in Chromium.
 *
 * The last check is the one that matters most: an organiser's owner, signed in with their own
 * credentials, gets nothing from the console's API. A role inside an account must never be a way
 * into the platform's own screens.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node console_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The deliberate 404 at the end is this script asking to be refused.
	if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() );
} );

console.log( 'Signing in as an operator' );
await page.goto( `${ BASE }/console`, { waitUntil: 'networkidle' } );
await page.fill( '#c-email', 'operator@seatmap.test' );
await page.fill( '#c-password', 'password' );
await page.click( '#console-login button[type=submit]' );
await page.waitForSelector( '.stat-grid' );
check( 'the platform in numbers', ( await page.locator( '.stat' ).count() ) >= 6 );

console.log( 'Organisers' );
await page.click( 'nav button[data-view=tenants]' );
await page.waitForSelector( '[data-tenant]' );
check( 'every account is listed', ( await page.locator( 'tbody tr' ).count() ) >= 2,
	`${ await page.locator( 'tbody tr' ).count() } organisers` );

// Northgate by name rather than whichever row sorted first: the payout checks below sign in as
// that account's owner and compare what the operator just did with what they can see.
await page.locator( 'tbody tr' ).filter( { hasText: /Northgate/i } ).first()
	.locator( '[data-tenant]' ).click();
await page.waitForSelector( '#c-suspend' );
check( 'and one can be opened, suspended or entered',
	await page.locator( '#c-impersonate' ).isVisible() );

console.log( 'Settling a period' );

// A month back to today — the seed's bookings are spread over the last fortnight, and a payout
// period is agreed in days rather than in instants.
const day = ( back ) => new Date( Date.now() - back * 86400000 ).toISOString().slice( 0, 10 );
const from = day( 30 );
const today = day( 0 );

// The payout block loads after the rest of the screen, on its own request.
await page.waitForSelector( '#c-pay-from' );

await page.fill( '#c-pay-from', from );
await page.fill( '#c-pay-to', today );
await page.click( '#c-pay-preview' );
await page.waitForSelector( '#c-pay-settle' );

const preview = await page.locator( '#c-pay-preview-out' ).innerText();

check( 'the operator is shown what it would pay before anything is written',
	/\d/.test( preview ), preview.replace( /\s+/g, ' ' ).slice( 0, 90 ) );

page.once( 'dialog', ( dialog ) => dialog.accept( 'SEPA-1' ) );
await page.click( '#c-pay-settle' );
await page.waitForTimeout( 1500 );

check( 'and the period is settled once',
	1 === await page.locator( '[data-void]' ).count(),
	`${ await page.locator( '[data-void]' ).count() } payout(s)` );

// The same days again: this is the mistake the whole feature exists to stop.
await page.fill( '#c-pay-from', from );
await page.fill( '#c-pay-to', today );
await page.click( '#c-pay-preview' );
await page.waitForSelector( '#c-pay-preview-out .issue--error' );

check( 'settling the same days again is refused, with the payout in the way named',
	0 === await page.locator( '#c-pay-settle' ).count() );

await page.screenshot( { path: `${ process.env.SEATMAP_SHOTS || '/tmp' }/payouts.png`, fullPage: true } );

console.log( 'And the organiser sees it on their own settlement screen' );
const theirs = await page.evaluate( async ( base ) => {
	const login = await fetch( base + '/v1/auth/login', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: JSON.stringify( {
			email: 'owner@northgate.test',
			password: 'password',
			device_name: 'probe',
		} ),
	} ).then( ( r ) => r.json() );

	const read = await fetch( base + '/v1/settlement/payouts', {
		headers: { Authorization: 'Bearer ' + login.token, Accept: 'application/json' },
	} );

	return { status: read.status, body: await read.json() };
}, BASE );

check( 'their own payouts are theirs to read',
	200 === theirs.status && 1 === ( theirs.body.data || [] ).length,
	`${ theirs.status } · ${ ( theirs.body.data || [] ).length } payout(s)` );

console.log( 'Plans' );
await page.click( 'nav button[data-view=plans]' );
await page.waitForSelector( '#c-new-plan' );
check( 'the tariffs are here', ( await page.locator( 'tbody tr' ).count() ) >= 2 );

await page.locator( '[data-plan]' ).first().click();
await page.waitForSelector( '#p-name' );
const limitFields = await page.locator( '[id^=p-max_]' ).count();
check( 'a plan can only promise what the platform enforces', 3 === limitFields,
	`${ limitFields } limit fields` );

console.log( 'What an organiser gets' );
const refused = await page.evaluate( async ( base ) => {
	const login = await fetch( base + '/v1/auth/login', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: JSON.stringify( {
			email: 'owner@northgate.test',
			password: 'password',
			device_name: 'probe',
		} ),
	} ).then( ( r ) => r.json() );

	const attempt = await fetch( base + '/v1/admin/tenants', {
		headers: { Authorization: 'Bearer ' + login.token, Accept: 'application/json' },
	} );

	return { status: attempt.status, body: await attempt.json() };
}, BASE );

check( 'an organiser gets nothing from the console, not even a refusal that admits it exists',
	404 === refused.status, `${ refused.status } ${ refused.body.error && refused.body.error.code }` );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/console-plans.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL CONSOLE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
