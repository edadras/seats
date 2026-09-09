/**
 * The seat picker, on anybody's own website.
 *
 * Three lines of HTML and no server:
 *
 *     <div data-seatmap-event="evt_xxxxxxxx"></div>
 *     <script src="https://api.example.com/embed/v1/seatmap.js" async></script>
 *
 * What it does is fetch what the picker needs from the platform's public embed API — the event,
 * its chart, the words in the reader's language — start the same picker the hosted sites and the
 * WordPress plugin run, and hand the buyer to the organiser's own checkout to pay.
 *
 * Three things are deliberate.
 *
 *   **No key.** There is nothing to protect here: this reads what a venue already shows publicly
 *   and holds seats, which is rate-limited per address. A key in a page's source is not a key.
 *
 *   **No payment.** The page this runs on never sees money, a card, or a price it could argue
 *   with. Seats are held by the API, priced by the API, and paid for on the organiser's hosted
 *   checkout — so a website that pastes this in takes on no obligations it cannot meet.
 *
 *   **No dependencies and no build.** It is one file of plain browser JavaScript, because the
 *   people pasting it in have a page, not a toolchain.
 */
( function () {
	'use strict';

	var SCRIPT = document.currentScript;

	/** Where this file was served from is where the API is. Nobody should have to configure that. */
	function apiBase() {
		var src = ( SCRIPT && SCRIPT.src ) || '';
		var at = src.indexOf( '/embed/v1/seatmap.js' );

		return at === -1 ? '' : src.slice( 0, at );
	}

	var API = ( SCRIPT && SCRIPT.getAttribute( 'data-api' ) ) || apiBase();
	var loaded = {};

	function load( kind, url ) {
		if ( loaded[ url ] ) {
			return loaded[ url ];
		}

		return loaded[ url ] = new Promise( function ( resolve, reject ) {
			var node;

			if ( 'css' === kind ) {
				node = document.createElement( 'link' );
				node.rel = 'stylesheet';
				node.href = url;
			} else {
				node = document.createElement( 'script' );
				node.src = url;
				node.async = false;
			}

			node.onload = resolve;
			node.onerror = function () { reject( new Error( url ) ); };
			document.head.appendChild( node );
		} );
	}

	function json( url ) {
		return fetch( url, { credentials: 'omit' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( url + ' → ' + response.status );
			}

			return response.json();
		} );
	}

	/**
	 * Which language to ask for.
	 *
	 * The page says, or the document does, or the browser does. Whatever comes back, the API
	 * decides what it actually has — asking for `pt` gets the platform's fallback rather than an
	 * empty picker.
	 */
	function localeFor( container ) {
		return container.getAttribute( 'data-seatmap-locale' ) ||
			document.documentElement.getAttribute( 'lang' ) ||
			( navigator.language || 'en' ).slice( 0, 2 );
	}

	/**
	 * A name for this browser, kept for as long as the tab lives.
	 *
	 * The public API has no session to know somebody by, and it needs one thing: that the same
	 * browser asking twice is recognised as the same browser, so a second attempt extends a hold
	 * rather than fighting it.
	 */
	function sessionId() {
		var key = 'seatmap_embed_session';
		var id = null;

		try {
			id = window.sessionStorage.getItem( key );
		} catch ( error ) {
			// A browser with storage switched off still gets a picker; it gets a new name each
			// page load, which is the same thing a first visit is.
		}

		if ( ! id ) {
			id = 'embed-' + Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );

			try {
				window.sessionStorage.setItem( key, id );
			} catch ( error ) {}
		}

		return id;
	}

	function fail( container, message ) {
		container.textContent = message;
		container.setAttribute( 'data-seatmap-state', 'error' );
	}

	function mount( container, index ) {
		var eventId = container.getAttribute( 'data-seatmap-event' );

		if ( ! eventId || container.getAttribute( 'data-seatmap-state' ) ) {
			return;
		}

		container.setAttribute( 'data-seatmap-state', 'loading' );
		container.classList.add( 'seatmap-widget' );

		if ( ! container.id ) {
			container.id = 'seatmap-embed-' + index;
		}

		var locale = localeFor( container );
		var events = API + '/v1/embed/events/' + encodeURIComponent( eventId );

		Promise.all( [
			json( events ),
			json( events + '/seat-map' ),
			json( API + '/v1/i18n/' + encodeURIComponent( locale ) ),
			load( 'css', API + '/site/css/widget.css' ),
		] ).then( function ( results ) {
			var event = results[ 0 ];
			var map = results[ 1 ];
			var strings = results[ 2 ];

			if ( event.error || map.error ) {
				throw new Error( ( event.error || map.error ).message );
			}

			window.seatmapBoot = window.seatmapBoot || [];
			window.seatmapBoot.push( {
				containerId: container.id,
				eventPublicId: eventId,
				// Whole URLs rather than a base with routes assumed: the widget is also embedded
				// in shops that put their own routes in front of this API, and it must not have to
				// know which kind of host it is in.
				availabilityUrl: events + '/availability',
				holdUrl: events + '/holds',
				sessionId: sessionId(),
				currency: {
					code: event.currency,
					symbol: event.currency,
					decimals: 'number' === typeof event.currency_decimals ? event.currency_decimals : 2,
					position: 'left_space',
				},
				locale: strings.locale || locale,
				isRtl: 'rtl' === strings.dir,
				event: {
					zones: event.zones || [],
					max_seats_per_order: event.max_seats_per_order,
				},
				geometry: map.geometry || { floors: [] },
				i18n: ( strings.messages && strings.messages.site && strings.messages.site.picker ) || {},
			} );

			container.setAttribute( 'data-seatmap-state', 'ready' );
			container.textContent = '';

			return load( 'js', API + '/site/js/widget.js' );
		} ).catch( function ( error ) {
			// Said in the page rather than only in the console: somebody pasted this in and needs
			// to know it did not work, and why, without opening developer tools.
			fail( container, 'Seatmap: ' + ( error && error.message ? error.message : 'could not load this event.' ) );
		} );
	}

	function start() {
		var containers = document.querySelectorAll( '[data-seatmap-event]' );

		Array.prototype.forEach.call( containers, mount );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
