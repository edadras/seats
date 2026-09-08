// Generates golden row/table positions from the JS model, for the PHP side to be pinned against.
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const sandbox = {};
function load( file ) {
	const src = fs.readFileSync( path.join( '/home/user/seats/api/public/editor/js', file ), 'utf8' );
	const holder = { module: { exports: {} } };
	new Function( 'module', 'window', 'globalThis', src )( holder.module, sandbox, sandbox );
	return holder.module.exports;
}
const Chart = load( 'chart.js' );

const cases = [
	{ name: 'straight', row: { x: 500, y: 300, rotation: 0, curve: 0, seatSpacing: 4, seats: 5 } },
	{ name: 'rotated', row: { x: 120, y: 640, rotation: 198, curve: 0, seatSpacing: 4, seats: 9 } },
	{ name: 'curved', row: { x: 500, y: 400, rotation: 0, curve: 30, seatSpacing: 6, seats: 11 } },
	{ name: 'curved-negative-rotated', row: { x: 300, y: 250, rotation: 45, curve: -22, seatSpacing: 2, seats: 8 } },
	{ name: 'single', row: { x: 10, y: 20, rotation: 33, curve: 40, seatSpacing: 7, seats: 1 } },
];

const tables = [
	{ name: 'round', table: { x: 400, y: 400, shape: 'round', width: 120, height: 120, rotation: 0, seats: 8 } },
	{ name: 'rect', table: { x: 250, y: 180, shape: 'rectangular', width: 200, height: 100, rotation: 15, seats: 10 } },
];

const out = { rows: {}, tables: {} };

cases.forEach( ( c ) => {
	const chart = Chart.empty();
	const row = Chart.newRow( chart, c.row );
	out.rows[ c.name ] = { input: { ...c.row }, positions: Chart.rowSeatPositions( row ) };
} );

tables.forEach( ( c ) => {
	const chart = Chart.empty();
	const table = Chart.newTable( chart, 'T', c.table );
	out.tables[ c.name ] = { input: { ...c.table }, positions: Chart.tableSeatPositions( table ) };
} );

fs.writeFileSync( '/home/user/seats/api/tests/Fixtures/row-geometry-golden.json', JSON.stringify( out, null, 2 ) + '\n' );
console.log( 'rows:', Object.keys( out.rows ).length, 'tables:', Object.keys( out.tables ).length );
