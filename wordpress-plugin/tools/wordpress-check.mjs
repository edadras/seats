/**
 * Buy a seat, in a real WordPress, with a real WooCommerce.
 *
 * Everything else about this plugin can be checked without WordPress: the signing code has
 * roundtrip-check.php, the widget has preview.html. What neither can see is the seam between the
 * plugin and WordPress itself — hook order, whether the cart exists in a REST request, what a block
 * theme does to the page before `wp_enqueue_scripts` fires. Two release-blocking bugs lived exactly
 * there, so this drives the whole purchase against the real thing.
 *
 * Set it up first with tools/wordpress-setup.sh, then:
 *
 *   node tools/wordpress-check.mjs --event evt_… --dir /tmp/seatmap-wordpress
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Playwright belongs to the API workspace, which is where the other browser checks live. Borrowed
// from there rather than adding a second copy of a browser for one script.
const here = path.dirname( fileURLToPath( import.meta.url ) );
const { chromium } = createRequire( path.join( here, '../../api/package.json' ) )( 'playwright' );

const args = Object.fromEntries(
	process.argv.slice( 2 ).reduce( ( pairs, value, index, all ) => {
		if ( value.startsWith( '--' ) ) {
			pairs.push( [ value.slice( 2 ), all[ index + 1 ] ] );
		}

		return pairs;
	}, [] )
);

const EVENT = args.event;
const SITE = ( args.url || 'http://127.0.0.1:8300' ).replace( /\/$/, '' );
const DIR = args.dir || path.join( process.env.TMPDIR || '/tmp', 'seatmap-wordpress' );

if ( ! EVENT ) {
	console.error( 'Usage: node wordpress-check.mjs --event evt_… [--url http://127.0.0.1:8300] [--dir DIR]' );
	process.exit( 2 );
}

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** Run a snippet inside the WordPress install and return its output. */
const wp = ( code ) => execFileSync( 'php', [ '-r', `require '${ DIR }/wordpress/wp-load.php'; ${ code }` ], {
	encoding: 'utf8',
	cwd: path.join( DIR, 'wordpress' ),
} ).trim();

console.log( 'Setting up the page' );

const pageUrl = wp( `
	wp_set_current_user( 1 );
	$existing = get_page_by_path( 'seatmap-check' );
	$id = wp_insert_post( array(
		'ID'           => $existing ? $existing->ID : 0,
		'post_title'   => 'Seatmap check',
		'post_name'    => 'seatmap-check',
		'post_content' => '<!-- wp:seatmap/event {"eventPublicId":"${ EVENT }"} /-->',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	) );
	echo get_permalink( $id );
` );

console.log( '  page:', pageUrl );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || undefined,
} );
const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text().slice( 0, 200 ) ); } );

console.log( 'The picker boots inside the theme' );

await page.goto( pageUrl, { waitUntil: 'networkidle' } );

// The default theme is a block theme, which renders the page's content before
// `wp_enqueue_scripts`. If the plugin registers its assets there, the inline configuration is
// dropped and the buyer is left with the noscript message.
//
// What it waits for is the *block* list, not seats: the picker opens on the plan of areas and a
// buyer zooms into one before there is a seat to click (ADR-0003 §5). Waiting for a seat here was
// waiting for a screen this app no longer shows first, and reported a working picker as broken.
const booted = await page.waitForSelector( '.seatmap-widget__block', { timeout: 20000 } )
	.then( () => true ).catch( () => false );

check( 'the plan of areas rendered', booted );

if ( ! booted ) {
	console.log( '\n1 CHECK(S) FAILED' );
	await browser.close();
	process.exit( 1 );
}

await page.waitForTimeout( 800 );
check( 'the plan is on the canvas', await page.locator( '.seatmap-widget__canvas' ).isVisible() );
check( 'prices came from the server',
	/\d/.test( await page.locator( '.seatmap-widget__legend' ).innerText() ) );

console.log( 'Choosing seats and reserving them' );

// Into the first area that still has seats, which is where the seats live.
const blocks = page.locator( '.seatmap-widget__block:not([disabled])' );

await blocks.first().click();
await page.waitForSelector( '.seatmap-widget__seat', { timeout: 20000 } );
await page.waitForTimeout( 400 );

const seats = page.locator( '.seatmap-widget__seat:not([disabled])' );
await seats.nth( 0 ).click();
await seats.nth( 1 ).click();
await page.waitForTimeout( 400 );

const chosen = await page.evaluate( () =>
	[ ...document.querySelectorAll( '.seatmap-widget__selection li span:first-child' ) ]
		.map( ( s ) => s.textContent ) );

check( 'both seats are in the summary', 2 === chosen.length, chosen.join( ' | ' ) );

await page.click( '.seatmap-widget__submit' );

// WooCommerce only builds the cart for ordinary page requests. A REST route that adds to it has
// to load it first, or `WC()->cart` is null and reserving a seat is a 500 rather than a cart —
// in which case the picker stays put and shows what went wrong.
const reserved = await page.waitForURL( ( u ) => u.href !== pageUrl, { timeout: 30000 } )
	.then( () => true ).catch( () => false );

if ( ! reserved ) {
	const message = await page.locator( '.seatmap-widget__message' ).innerText().catch( () => '' );

	check( 'the seats reached the cart', false, message.replace( /\s+/g, ' ' ).trim() || 'no navigation' );
	console.log( '\n1 CHECK(S) FAILED' );
	await browser.close();
	process.exit( 1 );
}

await page.waitForLoadState( 'networkidle' );

const lines = await page.locator( '.wc-block-cart-items__row, .cart_item' ).count();
check( 'the seats reached the cart', 2 === lines, `${ lines } lines` );
// The seat is written on the cart line by the plugin, not by the product — the product is one
// generic "Seat" for the whole venue. The Cart block fills its lines in after the page settles,
// so wait for the text rather than reading whatever is there the moment navigation ends.
const named = await page
	.waitForFunction(
		() => {
			const matches = document.body.innerText.match( /row [A-Za-z0-9]+, seat/g );

			return matches && matches.length >= 2 ? matches.length : false;
		},
		null,
		{ timeout: 15000 }
	)
	.then( ( handle ) => handle.jsonValue() )
	.catch( () => 0 );

check( 'each line names its seat', 2 === named, `${ named } named lines` );

console.log( 'Checking out' );

await page.goto( SITE + '/?page_id=' + wp( "echo (int) wc_get_page_id( 'checkout' );" ),
	{ waitUntil: 'networkidle' } );
await page.waitForSelector( '#email', { timeout: 20000 } );
await page.fill( '#email', 'buyer@example.test' );
await page.fill( '#billing-first_name', 'Amina' );
await page.fill( '#billing-last_name', 'Farsi' );
await page.fill( '#billing-address_1', 'Rosenthaler Str. 40' );
await page.fill( '#billing-postcode', '10178' );
await page.fill( '#billing-city', 'Berlin' );
await page.waitForTimeout( 600 );
await page.getByRole( 'button', { name: /place order/i } ).click();
await page.waitForURL( /order-received/, { timeout: 60000 } );

check( 'the order went through', /order-received/.test( page.url() ) );

// Identified from the thank-you URL, not "the most recent order": two runs in the same second
// would otherwise inspect each other's.
const orderId = Number( new URL( page.url() ).searchParams.get( 'order-received' ) );

const state = JSON.parse( wp( `
	$order = wc_get_order( ${ orderId } );
	echo wp_json_encode( array(
		'status'     => $order->get_status(),
		'registered' => (bool) $order->get_meta( '_seatmap_registered' ),
		'confirmed'  => 'yes' === $order->get_meta( '_seatmap_confirmed' ),
		'tickets'    => count( array_filter( (array) $order->get_meta( '_seatmap_tickets' ) ) ),
		'items'      => count( $order->get_items() ),
	) );
` ) );

check( 'the seats were registered with Seatmap', state.registered, JSON.stringify( state ) );
check( 'and confirmed once payment went through', state.confirmed );
check( 'a ticket came back for each seat', 2 === state.tickets );
check( 'both seats are on the order', 2 === state.items );

console.log( '\nConsole errors: ' + ( errors.length ? errors.join( ' | ' ) : 'none' ) );

if ( errors.length ) {
	failures++;
}

await browser.close();

console.log( failures === 0 ? '\nALL WORDPRESS CHECKS PASSED' : `\n${ failures } CHECK(S) FAILED` );
process.exit( failures === 0 ? 0 : 1 );
