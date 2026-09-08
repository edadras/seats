/**
 * Browser smoke test for the tenant panel and seat map designer.
 *
 * Drives the real UI in Chromium: sign in, open a published chart, read the validation checklist,
 * inspect a row's properties, change its seat count and curve, go into a section and back out,
 * draw a new row, undo, redo, publish, and confirm the server refuses a chart with a row dragged
 * off the canvas.
 *
 * It edits and publishes the seeded chart, so re-seed before each run:
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node editor_smoke.mjs
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
const page = await browser.newPage( { viewport: { width: 1600, height: 950 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( m.type() === 'error' ) errors.push( m.text() ); } );

console.log( 'Panel: sign in' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
check( 'login form rendered', await page.locator( '#login' ).isVisible() );

await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.topbar', { timeout: 10000 } );
check( 'signed in', await page.locator( '.topbar' ).isVisible() );

console.log( 'Designer: open the chart' );
await page.click( 'nav button[data-view=maps]' );
await page.waitForSelector( 'button[data-map]' );
await page.click( 'button[data-map]' );
await page.waitForSelector( '#dz-canvas' );
await page.waitForTimeout( 800 );

check( 'canvas mounted', await page.locator( '#dz-canvas' ).isVisible() );
check( 'tool palette rendered', ( await page.locator( '.dz-tool' ).count() ) >= 14,
	`${ await page.locator( '.dz-tool' ).count() } tools` );

const layers = await page.locator( '.dz-layer' ).allInnerTexts();
check( 'selection layers listed', layers.length === 5, layers.map( ( l ) => l.split( '\n' )[ 0 ] ).join( ', ' ) );

const places = await page.locator( '.insp-places' ).first().innerText();
check( 'places counted', /\d+ places/.test( places ), places );

const checks = await page.locator( '.insp-check' ).allInnerTexts();
check( 'validation checklist shown', checks.length === 5, `${ checks.length } checks` );
check( 'checklist matches the designer', checks.map( ( c ) => c.split( '\n' )[ 1 ] ).join( ' | ' ) ===
	'No duplicate objects | All objects are labeled | All objects are categorized | One category per object type | Focal point is set' );

console.log( 'Designer: read-only until a draft exists' );
check( 'published chart opens read only', await page.locator( '#dz-readonly' ).isVisible() );

// Saving forks a draft from the published version, which is what makes it editable.
await page.click( '#dz-save' );
await page.waitForSelector( '.toast' );
check( 'saving a draft unlocks editing', ! ( await page.locator( '#dz-readonly' ).isVisible() ) );

console.log( 'Designer: go into a section' );
const box = await page.locator( '#dz-canvas' ).boundingBox();
await page.mouse.dblclick( box.x + box.width / 2, box.y + box.height * 0.55 );
await page.waitForTimeout( 600 );

check( 'exit-section control appears', await page.locator( '#dz-exit' ).isVisible() );
const sectionTitle = await page.locator( '.insp-title' ).innerText();
check( 'panel narrows to the section', /section/i.test( sectionTitle ), sectionTitle );

console.log( 'Designer: inspect and edit a row' );
await page.evaluate( () => {
	const editor = window.__editor;
	const row = editor.container().objects.find( ( o ) => o.type === 'row' );
	editor.selection = [ row.key ];
	editor.onSelectionChange();
	editor.draw();
} );
await page.waitForTimeout( 300 );

const fields = await page.locator( '.insp-field label' ).allInnerTexts();
check( 'row panel shows the designer fields',
	[ 'Number of seats', 'Rotation', 'Curve', 'Seat spacing' ].every( ( f ) => fields.includes( f ) ),
	fields.slice( 0, 8 ).join( ', ' ) );
check( 'row labeling fields present',
	[ 'Enabled', 'Label', 'Displayed label', 'Position', 'Displayed type' ].every( ( f ) => fields.includes( f ) ) );
check( 'row label position control rendered', ( await page.locator( '.insp-position__end' ).count() ) === 2 );

const seatsBefore = await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).seats.length );

// Type into "Number of seats" — the row has to rearrange, which is only possible because seat
// positions are computed rather than stored.
const seatCountInput = page.locator( '.insp-stepper input' ).first();
await seatCountInput.fill( '7' );
await page.waitForTimeout( 400 );

const seatsAfter = await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).seats.length );
check( 'changing the seat count rebuilds the row', seatsAfter === 7, `${ seatsBefore } -> ${ seatsAfter }` );

const curveInput = page.locator( '.insp-stepper input' ).nth( 2 );
await curveInput.fill( '40' );
await page.waitForTimeout( 400 );
const curved = await page.evaluate( () => {
	const row = window.__editor.container().objects.find( ( o ) => o.type === 'row' );
	const p = window.SeatmapChart.rowSeatPositions( row );
	return Math.abs( p[ Math.floor( p.length / 2 ) ].y - p[ 0 ].y );
} );
check( 'curve bows the row', curved > 10, `middle sits ${ curved.toFixed( 1 ) } units off the chord` );

console.log( 'Designer: undo and redo' );
await page.click( '#dz-undo' );
await page.waitForTimeout( 300 );
const afterUndo = await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).curve );
check( 'undo reverts the curve', afterUndo !== 40, `curve now ${ afterUndo }` );

await page.click( '#dz-redo' );
await page.waitForTimeout( 300 );
check( 'redo restores it', ( await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).curve ) ) === 40 );

console.log( 'Designer: leave the section' );
await page.click( '#dz-exit' );
await page.waitForTimeout( 500 );
check( 'back at chart level', ! ( await page.locator( '#dz-exit' ).isVisible() ) );

console.log( 'Designer: categories' );
await page.locator( '.insp-link', { hasText: 'Manage' } ).first().click();
await page.waitForSelector( '.dz-modal' );
check( 'category manager lists the chart categories', ( await page.locator( '.dz-cat' ).count() ) === 5 );
await page.click( '#dz-cat-close' );

console.log( 'Designer: draw a general admission area' );
const areasBefore = await page.evaluate( () =>
	window.__editor.floor().objects.filter( ( o ) => o.type === 'area' ).length );

await page.click( '.dz-tool[data-tool=area]' );

// Draw on clear canvas: the selection-layer panel floats over the top-left corner of the stage.
const drawX = box.x + box.width - 320;
const drawY = box.y + 120;

await page.mouse.move( drawX, drawY );
await page.mouse.down();
await page.mouse.move( drawX + 200, drawY + 90, { steps: 10 } );
await page.mouse.up();
await page.waitForTimeout( 500 );

const areasAfter = await page.evaluate( () =>
	window.__editor.floor().objects.filter( ( o ) => o.type === 'area' ).length );
check( 'area drawn', areasAfter === areasBefore + 1, `${ areasBefore } -> ${ areasAfter }` );

const areaFields = await page.locator( '.insp-field label' ).allInnerTexts();
check( 'area panel shows shape and capacity fields',
	[ 'Width', 'Height', 'Rotation', 'Corner radius', 'Translucent', 'Scale', 'Type', 'Places' ]
		.every( ( f ) => areaFields.includes( f ) ),
	areaFields.join( ', ' ) );
check( 'general admission explained in the panel',
	( await page.locator( '.insp-hint' ).allInnerTexts() )
		.some( ( t ) => /Multiple users can select places/.test( t ) ) );

console.log( 'Designer: publish' );
await page.click( '#dz-publish' );
await page.waitForSelector( '.toast', { timeout: 15000 } );
await page.waitForTimeout( 800 );
const toast = await page.locator( '.toast' ).innerText();
check( 'published', /Published version \d+ with \d+ places/.test( toast ), toast );

const errorsBeforeIntentionalFailure = errors.length;
check( 'no console errors during normal use', errorsBeforeIntentionalFailure === 0, errors.join( ' | ' ) );

console.log( 'Designer: the server refuses a broken chart' );
await page.evaluate( () => {
	// Drag a row clean off the canvas, exactly as a mis-drag would.
	const editor = window.__editor;
	const section = editor.floor().objects.find( ( o ) => o.type === 'section' );
	section.objects.find( ( o ) => o.type === 'row' ).x = 99999;
	editor.onChange( editor.chart );
	// The chart checklist is what the panel shows when nothing is selected, so clear the selection
	// to see it — the same thing a designer does by clicking empty canvas.
	editor.clearSelection();
	editor.draw();
} );
await page.waitForTimeout( 400 );

check( 'client flags it immediately', ( await page.locator( '.insp-issue--error' ).count() ) > 0 );
check( 'and the checklist is no longer clean',
	( await page.locator( '.insp-check.is-bad' ).count() ) > 0 );

await page.click( '#dz-publish' );
await page.waitForTimeout( 2000 );
const refusal = await page.locator( '.toast' ).innerText();
check( 'server refuses it', /outside the canvas|cannot be published/i.test( refusal ), refusal );
check( 'shown as an error', ( await page.locator( '.toast--error' ).count() ) === 1 );

const unexpected = errors.slice( 0, errorsBeforeIntentionalFailure );
console.log( '\nUnexpected console errors: ' + ( unexpected.length ? unexpected.join( ' | ' ) : 'none' ) );
if ( unexpected.length ) failures++;

await page.screenshot( { path: '/tmp/designer.png' } );
await browser.close();

console.log( failures === 0 ? '\nALL DESIGNER CHECKS PASSED' : `\n${ failures } CHECK(S) FAILED` );
process.exit( failures === 0 ? 0 : 1 );
