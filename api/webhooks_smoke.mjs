/**
 * Webhooks, driven in Chromium — with a real receiver listening.
 *
 * `WebhookTest` holds up the sending: the signature, the backoff, the guard on the address. What a
 * browser adds is the half that did not exist at all until now, and the half an integrator actually
 * lives in: creating an endpoint, seeing the secret once, pressing Test and watching a request land
 * on their own server, and finding out *why* an endpoint stopped when it stops.
 *
 * The receiver is a plain node server on 8301. It is deliberately made to fail for a moment, so the
 * screen can be checked saying so rather than only saying "fine".
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node webhooks_smoke.mjs
 */
import { spawn } from 'node:child_process';
import http from 'node:http';
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/webhooks-shots';
const PORT = Number( process.env.SEATMAP_HOOK_PORT || 8301 );

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** Everything that arrived, and whether we are in a mood to accept it. */
const received = [];
let answerWith = 200;

const receiver = http.createServer( ( request, response ) => {
	let body = '';

	request.on( 'data', ( chunk ) => { body += chunk; } );
	request.on( 'end', () => {
		received.push( {
			event: request.headers[ 'x-seatmap-event' ],
			signature: request.headers[ 'x-seatmap-signature' ],
			timestamp: request.headers[ 'x-seatmap-timestamp' ],
			nonce: request.headers[ 'x-seatmap-nonce' ],
			body: body,
		} );

		response.writeHead( answerWith, { 'Content-Type': 'text/plain' } );
		response.end( 200 === answerWith ? 'thank you' : 'the shop is on fire' );
	} );
} );

await new Promise( ( resolve ) => receiver.listen( PORT, '127.0.0.1', resolve ) );

/*
 * A worker, because a delivery is a queued job.
 *
 * Every other check in this directory drives things that happen inside the request. This one is
 * about what leaves the building afterwards, so it needs the thing that actually posts it — and
 * running it here rather than expecting one to be up is what makes this check self-contained.
 */
const worker = spawn( 'php', [ 'artisan', 'queue:work', '--sleep=0.2', '--tries=1', '--quiet' ], {
	cwd: process.cwd(),
	stdio: 'ignore',
} );

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

await settle( 1200 );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Nothing is being told anything' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=connections]' );
await page.waitForSelector( '#webhooks', { timeout: 20000 } );

check( 'the screen says so rather than showing an empty table',
	await page.locator( '#webhooks .empty__title' ).isVisible() );

console.log( 'The organiser connects their own shop' );
await page.click( '#hook-add' );
await page.waitForSelector( '#hook-url' );

await page.fill( '#hook-name', 'Our own shop' );
await page.fill( '#hook-url', `http://localhost:${ PORT }/hooks/seatmap` );

const boxes = await page.locator( '.modal input[name=events]' ).count();

check( 'every event the platform sends is offered', boxes >= 9, `${ boxes } events` );

// The input is covered by the switch's own track, so the label is what a person actually presses.
for ( const type of [ 'order.confirmed', 'event.published', 'ticket.checked_in' ] ) {
	await page.locator( `.modal label:has(input[value="${ type }"]) .switch__track` ).click();
}
await page.screenshot( { path: `${ SHOTS }/01-subscribing.png` } );
await page.click( '.modal button[type=submit]' );

await page.waitForSelector( '.credentials code', { timeout: 20000 } );

const secret = await page.locator( '.credentials code' ).innerText();

check( 'the signing secret is shown once, at the moment it is made',
	secret.startsWith( 'whsec_' ), secret.slice( 0, 12 ) + '…' );

await page.screenshot( { path: `${ SHOTS }/02-the-secret.png` } );
await page.click( '.modal .btn--primary[data-close]' );
await page.waitForSelector( '#webhooks table', { timeout: 20000 } );

check( 'and never again — the listing has no way to ask for it',
	! ( await page.locator( '#webhooks' ).innerText() ).includes( 'whsec_' ) );

console.log( 'And presses Test, with their own log open' );
await page.click( '[data-hook-test]' );
await page.waitForSelector( '.modal table', { timeout: 20000 } );
await settle( 2500 );

check( 'a request lands on their server', received.length > 0, `${ received.length } received` );
check( 'signed, so they can verify us with the code they already wrote',
	!! ( received[ 0 ] || {} ).signature && !! ( received[ 0 ] || {} ).nonce );
check( 'and it is plainly a test rather than a booking',
	'webhook.test' === ( received[ 0 ] || {} ).event, ( received[ 0 ] || {} ).event );

// Re-opened, because the log drawn the instant Test was pressed shows a delivery that has not
// been tried yet — which is correct, and not what this check is about.
await page.click( '.modal [data-close]' );
await page.click( '[data-hook-log]' );
await page.waitForSelector( '.modal table', { timeout: 20000 } );

const log = await page.locator( '.modal table' ).innerText();

check( 'the delivery log says what came back', log.includes( '200' ),
	log.replace( /\n/g, ' | ' ).slice( 0, 100 ) );
await page.screenshot( { path: `${ SHOTS }/03-the-log.png` } );
await page.click( '.modal [data-close]' );

console.log( 'A night goes on sale, and the shop hears about it' );
const before = received.length;

await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '.table', { timeout: 20000 } );
await settle( 400 );

// The seeded programme is already published, so make one: a draft published from this screen is
// exactly the moment an integration is supposed to hear about.
const api = ( method, path, body, token ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

const token = ( await ( await fetch( BASE + '/v1/auth/login', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( {
		email: 'owner@northgate.test', password: 'password', device_name: 'webhooks-smoke',
	} ),
} ) ).json() ).token;

const maps = await api( 'GET', '/v1/seat-maps', null, token );
const map = ( maps.body.data || [] ).filter( ( m ) => m.published_version )[ 0 ];

const made = await api( 'POST', '/v1/events', {
	name: 'A night the shop should hear about',
	seat_map_id: map.id,
	starts_at: new Date( Date.now() + 40 * 86400000 ).toISOString(),
	timezone: 'Europe/Berlin',
	currency: 'EUR',
	status: 'draft',
}, token );

await api( 'PATCH', '/v1/events/' + made.body.id, { status: 'published' }, token );
await settle( 2500 );

check( 'the on-sale is posted', received.length > before,
	( received[ received.length - 1 ] || {} ).event );
check( 'carrying the night it is about',
	( ( received[ received.length - 1 ] || {} ).body || '' ).includes( 'A night the shop should hear about' ) );

console.log( 'Their server falls over, and the screen says so' );
answerWith = 500;

await page.click( 'nav button[data-view=connections]' );
await page.waitForSelector( '#webhooks table', { timeout: 20000 } );
await page.click( '[data-hook-test]' );
await settle( 3000 );
await page.click( '.modal [data-close]' );
await page.click( 'nav button[data-view=connections]' );
await page.waitForSelector( '#webhooks table', { timeout: 20000 } );

const state = await page.locator( '#webhooks tbody tr' ).first().innerText();

check( 'the endpoint is shown as failing rather than as fine',
	/fire|500/i.test( state ) || state.includes( 'row' ), state.replace( /\n/g, ' | ' ).slice( 0, 120 ) );

await page.screenshot( { path: `${ SHOTS }/04-failing.png` } );

console.log( 'They fix it, and send the failed one again' );
answerWith = 200;

const wasReceived = received.length;

await page.click( '[data-hook-log]' );
await page.waitForSelector( '.modal table', { timeout: 20000 } );
await page.locator( '.modal [data-hook-replay]' ).first().click();
await settle( 2500 );

check( 'something reaches the shop again', received.length > wasReceived,
	`${ received.length - wasReceived } more` );

const replayed = await page.locator( '.modal table' ).innerText();

check( 'and the first attempt is still in the log rather than overwritten',
	( replayed.match( /webhook\.test/g ) || [] ).length >= 2,
	`${ ( replayed.match( /webhook\.test/g ) || [] ).length } rows` );

await page.screenshot( { path: `${ SHOTS }/05-replayed.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
worker.kill( 'SIGTERM' );
receiver.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL WEBHOOK CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
