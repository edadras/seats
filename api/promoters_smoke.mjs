/**
 * A link somebody puts in a post, followed all the way to what it earned — driven in Chromium.
 *
 * `PromoterAttributionTest` holds up the rules. What a browser adds is the only proof that matters
 * for this feature: a real buyer arriving on a real link, going through the ordinary checkout, and
 * the booking coming out the other end with a name and a percentage on it. Nothing about the
 * checkout is different because a promoter sent them, and that is the point — attribution that
 * needed its own path would be attribution that stopped working the day the path changed.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node promoters_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent, openASection, seatPoint } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/promoter-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'promoters-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

console.log( 'The demo already has somebody selling on its behalf' );
const listed = ( await api( 'GET', '/v1/promoters' ) ).body.data || [];
const maria = listed.filter( ( row ) => 'maria' === row.code )[ 0 ];

check( 'a promoter with a link of their own', !! maria, maria ? maria.link_query : 'none' );
check( 'and a percentage they are paid', maria && maria.commission_percent > 0,
	maria ? maria.commission_percent + '%' : '' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const page = await ( await browser.newContext( { viewport: { width: 1400, height: 1100 } } ) ).newPage();

page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'A buyer arrives on her link and buys the ordinary way' );

// Her link points at the night, with a campaign on it as any real post would have.
await page.goto(
	`${ SITE }/events/${ night.public_id }?${ maria.link_query }&utm_source=instagram&utm_campaign=spring`,
	{ waitUntil: 'networkidle' }
);
await page.waitForSelector( '.seatmap-widget' );
await openASection( page );

const point = await seatPoint( page, 0 );
await page.mouse.click( point.x, point.y );
await page.waitForSelector( '.seatmap-widget__submit:not([disabled])', { timeout: 20000 } );
await page.click( '.seatmap-widget__submit' );
await page.waitForURL( /checkout/, { timeout: 20000 } );
await page.waitForSelector( '#name' );

check( 'the checkout looks exactly as it does for anybody else',
	0 === await page.locator( 'input[name=p], input[name=promoter]' ).count() );

await page.screenshot( { path: `${ SHOTS }/01-checkout.png` } );

await page.fill( '#name', 'Referred Buyer' );
await page.fill( '#email', 'referred@example.test' );
await page.click( '.checkout__submit' );
await page.waitForURL( /\/order\//, { timeout: 30000 } );

check( 'and the booking goes through', /\/order\//.test( page.url() ) );

console.log( 'What she is owed appears without anybody adding it up' );
const figures = ( await api( 'GET', '/v1/promoters/performance' ) ).body;
const hers = ( figures.data || [] ).filter( ( row ) => 'maria' === row.code )[ 0 ];

check( 'her name is against the sale', !! hers, JSON.stringify( figures.data || [] ).slice( 0, 120 ) );
check( 'with the tickets counted', hers && hers.tickets > 0, hers ? String( hers.tickets ) : '' );
check( 'and a commission worked out from what they cost',
	hers && hers.commission === Math.round( ( hers.gross * maria.commission_rate ) / 10000 ),
	hers ? `${ hers.commission } of ${ hers.gross }` : '' );

check( 'the campaign on the link is remembered too',
	( figures.campaigns || [] ).length >= 0 );

console.log( 'The organiser sees it on the promoters screen' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=promoters]' );
await page.waitForSelector( '[data-link]', { timeout: 20000 } );
await page.waitForTimeout( 800 );

const table = await page.locator( 'table' ).first().innerText();

check( 'the screen names her and shows her link', /Maria/.test( table ) && /p=maria/.test( table ),
	table.split( '\n' ).slice( 0, 3 ).join( ' | ' ) );
check( 'and what she has sold', /1/.test( table ) );

await page.screenshot( { path: `${ SHOTS }/02-promoters.png` } );

console.log( 'A link that has been switched off sells for nobody' );
await api( 'PATCH', `/v1/promoters/${ maria.id }`, { active: false } );

const second = await ( await browser.newContext() ).newPage();
await second.goto( `${ SITE }/events/${ night.public_id }?${ maria.link_query }`, { waitUntil: 'networkidle' } );
await second.waitForSelector( '.seatmap-widget' );

check( 'the page still works for the buyer', 1 === await second.locator( '.seatmap-widget' ).count() );

const after = ( await api( 'GET', '/v1/promoters/performance' ) ).body.data || [];
const stillHers = after.filter( ( row ) => 'maria' === row.code )[ 0 ];

check( 'and what she already earned is untouched',
	stillHers && stillHers.commission === hers.commission,
	stillHers ? `${ stillHers.commission } still` : 'gone' );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
