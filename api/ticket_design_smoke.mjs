/**
 * Designing the ticket for one night, driven in Chromium.
 *
 * `TicketDesignTest` holds up the rules — a field nobody declared is dropped, a position off the
 * page is pulled back, a landscape design comes out landscape. What a browser adds is the thing
 * those rules exist for: an organiser looking at a picture, dragging words onto it, and being able
 * to tell whether the result is a document before four hundred of them are printed.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node ticket_design_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/ticket-design-shots';

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

console.log( 'The organiser opens the ticket for a night' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-ticket-design]', { timeout: 20000 } );

check( 'every night offers one', await page.locator( '[data-ticket-design]' ).count() > 0 );

await page.locator( '[data-ticket-design]' ).first().click();
await page.waitForSelector( '#td-board', { timeout: 20000 } );

// A night with no design of its own starts from the platform's ticket rather than a blank page.
const started = await page.locator( '.ticket-field' ).count();

check( 'it starts from a real ticket rather than an empty page', started > 4, `${ started } fields` );

console.log( 'The page takes the shape of the paper' );
const wide = await page.locator( '#td-board' ).boundingBox();

await page.selectOption( '#td-orient', 'portrait' );
await page.waitForTimeout( 300 );

const tall = await page.locator( '#td-board' ).boundingBox();

check( 'turning the paper turns the board',
	tall.height > wide.height && tall.width <= wide.width,
	`${ Math.round( wide.width ) }×${ Math.round( wide.height ) } → ${ Math.round( tall.width ) }×${ Math.round( tall.height ) }` );

await page.selectOption( '#td-orient', 'landscape' );
await page.waitForTimeout( 300 );

// And the paper itself, not only the way round. A4, A5 and A6 are within half a per cent of one
// another, so a board that assumed a shape would look right on all three and be wrong on letter —
// which is the one this asks for.
const a5 = await page.locator( '#td-board' ).boundingBox();

await page.selectOption( '#td-page', 'letter' );
await page.waitForTimeout( 300 );

const letter = await page.locator( '#td-board' ).boundingBox();

check( 'and choosing a different paper reshapes it',
	Math.abs( ( letter.width / letter.height ) - ( a5.width / a5.height ) ) > 0.05,
	`${ ( a5.width / a5.height ).toFixed( 3 ) } → ${ ( letter.width / letter.height ).toFixed( 3 ) }` );

await page.selectOption( '#td-page', 'A5' );
await page.waitForTimeout( 300 );

console.log( 'A field is dragged, and lands where it was dropped' );
const chip = page.locator( '.ticket-field' ).first();
const board = await page.locator( '#td-board' ).boundingBox();
const before = await chip.boundingBox();

// A named target rather than "somewhere else": the point of percentages is that the field lands
// where the pointer let go, and a check that only asks whether it moved passes just as happily when
// a division by a detached board's zero width pins every drag to the edge of the page.
const target = { x: board.x + board.width * 0.45, y: board.y + board.height * 0.62 };

await page.mouse.move( before.x + 12, before.y + 6 );
await page.mouse.down();
await page.mouse.move( target.x, target.y, { steps: 12 } );
await page.mouse.up();
await page.waitForTimeout( 200 );

const after = await page.locator( '.ticket-field' ).first().boundingBox();

check( 'the field moved', Math.abs( after.x - before.x ) > 40 || Math.abs( after.y - before.y ) > 40,
	`${ Math.round( before.x ) },${ Math.round( before.y ) } → ${ Math.round( after.x ) },${ Math.round( after.y ) }` );

// Within a few pixels of where the pointer was let go, allowing for the place on the chip it was
// picked up by.
check( 'and landed under the pointer',
	Math.abs( after.x + 12 - target.x ) < 12 && Math.abs( after.y + 6 - target.y ) < 12,
	`${ Math.round( after.x + 12 ) },${ Math.round( after.y + 6 ) } vs ${ Math.round( target.x ) },${ Math.round( target.y ) }` );

// Chosen by the drag, so the inspector is about the thing under the pointer.
check( 'and is the one the inspector is about',
	await page.locator( '.ticket-field.is-picked' ).count() === 1 );

await page.screenshot( { path: `${ SHOTS }/01-the-board.png` } );

console.log( 'It is saved, and it is what the server keeps' );
await page.click( '#td-save' );
await page.waitForSelector( '.toast' );

const token = await page.evaluate( () => window.sessionStorage.getItem( 'seatmap_token' ) );
const eventId = await page.evaluate( () => window.SeatmapTicketDesign.eventId );

const saved = await page.evaluate( async ( { bearer, id } ) => {
	const response = await fetch( '/v1/events/' + id + '/ticket-design', {
		headers: { Authorization: 'Bearer ' + bearer, Accept: 'application/json' },
	} );

	return response.json();
}, { bearer: token, id: eventId } );

check( 'the design is stored against that night', !! saved.design,
	saved.design ? `${ saved.design.fields.length } fields, ${ saved.design.orientation }` : 'none' );
check( 'and the dragged field kept its new place',
	saved.design.fields.some( ( field ) => field.x > 30 && field.x < 70 ),
	JSON.stringify( saved.design.fields.map( ( f ) => [ f.key, f.x ] ).slice( 0, 3 ) ) );
// Nothing was dragged off the right-hand edge: x is the left of a box as wide as `width`.
check( 'and every field is still on the page',
	saved.design.fields.every( ( field ) => field.x + field.width <= 100.01 ),
	JSON.stringify( saved.design.fields.map( ( f ) => [ f.key, f.x + f.width ] ).slice( 0, 3 ) ) );

console.log( 'And the preview is a real PDF' );
const preview = await page.evaluate( async ( { bearer, id } ) => {
	const response = await fetch( '/v1/events/' + id + '/ticket-design/preview', {
		headers: { Authorization: 'Bearer ' + bearer },
	} );

	return {
		status: response.status,
		type: response.headers.get( 'content-type' ),
		head: ( await response.text() ).slice( 0, 5 ),
	};
}, { bearer: token, id: eventId } );

check( 'it renders', 200 === preview.status, String( preview.status ) );
check( 'as a PDF', /application\/pdf/.test( preview.type || '' ), preview.type );
check( 'and it really is one', '%PDF-' === preview.head, preview.head );

console.log( 'And the night can go back to the standard ticket' );
await page.click( '#td-clear' );
// Waiting for the button to go rather than for a toast: the one from the save may still be on
// screen, and a check that passes on somebody else's notice is not a check.
await page.waitForSelector( '#td-clear', { state: 'detached' } );

const cleared = await page.evaluate( async ( { bearer, id } ) => {
	const response = await fetch( '/v1/events/' + id + '/ticket-design', {
		headers: { Authorization: 'Bearer ' + bearer, Accept: 'application/json' },
	} );

	return response.json();
}, { bearer: token, id: eventId } );

check( 'the design is gone from the server', null === cleared.design );
// The board keeps the platform's layout rather than emptying: "no design" is the standard ticket,
// not a blank page.
check( 'and the board still shows a ticket', await page.locator( '.ticket-field' ).count() > 4 );
check( 'and the way back is no longer offered',
	0 === await page.locator( '#td-clear' ).count() );

check( 'nothing threw along the way', 0 === errors.length, errors.slice( 0, 2 ).join( ' | ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
