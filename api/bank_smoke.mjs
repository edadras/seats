/**
 * The books against the bank, driven in Chromium.
 *
 * `GatewayReconciliationTest` holds up the four sentences this feature exists to say. What a
 * browser adds is the half that decides whether anybody ever says them: a finance officer exports a
 * file from their processor — whatever shape it comes in — and gets an answer without editing it
 * in a spreadsheet first. If the column mapper does not work, the feature does not exist.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node bank_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/bank-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const night = await seatedEvent( BASE, 'bank-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

console.log( 'Somebody buys a ticket, which is the half of the comparison we own' );
const guest = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();

guest.on( 'pageerror', ( e ) => errors.push( e.message ) );

await guest.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await guest.waitForSelector( '.seatmap-widget__stage', { timeout: 15000 } );
await openASection( guest );

const seat = await seatPoint( guest, 0 );

await guest.mouse.click( seat.x, seat.y );
await guest.waitForTimeout( 300 );
await guest.locator( '.seatmap-widget__submit' ).click();
await guest.waitForURL( /\/checkout/, { timeout: 15000 } );
await guest.fill( '#name', 'Amina Farsi' );
await guest.fill( '#email', 'amina@example.test' );
await guest.locator( '.checkout__submit' ).click();
await guest.waitForURL( /\/order\//, { timeout: 20000 } );

const listed = ( await api( 'GET', '/v1/orders?per_page=5' ) ).body.data[ 0 ];

check( 'the sale is on the books', !! listed, listed && listed.reference );

// The handle is on the one order somebody has open, never on a list of forty.
const order = ( await api( 'GET', '/v1/orders/' + listed.id ) ).body;
const reference = order.payment_reference || '';

check( 'and carries the handle its gateway knows it by', '' !== reference, reference );

// Whichever gateway the demo actually took the money through. A statement filed against the wrong
// one would reconcile against nothing, which is correct behaviour and a useless check.
const gateway = order.gateway || 'offline';

console.log( 'The finance officer takes in the statement' );
const desk = await ( await browser.newContext( { viewport: { width: 1500, height: 1100 } } ) ).newPage();

desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );
await desk.click( 'nav button[data-view=settlement]' );
await desk.waitForSelector( '#bank-import', { timeout: 20000 } );

check( 'the settlement screen has a section for the bank',
	await desk.locator( '#bank' ).isVisible() );
check( 'and says nothing has been taken in yet',
	/No statements yet/.test( await desk.locator( '#bank' ).innerText() ) );

await desk.screenshot( { path: `${ SHOTS }/01-no-statements.png`, fullPage: true } );

const total = order.total_amount;
const major = ( minor ) => ( minor / 100 ).toFixed( 2 );

/*
 * A file in a shape nobody agreed on, which is the point.
 *
 * Column names taken from a real processor's export — `Created (UTC)`, `Reporting Category`,
 * `Source` — rather than from anything this platform would have chosen. The mapper has to cope
 * with a heading it has never seen, and with a description containing a comma.
 */
const csv = [
	'Source,Reporting Category,Amount,Fee,Created (UTC),Description',
	`${ reference },charge,${ major( total ) },0.75,2026-09-08,"Ticket, opening night"`,
	'ch_from_nowhere,charge,9.00,0.30,2026-09-08,A sale we have never heard of',
].join( '\n' );

await desk.click( '#bank-import' );
await desk.waitForSelector( '#bk-gateway' );
await desk.fill( '#bk-gateway', gateway );
await desk.fill( '#bk-reference', 'po_smoke_0001' );
await desk.fill( '#bk-currency', 'EUR' );
await desk.fill( '#bk-paid', '2026-09-09' );
await desk.fill( '#bk-gross', major( total + 900 ) );
await desk.fill( '#bk-fees', '1.05' );
// Deliberately not gross less fees: a statement that fails its own arithmetic is the commonest
// import mistake there is, and the screen has to say so rather than quietly reconciling anyway.
await desk.fill( '#bk-net', major( total + 900 - 105 - 50 ) );

await desk.locator( '#bk-file' ).setInputFiles( {
	name: 'payout.csv',
	mimeType: 'text/csv',
	buffer: Buffer.from( csv ),
} );

await desk.screenshot( { path: `${ SHOTS }/02-the-statement.png` } );
await desk.locator( '.modal button[type=submit]' ).click();

console.log( 'And says which column is which' );
await desk.waitForSelector( '#bk-col-reference', { timeout: 20000 } );

// Guessed from headings this platform has never seen, which is what makes the step short.
check( 'the reference column is guessed',
	'Source' === await desk.locator( '#bk-col-reference' ).inputValue(),
	await desk.locator( '#bk-col-reference' ).inputValue() );
check( 'and so is the amount', 'Amount' === await desk.locator( '#bk-col-amount' ).inputValue() );
check( 'and the date, under a name nobody would have chosen',
	'Created (UTC)' === await desk.locator( '#bk-col-occurred_on' ).inputValue(),
	await desk.locator( '#bk-col-occurred_on' ).inputValue() );

await desk.screenshot( { path: `${ SHOTS }/03-the-columns.png` } );
await desk.locator( '.modal button[type=submit]' ).click();

console.log( 'The answer' );
await desk.waitForSelector( '.statement-lines', { timeout: 20000 } );

const answer = await desk.locator( '.modal__body' ).innerText();

check( 'the sale we recorded and they paid for is accounted for',
	/1 accounted for/.test( answer ), ( answer.match( /\d+ accounted for/ ) || [ '' ] )[ 0 ] );
check( 'the line we cannot place is named',
	/cannot place/.test( answer ) && /ch_from_nowhere/.test( answer ) );
check( 'and the statement is told it fails its own arithmetic',
	/Gross less fees comes to/.test( answer ),
	( answer.match( /Gross less fees[^\n]*/ ) || [ 'not said' ] )[ 0 ] );

await desk.screenshot( { path: `${ SHOTS }/04-the-answer.png`, fullPage: true } );
await desk.locator( '.modal button[data-close]' ).last().click();
await settle( 900 );

const summary = await desk.locator( '#bank' ).innerText();

check( 'and the list says which statement to open', /unexplained/.test( summary ),
	( summary.match( /\d+ unexplained/ ) || [ 'not said' ] )[ 0 ] );

console.log( 'The same statement twice is one statement' );
const again = await api( 'POST', '/v1/settlement/gateway-payouts', {
	gateway: gateway,
	reference: 'po_smoke_0001',
	currency: 'EUR',
	paid_on: '2026-09-09',
	gross: total + 900,
	fees: 105,
	net: total + 900 - 105,
} );

check( 'a second upload is refused rather than doubling the income',
	409 === again.status && 'payout_already_recorded' === again.body.error.code,
	String( again.status ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL RECONCILIATION CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
