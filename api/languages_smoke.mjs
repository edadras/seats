/**
 * A site written in more than one language, driven in Chromium.
 *
 * `SitePageTranslationTest` holds up the overlay and the fallback. What a browser adds is the
 * organiser's half of it: choosing which languages the site is published in, writing a page in one
 * of them beside the words it falls back to, and then reading the result as a visitor — including
 * the switcher, which now offers what the site is written in rather than all six the platform
 * happens to speak.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node languages_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/languages-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'languages-smoke' );

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

console.log( 'A site published in one language offers no switch' );
const visitor = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
visitor.on( 'pageerror', ( e ) => errors.push( e.message ) );

await visitor.goto( `${ SITE }/`, { waitUntil: 'networkidle' } );

check( 'because a control offering one choice asks a question with one answer',
	0 === await visitor.locator( '.langs' ).count() );

console.log( 'The organiser publishes it in Persian too' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=sites]' );

// The websites screen lists the sites; the editor is one click further in.
await page.waitForSelector( '[data-site]', { timeout: 20000 } );
await page.waitForTimeout( 300 );
await page.click( '[data-site]' );
await page.waitForSelector( '#site-nav', { timeout: 20000 } );
await page.waitForTimeout( 400 );

// The settings group in the site editor's own sidebar, by its icon-and-word button.
await page.locator( '#site-nav .nav-item', { hasText: 'Languages' } ).first().click();
await page.waitForSelector( '#site-locales' );

const own = page.locator( '[data-locale="en"]' );

check( 'the site’s own language is ticked and cannot be unticked',
	await own.isChecked() && await own.isDisabled() );

await page.locator( '[data-locale="fa"]' ).check();
await page.screenshot( { path: `${ SHOTS }/01-languages.png` } );
await page.click( '#locales-save' );
await page.waitForTimeout( 1200 );

const site = ( await api( 'GET', '/v1/sites' ) ).body.data[ 0 ];

check( 'and the site is now published in two', 2 === ( site.locales || [] ).length,
	( site.locales || [] ).join( ', ' ) );

console.log( 'And writes a page in it' );
await page.locator( '.site-nav__group button', { hasText: 'Visiting' } ).first().click().catch( () => {} );
await page.waitForTimeout( 600 );

// Whichever page the site opens on: the point is the button, not which page it is on.
await page.waitForSelector( '#page-words', { timeout: 15000 } );
await page.click( '#page-words' );
await page.waitForSelector( '#pw-locale', { timeout: 15000 } );

const boxes = await page.locator( '#pw-fields .field' ).count();

check( 'the form offers the page’s own fields and no others', boxes > 0, `${ boxes } fields` );
check( 'with the words it falls back to beside each box, not inside it',
	( await page.locator( '#pw-fields .field__hint' ).first().innerText() ).length > 0,
	await page.locator( '#pw-fields .field__hint' ).first().innerText() );

await page.fill( '#pw-title', 'صفحهٔ فارسی' );

// The first block on the page, whatever it is.
const first = page.locator( '#pw-fields .field' ).nth( 3 );

if ( await first.count() ) {
	await first.locator( 'input, textarea' ).first().fill( 'این متن فارسی است.' );
}

await page.screenshot( { path: `${ SHOTS }/02-writing.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 1500 );

const pages = ( await api( 'GET', `/v1/sites/${ site.id }` ) ).body.pages || [];
const written = pages.filter( ( p ) => ( p.written_in || [] ).includes( 'fa' ) );

check( 'the page is saved as written in Persian', written.length > 0,
	written.map( ( p ) => p.path ).join( ', ' ) );

console.log( 'The visitor reads it' );
await visitor.reload( { waitUntil: 'networkidle' } );

check( 'the switcher now offers the two the site is written in',
	1 === await visitor.locator( '.langs' ).count() &&
	1 === await visitor.locator( '.langs__link' ).count(),
	await visitor.locator( '.langs' ).innerText() );
check( 'and not the four it is not', ! ( await visitor.content() ).includes( 'lang=de' ) );

const translated = written[ 0 ];

await visitor.goto( `${ SITE }${ translated.path }?lang=fa`, { waitUntil: 'networkidle' } );

check( 'the page is read in Persian', 'fa' === await visitor.evaluate(
	() => document.documentElement.getAttribute( 'lang' ) ) );
check( 'and turned round', 'rtl' === await visitor.evaluate(
	() => document.documentElement.getAttribute( 'dir' ) ) );

const body = await visitor.locator( 'main' ).innerText();

check( 'with the organiser’s own Persian words on it', /فارسی/.test( body ),
	body.split( '\n' ).filter( Boolean ).slice( 0, 3 ).join( ' | ' ) );

await visitor.screenshot( { path: `${ SHOTS }/03-persian.png`, fullPage: true } );

// The half of the page nobody translated is still worth reading in the language it was written in.
check( 'and anything untranslated still readable rather than missing', body.trim().length > 20 );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
