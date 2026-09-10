/**
 * The pace screen, driven in Chromium.
 *
 * `SalesPaceTest` holds up the arithmetic. What a browser adds is the thing an organiser actually
 * does with it: they look at a night a fortnight out and decide whether to spend money on
 * advertising it. That decision needs three things on one screen — a rate, a chart with the quiet
 * days still in it, and a funnel that says whether the problem is the price or the audience.
 *
 * It also drives the half of the feature that has no screen at all: a look is counted when a buyer
 * opens the page, and the number goes up by exactly one.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node pace_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/pace-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'pace-smoke' );

const api = ( path ) => fetch( BASE + path, {
	headers: { Accept: 'application/json', Authorization: 'Bearer ' + night.token },
} ).then( ( response ) => response.json() );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

console.log( 'A buyer opens the page' );
const buyer = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
buyer.on( 'pageerror', ( e ) => errors.push( e.message ) );

const before = ( await api( `/v1/events/${ night.id }/pace?days=30` ) ).funnel.looked;

await buyer.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await buyer.waitForSelector( '.event-hero' );

const after = ( await api( `/v1/events/${ night.id }/pace?days=30` ) ).funnel.looked;

check( 'the look is counted, and counted once', after === before + 1, `${ before } → ${ after }` );

console.log( 'The organiser reads the night' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1200 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( `[data-pace="${ night.id }"]` );
await page.locator( `[data-pace="${ night.id }"]` ).click();

await page.waitForSelector( '.chart__svg', { timeout: 15000 } );

const tiles = await page.locator( '.stat-grid' ).innerText();

check( 'four tiles lead with the numbers a decision needs',
	4 === await page.locator( '.stat-grid .stat' ).count(), tiles.replace( /\n/g, ' | ' ) );

const bars = await page.locator( '.chart__bar' ).count();

check( 'the chart has a bar for every day in the window, quiet ones included',
	30 === bars, `${ bars } bars` );
check( 'and a line for the people who only looked',
	1 === await page.locator( '.chart__line' ).count() );

// A tooltip on the bar, because a chart nobody can read a number off is decoration. Asked for
// as text content: an SVG <title> is not an HTML element and has no innerText to give.
const tip = await page.locator( '.chart__bar title' ).first().textContent();

check( 'each day says what it was', /\d/.test( tip ), tip );

console.log( 'The funnel' );
const steps = await page.locator( '.funnel__step' ).count();

check( 'four steps, from looking to buying', 4 === steps );

const first = await page.locator( '.funnel__step' ).first().innerText();
const looked = Number( ( await api( `/v1/events/${ night.id }/pace?days=30` ) ).funnel.looked );

check( 'the first step is the looking, and it is not a rate',
	first.includes( '—' ), first.replace( /\n/g, ' | ' ) );
check( 'the screen and the server agree about how many looked',
	first.replace( /\D/g, '' ).length > 0 && looked > 0, `${ looked } looked` );

const sentence = await page.locator( '#pace-sentence' ).innerText();

check( 'one sentence says what it all means', sentence.length > 20, sentence );
// The projection is hedged on purpose: it is arithmetic, and a straight line is exactly wrong
// about a run that sells out in its last three days.
check( 'and it does not promise anything',
	! /will sell out|guarantee/i.test( sentence ), sentence );

await page.screenshot( { path: `${ SHOTS }/01-pace.png`, fullPage: true } );

console.log( 'A shorter window' );
await page.selectOption( '#pace-days', '7' );
await page.waitForFunction( () => 7 === document.querySelectorAll( '.chart__bar' ).length, null,
	{ timeout: 15000 } );

check( 'the chart redraws to the week asked for',
	7 === await page.locator( '.chart__bar' ).count() );

await page.click( '#pace-back' );
await page.waitForSelector( '[data-pace]' );

check( 'and there is a way back to the programme', ( await page.locator( '[data-pace]' ).count() ) > 0 );

console.log( 'Persian' );
await page.evaluate( () => window.localStorage.setItem( 'seatmap.locale', 'fa' ) );
await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( `[data-pace="${ night.id }"]` );
await page.locator( `[data-pace="${ night.id }"]` ).click();
await page.waitForSelector( '.chart__svg', { timeout: 15000 } );

const faSteps = await page.locator( '.funnel' ).innerText();

check( 'the funnel is Persian', /نگاه|خرید/.test( faSteps ), faSteps.split( '\n' )[ 0 ] );
check( 'and the page turns round',
	'rtl' === await page.evaluate( () => document.documentElement.getAttribute( 'dir' ) ) );

await page.screenshot( { path: `${ SHOTS }/02-persian.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
