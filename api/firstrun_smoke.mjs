/**
 * A first afternoon, driven in Chromium.
 *
 * `FirstStepsTest` holds up each of the eight facts. What a browser adds is the thing those facts
 * exist for: somebody who has just signed up and is looking at a panel of thirty-six screens with
 * no idea which one matters today. So this signs up a genuinely new organiser and follows the list —
 * and then signs in as the seeded venue, which has been selling for a while, to check that it is
 * never shown any of this.
 *
 * The second half is the other defect this addresses: the venue's own web app, which buyers were
 * being offered and the organiser could not see. The tile, the name it installs under, and a plain
 * answer to "can anybody actually install this yet".
 *
 * Who may read the checklist is held up by `FirstStepsTest` rather than here: the demo data has no
 * volunteer to sign in as, and inventing one through the invitation flow would be a test of the
 * invitation flow.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node firstrun_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/firstrun-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1100 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

/** Whatever the panel is holding, asked for as the signed-in person. */
const api = ( path, options = {} ) => page.evaluate( async ( { path, options } ) => {
	const response = await fetch( path, {
		method: options.method || 'GET',
		headers: {
			Authorization: 'Bearer ' + window.sessionStorage.getItem( 'seatmap_token' ),
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: options.body ? JSON.stringify( options.body ) : undefined,
	} );

	return { status: response.status, body: await response.json() };
}, { path, options } );

console.log( 'A venue signs up this afternoon' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.click( '#go-signup' );
await page.waitForSelector( '#signup' );
await page.fill( '#s-org', 'Harbour Playhouse' );
await page.fill( '#s-name', 'Mina Karimi' );
await page.fill( '#s-email', `mina+${ Date.now() }@harbour.test` );
await page.fill( '#s-password', 'correct horse battery' );
await page.click( '#signup button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.waitForSelector( '.first-steps', { timeout: 20000 } );

const card = await page.locator( '.first-steps' ).innerText();

check( 'the first screen says what to do first', /first steps/i.test( card ) );
check( 'and how much of it is left', /0 of 8 done/.test( card ), card.split( '\n' )[ 1 ] );
check( 'eight steps, in the order they have to happen',
	8 === await page.locator( '.first-step' ).count() );
check( 'the first one is marked as the next one',
	( await page.locator( '.first-step.is-next' ).innerText() ).includes( 'Add the venue' ) );
check( 'nothing is ticked yet', 0 === await page.locator( '.first-step.is-done' ).count() );

await page.screenshot( { path: `${ SHOTS }/01-nothing-done.png` } );

console.log( 'Each step opens the screen that finishes it' );
await page.locator( '.first-step.is-next button' ).click();
await page.waitForSelector( '.page-head__text h1' );

check( 'the venue step leads to the venues',
	/Venues/.test( await page.locator( '.page-head__text h1' ).innerText() ),
	await page.locator( '.page-head__text h1' ).innerText() );

console.log( 'A venue is added, and the list notices without being told' );
const added = await api( '/v1/venues', {
	method: 'POST',
	body: { name: 'Harbour Playhouse', city: 'Bristol', timezone: 'Europe/London' },
} );

check( 'the venue is created', 201 === added.status, String( added.status ) );

await page.click( 'nav button[data-view=overview]' );
await page.waitForSelector( '.first-steps' );

const after = await page.locator( '.first-steps' ).innerText();

check( 'one of the eight is now done', /1 of 8 done/.test( after ), after.split( '\n' )[ 1 ] );
check( 'and the next step has moved on',
	( await page.locator( '.first-step.is-next' ).innerText() ).includes( 'Draw the seating' ) );
check( 'the finished step offers nothing to open',
	0 === await page.locator( '.first-step.is-done button' ).count() );

console.log( 'The app the organiser has never seen' );
check( 'it has a screen of its own', await page.locator( 'nav button[data-view=webapp]' ).count() > 0 );

await page.click( 'nav button[data-view=webapp]' );
await page.waitForSelector( '.homescreen', { timeout: 20000 } );

check( 'a tile is drawn, before there is any address to draw it from',
	'' !== ( await page.locator( '.homescreen__tile' ).innerText() ).trim(),
	await page.locator( '.homescreen__tile' ).innerText() );
check( 'the name under it is the name that will be installed',
	'Harbour' === ( await page.locator( '#webapp-label' ).innerText() ).trim(),
	await page.locator( '#webapp-label' ).innerText() );
check( 'and it says plainly that nobody can install it yet',
	/Not installable yet/.test( await page.locator( '.card' ).last().innerText() ) );
check( 'with the reason, rather than just the fact',
	/not live/.test( await page.locator( '.card' ).last().innerText() ) );

await page.screenshot( { path: `${ SHOTS }/02-the-app.png` } );

console.log( 'The organiser renames it' );
await page.fill( '#webapp-name', 'The Harbour' );

check( 'the label follows what is being typed, before anything is saved',
	'The Harbour' === ( await page.locator( '#webapp-label' ).innerText() ).trim() );

await page.click( '#webapp-save' );
await page.waitForSelector( '.toast' );

const sites = await api( '/v1/sites' );
const saved = await api( '/v1/sites/' + sites.body.data[ 0 ].id + '/app' );

check( 'the choice is what a browser will now read',
	'The Harbour' === saved.body.install_name && 'The Harbour' === saved.body.chosen_name,
	JSON.stringify( [ saved.body.install_name, saved.body.chosen_name ] ) );
check( 'and the derived name is still offered, for going back to it',
	'Harbour' === saved.body.suggested_name, saved.body.suggested_name );
check( 'the rest of the brand survived the save',
	!! ( sites.body.data[ 0 ].brand || {} ).gateways,
	JSON.stringify( sites.body.data[ 0 ].brand ) );

console.log( 'A venue that has been selling for years' );
await page.evaluate( () => window.sessionStorage.clear() );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.stat-strip', { timeout: 20000 } );

check( 'is shown no beginners\' checklist at all',
	0 === await page.locator( '.first-steps' ).count() );

const settled = await api( '/v1/first-steps' );

check( 'because the account has taken real money', true === settled.body.settled,
	JSON.stringify( { settled: settled.body.settled, done: settled.body.done } ) );

check( 'nothing threw along the way', 0 === errors.length, errors.slice( 0, 3 ).join( ' | ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
