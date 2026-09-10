/**
 * The room the designer draws and the room a buyer is shown are the same room.
 *
 * `shared/hall-3d/hall3d.js` is one file with three copies, and the copies are checked elsewhere.
 * What is checked here is the arithmetic itself, against numbers written down by hand — because a
 * rake is the one part of this that nobody can eyeball: a balcony two units too low still looks
 * like a balcony, and the seat somebody bought because the plan said they would see over the row
 * in front is the one that finds out.
 *
 *   node tools/hall3d-check.mjs
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire( import.meta.url );
const root = path.dirname( fileURLToPath( new URL( '.', import.meta.url ) ) );
const Hall3D = require( path.join( root, 'shared/hall-3d/hall3d.js' ) );

let failures = 0;

const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const near = ( a, b, tolerance = 0.001 ) => Math.abs( a - b ) <= tolerance;

/*
 * A hall anybody can check on paper.
 *
 * The stage is at (0, 0). The stalls run from 100 to 400 away from it on a 6% rake; the balcony
 * starts 500 away, 200 up, on a 12% rake. So the front stalls chair is on the floor, the back one
 * is 18 up, the front balcony chair is at 200 and one 100 further back is at 212.
 */
const chart = {
	view3d: {
		enabled: true,
		rake: 6,
		stage: { height: 24, depth: 120, width: 300 },
		sections: {
			stalls: { base: 0, rake: 6 },
			balcony: { base: 200, rake: 12, skirt: true },
		},
	},
};

const settings = Hall3D.settings( chart );

check( 'the settings are read as they were written',
	settings.enabled && settings.rake === 6 && settings.stage.height === 24 );

check( 'a block with no rake of its own inherits the hall’s',
	Hall3D.forSection( settings, 'boxes' ).rake === 6 &&
	Hall3D.forSection( settings, 'boxes' ).base === 0 );

const seats = [
	{ x: 0, y: 100, blockKey: 'stalls', key: 'stalls-front' },
	{ x: 0, y: 400, blockKey: 'stalls', key: 'stalls-back' },
	{ x: 0, y: 500, blockKey: 'balcony', key: 'balcony-front' },
	{ x: 0, y: 600, blockKey: 'balcony', key: 'balcony-back' },
];

const scene = Hall3D.build( {
	settings,
	focal: { x: 0, y: 0 },
	bounds: { x: -200, y: 0, width: 400, height: 700 },
	seats,
	blocks: [
		{ key: 'stalls', name: 'Stalls', outline: [ [ -100, 100 ], [ 100, 100 ], [ 100, 400 ], [ -100, 400 ] ] },
		{ key: 'balcony', name: 'Balcony', outline: [ [ -100, 500 ], [ 100, 500 ], [ 100, 600 ], [ -100, 600 ] ] },
	],
} );

const height = ( key ) => scene.seats.filter( ( chair ) => chair.seat.key === key )[ 0 ].z;

check( 'the front of the stalls is on the floor', near( height( 'stalls-front' ), 0 ),
	String( height( 'stalls-front' ) ) );
// 300 units of run at 6% is 18 up. Not "about 18": the number a drawing would give.
check( 'the back of the stalls is exactly its rake', near( height( 'stalls-back' ), 18 ),
	String( height( 'stalls-back' ) ) );
check( 'the balcony starts at its own height, not at the stage’s distance',
	near( height( 'balcony-front' ), 200 ), String( height( 'balcony-front' ) ) );
check( 'and rakes from its own front row', near( height( 'balcony-back' ), 212 ),
	String( height( 'balcony-back' ) ) );

/*
 * The platform is raked by the same rule as the chairs on it, from the same starting line.
 *
 * Its corners are not its chairs: the front corner of a rectangle drawn round a block sits further
 * from the stage than the middle of the front row, and is therefore a little higher. That is the
 * floor being a floor rather than a plate, and the numbers are pinned so it stays one — √(100² +
 * 100²) − 100 at 6% is 2.49, and √(100² + 400²) − 100 at 6% is 18.74.
 */
check( 'the platform under a block is raked with it',
	near( scene.platforms[ 0 ].points[ 0 ][ 2 ], 2.485, 0.01 ) &&
	near( scene.platforms[ 0 ].points[ 2 ][ 2 ], 18.74, 0.01 ) &&
	scene.platforms[ 0 ].points[ 0 ][ 2 ] < scene.platforms[ 0 ].points[ 2 ][ 2 ],
	scene.platforms[ 0 ].points.map( ( point ) => point[ 2 ].toFixed( 2 ) ).join( ', ' ) );

check( 'a raised block gets a wall under it and a block on the floor does not',
	scene.platforms[ 1 ].skirt === true && scene.platforms[ 0 ].skirt === false );

check( 'the stage is a box in front of the seats', !! scene.stage && scene.stage.height === 24 &&
	scene.stage.points.length === 4 );

/*
 * The camera.
 *
 * Pinned rather than described: the projection is four lines of trigonometry that every future
 * change to this file will be tempted to "tidy", and a room that renders slightly differently in
 * the designer from the picker is the bug this whole file exists to prevent.
 */
const camera = Hall3D.camera( scene, { yaw: 0, pitch: 0.6 } );

camera.distance = 1000;
camera.target = { x: 0, y: 350, z: 0 };

const middle = camera.project( 0, 350, 0, 800, 600 );

check( 'the point the camera is aimed at lands in the middle of the frame',
	near( middle.x, 400, 0.001 ) && near( middle.y, 300, 0.001 ),
	`${ middle.x.toFixed( 3 ) }, ${ middle.y.toFixed( 3 ) }` );

const back = camera.project( 0, 600, 0, 800, 600 );
const front = camera.project( 0, 100, 0, 800, 600 );

check( 'what is further away is higher up the frame and smaller',
	back.y < middle.y && front.y > middle.y && back.scale < front.scale,
	`back ${ back.y.toFixed( 1 ) }, front ${ front.y.toFixed( 1 ) }` );

// Straight overhead: the plan, seen from directly above, is the plan again.
const overhead = Hall3D.camera( scene, { yaw: 0, pitch: 1.45 } );

overhead.distance = 1000;
overhead.target = { x: 0, y: 350, z: 0 };

const a = overhead.project( -100, 350, 0, 800, 600 );
const b = overhead.project( 100, 350, 0, 800, 600 );

check( 'from overhead the room is symmetrical about its own middle',
	near( a.x - 400, -( b.x - 400 ), 0.001 ), `${ a.x.toFixed( 2 ) }, ${ b.x.toFixed( 2 ) }` );

const twice = camera.project( 0, 350, 0, 800, 600 );

// A thousand units in front of a camera standing a thousand units back is behind it.
check( 'a point behind the camera is refused rather than drawn inside out',
	null === camera.project( 0, -1000, 0, 800, 600 ) );

check( 'and the same point projects the same way twice',
	near( camera.project( 0, 350, 0, 800, 600 ).x, twice.x ) );

/*
 * The opening view is from behind the audience, looking at the stage.
 *
 * Not an aesthetic preference: a room that opened looking at the back of the last row would be
 * answering a question nobody asked. The stage in this hall is at the origin and the seats run away
 * from it along +y, so the camera belongs at +y looking back — half a turn.
 */
const opening = Hall3D.camera( scene, {} );

check( 'the room opens from behind the audience, looking at the stage',
	near( Math.abs( opening.yaw ), Math.PI, 0.001 ), opening.yaw.toFixed( 3 ) );

const stageOnScreen = opening.project( 0, 0, 0, 800, 600 );
const backRowOnScreen = opening.project( 0, 600, 0, 800, 600 );

check( 'so the stage is the far thing and the back row the near one',
	stageOnScreen.depth > backRowOnScreen.depth,
	`${ stageOnScreen.depth.toFixed( 0 ) } vs ${ backRowOnScreen.depth.toFixed( 0 ) }` );

/* The chair under the pointer, from the frame that has just been drawn. */
scene.seats.forEach( ( chair ) => {
	chair.screen = camera.project( chair.x, chair.y, chair.z, 800, 600 );
} );

const aimedAt = Hall3D.seatAt( scene, scene.seats[ 1 ].screen, 12 );

check( 'the pointer finds the chair it is over', aimedAt && 'stalls-back' === aimedAt.key,
	aimedAt ? aimedAt.key : 'nothing' );
check( 'and finds nothing where there is nothing',
	null === Hall3D.seatAt( scene, { x: 5, y: 5 }, 12 ) );

/* A hall nobody has set up is flat, and says so rather than guessing. */
const flat = Hall3D.settings( {} );

check( 'an unconfigured chart is off, not wrong', ! flat.enabled && flat.rake === 6 );

console.log( '' );
console.log( failures
	? `${ failures } FAILED`
	: 'THE ROOM IS THE SAME ROOM ON BOTH SIDES' );

process.exit( failures ? 1 : 0 );
