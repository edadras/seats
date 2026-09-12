/**
 * The page modules a venue's own site is built from, driven in Chromium.
 *
 * `SiteModulesTest` holds up what the server does: an address resolved to a provider and an id, a
 * slide with no picture dropped, a button that says what the event says. None of that is the part a
 * browser is needed for. What is:
 *
 *   - the slideshow works before its script does, and better after it;
 *   - the film is *not* loaded from anybody until a visitor presses play, and then is;
 *   - the terms fold and unfold;
 *   - the buy button leads to the night it is about;
 *   - and an organiser can actually build all of it in the panel.
 *
 * The pictures and the player are answered by the browser itself rather than fetched: a check that
 * needed YouTube to be up would be a check that fails on a train.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node modules_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SITE = process.env.SEATMAP_SITE || 'http://northgate.localhost:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/modules-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'modules-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json() } ) );

/** A picture, drawn rather than downloaded. */
const picture = ( label, from, to ) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360">
	<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
		<stop offset="0" stop-color="${ from }"/><stop offset="1" stop-color="${ to }"/>
	</linearGradient></defs>
	<rect width="640" height="360" fill="url(#g)"/>
	<text x="320" y="196" text-anchor="middle" font-family="sans-serif" font-size="54"
		fill="#ffffff">${ label }</text>
</svg>`;

const PICTURES = {
	'https://pictures.test/stalls.svg': picture( 'The stalls', '#2b2f6b', '#7d4fd1' ),
	'https://pictures.test/circle.svg': picture( 'The circle', '#0f5c54', '#3fae8f' ),
	'https://pictures.test/foyer.svg': picture( 'The foyer', '#6b2b3a', '#d17d4f' ),
	'https://pictures.test/still.svg': picture( 'The trailer', '#1b1d24', '#4a4fdc' ),
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const errors = [];

/** The same pictures, and the same stand-in player, for every page this check opens. */
const serveStandIns = async ( context ) => {
	await context.route( 'https://pictures.test/**', ( route ) => route.fulfill( {
		status: 200,
		contentType: 'image/svg+xml',
		body: PICTURES[ route.request().url() ] || picture( '?', '#444', '#888' ),
	} ) );

	// Whatever the page builds for the embed, answered locally: the claim worth checking is that
	// the frame appears only on a press and carries the address the server resolved, not that a
	// third party is reachable from wherever this runs.
	await context.route( 'https://www.youtube-nocookie.com/**', ( route ) => route.fulfill( {
		status: 200,
		contentType: 'text/html',
		body: '<!doctype html><title>stand-in player</title><body style="background:#000"></body>',
	} ) );
};

console.log( 'The organiser builds a page out of the modules' );

const site = ( await api( 'GET', '/v1/sites' ) ).body.data[ 0 ];
const made = await api( 'POST', `/v1/sites/${ site.id }/pages`, {
	title: 'The show',
	slug: 'the-show',
} );

check( 'a page is made for it', 201 === made.status, JSON.stringify( made.body ).slice( 0, 120 ) );

const pageId = made.body.id;

const written = await api( 'PATCH', `/v1/sites/${ site.id }/pages/${ pageId }`, {
	blocks: [
		{
			id: 'slides', type: 'slideshow', title: 'The room', height: 'tall', autoplay: false,
			items: [
				{ url: 'https://pictures.test/stalls.svg', alt: 'The stalls', caption: 'From the circle' },
				{ url: 'https://pictures.test/circle.svg', alt: 'The circle' },
				{ url: 'https://pictures.test/foyer.svg', alt: 'The foyer', href: '/visiting' },
			],
		},
		{
			id: 'facts', type: 'specs', title: 'The details',
			items: [
				{ label: 'Doors', value: '19:00' },
				{ label: 'Running time', value: '2h 20m, with an interval' },
				{ label: 'Age limit', value: '14 and over' },
			],
		},
		{
			id: 'film', type: 'video', title: 'The trailer',
			url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			poster: 'https://pictures.test/still.svg',
			caption: 'Two minutes from last year’s run.',
		},
		{
			id: 'sell', type: 'buy', event_public_id: night.public_id,
			title: 'Tickets', label: 'Choose a seat', note: 'Doors at seven.',
		},
		{
			id: 'small', type: 'terms', title: 'Conditions of sale',
			text: 'Tickets are not exchangeable. Latecomers are seated at a suitable break.',
			collapsed: true,
		},
	],
} );

const kinds = ( written.body.blocks || [] ).map( ( block ) => block.type );

check( 'every module survives the sanitiser', 200 === written.status &&
	[ 'slideshow', 'specs', 'video', 'buy', 'terms' ].every( ( type ) => kinds.includes( type ) ),
	kinds.join( ', ' ) );

const film = ( written.body.blocks || [] ).filter( ( block ) => 'video' === block.type )[ 0 ] || {};

check( 'and the film is stored as a provider and an id rather than an address',
	'youtube' === film.provider && 'dQw4w9WgXcQ' === film.key, `${ film.provider }:${ film.key }` );

await api( 'POST', `/v1/sites/${ site.id }/pages/${ pageId }/publish` );

console.log( 'A visitor reads it' );
const guestBox = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
await serveStandIns( guestBox );

const guest = await guestBox.newPage();
guest.on( 'pageerror', ( e ) => errors.push( e.message ) );
guest.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await guest.goto( `${ SITE }/the-show`, { waitUntil: 'networkidle' } );

check( 'the slideshow holds every picture', 3 === await guest.locator( '[data-slide]' ).count() );
check( 'it is a scroller before it is a carousel', await guest.evaluate(
	() => {
		const track = document.querySelector( '[data-slides-track]' );

		return track.scrollWidth > track.clientWidth;
	}
) );
check( 'and the script adds the arrows and a dot for each picture',
	1 === await guest.locator( '.slides__arrow--on' ).count() &&
	3 === await guest.locator( '.slides__dot' ).count() );

const firstDot = await guest.locator( '.slides__dot' ).first().getAttribute( 'aria-current' );

await guest.click( '.slides__arrow--on' );
await guest.waitForTimeout( 700 );

check( 'pressing onwards moves it', 'true' === firstDot &&
	'true' === await guest.locator( '.slides__dot' ).nth( 1 ).getAttribute( 'aria-current' ) );

check( 'the facts are a list somebody can scan for one row',
	3 === await guest.locator( '.specs__row' ).count() );

check( 'the film is a still, not a player',
	0 === await guest.locator( 'iframe' ).count() &&
	1 === await guest.locator( '.video__play' ).count() );

await guest.click( '.video__play' );
await guest.waitForSelector( '.video__frame', { timeout: 10000 } );

check( 'and it becomes a player when somebody asks for one',
	( await guest.locator( '.video__frame' ).getAttribute( 'src' ) || '' )
		.startsWith( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ),
	await guest.locator( '.video__frame' ).getAttribute( 'src' ) );

check( 'the terms are folded', ! await guest.locator( 'details.terms' ).evaluate( ( el ) => el.open ) );
await guest.click( '.terms__summary' );
check( 'and unfold where somebody looks for them',
	await guest.locator( 'details.terms' ).evaluate( ( el ) => el.open ) );

const buy = guest.locator( '.buy' );

check( 'the buy strip quotes the price the event quotes',
	/\d/.test( await buy.locator( '.buy__price' ).innerText() ),
	await buy.locator( '.buy__price' ).innerText() );

await guest.screenshot( { path: `${ SHOTS }/01-the-page.png`, fullPage: true } );

await buy.locator( '.button' ).click();
await guest.waitForLoadState( 'networkidle' );

check( 'and its button leads to that night', guest.url().includes( `/events/${ night.public_id }` ),
	guest.url() );
check( 'where the seats actually are', await guest.locator( '.seatmap-widget, .unlock, .notice' ).first().isVisible() );

console.log( 'And it is all editable in the panel' );
const deskBox = await browser.newContext( { viewport: { width: 1500, height: 1150 } } );
await serveStandIns( deskBox );

const desk = await deskBox.newPage();
desk.on( 'pageerror', ( e ) => errors.push( e.message ) );
desk.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

await desk.goto( BASE, { waitUntil: 'networkidle' } );
await desk.fill( 'input[name=email]', 'owner@northgate.test' );
await desk.fill( 'input[name=password]', 'password' );
await desk.click( '#login button[type=submit]' );
await desk.waitForSelector( '.sidebar' );
await desk.click( 'nav button[data-view=sites]' );
await desk.waitForSelector( '[data-site]', { timeout: 20000 } );
await desk.waitForTimeout( 300 );
await desk.click( '[data-site]' );
await desk.waitForSelector( '#site-nav', { timeout: 20000 } );
await desk.waitForTimeout( 400 );

await desk.locator( '#site-nav button', { hasText: 'The show' } ).first().click();
await desk.waitForSelector( '#blocks .block', { timeout: 20000 } );

const names = await desk.locator( '#blocks .block__type' ).allInnerTexts();

check( 'the page is shown as the modules it is made of', 5 === names.length, names.join( ', ' ) );
check( 'each module is named in the panel’s own language',
	names.every( ( name ) => name.trim().length > 1 && ! name.includes( '.' ) ), names.join( ', ' ) );

// Every type the picker offers, including the five this exercise added.
const offered = await desk.locator( '#block-add button' ).allInnerTexts();

check( 'and every one of them can be added to another page', offered.length >= 16,
	`${ offered.length } modules` );

const slideshow = desk.locator( '#blocks .block' ).first();
const rows = await slideshow.locator( '.faq-edit' ).count();

check( 'a slideshow is edited picture by picture', 3 === rows, `${ rows } pictures` );

await slideshow.locator( '.link-btn' ).last().click();
await desk.waitForTimeout( 1200 );

check( 'a fourth can be added',
	4 === await desk.locator( '#blocks .block' ).first().locator( '.faq-edit' ).count() );

/*
 * The picture is set by pasting an address, which is now one of four ways to set one.
 *
 * The box is behind its own button rather than always on the screen: most people drag a file onto
 * the field or choose one already uploaded, and a permanent URL box beside those reads as the
 * required way rather than the exception. A venue whose poster is already on their own server is
 * the exception it is there for — which is this check.
 */
const slide = desk.locator( '#blocks .block' ).first().locator( '.faq-edit' ).last();

await slide.locator( '[data-role=address]' ).click();
await slide.locator( '[data-role=url]' ).fill( 'https://pictures.test/foyer.svg' );
// Blurred rather than Entered: the field commits on `change`, and Enter inside the seat-view
// dialog would submit the dialog instead.
await slide.locator( '[data-role=url]' ).blur();
await desk.waitForTimeout( 1500 );

const reread = ( await api( 'GET', `/v1/sites/${ site.id }` ) ).body.pages
	.filter( ( p ) => p.id === pageId )[ 0 ];

check( 'and what was typed is what the server kept',
	4 === ( ( reread.blocks || [] )[ 0 ].items || [] ).length,
	JSON.stringify( ( ( reread.blocks || [] )[ 0 ].items || [] ).map( ( i ) => i.url ) ) );

await desk.screenshot( { path: `${ SHOTS }/02-the-editor.png`, fullPage: true } );

check( 'no console errors', 0 === errors.length, errors.join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nALL MODULE CHECKS PASSED' );
process.exit( failures ? 1 : 0 );
