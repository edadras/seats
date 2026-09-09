/**
 * Browser smoke test: the panel really is translated, not merely translatable.
 *
 * The i18n check (tools/i18n-check.mjs) proves the catalogues are level with each other. It cannot
 * prove that a screen *reads* them — a key can be perfectly translated in six languages and never
 * looked up. So this drives the panel in Persian and in German and reads what is on screen.
 *
 * Two things are asserted, and the second is the one that catches regressions:
 *
 *   1. the translated words are there;
 *   2. the English ones are *not* — a hard-coded string put back into panel.js, sites.js or the
 *      inspector shows up here as an English word on a Persian screen.
 *
 * The platform console gets the same treatment at the end, in both languages. It is a separate
 * application with a separate login and a catalogue delivered a different way, so proving the panel
 * is translated proves nothing at all about it.
 *
 * Run against a freshly seeded server:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node locale_smoke.mjs
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

/**
 * Sign in with the panel set to one language.
 *
 * The choice is written to localStorage before the first paint, because that is the one thing that
 * outranks the server (ADR-0005) and it is how a real person switches language.
 */
async function open( locale ) {
	const page = await browser.newPage( { viewport: { width: 1500, height: 950 } } );

	await page.goto( BASE, { waitUntil: 'domcontentloaded' } );
	await page.evaluate( ( value ) => window.localStorage.setItem( 'seatmap.locale', value ), locale );
	await page.goto( BASE, { waitUntil: 'networkidle' } );

	await page.fill( 'input[name=email]', 'owner@northgate.test' );
	await page.fill( 'input[name=password]', 'password' );
	await page.click( '#login button[type=submit]' );
	await page.waitForSelector( '.sidebar', { timeout: 10000 } );

	return page;
}

/**
 * Every word on screen, so an English leak anywhere is visible to one assertion.
 *
 * Folded to lower case: several headings are set in capitals by the stylesheet, and `innerText`
 * reports what is rendered. Comparing "SEITEN" against "Seiten" would fail on the CSS, not the
 * translation — and would pass in Persian, which has no case at all, hiding the difference.
 */
const fold = ( text ) => text.toLocaleLowerCase( 'en' );
const textOf = async ( page ) => fold( await page.evaluate( () => document.body.innerText ) );

const LANGUAGES = [
	{
		code: 'fa',
		dir: 'rtl',
		console: [ 'کنسول پلتفرم', 'برگزارکنندگان', 'طرح‌ها', 'گزارش اپراتور', 'نمای کلی' ],
		nav: [ 'رویدادها', 'بلیت‌ها', 'نقشه‌های صندلی', 'سالن‌ها', 'وب‌سایت‌ها', 'تیم' ],
		designer: [ 'ذخیرهٔ پیش‌نویس', 'انتشار', 'لایهٔ انتخاب', 'همهٔ اشیا' ],
		inspector: [ 'دسته‌ها', 'جایگاه' ],
		sites: [ 'صفحه‌ها', 'طراحی', 'منوها', 'نشانی' ],
		tickets: [ 'هر وضعیتی', 'استفاده‌نشده', 'واردشده' ],
	},
	{
		code: 'de',
		dir: 'ltr',
		console: [ 'Plattform-Konsole', 'Veranstalter', 'Tarife', 'Betreiberprotokoll', 'Überblick' ],
		nav: [ 'Veranstaltungen', 'Tickets', 'Saalpläne', 'Spielstätten', 'Websites', 'Team' ],
		designer: [ 'Entwurf speichern', 'Veröffentlichen', 'Auswahlebene', 'Alle Objekte' ],
		inspector: [ 'Kategorien', 'Plätze' ],
		sites: [ 'Seiten', 'Gestaltung', 'Menüs', 'Adresse' ],
		tickets: [ 'Beliebiger Status', 'Nicht benutzt', 'Eingelassen' ],
	},
];

/*
 * English that used to be baked into the panel. Chosen to be words no translation would contain and
 * no seed record carries: "Northgate Theatre" is data and stays English in every language, so it is
 * deliberately not in this list.
 */
const ENGLISH = [
	'Seat maps', 'Save draft', 'Selection layer', 'All objects', 'Any status', 'Not used',
	'Checked in', 'New seat map', 'Open designer', 'Publish page', 'Add an address',
	'No categories yet', 'Number of seats', 'Displayed label', 'Keyboard shortcuts',
];

/* The console's own former English, kept apart because it is a separate application. */
const CONSOLE_ENGLISH = [
	'Platform console', 'Organisers', 'Operator log', 'Overview', 'New plan', 'All organisers',
	'Takings, by currency', 'Every account on the platform',
];

for ( const language of LANGUAGES ) {
	console.log( `Panel in ${ language.code }` );

	const page = await open( language.code );
	const dir = await page.evaluate( () => document.documentElement.getAttribute( 'dir' ) );

	check( 'document direction', dir === language.dir, dir );

	const sidebar = fold( await page.locator( '.sidebar__nav' ).innerText() );
	const missingNav = language.nav.filter( ( word ) => ! sidebar.includes( fold( word ) ) );
	check( 'the sidebar is translated', missingNav.length === 0,
		missingNav.join( ', ' ) || sidebar.split( '\n' ).join( ' · ' ) );

	// Seat maps → the designer, which is where most of the newly moved strings live. Named
	// rather than counted: the sidebar is grouped, so a position is not a destination.
	await page.click( '[data-view=maps]' );
	await page.waitForSelector( '[data-map]', { timeout: 10000 } );
	await page.click( '[data-map]' );
	await page.waitForSelector( '#dz-canvas', { timeout: 10000 } );
	await page.waitForTimeout( 500 );

	const designer = await textOf( page );
	const missingDesigner = [ ...language.designer, ...language.inspector ]
		.filter( ( word ) => ! designer.includes( fold( word ) ) );
	check( 'the designer and its inspector are translated', missingDesigner.length === 0,
		missingDesigner.join( ', ' ) );

	const shortcuts = await page.evaluate( () => {
		document.getElementById( 'dz-help' ).click();

		return document.querySelector( '.modal' ).innerText;
	} );
	check( 'the shortcut sheet is translated',
		! /\bUndo\b|\bRedo\b|\bDeselect\b|\bPaste\b/.test( shortcuts ),
		shortcuts.split( '\n' ).slice( 0, 3 ).join( ' / ' ) );
	// Key names are printed on the keyboard in Latin whatever the language, so they stay.
	check( 'but the key names are still the ones on the keyboard', /Shift/.test( shortcuts ) );

	await page.keyboard.press( 'Escape' );
	await page.click( '#dz-close' );
	await page.waitForSelector( '.sidebar', { timeout: 10000 } );

	// Leaving the designer routes back to the seat maps, which is a fetch of its own. Let it land
	// before asking for another screen, or its answer repaints over the one being read.
	await page.waitForTimeout( 600 );

	// Websites → the site editor, then tickets.
	await page.click( '[data-view=sites]' );
	await page.waitForSelector( '[data-site]', { timeout: 10000 } );
	await page.waitForTimeout( 300 );
	await page.click( '[data-site]' );
	await page.waitForSelector( '#site-nav', { timeout: 10000 } );
	await page.waitForTimeout( 400 );

	const site = await textOf( page );
	const missingSites = language.sites.filter( ( word ) => ! site.includes( fold( word ) ) );
	check( 'the website editor is translated', missingSites.length === 0, missingSites.join( ', ' ) );

	await page.click( '#site-back' );
	await page.waitForSelector( '.sidebar', { timeout: 10000 } );
	await page.click( '[data-view=tickets]' );
	await page.waitForSelector( '#ticket-status', { timeout: 10000 } );
	await page.waitForTimeout( 400 );

	const tickets = await textOf( page );
	const missingTickets = language.tickets.filter( ( word ) => ! tickets.includes( fold( word ) ) );
	check( 'the ticket screen is translated', missingTickets.length === 0, missingTickets.join( ', ' ) );

	const leaks = ENGLISH.filter(
		( word ) => ( designer + site + tickets + sidebar ).includes( fold( word ) )
	);
	check( 'no English left on any of those screens', leaks.length === 0, leaks.join( ', ' ) );

	await page.close();
}

/*
 * The console.
 *
 * Its page is rendered by the server, so the language is chosen with `?lang=` rather than by
 * writing to localStorage — which is also the path a person takes when they pick a language from
 * the menu on the sign-in card.
 */
for ( const language of LANGUAGES ) {
	console.log( `Console in ${ language.code }` );

	const page = await browser.newPage( { viewport: { width: 1400, height: 900 } } );

	await page.goto( `${ BASE }/console?lang=${ language.code }`, { waitUntil: 'networkidle' } );

	const dir = await page.evaluate( () => document.documentElement.getAttribute( 'dir' ) );
	check( 'document direction', dir === language.dir, dir );

	const login = await textOf( page );
	check( 'the sign-in card is translated', login.includes( fold( language.console[ 0 ] ) ) );
	check( 'and offers a language to sign in in',
		await page.locator( '#c-login-lang' ).isVisible() );

	await page.fill( '#c-email', 'operator@seatmap.test' );
	await page.fill( '#c-password', 'password' );
	await page.click( '#console-login button[type=submit]' );
	await page.waitForSelector( '.sidebar', { timeout: 10000 } );
	await page.waitForTimeout( 400 );

	const overview = await textOf( page );

	await page.click( '.nav-item[data-view=plans]' );
	await page.waitForTimeout( 400 );
	const plans = await textOf( page );

	await page.click( '.nav-item[data-view=audit]' );
	await page.waitForTimeout( 400 );
	const audit = await textOf( page );

	// The first entry is the sign-in card's title, which is checked above and is gone once the
	// shell paints; everything after it belongs to the screens behind the login.
	const seen = overview + plans + audit;
	const missing = language.console.slice( 1 ).filter( ( word ) => ! seen.includes( fold( word ) ) );
	check( 'every console screen is translated', missing.length === 0, missing.join( ', ' ) );

	const leaks = CONSOLE_ENGLISH.filter( ( word ) => seen.includes( fold( word ) ) );
	check( 'no English left on any of them', leaks.length === 0, leaks.join( ', ' ) );

	// The language menu is in the sidebar too, so a choice can be changed after signing in.
	check( 'the language can be changed from inside', await page.locator( '#c-lang' ).isVisible() );

	await page.close();
}

await browser.close();

console.log( failures ? `\n${ failures } CHECK(S) FAILED` : '\nEVERY SCREEN IS TRANSLATED' );
process.exit( failures ? 1 : 0 );
