/**
 * Putting a picture on things, driven in Chromium.
 *
 * `MediaLibraryTest` holds up the rules — what may be kept, what is refused, what is re-encoded.
 * What a browser adds is the part those rules exist for: somebody with a poster on their desktop,
 * who should be able to drag it onto the field and see it land, in every place a picture belongs.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node media_smoke.mjs
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/media-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

mkdirSync( SHOTS, { recursive: true } );

/*
 * A real PNG, written to disk, because the server decides a file's type from its bytes.
 *
 * Sixteen pixels of solid colour, hand-assembled: a fixture committed as a binary would be a file
 * nobody can read in a diff, and the point of this one is only that it is genuinely a picture.
 */
const png = ( () => {
	const crc = ( bytes ) => {
		let c = ~0;
		for ( const byte of bytes ) {
			c ^= byte;
			for ( let i = 0; i < 8; i++ ) c = ( c >>> 1 ) ^ ( 0xEDB88320 & -( c & 1 ) );
		}
		return ~c >>> 0;
	};

	const chunk = ( type, data ) => {
		const head = Buffer.alloc( 4 );
		head.writeUInt32BE( data.length );

		const body = Buffer.concat( [ Buffer.from( type, 'ascii' ), data ] );
		const tail = Buffer.alloc( 4 );
		tail.writeUInt32BE( crc( body ) );

		return Buffer.concat( [ head, body, tail ] );
	};

	const side = 16;
	const header = Buffer.alloc( 13 );
	header.writeUInt32BE( side, 0 );
	header.writeUInt32BE( side, 4 );
	header[ 8 ] = 8;   // eight bits a channel
	header[ 9 ] = 2;   // truecolour, no alpha

	const rows = Buffer.concat( Array.from( { length: side }, () => Buffer.concat( [
		Buffer.from( [ 0 ] ),   // no filter on this row
		Buffer.from( Array.from( { length: side * 3 }, ( _, i ) => ( i % 3 ? 0x33 : 0xCC ) ) ),
	] ) ) );

	return Buffer.concat( [
		Buffer.from( [ 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A ] ),
		chunk( 'IHDR', header ),
		chunk( 'IDAT', deflateSync( rows ) ),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
} )();

const POSTER = `${ SHOTS }/poster.png`;
const NOT_A_PICTURE = `${ SHOTS }/takings.csv`;

writeFileSync( POSTER, png );
writeFileSync( NOT_A_PICTURE, 'section,sold\nstalls,412\n' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1100 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'An organiser puts a poster on a night' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-event-edit]', { timeout: 20000 } );
await page.locator( '[data-event-edit]' ).first().click();
await page.waitForSelector( '.modal .media-field' );

check( 'the artwork field is somewhere to drop a file',
	await page.locator( '.modal .media-drop' ).count() === 1 );

await page.setInputFiles( '.modal .media-field input[type=file]', POSTER );
await page.waitForSelector( '.modal .media-drop__art img', { timeout: 20000 } );

const shown = await page.getAttribute( '.modal .media-drop__art img', 'src' );

check( 'it uploads and the picture appears', /\/media\/[0-9a-f-]{36}\//.test( shown || '' ), shown );
check( 'and the form is carrying that address',
	await page.inputValue( '.modal input[name=image_url]' ) === shown );

await page.screenshot( { path: `${ SHOTS }/01-on-a-night.png` } );

// The address is a public one: this is a poster, and it is about to be on a website.
const served = await page.evaluate( async ( url ) => {
	const response = await fetch( url, { headers: {} } );
	return { status: response.status, type: response.headers.get( 'content-type' ) };
}, shown );

check( 'the address serves the picture to anybody', 200 === served.status, String( served.status ) );
check( 'as a picture', /^image\//.test( served.type || '' ), served.type );

await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.toast' );

console.log( 'And it is still there when the night is opened again' );
await page.locator( '[data-event-edit]' ).first().click();
await page.waitForSelector( '.modal .media-drop__art img', { timeout: 20000 } );

check( 'the poster was saved against the event',
	await page.getAttribute( '.modal .media-drop__art img', 'src' ) === shown );

console.log( 'A file that is not a picture is refused, in words' );
await page.setInputFiles( '.modal .media-field input[type=file]', NOT_A_PICTURE );

// Waited for by its *content*, not by the element: the toast from saving the event a moment ago is
// still on screen, and a check that reads whichever toast exists would pass on that one.
await page.waitForFunction(
	() => /JPEG|PNG|WebP/i.test( document.querySelector( '.toast' )?.textContent || '' ),
	null,
	{ timeout: 20000 }
);

const complaint = await page.textContent( '.toast' );

check( 'and it says what may be used instead', /JPEG|PNG|WebP/i.test( complaint || '' ), complaint );
check( 'while the poster that was there is untouched',
	await page.getAttribute( '.modal .media-drop__art img', 'src' ) === shown );

await page.click( '.modal [data-close]' );

console.log( 'The same poster is offered again rather than uploaded twice' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-ticket-design]', { timeout: 20000 } );
await page.locator( '[data-ticket-design]' ).first().click();
await page.waitForSelector( '#td-board' );

check( 'the ticket background is the same kind of field',
	await page.locator( '[data-media-field][data-id="td-bg"]' ).count() === 1 );

await page.click( '[data-media-field][data-id="td-bg"] [data-role=library]' );
await page.waitForSelector( '.media-grid', { timeout: 20000 } );

check( 'the account\'s files are there to pick from',
	await page.locator( '.media-tile' ).count() >= 1 );

await page.locator( '.media-tile' ).first().click();
await page.waitForSelector( '.ticket-board__art', { timeout: 20000 } );

check( 'and choosing one puts it behind the ticket',
	( await page.getAttribute( '.ticket-board__art', 'src' ) ) === shown );

await page.screenshot( { path: `${ SHOTS }/02-behind-a-ticket.png` } );

console.log( 'The masthead of the website takes one too' );
await page.click( 'nav button[data-view=sites]' );
await page.waitForSelector( '[data-site]', { timeout: 20000 } );
await page.locator( '[data-site]' ).first().click();
await page.waitForSelector( '#site-nav', { timeout: 20000 } );

await page.locator( '#site-nav .nav-item', { hasText: 'Design' } ).first().click();
await page.waitForSelector( '#site-main .media-field', { timeout: 20000 } );

check( 'the logo is a field a file can be dropped on',
	await page.locator( '#site-main .media-drop' ).count() >= 1 );

await page.setInputFiles( '#site-main .media-field input[type=file]', POSTER );
await page.waitForSelector( '#site-main .media-drop__art img', { timeout: 20000 } );

check( 'and dropping one there saves it on the site',
	( await page.getAttribute( '#site-main .media-drop__art img', 'src' ) ) === shown );

await page.screenshot( { path: `${ SHOTS }/03-on-the-masthead.png` } );

/*
 * One refusal is on purpose, and the browser logs every 422 as a console error.
 *
 * Filtered by exactly that status rather than by silencing the check: the spreadsheet dropped on
 * the artwork field above is *meant* to come back refused, and anything else in here is not.
 */
const unexpected = errors.filter( ( line ) => ! /status of 422/.test( line ) );

check( 'nothing else threw along the way', 0 === unexpected.length, unexpected.slice( 0, 2 ).join( ' | ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
