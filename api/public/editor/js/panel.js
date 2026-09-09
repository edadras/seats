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
	var i18n = window.SeatmapI18n;

	var STORE = {
		token: 'seatmap_token',
		profile: 'seatmap_profile',
		theme: 'seatmap_theme',
	};

	/*
	 * Navigation, in the order the work happens: events are the daily screen, so they come first.
	 *
	 * Only the key and the icon are here. The label is looked up when the nav is painted, because
	 * this array is built while the file loads — before the catalogue has been fetched — and a
	 * label captured then would be English for the rest of the session.
	 */
	var NAV = [
		{ group: null, items: [ { key: 'overview', icon: 'grid' } ] },
		{ group: 'programme', items: [
			{ key: 'events', icon: 'calendar' },
			{ key: 'counter', icon: 'ticket' },
			{ key: 'orders', icon: 'file' },
			{ key: 'tickets', icon: 'ticket' },
			{ key: 'doorlist', icon: 'check' },
			{ key: 'discounts', icon: 'tag' },
		] },
		{ group: 'venue', items: [
			{ key: 'maps', icon: 'map' },
			{ key: 'venues', icon: 'building' },
		] },
		{ group: 'audience', items: [
			{ key: 'customers', icon: 'users' },
			{ key: 'sites', icon: 'globe' },
			{ key: 'themes', icon: 'palette' },
			{ key: 'messaging', icon: 'mail' },
		] },
		{ group: 'insight', items: [
			{ key: 'reports', icon: 'chart' },
		] },
		{ group: 'account', items: [
			{ key: 'connections', icon: 'plug' },
			{ key: 'modules', icon: 'puzzle' },
			{ key: 'team', icon: 'users' },
			{ key: 'audit', icon: 'history' },
		] },
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
		var self = this;

		this.root = document.getElementById( 'app' );
		this.api = this.root.dataset.api;
		this.token = window.sessionStorage.getItem( STORE.token );
		this.profile = readJson( STORE.profile );

		Theme.apply( Theme.resolve() );

		// Nothing paints before the catalogue is in hand: t() is synchronous, and a render loop
		// that had to await a string would either flash English or not render at all (ADR-0005).
		// It resolves either way — a missing catalogue opens the panel in English rather than
		// leaving somebody at a blank page.
		i18n.load( this.api ).then( function () {
			self.token ? self.showWorkspace() : self.showLogin();
		} );
	};

	/** Short, because it appears once per label. */
	App.t = function ( key, replace ) {
		return i18n.t( key, replace );
	};

	App.has = function ( key ) {
		return i18n.has( key );
	};

	App.money = function ( minorUnits, currency, decimals ) {
		return i18n.money( minorUnits, currency, decimals );
	};

	App.number = function ( value ) {
		return i18n.number( value );
	};

	App.date = function ( value, options ) {
		return i18n.date( value, options );
	};

	/* ------------------------------------------------------------------------- transport */

	/**
	 * Every call the panel makes.
	 *
	 * `options.raw` asks for the response body itself rather than JSON — an export is a file, and
	 * a file cannot be fetched by pointing an anchor at it: an anchor carries no Authorization
	 * header, and a token in a query string is a token in somebody's server log.
	 */
	App.request = function ( method, path, body, options ) {
		var self = this;
		var raw = !! ( options && options.raw );
		var headers = { Accept: raw ? '*/*' : 'application/json' };

		// The panel's language travels with every call, so a message the *server* composes — a
		// module's name, a refusal — comes back in the language the panel is being read in.
		// Without this, the chrome is Persian and everything the API said is English.
		if ( i18n.locale ) {
			headers[ 'X-Seatmap-Locale' ] = i18n.locale;
		}

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
			if ( raw && response.ok ) {
				return response.blob();
			}

			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					// An expired or revoked token should return someone to the sign-in screen
					// rather than leaving them looking at an error they cannot act on.
					if ( 401 === response.status && self.token ) {
						self.signOut();
					}

					var error = new Error(
						( data.error && data.error.message ) || self.t( 'panel.common.failed' )
					);
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
				'</span>' + esc( this.t( 'panel.brand' ) ) + '</div>' +
				'<h1 class="auth__title">' + esc( this.t( 'panel.auth.signIn' ) ) + '</h1>' +
				'<p class="auth__sub">' + esc( this.t( 'panel.auth.subtitle' ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="email">' +
				esc( this.t( 'panel.auth.email' ) ) + '</label>' +
				'<input class="input" id="email" name="email" type="email" required autocomplete="username"></div>' +
				'<div class="field"><label class="field__label" for="password">' +
				esc( this.t( 'panel.auth.password' ) ) + '</label>' +
				'<input class="input" id="password" name="password" type="password" required ' +
				'autocomplete="current-password"></div>' +
				'<div class="issue issue--error" id="login-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">' +
				esc( this.t( 'panel.auth.signIn' ) ) + '</button>' +
				'<p class="auth__foot"><button type="button" class="link" id="go-signup">' +
					esc( this.t( 'signup.newAccount' ) ) + '</button></p>' +
			'</form></div>';

		var form = document.getElementById( 'login' );
		var submit = form.querySelector( 'button[type=submit]' );
		var problem = document.getElementById( 'login-error' );

		form.querySelector( '#email' ).focus();

		document.getElementById( 'go-signup' )
			.addEventListener( 'click', function () { window.SeatmapSignup.render( self ); } );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( form );

			problem.hidden = true;
			submit.disabled = true;
			submit.textContent = self.t( 'panel.auth.signingIn' );

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
						email_verified: false !== response.email_verified,
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
					submit.textContent = self.t( 'panel.auth.signIn' );
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
		var name = profile.tenant || profile.email || this.t( 'panel.shell.signedIn' );
		// A built-in role has a translated name; one the account invented for itself does not, and
		// making one up would be worse than showing what they called it.
		var meta = profile.role
			? ( this.has( 'team.roles.' + profile.role )
				? this.t( 'team.roles.' + profile.role )
				: titleCase( profile.role ) )
			: ( profile.tenant ? profile.email : '' );

		// The theme toggle lives up by the brand rather than in the account row: down there it
		// squeezed the organiser's name into an ellipsis, and its tooltip fell off the window.
		this.root.innerHTML =
			'<div class="shell">' +
				'<aside class="sidebar">' +
					'<div class="sidebar__brand">' +
						'<span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) + '</span>' +
						'<span class="grow">' + esc( this.t( 'panel.brand' ) ) + '</span>' +
						'<button class="icon-btn icon-btn--sm bell" id="bell" data-tip-side="bottom-end" ' +
							'data-tip="' + esc( this.t( 'panel.notices.title' ) ) + '" aria-label="' +
							esc( this.t( 'panel.notices.title' ) ) + '">' +
							icon( 'alert', { size: 16 } ) +
							'<span class="bell__count" id="bell-count" hidden></span></button>' +
						'<button class="icon-btn icon-btn--sm" id="theme" data-tip-side="bottom-end"></button>' +
					'</div>' +
					'<nav class="sidebar__nav" id="nav" aria-label="' +
						esc( this.t( 'panel.nav.sections' ) ) + '"></nav>' +
					'<div class="sidebar__footer">' +
						'<div class="sidebar__language">' + this.languageField( 'locale' ) + '</div>' +
						'<div class="account">' +
						'<span class="account__avatar" aria-hidden="true">' + esc( initials( name ) ) + '</span>' +
						'<div class="account__body">' +
							'<div class="account__name">' + esc( name ) + '</div>' +
							'<div class="account__meta">' + esc( meta || '' ) + '</div>' +
						'</div>' +
						'<button class="icon-btn icon-btn--sm" id="signout" data-tip="' +
							esc( this.t( 'panel.shell.signOut' ) ) + '" ' +
							'data-tip-side="top-end" aria-label="' +
							esc( this.t( 'panel.shell.signOut' ) ) + '">' +
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

		document.getElementById( 'bell' ).addEventListener( 'click', function () { self.notices(); } );

		// Asked for once now and every few minutes after. A notice worth raising is worth arriving
		// while somebody is still looking at the screen, and polling this rarely costs nothing.
		this.loadNotices();
		window.setInterval( function () { self.loadNotices(); }, 120000 );

		var language = document.getElementById( 'locale' );

		language.addEventListener( 'change', function () { i18n.choose( language.value ); } );

		// An account that has not verified its address gets a bar it can act on, not a nag: the
		// code box is in it, and everything else on the panel still works.
		window.SeatmapSignup.banner( this );

		this.route( 'overview' );
	};

	/* ------------------------------------------------------------------------- the notices */

	/**
	 * What the platform has to say to this account.
	 *
	 * Composed by the server in the reader's own language — a notice is a sentence about facts,
	 * and the facts are in the database while the sentence is in six catalogues. The panel's job
	 * is to count what is unread and show what there is.
	 */
	App.loadNotices = function () {
		var self = this;

		return this.request( 'GET', '/notifications' )
			.then( function ( response ) {
				self.noticeList = response.data || [];
				self.paintBell( response.unread || 0 );
			} )
			.catch( function () { /* A bell that cannot be filled is not worth an error. */ } );
	};

	App.paintBell = function ( unread ) {
		var badge = document.getElementById( 'bell-count' );

		if ( ! badge ) {
			return;
		}

		badge.textContent = unread > 9 ? '9+' : this.number( unread );
		badge.hidden = ! unread;
	};

	App.notices = function () {
		var self = this;
		var list = this.noticeList || [];

		this.modal( {
			title: this.t( 'panel.notices.title' ),
			submitLabel: this.t( 'panel.notices.markRead' ),
			cancelLabel: this.t( 'panel.common.close' ),
			body: list.length
				? '<ul class="notices">' + list.map( function ( notice ) {
					return '<li class="notice-row notice-row--' + esc( notice.level ) +
						( notice.read ? '' : ' is-unread' ) + '">' +
						'<div class="notice-row__head">' +
							'<strong>' + esc( notice.title ) + '</strong>' +
							'<span class="muted nowrap">' + esc( self.date( notice.created_at ) ) + '</span>' +
						'</div>' +
						'<p>' + esc( notice.body ) + '</p>' +
					'</li>';
				} ).join( '' ) + '</ul>'
				: this.emptyState( 'info', this.t( 'panel.notices.none' ),
					esc( this.t( 'panel.notices.noneHint' ) ) ),
			onSubmit: function () {
				return self.request( 'POST', '/notifications/read', {} ).then( function () {
					return self.loadNotices();
				} );
			},
		} );
	};

	/**
	 * The language menu.
	 *
	 * Every locale in its own language, never in the reader's: somebody looking for Persian is
	 * looking for فارسی, and "Persian" is exactly the word they cannot read.
	 */
	App.languageField = function ( id ) {
		return '<select class="select select--sm" id="' + esc( id ) + '" aria-label="' +
			esc( this.t( 'site.language' ) ) + '">' +
			i18n.locales.map( function ( entry ) {
				return '<option value="' + esc( entry.code ) + '"' +
					( entry.code === i18n.locale ? ' selected' : '' ) + '>' +
					esc( entry.native ) + '</option>';
			} ).join( '' ) +
			'</select>';
	};

	App.paintThemeButton = function () {
		var button = document.getElementById( 'theme' );

		if ( ! button ) {
			return;
		}

		var dark = 'dark' === Theme.current();

		button.innerHTML = icon( dark ? 'sun' : 'moon', { size: 16 } );
		button.setAttribute( 'data-tip', this.t( dark ? 'panel.shell.lightTheme' : 'panel.shell.darkTheme' ) );
		button.setAttribute( 'aria-label',
			this.t( dark ? 'panel.shell.switchToLight' : 'panel.shell.switchToDark' ) );
	};

	App.renderNav = function () {
		var self = this;
		var host = document.getElementById( 'nav' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';

		/*
		 * Grouped, because twelve unlabelled rows is a list somebody reads every time rather than
		 * learns once. The headings name the job, not the table: "Venue" is where the room lives,
		 * whether that is a chart or the building it is in.
		 */
		NAV.forEach( function ( section ) {
			var group = node( 'div', 'nav-group' );

			if ( section.group ) {
				var heading = node( 'p', 'nav-group__label' );
				heading.textContent = self.t( 'panel.navGroups.' + section.group );
				group.appendChild( heading );
			}

			section.items.forEach( function ( entry ) {
				var button = node( 'button', 'nav-item' );
				button.type = 'button';
				button.dataset.view = entry.key;
				button.innerHTML = icon( entry.icon, { size: 16 } ) +
					'<span>' + esc( self.t( 'panel.nav.' + entry.key ) ) + '</span>';

				if ( self.current === entry.key ) {
					button.classList.add( 'is-active' );
					button.setAttribute( 'aria-current', 'page' );
				}

				button.addEventListener( 'click', function () { self.route( entry.key ); } );
				group.appendChild( button );
			} );

			host.appendChild( group );
		} );
	};

	App.route = function ( view ) {
		this.current = view;
		this.renderNav();

		switch ( view ) {
			case 'overview': return this.renderOverview();
			case 'venues': return this.renderVenues();
			case 'maps': return this.renderMaps();
			case 'connections': return this.renderConnections();
			case 'sites': return window.SeatmapSites.renderList( this );
			case 'themes': return window.SeatmapThemes.render( this );
			case 'reports': return window.SeatmapReports.render( this );
			case 'messaging': return window.SeatmapMessaging.render( this );
			case 'modules': return window.SeatmapModules.render( this );
			case 'team': return window.SeatmapTeam.render( this );
			case 'audit': return window.SeatmapAudit.render( this );
			case 'tickets': return window.SeatmapTickets.render( this );
			case 'customers': return window.SeatmapCustomers.render( this );
			case 'counter': return window.SeatmapCounter.render( this );
			case 'doorlist': return window.SeatmapDoorList.render( this );
			case 'orders': return window.SeatmapOrders.render( this );
			case 'discounts': return window.SeatmapDiscounts.render( this );
			case 'events': return this.renderEvents();
			default: return this.renderOverview();
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
		this.page( {
			title: title,
			body: '<p class="muted">' + esc( this.t( 'panel.common.loading' ) ) + '</p>',
		} );
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

		/*
		 * A heading is a string, or an object saying more about the column. `html` is the one way
		 * in for markup — the reports screen puts a sort button in a heading — and the caller that
		 * uses it is the caller responsible for escaping what it built. Everything else is text and
		 * is escaped here.
		 */
		var head = headings.map( function ( heading ) {
			var content = heading && undefined !== heading.html
				? heading.html
				: esc( heading.label || heading );

			return '<th' + ( heading.numeric ? ' class="tnum"' : '' ) + '>' + content + '</th>';
		} ).join( '' );

		return '<div class="table-wrap"><table class="table"><thead><tr>' + head + '</tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';
	}

	function badge( text, tone ) {
		return '<span class="badge badge--' + ( tone || 'neutral' ) + '">' + esc( text ) + '</span>';
	}

	/**
	 * What this event charges, at a glance.
	 *
	 * The cheapest and dearest zone, in the event's own currency but formatted for whoever is
	 * reading it (ADR-0005 §5). An event with no prices says so plainly rather than showing a
	 * confident zero, because "0" and "not priced yet" sell very differently.
	 */
	function priceRange( event ) {
		var zones = event.price_zones || [];
		var currency = event.currency || 'EUR';

		if ( ! zones.length ) {
			return '<span class="muted">' + esc( App.t( 'pricing.unpriced' ) ) + '</span>';
		}

		var amounts = zones.map( function ( zone ) { return zone.amount; } );
		var low = Math.min.apply( null, amounts );
		var high = Math.max.apply( null, amounts );

		return esc( low === high
			? App.money( low, currency )
			: App.money( low, currency ) + ' – ' + App.money( high, currency ) );
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
					'<button type="button" class="icon-btn icon-btn--sm" data-close aria-label="' +
					esc( App.t( 'panel.common.close' ) ) + '">' +
					icon( 'close', { size: 16 } ) + '</button></div>' +
				'<div class="modal__body">' + ( options.body || '' ) + '</div>' +
				'<div class="modal__foot">' +
					( options.cancelLabel === null ? '' :
						'<button type="button" class="btn" data-close>' +
						esc( options.cancelLabel || App.t( 'panel.common.cancel' ) ) + '</button>' ) +
					( isForm
						? '<button type="submit" class="btn btn--primary">' +
							esc( options.submitLabel || App.t( 'panel.common.save' ) ) + '</button>'
						: '<button type="button" class="btn btn--primary" data-close>' +
							esc( options.doneLabel || App.t( 'panel.common.done' ) ) + '</button>' ) +
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
				submit.textContent = App.t( 'panel.common.working' );

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

		this.loading( this.t( 'panel.nav.venues' ) );

		this.request( 'GET', '/venues' )
			.then( function ( response ) {
				var rows = response.data.map( function ( venue ) {
					return '<tr><td class="table__primary">' + esc( venue.name ) + '</td>' +
						'<td>' + esc( venue.city || '—' ) + '</td>' +
						'<td>' + esc( venue.country || '—' ) + '</td>' +
						'<td class="muted">' + esc( venue.timezone ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: self.t( 'panel.nav.venues' ),
					description: esc( self.t( 'panel.venues.description' ) ),
					actions: '<button class="btn btn--primary" id="add-venue">' +
						icon( 'plus', { size: 15 } ) + esc( self.t( 'panel.venues.new' ) ) + '</button>',
					body: table(
						[
							self.t( 'panel.common.name' ),
							self.t( 'panel.venues.city' ),
							self.t( 'panel.venues.country' ),
							self.t( 'panel.common.timezone' ),
						],
						rows,
						emptyState( 'building', self.t( 'panel.venues.emptyTitle' ),
							esc( self.t( 'panel.venues.emptyBody' ) ) )
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
			title: this.t( 'panel.venues.new' ),
			submitLabel: this.t( 'panel.venues.create' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="v-name">' +
				esc( this.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="v-name" name="name" required maxlength="160" ' +
				'placeholder="' + esc( this.t( 'panel.venues.namePlaceholder' ) ) + '"></div>' +
				'<div class="row"><div class="field grow"><label class="field__label" for="v-city">' +
				esc( this.t( 'panel.venues.city' ) ) + '</label>' +
				'<input class="input" id="v-city" name="city" maxlength="120"></div>' +
				'<div class="field field--narrow"><label class="field__label" for="v-country">' +
				esc( this.t( 'panel.venues.country' ) ) + '</label>' +
				'<input class="input" id="v-country" name="country" maxlength="2" placeholder="GB"></div></div>' +
				'<div class="field"><label class="field__label" for="v-tz">' +
				esc( this.t( 'panel.common.timezone' ) ) + '</label>' +
				'<input class="input" id="v-tz" name="timezone" value="' + esc( guessTimezone() ) + '">' +
				'<span class="field__hint">' + esc( this.t( 'panel.venues.timezoneHint' ) ) +
				'</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return self.request( 'POST', '/venues', {
					name: data.get( 'name' ),
					city: data.get( 'city' ) || null,
					country: ( data.get( 'country' ) || '' ).toUpperCase() || null,
					timezone: data.get( 'timezone' ) || null,
				} ).then( function () {
					self.toast( self.t( 'panel.venues.created' ) );
					self.renderVenues();
				} );
			},
		} );
	};

	App.renderMaps = function () {
		var self = this;

		this.loading( this.t( 'panel.nav.maps' ) );

		Promise.all( [ this.request( 'GET', '/seat-maps' ), this.request( 'GET', '/venues' ) ] )
			.then( function ( results ) {
				var venues = {};

				results[ 1 ].data.forEach( function ( venue ) { venues[ venue.id ] = venue.name; } );

				var rows = results[ 0 ].data.map( function ( map ) {
					var published = map.published_version;
					var draft = map.draft_version;

					// The version number arrives already spelled "v2": it is the same token in every
					// language, and leaving the v out of the catalogue keeps it out of six files.
					var state = published
						? badge( self.t( 'panel.maps.live', { version: 'v' + published.version } ), 'ok' )
						: badge( self.t( 'panel.maps.notPublished' ), 'neutral' );

					if ( draft && ( ! published || draft.version > published.version ) ) {
						state += ' ' + badge(
							self.t( 'panel.maps.draft', { version: 'v' + draft.version } ), 'warn'
						);
					}

					var places = published
						? '<span class="tnum">' + published.seat_count + '</span>'
						: '<span class="muted">—</span>';

					return '<tr><td class="table__primary">' + esc( map.name ) + '</td>' +
						'<td>' + esc( venues[ map.venue_id ] || '—' ) + '</td>' +
						'<td>' + state + '</td>' +
						'<td class="tnum">' + places + '</td>' +
						'<td class="table__actions">' +
						actionButton( 'map', map.id, self.t( 'panel.maps.open' ), 'map' ) + '</td></tr>';
				} ).join( '' );

				self.page( {
					title: self.t( 'panel.nav.maps' ),
					description: esc( self.t( 'panel.maps.description' ) ),
					actions: results[ 1 ].data.length
						? '<button class="btn btn--primary" id="add-map">' +
							icon( 'plus', { size: 15 } ) + esc( self.t( 'panel.maps.new' ) ) + '</button>'
						: '',
					body: table(
						[
							self.t( 'panel.common.name' ),
							self.t( 'panel.maps.venue' ),
							self.t( 'panel.common.status' ),
							{ label: self.t( 'panel.maps.places' ), numeric: true },
							'',
						],
						rows,
						results[ 1 ].data.length
							? emptyState( 'map', self.t( 'panel.maps.emptyTitle' ),
								esc( self.t( 'panel.maps.emptyBody' ) ) )
							: emptyState( 'building', self.t( 'panel.maps.needVenueTitle' ),
								esc( self.t( 'panel.maps.needVenueBody' ) ) )
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
			title: this.t( 'panel.maps.new' ),
			submitLabel: this.t( 'panel.maps.create' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="m-name">' +
				esc( this.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="m-name" name="name" required maxlength="160" ' +
				'placeholder="' + esc( this.t( 'panel.maps.namePlaceholder' ) ) + '"></div>' +
				'<div class="field"><label class="field__label" for="m-venue">' +
				esc( this.t( 'panel.maps.venue' ) ) + '</label>' +
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

	/**
	 * The first screen: where the account stands.
	 *
	 * Not a dashboard of everything — a dashboard of everything is a screen nobody reads. Four
	 * numbers somebody would otherwise go and look up, the next few nights with how full each one
	 * is, and what changed lately. Everything on it is a door into the screen that owns it.
	 *
	 * What appears depends on what the reader may see: money, the door's numbers and the activity
	 * list are each absent from the payload for a role without the permission, so this renders what
	 * it was given rather than deciding again and getting it slightly different.
	 */
	App.renderOverview = function () {
		var self = this;

		this.loading( this.t( 'panel.nav.overview' ) );

		this.request( 'GET', '/overview' )
			.then( function ( data ) {
				self.page( {
					title: self.t( 'panel.nav.overview' ),
					description: self.t( 'panel.overview.description' ),
					body: '<div class="stat-strip">' + overviewStats( self, data ) + '</div>' +
						'<div class="split">' +
							'<section class="card card--pad">' +
								'<h2 class="card__title">' + esc( self.t( 'panel.overview.nextUp' ) ) + '</h2>' +
								overviewNights( self, data.next || [] ) +
							'</section>' +
							( data.activity
								? '<section class="card card--pad">' +
									'<h2 class="card__title">' + esc( self.t( 'panel.overview.recent' ) ) + '</h2>' +
									overviewActivity( self, data.activity ) +
									'</section>'
								: '' ) +
						'</div>',
				} );

				self.main().querySelectorAll( '[data-goto]' ).forEach( function ( element ) {
					element.addEventListener( 'click', function () { self.route( element.dataset.goto ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	/** A number worth walking across the room for, and the word that says what it is. */
	function statTile( app, value, label, meta, view ) {
		return '<button type="button" class="tile" data-goto="' + esc( view ) + '">' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			( meta ? '<span class="tile__meta">' + esc( meta ) + '</span>' : '' ) +
			'</button>';
	}

	function overviewStats( app, data ) {
		var tiles = [
			statTile(
				app,
				app.number( data.events.on_sale ),
				app.t( 'panel.overview.onSale' ),
				data.events.draft
					? app.t( 'panel.overview.inDraft', { count: app.number( data.events.draft ) } )
					: '',
				'events'
			),
			statTile(
				app,
				app.number( data.seats.sold_this_month ),
				app.t( 'panel.overview.soldThisMonth' ),
				'',
				'tickets'
			),
		];

		if ( data.money ) {
			// One line per currency: an account may sell an evening in euros and another in rials,
			// and adding those together would be a number that means nothing.
			var takings = ( data.money.this_month || [] );

			tiles.push( statTile(
				app,
				takings.length
					? app.money( takings[ 0 ].gross_amount, takings[ 0 ].currency )
					: app.money( 0, 'EUR' ),
				app.t( 'panel.overview.takenThisMonth' ),
				takings.length > 1
					? takings.slice( 1 ).map( function ( row ) {
						return app.money( row.gross_amount, row.currency );
					} ).join( ' · ' )
					: '',
				'reports'
			) );
		}

		if ( data.door ) {
			tiles.push( statTile(
				app,
				app.number( Math.round( data.door.rate * 100 ) ) + '%',
				app.t( 'panel.overview.checkedIn' ),
				app.t( 'panel.overview.ofIssued', {
					checked: app.number( data.door.checked_in ),
					issued: app.number( data.door.issued ),
				} ),
				'reports'
			) );
		}

		tiles.push( statTile(
			app,
			app.number( data.sites.live ),
			app.t( 'panel.overview.sitesLive' ),
			'',
			'sites'
		) );

		return tiles.join( '' );
	}

	function overviewNights( app, nights ) {
		if ( ! nights.length ) {
			return emptyState( 'calendar', app.t( 'panel.overview.nothingOn' ),
				esc( app.t( 'panel.overview.nothingOnBody' ) ) );
		}

		return '<ul class="nights">' + nights.map( function ( night ) {
			var percent = Math.round( night.sold_ratio * 100 );

			return '<li class="night">' +
				'<div class="night__when"><span class="night__day tnum">' +
					esc( app.date( night.starts_at, { day: 'numeric' } ) ) + '</span>' +
					'<span class="night__month">' +
					esc( app.date( night.starts_at, { month: 'short' } ) ) + '</span></div>' +
				'<div class="night__body">' +
					'<p class="night__name">' + esc( night.name ) + '</p>' +
					'<p class="night__meta">' + esc( app.date( night.starts_at, {
						hour: '2-digit', minute: '2-digit',
					} ) ) + ( night.venue ? ' · ' + esc( night.venue ) : '' ) + '</p>' +
					'<div class="meter" role="img" aria-label="' +
						esc( app.t( 'panel.overview.soldOf', {
							sold: app.number( night.allocated ),
							total: app.number( night.seats_total ),
						} ) ) + '">' +
						'<span class="meter__fill" style="inline-size: ' + percent + '%"></span>' +
					'</div>' +
				'</div>' +
				'<div class="night__figures">' +
					'<span class="night__sold tnum">' + esc( app.t( 'panel.overview.soldOf', {
						sold: app.number( night.allocated ),
						total: app.number( night.seats_total ),
					} ) ) + '</span>' +
					( null === night.gross_amount || undefined === night.gross_amount
						? ''
						: '<span class="night__gross tnum">' +
							esc( app.money( night.gross_amount, night.currency ) ) + '</span>' ) +
				'</div>' +
				'</li>';
		} ).join( '' ) + '</ul>';
	}

	function overviewActivity( app, entries ) {
		if ( ! entries.length ) {
			return '<p class="muted">' + esc( app.t( 'panel.overview.nothingYet' ) ) + '</p>';
		}

		return '<ul class="feed">' + entries.map( function ( entry ) {
			// The same names the Activity screen uses; borrowed rather than restated, so a
			// device does not become a "Device" here and a "Scanner" there.
			var who = entry.actor.name || app.t( 'team.' + ( {
				api_key: 'apiKey', device: 'device', system: 'system',
			}[ entry.actor.type ] || 'system' ) );

			return '<li class="feed__item">' +
				'<span class="feed__what">' + esc( entry.action ) + '</span>' +
				( entry.subject_label
					? '<span class="feed__subject">' + esc( entry.subject_label ) + '</span>'
					: '' ) +
				'<span class="feed__who">' + esc( who ) + ' · ' +
					esc( app.date( entry.created_at, {
						month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
					} ) ) + '</span>' +
				'</li>';
		} ).join( '' ) + '</ul>';
	}

	App.renderEvents = function () {
		var self = this;

		this.loading( this.t( 'panel.nav.events' ) );

		Promise.all( [ this.request( 'GET', '/events' ), this.request( 'GET', '/seat-maps' ) ] )
			.then( function ( results ) {
				var sellable = results[ 1 ].data.filter( function ( map ) { return !! map.published_version; } );

				var rows = results[ 0 ].data.map( function ( event ) {
					return '<tr><td class="table__primary">' + esc( event.name ) + '</td>' +
						'<td class="tnum">' + esc( App.date( event.starts_at ) ) + '</td>' +
						'<td>' + badge( self.t( 'panel.eventStatus.' + event.status ),
							STATUS_TONE[ event.status ] ) + '</td>' +
						'<td class="tnum">' + priceRange( event ) + '</td>' +
						'<td><code>' + esc( event.public_id ) + '</code>' +
						'<button class="icon-btn icon-btn--sm" data-copy="' + esc( event.public_id ) +
						'" data-tip="' + esc( self.t( 'panel.common.copy' ) ) + '" aria-label="' +
						esc( self.t( 'panel.events.copyPublicId' ) ) + '">' + icon( 'copy', { size: 14 } ) +
						'</button></td>' +
						'<td class="table__actions">' +
						actionButton( 'event-edit', event.id, self.t( 'panel.events.edit' ), 'settings' ) +
						actionButton( 'prices', event.id, App.t( 'pricing.openPrices' ), 'tag' ) +
						actionButton( 'stats', event.id, self.t( 'panel.events.inventory' ), 'layers' ) +
						'</td></tr>';
				} ).join( '' );

				self.page( {
					// The one description that carries markup of its own: the shortcode is code, and
					// showing it as text would leave an organiser copying the wrong thing.
					title: self.t( 'panel.nav.events' ),
					description: self.t( 'panel.events.description' ),
					actions: sellable.length
						? '<button class="btn btn--primary" id="add-event">' +
							icon( 'plus', { size: 15 } ) + esc( self.t( 'panel.events.new' ) ) + '</button>'
						: '',
					body: table(
						[
							self.t( 'panel.common.name' ),
							self.t( 'panel.events.starts' ),
							self.t( 'panel.common.status' ),
							{ label: App.t( 'pricing.prices' ), numeric: true },
							self.t( 'panel.events.publicId' ),
							'',
						],
						rows,
						sellable.length
							? emptyState( 'calendar', self.t( 'panel.events.emptyTitle' ),
								esc( self.t( 'panel.events.emptyBody' ) ) )
							: emptyState( 'map', self.t( 'panel.events.needChartTitle' ),
								esc( self.t( 'panel.events.needChartBody' ) ) )
					),
				} );

				var add = document.getElementById( 'add-event' );

				if ( add ) {
					add.addEventListener( 'click', function () { self.newEvent( sellable ); } );
				}

				self.main().querySelectorAll( '[data-event-edit]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var event = results[ 0 ].data.filter( function ( row ) {
							return row.id === button.dataset.eventEdit;
						} )[ 0 ];

						if ( event ) {
							self.editEvent( event );
						}
					} );
				} );

				self.main().querySelectorAll( '[data-prices]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						window.SeatmapPricing.open( self, button.dataset.prices );
					} );
				} );

				self.main().querySelectorAll( '[data-stats]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.showStats( button.dataset.stats, button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
					} );
				} );

				self.main().querySelectorAll( '[data-copy]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						copyText( button.dataset.copy );
						self.toast( self.t( 'panel.events.publicIdCopied' ) );
					} );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	/**
	 * The timezones a browser knows, for the field that decides what "21:00" means.
	 *
	 * Offered as a datalist rather than a select: the list is six hundred long, and typing three
	 * letters of a city is faster than scrolling to it. Browsers without the list still get a text
	 * field, which the server validates anyway.
	 */
	function timezoneOptions() {
		var zones = [];

		try {
			zones = Intl.supportedValuesOf( 'timeZone' );
		} catch ( e ) {
			// An older browser. The field stays a plain text input; nothing is lost but the list.
			zones = [];
		}

		return zones.map( function ( zone ) {
			return '<option value="' + esc( zone ) + '">';
		} ).join( '' );
	}

	/** The browser's own zone, as the sensible default for somebody creating their first event. */
	function hereZone() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		} catch ( e ) {
			return 'UTC';
		}
	}

	/**
	 * An ISO instant as the wall clock at the venue, in the shape `datetime-local` wants.
	 *
	 * en-CA because it writes dates the way the input parses them — year first, zero padded — and
	 * not because anybody here is Canadian.
	 */
	function wallClock( iso, zone ) {
		if ( ! iso ) {
			return '';
		}

		var parts = new Intl.DateTimeFormat( 'en-CA', {
			timeZone: zone || 'UTC',
			year: 'numeric', month: '2-digit', day: '2-digit',
			hour: '2-digit', minute: '2-digit', hour12: false,
		} ).formatToParts( new Date( iso ) ).reduce( function ( found, part ) {
			found[ part.type ] = part.value;

			return found;
		}, {} );

		// Midnight comes back as "24" from some engines, which is the same instant written the one
		// way `datetime-local` refuses to parse.
		var hour = '24' === parts.hour ? '00' : parts.hour;

		return parts.year + '-' + parts.month + '-' + parts.day + 'T' + hour + ':' + parts.minute;
	}

	/**
	 * The fields an event has, shared by the form that creates one and the form that edits it.
	 *
	 * One list, so a field added for the website cannot quietly exist on only one of the two — the
	 * failure that leaves an organiser able to set a poster but never change it.
	 */
	function eventFields( event, maps ) {
		var zone = ( event && event.timezone ) || hereZone();

		return '<div class="stack">' +
			'<div class="field"><label class="field__label" for="e-name">' +
			esc( App.t( 'panel.common.name' ) ) + '</label>' +
			'<input class="input" id="e-name" name="name" required maxlength="200" ' +
			'placeholder="' + esc( App.t( 'panel.events.namePlaceholder' ) ) + '" value="' +
			esc( event ? event.name : '' ) + '"></div>' +

			'<div class="field"><label class="field__label" for="e-category">' +
			esc( App.t( 'panel.events.category' ) ) + '</label>' +
			'<input class="input" id="e-category" name="category" maxlength="40" ' +
			'placeholder="' + esc( App.t( 'panel.events.categoryPlaceholder' ) ) + '" value="' +
			esc( ( event && event.category ) || '' ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.events.categoryHint' ) ) + '</span></div>' +

			( maps
				? '<div class="field"><label class="field__label" for="e-map">' +
					esc( App.t( 'panel.events.seatMap' ) ) + '</label>' +
					'<select class="select" id="e-map" name="seat_map_id" required>' +
					maps.map( function ( map ) {
						return '<option value="' + esc( map.id ) + '">' + esc( map.name ) +
							' — v' + map.published_version.version + '</option>';
					} ).join( '' ) +
					'</select><span class="field__hint">' + esc( App.t( 'panel.events.seatMapHint' ) ) +
					'</span></div>'
				: '' ) +

			'<div class="field"><label class="field__label" for="e-starts">' +
			esc( App.t( 'panel.events.starts' ) ) + '</label>' +
			'<input class="input" id="e-starts" name="starts_at" type="datetime-local" required value="' +
			esc( event ? wallClock( event.starts_at, zone ) : '' ) + '"></div>' +

			'<div class="field"><label class="field__label" for="e-timezone">' +
			esc( App.t( 'panel.events.timezone' ) ) + '</label>' +
			'<input class="input" id="e-timezone" name="timezone" list="e-timezones" required value="' +
			esc( zone ) + '">' +
			'<datalist id="e-timezones">' + timezoneOptions() + '</datalist>' +
			'<span class="field__hint">' + esc( App.t( 'panel.events.timezoneHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="e-currency">' +
			esc( App.t( 'pricing.currency' ) ) + '</label>' +
			'<input class="input input--code" id="e-currency" name="currency" list="e-currencies" ' +
			'maxlength="3" required value="' +
			esc( ( event && event.currency ) || window.SeatmapPricing.CURRENCIES[ 0 ] ) + '">' +
			'<datalist id="e-currencies">' +
			window.SeatmapPricing.CURRENCIES.map( function ( code ) {
				return '<option value="' + code + '">';
			} ).join( '' ) +
			'</datalist>' +
			'<span class="field__hint">' + esc( App.t( 'pricing.currencyHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="e-status">' +
			esc( App.t( 'panel.common.status' ) ) + '</label>' +
			'<select class="select" id="e-status" name="status">' +
			[ 'draft', 'published', 'closed', 'cancelled' ].map( function ( status ) {
				return '<option value="' + status + '"' +
					( event && event.status === status ? ' selected' : '' ) + '>' +
					esc( App.t( 'panel.eventStatus.' + status ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>' +

			'<div class="field"><label class="field__label" for="e-image">' +
			esc( App.t( 'panel.events.artwork' ) ) + '</label>' +
			'<input class="input" id="e-image" name="image_url" type="url" maxlength="500" ' +
			'placeholder="https://" value="' + esc( ( event && event.image_url ) || '' ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.events.artworkHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="e-about">' +
			esc( App.t( 'panel.events.about' ) ) + '</label>' +
			'<textarea class="input" id="e-about" name="description" rows="4" maxlength="5000">' +
			esc( ( event && event.description ) || '' ) + '</textarea>' +
			'<span class="field__hint">' + esc( App.t( 'panel.events.aboutHint' ) ) + '</span></div>' +
			'</div>';
	}

	/** Empty is nothing, not an empty string: `''` is not a URL and would fail validation. */
	function orNull( value ) {
		var text = String( value == null ? '' : value ).trim();

		return '' === text ? null : text;
	}

	/** The fields both forms send, read back off the form itself. */
	function eventPayload( data ) {
		return {
			name: data.get( 'name' ),
			category: orNull( data.get( 'category' ) ),
			starts_at: data.get( 'starts_at' ),
			timezone: data.get( 'timezone' ),
			currency: String( data.get( 'currency' ) || '' ).trim().toUpperCase(),
			status: data.get( 'status' ),
			image_url: orNull( data.get( 'image_url' ) ),
			description: orNull( data.get( 'description' ) ),
		};
	}

	App.newEvent = function ( maps ) {
		var self = this;

		this.modal( {
			title: this.t( 'panel.events.new' ),
			submitLabel: this.t( 'panel.events.create' ),
			body: eventFields( null, maps ),
			onSubmit: function ( data ) {
				var payload = eventPayload( data );

				payload.seat_map_id = data.get( 'seat_map_id' );

				return self.request( 'POST', '/events', payload ).then( function () {
					self.toast( self.t( 'panel.events.created' ) );
					self.renderEvents();
				} );
			},
		} );
	};

	/**
	 * Everything about an event except which chart it sells against.
	 *
	 * That one is deliberately absent: an event keeps selling against the version published when it
	 * was created, and moving a live event onto another chart would strand every seat already sold.
	 */
	App.editEvent = function ( event ) {
		var self = this;

		this.modal( {
			title: event.name,
			submitLabel: this.t( 'panel.common.save' ),
			body: eventFields( event, null ),
			onSubmit: function ( data ) {
				return self.request( 'PATCH', '/events/' + event.id, eventPayload( data ) )
					.then( function () {
						self.toast( self.t( 'panel.events.saved' ) );
						self.renderEvents();
					} );
			},
		} );
	};

	App.showStats = function ( eventId, name ) {
		var self = this;

		this.request( 'GET', '/events/' + eventId + '/stats' ).then( function ( stats ) {
			var cells = [
				[ 'seats_total', 'places' ],
				[ 'available', 'available' ],
				[ 'held', 'held' ],
				[ 'allocated', 'sold' ],
				[ 'blocked', 'blocked' ],
				[ 'checked_in', 'checkedIn' ],
			].map( function ( pair ) {
				return '<div class="stat stat--block"><span class="stat__value tnum">' +
					esc( self.number( stats[ pair[ 0 ] ] ) ) + '</span><span class="stat__label">' +
					esc( self.t( 'panel.inventory.' + pair[ 1 ] ) ) + '</span></div>';
			} ).join( '' );

			self.modal( {
				title: name || self.t( 'panel.inventory.title' ),
				cancelLabel: null,
				doneLabel: self.t( 'panel.common.close' ),
				body: '<div class="stat-grid">' + cells + '</div>' +
					'<p class="hint">' + esc( self.t( 'panel.inventory.hint' ) ) + '</p>',
			} );
		} ).catch( function ( error ) { self.toast( error.message, true ); } );
	};

	App.renderConnections = function () {
		var self = this;

		this.loading( this.t( 'panel.nav.connections' ) );

		Promise.all( [
			this.request( 'GET', '/api-clients' ),
			// The snippet below needs an event to point at, and an organiser reading this screen
			// should not have to go and fetch an id from another one.
			this.request( 'GET', '/events?per_page=50' ).catch( function () { return { data: [] }; } ),
		] )
			.then( function ( results ) {
				var response = results[ 0 ];
				var events = results[ 1 ].data || [];
				var rows = response.data.map( function ( client ) {
					var keys = client.keys.map( function ( key ) {
						return '<div class="row"><code>' + esc( key.key_id ) + '</code>' +
							'<span class="muted">…' + esc( key.secret_hint || '' ) + '</span>' +
							'<button class="icon-btn icon-btn--sm" data-revoke="' + esc( client.id ) +
							'" data-key="' + esc( key.key_id ) + '" data-tip="' +
							esc( self.t( 'panel.connections.revoke' ) ) + '" ' +
							'data-tip-side="bottom-end" aria-label="' +
							esc( self.t( 'panel.connections.revokeThis' ) ) + '">' +
							icon( 'trash', { size: 14 } ) + '</button></div>';
					} ).join( '' );

					return '<tr><td class="table__primary">' + esc( client.name ) + '</td>' +
						'<td>' + ( client.site_url
							? '<a href="' + esc( client.site_url ) + '" rel="noreferrer noopener" target="_blank">' +
								esc( client.site_url ) + '</a>'
							: '<span class="muted">—</span>' ) + '</td>' +
						'<td>' + ( keys || '<span class="muted">' +
							esc( self.t( 'panel.connections.noKeys' ) ) + '</span>' ) + '</td>' +
						'<td class="muted">' + esc( client.last_seen_at
							? App.date( client.last_seen_at )
							: self.t( 'panel.common.never' ) ) + '</td>' +
						'<td class="table__actions">' +
						actionButton( 'rotate', client.id, self.t( 'panel.connections.rotate' ), 'key' ) +
						'</td></tr>';
				} ).join( '' );

				self.page( {
					title: self.t( 'panel.nav.connections' ),
					description: esc( self.t( 'panel.connections.description' ) ),
					actions: '<button class="btn btn--primary" id="add-client">' +
						icon( 'plus', { size: 15 } ) + esc( self.t( 'panel.connections.connect' ) ) +
						'</button>',
					body: table(
						[
							self.t( 'panel.connections.site' ),
							self.t( 'panel.connections.url' ),
							self.t( 'panel.connections.keys' ),
							self.t( 'panel.connections.lastSeen' ),
							'',
						],
						rows,
						emptyState( 'plug', self.t( 'panel.connections.emptyTitle' ),
							esc( self.t( 'panel.connections.emptyBody' ) ) )
					) + self.embedMarkup( events ),
				} );

				self.bindEmbed( events );

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

	/**
	 * The picker, on a website that is not a shop and not one of ours.
	 *
	 * Two lines somebody pastes into their own page. There is no key in it, and there is nothing
	 * to configure: the script is served by this API, so it knows where the API is, and everything
	 * it needs about the event is public. Payment happens on the organiser's own hosted checkout,
	 * which is why a page that pastes this in takes on nothing it cannot honour.
	 */
	App.embedMarkup = function ( events ) {
		var self = this;

		if ( ! events.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( this.t( 'panel.connections.embedTitle' ) ) + '</h3>' +
			'<div class="card card--pad">' +
				'<p class="hint spaced-none">' + esc( this.t( 'panel.connections.embedHint' ) ) + '</p>' +
				'<div class="filters spaced">' +
					'<select class="select" id="embed-event" aria-label="' +
						esc( this.t( 'panel.connections.embedEvent' ) ) + '">' +
						events.map( function ( event ) {
							return '<option value="' + esc( event.public_id ) + '">' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<button class="btn" id="embed-copy">' + icon( 'copy', { size: 15 } ) +
						esc( this.t( 'panel.common.copy' ) ) + '</button>' +
				'</div>' +
				'<pre class="snippet" id="embed-snippet">' +
					esc( this.embedSnippet( events[ 0 ].public_id ) ) + '</pre>' +
			'</div>';
	};

	App.embedSnippet = function ( publicId ) {
		// The API's own origin, taken from where this panel is talking to it, so the snippet is
		// right on every deployment without anybody typing a URL.
		var api = String( this.api || '' ).replace( /\/v1\/?$/, '' );

		return '<div data-seatmap-event="' + publicId + '"></div>\n' +
			'<script src="' + api + '/embed/v1/seatmap.js" async></' + 'script>';
	};

	App.bindEmbed = function ( events ) {
		var self = this;
		var chooser = document.getElementById( 'embed-event' );
		var snippet = document.getElementById( 'embed-snippet' );

		if ( ! chooser || ! snippet ) {
			return;
		}

		chooser.addEventListener( 'change', function () {
			snippet.textContent = self.embedSnippet( chooser.value );
		} );

		document.getElementById( 'embed-copy' ).addEventListener( 'click', function () {
			copyText( snippet.textContent );
			self.toast( self.t( 'panel.connections.embedCopied' ) );
		} );
	};

	App.newClient = function () {
		var self = this;

		this.modal( {
			title: this.t( 'panel.connections.connect' ),
			submitLabel: this.t( 'panel.connections.submit' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="c-name">' +
				esc( this.t( 'panel.connections.siteName' ) ) + '</label>' +
				'<input class="input" id="c-name" name="name" required maxlength="120" ' +
				'placeholder="' + esc( this.t( 'panel.connections.siteNamePlaceholder' ) ) + '"></div>' +
				'<div class="field"><label class="field__label" for="c-url">' +
				esc( this.t( 'panel.connections.siteUrl' ) ) + '</label>' +
				'<input class="input" id="c-url" name="site_url" type="url" placeholder="https://example.com">' +
				'<span class="field__hint">' + esc( this.t( 'panel.connections.siteUrlHint' ) ) +
				'</span></div>' +
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
			title: this.t( rotated ? 'panel.connections.issued' : 'panel.connections.connected' ),
			cancelLabel: null,
			doneLabel: this.t( 'panel.connections.saved' ),
			body:
				'<div class="credentials">' +
					'<div class="row"><strong>' + esc( this.t( 'panel.connections.keyId' ) ) +
					'</strong></div>' +
					'<div class="credentials__row"><code>' + esc( credentials.key_id ) + '</code>' +
					'<button type="button" class="btn btn--sm" data-copy-key>' +
					icon( 'copy', { size: 14 } ) + esc( this.t( 'panel.common.copy' ) ) + '</button></div>' +
					'<div class="row"><strong>' + esc( this.t( 'panel.connections.secret' ) ) +
					'</strong></div>' +
					'<div class="credentials__row"><code>' + esc( credentials.secret ) + '</code>' +
					'<button type="button" class="btn btn--sm" data-copy-secret>' +
					icon( 'copy', { size: 14 } ) + esc( this.t( 'panel.common.copy' ) ) + '</button></div>' +
					'<p class="hint">' + esc( this.t( 'panel.connections.secretHint' ) ) + '</p>' +
				'</div>',
			onClose: function () { self.renderConnections(); },
		} );

		var host = document.querySelector( '.modal' );

		host.querySelector( '[data-copy-key]' ).addEventListener( 'click', function () {
			copyText( credentials.key_id );
			self.toast( self.t( 'panel.connections.keyIdCopied' ) );
		} );

		host.querySelector( '[data-copy-secret]' ).addEventListener( 'click', function () {
			copyText( credentials.secret );
			self.toast( self.t( 'panel.connections.secretCopied' ) );
		} );
	};

	App.revokeKey = function ( clientId, keyId ) {
		var self = this;

		this.modal( {
			title: this.t( 'panel.connections.revokeTitle' ),
			submitLabel: this.t( 'panel.connections.revoke' ),
			body: '<p>' + this.t( 'panel.connections.revokeBody', { key: esc( keyId ) } ) + '</p>',
			onSubmit: function () {
				return self.request( 'DELETE', '/api-clients/' + clientId + '/keys/' + keyId )
					.then( function () {
						self.toast( self.t( 'panel.connections.revoked' ) );
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
		var t = App.t.bind( App );

		return '' +
		'<div class="designer">' +
			'<div class="designer__bar">' +
				'<div class="designer__title">' +
					toolbarButton( 'dz-close', 'back', t( 'panel.designer.back' ) ) +
					'<span class="designer__name">' + esc( map.name ) + '</span>' +
					'<span class="badge badge--warn" id="dz-readonly" hidden>' +
						icon( 'lock', { size: 13 } ) + esc( t( 'panel.designer.readOnly' ) ) + '</span>' +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-undo', 'undo', t( 'panel.designer.undo' ) ) +
					toolbarButton( 'dz-redo', 'redo', t( 'panel.designer.redo' ) ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-duplicate', 'duplicate', t( 'panel.designer.duplicate' ) ) +
					toolbarButton( 'dz-copy', 'copy', t( 'panel.designer.copy' ) ) +
					toolbarButton( 'dz-delete', 'trash', t( 'panel.designer.delete' ) ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-mirror-h', 'flipH', t( 'panel.designer.mirrorHorizontally' ) ) +
					toolbarButton( 'dz-mirror-v', 'flipV', t( 'panel.designer.mirrorVertically' ) ) +
				'</div>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-focal', 'target', t( 'panel.designer.focalPoint' ) ) +
					toolbarButton( 'dz-labels', 'tag', t( 'panel.designer.toggleLabels' ) ) +
					toolbarButton( 'dz-add-floor', 'plus', t( 'panel.designer.addFloor' ) ) +
				'</div>' +
				'<span class="designer__spacer"></span>' +
				'<div class="toolbar-group">' +
					toolbarButton( 'dz-lock', 'unlock', t( 'panel.designer.lock' ) ) +
					toolbarButton( 'dz-preview', 'eye', t( 'panel.designer.preview' ) ) +
					toolbarButton( 'dz-theme', 'moon', t( 'panel.shell.darkTheme' ) ) +
					toolbarButton( 'dz-help', 'help', t( 'panel.shortcuts.title' ), 'bottom-end' ) +
				'</div>' +
				'<button class="btn" id="dz-save">' + icon( 'save', { size: 15 } ) +
					esc( t( 'panel.designer.saveDraft' ) ) + '</button>' +
				'<button class="btn btn--primary" id="dz-publish">' +
					icon( 'publish', { size: 15 } ) + esc( t( 'panel.designer.publish' ) ) + '</button>' +
			'</div>' +
			'<div class="designer__body">' +
				'<div class="tools" id="dz-tools" role="toolbar" aria-label="' +
					esc( t( 'panel.designer.tools' ) ) + '"></div>' +
				'<div class="stage">' +
					'<div class="stage__canvas"><canvas id="dz-canvas"></canvas></div>' +
					'<div class="float layers" id="dz-layers"></div>' +
					'<button class="float stage__exit" id="dz-exit" hidden>' +
						icon( 'back', { size: 15 } ) + esc( t( 'panel.designer.exitSection' ) ) + '</button>' +
					'<div class="float zoom">' +
						'<button class="icon-btn icon-btn--sm" id="dz-zoom-out" aria-label="' +
							esc( t( 'panel.designer.zoomOut' ) ) + '">' +
							icon( 'minus', { size: 16 } ) + '</button>' +
						'<button class="zoom__level" id="dz-zoom-level" data-tip="' +
							esc( t( 'panel.designer.fit' ) ) + '" ' +
							'aria-label="' + esc( t( 'panel.designer.fit' ) ) + '">100%</button>' +
						'<button class="icon-btn icon-btn--sm" id="dz-zoom-in" aria-label="' +
							esc( t( 'panel.designer.zoomIn' ) ) + '">' +
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
		{ key: 'select', icon: 'cursor' },
		{ key: 'lasso', icon: 'lasso' },
		{ key: 'sameType', icon: 'wand' },
		{ separator: true },
		{ key: 'row', icon: 'row' },
		{ key: 'curvedRow', icon: 'curvedRow' },
		{ key: 'section', icon: 'section' },
		{ key: 'table', icon: 'table' },
		{ key: 'booth', icon: 'booth' },
		{ key: 'area', icon: 'area' },
		{ separator: true },
		{ key: 'shape', icon: 'shape' },
		{ key: 'line', icon: 'line' },
		{ key: 'text', icon: 'text' },
		{ key: 'image', icon: 'image' },
		{ key: 'icon', icon: 'accessibility' },
		{ separator: true },
		{ key: 'pan', icon: 'hand' },
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
			var label = self.t( 'panel.tools.' + tool.key );

			button.innerHTML = icon( tool.icon );
			button.dataset.tool = tool.key;
			button.setAttribute( 'data-tip', label );
			button.setAttribute( 'data-tip-side', 'right' );
			button.setAttribute( 'aria-label', label );
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
		on( 'dz-copy', function () { editor.copy(); self.toast( self.t( 'panel.designer.copied' ) ); } );
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
			self.toast( self.t( editor.locked ? 'panel.designer.locked' : 'panel.designer.unlocked' ) );
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
		button.setAttribute( 'data-tip', this.t( dark ? 'panel.shell.lightTheme' : 'panel.shell.darkTheme' ) );
		button.setAttribute( 'aria-label',
			this.t( dark ? 'panel.shell.switchToLight' : 'panel.shell.switchToDark' ) );
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
			lock.setAttribute( 'data-tip',
				this.t( this.editor.locked ? 'panel.designer.unlock' : 'panel.designer.lock' ) );
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
			target.textContent = 1 === editor.seatSelection.length
				? this.t( 'panel.designer.seatSelected' )
				: this.t( 'panel.designer.seatsSelected', { count: this.number( editor.seatSelection.length ) } );

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
			( 1 === editor.selection.length
				? this.t( 'panel.designer.objectSelected' )
				: this.t( 'panel.designer.objectsSelected', { count: this.number( editor.selection.length ) } ) ) +
			( children ? ' ' + this.t( 'panel.designer.children', { count: this.number( children ) } ) : '' );
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
		host.appendChild( node( 'h4', 'layers__title overline', this.t( 'panel.designer.selectionLayer' ) ) );

		var floor = this.editor.floor();
		var counts = { all: 0 };

		Chart.LAYERS.forEach( function ( layer ) { counts[ layer ] = 0; } );

		( floor.objects || [] ).forEach( function ( object ) {
			counts.all += 1;
			counts[ object.layer || 'interactive' ] += 1;
		} );

		[ 'all' ].concat( Chart.LAYERS.slice().reverse() ).forEach( function ( layer ) {
			var button = node( 'button', 'layer' );
			button.appendChild( node( 'span', null, self.t( 'panel.chart.layers.' + layer ) ) );
			button.appendChild( node( 'span', 'layer__count', self.number( counts[ layer ] ) ) );

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
		gear.setAttribute( 'data-tip', this.t( 'panel.designer.floorSettings' ) );
		gear.setAttribute( 'data-tip-side', 'bottom-end' );
		gear.setAttribute( 'aria-label', this.t( 'panel.designer.floorSettings' ) );
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
			title: this.t( 'panel.floors.addTitle' ),
			submitLabel: this.t( 'panel.floors.addSubmit' ),
			body: '<div class="field"><label class="field__label" for="f-name">' +
				esc( this.t( 'panel.floors.name' ) ) + '</label>' +
				'<input class="input" id="f-name" name="name" required maxlength="60" value="' +
				esc( this.t( 'panel.floors.level', {
					number: this.number( this.editor.chart.floors.length + 1 ),
				} ) ) + '"></div>',
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
			title: this.t( 'panel.floors.title' ),
			submitLabel: this.t( 'panel.floors.rename' ),
			body: '<div class="field"><label class="field__label" for="f-rename">' +
				esc( this.t( 'panel.floors.name' ) ) + '</label>' +
				'<input class="input" id="f-rename" name="name" required maxlength="60" value="' +
				esc( floor.name ) + '"></div>' +
				'<p class="hint spaced">' +
				esc( this.t( removable ? 'panel.floors.removeWarning' : 'panel.floors.cannotRemove' ) ) +
				'</p>',
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
			var remove = node( 'button', 'btn btn--danger', this.t( 'panel.floors.remove' ) );
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
			title: this.t( 'panel.categories.title' ),
			cancelLabel: null,
			doneLabel: this.t( 'panel.common.done' ),
			body:
				'<div id="dz-cats" class="stack"></div>' +
				'<form id="dz-cat-form" class="row row--wrap spaced">' +
					'<input class="input grow" name="label" placeholder="' +
					esc( this.t( 'panel.categories.namePlaceholder' ) ) + '" required maxlength="60">' +
					'<input class="swatch" name="color" type="color" value="#5b63f0" aria-label="' +
					esc( this.t( 'panel.categories.colour' ) ) + '">' +
					'<label class="row"><input class="checkbox" name="accessible" type="checkbox">' +
					'<span>' + esc( this.t( 'panel.categories.accessible' ) ) + '</span></label>' +
					'<button class="btn" type="submit">' + icon( 'plus', { size: 14 } ) +
					esc( this.t( 'panel.common.add' ) ) + '</button>' +
				'</form>' +
				'<p class="hint spaced">' + esc( this.t( 'panel.categories.hint' ) ) + '</p>',
			onClose: function () { self.refreshDesigner(); },
		} );

		function paint() {
			var list = host.querySelector( '#dz-cats' );

			list.innerHTML = '';

			if ( ! ( chart.categories || [] ).length ) {
				list.appendChild( node( 'p', 'muted', self.t( 'panel.categories.empty' ) ) );

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
					mark.setAttribute( 'data-tip', self.t( 'panel.categories.accessible' ) );
					line.appendChild( mark );
				}

				var remove = node( 'button', 'icon-btn icon-btn--sm' );
				remove.type = 'button';
				remove.innerHTML = icon( 'trash', { size: 14 } );
				remove.setAttribute( 'data-tip', self.t( 'panel.common.remove' ) );
				remove.setAttribute( 'data-tip-side', 'bottom-end' );
				remove.setAttribute( 'aria-label',
					self.t( 'panel.categories.remove', { label: category.label } ) );

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

	/**
	 * The shortcut sheet.
	 *
	 * The key *names* stay in Latin — Shift, Enter, Ctrl/⌘ are printed on the reader's keyboard in
	 * those letters whatever language they read in, and translating them would name a key that is
	 * not there. What each combination does is translated; so are the three that are actions rather
	 * than keys: Click, drag and Double-click.
	 */
	App.showShortcuts = function () {
		var self = this;
		var keys = [
			[ [ 'Ctrl/⌘', 'Z' ], 'undo' ],
			[ [ 'Ctrl/⌘', 'Shift', 'Z' ], 'redo' ],
			[ [ 'Ctrl/⌘', 'A' ], 'selectAll' ],
			[ [ 'Ctrl/⌘', 'D' ], 'deselect' ],
			[ [ 'Ctrl/⌘', 'C' ], 'copy' ],
			[ [ 'Ctrl/⌘', 'V' ], 'paste' ],
			[ [ 'Shift', this.t( 'panel.shortcuts.click' ) ], 'addRemove' ],
			[ [ '←', '→', '↑', '↓' ], 'nudge' ],
			[ [ 'Space', this.t( 'panel.shortcuts.drag' ) ], 'pan' ],
			[ [ 'Enter' ], 'closeShape' ],
			[ [ this.t( 'panel.shortcuts.doubleClick' ) ], 'enterSection' ],
			[ [ 'Delete' ], 'removeSelection' ],
		];

		var list = keys.map( function ( entry ) {
			return '<dt>' + entry[ 0 ].map( function ( key ) {
				return '<span class="kbd">' + esc( key ) + '</span>';
			} ).join( '' ) + '</dt><dd>' +
				esc( self.t( 'panel.shortcuts.' + entry[ 1 ] ) ) + '</dd>';
		} ).join( '' );

		this.modal( {
			title: this.t( 'panel.shortcuts.title' ),
			cancelLabel: null,
			doneLabel: this.t( 'panel.common.close' ),
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
				self.toast( self.t( 'panel.designer.draftSaved' ) );

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
				self.toast( self.t( 'panel.designer.published', {
					version: self.number( version.version ),
					count: self.number( version.seat_count ),
				} ) );
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
						( issues.length > 1
							? ' ' + self.t( 'panel.designer.andMore', {
								count: self.number( issues.length - 1 ),
							} )
							: '' );
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
