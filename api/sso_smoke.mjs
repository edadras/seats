/**
 * Signing in with your own organisation, and scoping a key — driven in Chromium.
 *
 * What a browser is needed for here is the half that no unit test reaches: that an organiser can
 * actually set the thing up, that a wrong address is refused while they are still looking at the
 * screen, and that choosing what a key is for happens before the key exists rather than in a
 * settings page nobody finds.
 *
 * The round trip to a provider is not driven here — it wants a real OpenID Connect issuer on
 * https, and the tests walk the whole of it against a fake one. What is driven is everything on
 * this side of the redirect, including the sentence somebody gets when they come back refused.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node sso_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/sso-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1100 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => {
	// The two refusals below are provoked on purpose, and a browser logs each as a console error.
	if ( 'error' === m.type() && ! /422|Failed to load resource/.test( m.text() ) ) {
		errors.push( m.text() );
	}
} );

console.log( 'Somebody whose venue signs in elsewhere' );
await page.goto( BASE, { waitUntil: 'networkidle' } );

check( 'the sign-in screen offers it', await page.locator( '#go-sso' ).isVisible() );

await page.click( '#go-sso' );
await page.waitForSelector( '#sso-slug' );
await page.fill( '#sso-slug', 'northgate' );
await page.screenshot( { path: `${ SHOTS }/01-ask.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForURL( /\?sso=/, { timeout: 15000 } );

// The seeded account has no provider, and an account nobody has heard of answers the same way:
// telling them apart would answer a question about other people's organisations.
check( 'an account with no provider is sent back with a sentence, not a stack trace',
	/sso=unavailable/.test( page.url() ), page.url() );
await page.waitForSelector( '#login-error' );
check( 'and the sentence is on the screen',
	await page.locator( '#login-error' ).isVisible(),
	( await page.locator( '#login-error' ).innerText() ).replace( /\s+/g, ' ' ) );

console.log( 'The organiser sets one up' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

await page.click( 'nav button[data-view=security]' );
await page.waitForSelector( '#sso-issuer' );

check( 'the two addresses to hand out are composed rather than described',
	/\/sso\/return$/.test( await page.locator( '#sso-redirect' ).inputValue() ) &&
		/\/sso\/northgate$/.test( await page.locator( '#sso-link' ).inputValue() ),
	await page.locator( '#sso-link' ).inputValue() );

await page.fill( '#sso-label', 'Northgate staff' );
await page.fill( '#sso-issuer', 'https://nothing.invalid' );
await page.fill( '#sso-client', 'seatmap' );
await page.fill( '#sso-secret', 'shhh' );
await page.screenshot( { path: `${ SHOTS }/02-setup.png`, fullPage: true } );
await page.click( '#sso-save' );
await page.waitForSelector( '.toast', { timeout: 20000 } );

// A typo found while somebody is looking at the screen, rather than at half past seven on a
// Friday by a member of staff who cannot fix it.
check( 'an address that is not a provider is refused there and then',
	/did not answer|not an OpenID/i.test( await page.locator( '.toast' ).first().innerText() ),
	( await page.locator( '.toast' ).first().innerText() ).replace( /\s+/g, ' ' ) );

console.log( 'And a key is scoped before it exists' );
await page.click( 'nav button[data-view=connections]' );
await page.waitForSelector( '[data-rotate]' );

const before = await page.locator( 'tbody' ).innerText();

check( 'a key issued before scopes existed may still do everything',
	/everything/i.test( before ), before.replace( /\s+/g, ' ' ).slice( 0, 120 ) );

await page.locator( '[data-rotate]' ).first().click();
await page.waitForSelector( 'input[name=scopes]' );

check( 'everything is ticked to begin with', 3 === await page.locator( 'input[name=scopes]:checked' ).count() );

// A shop that sells but does not hand money back: the refund is done at the box office, by
// somebody looking at the booking.
await page.locator( 'input[name=scopes][value="orders.refund"]' ).uncheck();
await page.fill( '#key-label', 'The web shop' );
await page.screenshot( { path: `${ SHOTS }/03-key.png` } );
await page.click( '.modal button[type=submit]' );

// The dialogue that asked is replaced by the one that hands the credential over — the secret is
// shown exactly once, and this is the once.
await page.waitForSelector( '.credentials', { timeout: 20000 } );

const shown = await page.locator( '.credentials' ).innerText();

check( 'the key is handed over once', /sk_/.test( shown ) && /ak_/.test( shown ),
	shown.replace( /\s+/g, ' ' ).slice( 0, 90 ) );

// "I have saved it" rather than a submit: the credential dialogue has nothing to submit.
await page.click( '.modal .btn--primary[data-close]' );
await page.waitForSelector( '.credentials', { state: 'detached', timeout: 20000 } );
await page.waitForSelector( '[data-rotate]' );
await page.waitForTimeout( 800 );

const after = await page.locator( 'tbody' ).innerText();

check( 'and the screen says what the new one may do',
	/Read bookings, Sell and cancel/.test( after ),
	after.replace( /\s+/g, ' ' ).slice( 0, 200 ) );

await page.screenshot( { path: `${ SHOTS }/04-keys.png`, fullPage: true } );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SSO CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
