/**
 * The venue's own app, shown to the venue.
 *
 * Every hosted site has been installable for a while: a manifest, a tile drawn from the brand
 * colours, and a service worker that keeps a ticket readable with no signal. All of it was visible
 * only to buyers. The organiser had no screen anywhere that showed them the tile their customers
 * are offered, no way to see whether a browser would offer it at all, and no way to change the one
 * piece of text that ends up on somebody's home screen for months.
 *
 * So this screen is mostly a mirror, and deliberately: one preview that is drawn the same way the
 * server draws the real tile, one field, and a plain answer to "can anybody actually install this".
 * The preview is drawn here from the colours and initials rather than by loading the PNG, so that
 * it still works before a domain is verified — which is exactly when somebody is most likely to be
 * looking at this screen.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var WebApp = { App: null, sites: [], siteId: null, app: null };

	WebApp.render = function ( App ) {
		WebApp.App = App;
		App.loading( App.t( 'panel.webapp.title' ) );

		App.request( 'GET', '/sites' )
			.then( function ( answer ) {
				WebApp.sites = answer.data || [];

				if ( ! WebApp.sites.length ) {
					return WebApp.paintEmpty();
				}

				// Whatever was being looked at last, where it still exists. A screen that resets to
				// the first site every time is a screen nobody with two sites can use.
				var chosen = WebApp.sites.filter( function ( site ) { return site.id === WebApp.siteId; } )[ 0 ];

				return WebApp.load( ( chosen || WebApp.sites[ 0 ] ).id );
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	WebApp.load = function ( siteId ) {
		var App = WebApp.App;

		WebApp.siteId = siteId;

		return App.request( 'GET', '/sites/' + siteId + '/app' )
			.then( function ( answer ) {
				WebApp.app = answer;
				WebApp.paint();
			} );
	};

	WebApp.paintEmpty = function () {
		var App = WebApp.App;

		App.page( {
			title: App.t( 'panel.webapp.title' ),
			description: esc( App.t( 'panel.webapp.subtitle' ) ),
			// The home-screen app is what a website becomes, so there has to be a website first.
			body: App.emptyState(
				'globe',
				App.t( 'panel.webapp.none' ),
				esc( App.t( 'panel.webapp.noneHint' ) ),
				App.goesTo( 'sites' )
			),
		} );
	};

	WebApp.paint = function () {
		var App = WebApp.App;
		var app = WebApp.app;

		App.page( {
			title: App.t( 'panel.webapp.title' ),
			description: esc( App.t( 'panel.webapp.subtitle' ) ),
			actions: WebApp.sites.length > 1 ? WebApp.picker( App ) : '',
			body: '<div class="split">' +
				'<section class="card card--pad">' +
					'<h2 class="card__title">' + esc( App.t( 'panel.webapp.preview' ) ) + '</h2>' +
					WebApp.tile( app ) +
					'<p class="hint">' + esc( App.t( 'panel.webapp.previewHint' ) ) + '</p>' +
					WebApp.nameField( App, app ) +
				'</section>' +
				'<section class="card card--pad">' +
					'<h2 class="card__title">' + esc( App.t( 'panel.webapp.facts' ) ) + '</h2>' +
					WebApp.readiness( App, app ) +
					WebApp.facts( App, app ) +
					WebApp.links( App, app ) +
				'</section>' +
			'</div>',
		} );

		WebApp.wire();
	};

	WebApp.picker = function ( App ) {
		return '<select class="select" id="webapp-site" aria-label="' +
			esc( App.t( 'panel.webapp.site' ) ) + '">' + WebApp.sites.map( function ( site ) {
				return '<option value="' + esc( site.id ) + '"' +
					( site.id === WebApp.siteId ? ' selected' : '' ) + '>' +
					esc( site.name ) + '</option>';
			} ).join( '' ) + '</select>';
	};

	/**
	 * The tile as a launcher will show it: full-bleed colour, the initials, the label underneath.
	 *
	 * The label is clipped by the same CSS a home screen clips with rather than by cutting the
	 * string here, so what somebody sees on this screen is the truncation they will actually get
	 * instead of an approximation of it.
	 */
	WebApp.tile = function ( app ) {
		var background = app.accent || '#4a4fdc';
		var ink = app.on_accent || '#ffffff';

		return '<div class="homescreen" dir="' + esc( app.dir || 'ltr' ) + '">' +
			'<div class="homescreen__tile" style="background:' + esc( background ) + ';color:' + esc( ink ) + '">' +
				'<span>' + esc( app.initials || '' ) + '</span>' +
			'</div>' +
			'<span class="homescreen__label" id="webapp-label">' + esc( app.install_name ) + '</span>' +
		'</div>';
	};

	WebApp.nameField = function ( App, app ) {
		return '<div class="field"><label class="field__label" for="webapp-name">' +
			esc( App.t( 'panel.webapp.nameLabel' ) ) + '</label>' +
			'<input class="input" type="text" id="webapp-name" maxlength="30" value="' +
				esc( app.chosen_name || '' ) + '" placeholder="' +
				esc( App.t( 'panel.webapp.namePlaceholder', { name: app.suggested_name } ) ) + '">' +
			'<span class="field__hint">' +
				esc( App.t( 'panel.webapp.nameHint', { name: app.suggested_name } ) ) + '</span>' +
			'</div>' +
			'<div class="row--between"><span></span>' +
			'<button class="btn btn--primary" id="webapp-save">' +
				esc( App.t( 'panel.webapp.save' ) ) + '</button></div>';
	};

	/** Whether a browser will offer this at all, and if not, which of the two reasons it is. */
	WebApp.readiness = function ( App, app ) {
		if ( app.installable ) {
			return '<p><span class="badge badge--ok">' +
				esc( App.t( 'panel.webapp.installable' ) ) + '</span></p>' +
				'<p class="hint">' + esc( App.t( 'panel.webapp.installableHint' ) ) + '</p>';
		}

		return '<p><span class="badge badge--warn">' +
			esc( App.t( 'panel.webapp.notInstallable' ) ) + '</span></p>' +
			'<p class="hint">' + esc( App.t(
				'live' === app.status ? 'panel.webapp.noDomain' : 'panel.webapp.notLive'
			) ) + '</p>';
	};

	WebApp.facts = function ( App, app ) {
		var lines = [
			App.t( 'panel.webapp.factOffline' ),
			app.shortcut
				? App.t( 'panel.webapp.factShortcut', { name: app.shortcut } )
				: App.t( 'panel.webapp.factNoShortcut' ),
			App.t( 'panel.webapp.factColour', { colour: app.theme_color } ),
		];

		return '<ul class="statement-lines">' + lines.map( function ( line ) {
			return '<li>' + esc( line ) + '</li>';
		} ).join( '' ) + '</ul>';
	};

	/**
	 * The two things worth opening, and nothing that would 404.
	 *
	 * Both are null until the site has a verified address, and a link to nowhere is worse than no
	 * link: it teaches somebody that the screen is broken rather than that the site is unfinished.
	 */
	WebApp.links = function ( App, app ) {
		if ( ! app.url ) {
			return '';
		}

		var icons = ( app.icons || [] ).filter( function ( entry ) { return !! entry.url; } );

		return '<p class="spaced">' +
			'<a class="btn btn--sm" href="' + esc( app.url ) + '" target="_blank" rel="noopener">' +
				icon( 'globe', { size: 14 } ) + esc( App.t( 'panel.webapp.openSite' ) ) + '</a> ' +
			'<a class="btn btn--sm" href="' + esc( app.manifest_url ) + '" target="_blank" rel="noopener">' +
				icon( 'file', { size: 14 } ) + esc( App.t( 'panel.webapp.manifest' ) ) + '</a>' +
			'</p>' +
			// The real PNGs, as the server draws them. The preview above is a stand-in that works
			// without a domain; these are the files a phone will actually download.
			( icons.length
				? '<h3 class="subhead">' + esc( App.t( 'panel.webapp.icons' ) ) + '</h3>' +
					'<p class="spaced">' + icons.map( function ( entry ) {
						return '<a class="btn btn--sm" href="' + esc( entry.url ) +
							'" target="_blank" rel="noopener">' + esc( entry.size ) + '</a>';
					} ).join( ' ' ) + '</p>'
				: '' );
	};

	WebApp.wire = function () {
		var App = WebApp.App;
		var picker = document.getElementById( 'webapp-site' );
		var field = document.getElementById( 'webapp-name' );
		var label = document.getElementById( 'webapp-label' );
		var save = document.getElementById( 'webapp-save' );

		if ( picker ) {
			picker.addEventListener( 'change', function () {
				WebApp.load( picker.value ).catch( function ( error ) { App.error( error ); } );
			} );
		}

		// The label follows what is being typed, because the whole question this screen answers is
		// "what will it say", and answering it only after a save is answering it too late.
		if ( field && label ) {
			field.addEventListener( 'input', function () {
				label.textContent = field.value.trim() || WebApp.app.suggested_name;
			} );
		}

		if ( save && field ) {
			save.addEventListener( 'click', function () {
				var brand = Object.assign( {}, WebApp.brandOf( WebApp.siteId ), { app_name: field.value.trim() } );

				save.disabled = true;

				App.request( 'PATCH', '/sites/' + WebApp.siteId, { brand: brand } )
					.then( function () {
						App.toast( App.t( 'panel.webapp.saved' ) );

						return WebApp.load( WebApp.siteId );
					} )
					.catch( function ( error ) {
						save.disabled = false;
						App.error( error );
					} );
			} );
		}
	};

	/**
	 * The site's whole brand, as it stands.
	 *
	 * Sent back with the name because the site endpoint takes `brand` as one object and replaces
	 * it: patching with `{ app_name }` alone would silently discard the accent colour, the logo and
	 * the list of payment gateways. Found the hard way is the wrong way to find that.
	 */
	WebApp.brandOf = function ( siteId ) {
		var site = WebApp.sites.filter( function ( entry ) { return entry.id === siteId; } )[ 0 ];

		return ( site && site.brand ) || {};
	};

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapWebApp = WebApp;
}( typeof window !== 'undefined' ? window : globalThis ) );
