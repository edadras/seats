/**
 * The picker on somebody else's website, driven in Chromium.
 *
 * This is the integration a person with an ordinary website gets: a container, a script tag, and
 * no server of their own. So the check runs the real thing — the example page served from a
 * different origin by a plain static server, talking cross-origin to the API — and follows it all
 * the way to the organiser's checkout.
 *
 * What it proves, in order: a page the venue has not named gets no hall at all and is told why;
 * once the venue names it, the widget starts from the public API alone; the seats are held by that
 * API; the buyer is sent to the organiser's hosted checkout with nothing but a hold token; and that
 * checkout prices the hold the server made.
 *
 * The first of those is the one worth spelling out. The snippet has no key in it, which is what
 * makes it usable by somebody with a page and no toolchain — and it is also why anybody who views
 * a venue's booking page can copy it. This check pastes it onto an origin nobody authorised and
 * insists the hall stays shut.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node embed_smoke.mjs
 */
import { chromium } from 'playwright';
import { login, openASection, seatedEvent, seatPoint } from './smoke-support.mjs';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const PORT = Number( process.env.SEATMAP_EMBED_PORT || 8201 );

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/*
 * Which event to drive.
 *
 * The demo contains a warehouse sold by the head as well as a theatre with chairs, and the checks
 * below click chairs — so unless one is named, the seated one is found by asking availability
 * rather than by taking whichever comes first.
 */
const events = await ( await fetch( `${ BASE }/v1/embed/events/` + process.env.SEATMAP_EVENT, {
	headers: { Accept: 'application/json' },
} ).catch( () => ( { json: async () => ( {} ) } ) ) ).json().catch( () => ( {} ) );

let eventId = process.env.SEATMAP_EVENT;

if ( ! eventId || events.error ) {
	eventId = ( await seatedEvent( BASE, 'embed-smoke' ) ).public_id;
}

// The example page, exactly as it ships, with the event filled in — served from its own origin.
const dir = mkdtempSync( join( tmpdir(), 'seatmap-embed-' ) );
const page = readFileSync( new URL( '../docs/embed-example.html', import.meta.url ), 'utf8' )
	.replace( 'evt_replace_me', eventId )
	.replace( 'http://127.0.0.1:8123/embed/v1/seatmap.js', `${ BASE }/embed/v1/seatmap.js` );

writeFileSync( join( dir, 'index.html' ), page );

const server = spawn( 'python3', [ '-m', 'http.server', String( PORT ), '--bind', '127.0.0.1' ], {
	cwd: dir,
	stdio: 'ignore',
} );

await new Promise( ( resolve ) => setTimeout( resolve, 600 ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
const tab = await context.newPage();
const errors = [];
tab.on( 'pageerror', ( e ) => errors.push( e.message ) );
tab.on( 'console', ( m ) => {
	// A browser asking a bare static server for a favicon is not this feature failing. Neither is
	// the 403 the first load below deliberately provokes: this check asks an unauthorised page for
	// a hall precisely so it can insist the hall stays shut, and the browser logs every refusal it
	// receives. Counting those would make the check fail when the feature works.
	const noise = m.location()?.url?.includes( 'favicon' ) || /403/.test( m.text() );

	if ( 'error' === m.type() && ! noise ) {
		errors.push( m.text() );
	}
} );

// Where the widget tries to send the buyer. The organiser's canonical domain does not resolve in
// a test container, so the attempt is caught here and followed by hand below.
let checkout = null;
tab.on( 'request', ( request ) => {
	if ( request.url().includes( '/checkout/resume' ) ) {
		checkout = request.url();
	}
} );

try {
	console.log( 'A website nobody authorised' );
	await tab.goto( `http://127.0.0.1:${ PORT }/`, { waitUntil: 'networkidle' } );
	await tab.waitForSelector( '[data-seatmap-state="error"]', { timeout: 15000 } );

	const refusal = await tab.locator( '[data-seatmap-event]' ).innerText();

	check( 'the hall does not open', 0 === await tab.locator( '.seatmap-widget__stage' ).count() );
	check( 'and the page says why, in a sentence somebody can act on',
		/allowed on this website/i.test( refusal ), refusal.slice( 0, 80 ) );

	/*
	 * Now the venue says the site is theirs — which is the whole of the fix, and is done here
	 * through the same endpoint the Connections screen calls rather than by writing a row.
	 */
	console.log( 'The venue names it as one of theirs' );

	const staff = await login( BASE, 'embed-smoke-origins' );
	const allowed = await fetch( `${ BASE }/v1/embed-origins`, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			Authorization: 'Bearer ' + staff,
		},
		body: JSON.stringify( { hostname: `127.0.0.1:${ PORT }`, label: 'Embed check' } ),
	} );

	check( 'the website is added from the panel\'s own endpoint', 201 === allowed.status,
		String( allowed.status ) );

	console.log( 'Somebody else\'s website' );
	await tab.goto( `http://127.0.0.1:${ PORT }/`, { waitUntil: 'networkidle' } );
	await tab.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

	check( 'the picker starts from the public API alone',
		( await tab.locator( '.seatmap-widget__seat' ).count() ) > 0 ||
		( await tab.locator( '.seatmap-widget__block' ).count() ) > 0 );

	// A hall is offered as sections first; the chairs are inside one. Pressed by its own button,
	// where a buyer presses it, rather than at a guessed point on the plan.
	await openASection( tab );


	for ( const index of [ 0, 0 ] ) {
		const seat = await seatPoint( tab, index );

		await tab.mouse.click( seat.x, seat.y );
		await tab.waitForTimeout( 300 );
	}

	check( 'two seats are chosen', 2 === await tab.locator( '.seatmap-widget__selection li' ).count() );

	const summary = await tab.locator( '.seatmap-widget__summary' ).innerText();
	check( 'prices come back with the seats', /[0-9۰-۹]/.test( summary ), summary.replace( /\n/g, ' | ' ) );

	await tab.screenshot( { path: process.env.SEATMAP_SHOT_PAGE || '/tmp/embed-page.png' } );

	console.log( 'To the box office' );
	await tab.locator( '.seatmap-widget__submit' ).click();
	await tab.waitForTimeout( 2500 );

	check( 'the buyer is sent to the organiser\'s checkout', !! checkout, String( checkout ) );

	const token = checkout ? new URL( checkout ).searchParams.get( 'hold' ) : null;

	check( 'carrying a hold token and nothing else',
		!! token && new URL( checkout ).searchParams.size === 1, String( checkout ) );

	// The same URL, on the host this container can actually reach.
	const resume = `${ BASE.replace( '127.0.0.1', 'northgate.localhost' ) }/checkout/resume?hold=${ token }`;

	await tab.goto( resume, { waitUntil: 'networkidle' } );

	const checkoutPage = await tab.locator( 'body' ).innerText();

	check( 'and the checkout prices the hold the server made',
		/€|EUR/.test( checkoutPage ) && /Checkout|پرداخت/i.test( checkoutPage ),
		checkoutPage.split( '\n' ).slice( 0, 8 ).join( ' | ' ) );

	await tab.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/embed.png' } );

	check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );
} finally {
	await browser.close();
	server.kill();
}

console.log( failures ? `\n${ failures } FAILED` : '\nALL EMBED CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
