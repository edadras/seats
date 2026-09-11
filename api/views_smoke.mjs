/**
 * The view from the seat, driven in Chromium.
 *
 * `SeatViewTest` holds up the storing and the refusing. What a browser adds is the only thing that
 * actually sells a ticket: a buyer who has opened a section sees what the stage looks like from it,
 * and sees the right one — the photograph of the section they are standing in, not of the room.
 *
 * The organiser's half is driven through the panel rather than the API, because "there is an
 * endpoint" and "somebody can attach a photograph on a Tuesday morning" are different claims.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node views_smoke.mjs
 */
import { chromium } from 'playwright';
import { EMBED_ORIGIN, openASection, seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/views-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const settle = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const night = await seatedEvent( BASE, 'views-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

/* A one-pixel photograph. Nothing leaves this machine: the address is intercepted below. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
);

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];
const desk = await ( await browser.newContext( { viewport: { width: 1500, height: 1150 } } ) ).newPage();

desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

/*
 * The panel shows the organiser the picture as they paste the address, so this side of the check
 * needs the address to answer too — otherwise the only thing proved is that a made-up hostname does
 * not resolve.
 */
await desk.route( 'https://cdn.example/**', ( route ) =>
	route.fulfill( { status: 200, contentType: 'image/png', body: PIXEL } ) );

console.log( 'The sections of a chart, offered a photograph each' );

const sections = ( await api( 'GET', '/v1/seat-maps/' + night.seat_map_id + '/views' ) ).body.data;

check( 'every section of the chart is a place to attach one', sections.length >= 2,
	sections.map( ( one ) => one.name ).join( ', ' ) );
check( 'and none of them has one yet', sections.every( ( one ) => null === one.url ) );

await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );
await desk.click( 'nav button[data-view=maps]' );
await desk.waitForSelector( `[data-map-views="${ night.seat_map_id }"]`, { timeout: 20000 } );

console.log( 'The organiser attaches one' );
await desk.click( `[data-map-views="${ night.seat_map_id }"]` );
await desk.waitForSelector( '[data-view-row]', { timeout: 20000 } );

const first = sections[ 0 ].section_key;

check( 'the chart’s own sections are listed to attach to',
	( await desk.locator( '[data-view-row]' ).count() ) === sections.length );

await desk.fill( `[data-view-url="${ first }"]`, `https://cdn.example/${ first }.png` );
await desk.fill( `[data-view-caption="${ first }"]`, 'Row F, centre' );
await settle( 300 );
await desk.screenshot( { path: `${ SHOTS }/01-attaching.png`, fullPage: true } );
await desk.click( '.modal button[type=submit]' );
await settle( 1200 );

const saved = ( await api( 'GET', '/v1/seat-maps/' + night.seat_map_id + '/views' ) ).body.data;
const savedFirst = saved.find( ( one ) => one.section_key === first );

check( 'and it is kept against that section', `https://cdn.example/${ first }.png` === savedFirst.url,
	String( savedFirst.url ) );
check( 'with its caption', 'Row F, centre' === savedFirst.caption );
check( 'and no other section gained one',
	saved.filter( ( one ) => one.url ).length === 1 );

/*
 * The rest of the room, through the API.
 *
 * A buyer below opens whichever section the picker offers first, and that is the picker's business
 * rather than this check's: with every section carrying its own photograph, whichever one is opened
 * proves the pairing instead of the ordering.
 */
await api( 'PUT', '/v1/seat-maps/' + night.seat_map_id + '/views', {
	views: saved.map( ( one ) => ( {
		section_key: one.section_key,
		url: `https://cdn.example/${ one.section_key }.png`,
		caption: `From ${ one.section_key }`,
	} ) ),
} );

console.log( 'A buyer opens a section' );
const guest = await ( await browser.newContext( { viewport: { width: 1280, height: 1100 } } ) ).newPage();

guest.on( 'pageerror', ( e ) => errors.push( e.message ) );
guest.on( 'console', ( m ) => { if ( 'error' === m.type() && ! m.text().includes( '404' ) ) errors.push( m.text() ); } );

let asked = [];

await guest.route( 'https://cdn.example/**', async ( route ) => {
	asked.push( route.request().url() );
	await route.fulfill( { status: 200, contentType: 'image/png', body: PIXEL } );
} );

await guest.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );

check( 'the plan of the room shows no photograph, because a room is not a seat',
	0 === await guest.locator( '.seatmap-widget__view' ).count() );

check( 'and nothing has been fetched for one yet', 0 === asked.length );

const entered = await openASection( guest );

check( 'a section opens', entered );

await guest.waitForSelector( '.seatmap-widget__view img', { timeout: 15000 } );

const opened = await guest.evaluate( () => {
	const widget = document.querySelector( '.seatmap-widget' ).seatmapWidget;
	const block = widget.currentBlock();
	const figure = document.querySelector( '.seatmap-widget__view' );

	return {
		key: block ? block.sectionKey : null,
		name: block ? block.name : null,
		src: figure.querySelector( 'img' ).getAttribute( 'src' ),
		alt: figure.querySelector( 'img' ).getAttribute( 'alt' ),
		caption: figure.querySelector( 'figcaption' ).textContent,
		href: figure.querySelector( 'a' ).getAttribute( 'href' ),
		rel: figure.querySelector( 'a' ).getAttribute( 'rel' ),
	};
} );

check( 'the photograph shown is that section’s own',
	opened.src === `https://cdn.example/${ opened.key }.png`, `${ opened.key } → ${ opened.src }` );
check( 'and it was actually fetched and drawn',
	asked.includes( `https://cdn.example/${ opened.key }.png` ) );
check( 'its caption is the venue’s own words', `From ${ opened.key }` === opened.caption,
	opened.caption );
check( 'somebody who cannot see it is told which part of the room it is of',
	new RegExp( opened.name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) ).test( opened.alt ), opened.alt );
check( 'and it opens full size without handing the venue’s page away',
	opened.href === opened.src && /noopener/.test( opened.rel ) );

await guest.screenshot( { path: `${ SHOTS }/02-the-view.png`, fullPage: true } );

console.log( 'And the same picture on somebody else’s website' );
const embedded = await ( await fetch( `${ BASE }/v1/embed/events/${ night.public_id }`, {
	headers: { Accept: 'application/json', Origin: EMBED_ORIGIN },
} ) ).json();

check( 'the embed is booted with them too',
	embedded.views && embedded.views[ opened.key ]
		&& embedded.views[ opened.key ].url === `https://cdn.example/${ opened.key }.png`,
	Object.keys( embedded.views || {} ).join( ', ' ) );

console.log( 'A picture taken away is a picture gone' );
await api( 'PUT', '/v1/seat-maps/' + night.seat_map_id + '/views', {
	views: saved.map( ( one ) => ( { section_key: one.section_key, url: '', caption: '' } ) ),
} );

asked = [];
await guest.goto( `${ SITE }/events/${ night.public_id }`, { waitUntil: 'networkidle' } );
await openASection( guest );
await settle( 800 );

check( 'no empty frame is left behind',
	0 === await guest.locator( '.seatmap-widget__view' ).count() );
check( 'and nothing is fetched for it', 0 === asked.length );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL SEAT VIEW CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
