/**
 * The settlement screen, driven in Chromium.
 *
 * What is checked is what an organiser actually does with it at the end of a run: read what is
 * owed, cut the period two ways, and take both files away — the spreadsheet for the books and the
 * statement for the venue.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node settlement_smoke.mjs
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/settlement-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( {
	viewport: { width: 1500, height: 1000 },
	acceptDownloads: true,
} );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'What is owed' );
await page.click( 'nav button[data-view=settlement]' );
await page.waitForSelector( '#settle-rows table, #settle-rows .empty-state' );

const rows = await page.locator( '#settle-rows tbody tr' ).count();

check( 'every event that sold is a row', rows > 0, `${ rows } rows` );
check( 'and the money is totalled', ( await page.locator( '.stat-strip' ).count() ) > 0 );
check( 'with what is payable carried',
	( await page.locator( '.tile--accent' ).count() ) > 0 );

await page.screenshot( { path: `${ SHOTS }/01-settlement.png` } );

console.log( 'Cutting the period' );
await page.selectOption( '#settle-basis', 'event' );
await page.waitForTimeout( 800 );

check( 'counting by the night still finds the run',
	( await page.locator( '#settle-rows tbody tr' ).count() ) > 0 );

await page.selectOption( '#settle-basis', 'paid' );
await page.waitForTimeout( 800 );

console.log( 'What has already been paid' );

/*
 * Settled the way the console settles it, rather than by writing a row.
 *
 * The point of the block on this screen is that the organiser sees what the platform did, so the
 * check is worth nothing if the two write different things.
 */
const settled = execFileSync( 'php', [ 'artisan', 'tinker', '--execute', `
	$tenant = \\App\\Models\\Tenant::where('slug', 'northgate')->firstOrFail();

	$made = app(\\App\\Domain\\Settlement\\Payouts::class)->settle(
		$tenant,
		now()->subDays(30)->toDateString(),
		now()->toDateString(),
		['reference' => 'SEPA-7'],
	);

	echo count($made);
` ], { encoding: 'utf8' } ).trim();

check( 'the platform settles the period', settled.endsWith( '1' ), settled );

await page.reload( { waitUntil: 'networkidle' } );
await page.click( 'nav button[data-view=settlement]' );
await page.waitForSelector( '#settle-payouts table' );

const paid = await page.locator( '#settle-payouts' ).innerText();

check( 'and the organiser sees it under what they are owed', /SEPA-7/.test( paid ),
	paid.replace( /\s+/g, ' ' ).slice( 0, 100 ) );
check( 'with the days it covers, so they know what is still open',
	/Recorded/i.test( paid ) );

await page.screenshot( { path: `${ SHOTS }/payouts.png`, fullPage: true } );

console.log( 'The files' );
const csv = await Promise.all( [
	page.waitForEvent( 'download' ),
	page.click( '#settle-csv' ),
] ).then( ( [ file ] ) => file );

const text = readFileSync( await csv.path(), 'utf8' );
const lines = text.trim().split( '\n' );

check( 'the spreadsheet has a heading and a row per event', lines.length > 1, `${ lines.length } lines` );
// Opened by accounting software, so amounts are plain decimals rather than the reader's numerals.
check( 'and its amounts are decimals a spreadsheet can add', /,\d+\.\d{2},/.test( text ) );

const pdf = await Promise.all( [
	page.waitForEvent( 'download' ),
	page.click( '#settle-pdf' ),
] ).then( ( [ file ] ) => file );

const bytes = readFileSync( await pdf.path() );

check( 'the statement is a real PDF', '%PDF-' === bytes.subarray( 0, 5 ).toString( 'latin1' ),
	`${ bytes.length } bytes` );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SETTLEMENT CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
