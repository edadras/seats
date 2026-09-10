/**
 * A ticket in somebody's hand, driven in Chromium.
 *
 * `TicketPrintingTest` holds up the rules. What a browser adds is the counter itself: a clerk who
 * ticks "print" once and never thinks about it again, a strip of paper that carries the QR as an
 * image because a print dialogue cannot compute one, and a reprint that quietly stops the copy
 * somebody says they lost from working.
 *
 * The print dialogue is never opened — `window.print` is replaced before the page loads, in every
 * frame, because a dialogue in a headless browser is a hang rather than a failure. What is checked
 * is that it was reached, and what was on the paper when it was.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node printing_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/printing-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'printing-smoke' );

const api = ( method, path ) => fetch( BASE + path, {
	method,
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + night.token },
} ).then( async ( response ) => ( { status: response.status, body: await response.json().catch( () => ( {} ) ) } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 }, acceptDownloads: true } );

// In every frame, including the print frame: the ticket is laid out in an iframe of its own, and
// that is the window whose `print` would otherwise stop the run.
await context.addInitScript( () => {
	window.print = function () {
		try {
			window.top.__printed = ( window.top.__printed || 0 ) + 1;
		} catch ( error ) {
			/* A frame that cannot see the top window is not one this check is about. */
		}
	};
} );

const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'A sale at the window, with the printer beside it' );
await page.click( 'nav button[data-view=counter]' );
await page.waitForSelector( '#counter-event' );
await page.selectOption( '#counter-event', { label: 'Opening night' } );
await page.waitForSelector( '.counter__blocks' );
await page.locator( '.counter__block:not([disabled])' ).first().click();
await page.waitForSelector( '.counter__seat' );
await page.locator( '.counter__seat:not([disabled])' ).first().click();
await page.waitForTimeout( 300 );

await page.click( '#counter-sell' );
await page.waitForSelector( '.modal' );
await page.fill( '#c-name', 'Paper buyer' );
await page.selectOption( '#c-payment', 'comp' );

check( 'printing is offered at the counter, and starts off',
	await page.locator( '#c-print' ).count() === 1 && ! await page.locator( '#c-print' ).isChecked() );

await page.locator( '#c-print' ).check();
await page.screenshot( { path: `${ SHOTS }/01-sell.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );
await page.waitForTimeout( 3000 );

const toast = await page.locator( '.toast' ).innerText();
const reference = ( toast.match( /bo-[a-z0-9]+/ ) || [ '' ] )[ 0 ];

check( 'the sale completes with a booking reference', !! reference, toast );
check( 'and the ticket went to the printer without anybody asking where',
	1 === await page.evaluate( () => window.__printed || 0 ) );

const paper = await page.evaluate( () => {
	const frame = document.querySelector( 'iframe[aria-hidden=true]' );

	return frame ? frame.srcdoc : '';
} );

check( 'the paper carries the event, the seat and the price',
	paper.includes( 'Opening night' ) && /class="seat"/.test( paper ) && /class="pair"/.test( paper ),
	`${ paper.length } characters` );
// The one thing a print dialogue cannot do for itself: the code is drawn server-side and arrives
// as an image, because the browser has no way to compute a QR from a token.
check( 'and the QR itself, drawn rather than described',
	paper.includes( 'data:image/svg+xml' ) );
// Millimetres, because a roll is a physical width and the driver is told it in `@page`.
check( 'laid out for the roll rather than for A4', /@page \{ size: 80mm auto/.test( paper ) );

console.log( 'The same booking, printed again from the orders screen' );
const codes = async () => {
	const answer = await api(
		'GET',
		`/v1/tickets?event_id=${ night.id }&q=${ encodeURIComponent( reference ) }&per_page=100`
	);

	return ( answer.body.data || [] ).map( ( ticket ) => ticket.token_prefix || '' ).join( ',' );
};

const before = await codes();

await page.click( 'nav button[data-view=orders]' );
await page.waitForSelector( '[data-order]', { timeout: 20000 } );
await page.fill( '#order-search', reference ).catch( () => {} );
await page.waitForTimeout( 1200 );
await page.locator( '[data-order]' ).first().click();
await page.waitForSelector( '#order-print', { timeout: 20000 } );

check( 'the booking offers paper, and so does the single seat',
	await page.locator( '#order-print' ).isVisible() &&
	( await page.locator( '[data-print]' ).count() ) > 0 );

await page.click( '#order-print' );
await page.waitForSelector( '#print-destination' );

check( 'it says what printing costs before anybody presses it',
	/new code/i.test( await page.locator( '.modal__body' ).innerText() ),
	( await page.locator( '.modal__body' ).innerText() ).split( '\n' )[ 0 ] );
check( 'and offers both roll widths', 2 === await page.locator( '#print-width option' ).count() );

await page.screenshot( { path: `${ SHOTS }/02-print.png` } );

// The other destination: the bytes themselves, for a local agent to push at the roll printer.
await page.selectOption( '#print-destination', 'escpos' );
await page.selectOption( '#print-width', '58' );

const download = await Promise.all( [
	page.waitForEvent( 'download', { timeout: 20000 } ),
	page.click( '.modal button[type=submit]' ),
] ).then( ( [ event ] ) => event );

const stream = await download.createReadStream();
const chunks = [];

for await ( const chunk of stream ) {
	chunks.push( chunk );
}

const bytes = Buffer.concat( chunks );

check( 'the roll printer gets a stream that starts by resetting the printer',
	0x1b === bytes[ 0 ] && 0x40 === bytes[ 1 ], bytes.length + ' bytes' );
// GS ( k — the QR store command. Without it the paper has a price and no way in.
check( 'with the code as a QR the printer draws itself',
	bytes.includes( Buffer.from( [ 0x1d, 0x28, 0x6b ] ) ) );
// GS V — the cut. Two seats left on one strip is two people sharing a ticket at the door.
check( 'and a cut at the end of it', bytes.includes( Buffer.from( [ 0x1d, 0x56 ] ) ) );

console.log( 'And the copy somebody says they lost has stopped working' );
const after = await codes();

check( 'printing re-mints the code rather than reproducing it',
	'' !== before && before !== after, `${ before } → ${ after }` );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
