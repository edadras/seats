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

// The seated chart, which is the one the event above is sold from.
await page.locator( 'tr', { hasText: 'Main auditorium' } ).first().locator( 'button' ).first().click();
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

check( 'the events screen says which night is behind its own chart',
	await page.locator( '[data-rechart]' ).count() > 0,
	( await page.locator( '[data-rechart]' ).count() ) + ' offered' );

await page.locator( '[data-rechart]' ).first().click();
await page.waitForTimeout( 2500 );

check( 'and takes it up when asked', 0 === await page.locator( '[data-rechart]' ).count() );

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

check( 'the buyer’s room is the room the designer set up',
	room && room.seats > 0 && room.stage && room.climb > 0, JSON.stringify( room ) );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/02-buyer.png` } );

// A chair chosen from inside the room reaches the basket like any other.
const box = await page.locator( '.seatmap-widget canvas' ).boundingBox();

/*
 * Aimed at a chair the room actually drew, rather than at a spot on the canvas.
 *
 * The last frame left every chair's screen position on it — which is also how the picker answers a
 * click — so this asks where one is and clicks there. Guessing a fraction of the canvas is how a
 * check like this passes in one hall and fails in the next.
 */
const target = await page.evaluate( () => {
	const widget = [ ...document.querySelectorAll( '*' ) ].map( ( n ) => n.seatmapWidget ).filter( Boolean )[ 0 ];
	const chair = widget.hall.seats.filter( ( seat ) => seat.screen &&
		'available' === seat.seat.record.state )[ 0 ];

	return chair ? { x: chair.screen.x, y: chair.screen.y, label: chair.seat.record.label } : null;
} );

check( 'the room knows where its chairs are on screen', !! target,
	target ? `seat ${ target.label } at ${ Math.round( target.x ) }, ${ Math.round( target.y ) }` : 'none' );

await page.mouse.click( box.x + target.x, box.y + target.y );
await page.waitForTimeout( 900 );

const basket = await page.locator( '.seatmap-widget__summary' ).innerText().catch( () => '' );

check( 'a chair clicked in the room lands in the basket', /\d/.test( basket ) &&
	await page.locator( '.seatmap-widget__submit:not([disabled])' ).count() > 0,
	basket.replace( /\n/g, ' | ' ).slice( 0, 90 ) );

await page.locator( '.seatmap-widget' ).screenshot( { path: `${ SHOTS }/03-chosen.png` } );

// And back to the plan, which is still the plan.
await page.locator( '.seatmap-widget__zoom button[aria-label="Back to the plan"]' ).click();
await page.waitForTimeout( 900 );

check( 'and the plan is still there to go back to',
	1 === await page.locator( '.seatmap-widget__zoom button[aria-label="See the hall in 3D"]' ).count() );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
