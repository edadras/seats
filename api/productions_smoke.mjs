/**
 * The same show, in another town — driven in Chromium.
 *
 * `ProductionTest` holds up the rules. What a browser adds is the afternoon somebody spends putting
 * a tour together: a second hall, a date added to it in four fields, a run that adds itself up
 * across two cities, and — the part an audience sees — the other date appearing on the public page
 * with the name of the town beside it, because "Sun 4 Oct" tells nobody in Hamburg anything.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node productions_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/productions-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'productions-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json().catch( () => ( {} ) ) } ) );

console.log( 'A second hall, in another town' );

const venue = ( await api( 'POST', '/v1/venues', {
	name: 'Hamburg Playhouse',
	city: 'Hamburg',
	country: 'DE',
	timezone: 'Europe/Berlin',
} ) ).body;

check( 'the other building exists', !! venue.id, venue.city || JSON.stringify( venue ).slice( 0, 120 ) );

// The same shape of room: the seeded chart, republished in the new building, so the prices have
// categories to land on. An organiser's own tooling would do exactly this.
const charts = ( await api( 'GET', '/v1/seat-maps?per_page=50' ) ).body.data || [];
const home = charts.filter( ( chart ) => chart.published_version )[ 0 ];
const geometry = ( await api( 'GET', `/v1/seat-maps/${ home.id }` ) ).body.published_version.geometry;

const map = ( await api( 'POST', '/v1/seat-maps', { venue_id: venue.id, name: 'Hamburg stalls' } ) ).body;
const version = ( await api( 'POST', `/v1/seat-maps/${ map.id }/versions`, { geometry } ) ).body;

await api( 'POST', `/v1/seat-maps/${ map.id }/publish`, { version_id: version.id } );

const published = ( await api( 'GET', `/v1/seat-maps/${ map.id }` ) ).body;

check( 'and has a chart anybody could sell in', !! published.published_version,
	published.published_version ? published.published_version.seat_count + ' seats' : 'not published' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1050 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'A production' );
await page.click( 'nav button[data-view=productions]' );
await page.waitForSelector( '#production-add', { timeout: 20000 } );
await page.click( '#production-add' );
await page.waitForSelector( '#run-name' );
await page.fill( '#run-name', 'The Winter Tour' );
await page.fill( '#run-category', 'Theatre' );
await page.fill( '#run-description', 'One show, two towns, the same evening twice.' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );
await page.waitForTimeout( 900 );

check( 'it appears in the list', ( await page.locator( '#main' ).innerText() ).includes( 'The Winter Tour' ) );

console.log( 'And a date for it, in the other town' );
await page.locator( '[data-production]' ).first().click();
await page.waitForSelector( '#production-date', { timeout: 20000 } );
await page.click( '#production-date' );
await page.waitForSelector( '#date-venue' );
await page.selectOption( '#date-venue', { label: 'Hamburg Playhouse — Hamburg' } );
await page.waitForTimeout( 300 );

const chartOptions = await page.locator( '#date-map option' ).allInnerTexts();

// The chart list follows the building: offering every chart in the account would be offering to
// sell Hamburg's stalls in Berlin.
check( 'only this building’s charts are offered',
	1 === chartOptions.length && /Hamburg/.test( chartOptions[ 0 ] ), chartOptions.join( ', ' ) );

// The night this one is a copy of: the show already playing at home.
const copyFrom = ( await page.locator( '#date-from option' ).allInnerTexts() )
	.filter( ( label ) => /Opening night/.test( label ) )[ 0 ];

await page.selectOption( '#date-from', { label: copyFrom } );
await page.fill( '#date-when', '2027-02-18T19:30' );
await page.screenshot( { path: `${ SHOTS }/01-add-date.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached' } );
await page.waitForTimeout( 1500 );

const run = await page.locator( '#main' ).innerText();

check( 'the run now plays in two towns', /Hamburg/.test( run ) && /Berlin|Northgate/.test( run ),
	run.split( '\n' ).filter( ( line ) => /Hamburg/.test( line ) )[ 0 ] || '' );
// Never on sale by being copied: going on sale is a decision about a town.
check( 'and the new date starts as a draft', /Draft/i.test( run ) );

await page.screenshot( { path: `${ SHOTS }/02-production.png` } );

const detail = ( await api( 'GET', '/v1/productions' ) ).body.data
	.filter( ( row ) => 'The Winter Tour' === row.name )[ 0 ];
const dates = ( await api( 'GET', `/v1/productions/${ detail.id }` ) ).body;
const hamburg = dates.dates.filter( ( date ) => 'Hamburg' === date.city )[ 0 ];

check( 'two dates, two cities', 2 === dates.totals.dates && 2 === dates.cities,
	`${ dates.totals.dates } dates in ${ dates.cities } cities` );
check( 'the new hall has seats to sell', hamburg && hamburg.capacity > 0,
	hamburg ? hamburg.capacity + ' places' : 'missing' );

// The prices travelled: the chart is the same shape, so every category has a price to land on.
const prices = ( await api( 'GET', `/v1/events/${ hamburg.id }` ) ).body;

check( 'the prices came with the show',
	( prices.price_zones || [] ).length > 0,
	( prices.price_zones || [] ).map( ( zone ) => zone.key ).join( ', ' ) || 'none' );

console.log( 'What the audience sees' );
// On sale, because the other-dates list on a public page is what somebody can actually buy.
await api( 'PATCH', `/v1/events/${ hamburg.id }`, { status: 'published' } );

// Fetched by the browser rather than by Node: the site answers on a hostname the browser
// resolves and a bare DNS lookup does not.
await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

const html = await page.content();

check( 'the tour’s other date is on the public page', html.includes( 'Hamburg' ),
	( html.match( /.{0,60}Hamburg.{0,40}/ ) || [ 'not found' ] )[ 0 ].replace( /\s+/g, ' ' ) );

await page.screenshot( { path: `${ SHOTS }/03-public.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
