/**
 * The customer directory, driven in Chromium.
 *
 * The seeder sells to six people at each of two events, so the demo has customers who bought more
 * than once — which is the whole reason this screen exists rather than a second column on the
 * orders list. What is checked is that one person is one row across their orders, that opening
 * one shows what they actually bought, and that the CSV says what the screen said.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node customers_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 }, acceptDownloads: true } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The list' );
await page.click( 'nav button[data-view=customers]' );
await page.waitForSelector( '#customer-results table' );

const rows = await page.locator( '#customer-results tbody tr' ).count();
check( 'the buyers are listed', rows >= 4, `${ rows } people` );

const first = await page.locator( '#customer-results tbody tr' ).first().innerText();
check( 'each row carries an address', /@/.test( first ), first.replace( /\n/g, ' | ' ) );
check( 'and money in the event\'s currency', /€/.test( first ), first.replace( /\n/g, ' | ' ) );

console.log( 'Searching' );
await page.fill( '#customer-search', 'dana@' );
await page.waitForTimeout( 700 );
check( 'search narrows to one person',
	1 === await page.locator( '#customer-results tbody tr' ).count() );

console.log( 'One person' );
await page.click( '#customer-results [data-person]' );
await page.waitForSelector( '.order-card' );

const profile = await page.locator( '.page-body' ).innerText();
check( 'their orders are shown', ( await page.locator( '.order-card' ).count() ) >= 1 );
check( 'with the seats on them', ( await page.locator( '.order-card__lines li' ).count() ) >= 1,
	profile.replace( /\n/g, ' | ' ).slice( 0, 200 ) );
check( 'and what they have spent', /€/.test( profile ) );

await page.screenshot( { path: process.env.SEATMAP_SHOT || '/tmp/customer.png' } );

console.log( 'The CSV' );
await page.click( '#customer-back' );
await page.waitForSelector( '#customers-export' );

const download = await Promise.all( [
	page.waitForEvent( 'download' ),
	page.click( '#customers-export' ),
] ).then( ( [ d ] ) => d );

const { readFileSync } = await import( 'node:fs' );
const csv = readFileSync( await download.path(), 'utf8' );

check( 'the file has a person in it', /@/.test( csv ), csv.split( '\n' )[ 1 ] );
check( 'and its headings are translated, with a BOM before them',
	csv.startsWith( '﻿' ), JSON.stringify( csv.slice( 0, 40 ) ) );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL CUSTOMER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
