/**
 * Everything on a plan can be reached by a pointer.
 *
 * This exists because of a bug that nobody would have found by reading the code. Seats in a row sit
 * about 24 units apart; a seat is 18 across; and the catchment used for "which chair did they
 * click" was 0.8 of a seat's *width* — 14.4 either side of every centre. Those circles overlapped,
 * so there was no point anywhere along a row that did not belong to a chair, and the row itself —
 * a strip exactly one seat tall — could never be selected at all. Not awkward: impossible.
 *
 * Arithmetic, not opinion, so it is checked here rather than in a browser: a catchment wider than
 * half the distance between two seats swallows whatever is underneath, every time, on every chart.
 *
 *   node tools/designer-reach-check.mjs
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.dirname( fileURLToPath( new URL( '.', import.meta.url ) ) );

/*
 * Loaded the way a browser loads them, not the way Node would.
 *
 * `api/package.json` says `"type": "module"`, so every `.js` under it is ESM as far as Node is
 * concerned — and these two are plain browser scripts that hang themselves off a global. Evaluating
 * them against one shared object is both simpler than working around that and closer to the truth:
 * it is exactly what the designer's page does.
 */
const sandbox = {};

const load = ( file ) => {
	const source = readFileSync( path.join( root, 'api/public/editor/js', file ), 'utf8' );
	const module = { exports: {} };

	new Function( 'module', 'exports', 'window', source )( module, module.exports, sandbox );

	return module.exports;
};

const Chart = load( 'chart.js' );

sandbox.SeatmapChart = Chart;

const Ops = load( 'chart-ops.js' );

let failures = 0;

const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

/* A row of ten chairs, straight, at the origin — the simplest thing a designer draws. */
const row = {
	key: 'row-a',
	type: 'row',
	x: 100,
	y: 100,
	rotation: 0,
	curve: 0,
	seatSpacing: 6,
	labeling: { label: 'A', enabled: true, position: 'both' },
	seats: Array.from( { length: 10 }, ( _, i ) => ( {
		key: 's' + i, type: 'seat', label: String( i + 1 ),
	} ) ),
};

const container = { objects: [ row ] };
const positions = Chart.rowSeatPositions( row );
const pitch = Math.hypot( positions[ 1 ].x - positions[ 0 ].x, positions[ 1 ].y - positions[ 0 ].y );

console.log( 'A chair is the circle you can see' );

check( 'the seats are a seat and a spacing apart', Math.abs( pitch - ( Chart.SEAT_SIZE + 6 ) ) < 0.001,
	`${ pitch.toFixed( 2 ) } units` );

/*
 * The rule the bug broke, measured rather than restated.
 *
 * How far from a chair's centre does the chair still answer? Asked of the real function by walking
 * outwards from a seat until it stops claiming the point — because a constant copied into a check
 * is a check that agrees with whatever the code says, including when the code is wrong.
 */
let catchment = 0;

for ( let d = 0; d <= pitch; d += 0.1 ) {
	if ( Ops.seatAt( container, { x: positions[ 3 ].x, y: positions[ 3 ].y + d } ) ) {
		catchment = d;
	}
}

check( 'and its catchment is narrower than half the gap to the next one', catchment < pitch / 2,
	`reaches ${ catchment.toFixed( 1 ) } units; half the gap is ${ ( pitch / 2 ).toFixed( 2 ) }` );

const onASeat = Ops.seatAt( container, positions[ 3 ] );

check( 'clicking a chair finds that chair', onASeat && 's3' === onASeat.seat.key,
	onASeat ? onASeat.seat.key : 'nothing' );

const between = {
	x: ( positions[ 3 ].x + positions[ 4 ].x ) / 2,
	y: ( positions[ 3 ].y + positions[ 4 ].y ) / 2,
};

check( 'clicking between two chairs finds neither', null === Ops.seatAt( container, between ) );

check( 'and lands on the row that owns them', Ops.hitTest( row, between ) );

console.log( 'A row is reached by its name' );

const labels = Chart.rowLabelPositions( row, positions );

check( 'a row that shows its label offers two places to grab it', 2 === labels.length,
	`${ labels.length }` );

check( 'the label sits one pitch beyond the end chair',
	Math.abs( Math.hypot( labels[ 0 ].x - positions[ 0 ].x, labels[ 0 ].y - positions[ 0 ].y ) - pitch ) < 0.001 );

check( 'clicking it selects the row', row === Ops.rowLabelAt( container, labels[ 0 ] ) );

check( 'and the label is outside every chair’s catchment',
	null === Ops.seatAt( container, labels[ 0 ] ) && null === Ops.seatAt( container, labels[ 1 ] ) );

check( 'a click nowhere near it selects nothing',
	null === Ops.rowLabelAt( container, { x: labels[ 0 ].x, y: labels[ 0 ].y + 60 } ) );

/*
 * A row with its labels turned off has nothing to click, and must not answer for a point that
 * happens to be where a label would have been.
 */
const quiet = Object.assign( {}, row, { labeling: { label: 'A', enabled: false, position: 'both' } } );

check( 'a row with no label shows none',
	0 === Chart.rowLabelPositions( quiet, positions ).length );

check( 'and does not answer where one would have been',
	null === Ops.rowLabelAt( { objects: [ quiet ] }, labels[ 0 ] ) );

console.log( 'A rotated row keeps all of it' );

const tilted = Object.assign( {}, row, { rotation: 30 } );
const tiltedPositions = Chart.rowSeatPositions( tilted );
const tiltedLabels = Chart.rowLabelPositions( tilted, tiltedPositions );

check( 'its label follows the rotation',
	Math.abs( Math.hypot(
		tiltedLabels[ 0 ].x - tiltedPositions[ 0 ].x,
		tiltedLabels[ 0 ].y - tiltedPositions[ 0 ].y
	) - pitch ) < 0.001 );

check( 'and is still what a click there finds',
	tilted === Ops.rowLabelAt( { objects: [ tilted ] }, tiltedLabels[ 1 ] ) );

console.log( '\n' + '-'.repeat( 68 ) );
console.log( failures ? `${ failures } FAILED` : 'EVERYTHING ON THE PLAN CAN BE REACHED' );
process.exit( failures ? 1 : 0 );
