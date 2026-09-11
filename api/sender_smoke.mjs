/**
 * Who a buyer's confirmation comes from, driven in Chromium.
 *
 * `SenderIdentityTest` holds up the rules — the name at once, the address once proved, the From
 * address only where the operator authorised the domain. What a browser adds is the part that made
 * this worth building: an organiser sitting in front of the messaging screen wondering why their
 * customers' email says somebody else's name at the top of it, and being able to change it.
 *
 * The six-digit code is read out of the log, which is where this installation's mailer puts an
 * email — the same place a developer would look for it.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node sender_smoke.mjs
 */
import fs from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/sender-shots';
const LOG = process.env.SEATMAP_LOG || 'storage/logs/laravel.log';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/*
 * Everything written to the log since a moment, which is where a `log` mailer puts an email.
 *
 * By byte offset rather than by reading the tail: this file is hundreds of megabytes on a machine
 * that has been developed on, and "the last 400KB" is the same 400KB before and after a send.
 */
const mark = () => fs.statSync( LOG ).size;

const since = ( offset ) => {
	const size = fs.statSync( LOG ).size;

	if ( size <= offset ) {
		return '';
	}

	const handle = fs.openSync( LOG, 'r' );
	const buffer = Buffer.alloc( size - offset );

	fs.readSync( handle, buffer, 0, buffer.length, offset );
	fs.closeSync( handle );

	return buffer.toString( 'utf8' );
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Every venue’s email used to come from the platform' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( '#sender', { timeout: 20000 } );

const opening = await page.locator( '#sender' ).innerText();

check( 'the screen says plainly what a buyer will see', /Buyers see:/.test( opening ),
	opening.split( '\n' ).filter( ( line ) => line.startsWith( 'Buyers see' ) )[ 0 ] );
check( 'and it is the account’s own name rather than the platform’s brand',
	opening.includes( 'Northgate Theatre' ) );

await page.screenshot( { path: `${ SHOTS }/01-before.png` } );

console.log( 'The venue puts its own name and address on it' );
const before = mark();

await page.fill( '#sender-name', 'Northgate Box Office' );
await page.fill( '#sender-email', 'boxoffice@northgate.test' );
await page.click( '#sender-save' );
await page.waitForSelector( '#sender-code', { timeout: 20000 } );
await settle( 600 );

const asked = await page.locator( '#sender' ).innerText();

check( 'the name takes effect at once', asked.includes( 'Northgate Box Office' ) );
check( 'and the address does not, until a code sent to it comes back',
	asked.includes( 'boxoffice@northgate.test' ) && /code/i.test( asked ) );

await page.screenshot( { path: `${ SHOTS }/02-awaiting.png` } );

const letter = since( before );
const codeMatch = letter.match( /\b(\d{6})\b/ );

check( 'a six-digit code was sent to that address',
	!! codeMatch && letter.includes( 'boxoffice@northgate.test' ),
	codeMatch ? codeMatch[ 1 ] : 'none found' );

console.log( 'And types the code' );
await page.fill( '#sender-code', codeMatch ? codeMatch[ 1 ] : '000000' );
await page.click( '#sender-verify' );
await settle( 1200 );

const done = await page.locator( '#sender' ).innerText();

check( 'replies now reach the venue', /Replies reach you/i.test( done ),
	done.split( '\n' )[ 1 ] );
check( 'and the From address is still ours, because nobody set up the DNS for theirs',
	done.includes( 'Buyers see: Northgate Box Office' ) && ! /Your own address/.test( done ) );

await page.screenshot( { path: `${ SHOTS }/03-verified.png` } );

console.log( 'A real message leaves with all of it on' );
const beforeTest = mark();

await page.fill( '#msg-test-to', 'sam@example.test' );
await page.click( '#msg-test' );
await settle( 1500 );

const sent = since( beforeTest );

check( 'the From line carries the venue’s name', /Northgate Box Office/.test( sent ) );
check( 'and a reply goes to the venue', /Reply-To:.*boxoffice@northgate\.test/s.test( sent ),
	( sent.match( /Reply-To:[^\n]*/ ) || [ 'no Reply-To' ] )[ 0 ] );

console.log( 'And can put it back' );
await page.click( '#sender-clear' );
await settle( 1200 );

const cleared = await page.locator( '#sender' ).innerText();

check( 'back to the platform’s own', ! cleared.includes( 'boxoffice@northgate.test' ) );
check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SENDER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
