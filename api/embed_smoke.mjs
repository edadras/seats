/**
 * The picker on somebody else's website, driven in Chromium.
 *
 * This is the integration a person with an ordinary website gets: a container, a script tag, and
 * no server of their own. So the check runs the real thing — the example page served from a
 * different origin by a plain static server, talking cross-origin to the API — and follows it all
 * the way to the organiser's checkout.
 *
 * What it proves, in order: the widget starts from the public API alone, the seats are held by
 * that API, the buyer is sent to the organiser's hosted checkout with nothing but a hold token,
 * and that checkout prices the hold the server made.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node embed_smoke.mjs
 */
import { chromium } from 'playwright';
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

// The seated event, asked of the API rather than assumed: the demo has a warehouse in it too.
const events = await ( await fetch( `${ BASE }/v1/embed/events/` + process.env.SEATMAP_EVENT, {
	headers: { Accept: 'application/json' },
} ).catch( () => ( { json: async () => ( {} ) } ) ) ).json().catch( () => ( {} ) );

let eventId = process.env.SEATMAP_EVENT;

if ( ! eventId || events.error ) {
	const { seatedEvent } = await import( './smoke-support.mjs' );
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
	// A browser asking a bare static server for a favicon is not this feature failing.
	if ( 'error' === m.type() && ! m.location()?.url?.includes( 'favicon' ) ) {
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
	console.log( 'Somebody else\'s website' );
	await tab.goto( `http://127.0.0.1:${ PORT }/`, { waitUntil: 'networkidle' } );
	await tab.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

	check( 'the picker starts from the public API alone',
		( await tab.locator( '.seatmap-widget__seat' ).count() ) > 0 ||
		( await tab.locator( '.seatmap-widget__block' ).count() ) > 0 );

	// A hall is offered as blocks first; the chairs are inside one. Clicked on the plan, where a
	// buyer clicks, which means asking the picker where it drew them.
	if ( await tab.locator( '.seatmap-widget__block' ).count() ) {
		const canvas = await tab.locator( '.seatmap-widget__canvas' ).boundingBox();

		await tab.mouse.click( canvas.x + canvas.width / 2, canvas.y + canvas.height / 2 );
		await tab.waitForSelector( '.seatmap-widget__list' );
	}

	const seatPoint = ( index ) => tab.evaluate( ( i ) => {
		const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
		const seats = widget.seats.filter( ( seat ) =>
			seat.floorKey === widget.floorKey && widget.inOpenBlock( seat ) && 'available' === seat.state );
		const rect = widget.canvas.getBoundingClientRect();
		const scale = widget.baseScale * widget.view.scale;

		return {
			x: rect.left + seats[ i ].x * scale + widget.view.x,
			y: rect.top + seats[ i ].y * scale + widget.view.y,
		};
	}, index );

	for ( const index of [ 0, 0 ] ) {
		const seat = await seatPoint( index );

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
