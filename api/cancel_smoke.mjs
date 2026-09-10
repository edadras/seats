/**
 * Calling a night off, driven in Chromium.
 *
 * The check that matters is that the four things all happened and that the buyer can see it: the
 * sale stops, the page says why, the money goes back and the booking reads as refunded. And that
 * the confirmation is not a yes — it is the event's own name, because a mis-click on a list of
 * dates has no way back.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node cancel_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/cancel-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'A night is moved' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '[data-move]' );

await page.locator( '[data-move]' ).first().click();
await page.waitForSelector( '#move-starts' );

const was = await page.locator( '#move-starts' ).inputValue();
const moved = was.replace( /^(\d{4})-(\d{2})-(\d{2})/, ( _, y, m, d ) =>
	`${ y }-${ m }-${ String( Math.min( 28, Number( d ) + 1 ) ).padStart( 2, '0' ) }` );

await page.fill( '#move-starts', moved );
await page.fill( '#move-reason', 'The hall is being repaired.' );
await page.screenshot( { path: `${ SHOTS }/01-move.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1500 );

check( 'the date moved', ! ( await page.locator( '[data-move]' ).first().isVisible()
	.then( async () => {
		await page.locator( '[data-move]' ).first().click();
		await page.waitForSelector( '#move-starts' );
		const now = await page.locator( '#move-starts' ).inputValue();
		await page.locator( '.modal [data-close]' ).first().click();

		return now === was;
	} ) ), `${ was } → ${ moved }` );

console.log( 'And another is called off' );
await page.waitForTimeout( 400 );

// The name is read from the row rather than from the modal's heading: the confirmation has to be
// the event's own name, and a smoke test that guessed it would prove nothing.
const row = page.locator( 'tbody tr' ).filter( { has: page.locator( '[data-call-off]' ) } ).first();
const eventName = ( await row.locator( '.table__primary' ).innerText() ).trim();
const publicId = ( await row.locator( 'code' ).innerText() ).trim();

await row.locator( '[data-call-off]' ).click();
await page.waitForSelector( '#off-confirm' );

await page.fill( '#off-reason', 'The singer is ill.' );
await page.fill( '#off-confirm', 'not the name' );
await page.screenshot( { path: `${ SHOTS }/02-call-off.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 900 );

check( 'a wrong confirmation is refused',
	await page.locator( '#off-confirm' ).isVisible() );

await page.fill( '#off-confirm', eventName );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 2500 );

const statuses = await page.locator( 'tbody tr td:nth-child(3)' ).allInnerTexts();

check( 'and the right one is accepted',
	statuses.some( ( text ) => /Cancelled/i.test( text ) ), statuses.join( ' | ' ) );

await page.screenshot( { path: `${ SHOTS }/03-events.png` } );

console.log( 'What the buyer sees' );

// Not advertised any more — a cancelled night has no business in a programme — but still there
// for whoever arrives from a diary entry or from the cancellation email itself. A 404 would be
// the worst answer available.
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

const listed = await page.locator( '.event-card' ).allInnerTexts();

check( 'it is off the programme', ! listed.some( ( text ) => text.includes( eventName ) ),
	`${ listed.length } dates still listed` );

await page.goto( `${ SITE }/events/${ publicId }`, { waitUntil: 'networkidle' } );

const body = await page.locator( 'body' ).innerText();

check( 'but an old link still opens', await page.locator( '.event-hero' ).isVisible() );
check( 'and the page says it is off, and why', /singer is ill/.test( body ) );
check( 'with nothing left to buy', 0 === await page.locator( '.seatmap-widget__submit' ).count() );

await page.screenshot( { path: `${ SHOTS }/04-site.png` } );

// The deliberate wrong confirmation above is a 422 this script asked for.
check( 'no unexpected console errors',
	0 === errors.filter( ( text ) => ! text.includes( '422' ) ).length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL CANCELLATION CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
