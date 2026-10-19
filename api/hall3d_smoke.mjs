/**
 * The hall in three dimensions, driven in Chromium — set up in the designer, sold from in the room.
 *
 * `tools/hall3d-check.mjs` pins the arithmetic. What a browser adds is the two halves meeting: an
 * organiser types a stage height and a rake into the designer and watches the room change, presses
 * publish, and a buyer on the site opens the same room and clicks a chair in it. If those two ever
 * disagree about where a chair is, this is where it shows.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node hall3d_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/hall3d-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'hall3d-smoke' );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1000 } } ) ).newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'Before anybody has said what shape the room is' );
await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.seatmap-widget canvas', { timeout: 60000 } );
await page.waitForTimeout( 1500 );

// A chart nobody has set up is flat, and a flat plate is a worse answer than the plan: no button.
check( 'a chart with no room set up offers no 3D button',
	0 === await page.locator( '.seatmap-widget__zoom button[aria-label="See the hall in 3D"]' ).count() );

console.log( 'The designer sets the room up' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=maps]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );

// The seated chart, which is the one the event above is sold from. Named by what the button does
// rather than by where it sits: the row carries more than one now.
await page.locator( 'tr', { hasText: 'Main auditorium' } ).first().locator( '[data-map]' ).click();
await page.waitForSelector( '#dz-canvas', { timeout: 60000 } );
await page.waitForTimeout( 2500 );

// A published chart with no draft is read-only; forking one is how anything about it is changed.
if ( await page.locator( '#dz-readonly:visible' ).count() ) {
	await page.click( '#dz-save' );
	await page.waitForTimeout( 3000 );
}

await page.click( '#dz-3d' );
await page.waitForSelector( '#room-stage-height', { timeout: 20000 } );

check( 'the designer switches the same canvas to the room',
	await page.locator( '.designer.is-room' ).count() > 0 &&
	await page.locator( '.tools' ).isHidden() );
check( 'and the tools are the numbers that shape it',
	await page.locator( '#room-stage-height' ).isVisible() &&
	await page.locator( '#room-rake' ).isVisible() &&
	await page.locator( '.room-block' ).count() > 0,
	( await page.locator( '.room-block' ).count() ) + ' blocks' );

const flat = await page.evaluate( () => Math.round( Math.max(
	...window.__panel.hall.scene.seats.map( ( seat ) => seat.z )
) ) );

await page.fill( '#room-stage-height', '120' );
await page.waitForTimeout( 400 );
await page.fill( '#room-rake', '14' );
await page.waitForTimeout( 700 );

const raked = await page.evaluate( () => Math.round( Math.max(
	...window.__panel.hall.scene.seats.map( ( seat ) => seat.z )
) ) );

// Typed at the room, not at a form somewhere else: the back row climbs as the number is typed.
check( 'typing a rake lifts the back of the hall', raked > flat, `${ flat } → ${ raked }` );

const blockField = page.locator( '.room-block' ).first().locator( 'input' ).first();

await blockField.fill( '150' );
await page.waitForTimeout( 700 );

const raised = await page.evaluate( () => {
	const platforms = window.__panel.hall.scene.platforms;

	return { skirts: platforms.filter( ( plate ) => plate.skirt ).length };
} );

check( 'a block given a height gets a wall under it', raised.skirts > 0, JSON.stringify( raised ) );

await page.locator( '#room-enabled' ).check();
await page.waitForTimeout( 400 );
await page.screenshot( { path: `${ SHOTS }/01-designer.png` } );

await page.click( '#dz-publish' );
await page.waitForTimeout( 6000 );

/*
 * And the night takes the new chart up.
 *
 * Publishing a chart moves no event onto it: a chart republished the afternoon before a show must
 * not silently move the seats somebody has already bought. So the events screen offers the change
 * as a decision, on exactly the nights that are behind their own chart.
 */
// The designer takes the whole window; the way out is the way in.
await page.click( '#dz-close' );
await page.waitForSelector( '.sidebar', { timeout: 20000 } );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );

// By id rather than by position: the seed has more than one night, and the one this check is
// about is the one whose page the buyer opens below.
const rechart = page.locator( `[data-rechart="${ night.id }"]` );

check( 'the events screen says which night is behind its own chart',
	1 === await rechart.count(),
	( await page.locator( '[data-rechart]' ).count() ) + ' offered in all' );

await rechart.click();
await page.waitForTimeout( 2500 );

check( 'and takes it up when asked', 0 === await rechart.count() );

console.log( 'And a buyer stands in it' );
await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.seatmap-widget canvas', { timeout: 60000 } );
await page.waitForTimeout( 2000 );

const button = page.locator( '.seatmap-widget__zoom button[aria-label="See the hall in 3D"]' );

check( 'the picker now offers the room', 1 === await button.count() );

await button.click();
await page.waitForTimeout( 1800 );

const room = await page.evaluate( () => {
	const widget = [ ...document.querySelectorAll( '*' ) ].map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];

	if ( ! widget || ! widget.hall ) {
		return null;
	}

	const z = widget.hall.seats.map( ( seat ) => seat.z );

	return {
		seats: widget.hall.seats.length,
		platforms: widget.hall.platforms.length,
		stage: !! widget.hall.stage,
		climb: Math.round( Math.max( ...z ) - Math.min( ...z ) ),
	};
} );

/*
 * A point on the room, in the coordinates a mouse works in.
 *
 * The picker sits most of the way down a long page, so a fraction of the canvas's height can be a
 * point below the fold — and a wheel turned there reaches nothing. Playwright also scrolls a button
 * into view before pressing it, which moves the canvas out from under any box measured earlier. So
 * every point is asked for fresh, from the page itself, and kept inside the window.
 */
const spotOnTheRoom = ( across, down ) => page.evaluate( ( fractions ) => {
	const widget = [ ...document.querySelectorAll( '*' ) ]
		.map( ( node ) => node.seatmapWidget ).filter( Boolean )[ 0 ];
	const rect = widget.canvas.getBoundingClientRect();
	const top = Math.max( rect.top, 8 );
	const bottom = Math.min( rect.bottom, window.innerHeight - 8 );

	return {
		x: rect.left + rect.width * fractions[ 0 ],
		y: top + ( bottom - top ) * fractions[ 1 ],
	};
}, [ across, down ] );

check( 'the buyer’s room is the room the designer set up',
	room && room.seats > 0 && room.stage && room.climb > 0, JSON.stringify( room ) );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/02-buyer.png` } );

/*
 * Walking about it, which is the part a plan cannot do.
 *
 * Touring circles the hall from outside; walking stands in it at eye height and answers the only
 * question anybody actually has — what will I see from there. The keys are written on the screen
 * because nobody guesses them, and the readout says where somebody is standing in the two numbers
 * anybody can picture.
 */
const eyeOf = () => page.evaluate( () => {
	const widget = [ ...document.querySelectorAll( '*' ) ]
		.map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];
	const eye = widget.camera.eye();

	return {
		mode: widget.camera.mode,
		x: Math.round( eye.x ), y: Math.round( eye.y ), z: Math.round( eye.z ),
		where: ( document.querySelector( '.seatmap-widget__hall-where' ) || {} ).textContent || '',
	};
} );

await page.locator( '.seatmap-widget__hall-button', { hasText: 'Walk about' } ).click();
await page.waitForTimeout( 700 );

const standing = await eyeOf();

check( 'walking puts the buyer inside the hall at eye height',
	'flight' === standing.mode && standing.z > 0, JSON.stringify( standing ) );
check( 'and says where they are standing in metres',
	/\d+\s*m/.test( standing.where ) &&
	await page.locator( '.seatmap-widget__hall-keys' ).isVisible(), standing.where );

// No click first, deliberately: the keys are bound on the document, so walking works from
// wherever somebody's hands already are. A click to "focus the room" would also be a click on a
// chair, which is a different thing entirely.
await page.keyboard.press( 'w' );
await page.keyboard.press( 'w' );
await page.waitForTimeout( 400 );

const walked = await eyeOf();

check( 'a key takes a step through the room',
	Math.hypot( walked.x - standing.x, walked.y - standing.y ) > 20,
	`${ standing.x },${ standing.y } → ${ walked.x },${ walked.y }` );

await page.keyboard.press( 'e' );
await page.waitForTimeout( 300 );

const lifted = await eyeOf();

check( 'and a key rises off the floor', lifted.z > walked.z, `${ walked.z } → ${ lifted.z }` );

const middle = await spotOnTheRoom( 0.5, 0.5 );

await page.mouse.move( middle.x, middle.y );
await page.mouse.wheel( 0, -240 );
await page.waitForTimeout( 400 );

const rolled = await eyeOf();

check( 'and the wheel walks rather than zooms while inside the room',
	Math.hypot( rolled.x - lifted.x, rolled.y - lifted.y ) > 10,
	`${ lifted.x },${ lifted.y } → ${ rolled.x },${ rolled.y }` );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/04-walking.png` } );

await page.locator( '.seatmap-widget__hall-button', { hasText: 'Tour the hall' } ).click();
await page.waitForTimeout( 500 );

check( 'and touring goes back to circling it from outside',
	'tour' === ( await eyeOf() ).mode );

/*
 * A chair chosen from inside the room reaches the basket like any other — and it is aimed at a
 * chair the room actually drew, rather than at a spot on the canvas.
 *
 * The last frame left every chair's screen position on it, which is also how the picker answers a
 * click, so this asks where one is and clicks there. Guessing a fraction of the canvas is how a
 * check like this passes in one hall and fails in the next; a chair scrolled off the bottom of the
 * window is how it fails in the same hall on a smaller screen.
 */
const target = await page.evaluate( () => {
	const widget = [ ...document.querySelectorAll( '*' ) ].map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];
	const rect = widget.canvas.getBoundingClientRect();
	const chair = widget.hall.seats.filter( ( seat ) => seat.screen &&
		'available' === seat.seat.record.state &&
		rect.top + seat.screen.y > 8 && rect.top + seat.screen.y < window.innerHeight - 8 )[ 0 ];

	return chair
		? {
			x: rect.left + chair.screen.x,
			y: rect.top + chair.screen.y,
			label: chair.seat.record.label,
		}
		: null;
} );

check( 'the room knows where its chairs are on screen', !! target,
	target ? `seat ${ target.label } at ${ Math.round( target.x ) }, ${ Math.round( target.y ) }` : 'none' );

await page.mouse.click( target.x, target.y );
await page.waitForTimeout( 900 );

const basket = await page.locator( '.seatmap-widget__summary' ).innerText().catch( () => '' );

check( 'a chair clicked in the room lands in the basket', /\d/.test( basket ) &&
	await page.locator( '.seatmap-widget__submit:not([disabled])' ).count() > 0,
	basket.replace( /\n/g, ' | ' ).slice( 0, 90 ) );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/03-chosen.png` } );

/*
 * And the thing the room was built for: sitting in the seat and looking at the stage.
 *
 * Offered only once a seat has been chosen, because until then there is no chair to sit in — and
 * aimed at that chair rather than asking somebody to name a seat they are already pointing at.
 */
check( 'sitting is offered once a seat is chosen',
	await page.locator( '.seatmap-widget__hall-button', { hasText: 'Sit in this seat' } ).isVisible() );

await page.locator( '.seatmap-widget__hall-button', { hasText: 'Sit in this seat' } ).click();
await page.waitForTimeout( 700 );

const seated = await page.evaluate( () => {
	const widget = [ ...document.querySelectorAll( '*' ) ]
		.map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];
	const chair = window.SeatmapHall3D.chairOf( widget.hall, widget.selected[ 0 ] );
	const eye = widget.camera.eye();
	const canvas = widget.container.querySelector( 'canvas' );
	// What somebody in that chair is looking at — and whether it is in front of them, which is the
	// one thing sitting down has to get right.
	const looking = window.SeatmapHall3D.lookingAt( widget.hall );
	const stage = widget.camera.project(
		looking.x, looking.y, looking.z, canvas.width, canvas.height
	);

	return {
		mode: widget.camera.mode,
		// How far the eye is from the chair it is meant to be sitting in, on the floor.
		off: Math.round( Math.hypot( eye.x - chair.x, eye.y - chair.y ) ),
		above: Math.round( eye.z - chair.z ),
		stageOnScreen: !! stage && stage.x > 0 && stage.x < canvas.width,
	};
} );

check( 'and it puts the buyer in that chair, looking at the stage',
	'flight' === seated.mode && seated.off < 20 && seated.above > 0 && seated.stageOnScreen,
	JSON.stringify( seated ) );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/05-seated.png` } );

// Back out of the chair before leaving the room, so the last frame is the one the plan replaces.
await page.locator( '.seatmap-widget__hall-button', { hasText: 'Tour the hall' } ).click();
await page.waitForTimeout( 500 );

// And back to the plan, which is still the plan.
await page.locator( '.seatmap-widget__zoom button[aria-label="Back to the plan"]' ).click();
await page.waitForTimeout( 900 );

check( 'and the plan is still there to go back to',
	1 === await page.locator( '.seatmap-widget__zoom button[aria-label="See the hall in 3D"]' ).count() );

/*
 * One tick, on the night rather than on the chart.
 *
 * Everything above is a buyer who pressed a button. The tick is for the nights where the room is
 * the thing — a cinema, where nobody wants a diagram — and it decides which of the two somebody is
 * shown without pressing anything. It lives on the event because the same building is a cinema on
 * Friday and a conference on Monday, and it is offered on exactly the charts that have a room.
 */
console.log( 'And one tick decides which of the two a buyer arrives on' );
await page.goto( BASE, { waitUntil: 'networkidle' } );

// The panel is on another origin from the site, so the session may or may not still be here.
if ( await page.locator( 'input[name=email]' ).count() ) {
	await page.fill( 'input[name=email]', 'owner@northgate.test' );
	await page.fill( 'input[name=password]', 'password' );
	await page.click( '#login button[type=submit]' );
}

await page.waitForSelector( '.sidebar', { timeout: 20000 } );
await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );
await page.locator( `[data-event-edit="${ night.id }"]` ).click();
await page.waitForSelector( '#e-hall-3d', { timeout: 20000 } );

check( 'the event form offers the room on a chart that has one',
	await page.locator( '#e-hall-3d' ).isVisible() &&
	! await page.locator( '#e-hall-3d' ).isChecked() );

// Pressed the way a person does: the switch's own track, not the checkbox underneath it.
await page.locator( '.modal label:has(#e-hall-3d) .switch__track' ).click();
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 3000 );

// Asked the way an organiser would ask it: open the night again and look at the switch.
await page.waitForSelector( 'tbody tr', { timeout: 20000 } );
await page.locator( `[data-event-edit="${ night.id }"]` ).click();
await page.waitForSelector( '#e-hall-3d', { timeout: 20000 } );

check( 'and remembers the answer', await page.locator( '#e-hall-3d' ).isChecked() );

await page.keyboard.press( 'Escape' );

await page.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.seatmap-widget canvas', { timeout: 60000 } );
await page.waitForTimeout( 2500 );

check( 'a ticked night opens inside the hall without being asked',
	await page.evaluate( () => {
		const widget = [ ...document.querySelectorAll( '*' ) ]
			.map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];

		return !! ( widget && widget.threeD && widget.hall );
	} ) );
check( 'and says so in its own bar',
	await page.locator( '.seatmap-widget__hall' ).isVisible() &&
	await page.locator( '.seatmap-widget__hall-button', { hasText: 'Walk about' } ).count() > 0 );
check( 'and the plan is still one press away',
	1 === await page.locator(
		'.seatmap-widget__zoom button[aria-label="Back to the plan"]'
	).count() );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/06-ticked.png` } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
