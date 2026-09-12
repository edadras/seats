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
page.on( 'console', ( m ) => {
	// One address in this run is deliberately unreachable — the check that a missing picture draws
	// a frame instead of throwing needs a picture that cannot be fetched. The browser logs that it
	// could not be loaded, which is the truth and not a fault in the panel.
	const from = ( m.location() || {} ).url || '';

	if ( 'error' === m.type() && ! /example\.invalid/.test( m.text() + ' ' + from ) ) {
		errors.push( m.text() );
	}
} );

// The designer used to ask for a picture and for a label through `window.prompt`. Nothing on this
// screen may open one now, so every dialog the browser raises is recorded and counted.
const prompts = [];
page.on( 'dialog', async ( d ) => { prompts.push( d.type() + ': ' + d.message() ); await d.dismiss(); } );

console.log( 'Panel: sign in' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
check( 'login form rendered', await page.locator( '#login' ).isVisible() );

await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar', { timeout: 10000 } );
check( 'signed in', await page.locator( '.sidebar' ).isVisible() );
/*
 * Every entry in the sidebar, opened.
 *
 * A frozen list of names used to live here and it rotted: the panel grew a dozen screens and the
 * check went on asserting the fourteen it was born with. What is worth catching is not the roll
 * call — it is a screen that has quietly stopped opening — so this walks whatever the sidebar
 * currently offers, presses each one, and insists it draws a titled page and says nothing to the
 * console on the way.
 */
const views = await page.locator( 'nav button[data-view]' ).evaluateAll(
	( buttons ) => buttons.map( ( button ) => ( {
		view: button.dataset.view,
		label: button.textContent.trim(),
	} ) )
);

check( 'the sidebar offers every screen', views.length >= 20, `${ views.length } screens` );
check( 'each one is named in the reader\'s language',
	views.every( ( entry ) => entry.label && entry.label !== entry.view ),
	views.filter( ( entry ) => ! entry.label || entry.label === entry.view )
		.map( ( entry ) => entry.view ).join( ', ' ) || 'all named' );

const broken = [];

for ( const entry of views ) {
	const before = errors.length;

	await page.click( `nav button[data-view=${ entry.view }]` );

	try {
		await page.waitForFunction( () => {
			const heading = document.querySelector( '.page-head h1' );

			return heading && heading.textContent.trim().length > 0;
		}, null, { timeout: 8000 } );
	} catch ( e ) {
		broken.push( `${ entry.view }: nothing drew` );
		continue;
	}

	// A screen that loads its own data draws a title first and fills in after, so give the
	// request a moment before deciding it was quiet.
	await page.waitForTimeout( 700 );

	if ( errors.length > before ) {
		broken.push( `${ entry.view }: ${ errors.slice( before ).join( ' / ' ) }` );
	}
}

check( 'and every one of them opens', 0 === broken.length, broken.join( ' | ' ) );

console.log( 'Designer: open the chart' );
await page.click( 'nav button[data-view=maps]' );
await page.waitForSelector( 'button[data-map]' );
await page.click( 'button[data-map]' );
await page.waitForSelector( '#dz-canvas' );
await page.waitForTimeout( 800 );

check( 'canvas mounted', await page.locator( '#dz-canvas' ).isVisible() );
check( 'tool palette rendered', ( await page.locator( '.tools [data-tool]' ).count() ) >= 14,
	`${ await page.locator( '.tools [data-tool]' ).count() } tools` );
check( 'tools are vector icons, not emoji',
	( await page.locator( '.tools [data-tool] svg.icon' ).count() ) ===
	( await page.locator( '.tools [data-tool]' ).count() ) );

const layers = await page.locator( '.layer' ).allInnerTexts();
check( 'selection layers listed', layers.length === 5, layers.map( ( l ) => l.split( '\n' )[ 0 ] ).join( ', ' ) );

const places = await page.locator( '.stat' ).first().innerText();
check( 'places counted', /[\d,]+\s+places/.test( places ), places.replace( /\n/g, ' ' ) );

const checks = await page.locator( '.check-row' ).allInnerTexts();
check( 'validation checklist shown', checks.length === 5, `${ checks.length } checks` );
check( 'checklist matches the designer', checks.map( ( c ) => c.trim() ).join( ' | ' ) ===
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
const sectionTitle = await page.locator( '.inspector__head' ).innerText();
check( 'panel narrows to the section', /section/i.test( sectionTitle ), sectionTitle );

/*
 * Reaching a row, which for a long time was impossible.
 *
 * Every point along a row belongs to one of its chairs, so a row could only ever be selected by the
 * gaps — and the catchment around each seat was wide enough to swallow those too. Driven with a
 * real pointer here rather than by setting `editor.selection`, because setting it is exactly what
 * hid the bug: the row *could* be selected, just not by anybody using a mouse.
 */
console.log( 'Designer: a row can actually be clicked' );

const where = async ( what ) => page.evaluate( ( which ) => {
	const editor = window.__editor;
	const row = editor.container().objects.find( ( o ) => o.type === 'row' );
	const seats = window.SeatmapChart.rowSeatPositions( row );
	const labels = window.SeatmapChart.rowLabelPositions( row, seats );
	const rect = editor.canvas.getBoundingClientRect();
	const to = ( p ) => ( {
		x: rect.left + editor.view.x + p.x * editor.view.scale,
		y: rect.top + editor.view.y + p.y * editor.view.scale,
	} );

	if ( 'label' === which ) {
		return to( labels[ 0 ] );
	}

	if ( 'between' === which ) {
		return to( { x: ( seats[ 0 ].x + seats[ 1 ].x ) / 2, y: ( seats[ 0 ].y + seats[ 1 ].y ) / 2 } );
	}

	return to( seats[ Math.floor( seats.length / 2 ) ] );
}, what );

const selected = () => page.evaluate( () => ( {
	objects: window.__editor.selection.length,
	seats: window.__editor.seatSelection.length,
} ) );

let spot = await where( 'seat' );
await page.mouse.click( spot.x, spot.y );
await page.waitForTimeout( 250 );
let picked = await selected();

check( 'clicking a chair still takes the chair', 1 === picked.seats && 0 === picked.objects,
	JSON.stringify( picked ) );

/*
 * And the chair says what it is actually priced as.
 *
 * A seat with no category of its own takes the row's, which is how a whole block is priced in one
 * move — and the panel used to answer "no category assigned" for such a seat while the buyer saw it
 * as Premium. Worse than cosmetic: touching that dropdown wrote a category onto the seat and quietly
 * detached it from its row.
 */
const inherited = await page.evaluate( () => {
	const row = window.__editor.container().objects.find( ( o ) => o.type === 'row' );
	const select = document.querySelector( '#dz-inspector select' );

	return { row: row.categoryKey, shown: select ? select.selectedOptions[ 0 ].textContent : '' };
} );

check( 'and a chair that takes the row’s category says so',
	!! inherited.row && new RegExp( inherited.row, 'i' ).test( inherited.shown ),
	`the row is "${ inherited.row }", the panel says "${ inherited.shown }"` );

spot = await where( 'label' );
await page.mouse.click( spot.x, spot.y );
await page.waitForTimeout( 250 );
picked = await selected();

check( 'clicking the row’s letter takes the row', 1 === picked.objects && 0 === picked.seats,
	JSON.stringify( picked ) );

spot = await where( 'seat' );
await page.keyboard.down( 'Alt' );
await page.mouse.click( spot.x, spot.y );
await page.keyboard.up( 'Alt' );
await page.waitForTimeout( 250 );
picked = await selected();

check( 'and so does Alt and a chair in it', 1 === picked.objects && 0 === picked.seats,
	JSON.stringify( picked ) );

spot = await where( 'between' );
await page.mouse.click( spot.x, spot.y );
await page.waitForTimeout( 250 );
picked = await selected();

check( 'and the gap between two chairs belongs to the row', 1 === picked.objects && 0 === picked.seats,
	JSON.stringify( picked ) );

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
check( 'row label position control rendered', ( await page.locator( '.ends__cap' ).count() ) === 2 );

const seatsBefore = await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).seats.length );

// Type into "Number of seats" — the row has to rearrange, which is only possible because seat
// positions are computed rather than stored.
const seatCountInput = page.locator( '.stepper input' ).first();
await seatCountInput.fill( '7' );
await page.waitForTimeout( 400 );

const seatsAfter = await page.evaluate( () =>
	window.__editor.container().objects.find( ( o ) => o.type === 'row' ).seats.length );
check( 'changing the seat count rebuilds the row', seatsAfter === 7, `${ seatsBefore } -> ${ seatsAfter }` );

const curveInput = page.locator( '.stepper input' ).nth( 2 );
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

/*
 * The room, and the padlock that governs it.
 *
 * Two things answered "may this be edited" and they disagreed: a fact about the map, decided when
 * the designer opened, and the padlock in the toolbar that people actually press. The room's panel
 * read the first, so on a published chart every field in it — including the switch that offers the
 * 3D view to buyers — stayed greyed out for ever, under a toast saying the chart was unlocked.
 */
console.log( 'Designer: the room follows the padlock' );
await page.click( '#dz-3d' );
await page.waitForSelector( '#room-enabled', { timeout: 10000 } );

check( 'an unlocked chart offers the room’s numbers',
	! ( await page.locator( '#room-enabled' ).isDisabled() ) );

await page.locator( '#room-enabled' ).click();
await page.waitForTimeout( 300 );

check( 'and the switch actually writes to the chart',
	true === await page.evaluate( () => !! ( window.__editor.chart.view3d || {} ).enabled ) );

await page.fill( '#room-stage-height', '40' );
await page.waitForTimeout( 300 );

check( 'so does a number beside it',
	40 === await page.evaluate( () => window.__editor.chart.view3d.stage.height ) );

await page.click( '#dz-lock' );
await page.waitForTimeout( 500 );

check( 'and locking the chart takes them away again',
	await page.locator( '#room-enabled' ).isDisabled() );

await page.click( '#dz-lock' );
await page.waitForTimeout( 500 );
await page.click( '#dz-3d' );
await page.waitForTimeout( 600 );

check( 'back to the plan', 0 === await page.locator( '#room-enabled' ).count() );

/*
 * The layer palette folds away.
 *
 * It floats over the top-left of the plan and whatever is drawn under it cannot be clicked at all,
 * which on a chart whose stage sits near the origin is a stage nobody can select.
 */
console.log( 'Designer: the layer palette folds away' );
const palette = () => page.evaluate( () =>
	Math.round( document.getElementById( 'dz-layers' ).getBoundingClientRect().height ) );

const openHeight = await palette();

await page.click( '.layers__title' );
await page.waitForTimeout( 300 );

const foldedHeight = await palette();

check( 'folded, it is a title bar', foldedHeight < openHeight / 2,
	`${ openHeight }px → ${ foldedHeight }px` );

await page.click( '.layers__title' );
await page.waitForTimeout( 300 );

check( 'and it comes back', ( await palette() ) === openHeight );

/*
 * The padlock, and everything it is supposed to hold.
 *
 * The lock was only ever checked in `mutate`, and a drag does not go through `mutate` — it writes
 * into the objects on every pointermove, so that one drag is one undo. A locked chart, a published
 * one included, could therefore be rearranged with the pointer and saved with the padlock lit. Undo
 * was the same kind of hole: it looks like navigation and rewrites the whole chart.
 */
console.log( 'Designer: a locked chart is locked' );
await page.click( '#dz-lock' );
await page.waitForTimeout( 400 );

const anyRow = () => page.evaluate( () => {
	const editor = window.__editor;
	const row = editor.floor().objects
		.filter( ( o ) => o.type === 'section' )[ 0 ].objects.find( ( o ) => o.type === 'row' );

	return { key: row.key, x: row.x, y: row.y };
} );

const rowBefore = await anyRow();
const rowSpot = await page.evaluate( ( key ) => {
	const editor = window.__editor;
	const row = editor.floor().objects
		.filter( ( o ) => o.type === 'section' )[ 0 ].objects.find( ( o ) => o.key === key );
	const rect = editor.canvas.getBoundingClientRect();

	editor.enterSection( editor.floor().objects.filter( ( o ) => o.type === 'section' )[ 0 ].key );
	editor.selection = [ key ];
	editor.onSelectionChange();
	editor.draw();

	return {
		x: rect.left + editor.view.x + row.x * editor.view.scale,
		y: rect.top + editor.view.y + row.y * editor.view.scale,
	};
}, rowBefore.key );

await page.mouse.move( rowSpot.x, rowSpot.y );
await page.mouse.down();
await page.mouse.move( rowSpot.x + 70, rowSpot.y + 50, { steps: 8 } );
await page.mouse.up();
await page.waitForTimeout( 300 );

const rowAfter = await anyRow();
check( 'a locked row cannot be dragged anywhere',
	rowBefore.x === rowAfter.x && rowBefore.y === rowAfter.y,
	`${ rowBefore.x },${ rowBefore.y } → ${ rowAfter.x },${ rowAfter.y }` );
check( 'and the status line says why',
	/locked/i.test( await page.locator( '#dz-status' ).innerText() ),
	await page.locator( '#dz-status' ).innerText() );

const historyBefore = await page.evaluate( () => window.__editor.history.past.length );
await page.keyboard.press( 'Control+z' );
await page.waitForTimeout( 300 );
check( 'undo is an edit too, and refuses',
	historyBefore === await page.evaluate( () => window.__editor.history.past.length ) );

const toolPalette = await page.evaluate( () => {
	const tools = [ ...document.querySelectorAll( '.tools [data-tool]' ) ];

	return {
		off: tools.filter( ( t ) => t.disabled ).map( ( t ) => t.dataset.tool ),
		on: tools.filter( ( t ) => ! t.disabled ).map( ( t ) => t.dataset.tool ),
	};
} );

check( 'the tools that draw are put away',
	toolPalette.off.includes( 'row' ) && toolPalette.off.includes( 'image' ),
	toolPalette.off.join( ', ' ) );
check( 'and the ones that only look are not', [ 'select', 'lasso', 'sameType', 'pan' ]
	.every( ( tool ) => toolPalette.on.includes( tool ) ), toolPalette.on.join( ', ' ) );
check( 'so is undo, and delete', await page.locator( '#dz-undo' ).isDisabled() &&
	await page.locator( '#dz-delete' ).isDisabled() );

const frozen = await page.evaluate( () => {
	const controls = [ ...document.querySelectorAll( '#dz-inspector input, #dz-inspector select, #dz-inspector button' ) ];

	return { total: controls.length, live: controls.filter( ( c ) => ! c.disabled ).length };
} );

check( 'every field in the panel is disabled rather than silently refusing',
	frozen.total > 0 && 0 === frozen.live, `${ frozen.live } of ${ frozen.total } still live` );
check( 'and one line at the top says why',
	1 === await page.locator( '#dz-inspector .insp-locked' ).count() );

await page.click( '#dz-lock' );
await page.waitForTimeout( 400 );
check( 'unlocking gives them all back',
	! ( await page.locator( '#dz-undo' ).isDisabled() ) &&
	! ( await page.locator( '.tools [data-tool=row]' ).isDisabled() ) &&
	0 === await page.locator( '#dz-inspector .insp-locked' ).count() );

await page.click( '#dz-exit' );
await page.waitForTimeout( 400 );

/*
 * A picture is chosen, not typed.
 *
 * The image tool used to call `window.prompt( 'Image URL' )` — untranslated, unstyled, and asking a
 * theatre to find a web host before they could trace their own floor plan. The plan's pictures now
 * use the field the rest of the platform uses.
 */
console.log( 'Designer: a picture is dragged in, not typed into a grey box' );
const promptsBefore = prompts.length;

await page.click( '.tools [data-tool=image]' );
await page.mouse.move( box.x + 200, box.y + box.height - 180 );
await page.mouse.down();
await page.mouse.move( box.x + 360, box.y + box.height - 80, { steps: 8 } );
await page.mouse.up();
await page.waitForSelector( '.modal', { timeout: 8000 } );

check( 'the browser is never asked to prompt', promptsBefore === prompts.length );
check( 'a picture field is offered instead',
	1 === await page.locator( '.modal [data-media-field]' ).count() );
check( 'with all four ways to give one',
	4 === await page.locator( '.modal .media-field__actions button' ).count(),
	( await page.locator( '.modal .media-field__actions button' ).allInnerTexts() ).join( ' / ' ) );

// An address is one of the four, and the one a test can drive without a file dialog.
await page.click( '.modal [data-role=address]' );
await page.fill( '.modal .media-field__url', 'https://example.invalid/plan.png' );
await page.locator( '.modal .media-field__url' ).press( 'Enter' );
await page.waitForTimeout( 800 );

const placed = await page.evaluate( () =>
	window.__editor.floor().objects.filter( ( o ) => o.type === 'image' ).map( ( o ) => o.href ) );

check( 'and the picture lands on the plan', placed.includes( 'https://example.invalid/plan.png' ), placed.join( ', ' ) );
check( 'the picture can be changed afterwards, which it never could before',
	1 === await page.locator( '#dz-inspector [data-media-field]' ).count() );

/*
 * A picture that is not there must not take the plan down with it. The browser calls a 404'd image
 * `complete` with no width, and `drawImage` on one throws — inside the repaint, which abandoned the
 * rest of the frame and whatever was waiting behind it.
 */
await page.evaluate( () => window.__editor.draw() );
await page.waitForTimeout( 600 );
check( 'a picture that cannot be loaded draws a frame rather than throwing',
	! errors.some( ( e ) => /drawImage|CanvasRenderingContext/.test( e ) ),
	errors.filter( ( e ) => /drawImage/.test( e ) ).join( ' | ' ) || 'nothing thrown' );

await page.evaluate( () => {
	const editor = window.__editor;

	editor.floor().objects = editor.floor().objects.filter( ( o ) => o.type !== 'image' );
	editor.clearSelection();
} );

console.log( 'Designer: words are asked for in the reader’s language' );
await page.click( '.tools [data-tool=text]' );
await page.mouse.click( box.x + 420, box.y + box.height - 120 );
await page.waitForSelector( '.modal', { timeout: 8000 } );

check( 'the panel asks, not the browser', promptsBefore === prompts.length );
check( 'in a dialog of its own', 'Text' === await page.locator( '.modal h2' ).innerText() );

await page.fill( '.modal input[name=answer]', 'Sound desk' );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 600 );

check( 'and the words land on the plan',
	( await page.evaluate( () => window.__editor.floor().objects
		.filter( ( o ) => o.type === 'text' ).map( ( o ) => o.text ) ) ).includes( 'Sound desk' ) );

/*
 * What a selection box takes. It used to take whatever had its centre inside it, which is a rule
 * nobody has been taught: a box dragged across half a section came back with nothing selected.
 */
console.log( 'Designer: a box selects what it touches' );
const stageBox = await page.evaluate( () => {
	const editor = window.__editor;
	const rect = editor.canvas.getBoundingClientRect();
	const stage = editor.floor().objects.find( ( o ) => o.key === 'stage' );
	const to = ( x, y ) => ( {
		x: rect.left + editor.view.x + x * editor.view.scale,
		y: rect.top + editor.view.y + y * editor.view.scale,
	} );

	editor.clearSelection();

	return {
		from: to( stage.x - 40, stage.y - 30 ),
		to: to( stage.x + stage.width * 0.4, stage.y + stage.height / 2 ),
	};
} );

await page.mouse.move( stageBox.from.x, stageBox.from.y );
await page.mouse.down();
await page.mouse.move( stageBox.to.x, stageBox.to.y, { steps: 12 } );
await page.mouse.up();
await page.waitForTimeout( 400 );

check( 'a box over part of the stage takes the stage',
	( await page.evaluate( () => window.__editor.selection ) ).includes( 'stage' ),
	( await page.evaluate( () => window.__editor.selection ) ).join( ', ' ) );

await page.evaluate( () => {
	const editor = window.__editor;

	editor.floor().objects = editor.floor().objects.filter( ( o ) => o.text !== 'Sound desk' );
	editor.clearSelection();
	editor.draw();
} );

console.log( 'Designer: categories' );
await page.locator( '.link-btn', { hasText: 'Manage' } ).first().click();
await page.waitForSelector( '.modal' );
check( 'category manager lists the chart categories', ( await page.locator( '.modal .category-row' ).count() ) === 5 );
await page.click( '.modal__foot .btn--primary' );
await page.waitForSelector( '.modal', { state: 'detached' } );

console.log( 'Designer: draw a general admission area' );
const areasBefore = await page.evaluate( () =>
	window.__editor.floor().objects.filter( ( o ) => o.type === 'area' ).length );

await page.click( '.tools [data-tool=area]' );

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
	( await page.locator( '.inspector .hint' ).allInnerTexts() )
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

check( 'client flags it immediately', ( await page.locator( '.issue--error' ).count() ) > 0 );
check( 'and the checklist is no longer clean',
	( await page.locator( '.check-row.is-bad' ).count() ) > 0 );

await page.click( '#dz-publish' );
await page.waitForTimeout( 2000 );
const refusal = await page.locator( '.toast' ).innerText();
check( 'server refuses it', /outside the canvas|cannot be published/i.test( refusal ), refusal );
check( 'shown as an error', ( await page.locator( '.toast--error' ).count() ) === 1 );

const unexpected = errors.slice( 0, errorsBeforeIntentionalFailure );
console.log( '\nUnexpected console errors: ' + ( unexpected.length ? unexpected.join( ' | ' ) : 'none' ) );
if ( unexpected.length ) failures++;

await page.screenshot( { path: '/tmp/designer.png' } );

await page.click( '#dz-theme' );
await page.waitForTimeout( 400 );
check( 'dark theme applied', 'dark' === await page.evaluate( () =>
	document.documentElement.getAttribute( 'data-theme' ) ) );
await page.screenshot( { path: '/tmp/designer-dark.png' } );

/*
 * A closed designer lets go of the keyboard.
 *
 * The editor binds Delete and Ctrl+Z to the window, because they have to work wherever the pointer
 * is, and a listener on the window outlives the screen that added it. Closing and reopening left
 * the first editor listening: it went on taking every keystroke, editing a chart nobody could see,
 * and writing its answers into the status line of the designer that had replaced it. A locked
 * editor left behind is what makes that visible — "This chart is locked" under a chart that is not.
 */
console.log( 'Designer: a closed designer lets go of the keyboard' );
await page.evaluate( () => {
	const editor = window.__editor;

	editor.selection = [ editor.floor().objects[ 0 ].key ];
	editor.locked = true;
	editor.onSelectionChange();
} );

await page.click( '#dz-close' );
await page.waitForTimeout( 600 );
await page.click( 'button[data-map]' );
await page.waitForSelector( '#dz-canvas' );
await page.waitForTimeout( 800 );

await page.evaluate( () => {
	document.getElementById( 'dz-status' ).textContent = 'SENTINEL';
	window.__editor.clearSelection();
} );
await page.keyboard.press( 'Delete' );
await page.waitForTimeout( 400 );

check( 'the designer that was closed answers nothing',
	'SENTINEL' === await page.locator( '#dz-status' ).innerText(),
	await page.locator( '#dz-status' ).innerText() );

await browser.close();

console.log( failures === 0 ? '\nALL DESIGNER CHECKS PASSED' : `\n${ failures } CHECK(S) FAILED` );
process.exit( failures === 0 ? 0 : 1 );
