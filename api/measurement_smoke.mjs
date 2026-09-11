/**
 * Measurement, and the question that comes before it — driven in Chromium.
 *
 * `MeasurementTest` holds up what the server renders. What a browser adds is the only claim that
 * actually matters here, and it is one no server-side test can make: **nothing is fetched from
 * Google, Meta or Plausible until a visitor has said yes.** That is checked by watching the network
 * rather than by reading the markup, because the markup is not the promise.
 *
 * The three providers are intercepted so this check needs no internet and measures nothing real.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node measurement_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/measurement-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const token = ( await ( await fetch( BASE + '/v1/auth/login', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( {
		email: 'owner@northgate.test', password: 'password', device_name: 'measurement-smoke',
	} ),
} ) ).json() ).token;

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

/** Every request that left for somebody else's server. */
let outbound = [];

const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );

for ( const host of [
	'https://www.googletagmanager.com/**',
	'https://connect.facebook.net/**',
	'https://plausible.io/**',
] ) {
	await context.route( host, ( route ) => {
		outbound.push( route.request().url() );

		// Answered locally: this check is about whether the request is made at all.
		route.fulfill( { status: 200, contentType: 'application/javascript', body: '' } );
	} );
}

const page = await context.newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'A site that measures nothing asks nothing' );
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

check( 'no bar, because there is no question to ask',
	0 === await page.locator( '[data-consent]' ).count() );

console.log( 'The organiser turns measurement on' );
const site = ( await api( 'GET', '/v1/sites' ) ).body.data[ 0 ];
const saved = await api( 'PATCH', '/v1/sites/' + site.id, {
	measurement: {
		ga4: 'G-SMOKE12345',
		// Deliberately wrong, to see that it is dropped rather than stored and quietly ignored.
		meta: 'not-a-pixel',
		plausible: 'northgate.example',
	},
} );

check( 'the ids it recognised are kept and the one it did not is dropped',
	200 === saved.status && 'G-SMOKE12345' === saved.body.measurement.ga4 &&
	'northgate.example' === saved.body.measurement.plausible &&
	undefined === saved.body.measurement.meta,
	JSON.stringify( saved.body.measurement ) );

console.log( 'A visitor is asked before anything is loaded' );
outbound = [];
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await settle( 600 );

check( 'the bar is there', await page.locator( '[data-consent]' ).isVisible() );
check( 'and nothing has been fetched from anybody', 0 === outbound.length, outbound.join( ', ' ) );

await page.screenshot( { path: `${ SHOTS }/01-asking.png` } );

console.log( 'They say no' );
await page.click( '[data-consent-no]' );
await settle( 400 );

check( 'the bar goes', ! await page.locator( '[data-consent]' ).isVisible() );

await page.reload( { waitUntil: 'networkidle' } );
await settle( 600 );

check( 'and stays gone on the next page', ! await page.locator( '[data-consent]' ).isVisible() );
check( 'with still nothing fetched from anybody', 0 === outbound.length, outbound.join( ', ' ) );

console.log( 'They change their mind' );
await page.click( '[data-consent-reopen]' );
await settle( 300 );

check( 'the question comes back', await page.locator( '[data-consent]' ).isVisible() );

await page.click( '[data-consent-yes]' );
await settle( 1200 );

check( 'now the tags load', outbound.length >= 2, outbound.join( ', ' ) );
check( 'and it is the id the organiser typed, on the address we built',
	outbound.some( ( url ) => url.includes( 'googletagmanager.com/gtag/js?id=G-SMOKE12345' ) ),
	outbound.filter( ( url ) => url.includes( 'gtag' ) ).join( ', ' ) );

await page.screenshot( { path: `${ SHOTS }/02-allowed.png` } );

console.log( 'And a page records what happened on it' );
outbound = [];
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await page.locator( '.event-card' ).first().click();
await page.waitForSelector( '.event-hero' );
await settle( 900 );

const layer = await page.evaluate( () => ( window.dataLayer || [] ).map( function ( row ) {
	return Array.prototype.slice.call( row );
} ) );

check( 'looking at a night is recorded',
	layer.some( ( row ) => 'event' === row[ 0 ] && 'view_item' === row[ 1 ] ),
	JSON.stringify( layer.map( ( row ) => row[ 1 ] ) ) );
check( 'not asked again, having already answered',
	0 === await page.locator( '[data-consent]:not([hidden])' ).count() );

console.log( 'And the organiser can see all of it in the panel' );
const desk = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );
await desk.click( 'nav button[data-view=sites]' );
await desk.waitForSelector( '[data-site]', { timeout: 20000 } );
await desk.waitForTimeout( 300 );
await desk.click( '[data-site]' );
await desk.waitForSelector( '#site-nav', { timeout: 20000 } );
await desk.locator( '#site-nav .nav-item', { hasText: 'Measurement' } ).first().click();
await desk.waitForSelector( '#m-ga4', { timeout: 20000 } );

check( 'the ids are where the organiser left them',
	'G-SMOKE12345' === await desk.locator( '#m-ga4' ).inputValue() &&
	'' === await desk.locator( '#m-meta' ).inputValue() );
check( 'and there is nowhere to paste a script',
	0 === await desk.locator( '#site-main textarea' ).count() );

await desk.screenshot( { path: `${ SHOTS }/03-the-screen.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL MEASUREMENT CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
