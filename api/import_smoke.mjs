/**
 * A plan brought in from somewhere else, seen from both ends — driven in Chromium.
 *
 * `ChartImporterTest` proves the fitting: that a cloud of per-seat coordinates comes back as rows
 * with an anchor, a rotation and a pitch, and that our own geometry puts every chair within a
 * fraction of a unit of where the export had it. What a browser adds is the half arithmetic cannot
 * check — that the imported room is a room somebody can *look* at and buy a seat in.
 *
 * The scenery is the part that matters here and is the part that was broken: a plan traced from a
 * floor drawing arrives as hundreds of separate wall segments, and the buyer's picker had no branch
 * for drawing a line. With no width and no height, every one of them painted nothing at all, so an
 * imported hall showed its chairs floating in an empty rectangle. This walks the canvas and counts
 * the ink to make sure that stays fixed.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node import_smoke.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/import-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/*
 * A fan of blocks around a stage, written the way another platform writes one: every seat its own
 * pair of coordinates, no rows, no rotations, nothing said about how any of it was drawn. The three
 * blocks lean at different angles, which is what a real amphitheatre does and what makes fitting a
 * row more than reading a list.
 */
const seats = [];
const lines = [];
const blocks = [ [ 'Block 01', 0 ], [ 'Block 02', -12 ], [ 'Block 03', 12 ] ];

blocks.forEach( ( [ name, degrees ], block ) => {
	const radians = ( degrees * Math.PI ) / 180;

	for ( let row = 0; row < 8; row++ ) {
		const label = String.fromCharCode( 65 + row );
		const x0 = 400 + block * 700 + row * 26;
		const y0 = 500 + row * 60;

		for ( let seat = 0; seat < 14; seat++ ) {
			// Numbered from two, evens only, because that is how half of Europe numbers a block and
			// it is the case a naive importer renumbers from one without telling anybody.
			seats.push( {
				sectionName: name,
				rowName: label,
				number: 2 + seat * 2,
				x: x0 + Math.cos( radians ) * 44 * seat,
				y: y0 + Math.sin( radians ) * 44 * seat,
				color: row < 3 ? '#992C4E' : row < 6 ? '#4176A5' : '#58B44F',
				status: 'booked',
			} );
		}
	}
} );

// The traced walls: one long run across the back of the hall, in segments, as a floor plan gives it.
for ( let i = 0; i < 40; i++ ) {
	lines.push( {
		x1: 300 + i * 90, y1: 1300, x2: 390 + i * 90, y2: 1300,
		color: '#333333', thickness: 3, mode: 'continuous',
	} );
}

const file = join( mkdtempSync( join( tmpdir(), 'import-smoke-' ) ), 'export.json' );

writeFileSync( file, JSON.stringify( {
	venue: 'Imported Amphitheatre',
	canvasSize: { width: 4200, height: 2000 },
	seats,
	lines,
	shapes: [ { type: 'rectangle', x: 1500, y: -180, width: 1200, height: 150, text: 'SCÈNE', backgroundColor: '#111111' } ],
	texts: [ { text: 'Régie', x: 2050, y: 1500, fontSize: 30, color: '#323335' } ],
} ) );

console.log( 'The plan is read, fitted and published' );

const imported = execFileSync( 'php', [ 'artisan', 'chart:import', file, '--tenant=northgate', '--publish' ], { encoding: 'utf8' } );

check( 'every seat in the file came through', imported.includes( String( seats.length ) ), 
	( imported.match( /Seats\s*\|\s*(\d+)/ ) || [] )[ 1 ] );
check( 'and no seat moved by even a fifth of a chair',
	parseFloat( ( imported.match( /Worst seat moved by \| ([\d.]+)/ ) || [ '', '99' ] )[ 1 ] ) < 3.6,
	( imported.match( /Worst seat moved by \| ([\d.]+)/ ) || [] )[ 1 ] );
check( 'last night’s sales did not come with the room', /belongs to a performance/.test( imported ) );

const map = ( imported.match( /Imported as map ([0-9a-f-]{36})/ ) || [] )[ 1 ];

check( 'it was saved', !! map, map );

const token = await ( await fetch( BASE + '/v1/auth/login', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
	body: JSON.stringify( { email: 'owner@northgate.test', password: 'password', device_name: 'import-smoke' } ),
} ) ).json().then( ( body ) => body.token );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

// A night on the imported plan, put on sale the ordinary way, through the ordinary API.
const saved = ( await api( 'GET', `/v1/seat-maps/${ map }` ) ).body;
const categories = ( ( saved.published_version || saved.draft_version || {} ).geometry || {} ).categories || [];

check( 'each colour in the file became a price category of its own', 3 === categories.length,
	categories.map( ( c ) => c.label ).join( ', ' ) );

const created = await api( 'POST', '/v1/events', {
	name: 'Imported night',
	seat_map_id: map,
	starts_at: new Date( Date.now() + 21 * 86400000 ).toISOString(),
	timezone: 'Europe/Paris',
	currency: 'EUR',
	status: 'published',
} );

check( 'and an event can be put on sale against it', 201 === created.status, JSON.stringify( created.body ).slice( 0, 160 ) );

const event = created.body;

await api( 'PUT', `/v1/events/${ event.id }/pricing`, {
	currency: 'EUR',
	zones: categories.map( ( category, index ) => ( {
		key: category.key, name: category.label, amount: 9000 - index * 2500, color: category.color,
	} ) ),
} );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

console.log( 'A buyer opens it' );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1200 } } ) ).newPage();
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await page.goto( `${ SITE }/events/${ event.public_id }`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.seatmap-widget canvas' );
await page.waitForTimeout( 2500 );

const listed = await page.locator( '.seatmap-widget__block-name' ).allInnerTexts();

check( 'every block of the fan is offered', blocks.every( ( [ name ] ) => listed.join( ' | ' ).includes( name ) ),
	listed.join( ' | ' ) );

/*
 * The walls. Sampled along the line the export drew them on: a hall whose scenery is not painted
 * has nothing but background there, and that is exactly what the missing branch used to leave.
 */
const ink = await page.evaluate( () => {
	const canvas = document.querySelector( '.seatmap-widget canvas' );
	const ctx = canvas.getContext( '2d' );
	const pixels = ctx.getImageData( 0, 0, canvas.width, canvas.height ).data;
	const seen = {};

	for ( let i = 0; i < pixels.length; i += 4 ) {
		if ( pixels[ i + 3 ] < 8 ) continue;
		const key = [ pixels[ i ], pixels[ i + 1 ], pixels[ i + 2 ] ].join( ',' );
		seen[ key ] = ( seen[ key ] || 0 ) + 1;
	}

	return seen;
} );

// #333333 is the colour the export gave those walls, and nothing else on this plan is that colour.
const wall = ink[ '51,51,51' ] || 0;

check( 'the traced walls are drawn, not silently dropped', wall > 200, `${ wall } pixels of wall` );

await page.screenshot( { path: `${ SHOTS }/01-imported-plan.png` } );

console.log( 'And picks a seat in it' );
await page.getByText( 'Block 02', { exact: true } ).first().click();
await page.waitForTimeout( 1500 );

/*
 * One map, not a set of separate rooms.
 *
 * Going into a block used to hide every other block's chairs behind a grey rectangle, which is
 * exactly wrong for a fan: the seat somebody actually wants is often the one at the end of the
 * next block along, and they cannot compare it with this one without leaving this one. So the
 * chairs of every block near enough and big enough are drawn, and the plan is dragged between
 * them rather than stepped between.
 */
const onScreen = () => page.evaluate( () => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const view = widget.viewBox();
	const drawn = widget.seats.filter( ( seat ) => seat.floorKey === widget.floorKey &&
		seat.x >= view.x && seat.x <= view.x + view.width &&
		seat.y >= view.y && seat.y <= view.y + view.height );

	return {
		chairs: widget.chairsShown(),
		blocks: [ ...new Set( drawn.map( ( seat ) => seat.section ) ) ].sort(),
		outlines: widget.blocks.filter( ( block ) => block.outline && block.outline.length > 2 ).length,
		// Block 02 leans twelve degrees, so its outline and its bounding box are different shapes.
		// If the picker were still drawing boxes, these two would be the same four corners.
		boxed: widget.blocks.filter( ( block ) => 'Block 02' === block.name ).map( ( block ) => {
			const xs = block.outline.map( ( point ) => point[ 0 ] );
			const ys = block.outline.map( ( point ) => point[ 1 ] );
			const corners = [ Math.min( ...xs ), Math.max( ...xs ), Math.min( ...ys ), Math.max( ...ys ) ];

			return block.outline.every( ( point ) =>
				corners.includes( point[ 0 ] ) && corners.includes( point[ 1 ] ) );
		} )[ 0 ],
	};
} );

const inside = await onScreen();

check( 'going into a block draws chairs, not another outline', inside.chairs );
check( 'and the blocks either side of it are drawn too, at the same time',
	inside.blocks.length > 1, inside.blocks.join( ', ' ) );
check( 'each block is the shape its own seats make, not a rectangle',
	inside.outlines === blocks.length && false === inside.boxed );

// Dragging brings the rest of the room across, rather than a button stepping to another screen.
const canvas = await page.locator( '.seatmap-widget canvas' ).boundingBox();
await page.mouse.move( canvas.x + canvas.width * 0.75, canvas.y + canvas.height * 0.5 );
await page.mouse.down();
await page.mouse.move( canvas.x + canvas.width * 0.2, canvas.y + canvas.height * 0.5, { steps: 20 } );
await page.mouse.up();
await page.waitForTimeout( 700 );

const panned = await onScreen();

check( 'and the map can be dragged across the rest of the room',
	panned.blocks.join() !== inside.blocks.join() && panned.chairs,
	panned.blocks.join( ', ' ) );

/*
 * A chair in a block the buyer never opened.
 *
 * The point of drawing the neighbours is being able to take one of their seats, so this reaches
 * past Block 02 into whatever else is on screen. The list beside the plan follows — without the
 * view moving, because a map that jumps when a seat is taken loses the seat next to it.
 */
const elsewhere = await page.evaluate( () => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const view = widget.viewBox();
	const seat = widget.seats.filter( ( entry ) => entry.floorKey === widget.floorKey &&
		'available' === entry.state && entry.section !== 'Block 02' &&
		entry.x >= view.x + 60 && entry.x <= view.x + view.width - 60 &&
		entry.y >= view.y + 60 && entry.y <= view.y + view.height - 60 )[ 0 ];

	if ( ! seat ) {
		return null;
	}

	const rect = widget.canvas.getBoundingClientRect();
	const scale = widget.baseScale * widget.view.scale;

	return {
		block: seat.section,
		x: rect.left + widget.view.x + seat.x * scale,
		y: rect.top + widget.view.y + seat.y * scale,
	};
} );

check( 'a chair in a block the buyer never opened is there to be taken', !! elsewhere,
	elsewhere ? elsewhere.block : 'none on screen' );

if ( elsewhere ) {
	const before = await page.evaluate( () => document.querySelector( '.seatmap-widget' ).seatmapWidget.view.x );

	await page.mouse.click( elsewhere.x, elsewhere.y );
	await page.waitForTimeout( 600 );

	const summary = ( await page.locator( '.seatmap-widget__selection' ).innerText().catch( () => '' ) )
		.replace( /\s+/g, ' ' );

	check( 'and taking it names that block in the summary', summary.includes( elsewhere.block ),
		summary.slice( 0, 120 ) );
	check( 'without the plan moving under the hand that took it', before ===
		await page.evaluate( () => document.querySelector( '.seatmap-widget' ).seatmapWidget.view.x ) );
}

// Zooming out is the way back to the overview, by the same rule rather than a different screen.
await page.mouse.move( canvas.x + canvas.width / 2, canvas.y + canvas.height / 2 );
for ( let i = 0; i < 8; i++ ) {
	await page.mouse.wheel( 0, 260 );
	await page.waitForTimeout( 120 );
}
await page.waitForTimeout( 500 );

check( 'and zooming out turns the chairs back into blocks', ! ( await onScreen() ).chairs );

// A block on the canvas is the way back in — the same press as from the overview.
const plate = await page.evaluate( () => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const rect = widget.canvas.getBoundingClientRect();
	const scale = widget.baseScale * widget.view.scale;
	const onCanvas = ( block ) => ( {
		name: block.name,
		x: rect.left + widget.view.x + ( block.box.x + block.box.width / 2 ) * scale,
		y: rect.top + widget.view.y + ( block.box.y + block.box.height / 2 ) * scale,
	} );

	return widget.blocksOnFloor().map( onCanvas ).filter( ( point ) =>
		point.x > rect.left + 4 && point.x < rect.right - 4 &&
		point.y > rect.top + 4 && point.y < rect.bottom - 4 )[ 0 ] || null;
} );

check( 'a block is on screen to press', !! plate, plate ? plate.name : 'none' );
await page.mouse.click( plate.x, plate.y );
await page.waitForTimeout( 1400 );

check( 'and a block can be pressed to go back into one', ( await onScreen() ).chairs );

const box = await page.locator( '.seatmap-widget canvas' ).boundingBox();
let chosen = '';

for ( let dy = 0.3; dy <= 0.75 && ! chosen; dy += 0.05 ) {
	for ( let dx = 0.3; dx <= 0.72 && ! chosen; dx += 0.04 ) {
		await page.mouse.click( box.x + box.width * dx, box.y + box.height * dy );
		await page.waitForTimeout( 260 );

		if ( await page.locator( '.seatmap-widget__submit:not([disabled])' ).count() ) {
			// Asked of the widget: the chairs are drawn on a canvas, so the label a buyer is
			// looking at is not in the DOM to be read.
			chosen = await page.evaluate( () => {
				const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
				const seat = widget.selected[ 0 ];

				return seat ? [ seat.section, seat.row, seat.label ].join( ' · ' ) : '';
			} );
		}
	}
}

check( 'a chair in an imported block can be chosen', !! chosen, chosen );
// Evens only, as the file had them: an odd label would mean the importer renumbered the block.
check( 'and it is called what the file called it',
	/\d/.test( chosen ) && 0 === Number( chosen.split( '·' ).pop().trim() ) % 2, chosen );

await page.screenshot( { path: `${ SHOTS }/02-seat-chosen.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );
await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
