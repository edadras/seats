/**
 * Chart model tests.
 *
 * These files are browser scripts and the project's package.json sets "type": "module", so they are
 * loaded and evaluated the way a <script> tag does rather than required.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );

const sandbox = { SeatmapChart: null, SeatmapChartOps: null, SeatmapI18n: null };

function load( file ) {
	const source = fs.readFileSync( path.join( __dirname, '../../public/editor/js', file ), 'utf8' );
	const holder = { module: { exports: {} } };

	new Function( 'module', 'window', 'globalThis', source )( holder.module, sandbox, sandbox );

	return holder.module.exports;
}

/*
 * The real catalogue, not a stub.
 *
 * The chart model produces words — the publish checklist, the layer names, every refusal in
 * validate() — through the same `t()` the panel uses, so a test that stubbed it would assert
 * against strings this platform does not actually say. The English catalogue is read by PHP,
 * because these files are PHP and a second half-parser here would disagree with the real one on
 * exactly the day it mattered. It is the same trick tools/i18n-check.mjs uses, for the same reason.
 */
function englishCatalogue() {
	const dir = path.join( __dirname, '../../lang/en' );
	const messages = {};

	for ( const file of fs.readdirSync( dir ) ) {
		if ( ! file.endsWith( '.php' ) ) {
			continue;
		}

		messages[ file.replace( /\.php$/, '' ) ] = JSON.parse( execFileSync(
			'php',
			[ '-r', 'echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);', path.join( dir, file ) ],
			{ encoding: 'utf8' }
		) );
	}

	return messages;
}

// i18n.js publishes itself on the window rather than through module.exports, so loading it is
// what puts it in the sandbox.
load( 'i18n.js' );
sandbox.SeatmapI18n.messages = englishCatalogue();
sandbox.SeatmapI18n.loaded = true;

const Chart = load( 'chart.js' );
sandbox.SeatmapChart = Chart;
const Ops = load( 'chart-ops.js' );

const near = ( actual, expected, tolerance, message ) =>
	assert.ok( Math.abs( actual - expected ) < ( tolerance ?? 0.01 ), `${ message ?? '' } expected ~${ expected }, got ${ actual }` );

/* ------------------------------------------------------------------ rows and seat maths */

test( 'a straight row spaces seats by size plus spacing, centred on its anchor', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 500, y: 300, seats: 5, seatSpacing: 4, rotation: 0 } );

	const positions = Chart.rowSeatPositions( row );
	const pitch = Chart.SEAT_SIZE + 4;

	assert.equal( positions.length, 5 );
	near( positions[ 0 ].x, 500 - 2 * pitch );
	near( positions[ 4 ].x, 500 + 2 * pitch );
	positions.forEach( ( p ) => near( p.y, 300 ) );

	// The anchor is the middle of the row, so the third of five seats sits exactly on it.
	near( positions[ 2 ].x, 500 );
} );

test( 'seat spacing is the gap between seats, not the distance between centres', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 0, y: 0, seats: 2, seatSpacing: 10 } );
	const [ a, b ] = Chart.rowSeatPositions( row );

	near( Math.hypot( b.x - a.x, b.y - a.y ), Chart.SEAT_SIZE + 10 );
} );

test( 'rotation turns the whole row about its anchor', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 100, y: 100, seats: 3, seatSpacing: 0, rotation: 90 } );
	const positions = Chart.rowSeatPositions( row );

	// Rotated a quarter turn, the row runs down the canvas instead of across it.
	near( positions[ 0 ].x, 100 );
	near( positions[ 0 ].y, 100 - Chart.SEAT_SIZE );
	near( positions[ 2 ].y, 100 + Chart.SEAT_SIZE );
} );

test( 'a curved row keeps neighbouring seats evenly spaced along the arc', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 500, y: 400, seats: 11, seatSpacing: 6, curve: 30 } );
	const positions = Chart.rowSeatPositions( row );

	const gaps = [];
	for ( let i = 1; i < positions.length; i++ ) {
		gaps.push( Math.hypot( positions[ i ].x - positions[ i - 1 ].x, positions[ i ].y - positions[ i - 1 ].y ) );
	}

	const min = Math.min( ...gaps );
	const max = Math.max( ...gaps );

	// This is the property that equal-angle spacing would break: the ends would spread apart.
	assert.ok( max - min < 0.5, `gaps ranged ${ min } to ${ max }` );
} );

test( 'curve bows the row by the sagitta it names, and its sign chooses the direction', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 0, y: 0, seats: 9, seatSpacing: 0, curve: 25 } );

	const positions = Chart.rowSeatPositions( row );
	const chord = ( 9 - 1 ) * Chart.SEAT_SIZE;
	const middle = positions[ 4 ];

	// Sagitta is a percentage of the chord, measured from the line joining the two ends.
	near( Math.abs( middle.y ), 0.25 * chord, 0.5 );
	near( positions[ 0 ].y, 0, 0.5, 'the ends stay on the chord' );

	row.curve = -25;
	const flipped = Chart.rowSeatPositions( row );
	near( flipped[ 4 ].y, -middle.y, 0.5, 'a negative curve bows the other way' );
} );

test( 'seats on a curved row face the centre of the arc', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 0, y: 0, seats: 9, curve: 30 } );
	const positions = Chart.rowSeatPositions( row );

	assert.ok( positions[ 0 ].rotation * positions[ 8 ].rotation < 0, 'the two ends angle opposite ways' );
	near( positions[ 4 ].rotation, 0, 0.01, 'the middle seat faces straight ahead' );
} );

test( 'a curve approaching zero agrees with a straight row', () => {
	const chart = Chart.empty();
	const straight = Chart.rowSeatPositions( Chart.newRow( chart, { x: 0, y: 0, seats: 7, curve: 0 } ) );
	const nearlyStraight = Chart.rowSeatPositions( Chart.newRow( chart, { x: 0, y: 0, seats: 7, curve: 0.01 } ) );

	// No discontinuity as the designer drags the curve control through zero.
	straight.forEach( ( point, index ) => {
		near( nearlyStraight[ index ].x, point.x, 0.5 );
		near( nearlyStraight[ index ].y, point.y, 0.5 );
	} );
} );

test( 'changing the seat count keeps the seats that survive', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { seats: 10 } );
	const keysBefore = row.seats.slice( 0, 6 ).map( ( s ) => s.key );

	Chart.setRowSeatCount( row, 6 );

	assert.equal( row.seats.length, 6 );
	assert.deepEqual( row.seats.map( ( s ) => s.key ), keysBefore, 'shrinking must not re-key survivors' );

	Chart.setRowSeatCount( row, 9 );
	assert.equal( row.seats.length, 9 );
	assert.deepEqual( row.seats.slice( 0, 6 ).map( ( s ) => s.key ), keysBefore );
} );

test( 'renumbering changes labels and never keys', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { seats: 5 } );
	const keys = row.seats.map( ( s ) => s.key );

	Chart.renumberRow( row, 'reverse' );

	assert.deepEqual( row.seats.map( ( s ) => s.key ), keys );
	assert.deepEqual( row.seats.map( ( s ) => s.label ), [ '5', '4', '3', '2', '1' ] );

	Chart.renumberRow( row, 'odd' );
	assert.deepEqual( row.seats.map( ( s ) => s.label ), [ '1', '3', '5', '7', '9' ] );
} );

test( 'row names run past Z the way venues print them', () => {
	assert.equal( Chart.indexToLetters( 0 ), 'A' );
	assert.equal( Chart.indexToLetters( 25 ), 'Z' );
	assert.equal( Chart.indexToLetters( 26 ), 'AA' );
	assert.equal( Chart.indexToLetters( 51 ), 'AZ' );
} );

/* -------------------------------------------------------------------- tables and areas */

test( 'chairs sit evenly around a round table', () => {
	const chart = Chart.empty();
	const table = Chart.newTable( chart, 'T1', { x: 400, y: 400, shape: 'round', width: 120, height: 120, seats: 8 } );
	const positions = Chart.tableSeatPositions( table );

	assert.equal( positions.length, 8 );

	const radii = positions.map( ( p ) => Math.hypot( p.x - 400, p.y - 400 ) );
	near( Math.max( ...radii ) - Math.min( ...radii ), 0, 0.1, 'all chairs are the same distance out' );

	const gaps = positions.map( ( p, i ) => {
		const q = positions[ ( i + 1 ) % positions.length ];
		return Math.hypot( q.x - p.x, q.y - p.y );
	} );
	near( Math.max( ...gaps ) - Math.min( ...gaps ), 0, 0.1, 'and evenly spread' );
} );

test( 'chairs walk the perimeter of a rectangular table without bunching at corners', () => {
	const chart = Chart.empty();
	const table = Chart.newTable( chart, 'T2', { shape: 'rectangular', width: 200, height: 100, seats: 12 } );
	const positions = Chart.tableSeatPositions( table );

	assert.equal( positions.length, 12 );

	const unique = new Set( positions.map( ( p ) => `${ p.x },${ p.y }` ) );
	assert.equal( unique.size, 12, 'no two chairs share a spot' );
} );

test( 'a table booked whole counts as one place, by the seat counts as many', () => {
	const chart = Chart.empty();

	chart.floors[ 0 ].objects.push( Chart.newTable( chart, 'Gala 1', { seats: 10, bookAs: 'table' } ) );
	assert.equal( Chart.placeCount( chart ), 1 );

	chart.floors[ 0 ].objects.push( Chart.newTable( chart, 'Club 1', { seats: 4, bookAs: 'seat' } ) );
	assert.equal( Chart.placeCount( chart ), 5 );
} );

test( 'a general admission area contributes its capacity', () => {
	const chart = Chart.empty();

	chart.floors[ 0 ].objects.push( Chart.newArea( chart, 'Standing', { places: 250 } ) );
	chart.floors[ 0 ].objects.push( Chart.newRow( chart, { seats: 10 } ) );

	assert.equal( Chart.placeCount( chart ), 260 );
} );

test( 'empty placeholders in a row are not bookable places', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { seats: 6 } );

	// A gap left for a camera position or a pillar.
	row.seats[ 2 ].type = 'empty';
	chart.floors[ 0 ].objects.push( row );

	assert.equal( Chart.placeCount( chart ), 5 );
} );

/* ------------------------------------------------------------------------- categories */

test( 'removing a category clears it from everything that used it', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'Premium', '#b8860b' );
	const row = Chart.newRow( chart, { seats: 3, categoryKey: category.key } );

	row.seats[ 0 ].categoryKey = category.key;
	chart.floors[ 0 ].objects.push( row );

	Chart.removeCategory( chart, category.key );

	// Leaving dangling references would keep the chart failing "all objects are categorized"
	// against a category that no longer exists.
	assert.equal( chart.categories.length, 0 );
	assert.equal( row.categoryKey, null );
	assert.equal( row.seats[ 0 ].categoryKey, null );
} );

test( 'a seat category beats its row, which beats its section', () => {
	const chart = Chart.empty();
	const premium = Chart.addCategory( chart, 'Premium', '#b8860b' );
	const standard = Chart.addCategory( chart, 'Standard', '#2d6cdf' );
	const stalls = Chart.addCategory( chart, 'Stalls', '#3f9c6d' );

	const section = Chart.newSection( chart, 'Stalls', null, { categoryKey: stalls.key } );
	const row = Chart.newRow( chart, { seats: 3, categoryKey: standard.key } );

	row.seats[ 0 ].categoryKey = premium.key;
	section.objects.push( row );

	assert.equal( Chart.effectiveCategory( chart, row.seats[ 0 ], row, section ).key, premium.key );
	assert.equal( Chart.effectiveCategory( chart, row.seats[ 1 ], row, section ).key, standard.key );
	assert.equal( Chart.effectiveCategory( chart, {}, {}, section ).key, stalls.key );
} );

/* ------------------------------------------------------------------------- validation */

test( 'the checklist matches the panel and reports what is missing', () => {
	const chart = Chart.empty();
	const report = Chart.validate( chart );

	assert.deepEqual(
		report.checks.map( ( c ) => c.label ),
		[
			'No duplicate objects',
			'All objects are labeled',
			'All objects are categorized',
			'One category per object type',
			'Focal point is set',
		]
	);

	assert.equal( report.valid, false, 'an empty chart has no places to sell' );
	assert.equal( report.checks.find( ( c ) => c.code === 'focal_point' ).ok, false );
} );

test( 'a complete chart passes every check', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'Stalls', '#2d6cdf' );

	for ( let i = 0; i < 3; i++ ) {
		chart.floors[ 0 ].objects.push(
			Chart.newRow( chart, { x: 400, y: 300 + i * 30, seats: 8, label: Chart.indexToLetters( i ), categoryKey: category.key } )
		);
	}

	Chart.setFocalPoint( chart, 400, 100 );

	const report = Chart.validate( chart );

	assert.ok( report.valid, JSON.stringify( report.errors ) );
	assert.equal( report.places, 24 );
	assert.ok( report.checks.every( ( c ) => c.ok ), JSON.stringify( report.checks ) );
} );

test( 'two rows with the same label in the same section is a duplicate', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'Stalls', '#2d6cdf' );

	chart.floors[ 0 ].objects.push( Chart.newRow( chart, { seats: 4, label: 'A', categoryKey: category.key } ) );
	chart.floors[ 0 ].objects.push( Chart.newRow( chart, { seats: 4, label: 'A', categoryKey: category.key } ) );

	const report = Chart.validate( chart );

	assert.equal( report.checks.find( ( c ) => c.code === 'no_duplicate_objects' ).ok, false );
} );

test( 'the same row label in two different sections is fine', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'Seats', '#2d6cdf' );

	[ 'Stalls', 'Circle' ].forEach( ( name ) => {
		const section = Chart.newSection( chart, name );
		section.objects.push( Chart.newRow( chart, { seats: 4, label: 'A', categoryKey: category.key } ) );
		chart.floors[ 0 ].objects.push( section );
	} );

	Chart.setFocalPoint( chart, 100, 100 );

	// "Stalls A" and "Circle A" are unambiguous on a ticket, and every venue has both.
	const report = Chart.validate( chart );
	assert.ok( report.checks.find( ( c ) => c.code === 'no_duplicate_objects' ).ok, JSON.stringify( report.errors ) );
} );

test( 'one category used for both seats and standing is flagged', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'One price', '#2d6cdf' );

	chart.floors[ 0 ].objects.push( Chart.newRow( chart, { seats: 4, categoryKey: category.key } ) );
	chart.floors[ 0 ].objects.push( Chart.newArea( chart, 'Standing', { places: 50, categoryKey: category.key } ) );

	const report = Chart.validate( chart );

	assert.equal( report.checks.find( ( c ) => c.code === 'one_category_per_type' ).ok, false );
	assert.ok( report.valid, 'it is a warning, not a blocker — some venues really do price them alike' );
} );

test( 'validation stays fast on a large chart', () => {
	const chart = Chart.empty();
	const category = Chart.addCategory( chart, 'Bowl', '#2d6cdf' );
	chart.floors[ 0 ].canvas = { width: 6000, height: 6000 };

	for ( let i = 0; i < 200; i++ ) {
		chart.floors[ 0 ].objects.push(
			Chart.newRow( chart, { x: 3000, y: 100 + i * 26, seats: 100, label: 'R' + i, categoryKey: category.key } )
		);
	}

	Chart.setFocalPoint( chart, 3000, 50 );

	const started = Date.now();
	const report = Chart.validate( chart );
	const elapsed = Date.now() - started;

	assert.equal( report.places, 20000 );
	assert.ok( elapsed < 3000, `validation took ${ elapsed }ms` );
} );

/* -------------------------------------------------------------------------- transforms */

test( 'mirroring is a true reflection: each chair changes ends but keeps its identity', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 300, y: 200, seats: 5, seatSpacing: 0 } );

	const before = Chart.rowSeatPositions( row );
	const firstSeat = row.seats[ 0 ];

	assert.ok( before[ 0 ].x < before[ 4 ].x, 'seat 1 starts on the left' );

	Ops.mirror( [ row ], 'horizontal' );

	const after = Chart.rowSeatPositions( row );

	// Seat 1 is the same chair with the same key — it has moved to the other end, which is what
	// reflecting the row means.
	assert.strictEqual( row.seats[ 0 ], firstSeat );
	assert.equal( row.seats[ 0 ].label, '1' );
	assert.ok( after[ 0 ].x > after[ 4 ].x, 'and now sits on the right' );

	// Reflection about the selection's own centre leaves the row where it was, end for end.
	near( after[ 0 ].x, before[ 4 ].x, 0.5 );
	near( after[ 4 ].x, before[ 0 ].x, 0.5 );
	after.forEach( ( p, i ) => near( p.y, before[ i ].y, 0.5, 'a horizontal mirror does not move it vertically' ) );
} );

test( 'mirroring flips a curve so a mirrored half-auditorium bows the right way', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { x: 300, y: 200, seats: 9, curve: 30, rotation: 20 } );

	Ops.mirror( [ row ], 'horizontal' );

	assert.equal( row.curve, -30 );
	assert.equal( row.rotation, 160 );
} );

test( 'moving a section carries everything inside it', () => {
	const chart = Chart.empty();
	const section = Chart.newSection( chart, 'Stalls', Chart.rectanglePolygon( 100, 100, 200, 200 ) );
	const row = Chart.newRow( chart, { x: 150, y: 150, seats: 4 } );

	section.objects.push( row );
	Ops.move( section, 50, 25 );

	assert.deepEqual( section.polygon[ 0 ], [ 150, 125 ] );
	assert.equal( row.x, 200 );
	assert.equal( row.y, 175 );
} );

test( 'duplicating gives every copied seat a fresh key', () => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, { seats: 4 } );
	chart.floors[ 0 ].objects.push( row );

	const copies = Ops.duplicate( chart, [ row ], 40, 40 );

	assert.equal( copies.length, 1 );
	assert.notEqual( copies[ 0 ].key, row.key );

	const originalKeys = row.seats.map( ( s ) => s.key );
	copies[ 0 ].seats.forEach( ( seat ) => {
		assert.ok( ! originalKeys.includes( seat.key ), 'a pasted chair is a new chair' );
	} );
} );

test( 'align uses the mean of the selection', () => {
	const chart = Chart.empty();
	const rows = [ 100, 110, 120 ].map( ( y ) => Chart.newRow( chart, { x: 300, y: y, seats: 3 } ) );

	Ops.align( rows, 'middle' );

	rows.forEach( ( row ) => near( Ops.center( row ).y, 110, 0.6 ) );
} );

test( 'distribute spaces a selection evenly', () => {
	const chart = Chart.empty();
	const rows = [ 0, 5, 7, 300 ].map( ( x ) => Chart.newRow( chart, { x: x, y: 100, seats: 1 } ) );

	Ops.distribute( rows, 'x' );

	const centers = rows.map( ( r ) => Ops.center( r ).x ).sort( ( a, b ) => a - b );

	near( centers[ 1 ] - centers[ 0 ], 100, 1 );
	near( centers[ 2 ] - centers[ 1 ], 100, 1 );
} );

test( 'a point inside a concave section polygon is detected', () => {
	// Arena sections are frequently L- or octagon-shaped, so a bounding-box test is not enough.
	const polygon = [ [ 0, 0 ], [ 100, 0 ], [ 100, 100 ], [ 60, 100 ], [ 60, 40 ], [ 0, 40 ] ];

	assert.equal( Ops.pointInPolygon( { x: 20, y: 20 }, polygon ), true );
	assert.equal( Ops.pointInPolygon( { x: 80, y: 80 }, polygon ), true );
	assert.equal( Ops.pointInPolygon( { x: 20, y: 80 }, polygon ), false, 'the notch is outside' );
} );

/* --------------------------------------------------------------------------- migration */

test( 'a v1 geometry opens as a v2 chart with its seat identities intact', () => {
	const v1 = {
		canvas: { width: 1000, height: 800 },
		sections: [ {
			key: 'stalls',
			name: 'Stalls',
			rows: [ {
				key: 'A',
				name: 'Row A',
				seats: [
					{ key: 'A1', label: '1', x: 100, y: 200, zone_key: 'standard' },
					{ key: 'A2', label: '2', x: 122, y: 200, zone_key: 'standard' },
					{ key: 'A3', label: '3', x: 144, y: 200, zone_key: 'standard' },
				],
			} ],
		} ],
		shapes: [ { kind: 'stage', x: 100, y: 40, width: 300, height: 40, label: 'Stage' } ],
		texts: [],
	};

	const chart = Ops.migrate( v1 );

	assert.equal( chart.version, 2 );
	assert.equal( Chart.placeCount( chart ), 3 );

	const row = chart.floors[ 0 ].objects.find( ( o ) => o.type === 'row' );

	// Orders point at published v1 seats, so the composite keys must survive conversion.
	assert.deepEqual( row.seats.map( ( s ) => s.key ), [ 'stalls/A/A1', 'stalls/A/A2', 'stalls/A/A3' ] );
	assert.deepEqual( row.seats.map( ( s ) => s.label ), [ '1', '2', '3' ] );

	// The anchor, rotation and spacing are recovered from the old per-seat coordinates.
	near( row.x, 122 );
	near( row.y, 200 );
	near( row.rotation, 0 );
	near( row.seatSpacing, 22 - Chart.SEAT_SIZE );

	const positions = Chart.rowSeatPositions( row );
	near( positions[ 0 ].x, 100, 0.5, 'and reproduce the original layout' );
	near( positions[ 2 ].x, 144, 0.5 );

	assert.equal( chart.categories.length, 1, 'v1 price zones become categories' );
	assert.ok( chart.floors[ 0 ].objects.some( ( o ) => o.type === 'shape' && o.kind === 'stage' ) );
} );

test( 'migration recovers the angle of a rotated v1 row', () => {
	const v1 = {
		canvas: { width: 1000, height: 1000 },
		sections: [ { key: 's', name: 'S', rows: [ { key: 'A', name: 'A', seats: [
			{ key: '1', label: '1', x: 100, y: 100 },
			{ key: '2', label: '2', x: 100, y: 122 },
		] } ] } ],
	};

	const row = Ops.migrate( v1 ).floors[ 0 ].objects[ 0 ];

	near( row.rotation, 90, 0.5 );
} );

test( 'migrating an already-current chart leaves it alone', () => {
	const chart = Chart.empty();
	assert.strictEqual( Ops.migrate( chart ), chart );
} );
