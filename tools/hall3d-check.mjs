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

/*
 * Walking about in it.
 *
 * The camera is defined by what it looks at and from how far, so where it is *standing* is
 * arithmetic rather than a stored number — and every one of these moves has to agree with that
 * one derivation. Checked on paper because none of it can be eyeballed: a camera that drifts a
 * metre each time you turn your head still looks like a camera, and the seat somebody chose
 * because the view from it looked clear is the one that finds out.
 */
console.log( 'Standing in the room, and walking about it' );

const walker = Hall3D.camera( scene, { mode: 'flight' } );

check( 'a point at the eye itself is behind the lens rather than drawn',
	null === walker.project( walker.eye().x, walker.eye().y, walker.eye().z, 800, 600 ) );

const standing = { x: 0, y: 650, z: 60 };

walker.standAt( standing );

const planted = walker.eye();

check( 'standing somewhere puts the eye exactly there',
	near( planted.x, standing.x, 0.001 ) && near( planted.y, standing.y, 0.001 ) &&
	near( planted.z, standing.z, 0.001 ),
	`${ planted.x.toFixed( 2 ) }, ${ planted.y.toFixed( 2 ) }, ${ planted.z.toFixed( 2 ) }` );

walker.look( 0.4, -0.2 );

const afterLooking = walker.eye();

check( 'turning your head does not walk you across the room',
	near( afterLooking.x, standing.x, 0.001 ) && near( afterLooking.y, standing.y, 0.001 ) &&
	near( afterLooking.z, standing.z, 0.001 ),
	`${ afterLooking.x.toFixed( 2 ) }, ${ afterLooking.y.toFixed( 2 ) }, ${ afterLooking.z.toFixed( 2 ) }` );

/* Facing the stage — yaw 0 means +y is forward — a step forward is a step towards it. */
walker.yaw = Math.PI;
walker.pitch = 0.4;
walker.standAt( standing );
walker.move( 40, 0, 0 );

const stepped = walker.eye();

check( 'a step forward is a step the way you are looking, however you are tilted',
	near( stepped.y, standing.y - 40, 0.5 ) && near( stepped.x, standing.x, 0.5 ) &&
	near( stepped.z, standing.z, 0.5 ),
	`${ stepped.x.toFixed( 1 ) }, ${ stepped.y.toFixed( 1 ) }, ${ stepped.z.toFixed( 1 ) }` );

walker.standAt( standing );
walker.move( 0, 30, 0 );

const sidestep = walker.eye();

check( 'and a sidestep is sideways, not forwards',
	near( sidestep.x, standing.x - 30, 0.5 ) && near( sidestep.y, standing.y, 0.5 ),
	`${ sidestep.x.toFixed( 1 ) }, ${ sidestep.y.toFixed( 1 ) }` );

/* The floor and the ceiling are not suggestions. */
walker.standAt( { x: 0, y: 650, z: 5 } );
walker.move( 0, 0, -500 );

check( 'nobody is flown under the floor', walker.eye().z > 0, walker.eye().z.toFixed( 1 ) );

walker.standAt( { x: 0, y: 650, z: 5 } );
walker.move( 0, 0, 5000 );

check( 'nor up through the roof', walker.eye().z <= scene.ceiling + 110,
	`${ walker.eye().z.toFixed( 0 ) } with a ceiling of ${ scene.ceiling.toFixed( 0 ) }` );

walker.standAt( { x: 0, y: 650, z: 60 } );
walker.move( 100000, 0, 0 );

check( 'and nobody ends up in the car park looking at the outside of a box',
	walker.eye().y < scene.bounds.y + scene.bounds.height + scene.bounds.height,
	walker.eye().y.toFixed( 0 ) );

/*
 * The view from a seat: the eye in the chair, looking at the stage. The front stalls chair is on
 * the floor at (0, 100), so a person sitting in it has their eye a little over a metre up and is
 * looking back along -y at a stage 100 away.
 */
const seated = Hall3D.camera( scene, { mode: 'flight' } );

check( 'sitting in a seat works', true === Hall3D.sit( scene, seated, seats[ 0 ] ) );

const inTheChair = seated.eye();

check( 'the eye is in the chair, a person above its own floor',
	near( inTheChair.x, 0, 0.5 ) && near( inTheChair.y, 100, 0.5 ) &&
	inTheChair.z > 30 && inTheChair.z < 50,
	`${ inTheChair.x.toFixed( 1 ) }, ${ inTheChair.y.toFixed( 1 ) }, ${ inTheChair.z.toFixed( 1 ) }` );

check( 'and it is looking at the stage rather than away from it',
	near( Math.abs( seated.yaw ), Math.PI, 0.01 ), seated.yaw.toFixed( 3 ) );

const onScreen = seated.project( 0, 0, 24, 800, 600 );

check( 'so the stage is in front of the camera, on the screen', !! onScreen &&
	onScreen.x > 0 && onScreen.x < 800,
	onScreen ? `${ onScreen.x.toFixed( 0 ) }, ${ onScreen.y.toFixed( 0 ) }` : 'behind the camera' );

/* Two numbers a person can picture, rather than a coordinate. */
const where = Hall3D.readout( scene, seated );

check( 'the readout is in metres from the stage and metres up',
	where.toStage > 2 && where.toStage < 4 && where.height > 1 && where.height < 1.5,
	`${ where.toStage.toFixed( 1 ) } m from the stage, ${ where.height.toFixed( 2 ) } m up` );

/* Touring is unchanged: the camera still circles what it is looking at. */
const tour = Hall3D.camera( scene, {} );
const before = tour.target.x + ',' + tour.target.y;

tour.orbit( 0.3, 0 );

check( 'touring still circles the room rather than turning on the spot',
	before === tour.target.x + ',' + tour.target.y );

/* A hall nobody has set up is flat, and says so rather than guessing. */
const flat = Hall3D.settings( {} );

check( 'an unconfigured chart is off, not wrong', ! flat.enabled && flat.rake === 6 );

console.log( '' );
console.log( failures
	? `${ failures } FAILED`
	: 'THE ROOM IS THE SAME ROOM ON BOTH SIDES' );

process.exit( failures ? 1 : 0 );
