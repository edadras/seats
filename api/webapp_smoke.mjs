/**
 * A venue's site as an app, driven in Chromium.
 *
 * `WebAppTest` holds up what the server sends. Everything that matters about this feature happens
 * in the browser, though, and only two claims are worth a check of this size:
 *
 *   - **the ticket opens at the door.** A booking is made, the network is then switched off, and the
 *     confirmation page is asked for again. If the seat numbers come back, this feature works; if
 *     they do not, nothing else about it matters.
 *   - **nothing about a session survives.** The same network switch, on the account page, must
 *     produce no page at all. A ticket shop that leaves somebody's order history in a cache on a
 *     borrowed phone has made things worse, not better.
 *
 * The rest is the shape of the thing on a phone: a header that folds, a page that never scrolls
 * sideways, and the reserve button staying on screen while a plan is being dragged.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node webapp_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/webapp-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const night = await seatedEvent( BASE, 'webapp-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

/*
 * The files below are asked for from inside the browser rather than from node.
 *
 * `*.localhost` is a loopback name a browser resolves and a DNS resolver does not, so a check that
 * fetched them from here would be testing node's resolver. Asking the page itself also happens to
 * be the honest question: these are files a browser fetches.
 */
const shop = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
const buyer = await shop.newPage();

buyer.on( 'pageerror', ( e ) => errors.push( e.message ) );
// The disconnection below is deliberate, so the browser's own note about it is not a fault.
const noise = /404|ERR_INTERNET_DISCONNECTED|Failed to load resource/;

buyer.on( 'console', ( m ) => { if ( 'error' === m.type() && ! noise.test( m.text() ) ) errors.push( m.text() ); } );

await buyer.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

const ask = ( path, headers ) => buyer.evaluate( async ( [ where, sent ] ) => {
	const response = await fetch( where, { headers: sent || {}, cache: 'no-store' } );
	const bytes = new Uint8Array( await response.clone().arrayBuffer() );

	return {
		status: response.status,
		type: response.headers.get( 'content-type' ) || '',
		etag: response.headers.get( 'etag' ) || '',
		length: bytes.length,
		first: bytes[ 0 ],
		text: bytes.length < 40000 ? await response.text() : '',
	};
}, [ path, headers ] );

console.log( 'What a browser is told before it offers to install anything' );

const manifest = await ask( '/manifest.webmanifest' );
const described = JSON.parse( manifest.text );

check( 'the manifest is served as one', /application\/manifest\+json/.test( manifest.type ), manifest.type );
check( 'under the venue\u2019s own name', 'Northgate Theatre' === described.name, described.name );
check( 'with a short name that fits under an icon', described.short_name.length <= 12, described.short_name );
check( 'it opens at the top of the site and owns the whole of it',
	'/' === described.start_url && '/' === described.scope );
check( 'it opens without browser chrome', 'standalone' === described.display );
check( 'it is coloured by the venue rather than by this platform',
	/^#[0-9a-f]{6}$/.test( described.theme_color ) && '#4a4fdc' !== described.theme_color,
	described.theme_color );
check( 'and it offers a tile at every size a launcher asks for',
	[ 180, 192, 512 ].every( ( size ) => described.icons.some( ( icon ) => icon.sizes === size + 'x' + size ) ),
	described.icons.map( ( icon ) => icon.sizes ).join( ', ' ) );

const tile = await ask( '/app-icon-512.png' );

check( 'the tile is a real picture, drawn on the server',
	'image/png' === tile.type && 0x89 === tile.first && tile.length > 400,
	`${ tile.length } bytes` );

const again = await ask( '/app-icon-512.png' );

// The 304 itself is proved in `WebAppTest`: a conditional header is at the browser cache's
// discretion here, and what matters from the browser's side is that the tag does not move.
check( 'and it carries a tag that does not move while the colours do not',
	'' !== tile.etag && tile.etag === again.etag, tile.etag );

// The panel answers on a host too, and it is not a thing anybody installs.
const notAnApp = await fetch( `${ BASE }/manifest.webmanifest` );

check( 'the control panel is not an app', 404 === notAnApp.status, String( notAnApp.status ) );

const worker = ( await ask( '/sw.js' ) ).text;

check( 'the worker is told which build it is', /SEATMAP_BUILD="[a-f0-9]{12}"/.test( worker ) );
check( 'and which theme this site is actually wearing',
	/SEATMAP_SHELL=\[[^\]]*themes\//.test( worker ),
	( worker.match( /themes\/[a-z]+\.css/ ) || [ 'none' ] )[ 0 ] );

console.log( 'Somebody buys a ticket' );
await buyer.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await buyer.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( buyer );

for ( const index of [ 0, 1 ] ) {
	const seat = await seatPoint( buyer, index );

	await buyer.mouse.click( seat.x, seat.y );
	await buyer.waitForTimeout( 300 );
}

await buyer.locator( '.seatmap-widget__submit' ).click();
await buyer.waitForURL( /\/checkout/, { timeout: 15000 } );
await buyer.fill( '#name', 'Amina Farsi' );
await buyer.fill( '#email', 'amina@example.test' );
await buyer.locator( '.checkout__submit' ).click();
await buyer.waitForURL( /\/order\//, { timeout: 20000 } );

const ticketUrl = buyer.url();

check( 'the booking goes through', /\/order\//.test( ticketUrl ), ticketUrl );

// The worker installs on load and claims the pages that are already open; the reload below is what
// makes certain this particular page went *through* it rather than past it.
const claimed = await buyer.evaluate( () => navigator.serviceWorker.ready.then( function () {
	return !! navigator.serviceWorker.controller;
} ) );

check( 'a worker is running for this site', claimed );

await buyer.reload( { waitUntil: 'networkidle' } );

const seatsWithSignal = await buyer.locator( '.ticket__seat' ).allInnerTexts();

check( 'the tickets are on the confirmation', seatsWithSignal.length >= 2, seatsWithSignal.join( ', ' ) );

console.log( 'And then arrives at a door with no signal' );

/*
 * Aborted at the route, not with `setOffline`.
 *
 * `setOffline` is applied to the page's own network stack, and a request a service worker makes is
 * not the page's — so the worker went on fetching happily and the check passed for the wrong
 * reason. Refusing every request in the context is the state this feature exists for.
 */
await shop.route( '**/*', ( route ) => route.abort( 'internetdisconnected' ) );
await buyer.reload( { waitUntil: 'domcontentloaded' } );

const seatsWithout = await buyer.locator( '.ticket__seat' ).allInnerTexts();

check( 'the same tickets open with the network switched off',
	seatsWithout.length > 0 && seatsWithout.join( '|' ) === seatsWithSignal.join( '|' ),
	seatsWithout.join( ', ' ) || 'nothing rendered' );

const codes = await buyer.locator( 'img.ticket__qr' ).count();

check( 'and the codes a scanner reads are there too', codes >= 2, `${ codes } codes` );

await buyer.screenshot( { path: `${ SHOTS }/01-ticket-with-no-signal.png`, fullPage: true } );

console.log( 'A page nobody has opened before says so, in the venue’s own words' );
await buyer.goto( `${ SITE }/nothing-anybody-has-been-to`, { waitUntil: 'domcontentloaded' } );

check( 'the offline page is served instead of a browser error',
	/No connection/i.test( await buyer.locator( 'body' ).innerText() ),
	( await buyer.locator( 'h1' ).innerText().catch( () => '' ) ) );

await buyer.screenshot( { path: `${ SHOTS }/02-offline.png` } );

console.log( 'What is never kept' );
let accountReached = true;

try {
	await buyer.goto( `${ SITE }/account`, { waitUntil: 'domcontentloaded', timeout: 8000 } );
} catch ( error ) {
	accountReached = false;
}

check( 'an account page is not in any cache', ! accountReached,
	accountReached ? 'it came back offline' : 'refused, as it should be' );

await shop.unroute( '**/*' );

console.log( 'The same shop, in one hand' );
const pocket = await browser.newContext( {
	viewport: { width: 390, height: 844 },
	deviceScaleFactor: 2,
	isMobile: true,
	hasTouch: true,
} );
const phone = await pocket.newPage();

phone.on( 'pageerror', ( e ) => errors.push( e.message ) );
phone.on( 'console', ( m ) => { if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() ); } );

const sideways = async ( page ) => page.evaluate(
	() => document.documentElement.scrollWidth - document.documentElement.clientWidth
);

await phone.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

check( 'the header folds into a button', await phone.locator( '.menu__button' ).isVisible() );
check( 'and the links are behind it', ! await phone.locator( '.menu__panel' ).isVisible() );

await phone.locator( '.menu__button' ).click();
await settle( 250 );

check( 'which opens them', await phone.locator( '.menu__panel' ).isVisible() );
check( 'and says so where a screen reader can hear it',
	'true' === await phone.locator( '.menu__button' ).getAttribute( 'aria-expanded' ) );
check( 'no page scrolls sideways on a phone', 0 === await sideways( phone ) );

await phone.screenshot( { path: `${ SHOTS }/03-the-menu.png` } );

await phone.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await phone.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );

check( 'nor the page a seat is chosen on', 0 === await sideways( phone ) );
check( 'and nothing is in the way before a seat is chosen',
	'fixed' !== await phone.evaluate(
		() => getComputedStyle( document.querySelector( '.seatmap-widget__summary' ) ).position
	) );

await openASection( phone );

const pick = await seatPoint( phone, 0 );

await phone.touchscreen.tap( pick.x, pick.y );
await settle( 700 );

const tray = await phone.evaluate( () => {
	const summary = document.querySelector( '.seatmap-widget__summary' );
	const box = summary.getBoundingClientRect();

	return {
		position: getComputedStyle( summary ).position,
		offBottom: Math.round( window.innerHeight - box.bottom ),
		buttonOnScreen: summary.querySelector( '.seatmap-widget__submit' )
			.getBoundingClientRect().bottom <= window.innerHeight,
	};
} );

check( 'once one is, the total and the button come to the thumb', 'fixed' === tray.position, tray.position );
check( 'at the foot of the screen', 0 === tray.offBottom, `${ tray.offBottom }px off` );
check( 'with the button itself on screen', tray.buttonOnScreen );

await phone.screenshot( { path: `${ SHOTS }/04-reserve-in-reach.png` } );

console.log( 'And laid out as a row again where there is room' );
const desk = await ( await browser.newContext( { viewport: { width: 1280, height: 900 } } ) ).newPage();

await desk.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

check( 'the button is gone on a desktop', ! await desk.locator( '.menu__button' ).isVisible() );
check( 'and the links are simply there', await desk.locator( '.menu__panel' ).isVisible() );

await desk.screenshot( { path: `${ SHOTS }/05-desktop.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL WEB APP CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
