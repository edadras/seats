/**
 * Setting yourself up, driven in Chromium.
 *
 * One form, four things created, straight into the panel with a bar asking for the code. The check
 * that matters is the last one: an unverified account can build and cannot publish, which is the
 * whole restriction and the whole reason the bar is not just a nag.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node signup_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const email = `mina+${ Date.now() }@harbour.test`;

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The 409 below is deliberate — this script asks the API to publish and expects to be told no.
	if ( 'error' === m.type() && ! m.text().includes( '409' ) ) errors.push( m.text() );
} );

console.log( 'From the sign-in screen' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
check( 'there is a way to create an account', await page.locator( '#go-signup' ).isVisible() );

await page.click( '#go-signup' );
await page.waitForSelector( '#signup' );
check( 'the plans are offered', ( await page.locator( '.plan' ).count() ) >= 2,
	`${ await page.locator( '.plan' ).count() } plans` );

console.log( 'Signing up' );
await page.fill( '#s-org', 'Harbour Playhouse' );
await page.fill( '#s-name', 'Mina Karimi' );
await page.fill( '#s-email', email );
await page.fill( '#s-password', 'correct horse battery' );
await page.click( '#signup button[type=submit]' );

await page.waitForSelector( '.sidebar' );
check( 'straight into the panel', await page.locator( '.sidebar' ).isVisible() );
check( 'as the venue that was just typed',
	( await page.locator( '.account__name' ).innerText() ).includes( 'Harbour Playhouse' ) );
check( 'with a bar asking for the code', await page.locator( '.verify-bar' ).isVisible() );
check( 'and the code box is in it, not somewhere else',
	await page.locator( '#verify-code' ).isVisible() );

console.log( 'What a new account already has' );
const token = await page.evaluate( () => window.sessionStorage.getItem( 'seatmap_token' ) );

const sites = await page.evaluate( async ( bearer ) => {
	const response = await fetch( '/v1/sites', {
		headers: { Authorization: 'Bearer ' + bearer, Accept: 'application/json' },
	} );

	return response.json();
}, token );

check( 'a website, in draft', 1 === ( sites.data || [] ).length && 'draft' === sites.data[ 0 ].status,
	JSON.stringify( ( sites.data || [] ).map( ( s ) => s.status ) ) );

const publish = await page.evaluate( async ( { bearer, site } ) => {
	const response = await fetch( '/v1/sites/' + site, {
		method: 'PATCH',
		headers: {
			Authorization: 'Bearer ' + bearer,
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: JSON.stringify( { status: 'live' } ),
	} );

	return { status: response.status, body: await response.json() };
}, { bearer: token, site: sites.data[ 0 ].id } );

check( 'and cannot put it on the internet until the address is verified',
	409 === publish.status && 'email_unverified' === publish.body.error.code,
	`${ publish.status } ${ publish.body.error && publish.body.error.code }` );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/signup-in.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SIGNUP CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
