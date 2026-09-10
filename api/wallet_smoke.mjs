/**
 * The ticket, into a phone's wallet — driven in Chromium.
 *
 * The thing worth driving here is the rule the whole feature turns on: a button is offered only
 * where pressing it will actually produce a pass. So the screen is opened with nothing configured
 * and says so, a real certificate is pasted in, and the "sign a test pass" button is pressed —
 * which is the only honest way to tell a certificate that can sign from one that cannot.
 *
 * The certificate is generated here and thrown away with the run. It proves the packaging and the
 * signing, not that Apple would accept it: only Apple's own certificate can do that.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node wallet_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/wallet-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/** A throwaway self-signed certificate and its key, in PEM. */
const workshop = mkdtempSync( join( tmpdir(), 'wallet-' ) );

execFileSync( 'openssl', [
	'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
	'-subj', '/C=GB/O=Northgate Theatre/CN=Pass Type ID: pass.test.seatmap',
	'-keyout', join( workshop, 'key.pem' ),
	'-out', join( workshop, 'cert.pem' ),
], { stdio: 'ignore' } );

const certificate = readFileSync( join( workshop, 'cert.pem' ), 'utf8' );
const key = readFileSync( join( workshop, 'key.pem' ), 'utf8' );

/** What the platform would offer a buyer right now, asked of the domain rather than the screen. */
const offered = () => execFileSync( 'php', [ 'artisan', 'tinker', '--execute', `
	$tenant = \\App\\Models\\Tenant::first();
	app(\\App\\Support\\Tenancy\\TenantContext::class)->runAs($tenant, function () {
		echo json_encode(app(\\App\\Domain\\Wallet\\Wallets::class)->offered());
	});
` ], { encoding: 'utf8' } ).trim();

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Nothing is configured, and the screen says so' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=wallet]' );
await page.waitForSelector( '#w-apple-cert' );

check( 'both wallets are asked for separately',
	2 === await page.locator( '.cards .card' ).count() );
check( 'and neither claims to be ready',
	0 === await page.locator( '.card__head .badge--ok' ).count() );
check( 'the certificate box is empty rather than full of asterisks',
	'' === await page.locator( '#w-apple-cert' ).inputValue() );
check( 'and no button is offered to a buyer',
	'{"apple":false,"google":false}' === offered(), offered() );

await page.screenshot( { path: `${ SHOTS }/01-empty.png` } );

console.log( 'The organiser pastes in their own credentials' );
await page.check( '#w-apple-on' );
await page.fill( '#w-apple-pass', 'pass.test.seatmap' );
await page.fill( '#w-apple-team', 'ABCDE12345' );
await page.fill( '#w-apple-cert', certificate );
await page.fill( '#w-apple-key', key );
await page.fill( '#w-apple-wwdr', certificate );
await page.fill( '#w-logo', 'Northgate Theatre' );
await page.click( '#w-save' );
await page.waitForTimeout( 1500 );

check( 'the card now reads as able to sign',
	1 === await page.locator( '.card__head .badge--ok' ).count() );
check( 'the secret is stored and not shown back',
	'' === await page.locator( '#w-apple-cert' ).inputValue() );
check( 'and now the button would be offered',
	'{"apple":true,"google":false}' === offered(), offered() );

await page.screenshot( { path: `${ SHOTS }/02-configured.png` } );

console.log( 'And the credentials are tried rather than trusted' );
await page.click( '#w-test-apple' );
await page.waitForTimeout( 2500 );

const toast = await page.locator( '.toast' ).innerText().catch( () => '' );

check( 'a real pass is signed with them', /signed/i.test( toast ), toast );

await page.screenshot( { path: `${ SHOTS }/03-tested.png` } );

console.log( 'A wrong key is named as a wrong key' );
await page.fill( '#w-apple-key', certificate );
await page.click( '#w-save' );
await page.waitForTimeout( 1500 );
await page.click( '#w-test-apple' );
await page.waitForTimeout( 2500 );

const complaint = await page.locator( '.toast' ).innerText().catch( () => '' );

// The difference between a wrong password and a wrong file is the whole of the debugging.
check( 'and the reason is the real one', /private key could not be read/i.test( complaint ), complaint );

await page.screenshot( { path: `${ SHOTS }/04-refused.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

rmSync( workshop, { recursive: true, force: true } );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL WALLET CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
