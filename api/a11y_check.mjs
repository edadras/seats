/**
 * Contrast and keyboard checks for the panel and the buyer's seat picker.
 *
 * The design system is a set of colour tokens, so contrast is a property of the tokens rather than
 * of any one screen: measure the pairs once, in a real browser, in both themes, and every screen
 * built from them is covered. The keyboard checks cover the three things that are invisible until
 * someone tries to use the panel without a mouse.
 *
 * Needs the API, the seeded data, and the plugin preview server:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   (cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)
 *   node a11y_check.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const PREVIEW = process.env.SEATMAP_PREVIEW || 'http://127.0.0.1:8200';

/* 4.5:1 is the WCAG AA threshold for body text. Every pair below carries words, so they are all
   held to it rather than to the 3:1 allowed for large text and UI edges. */
const AA = 4.5;

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/**
 * Measure contrast between token pairs, in the page, where the cascade has already resolved them.
 *
 * Runs in the browser: `var()` chains, `color-scheme` and translucent tokens all need a real
 * computed style to mean anything.
 */
const measure = ( page, pairs, scope ) => page.evaluate( ( [ pairs, scope ] ) => {
	const host = document.querySelector( scope );
	const value = ( name ) => getComputedStyle( host ).getPropertyValue( name ).trim();

	const parse = ( colour ) => {
		const probe = document.createElement( 'span' );
		probe.style.color = colour;
		document.body.appendChild( probe );
		const parts = getComputedStyle( probe ).color.match( /[\d.]+/g ).map( Number );
		probe.remove();

		return { rgb: parts.slice( 0, 3 ), alpha: parts.length > 3 ? parts[ 3 ] : 1 };
	};

	// A translucent token is only ever seen over the surface behind it, so composite it there
	// before measuring — otherwise a soft badge background reads as its own opaque colour.
	const rgb = ( colour, behind ) => {
		const front = parse( colour );

		if ( front.alpha >= 0.999 ) {
			return front.rgb;
		}

		const back = parse( value( behind ) ).rgb;

		return front.rgb.map( ( c, i ) => c * front.alpha + back[ i ] * ( 1 - front.alpha ) );
	};

	const lum = ( c ) => {
		const [ r, g, b ] = c.map( ( v ) => {
			const s = v / 255;

			return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
		} );

		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	};

	const results = {};

	pairs.forEach( ( [ label, front, back, over ] ) => {
		const ground = over || back;
		const [ x, y ] = [ lum( rgb( value( front ), ground ) ), lum( rgb( value( back ), ground ) ) ]
			.sort( ( p, q ) => q - p );

		results[ label ] = ( x + 0.05 ) / ( y + 0.05 );
	} );

	return results;
}, [ pairs, scope ] );

const report = ( results ) => {
	for ( const [ pair, value ] of Object.entries( results ) ) {
		check( pair, value >= AA, value.toFixed( 2 ) + ':1' );
	}
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );

/* ------------------------------------------------------------------------------- the panel */

const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.waitForTimeout( 600 );

const PANEL_PAIRS = [
	[ 'body text on surface', '--text', '--surface' ],
	[ 'secondary on surface', '--text-secondary', '--surface' ],
	[ 'muted on surface', '--text-muted', '--surface' ],
	[ 'muted on the sunken surface', '--text-muted', '--surface-sunken' ],
	[ 'accent link on surface', '--text-accent', '--surface' ],
	[ 'button text on the accent', '--on-accent', '--accent' ],
	[ 'danger on its soft background', '--danger', '--danger-soft', '--surface' ],
	[ 'warning on its soft background', '--warn', '--warn-soft', '--surface' ],
	[ 'success on its soft background', '--ok', '--ok-soft', '--surface' ],
];

for ( const theme of [ 'light', 'dark' ] ) {
	await page.evaluate( ( t ) => document.documentElement.setAttribute( 'data-theme', t ), theme );
	await page.waitForTimeout( 150 );

	console.log( `Panel contrast (${ theme } theme)` );
	report( await measure( page, PANEL_PAIRS, ':root' ) );
}

await page.evaluate( () => document.documentElement.setAttribute( 'data-theme', 'light' ) );

console.log( 'Panel keyboard' );

await page.keyboard.press( 'Tab' );

const stops = [];

for ( let i = 0; i < 14; i++ ) {
	const stop = await page.evaluate( () => {
		const el = document.activeElement;

		if ( ! el || el === document.body ) {
			return null;
		}

		const style = getComputedStyle( el );

		/*
		 * Themes routinely remove the browser default, so the design system draws its own — and it
		 * draws it two ways. Buttons and links take the shared `:focus-visible` outline; form
		 * controls take `outline: none` and a box-shadow ring instead, because an outline outside a
		 * bordered field reads as a second border. Both are focus indicators, and a check that knew
		 * only about outlines called a perfectly visible field unfocusable.
		 */
		const outlined = style.outlineStyle !== 'none' && parseFloat( style.outlineWidth ) > 0;
		const ringed = style.boxShadow !== 'none' && '' !== style.boxShadow;

		return {
			name: ( el.getAttribute( 'aria-label' ) || el.textContent || el.tagName ).trim().slice( 0, 28 ),
			visible: outlined || ringed,
		};
	} );

	if ( stop ) {
		stops.push( stop );
	}

	await page.keyboard.press( 'Tab' );
}

check( 'every focused control shows a focus ring', stops.every( ( s ) => s.visible ),
	stops.filter( ( s ) => ! s.visible ).map( ( s ) => s.name ).join( ', ' ) ||
	`${ stops.length } stops checked` );
check( 'the sidebar is reachable by keyboard',
	stops.some( ( s ) => /Events|Seat maps|Venues|Connections/.test( s.name ) ),
	stops.map( ( s ) => s.name ).join( ' → ' ) );

console.log( 'Panel modals' );

await page.click( 'nav button[data-view=venues]' );
await page.waitForSelector( '#add-venue' );
await page.click( '#add-venue' );
await page.waitForSelector( '.modal' );

check( 'focus lands in the modal', await page.evaluate( () =>
	!! document.activeElement.closest( '.modal__body' ) ) );

await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 200 );

check( 'Escape closes it', 0 === await page.locator( '.modal' ).count() );
check( 'focus returns to whatever opened it', await page.evaluate( () =>
	document.activeElement && 'add-venue' === document.activeElement.id ) );

await page.close();

/* ------------------------------------------------------------------------------ the picker */

const PICKER_PAIRS = [
	[ 'seat label on surface', '--seatmap-text', '--seatmap-surface' ],
	[ 'muted text on surface', '--seatmap-muted', '--seatmap-surface' ],
	[ 'muted text on the sunken surface', '--seatmap-muted', '--seatmap-surface-sunken' ],
	[ 'selected seat label', '--seatmap-on-accent', '--seatmap-accent' ],
	[ 'error text on its background', '--seatmap-danger', '--seatmap-danger-soft', '--seatmap-surface' ],
];

// The seated room specifically: the checks below click a chair, and the demo also contains a
// warehouse that has none.
const eventId = process.env.EVENT || ( await seatedEvent( BASE, 'a11y' ) ).public_id;

for ( const scheme of [ 'light', 'dark' ] ) {
	const buyer = await browser.newPage( { viewport: { width: 1200, height: 900 }, colorScheme: scheme } );

	await buyer.goto( `${ PREVIEW }/tools/preview.html?api=${ BASE }&event=${ eventId }`,
		{ waitUntil: 'networkidle' } );

	// The picker opens on the venue's blocks, so a section has to be chosen before there are any
	// chairs to check. That the block list is reachable at all is itself the first check.
	await buyer.waitForSelector( '.seatmap-widget__block' );
	await buyer.locator( '.seatmap-widget__block:not([disabled])' ).first().click();
	await buyer.waitForSelector( '.seatmap-widget__seat' );

	console.log( `Picker contrast (${ scheme } theme)` );
	report( await measure( buyer, PICKER_PAIRS, '.seatmap-widget' ) );

	if ( 'light' === scheme ) {
		console.log( 'Picker keyboard' );

		// Every seat is a real button, which is what makes the plan usable without seeing it.
		await buyer.locator( '.seatmap-widget__seat:not([disabled])' ).first().focus();
		await buyer.keyboard.press( 'Enter' );
		await buyer.waitForTimeout( 300 );

		check( 'a seat can be chosen from the keyboard',
			1 === await buyer.locator( '.seatmap-widget__seat.is-selected' ).count() );
		check( 'focus stays on the seat after the list repaints', await buyer.evaluate( () =>
			document.activeElement.classList.contains( 'seatmap-widget__seat' ) ) );
		check( 'the choice is announced', await buyer.evaluate( () =>
			!! document.querySelector( '.seatmap-widget__selection li' ) ) );

		// And back out again, without a mouse: a buyer who zoomed into the wrong block must not
		// be stranded in it.
		await buyer.locator( '.seatmap-widget__back-button' ).click();
		await buyer.waitForTimeout( 200 );
		check( 'the way back to the venue is a button too',
			( await buyer.locator( '.seatmap-widget__block' ).count() ) > 1 );
	}

	await buyer.close();
}

console.log( failures === 0 ? '\nALL ACCESSIBILITY CHECKS PASSED' : `\n${ failures } CHECK(S) FAILED` );

await browser.close();
process.exit( failures === 0 ? 0 : 1 );
