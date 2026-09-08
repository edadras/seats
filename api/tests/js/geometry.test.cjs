/**
 * Geometry model tests.
 *
 * The editor scripts are plain browser scripts, and this project's package.json sets
 * "type": "module", so `require()` would misread them as ESM. Loading the source and evaluating it
 * the way a <script> tag does keeps the test honest about how the file is actually consumed.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

function load( file ) {
	const source = fs.readFileSync( path.join( __dirname, '../../public/editor/js', file ), 'utf8' );
	const sandbox = { module: { exports: {} } };

	new Function( 'module', 'window', source )( sandbox.module, undefined );

	return sandbox.module.exports;
}

const Geometry = load( 'geometry.js' );

test( 'row names follow the venue convention past Z', () => {
	assert.equal( Geometry.indexToLetters( 0 ), 'A' );
	assert.equal( Geometry.indexToLetters( 25 ), 'Z' );
	assert.equal( Geometry.indexToLetters( 26 ), 'AA' );
	assert.equal( Geometry.indexToLetters( 27 ), 'AB' );
	assert.equal( Geometry.indexToLetters( 51 ), 'AZ' );
	assert.equal( Geometry.indexToLetters( 52 ), 'BA' );
} );

test( 'straight rows lay out a full block', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );

	Geometry.addStraightRows( section, { rows: 4, seatsPerRow: 10, x: 100, y: 100, zoneKey: 'standard' } );

	assert.equal( Geometry.seatCount( geometry ), 40 );
	assert.deepEqual( section.rows.map( ( r ) => r.name ), [ 'A', 'B', 'C', 'D' ] );
	assert.equal( section.rows[ 0 ].seats[ 0 ].label, '1' );
	assert.equal( section.rows[ 0 ].seats[ 9 ].label, '10' );
	assert.equal( section.rows[ 0 ].seats.every( ( s ) => s.zone_key === 'standard' ), true );

	// Rows step down the canvas; seats step across it.
	assert.equal( section.rows[ 1 ].seats[ 0 ].y > section.rows[ 0 ].seats[ 0 ].y, true );
	assert.equal( section.rows[ 0 ].seats[ 1 ].x > section.rows[ 0 ].seats[ 0 ].x, true );
} );

test( 'curved rows keep neighbouring seats evenly spaced as rows get further back', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Circle' );

	Geometry.addCurvedRows( section, {
		rows: 3,
		seatsPerRow: 10,
		centerX: 600,
		centerY: 700,
		innerRadius: 200,
		seatGap: 30,
	} );

	const spacing = ( row ) => {
		const a = row.seats[ 0 ];
		const b = row.seats[ 1 ];

		return Math.hypot( a.x - b.x, a.y - b.y );
	};

	const front = spacing( section.rows[ 0 ] );
	const back = spacing( section.rows[ 2 ] );

	// This is the property a constant *angular* step would break: the back row would fan out.
	assert.ok( Math.abs( front - back ) < 1, `front ${ front } vs back ${ back }` );
	assert.ok( Math.abs( front - 30 ) < 1, `expected ~30 units apart, got ${ front }` );
} );

test( 'curved seats face the stage', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Circle' );

	Geometry.addCurvedRows( section, { rows: 1, seatsPerRow: 5, centerX: 600, centerY: 700 } );

	const seats = section.rows[ 0 ].seats;

	assert.ok( seats[ 0 ].rotation < 0, 'seats left of centre angle one way' );
	assert.ok( seats[ 4 ].rotation > 0, 'seats right of centre angle the other' );
	assert.ok( Math.abs( seats[ 2 ].rotation ) < 0.001, 'the middle seat faces straight ahead' );
} );

test( 'seat keys stay unique inside a row', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );
	const row = Geometry.createRow( section, 'A' );

	Geometry.createSeat( row, { label: '1', x: 0, y: 0 } );
	Geometry.createSeat( row, { label: '1', x: 30, y: 0 } );

	assert.notEqual( row.seats[ 0 ].key, row.seats[ 1 ].key );
} );

test( 'renumbering changes labels but never keys', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );

	Geometry.addStraightRows( section, { rows: 1, seatsPerRow: 5, x: 100, y: 100 } );

	const row = section.rows[ 0 ];
	const keysBefore = row.seats.map( ( s ) => s.key );

	Geometry.renumberRow( row, 'rtl' );

	// The point of the whole stable-key design: a seat that has been sold keeps its identity even
	// when the organiser flips how the row counts.
	assert.deepEqual( row.seats.map( ( s ) => s.key ), keysBefore );
	assert.deepEqual(
		row.seats.slice().sort( ( a, b ) => a.x - b.x ).map( ( s ) => s.label ),
		[ '5', '4', '3', '2', '1' ]
	);
} );

test( 'duplicated seats are new chairs, not aliases', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );

	Geometry.addStraightRows( section, { rows: 1, seatsPerRow: 2, x: 100, y: 100 } );

	const keys = section.rows[ 0 ].seats.map( ( s ) => `${ section.key }/${ section.rows[ 0 ].key }/${ s.key }` );
	const created = Geometry.duplicateSeats( geometry, keys, 20, 20 );

	assert.equal( created.length, 2 );
	assert.equal( Geometry.seatCount( geometry ), 4 );

	const allKeys = section.rows[ 0 ].seats.map( ( s ) => s.key );
	assert.equal( new Set( allKeys ).size, 4, 'every seat has its own key' );
} );

test( 'moving and deleting act only on the selection', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );

	Geometry.addStraightRows( section, { rows: 1, seatsPerRow: 3, x: 100, y: 100 } );

	const row = section.rows[ 0 ];
	const target = `${ section.key }/${ row.key }/${ row.seats[ 0 ].key }`;
	const originalX = row.seats[ 1 ].x;

	Geometry.moveSeats( geometry, [ target ], 50, 0 );

	assert.equal( row.seats[ 0 ].x, 150 );
	assert.equal( row.seats[ 1 ].x, originalX, 'unselected seats stay put' );

	Geometry.deleteSeats( geometry, [ target ] );
	assert.equal( Geometry.seatCount( geometry ), 2 );
} );

test( 'deleting the last seat of a row removes the empty row', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );

	Geometry.addStraightRows( section, { rows: 2, seatsPerRow: 1, x: 100, y: 100 } );

	const keys = [ `${ section.key }/${ section.rows[ 0 ].key }/${ section.rows[ 0 ].seats[ 0 ].key }` ];

	Geometry.deleteSeats( geometry, keys );

	assert.equal( section.rows.length, 1, 'an empty row would publish as a row with no seats' );
} );

test( 'align uses the mean, not the first seat', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );
	const row = Geometry.createRow( section, 'A' );

	Geometry.createSeat( row, { label: '1', x: 100, y: 100 } );
	Geometry.createSeat( row, { label: '2', x: 130, y: 110 } );
	Geometry.createSeat( row, { label: '3', x: 160, y: 120 } );

	const keys = row.seats.map( ( s ) => `${ section.key }/${ row.key }/${ s.key }` );

	Geometry.align( geometry, keys, 'y' );

	// Mean of 100, 110, 120 — aligning to the first seat would snap everything to 100 and shift
	// the whole row upward.
	assert.deepEqual( row.seats.map( ( s ) => s.y ), [ 110, 110, 110 ] );
} );

test( 'distribute spaces a selection evenly between its extremes', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );
	const row = Geometry.createRow( section, 'A' );

	[ 0, 5, 7, 100 ].forEach( ( x, i ) => Geometry.createSeat( row, { label: String( i ), x: x, y: 0 } ) );

	const keys = row.seats.map( ( s ) => `${ section.key }/${ row.key }/${ s.key }` );

	Geometry.distribute( geometry, keys, 'x' );

	assert.deepEqual(
		row.seats.slice().sort( ( a, b ) => a.x - b.x ).map( ( s ) => s.x ),
		[ 0, 33.33, 66.67, 100 ]
	);
} );

test( 'validation mirrors the server rules', () => {
	const geometry = Geometry.empty();

	assert.equal( Geometry.validate( geometry ).valid, false, 'an empty map cannot be published' );

	const section = Geometry.addSection( geometry, 'Stalls' );
	Geometry.addStraightRows( section, { rows: 2, seatsPerRow: 5, x: 100, y: 100 } );

	const clean = Geometry.validate( geometry );
	assert.equal( clean.valid, true, JSON.stringify( clean.errors ) );
	assert.equal( clean.seat_count, 10 );

	// Off-canvas is an error: the seat could never be clicked.
	section.rows[ 0 ].seats[ 0 ].x = 99999;
	assert.equal( Geometry.validate( geometry ).valid, false );
	assert.ok( Geometry.validate( geometry ).errors.some( ( e ) => e.code === 'seat_off_canvas' ) );
} );

test( 'overlapping seats warn without blocking a publish', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Stalls' );
	const row = Geometry.createRow( section, 'A' );

	Geometry.createSeat( row, { label: '1', x: 100, y: 100 } );
	Geometry.createSeat( row, { label: '2', x: 102, y: 100 } );

	const report = Geometry.validate( geometry );

	assert.equal( report.valid, true, 'a bench or companion seat is legitimate' );
	assert.ok( report.warnings.some( ( w ) => w.code === 'seats_overlap' ) );
} );

test( 'overlap detection stays fast on a large map', () => {
	const geometry = Geometry.empty();
	const section = Geometry.addSection( geometry, 'Arena' );

	Geometry.addStraightRows( section, { rows: 100, seatsPerRow: 100, x: 20, y: 20, seatGap: 11, rowGap: 11 } );
	geometry.canvas = { width: 2000, height: 2000 };

	assert.equal( Geometry.seatCount( geometry ), 10000 );

	const started = Date.now();
	const report = Geometry.validate( geometry );
	const elapsed = Date.now() - started;

	assert.equal( report.seat_count, 10000 );
	// A pairwise scan would be 50 million comparisons here and take far longer than this.
	assert.ok( elapsed < 2000, `validation took ${ elapsed }ms` );
} );

test( 'snapping rounds to the grid', () => {
	assert.equal( Geometry.snap( 103, 10 ), 100 );
	assert.equal( Geometry.snap( 106, 10 ), 110 );
	assert.equal( Geometry.snap( 103, 0 ), 103, 'a zero grid means no snapping' );
} );

test( 'undo and redo restore whole snapshots', () => {
	const { History } = load( 'editor.js' );
	const history = new History( 5 );

	const first = { sections: [ { key: 'a' } ] };
	const second = { sections: [ { key: 'a' }, { key: 'b' } ] };

	history.push( first );

	assert.equal( history.canUndo(), true );

	const undone = history.undo( second );
	assert.deepEqual( undone, first );
	assert.equal( history.canRedo(), true );

	const redone = history.redo( undone );
	assert.deepEqual( redone, second );
} );

test( 'a new edit discards the redo branch', () => {
	const { History } = load( 'editor.js' );
	const history = new History();

	history.push( { v: 1 } );
	history.undo( { v: 2 } );

	assert.equal( history.canRedo(), true );

	history.push( { v: 3 } );

	// Redoing into a branch the user has edited away from would silently discard their work.
	assert.equal( history.canRedo(), false );
} );

test( 'history is bounded so a long session cannot grow without limit', () => {
	const { History } = load( 'editor.js' );
	const history = new History( 3 );

	for ( let i = 0; i < 10; i++ ) {
		history.push( { v: i } );
	}

	assert.equal( history.past.length, 3 );
} );
