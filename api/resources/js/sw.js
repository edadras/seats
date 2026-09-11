/**
 * The service worker every hosted site installs.
 *
 * Its job is not speed. It is the door: somebody who bought tickets last month arrives at a venue
 * where three hundred other people are all on the same cell, opens the page their confirmation
 * email points at, and it has to render — QR and all — without asking the network for permission.
 * Everything else here exists to make that one case reliable.
 *
 * Three rules, and they are worth stating because a cache on a shop is how a sold-out night keeps
 * selling:
 *
 *   1. **A page is fetched from the network first, always.** Prices, availability and sale windows
 *      change by the minute; a cached page is only ever a fallback for a network that failed.
 *   2. **An order page is kept.** It is the buyer's own ticket on the buyer's own device, it does
 *      not change after the sale, and it is the reason any of this is here.
 *   3. **Nothing about a session is stored.** The checkout, the store endpoints, the gateway and
 *      the account page are not touched at all — an account page cached on a borrowed phone is
 *      somebody's order history left behind.
 *
 * `SEATMAP_BUILD` and `SEATMAP_SHELL` are prepended by the route that serves this file (see
 * App\Http\Controllers\Site\WebAppController): the build stamp changes when the stylesheets do,
 * which is what makes the browser treat this as a new worker and throw the old shell away, and the
 * shell list carries the theme the site is actually wearing rather than all six.
 */
/* eslint-env serviceworker */
( function () {
	'use strict';

	var BUILD = self.SEATMAP_BUILD || 'dev';
	var SHELL = self.SEATMAP_SHELL || [];
	var SHELL_CACHE = 'seatmap-shell-' + BUILD;

	/*
	 * Deliberately not stamped with the build.
	 *
	 * A ticket outlives a stylesheet. If this were versioned, shipping a CSS change the week of a
	 * concert would quietly throw away every ticket page anybody had opened — which is precisely
	 * the week it matters.
	 */
	var TICKETS_CACHE = 'seatmap-tickets';

	/** Paths that are about one person's session, and are therefore never stored. */
	var PRIVATE = [ '/checkout', '/_store/', '/pay/', '/account', '/season/', '/queue' ];

	function isPrivate( path ) {
		for ( var i = 0; i < PRIVATE.length; i++ ) {
			if ( path === PRIVATE[ i ] || 0 === path.indexOf( PRIVATE[ i ] ) ) {
				return true;
			}
		}

		return false;
	}

	/** A buyer's own booking: `/order/ABC123` and the printable sheet under it. */
	function isTicket( path ) {
		return 0 === path.indexOf( '/order/' );
	}

	/** Something this site serves that never changes without its address changing. */
	function isShellAsset( path ) {
		return 0 === path.indexOf( '/site/' ) || 0 === path.indexOf( '/app-icon-' );
	}

	function storable( response ) {
		return response
			&& 200 === response.status
			&& 'basic' === response.type
			&& -1 === ( response.headers.get( 'Cache-Control' ) || '' ).indexOf( 'no-store' );
	}

	self.addEventListener( 'install', function ( event ) {
		event.waitUntil(
			caches.open( SHELL_CACHE ).then( function ( cache ) {
				// Individually rather than addAll: one asset that 404s would otherwise fail the
				// whole install and leave the site with no worker at all.
				return Promise.all( SHELL.map( function ( url ) {
					return cache.add( new Request( url, { cache: 'reload' } ) ).catch( function () {} );
				} ) );
			} ).then( function () {
				return self.skipWaiting();
			} )
		);
	} );

	self.addEventListener( 'activate', function ( event ) {
		event.waitUntil(
			caches.keys().then( function ( names ) {
				return Promise.all( names.map( function ( name ) {
					if ( name === SHELL_CACHE || name === TICKETS_CACHE ) {
						return null;
					}

					return caches.delete( name );
				} ) );
			} ).then( function () {
				return self.clients.claim();
			} )
		);
	} );

	self.addEventListener( 'fetch', function ( event ) {
		var request = event.request;
		var url;

		try {
			url = new URL( request.url );
		} catch ( error ) {
			return;
		}

		if ( url.origin !== self.location.origin ) {
			return;
		}

		/*
		 * Signing out takes the tickets with it.
		 *
		 * A shared phone is a real thing, and "sign out" that leaves a stranger's seat numbers a
		 * back-button away is not a sign-out. The request itself is left alone; only the cache goes.
		 */
		if ( 'POST' === request.method && 0 === url.pathname.indexOf( '/account/sign-out' ) ) {
			event.waitUntil( caches.delete( TICKETS_CACHE ) );

			return;
		}

		if ( 'GET' !== request.method || isPrivate( url.pathname ) ) {
			return;
		}

		if ( isTicket( url.pathname ) ) {
			event.respondWith( keepTicket( request ) );

			return;
		}

		if ( isShellAsset( url.pathname ) ) {
			event.respondWith( fromShell( request ) );

			return;
		}

		if ( 'navigate' === request.mode ) {
			event.respondWith( pageFirstFromNetwork( request ) );
		}
	} );

	/**
	 * A ticket: shown from the network when there is one, and from the cache the instant there is
	 * not. Refreshed in the background either way, so a seat changed at the box office this
	 * morning is right the next time the page is opened with signal.
	 */
	function keepTicket( request ) {
		return caches.open( TICKETS_CACHE ).then( function ( cache ) {
			var fresh = fetch( request ).then( function ( response ) {
				if ( storable( response ) ) {
					cache.put( request, response.clone() );
				}

				return response;
			} );

			return fresh.catch( function () {
				return cache.match( request ).then( function ( held ) {
					return held || offline();
				} );
			} );
		} );
	}

	function fromShell( request ) {
		return caches.open( SHELL_CACHE ).then( function ( cache ) {
			return cache.match( request ).then( function ( held ) {
				var fresh = fetch( request ).then( function ( response ) {
					if ( storable( response ) ) {
						cache.put( request, response.clone() );
					}

					return response;
				} );

				return held || fresh;
			} );
		} );
	}

	/** Everything else a person navigates to. The network decides; the cache only catches. */
	function pageFirstFromNetwork( request ) {
		return fetch( request ).then( function ( response ) {
			if ( storable( response ) ) {
				var copy = response.clone();

				caches.open( SHELL_CACHE ).then( function ( cache ) { cache.put( request, copy ); } );
			}

			return response;
		} ).catch( function () {
			return caches.match( request ).then( function ( held ) {
				return held || offline();
			} );
		} );
	}

	function offline() {
		return caches.match( '/offline' ).then( function ( page ) {
			return page || new Response( '', { status: 504, statusText: 'Offline' } );
		} );
	}
}() );
