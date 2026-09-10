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
		const x0 = 400 + block * 1100 + row * 26;
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
