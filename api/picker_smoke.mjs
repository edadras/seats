/**
 * The buyer's picker, driven in Chromium.
 *
 * The venue arrives as blocks; one is opened; its chairs appear and can be chosen; and there is a
 * way back out. That last one matters as much as the rest: a buyer who zooms into the wrong block
 * and cannot leave it has lost the whole venue.
 *
 * Then the other kind of room. Half the events on this platform have no chairs at all — a
 * warehouse, a festival tent, a standing gig — and the picker has to be a different thing there:
 * no seat legend, no seat list, a heading that does not say "seats", and a row per ticket type with
 * a stepper. Both are checked, and the run fails if the demo has stopped containing one of them,
 * because a path nobody looks at is a path that rots.
 *
 * Needs the API and the plugin's preview page:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   (cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)
 *   node picker_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const PREVIEW = process.env.SEATMAP_PREVIEW || 'http://127.0.0.1:8200';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const token = await ( await fetch( BASE + '/v1/auth/login', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( { email: 'owner@northgate.test', password: 'password', device_name: 'picker' } ),
} ) ).json().then( ( body ) => body.token );

const events = await ( await fetch( BASE + '/v1/events', {
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
} ) ).json();

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1200, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The preview page has no favicon; that 404 is the page's, not the picker's.
	if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() );
} );

/**
 * Where a seat is on screen.
 *
 * The chairs are chosen on the plan now — the list under it is there for keyboards and screen
 * readers, clipped out of the picture — so a check that drives a mouse has to ask the picker where
 * it drew things, exactly as a buyer's eye does.
 */
const seatPoint = ( index ) => page.evaluate( ( i ) => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const seats = widget.seats.filter( ( seat ) =>
		seat.floorKey === widget.floorKey && widget.inOpenBlock( seat ) && 'available' === seat.state );
	const seat = seats[ i ];
	const rect = widget.canvas.getBoundingClientRect();
	const scale = widget.baseScale * widget.view.scale;

	return {
		x: rect.left + seat.x * scale + widget.view.x,
		y: rect.top + seat.y * scale + widget.view.y,
		name: [ seat.section, seat.row, seat.label ].filter( Boolean ).join( ' · ' ),
	};
}, index );

const open = async ( publicId ) => {
	await page.goto( `${ PREVIEW }/tools/preview.html?api=${ BASE }&event=${ publicId }`,
		{ waitUntil: 'networkidle' } );
	await page.waitForSelector( '.seatmap-widget__title' );
};

/*
 * Which event is which is asked of the picker rather than of the API: "has chairs" is exactly the
 * question the picker itself answers, and a smoke test that decided it from a seat count would
 * agree with the code it is checking by construction. Standing places count towards a seat total,
 * so that number cannot tell the two rooms apart anyway.
 */
const rooms = {};

for ( const event of events.data ) {
	await open( event.public_id );

	const kind = await page.locator( '.seatmap-widget__seats' ).count() ? 'seated' : 'standing';

	rooms[ kind ] = rooms[ kind ] || event.public_id;
}

check( 'the demo still has a seated room', !! rooms.seated );
check( 'and a room sold by the head', !! rooms.standing );

if ( ! rooms.seated || ! rooms.standing ) {
	await browser.close();
	console.log( `\n${ failures } FAILED` );
	process.exit( 1 );
}

await open( rooms.seated );

console.log( 'The venue, as blocks' );
await page.waitForSelector( '.seatmap-widget__block' );
check( 'every block is offered', ( await page.locator( '.seatmap-widget__block' ).count() ) >= 2,
	( await page.locator( '.seatmap-widget__block-name' ).allInnerTexts() ).join( ', ' ) );
check( 'no chairs yet', 0 === await page.locator( '.seatmap-widget__seat' ).count() );
check( 'each block says what it costs to sit there',
	/From/.test( await page.locator( '.seatmap-widget__block-meta' ).first().innerText() ),
	await page.locator( '.seatmap-widget__block-meta' ).first().innerText() );
check( 'and there is no way back from where nobody has gone',
	await page.locator( '.seatmap-widget__back' ).isHidden() );

console.log( 'Into a block, from the plan itself' );
const box = await page.locator( '.seatmap-widget__canvas' ).boundingBox();
await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
await page.waitForSelector( '.seatmap-widget__list' );
check( 'the chairs are there', ( await page.locator( '.seatmap-widget__seat' ).count() ) > 20,
	`${ await page.locator( '.seatmap-widget__seat' ).count() } seats` );
check( 'one block’s chairs, not the building’s',
	1 === await page.locator( '.seatmap-widget__seats h4' ).count(),
	( await page.locator( '.seatmap-widget__seats h4' ).allInnerTexts() ).join( ', ' ) );
check( 'the way back appeared', await page.locator( '.seatmap-widget__back' ).isVisible() );
// Standing room and tables belong to the venue, not to the block being looked at.
check( 'and the standing offer stepped out of the way',
	await page.locator( '.seatmap-widget__areas' ).isHidden() );

check( 'the grid of chairs is folded away, not printed under the plan',
	await page.locator( '.seatmap-widget__list' ).evaluate( ( el ) => ! el.open ) );
check( 'and it opens for whoever wants to read it',
	await page.locator( '.seatmap-widget__list > summary' ).isVisible() );

console.log( 'Hovering' );
const first = await seatPoint( 0 );
await page.mouse.move( first.x, first.y );
await page.waitForTimeout( 200 );
check( 'the plan says what is under the pointer',
	await page.locator( '.seatmap-widget__tip' ).isVisible() );
check( 'and names that seat and its price',
	/row/.test( await page.locator( '.seatmap-widget__tip' ).innerText() ) &&
	/\d/.test( await page.locator( '.seatmap-widget__tip' ).innerText() ),
	await page.locator( '.seatmap-widget__tip' ).innerText() );

console.log( 'Choosing, on the plan' );
await page.mouse.click( first.x, first.y );
await page.waitForTimeout( 250 );
check( 'the seat is in the summary',
	( await page.locator( '.seatmap-widget__selection li' ).count() ) === 1,
	await page.locator( '.seatmap-widget__selection' ).innerText().then( ( t ) => t.replace( /\s+/g, ' ' ) ) );

console.log( 'Changing your mind' );
const second = await seatPoint( 0 );
await page.mouse.click( second.x, second.y );
await page.waitForTimeout( 250 );
check( 'two seats are held', 2 === await page.locator( '.seatmap-widget__selection li' ).count() );

// The plan is not the only way back out of a choice: after zooming into another section the chair
// you picked is nowhere to be found, and the summary is where the order actually lives.
await page.locator( '.seatmap-widget__drop' ).first().click();
await page.waitForTimeout( 250 );
check( 'and one can be dropped from the summary itself',
	1 === await page.locator( '.seatmap-widget__selection li' ).count(),
	await page.locator( '.seatmap-widget__selection' ).innerText().then( ( t ) => t.replace( /\s+/g, ' ' ) ) );

console.log( 'Back out' );
await page.click( '.seatmap-widget__back-button' );
await page.waitForSelector( '.seatmap-widget__block' );
check( 'the venue is back', ( await page.locator( '.seatmap-widget__block' ).count() ) >= 2 );
check( 'and the choice survived the trip',
	( await page.locator( '.seatmap-widget__selection li' ).count() ) === 1 );
check( 'and the standing offer is where it was left',
	await page.locator( '.seatmap-widget__areas' ).isVisible() );

console.log( 'Escape' );
await page.locator( '.seatmap-widget__block:not([disabled])' ).first().click();
await page.waitForSelector( '.seatmap-widget__list' );
await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 200 );
check( 'Escape leaves the block too',
	( await page.locator( '.seatmap-widget__block' ).count() ) >= 2 );

console.log( 'A room with no chairs in it' );

await open( rooms.standing );

check( 'it does not ask for seats',
	'Choose your tickets' === await page.locator( '.seatmap-widget__title' ).innerText() );
check( 'there is no seat list to be empty', 0 === await page.locator( '.seatmap-widget__seats' ).count() );
check( 'and no legend for states a chair has',
	! /Selected|Unavailable/.test( await page.locator( '.seatmap-widget__legend' ).innerText() ),
	await page.locator( '.seatmap-widget__legend' ).innerText() );

const tickets = page.locator( '.seatmap-widget__area' );
check( 'every ticket type is offered', ( await tickets.count() ) >= 3, `${ await tickets.count() } types` );
check( 'each says what is left',
	/left/.test( await tickets.first().locator( '.seatmap-widget__area-left' ).innerText() ),
	await tickets.first().locator( '.seatmap-widget__area-left' ).innerText() );

// Two of one kind, which is the whole interaction: there is no chair to click.
const plus = tickets.first().locator( '.seatmap-widget__stepper button' ).last();
await plus.click();
await plus.click();
await page.waitForTimeout( 250 );

check( 'a quantity can be taken', '2' === await tickets.first().locator( 'output' ).innerText(),
	await tickets.first().locator( 'output' ).innerText() );
check( 'and it reaches the summary',
	1 === await page.locator( '.seatmap-widget__selection li' ).count(),
	await page.locator( '.seatmap-widget__selection' ).innerText().then( ( t ) => t.replace( /\s+/g, ' ' ) ) );
check( 'which can then be reserved',
	! await page.locator( '.seatmap-widget__submit' ).isDisabled() );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL PICKER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
