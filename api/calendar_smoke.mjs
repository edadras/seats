/**
 * Two calendars, driven in Chromium.
 *
 * `CalendarTest` holds up the rules on the server — which calendar is in force, what a site and an
 * account decide, and that the cached language catalogue says nothing about either. What a browser
 * adds is the half that only exists in a browser: an organiser in Tehran *typing* ۱۴۰۵/۰۷/۰۸ into a
 * box and the right instant reaching the server.
 *
 * And one thing no test on the server can do: check the conversion itself against ICU's own answer,
 * every day across seventy years, in both directions. A calendar that is a day out in one year is
 * the kind of wrong that reaches a poster.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node calendar_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/calendar-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1100 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The conversion agrees with ICU, every day, both ways' );
await page.goto( BASE, { waitUntil: 'networkidle' } );

const arithmetic = await page.evaluate( () => {
	const C = window.SeatmapCalendar;
	const icu = new Intl.DateTimeFormat( 'en-u-ca-persian-nu-latn', {
		year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC',
	} );

	let checked = 0;
	const wrong = [];

	for ( let t = Date.UTC( 1990, 0, 1 ); t <= Date.UTC( 2060, 0, 1 ); t += 86400000 ) {
		const day = new Date( t );
		const mine = C.toJalali( day.getUTCFullYear(), day.getUTCMonth() + 1, day.getUTCDate() );
		const parts = Object.fromEntries( icu.formatToParts( day ).map( ( p ) => [ p.type, p.value ] ) );

		if ( mine.year !== Number( parts.year ) || mine.month !== Number( parts.month ) ||
			mine.day !== Number( parts.day ) ) {
			if ( wrong.length < 3 ) {
				wrong.push( day.toISOString().slice( 0, 10 ) + ' → ' + JSON.stringify( mine ) );
			}
		}

		const back = C.toGregorian( mine.year, mine.month, mine.day );

		if ( back.year !== day.getUTCFullYear() || back.month !== day.getUTCMonth() + 1 ||
			back.day !== day.getUTCDate() ) {
			if ( wrong.length < 6 ) {
				wrong.push( 'round trip ' + day.toISOString().slice( 0, 10 ) );
			}
		}

		checked++;
	}

	return { checked, wrong };
} );

check( 'seventy years of days convert exactly', 0 === arithmetic.wrong.length,
	`${ arithmetic.checked } days${ arithmetic.wrong.length ? ' — ' + arithmetic.wrong.join( '; ' ) : '' }` );

console.log( 'An organiser switches the account to Jalali' );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );

const gregorian = await page.locator( 'tbody tr' ).first().innerText();

check( 'the events table starts in the reader’s own calendar', /20\d\d/.test( gregorian ),
	( gregorian.match( /\d[^\t\n]*20\d\d[^\t\n]*/ ) || [ '' ] )[ 0 ].trim() );

await page.selectOption( '#calendar', 'persian' );
// The panel reloads, exactly as a language change does: the calendar is inside the locale string
// every date on every screen is formatted with.
await page.waitForTimeout( 2500 );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );

const jalali = await page.locator( 'tbody tr' ).first().innerText();

check( 'and afterwards every date on it is Jalali', /1[34]\d\d/.test( jalali ),
	( jalali.match( /\d[^\t\n]*1[34]\d\d[^\t\n]*/ ) || [ '' ] )[ 0 ].trim() );

console.log( 'And types a date in it' );
await page.locator( '[data-event-edit]' ).first().click();
await page.waitForSelector( '.modal .jalali', { timeout: 20000 } );

check( 'every date box on the form takes Jalali',
	await page.locator( '.modal .jalali' ).count() >= 3 );

const box = page.locator( '.modal .jalali input[type=text]' ).first();

check( 'and shows what is already stored, written in it',
	/^1[34]\d\d\//.test( await box.inputValue() ), await box.inputValue() );

await page.locator( '.modal .jalali__open' ).first().click();
await page.waitForSelector( '.jalali__month' );
await page.screenshot( { path: `${ SHOTS }/01-the-month.png` } );

await page.locator( '.jalali__month [data-day="20"]' ).click();
await page.waitForTimeout( 200 );

check( 'picking a day writes it into the form',
	/\/20 /.test( await box.inputValue() ) || /\/20$/.test( await box.inputValue() ),
	await box.inputValue() );

// The one that matters: what the server is actually sent.
await box.fill( '1405/07/08 21:15' );
await box.blur();
await page.waitForTimeout( 200 );

const native = await page.locator( '.modal input[name=starts_at]' ).inputValue();

check( 'and a typed Jalali date becomes the right instant', '2026-09-30T21:15' === native, native );

// Persian digits, as a Persian keyboard produces them.
await box.fill( '۱۴۰۵/۰۷/۰۹ ۱۹:۰۰' );
await box.blur();
await page.waitForTimeout( 200 );

check( 'typed in Persian digits too',
	'2026-10-01T19:00' === await page.locator( '.modal input[name=starts_at]' ).inputValue(),
	await page.locator( '.modal input[name=starts_at]' ).inputValue() );

await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.toast' );
await page.screenshot( { path: `${ SHOTS }/02-the-form.png` } );

console.log( 'The website is a separate decision, and the buyer sees it' );
const token = await page.evaluate( () => window.sessionStorage.getItem( 'seatmap_token' ) );

const site = await page.evaluate( async ( bearer ) => {
	const list = await ( await fetch( '/v1/sites', {
		headers: { Authorization: 'Bearer ' + bearer, Accept: 'application/json' },
	} ) ).json();

	const first = ( list.data || [] )[ 0 ];

	const saved = await ( await fetch( '/v1/sites/' + first.id, {
		method: 'PATCH',
		headers: {
			Authorization: 'Bearer ' + bearer,
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: JSON.stringify( { calendar: 'persian' } ),
	} ) ).json();

	return { id: first.id, calendar: saved.calendar };
}, token );

check( 'the site keeps its own choice', 'persian' === site.calendar, site.calendar );

await page.goto( SITE, { waitUntil: 'networkidle' } );

const programme = await page.locator( 'body' ).innerText();

check( 'and the programme is dated in it',
	/Farvardin|Ordibehesht|Khordad|Tir|Mordad|Shahrivar|Mehr|Aban|Azar|Dey|Bahman|Esfand/.test( programme ),
	( programme.match( /\b(Mehr|Aban|Azar|Dey|Bahman|Esfand|Farvardin)\b[^\n]{0,20}/ ) || [ '—' ] )[ 0 ] );

await page.screenshot( { path: `${ SHOTS }/03-the-programme.png` } );

check( 'nothing threw along the way', 0 === errors.length, errors.slice( 0, 2 ).join( ' | ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
