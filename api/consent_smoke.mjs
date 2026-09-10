/**
 * Who agreed to be written to — driven in Chromium, from both sides.
 *
 * `MarketingConsentTest` holds up the rule. What a browser adds is the shape of the promise as the
 * two people involved meet it: a buyer who is asked once, plainly, in a box that starts empty; and
 * an organiser who is told, before they press send, how many of their buyers they may not write to.
 *
 * The last part is the one that matters most and is the easiest to get wrong: leaving a mailing
 * list has to be easier than reporting it, so the link in the footer opens a page that needs no
 * password at eleven o'clock at night.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node consent_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/consent-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'consent-smoke' );

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

/*
 * How many people this organiser may already write to.
 *
 * Not nought: the seeded demo has buyers who ticked the box at their own checkout, because an
 * organiser whose announcement screen says "nobody at all" has a demo that shows nothing. So every
 * count below is measured against this rather than against an assumption.
 */
const baseline = ( await api( 'GET', '/v1/messaging/announcements/audience?channels[]=email' ) )
	.body.people;

console.log( 'A buyer is asked once, in a box that starts empty' );
const buyer = await ( await browser.newContext( { viewport: { width: 1280, height: 1050 } } ) ).newPage();
buyer.on( 'pageerror', ( e ) => errors.push( e.message ) );

/** Choose a seat and go through to the checkout page. */
const toCheckout = async ( page ) => {
	await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.seatmap-widget' );
	await openASection( page );
	const point = await seatPoint( page, 0 );
	await page.mouse.click( point.x, point.y );
	await page.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
	await page.click( '.seatmap-widget__submit' );
	await page.waitForURL( /checkout/, { timeout: 20000 } );
	await page.waitForSelector( '#name' );
};

await toCheckout( buyer );

const box = buyer.locator( '.consent input[name=news]' );

check( 'the checkout asks about being written to', 1 === await box.count() );
// A tick box that arrives ticked is not consent, it is a default somebody failed to notice.
check( 'and the box starts empty', ! await box.isChecked() );
check( 'with what it means written out beside it, not "keep me updated"',
	( await buyer.locator( '.consent' ).innerText() ).length > 20,
	await buyer.locator( '.consent' ).innerText() );

await buyer.screenshot( { path: `${ SHOTS }/01-checkout.png` } );

await buyer.fill( '#name', 'Quiet Buyer' );
await buyer.fill( '#email', 'quiet@example.test' );
await buyer.click( '.checkout__submit' );
await buyer.waitForURL( /\/order\//, { timeout: 30000 } );

check( 'a buyer who leaves it alone still buys their ticket', /\/order\//.test( buyer.url() ) );

console.log( 'And another who ticks it' );
const keen = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
keen.on( 'pageerror', ( e ) => errors.push( e.message ) );

await toCheckout( keen );
await keen.fill( '#name', 'Keen Buyer' );
await keen.fill( '#email', 'keen@example.test' );
await keen.locator( '.consent input[name=news]' ).check();
await keen.click( '.checkout__submit' );
await keen.waitForURL( /\/order\//, { timeout: 30000 } );

console.log( 'The organiser is told what they may not do' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=messaging]' );
await page.waitForSelector( '#announce-new', { timeout: 20000 } );
await page.click( '#announce-new' );
await page.waitForSelector( '#a-body' );
await page.waitForTimeout( 1200 );

const news = await page.locator( '#a-reach' ).innerText();
const kind = await page.locator( '#a-kind' ).innerText();

check( 'writing to everybody is named as news', /agreed|consent|only people/i.test( kind ), kind );
check( 'and the count says how many have not been asked', /not been asked/i.test( news ), news );

await page.screenshot( { path: `${ SHOTS }/02-composer.png` } );

// The same composer, told to write to one night's buyers instead of to everybody.
await page.selectOption( '#a-event', night.id );
await page.waitForTimeout( 1200 );

const service = await page.locator( '#a-kind' ).innerText();

check( 'writing to one event’s buyers is named as service instead',
	service !== kind && /booking they hold|everybody who bought/i.test( service ), service );

console.log( 'Sending it' );
await page.selectOption( '#a-event', '' );
await page.waitForTimeout( 900 );
await page.fill( '#a-subject', 'Our new season' );
await page.fill( '#a-body', 'Hello {buyer}, the new season opens in March.' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal .btn--danger' );
await page.locator( '.modal .btn--danger' ).click();
await page.waitForTimeout( 2500 );

const sent = ( await api( 'GET', '/v1/messaging/announcements' ) ).body.data[ 0 ];

// One more than could be written to a moment ago, and that one is the buyer who ticked the box.
// The buyer who left it alone is not in it, and neither is anybody who was never asked.
check( 'it reaches the people who agreed and nobody else', baseline + 1 === sent.total,
	`${ sent.total } reached, ${ baseline } before this buyer agreed` );

console.log( 'And leaving is one press, with no password' );
const log = ( await api( 'GET', '/v1/messaging/log' ) ).body.data || [];
// This buyer's own copy, found by the address in its unsubscribe link: every recipient gets a link
// of their own, and following somebody else's would prove nothing about this one.
const delivery = log.filter( ( row ) => 'announcement' === row.kind
	&& ( row.preview || '' ).includes( encodeURIComponent( 'keen@example.test' ) ) )[ 0 ]
	|| log.filter( ( row ) => 'announcement' === row.kind )[ 0 ];

check( 'the message carries a way out of it', /preferences\//.test( delivery.preview || '' ),
	( delivery.preview || '' ).slice( -80 ) );

const link = ( delivery.preview || '' ).match( /https?:\/\/\S+\/preferences\/\S+/ );

check( 'which is a real link', !! link, link ? link[ 0 ] : 'none' );

const leaving = await ( await browser.newContext() ).newPage();
leaving.on( 'pageerror', ( e ) => errors.push( e.message ) );

// The link is written with the site's own hostname; the browser reaches it on the test port.
await leaving.goto( String( link[ 0 ] ).replace( /^https?:\/\/[^/]+/, SITE ), { waitUntil: 'networkidle' } );

check( 'the page opens with no sign-in at all',
	( await leaving.locator( 'body' ).innerText() ).includes( 'keen@example.test' ) );

await leaving.locator( 'input[name=news]' ).uncheck();
await leaving.click( 'button[type=submit]' );
await leaving.waitForLoadState( 'networkidle' );

check( 'and saying stop is one press', ( await leaving.locator( '.notice' ).count() ) > 0,
	await leaving.locator( 'body' ).innerText().then( ( t ) => t.split( '\n' ).slice( 1, 4 ).join( ' | ' ) ) );

await leaving.screenshot( { path: `${ SHOTS }/03-preferences.png` } );

const after = await api( 'GET', '/v1/messaging/announcements/audience?channels[]=email' );

// Back to where it started: their yes is gone, and nobody else's answer was touched by it.
check( 'after which they may not be written to again', baseline === after.body.people,
	`${ after.body.people } left, ${ baseline } before they agreed` );

console.log( 'A wrong signature says nothing' );
const guess = await ( await browser.newContext() ).newPage();

await guess.goto( `${ SITE }/preferences/${ encodeURIComponent( 'keen@example.test' ) }/nonsense`,
	{ waitUntil: 'networkidle' } ).catch( () => {} );

check( 'because "wrong token" would confirm the address is known here',
	! ( await guess.locator( 'body' ).innerText() ).includes( 'keen@example.test' ) );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
