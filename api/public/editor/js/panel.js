/**
 * Tenant panel and designer shell.
 *
 * Plain DOM rather than a framework: the panel is a handful of screens around one genuinely
 * interesting component, and a build step would add more to maintain than it removes.
 *
 * Every class name here comes from design.css or panel.css. Nothing in this file styles anything
 * itself — the moment markup starts carrying its own colours the design system stops being one.
 */
( function () {
	'use strict';

	var Chart = window.SeatmapChart;
	var Ops = window.SeatmapChartOps;
	var icon = window.SeatmapIcon;

	var STORE = {
		token: 'seatmap_token',
		profile: 'seatmap_profile',
		theme: 'seatmap_theme',
	};

	/* Navigation, in the order the work happens: events are the daily screen, so they come first. */
	var NAV = [
		{ key: 'events', label: 'Events', icon: 'calendar' },
		{ key: 'tickets', label: 'Tickets', icon: 'ticket' },
		{ key: 'maps', label: 'Seat maps', icon: 'map' },
		{ key: 'venues', label: 'Venues', icon: 'building' },
		{ key: 'sites', label: 'Websites', icon: 'globe' },
		{ key: 'connections', label: 'Connections', icon: 'plug' },
	];

	var STATUS_TONE = {
		draft: 'neutral',
		published: 'ok',
		closed: 'neutral',
		cancelled: 'danger',
	};

	var App = {
		api: null,
		token: null,
		root: null,
		profile: null,
		current: null,
		map: null,
		editor: null,
		inspector: null,
		readOnly: false,
	};

	/* ---------------------------------------------------------------------------- theme */

	/**
	 * Theme, stored per browser rather than per tab: someone who works in the dark on Monday is
	 * still working in the dark on Tuesday. The OS preference decides the first visit.
	 */
	var Theme = {
		stored: function () {
			try {
				return window.localStorage.getItem( STORE.theme );
			} catch ( error ) {
				return null;
			}
		},

		resolve: function () {
			var stored = this.stored();

			if ( 'dark' === stored || 'light' === stored ) {
				return stored;
			}

			return window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches
				? 'dark'
				: 'light';
		},

		current: function () {
			return document.documentElement.getAttribute( 'data-theme' ) || 'light';
		},

		apply: function ( value ) {
			document.documentElement.setAttribute( 'data-theme', value );
		},

		toggle: function () {
			var next = 'dark' === this.current() ? 'light' : 'dark';

			try {
				window.localStorage.setItem( STORE.theme, next );
			} catch ( error ) {
				// A browser with storage disabled still gets the theme, just not the memory of it.
			}

			this.apply( next );

			return next;
		},
	};

	App.init = function () {
		this.root = document.getElementById( 'app' );
		this.api = this.root.dataset.api;
		this.token = window.sessionStorage.getItem( STORE.token );
		this.profile = readJson( STORE.profile );

		Theme.apply( Theme.resolve() );

		this.token ? this.showWorkspace() : this.showLogin();
	};

	/* ------------------------------------------------------------------------- transport */

	App.request = function ( method, path, body ) {
		var self = this;
		var headers = { Accept: 'application/json' };

		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		if ( this.token ) {
			headers.Authorization = 'Bearer ' + this.token;
		}

		return fetch( this.api + path, {
			method: method,
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					// An expired or revoked token should return someone to the sign-in screen
					// rather than leaving them looking at an error they cannot act on.
					if ( 401 === response.status && self.token ) {
						self.signOut();
					}

					var error = new Error( ( data.error && data.error.message ) || 'Request failed' );
					error.code = data.error && data.error.code;
					error.details = data.error && data.error.details;
					error.status = response.status;

					throw error;
				}

				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------------ auth shell */

	App.showLogin = function () {
		var self = this;

		this.root.innerHTML =
			'<div class="auth"><form class="auth__card" id="login">' +
				'<div class="auth__brand"><span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) +
				'</span>Seatmap</div>' +
				'<h1 class="auth__title">Sign in</h1>' +
				'<p class="auth__sub">Manage your venues, seating charts and events.</p>' +
				'<div class="field"><label class="field__label" for="email">Email</label>' +
				'<input class="input" id="email" name="email" type="email" required autocomplete="username"></div>' +
				'<div class="field"><label class="field__label" for="password">Password</label>' +
				'<input class="input" id="password" name="password" type="password" required ' +
				'autocomplete="current-password"></div>' +
				'<div class="issue issue--error" id="login-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">Sign in</button>' +
			'</form></div>';

		var form = document.getElementById( 'login' );
		var submit = form.querySelector( 'button[type=submit]' );
		var problem = document.getElementById( 'login-error' );

		form.querySelector( '#email' ).focus();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( form );

			problem.hidden = true;
			submit.disabled = true;
			submit.textContent = 'Signing in…';

			self.request( 'POST', '/auth/login', {
				email: data.get( 'email' ),
				password: data.get( 'password' ),
				device_name: 'panel',
			} )
				.then( function ( response ) {
					self.token = response.token;
					self.profile = {
						email: String( data.get( 'email' ) || '' ),
						tenant: response.tenant ? response.tenant.name : '',
						role: response.role || '',
					};

					// sessionStorage, not localStorage: the token dies with the tab rather than
					// lingering on a shared machine.
					window.sessionStorage.setItem( STORE.token, response.token );
					window.sessionStorage.setItem( STORE.profile, JSON.stringify( self.profile ) );

					self.showWorkspace();
				} )
				.catch( function ( error ) {
					problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
					problem.hidden = false;
					submit.disabled = false;
					submit.textContent = 'Sign in';
				} );
		} );
	};

	App.signOut = function () {
		window.sessionStorage.removeItem( STORE.token );
		window.sessionStorage.removeItem( STORE.profile );
		this.token = null;
		this.profile = null;
		this.editor = null;
		this.inspector = null;
		this.showLogin();
	};

	App.showWorkspace = function () {
		var self = this;
		var profile = this.profile || {};
		var name = profile.tenant || profile.email || 'Signed in';
		var meta = profile.role ? titleCase( profile.role ) : ( profile.tenant ? profile.email : '' );

		// The theme toggle lives up by the brand rather than in the account row: down there it
		// squeezed the organiser's name into an ellipsis, and its tooltip fell off the window.
		this.root.innerHTML =
			'<div class="shell">' +
				'<aside class="sidebar">' +
					'<div class="sidebar__brand">' +
						'<span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) + '</span>' +
						'<span class="grow">Seatmap</span>' +
						'<button class="icon-btn icon-btn--sm" id="theme" data-tip-side="bottom-end"></button>' +
					'</div>' +
					'<nav class="sidebar__nav" id="nav" aria-label="Sections"></nav>' +
					'<div class="sidebar__footer"><div class="account">' +
						'<span class="account__avatar" aria-hidden="true">' + esc( initials( name ) ) + '</span>' +
						'<div class="account__body">' +
							'<div class="account__name">' + esc( name ) + '</div>' +
							'<div class="account__meta">' + esc( meta || '' ) + '</div>' +
						'</div>' +
						'<button class="icon-btn icon-btn--sm" id="signout" data-tip="Sign out" ' +
							'data-tip-side="top-end" aria-label="Sign out">' +
							icon( 'logout', { size: 16 } ) + '</button>' +
					'</div></div>' +
				'</aside>' +
				'<main class="main" id="main"></main>' +
			'</div>';

		this.paintThemeButton();

		document.getElementById( 'theme' ).addEventListener( 'click', function () {
			Theme.toggle();
			self.paintThemeButton();

			if ( self.editor ) {
				self.editor.draw();
			}
		} );

		document.getElementById( 'signout' ).addEventListener( 'click', function () { self.signOut(); } );

		this.route( 'events' );
	};

	App.paintThemeButton = function () {
		var button = document.getElementById( 'theme' );

		if ( ! button ) {
			return;
		}

		var dark = 'dark' === Theme.current();

		button.innerHTML = icon( dark ? 'sun' : 'moon', { size: 16 } );
		button.setAttribute( 'data-tip', dark ? 'Light theme' : 'Dark theme' );
		button.setAttribute( 'aria-label', dark ? 'Switch to the light theme' : 'Switch to the dark theme' );
	};

	App.renderNav = function () {
		var self = this;
		var host = document.getElementById( 'nav' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';

		NAV.forEach( function ( entry ) {
			var button = node( 'button', 'nav-item' );
			button.type = 'button';
			button.dataset.view = entry.key;
			button.innerHTML = icon( entry.icon, { size: 16 } ) + '<span>' + esc( entry.label ) + '</span>';

			if ( self.current === entry.key ) {
				button.classList.add( 'is-active' );
				button.setAttribute( 'aria-current', 'page' );
			}

			button.addEventListener( 'click', function () { self.route( entry.key ); } );
			host.appendChild( button );
		} );
	};

	App.route = function ( view ) {
		this.current = view;
		this.renderNav();

		switch ( view ) {
			case 'venues': return this.renderVenues();
			case 'maps': return this.renderMaps();
			case 'connections': return this.renderConnections();
			case 'sites': return window.SeatmapSites.renderList( this );
			case 'tickets': return window.SeatmapTickets.render( this );
			default: return this.renderEvents();
		}
	};

	App.main = function () { return document.getElementById( 'main' ); };

	/**
	 * One page skeleton for every screen: a header saying what this is and offering the one action
	 * most people came to take, then the body.
	 */
	App.page = function ( options ) {
		this.main().innerHTML =
			'<div class="page-head">' +
				'<div class="page-head__text"><h1>' + esc( options.title ) + '</h1>' +
				( options.description ? '<p class="page-head__desc">' + options.description + '</p>' : '' ) +
				'</div>' +
				'<div class="page-head__actions">' + ( options.actions || '' ) + '</div>' +
			'</div>' +
			'<div class="page-body">' + ( options.body || '' ) + '</div>';
	};

	// Shared with the website and ticket screens, which build the same furniture.
	App.table = function ( headings, rows, emptyMarkup ) {
		return table( headings, rows, emptyMarkup );
	};

	App.emptyState = function ( iconName, title, body ) {
		return emptyState( iconName, title, body );
	};

	App.timezone = function () {
		return guessTimezone();
	};

	App.loading = function ( title ) {
		this.page( { title: title, body: '<p class="muted">Loading…</p>' } );
	};

	App.error = function ( error ) {
		this.main().innerHTML =
			'<div class="page-body"><div class="issue issue--error" role="alert">' +
			icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span></div></div>';
	};

	/* --------------------------------------------------------------------------- pieces */

	function emptyState( iconName, title, body ) {
		return '<div class="empty"><span class="empty__icon">' + icon( iconName, { size: 22 } ) + '</span>' +
			'<p class="empty__title">' + esc( title ) + '</p>' +
			'<p class="empty__body">' + body + '</p></div>';
	}

	function table( headings, rows, emptyMarkup ) {
		if ( ! rows ) {
			return '<div class="card">' + emptyMarkup + '</div>';
		}

		var head = headings.map( function ( heading ) {
			return '<th' + ( heading.numeric ? ' class="tnum"' : '' ) + '>' + esc( heading.label || heading ) + '</th>';
		} ).join( '' );

		return '<div class="table-wrap"><table class="table"><thead><tr>' + head + '</tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';
	}

	function badge( text, tone ) {
		return '<span class="badge badge--' + ( tone || 'neutral' ) + '">' + esc( text ) + '</span>';
	}

	function actionButton( attribute, value, label, iconName ) {
		return '<button class="btn btn--sm" data-' + attribute + '="' + esc( value ) + '">' +
			( iconName ? icon( iconName, { size: 14 } ) : '' ) + esc( label ) + '</button>';
	}

	/* --------------------------------------------------------------------------- modals */

	/**
	 * A modal built around a form, because nearly every one here collects something.
	 *
	 * Closes on Escape and on a click outside the panel, restores focus to whatever opened it, and
	 * puts the cursor in the first field — the three things people notice only when they are absent.
	 */
	App.modal = function ( options ) {
		var host = document.createElement( 'div' );
		var opener = document.activeElement;
		var isForm = !! options.onSubmit;

		host.className = 'modal';
		host.setAttribute( 'role', 'dialog' );
		host.setAttribute( 'aria-modal', 'true' );

		host.innerHTML =
			'<' + ( isForm ? 'form' : 'div' ) + ' class="modal__panel">' +
				'<div class="modal__head"><h2>' + esc( options.title ) + '</h2>' +
					'<button type="button" class="icon-btn icon-btn--sm" data-close aria-label="Close">' +
					icon( 'close', { size: 16 } ) + '</button></div>' +
				'<div class="modal__body">' + ( options.body || '' ) + '</div>' +
				'<div class="modal__foot">' +
					( options.cancelLabel === null ? '' :
						'<button type="button" class="btn" data-close>' +
						esc( options.cancelLabel || 'Cancel' ) + '</button>' ) +
					( isForm
						? '<button type="submit" class="btn btn--primary">' + esc( options.submitLabel || 'Save' ) + '</button>'
						: '<button type="button" class="btn btn--primary" data-close>' +
							esc( options.doneLabel || 'Done' ) + '</button>' ) +
				'</div>' +
			'</' + ( isForm ? 'form' : 'div' ) + '>';

		function close() {
			document.removeEventListener( 'keydown', onKey );
			host.remove();

			if ( opener && opener.focus ) {
				opener.focus();
			}

			if ( options.onClose ) {
				options.onClose();
			}
		}

		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		}

		document.body.appendChild( host );
		document.addEventListener( 'keydown', onKey );

		host.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === host ) {
				close();
			}
		} );

		host.querySelectorAll( '[data-close]' ).forEach( function ( button ) {
			button.addEventListener( 'click', close );
		} );

		if ( isForm ) {
			host.querySelector( 'form' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var submit = host.querySelector( 'button[type=submit]' );
				var label = submit.textContent;

				submit.disabled = true;
				submit.textContent = 'Working…';

				Promise.resolve( options.onSubmit( new FormData( event.target ), host ) )
					.then( function ( keepOpen ) {
						if ( ! keepOpen ) {
							close();
						}
					} )
					.catch( function ( error ) {
						App.toast( error.message, true );
					} )
					.then( function () {
						submit.disabled = false;
						submit.textContent = label;
					} );
			} );
		}

		var first = host.querySelector( '.modal__body input, .modal__body select, .modal__body textarea' );

		if ( first ) {
			first.focus();
		}

		host.close = close;

		return host;
	};

	/* -------------------------------------------------------------------------- listings */

	App.renderVenues = function () {
		var self = this;

		this.loading( 'Venues' );

		this.request( 'GET', '/venues' )
			.then( function ( response ) {
				var rows = response.data.map( function ( venue ) {
					return '<tr><td class="table__primary">' + esc( venue.name ) + '</td>' +
						'<td>' + esc( venue.city || '—' ) + '</td>' +
						'<td>' + esc( venue.country || '—' ) + '</td>' +
						'<td class="muted">' + esc( venue.timezone ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: 'Venues',
					description: 'A venue is the building. Its seating charts live under Seat maps.',
					actions: '<button class="btn btn--primary" id="add-venue">' +
						icon( 'plus', { size: 15 } ) + 'New venue</button>',
					body: table(
						[ 'Name', 'City', 'Country', 'Time zone' ],
						rows,
						emptyState( 'building', 'No venues yet',
							'Add the building first — charts and events both hang off it.' )
					),
				} );

				document.getElementById( 'add-venue' ).addEventListener( 'click', function () {
					self.newVenue();
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.newVenue = function () {
		var self = this;

		this.modal( {
			title: 'New venue',
			submitLabel: 'Create venue',
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="v-name">Name</label>' +
				'<input class="input" id="v-name" name="name" required maxlength="160" ' +
				'placeholder="Northgate Theatre"></div>' +
				'<div class="row"><div class="field grow"><label class="field__label" for="v-city">City</label>' +
				'<input class="input" id="v-city" name="city" maxlength="120"></div>' +
				'<div class="field field--narrow"><label class="field__label" for="v-country">Country</label>' +
				'<input class="input" id="v-country" name="country" maxlength="2" placeholder="GB"></div></div>' +
				'<div class="field"><label class="field__label" for="v-tz">Time zone</label>' +
				'<input class="input" id="v-tz" name="timezone" value="' + esc( guessTimezone() ) + '">' +
				'<span class="field__hint">Doors and start times are shown in this zone.</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return self.request( 'POST', '/venues', {
					name: data.get( 'name' ),
					city: data.get( 'city' ) || null,
					country: ( data.get( 'country' ) || '' ).toUpperCase() || null,
					timezone: data.get( 'timezone' ) || null,
				} ).then( function () {
					self.toast( 'Venue created.' );
					self.renderVenues();
				} );
			},
		} );
	};

	App.renderMaps = function () {
		var self = this;

		this.loading( 'Seat maps' );

		Promise.all( [ this.request( 'GET', '/seat-maps' ), this.request( 'GET', '/venues' ) ] )
			.then( function ( results ) {
				var venues = {};

				results[ 1 ].data.forEach( function ( venue ) { venues[ venue.id ] = venue.name; } );

				var rows = results[ 0 ].data.map( function ( map ) {
					var published = map.published_version;
					var draft = map.draft_version;

					var state = published
						? badge( 'v' + published.version + ' live', 'ok' )
						: badge( 'Not published', 'neutral' );

					if ( draft && ( ! published || draft.version > published.version ) ) {
						state += ' ' + badge( 'Draft v' + draft.version, 'warn' );
					}

					var places = published
						? '<span class="tnum">' + published.seat_count + '</span>'
						: '<span class="muted">—</span>';

					return '<tr><td class="table__primary">' + esc( map.name ) + '</td>' +
						'<td>' + esc( venues[ map.venue_id ] || '—' ) + '</td>' +
						'<td>' + state + '</td>' +
						'<td class="tnum">' + places + '</td>' +
						'<td class="table__actions">' +
						actionButton( 'map', map.id, 'Open designer', 'map' ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: 'Seat maps',
					description: 'A chart is drawn once and published. Events sell against the version ' +
						'that was live when they were created.',
					actions: results[ 1 ].data.length
						? '<button class="btn btn--primary" id="add-map">' +
							icon( 'plus', { size: 15 } ) + 'New seat map</button>'
						: '',
					body: table(
						[ 'Name', 'Venue', 'Status', { label: 'Places', numeric: true }, '' ],
						rows,
						results[ 1 ].data.length
							? emptyState( 'map', 'No seat maps yet', 'Draw your first chart in the designer.' )
							: emptyState( 'building', 'Add a venue first',
								'A chart belongs to a building, so there has to be one to hang it on.' )
					),
				} );

				var add = document.getElementById( 'add-map' );

				if ( add ) {
					add.addEventListener( 'click', function () { self.newMap( results[ 1 ].data ); } );
				}

				self.main().querySelectorAll( '[data-map]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.openDesigner( button.dataset.map ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.newMap = function ( venues ) {
		var self = this;

		this.modal( {
			title: 'New seat map',
			submitLabel: 'Create and open',
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="m-name">Name</label>' +
				'<input class="input" id="m-name" name="name" required maxlength="160" ' +
				'placeholder="Main auditorium"></div>' +
				'<div class="field"><label class="field__label" for="m-venue">Venue</label>' +
				'<select class="select" id="m-venue" name="venue_id" required>' +
				venues.map( function ( venue ) {
					return '<option value="' + esc( venue.id ) + '">' + esc( venue.name ) + '</option>';
				} ).join( '' ) +
				'</select></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return self.request( 'POST', '/seat-maps', {
					venue_id: data.get( 'venue_id' ),
					name: data.get( 'name' ),
				} ).then( function ( map ) {
					self.openDesigner( map.id );
				} );
			},
		} );
	};

	App.renderEvents = function () {
		var self = this;

		this.loading( 'Events' );

		Promise.all( [ this.request( 'GET', '/events' ), this.request( 'GET', '/seat-maps' ) ] )
			.then( function ( results ) {
				var sellable = results[ 1 ].data.filter( function ( map ) { return !! map.published_version; } );

				var rows = results[ 0 ].data.map( function ( event ) {
					return '<tr><td class="table__primary">' + esc( event.name ) + '</td>' +
						'<td class="tnum">' + esc( formatDate( event.starts_at ) ) + '</td>' +
						'<td>' + badge( titleCase( event.status ), STATUS_TONE[ event.status ] ) + '</td>' +
						'<td><code>' + esc( event.public_id ) + '</code>' +
						'<button class="icon-btn icon-btn--sm" data-copy="' + esc( event.public_id ) +
						'" data-tip="Copy" aria-label="Copy the public ID">' + icon( 'copy', { size: 14 } ) +
						'</button></td>' +
						'<td class="table__actions">' +
						actionButton( 'stats', event.id, 'Inventory', 'layers' ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: 'Events',
					description: 'Paste an event’s public ID into WordPress as ' +
						'<code>[seatmap_event id="evt_…"]</code>, or into the Seat map block.',
					actions: sellable.length
						? '<button class="btn btn--primary" id="add-event">' +
							icon( 'plus', { size: 15 } ) + 'New event</button>'
						: '',
					body: table(
						[ 'Name', 'Starts', 'Status', 'Public ID', '' ],
						rows,
						sellable.length
							? emptyState( 'calendar', 'No events yet',
								'An event is one performance selling against a published chart.' )
							: emptyState( 'map', 'Publish a chart first',
								'An event sells against a published seat map, so there has to be one to sell.' )
					),
				} );

				var add = document.getElementById( 'add-event' );

				if ( add ) {
					add.addEventListener( 'click', function () { self.newEvent( sellable ); } );
				}

				self.main().querySelectorAll( '[data-stats]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.showStats( button.dataset.stats, button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
					} );
				} );

				self.main().querySelectorAll( '[data-copy]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						copyText( button.dataset.copy );
						self.toast( 'Public ID copied.' );
					} );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.newEvent = function ( maps ) {
		var self = this;

		this.modal( {
			title: 'New event',
			submitLabel: 'Create event',
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="e-name">Name</label>' +
				'<input class="input" id="e-name" name="name" required maxlength="200" ' +
				'placeholder="Saturday evening"></div>' +
				'<div class="field"><label class="field__label" for="e-map">Seat map</label>' +
				'<select class="select" id="e-map" name="seat_map_id" required>' +
				maps.map( function ( map ) {
					return '<option value="' + esc( map.id ) + '">' + esc( map.name ) +
						' — v' + map.published_version.version + '</option>';
				} ).join( '' ) +
				'</select><span class="field__hint">The event keeps selling against this version even ' +
				'if the chart is republished later.</span></div>' +
				'<div class="field"><label class="field__label" for="e-starts">Starts</label>' +
				'<input class="input" id="e-starts" name="starts_at" type="datetime-local" required></div>' +
				'<div class="field"><label class="field__label" for="e-status">Status</label>' +
				'<select class="select" id="e-status" name="status">' +
				'<option value="draft">Draft — not on sale</option>' +
				'<option value="published">Published — on sale</option>' +
				'</select></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return self.request( 'POST', '/events', {
					name: data.get( 'name' ),
					seat_map_id: data.get( 'seat_map_id' ),
					starts_at: data.get( 'starts_at' ),
					status: data.get( 'status' ),
				} ).then( function () {
					self.toast( 'Event created.' );
					self.renderEvents();
				} );
			},
		} );
	};

	App.showStats = function ( eventId, name ) {
		var self = this;

		this.request( 'GET', '/events/' + eventId + '/stats' ).then( function ( stats ) {
			var cells = [
				[ 'seats_total', 'Places' ],
				[ 'available', 'Available' ],
				[ 'held', 'Held' ],
				[ 'allocated', 'Sold' ],
				[ 'blocked', 'Blocked' ],
				[ 'checked_in', 'Checked in' ],
			].map( function ( pair ) {
				return '<div class="stat stat--block"><span class="stat__value tnum">' +
					esc( stats[ pair[ 0 ] ] ) + '</span><span class="stat__label">' + esc( pair[ 1 ] ) +
					'</span></div>';
			} ).join( '' );

			self.modal( {
				title: name || 'Inventory',
				cancelLabel: null,
				doneLabel: 'Close',
				body: '<div class="stat-grid">' + cells + '</div>' +
					'<p class="hint">Counted live from allocations, holds and overrides. Money is your ' +
					'shop’s business — these are places, not takings.</p>',
			} );
		} ).catch( function ( error ) { self.toast( error.message, true ); } );
	};

	App.renderConnections = function () {
		var self = this;

		this.loading( 'Connections' );

		this.request( 'GET', '/api-clients' )
			.then( function ( response ) {
				var rows = response.data.map( function ( client ) {
					var keys = client.keys.map( function ( key ) {
						return '<div class="row"><code>' + esc( key.key_id ) + '</code>' +
							'<span class="muted">…' + esc( key.secret_hint || '' ) + '</span>' +
							'<button class="icon-btn icon-btn--sm" data-revoke="' + esc( client.id ) +
							'" data-key="' + esc( key.key_id ) + '" data-tip="Revoke" ' +
							'data-tip-side="bottom-end" aria-label="Revoke this key">' +
							icon( 'trash', { size: 14 } ) + '</button></div>';
					} ).join( '' );

					return '<tr><td class="table__primary">' + esc( client.name ) + '</td>' +
						'<td>' + ( client.site_url
							? '<a href="' + esc( client.site_url ) + '" rel="noreferrer noopener" target="_blank">' +
								esc( client.site_url ) + '</a>'
							: '<span class="muted">—</span>' ) + '</td>' +
						'<td>' + ( keys || '<span class="muted">No active keys</span>' ) + '</td>' +
						'<td class="muted">' + esc( client.last_seen_at ? formatDate( client.last_seen_at ) : 'Never' ) + '</td>' +
						'<td class="table__actions">' +
						actionButton( 'rotate', client.id, 'Rotate key', 'key' ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: 'Connections',
					description: 'Each shop that sells your seats gets its own key pair. Requests are ' +
						'signed, so a stolen key is the only thing worth rotating.',
					actions: '<button class="btn btn--primary" id="add-client">' +
						icon( 'plus', { size: 15 } ) + 'Connect a site</button>',
					body: table(
						[ 'Site', 'URL', 'Keys', 'Last seen', '' ],
						rows,
						emptyState( 'plug', 'No sites connected',
							'Connect your WordPress shop, then paste the key pair into the plugin’s settings.' )
					),
				} );

				document.getElementById( 'add-client' ).addEventListener( 'click', function () {
					self.newClient();
				} );

				self.main().querySelectorAll( '[data-rotate]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.request( 'POST', '/api-clients/' + button.dataset.rotate + '/keys', {} )
							.then( function ( credentials ) { self.showCredentials( credentials, true ); } )
							.catch( function ( error ) { self.toast( error.message, true ); } );
					} );
				} );

				self.main().querySelectorAll( '[data-revoke]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.revokeKey( button.dataset.revoke, button.dataset.key ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.newClient = function () {
		var self = this;

		this.modal( {
			title: 'Connect a site',
			submitLabel: 'Connect',
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="c-name">Site name</label>' +
				'<input class="input" id="c-name" name="name" required maxlength="120" ' +
				'placeholder="Box office"></div>' +
				'<div class="field"><label class="field__label" for="c-url">Site URL</label>' +
				'<input class="input" id="c-url" name="site_url" type="url" placeholder="https://example.com">' +
				'<span class="field__hint">Only for your own reference — it is not used to authorise ' +
				'anything.</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return self.request( 'POST', '/api-clients', {
					name: data.get( 'name' ),
					site_url: data.get( 'site_url' ) || null,
				} ).then( function ( client ) {
					self.showCredentials( client.credentials, false );
				} );
			},
		} );
	};

	/**
	 * The secret is shown once and never again, so this modal has no quiet dismissal: closing it is
	 * a deliberate act, and the warning says plainly what happens if the secret was not saved.
	 */
	App.showCredentials = function ( credentials, rotated ) {
		var self = this;

		this.modal( {
			title: rotated ? 'New key issued' : 'Site connected',
			cancelLabel: null,
			doneLabel: 'I have saved it',
			body:
				'<div class="credentials">' +
					'<div class="row"><strong>Key ID</strong></div>' +
					'<div class="credentials__row"><code>' + esc( credentials.key_id ) + '</code>' +
					'<button type="button" class="btn btn--sm" data-copy-key>' +
					icon( 'copy', { size: 14 } ) + 'Copy</button></div>' +
					'<div class="row"><strong>Secret</strong></div>' +
					'<div class="credentials__row"><code>' + esc( credentials.secret ) + '</code>' +
					'<button type="button" class="btn btn--sm" data-copy-secret>' +
					icon( 'copy', { size: 14 } ) + 'Copy</button></div>' +
					'<p class="hint">This is the only time the secret is shown. If you lose it, rotate ' +
					'the key — the old one keeps working until you revoke it, so your shop stays up.</p>' +
				'</div>',
			onClose: function () { self.renderConnections(); },
		} );

		var host = document.querySelector( '.modal' );

		host.querySelector( '[data-copy-key]' ).addEventListener( 'click', function () {
			copyText( credentials.key_id );
			self.toast( 'Key ID copied.' );
		} );

		host.querySelector( '[data-copy-secret]' ).addEventListener( 'click', function () {
			copyText( credentials.secret );
			self.toast( 'Secret copied.' );
		} );
	};

	App.revokeKey = function ( clientId, keyId ) {
		var self = this;

		this.modal( {
			title: 'Revoke this key?',
			submitLabel: 'Revoke',
			body: '<p>Requests signed with <code>' + esc( keyId ) + '</code> will start failing ' +
				'immediately. If a shop is still using it, its seat picker stops working.</p>',
			onSubmit: function () {
				return self.request( 'DELETE', '/api-clients/' + clientId + '/keys/' + keyId )
					.then( function () {
						self.toast( 'Key revoked.' );
						self.renderConnections();
					} );
			},
		} );
	};

	/* --------------------------------------------------------------------------- designer */

	App.openDesigner = function ( mapId ) {
		var self = this;

		this.request( 'GET', '/seat-maps/' + mapId ).then( function ( map ) {
			self.map = map;

			var version = map.draft_version || map.published_version;
			// A published v1 map opens through the converter, so charts drawn before the model
			// changed still load rather than showing an empty canvas.
			var chart = version ? Ops.migrate( version.geometry ) : Chart.empty( map.name );

			// Editing the published version directly is not possible — publishing forks a draft —
			// so make that state visible rather than letting someone edit and wonder why.
			self.readOnly = ! map.draft_version && !! map.published_version;

			// The designer takes the whole window: a sidebar beside a canvas is a sidebar in the way.
			self.root.innerHTML = self.designerMarkup( map );
			self.mountDesigner( chart );
		} ).catch( function ( error ) { self.toast( error.message, true ); } );
	};

	/** A toolbar button. Icon only, with its name in both the tooltip and the accessible label. */
	function toolbarButton( id, iconName, label, side ) {
		return '<button class="icon-btn" id="' + id + '" data-tip="' + esc( label ) + '"' +
			( side ? ' data-tip-side="' + side + '"' : '' ) +
			' aria-label="' + esc( label ) + '">' + icon( iconName ) + '</button>';
	}

	App.designerMarkup = function ( map ) {
		return '' +
		'<div class="designer">' +
			'<div class="designer__bar">' +
				'<div class="designer__title">' +
					toolbarButton( 'dz-close', 'back', 'Back to seat maps' ) +
					'<span class="designer__name">' + esc( map.name ) + '</span>' +
					'<span class="badge badge--warn" id="dz-readonly" hidden>' +
						icon( 'lock', { size: 13 } ) + 'Read only</span>' +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-undo', 'undo', 'Undo' ) +
					toolbarButton( 'dz-redo', 'redo', 'Redo' ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-duplicate', 'duplicate', 'Duplicate' ) +
					toolbarButton( 'dz-copy', 'copy', 'Copy' ) +
					toolbarButton( 'dz-delete', 'trash', 'Delete' ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-mirror-h', 'flipH', 'Mirror horizontally' ) +
					toolbarButton( 'dz-mirror-v', 'flipV', 'Mirror vertically' ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-focal', 'target', 'Set the focal point' ) +
					toolbarButton( 'dz-labels', 'tag', 'Show or hide labels' ) +
					toolbarButton( 'dz-add-floor', 'plus', 'Add a floor' ) +
				'</div>' +
				'<span class="designer__spacer"></span>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-lock', 'unlock', 'Lock the chart against edits' ) +
					toolbarButton( 'dz-preview', 'eye', 'Preview as a buyer sees it' ) +
					toolbarButton( 'dz-theme', 'moon', 'Dark theme' ) +
					toolbarButton( 'dz-help', 'help', 'Keyboard shortcuts', 'bottom-end' ) +
				'</div>' +
				'<button class="btn" id="dz-save">' + icon( 'save', { size: 15 } ) + 'Save draft</button>' +
				'<button class="btn btn--primary" id="dz-publish">' +
					icon( 'publish', { size: 15 } ) + 'Publish</button>' +
			'</div>' +
			'<div class="designer__body">' +
				'<div class="tools" id="dz-tools" role="toolbar" aria-label="Drawing tools"></div>' +
				'<div class="stage">' +
					'<div class="stage__canvas"><canvas id="dz-canvas"></canvas></div>' +
					'<div class="float layers" id="dz-layers"></div>' +
					'<button class="float stage__exit" id="dz-exit" hidden>' +
						icon( 'back', { size: 15 } ) + 'Exit section</button>' +
					'<div class="float zoom">' +
						'<button class="icon-btn icon-btn--sm" id="dz-zoom-out" aria-label="Zoom out">' +
							icon( 'minus', { size: 16 } ) + '</button>' +
						'<button class="zoom__level" id="dz-zoom-level" data-tip="Fit to view" ' +
							'aria-label="Fit to view">100%</button>' +
						'<button class="icon-btn icon-btn--sm" id="dz-zoom-in" aria-label="Zoom in">' +
							icon( 'plus', { size: 16 } ) + '</button>' +
					'</div>' +
					'<div class="float floors" id="dz-floors"></div>' +
				'</div>' +
				'<aside class="inspector" id="dz-inspector"></aside>' +
			'</div>' +
			'<div class="designer__status"><span id="dz-status"></span><span id="dz-selection"></span></div>' +
		'</div>';
	};

	/**
	 * The tool palette.
	 *
	 * Grouped the way the work goes: pick things, then place seating, then place everything that is
	 * not seating, then move the view.
	 */
	App.TOOLS = [
		{ key: 'select', icon: 'cursor', label: 'Select' },
		{ key: 'lasso', icon: 'lasso', label: 'Lasso select' },
		{ key: 'sameType', icon: 'wand', label: 'Select same type' },
		{ separator: true },
		{ key: 'row', icon: 'row', label: 'Straight row' },
		{ key: 'curvedRow', icon: 'curvedRow', label: 'Curved row' },
		{ key: 'section', icon: 'section', label: 'Section' },
		{ key: 'table', icon: 'table', label: 'Table' },
		{ key: 'booth', icon: 'booth', label: 'Booth' },
		{ key: 'area', icon: 'area', label: 'General admission area' },
		{ separator: true },
		{ key: 'shape', icon: 'shape', label: 'Shape' },
		{ key: 'line', icon: 'line', label: 'Line' },
		{ key: 'text', icon: 'text', label: 'Text' },
		{ key: 'image', icon: 'image', label: 'Image' },
		{ key: 'icon', icon: 'accessibility', label: 'Icon' },
		{ separator: true },
		{ key: 'pan', icon: 'hand', label: 'Pan' },
	];

	App.mountDesigner = function ( chart ) {
		var self = this;

		var editor = new window.SeatmapEditor( document.getElementById( 'dz-canvas' ), {
			chart: chart,
			onChange: function () { self.refreshDesigner(); },
			onSelectionChange: function () { self.refreshInspector(); self.refreshStatus(); },
			onContextChange: function () { self.refreshDesigner(); },
			onStatus: function ( message ) { document.getElementById( 'dz-status' ).textContent = message; },
		} ).init();

		this.editor = editor;
		editor.locked = this.readOnly;

		// Every path that changes the view ends in a draw — including the wheel — so the readout
		// hangs off draw rather than off the handful of buttons that happen to call zoomBy.
		var draw = editor.draw.bind( editor );

		editor.draw = function () {
			draw();
			self.refreshZoom();
		};

		this.inspector = new window.SeatmapInspector(
			document.getElementById( 'dz-inspector' ),
			editor,
			{ onManageCategories: function () { self.manageCategories(); } }
		);

		// Exposed for the browser smoke test, and genuinely useful in the console when diagnosing
		// a chart a customer has sent in.
		window.__editor = editor;

		this.bindDesigner();
		this.renderTools();
		editor.zoomToFit();
		this.refreshDesigner();
		editor.setTool( 'select' );

		this.onResize = function () { editor.resize(); };
		window.addEventListener( 'resize', this.onResize );
	};

	App.renderTools = function () {
		var self = this;
		var host = document.getElementById( 'dz-tools' );

		host.innerHTML = '';

		this.TOOLS.forEach( function ( tool ) {
			if ( tool.separator ) {
				host.appendChild( node( 'div', 'tools__sep' ) );

				return;
			}

			var button = node( 'button', 'icon-btn' );
			button.innerHTML = icon( tool.icon );
			button.dataset.tool = tool.key;
			button.setAttribute( 'data-tip', tool.label );
			button.setAttribute( 'data-tip-side', 'right' );
			button.setAttribute( 'aria-label', tool.label );
			button.setAttribute( 'aria-pressed', self.editor.tool === tool.key ? 'true' : 'false' );

			button.addEventListener( 'click', function () {
				self.editor.setTool( tool.key );
				self.renderTools();
			} );

			if ( self.editor.tool === tool.key ) {
				button.classList.add( 'is-active' );
			}

			host.appendChild( button );
		} );
	};

	App.bindDesigner = function () {
		var self = this;
		var editor = this.editor;

		function on( id, handler ) {
			var element = document.getElementById( id );

			if ( element ) {
				element.addEventListener( 'click', handler );
			}
		}

		on( 'dz-close', function () {
			window.removeEventListener( 'resize', self.onResize );
			self.editor = null;
			self.inspector = null;
			self.showWorkspace();
			self.route( 'maps' );
		} );

		on( 'dz-undo', function () { editor.undo(); } );
		on( 'dz-redo', function () { editor.redo(); } );
		on( 'dz-zoom-in', function () { editor.zoomBy( 1.25 ); } );
		on( 'dz-zoom-out', function () { editor.zoomBy( 0.8 ); } );
		on( 'dz-zoom-level', function () { editor.zoomToFit(); } );
		on( 'dz-exit', function () { editor.exitSection(); } );
		on( 'dz-mirror-h', function () { editor.mirrorSelection( 'horizontal' ); } );
		on( 'dz-mirror-v', function () { editor.mirrorSelection( 'vertical' ); } );
		on( 'dz-duplicate', function () { editor.duplicateSelection(); } );
		on( 'dz-copy', function () { editor.copy(); self.toast( 'Copied.' ); } );
		on( 'dz-delete', function () { editor.deleteSelection(); } );
		on( 'dz-focal', function () { editor.setTool( 'focalPoint' ); self.renderTools(); } );
		on( 'dz-save', function () { self.saveDraft(); } );
		on( 'dz-publish', function () { self.publish(); } );
		on( 'dz-add-floor', function () { self.addFloor(); } );
		on( 'dz-help', function () { self.showShortcuts(); } );

		on( 'dz-labels', function () {
			editor.showLabels = ! editor.showLabels;
			document.getElementById( 'dz-labels' ).classList.toggle( 'is-active', editor.showLabels );
			editor.draw();
		} );

		on( 'dz-lock', function () {
			editor.locked = ! editor.locked;
			self.refreshDesigner();
			self.toast( editor.locked ? 'Chart locked.' : 'Chart unlocked.' );
		} );

		on( 'dz-preview', function () {
			// Preview hides the designer's own furniture — grid, focal crosshair, selection — so
			// what is left is what a buyer would see.
			var designer = document.querySelector( '.designer' );
			var previewing = designer.classList.toggle( 'is-preview' );

			document.getElementById( 'dz-preview' ).classList.toggle( 'is-active', previewing );
			editor.snapToGrid = ! previewing;
			editor.clearSelection();
			editor.resize();
		} );

		on( 'dz-theme', function () {
			Theme.toggle();
			self.paintDesignerTheme();
			editor.draw();
		} );

		this.paintDesignerTheme();
		document.getElementById( 'dz-labels' ).classList.toggle( 'is-active', editor.showLabels );
	};

	App.paintDesignerTheme = function () {
		var button = document.getElementById( 'dz-theme' );

		if ( ! button ) {
			return;
		}

		var dark = 'dark' === Theme.current();

		button.innerHTML = icon( dark ? 'sun' : 'moon' );
		button.setAttribute( 'data-tip', dark ? 'Light theme' : 'Dark theme' );
		button.setAttribute( 'aria-label', dark ? 'Switch to the light theme' : 'Switch to the dark theme' );
	};

	App.refreshDesigner = function () {
		this.refreshInspector();
		this.refreshStatus();
		this.refreshLayers();
		this.refreshFloors();
		this.refreshZoom();

		var exit = document.getElementById( 'dz-exit' );

		if ( exit ) {
			exit.hidden = ! this.editor.sectionKey;
		}

		var badgeNode = document.getElementById( 'dz-readonly' );

		if ( badgeNode ) {
			badgeNode.hidden = ! this.editor.locked;
		}

		var lock = document.getElementById( 'dz-lock' );

		if ( lock ) {
			lock.innerHTML = icon( this.editor.locked ? 'lock' : 'unlock' );
			lock.classList.toggle( 'is-active', this.editor.locked );
			lock.setAttribute( 'data-tip', this.editor.locked ? 'Unlock the chart' : 'Lock the chart against edits' );
			lock.setAttribute( 'aria-label', lock.getAttribute( 'data-tip' ) );
		}
	};

	App.refreshZoom = function () {
		var readout = document.getElementById( 'dz-zoom-level' );

		if ( readout && this.editor ) {
			readout.textContent = Math.round( this.editor.view.scale * 100 ) + '%';
		}
	};

	App.refreshInspector = function () {
		if ( this.inspector ) {
			this.inspector.render();
		}
	};

	App.refreshStatus = function () {
		var editor = this.editor;
		var target = document.getElementById( 'dz-selection' );

		if ( ! target ) {
			return;
		}

		if ( editor.seatSelection.length ) {
			target.textContent = editor.seatSelection.length + ' seat' +
				( 1 === editor.seatSelection.length ? '' : 's' ) + ' selected';

			return;
		}

		if ( ! editor.selection.length ) {
			target.textContent = '';

			return;
		}

		var children = 0;

		editor.selectedObjects().forEach( function ( object ) {
			children += ( object.seats || object.objects || [] ).length;
		} );

		target.textContent =
			editor.selection.length + ' object' + ( 1 === editor.selection.length ? '' : 's' ) + ' selected' +
			( children ? ' (' + children + ' children)' : '' );
	};

	/**
	 * The selection-layer list.
	 *
	 * Restricting selection to one layer is what makes a busy chart workable: you can drag the
	 * scenery around without disturbing a single seat, and vice versa.
	 */
	App.refreshLayers = function () {
		var self = this;
		var host = document.getElementById( 'dz-layers' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';
		host.appendChild( node( 'h4', 'layers__title overline', 'Selection layer' ) );

		var floor = this.editor.floor();
		var counts = { all: 0 };

		Chart.LAYERS.forEach( function ( layer ) { counts[ layer ] = 0; } );

		( floor.objects || [] ).forEach( function ( object ) {
			counts.all += 1;
			counts[ object.layer || 'interactive' ] += 1;
		} );

		[ 'all' ].concat( Chart.LAYERS.slice().reverse() ).forEach( function ( layer ) {
			var button = node( 'button', 'layer' );
			button.appendChild( node( 'span', null, Chart.LAYER_LABELS[ layer ] ) );
			button.appendChild( node( 'span', 'layer__count', String( counts[ layer ] ) ) );

			// A layer with nothing on it is shown but greyed, so the list stays a stable map of the
			// chart rather than appearing and disappearing as objects are added.
			if ( 'all' !== layer && ! counts[ layer ] ) {
				button.classList.add( 'is-empty' );
			}

			if ( self.editor.layer === layer ) {
				button.classList.add( 'is-active' );
				button.setAttribute( 'aria-current', 'true' );
			}

			button.addEventListener( 'click', function () {
				self.editor.layer = layer;
				self.editor.clearSelection();
				self.refreshLayers();
			} );

			host.appendChild( button );
		} );
	};

	App.refreshFloors = function () {
		var self = this;
		var host = document.getElementById( 'dz-floors' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';

		var gear = node( 'button', 'floor' );
		gear.innerHTML = icon( 'settings', { size: 15 } );
		gear.setAttribute( 'data-tip', 'Rename or remove this floor' );
		gear.setAttribute( 'data-tip-side', 'bottom-end' );
		gear.setAttribute( 'aria-label', 'Rename or remove this floor' );
		gear.addEventListener( 'click', function () { self.editFloor(); } );
		host.appendChild( gear );

		this.editor.chart.floors.slice().reverse().forEach( function ( floor ) {
			var button = node( 'button', 'floor', floor.key );
			button.setAttribute( 'data-tip', floor.name );
			button.setAttribute( 'data-tip-side', 'bottom-end' );
			button.setAttribute( 'aria-label', floor.name );

			if ( self.editor.floorKey === floor.key ) {
				button.classList.add( 'is-active' );
				button.setAttribute( 'aria-current', 'true' );
			}

			button.addEventListener( 'click', function () { self.editor.setFloor( floor.key ); } );
			host.appendChild( button );
		} );
	};

	App.addFloor = function () {
		var self = this;

		this.modal( {
			title: 'Add a floor',
			submitLabel: 'Add floor',
			body: '<div class="field"><label class="field__label" for="f-name">Floor name</label>' +
				'<input class="input" id="f-name" name="name" required maxlength="60" value="Level ' +
				( this.editor.chart.floors.length + 1 ) + '"></div>',
			onSubmit: function ( data ) {
				var name = String( data.get( 'name' ) || '' ).trim();

				if ( ! name ) {
					return true;
				}

				self.editor.mutate( function ( chart ) {
					var key = String( chart.floors.length + 1 );

					while ( chart.floors.some( function ( floor ) { return floor.key === key; } ) ) {
						key += '\''; // A removed-then-re-added floor must not collide with a surviving one.
					}

					chart.floors.push( Chart.newFloor( key, name ) );
					self.editor.floorKey = key;
				} );

				self.refreshDesigner();
			},
		} );
	};

	App.editFloor = function () {
		var self = this;
		var floor = this.editor.floor();
		var removable = this.editor.chart.floors.length > 1;

		var host = this.modal( {
			title: 'Floor',
			submitLabel: 'Rename',
			body: '<div class="field"><label class="field__label" for="f-rename">Floor name</label>' +
				'<input class="input" id="f-rename" name="name" required maxlength="60" value="' +
				esc( floor.name ) + '"></div>' +
				( removable
					? '<p class="hint spaced">Removing a floor removes everything drawn on it.</p>'
					: '<p class="hint spaced">A chart needs at least one floor, so this one cannot be ' +
						'removed.</p>' ),
			onSubmit: function ( data ) {
				var name = String( data.get( 'name' ) || '' ).trim();

				if ( ! name ) {
					return true;
				}

				self.editor.mutate( function () { floor.name = name; } );
				self.refreshDesigner();
			},
		} );

		if ( removable ) {
			var remove = node( 'button', 'btn btn--danger', 'Remove floor' );
			remove.type = 'button';

			remove.addEventListener( 'click', function () {
				self.editor.mutate( function ( chart ) {
					chart.floors = chart.floors.filter( function ( entry ) { return entry.key !== floor.key; } );
					self.editor.floorKey = chart.floors[ 0 ].key;
				} );

				host.close();
				self.editor.setFloor( self.editor.floorKey );
				self.refreshDesigner();
			} );

			var foot = host.querySelector( '.modal__foot' );
			foot.classList.add( 'modal__foot--split' );
			foot.insertBefore( remove, foot.firstChild );
		}
	};

	/* ------------------------------------------------------------------------- categories */

	App.manageCategories = function () {
		var self = this;
		var chart = this.editor.chart;

		var host = this.modal( {
			title: 'Categories',
			cancelLabel: null,
			doneLabel: 'Done',
			body:
				'<div id="dz-cats" class="stack"></div>' +
				'<form id="dz-cat-form" class="row row--wrap spaced">' +
					'<input class="input grow" name="label" placeholder="Category name" required maxlength="60">' +
					'<input class="swatch" name="color" type="color" value="#5b63f0" aria-label="Colour">' +
					'<label class="row"><input class="checkbox" name="accessible" type="checkbox">' +
					'<span>Accessible</span></label>' +
					'<button class="btn" type="submit">' + icon( 'plus', { size: 14 } ) + 'Add</button>' +
				'</form>' +
				'<p class="hint spaced">A category is a price tier. ' +
				'Every bookable object needs one before the chart can be priced for an event.</p>',
			onClose: function () { self.refreshDesigner(); },
		} );

		function paint() {
			var list = host.querySelector( '#dz-cats' );

			list.innerHTML = '';

			if ( ! ( chart.categories || [] ).length ) {
				list.appendChild( node( 'p', 'muted', 'No categories yet.' ) );

				return;
			}

			chart.categories.forEach( function ( category ) {
				var line = node( 'div', 'category-row' );
				var dot = node( 'span', 'dot' );

				dot.style.background = category.color;
				line.appendChild( dot );
				line.appendChild( node( 'span', 'category-row__label', category.label ) );

				if ( category.accessible ) {
					var mark = node( 'span', 'muted' );
					mark.innerHTML = icon( 'accessibility', { size: 15 } );
					mark.setAttribute( 'data-tip', 'Accessible' );
					line.appendChild( mark );
				}

				var remove = node( 'button', 'icon-btn icon-btn--sm' );
				remove.type = 'button';
				remove.innerHTML = icon( 'trash', { size: 14 } );
				remove.setAttribute( 'data-tip', 'Remove' );
				remove.setAttribute( 'data-tip-side', 'bottom-end' );
				remove.setAttribute( 'aria-label', 'Remove ' + category.label );

				remove.addEventListener( 'click', function () {
					self.editor.mutate( function () { Chart.removeCategory( chart, category.key ); } );
					paint();
				} );

				line.appendChild( remove );
				list.appendChild( line );
			} );
		}

		paint();

		host.querySelector( '#dz-cat-form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( event.target );

			self.editor.mutate( function () {
				Chart.addCategory( chart, data.get( 'label' ), data.get( 'color' ), !! data.get( 'accessible' ) );
			} );

			event.target.reset();
			event.target.querySelector( 'input[name=label]' ).focus();
			paint();
		} );
	};

	App.showShortcuts = function () {
		var keys = [
			[ [ 'Ctrl/⌘', 'Z' ], 'Undo' ],
			[ [ 'Ctrl/⌘', 'Shift', 'Z' ], 'Redo' ],
			[ [ 'Ctrl/⌘', 'A' ], 'Select everything in the current layer' ],
			[ [ 'Ctrl/⌘', 'D' ], 'Deselect' ],
			[ [ 'Ctrl/⌘', 'C' ], 'Copy' ],
			[ [ 'Ctrl/⌘', 'V' ], 'Paste' ],
			[ [ 'Shift', 'Click' ], 'Add to or remove from the selection' ],
			[ [ '←', '→', '↑', '↓' ], 'Nudge by one unit — hold Shift for a grid step' ],
			[ [ 'Space', 'drag' ], 'Pan' ],
			[ [ 'Enter' ], 'Close the section or line being drawn' ],
			[ [ 'Double-click' ], 'Go into a section, or back out of it' ],
			[ [ 'Delete' ], 'Remove the selection' ],
		];

		var list = keys.map( function ( entry ) {
			return '<dt>' + entry[ 0 ].map( function ( key ) {
				return '<span class="kbd">' + esc( key ) + '</span>';
			} ).join( '' ) + '</dt><dd>' + esc( entry[ 1 ] ) + '</dd>';
		} ).join( '' );

		this.modal( {
			title: 'Keyboard shortcuts',
			cancelLabel: null,
			doneLabel: 'Close',
			body: '<dl class="kbd-list">' + list + '</dl>',
		} );
	};

	/* ------------------------------------------------------------------------ persistence */

	App.saveDraft = function () {
		var self = this;

		return this.request( 'POST', '/seat-maps/' + this.map.id + '/versions', { geometry: this.editor.chart } )
			.then( function ( version ) {
				// Saving a draft forks one from the published version, so the chart stops being
				// read-only the moment there is something to edit.
				self.readOnly = false;
				self.editor.locked = false;
				self.map.draft_version = version;
				self.refreshDesigner();
				self.toast( 'Draft saved.' );

				return version;
			} )
			.catch( function ( error ) {
				self.toast( error.message, true );

				throw error;
			} );
	};

	/**
	 * Publishing saves first, so what goes live is exactly what is on screen. Publishing a stale
	 * server-side draft is the kind of surprise that costs a venue a night's sales.
	 */
	App.publish = function () {
		var self = this;

		this.saveDraft()
			.then( function () {
				return self.request( 'POST', '/seat-maps/' + self.map.id + '/publish', {} );
			} )
			.then( function ( version ) {
				self.toast( 'Published version ' + version.version + ' with ' + version.seat_count + ' places.' );
			} )
			.catch( function ( error ) {
				if ( ! error || ! error.message ) {
					return;
				}

				var issues = ( error.details && error.details.errors ) || [];
				var detail = '';

				// One example plus a count. Pasting every failing seat into a toast produces a
				// paragraph nobody reads; the full list is in the panel beside it.
				if ( issues.length ) {
					detail = ' ' + issues[ 0 ].message +
						( issues.length > 1 ? ' (and ' + ( issues.length - 1 ) + ' more)' : '' );
				}

				self.toast( error.message + detail, true );
			} );
	};

	App.toast = function ( message, isError ) {
		var existing = document.querySelector( '.toast' );

		if ( existing ) {
			existing.remove();
		}

		var toast = document.createElement( 'div' );

		toast.className = 'toast' + ( isError ? ' toast--error' : ' toast--ok' );
		toast.setAttribute( 'role', 'status' );
		toast.innerHTML = icon( isError ? 'alert' : 'check', { size: 16 } ) + '<span></span>';
		toast.querySelector( 'span' ).textContent = message;

		document.body.appendChild( toast );

		window.setTimeout( function () { toast.remove(); }, isError ? 9000 : 5000 );
	};

	/* ----------------------------------------------------------------------------- helpers */

	function node( tag, className, text ) {
		var element = document.createElement( tag );

		if ( className ) {
			element.className = className;
		}

		if ( text != null ) {
			element.textContent = text;
		}

		return element;
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value == null ? '' : value );

		return element.innerHTML;
	}

	function readJson( key ) {
		try {
			return JSON.parse( window.sessionStorage.getItem( key ) || 'null' );
		} catch ( error ) {
			return null;
		}
	}

	function initials( value ) {
		var words = String( value || '' ).replace( /[^\p{L}\p{N}\s]/gu, ' ' ).trim().split( /\s+/ );

		if ( ! words[ 0 ] ) {
			return '?';
		}

		return ( words[ 0 ].charAt( 0 ) + ( words.length > 1 ? words[ words.length - 1 ].charAt( 0 ) : '' ) )
			.toUpperCase();
	}

	function titleCase( value ) {
		var text = String( value || '' ).replace( /[_-]+/g, ' ' );

		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	function formatDate( value ) {
		var date = new Date( value );

		if ( isNaN( date.getTime() ) ) {
			return '—';
		}

		return date.toLocaleString( undefined, {
			day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
		} );
	}

	function guessTimezone() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		} catch ( error ) {
			return 'UTC';
		}
	}

	function copyText( value ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).catch( function () { fallbackCopy( value ); } );

			return;
		}

		fallbackCopy( value );
	}

	// Clipboard access needs a secure context, and a venue's panel is not always on one.
	function fallbackCopy( value ) {
		var field = document.createElement( 'textarea' );

		field.value = value;
		field.setAttribute( 'readonly', 'readonly' );
		field.style.position = 'fixed';
		field.style.opacity = '0';

		document.body.appendChild( field );
		field.select();

		try {
			document.execCommand( 'copy' );
		} catch ( error ) {
			// Nothing more to try; the value is on screen and can be selected by hand.
		}

		field.remove();
	}

	App.escape = esc;

	document.addEventListener( 'DOMContentLoaded', function () { App.init(); } );
} )();
