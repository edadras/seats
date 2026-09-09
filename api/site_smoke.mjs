/**
 * The hosted event site, driven in Chromium.
 *
 * Not the picker — that has its own check — but everything around it: a programme somebody can
 * search, a page a search engine can read, and the two files that are written for machines. All of
 * it server-rendered, which is why this drives it with a browser rather than asserting on JSON.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node site_smoke.mjs
 */
import { chromium } from 'playwright';

const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The programme' );
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

const all = await page.locator( '.event-card' ).count();

check( 'the season is listed', all >= 2, `${ all } dates` );
check( 'and it can be searched', await page.locator( '.finder input[name=q]' ).isVisible() );

await page.fill( '.finder input[name=q]', 'late' );
await page.click( '.finder button[type=submit]' );
await page.waitForLoadState( 'networkidle' );

check( 'a search narrows it', ( await page.locator( '.event-card' ).count() ) < all,
	`${ await page.locator( '.event-card' ).count() } of ${ all }` );
check( 'and the result is an address that can be sent to somebody',
	page.url().includes( 'q=late' ), page.url() );

await page.goto( `${ SITE }/?q=zzzzz`, { waitUntil: 'networkidle' } );
check( 'nothing matched is not the same page as nothing is on',
	( await page.locator( 'body' ).innerText() ).includes( 'Nothing matches' ) );

console.log( 'One night' );
await page.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );
await page.locator( '.event-card' ).first().click();
await page.waitForSelector( '.event-hero' );

const jsonld = await page.locator( 'script[type="application/ld+json"]' ).innerText();
const data = JSON.parse( jsonld );

check( 'a search engine is told what this is', 'Event' === data[ '@type' ], data.name );
check( 'when it is on and where', !! data.startDate && !! data.location, data.startDate );
check( 'and what it costs', !! data.offers, JSON.stringify( data.offers || {} ) );

check( 'a calendar file is offered', await page.locator( '.event-hero__calendar' ).isVisible() );

const ics = await page.evaluate( async ( href ) => {
	const response = await fetch( href );

	return { status: response.status, type: response.headers.get( 'content-type' ), body: await response.text() };
}, await page.locator( '.event-hero__calendar' ).getAttribute( 'href' ) );

check( 'and it is a calendar', 200 === ics.status && ics.type.startsWith( 'text/calendar' ), ics.type );
check( 'with this event in it', ics.body.includes( 'BEGIN:VEVENT' ) && ics.body.includes( 'SUMMARY:' ),
	ics.body.split( '\r\n' ).slice( 5, 8 ).join( ' | ' ) );

console.log( 'For the machines' );
const files = await page.evaluate( async ( base ) => {
	const read = async ( path ) => {
		const response = await fetch( base + path );

		return { status: response.status, body: await response.text() };
	};

	return { robots: await read( '/robots.txt' ), sitemap: await read( '/sitemap.xml' ) };
}, SITE );

check( 'robots points at the sitemap', files.robots.body.includes( 'Sitemap:' ),
	files.robots.body.split( '\n' )[ 0 ] );
check( 'and keeps crawlers out of the checkout', files.robots.body.includes( 'Disallow: /checkout' ) );
check( 'the sitemap lists the nights', files.sitemap.body.includes( '/events/evt_' ),
	`${ ( files.sitemap.body.match( /<url>/g ) || [] ).length } urls` );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/site.png' } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SITE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
