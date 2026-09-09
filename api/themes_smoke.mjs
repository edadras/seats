/**
 * The theme editor, driven in Chromium.
 *
 * Duplicate one of ours, move a control, write a line of CSS, save, come back. What is being
 * checked is that the preview is the real page — the same stylesheet a visitor gets — rather than
 * an impression of it, and that a stylesheet which tries to leave its element does not.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node themes_smoke.mjs
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
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

console.log( 'The gallery' );
await page.click( 'nav button[data-view=themes]' );
await page.waitForSelector( '.look' );
check( 'six themes of ours', 6 === await page.locator( '[data-copy]' ).count(),
	`${ await page.locator( '[data-copy]' ).count() }` );

console.log( 'Making one my own' );
await page.locator( '[data-copy="noir"]' ).click();
await page.waitForSelector( '.modal' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '#theme-preview' );
await page.waitForTimeout( 800 );

const painted = async () => page.evaluate( () => {
	const doc = document.getElementById( 'theme-preview' ).contentDocument;
	const body = doc.body;

	return {
		background: getComputedStyle( body ).backgroundColor,
		heading: getComputedStyle( doc.querySelector( 'h1' ) ).color,
		button: getComputedStyle( doc.querySelector( '.button' ) ).backgroundColor,
		letterSpacing: getComputedStyle( doc.querySelector( '.masthead' ) ).letterSpacing,
	};
} );

let look = await painted();
check( 'the preview is painted in the theme, not the default',
	'rgb(13, 15, 20)' === look.background, look.background );
check( 'and Noir means Noir', 'rgb(240, 69, 95)' === look.button, look.button );
check( 'the heading is legible on it', 'rgb(242, 244, 248)' === look.heading, look.heading );

console.log( 'Moving a control' );
await page.fill( '[data-hex="accent"]', '#00c2a8' );
await page.dispatchEvent( '[data-hex="accent"]', 'change' );
await page.waitForTimeout( 200 );
look = await painted();
check( 'the page follows the control at once', 'rgb(0, 194, 168)' === look.button, look.button );

console.log( 'Writing CSS' );
await page.fill( '#theme-css', '.masthead { letter-spacing: 0.4em } /* </style><script>x</script> */' );
await page.waitForTimeout( 500 );
look = await painted();
// 0.4em against the preview's 16px root.
check( 'the stylesheet reaches the page', '6.4px' === look.letterSpacing, look.letterSpacing );
check( 'and cannot bring a script with it', await page.evaluate( () =>
	! document.getElementById( 'theme-preview' ).contentDocument.querySelector( 'script' ) ) );

console.log( 'Saving' );
await page.click( '#theme-save' );
await page.waitForTimeout( 900 );
await page.waitForSelector( '#theme-css' );

check( 'the colour was kept',
	'#00c2a8' === await page.locator( '[data-hex="accent"]' ).inputValue(),
	await page.locator( '[data-hex="accent"]' ).inputValue() );
check( 'the stylesheet was kept, without its angle brackets',
	! ( await page.locator( '#theme-css' ).inputValue() ).includes( '<' ),
	await page.locator( '#theme-css' ).inputValue() );
check( 'and what it replaced is in the history',
	( await page.locator( '.version' ).count() ) >= 1,
	`${ await page.locator( '.version' ).count() } versions` );

check( 'no console errors', errors.length === 0, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL THEME CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
