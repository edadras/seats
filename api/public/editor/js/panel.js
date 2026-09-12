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
	 * The key, the icon, and what the screen needs. The label is looked up when the nav is painted,
	 * because this array is built while the file loads — before the catalogue has been fetched — and
	 * a label captured then would be English for the rest of the session.
	 *
	 * `needs` is the permission the screen's *landing* call already checks on the server, named here
	 * so somebody is not offered a door that refuses them when they reach it. It hides, it does not
	 * protect: the refusal is the server's, and this list only keeps the nav honest about it. An
	 * entry with no `needs` is one everybody holds — the account's own sign-in security is nobody
	 * else's to grant — and a list of them is any-of, for the screens that answer a narrow question
	 * to one caller and a wide one to another.
	 */
	/*
	 * The sidebar.
	 *
	 * Grouped by the question somebody arrived with, not by the table the screen reads. That is a
	 * correction rather than a first draft: "Programme" had grown to twenty-one items, six of which
	 * — discounts, access codes, seasons, vouchers, points, memberships — all answer one question,
	 * "who gets in cheaper or earlier", and a person who wanted one of them had no way to know
	 * which. A group of twenty-one is a list; a list of six groups is a menu.
	 */
	var NAV = [
		{ group: null, items: [ { key: 'overview', icon: 'grid', needs: 'reports.attendance.view', notFor: 'manager' } ] },
		{ group: 'programme', items: [
			{ key: 'events', icon: 'calendar', needs: 'events.view' },
			{ key: 'productions', icon: 'map', needs: 'events.view', notFor: 'manager' },
			{ key: 'entryslots', icon: 'clock', needs: 'events.view' },
			{ key: 'questions', icon: 'file', needs: 'events.view' },
			// A dress rehearsal for the buyer's path. Here rather than beside the counter: it is
			// about a night rather than about a sale, and it is the last thing done to one before
			// it goes on sale.
			{ key: 'rehearsal', icon: 'eye', needs: 'events.manage' },
		] },
		{ group: 'selling', items: [
			{ key: 'counter', icon: 'ticket', needs: 'orders.sell' },
			{ key: 'tills', icon: 'wallet', needs: 'orders.sell' },
			{ key: 'orders', icon: 'file', needs: [ 'orders.view', 'orders.view.own' ] },
			{ key: 'plans', icon: 'clock', needs: 'orders.view' },
			{ key: 'baskets', icon: 'list', needs: 'orders.view' },
			{ key: 'agents', icon: 'users', needs: 'agents.manage' },
			// An agency's own account, offered to the agency. `when` rather than `needs` because
			// selling for somebody is not a permission — an owner holds every permission there is
			// and is nobody's agency.
			{ key: 'myagency', icon: 'wallet', when: 'agent' },
		] },
		/*
		 * Every way a person pays less than the wall price, or gets in before everybody else.
		 *
		 * These six were scattered through a group of twenty-one, each behind a name that only
		 * makes sense once you already know what it does. Together they are one question with six
		 * answers, which is a thing somebody can choose between.
		 */
		{ group: 'offers', items: [
			{ key: 'discounts', icon: 'tag', needs: 'discounts.manage' },
			{ key: 'access', icon: 'lock', needs: 'discounts.manage' },
			{ key: 'seasons', icon: 'calendar', needs: 'discounts.manage' },
			{ key: 'vouchers', icon: 'wallet', needs: 'vouchers.manage' },
			{ key: 'loyalty', icon: 'target', needs: 'vouchers.manage' },
			{ key: 'memberships', icon: 'users', needs: 'vouchers.manage' },
		] },
		// What the door works on, what it works from, and what it works with.
		{ group: 'door', items: [
			{ key: 'tickets', icon: 'ticket', needs: 'tickets.view' },
			{ key: 'doorlist', icon: 'check', needs: 'checkins.view' },
			{ key: 'scanners', icon: 'seat', needs: 'devices.manage' },
		] },
		{ group: 'venue', items: [
			{ key: 'maps', icon: 'map', needs: 'maps.view' },
			{ key: 'venues', icon: 'building', needs: 'venues.view' },
		] },
		{ group: 'audience', items: [
			{ key: 'customers', icon: 'users', needs: 'orders.view', notFor: 'manager' },
			{ key: 'waitlist', icon: 'clock', needs: 'orders.view' },
			{ key: 'messaging', icon: 'mail', needs: 'messages.send' },
			{ key: 'sites', icon: 'globe', needs: 'sites.view' },
			{ key: 'themes', icon: 'palette', needs: 'sites.view' },
			// The same website, as a thing on a phone's home screen. Beside the site rather than
			// under the account, because it *is* the site — one tile, one name, one question about
			// whether a browser will offer it.
			{ key: 'webapp', icon: 'ticket', needs: 'sites.view' },
		] },
		{ group: 'insight', items: [
			{ key: 'reports', icon: 'chart', needs: 'reports.attendance.view', notFor: 'manager' },
			{ key: 'settlement', icon: 'wallet', needs: 'reports.orders.view', notFor: 'manager' },
			{ key: 'promoters', icon: 'users', needs: 'reports.orders.view', notFor: 'manager' },
		] },
		{ group: 'account', items: [
			{ key: 'connections', icon: 'plug', needs: 'connections.manage' },
			{ key: 'modules', icon: 'puzzle', needs: 'modules.manage' },
			{ key: 'team', icon: 'users', needs: 'team.view' },
			{ key: 'managers', icon: 'users', needs: 'team.view' },
			{ key: 'billing', icon: 'wallet', needs: 'account.manage' },
			{ key: 'wallet', icon: 'ticket', needs: 'account.manage' },
			{ key: 'security', icon: 'lock' },
			{ key: 'audit', icon: 'history', needs: 'audit.view' },
			// Taking this account's data, and closing it. Last, because it is the last thing
			// anybody does here.
			{ key: 'leaving', icon: 'download', needs: 'account.manage' },
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
		permissions: null,
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
			/*
			 * An invitation link, which is the one URL the panel has that means something.
			 *
			 * Ahead of the token, because somebody who is already signed in as themselves may be
			 * opening a link that invites them somewhere else, and the link is the more recent
			 * intention. It is consumed either way: taken up, or refused with a reason.
			 */
			var invited = /^\/invite\/(.+)$/.exec( window.location.pathname );

			if ( invited ) {
				self.showInvitation( decodeURIComponent( invited[ 1 ] ) );

				return;
			}

			/*
			 * Coming back from the account's own provider.
			 *
			 * Ahead of the stored token for the same reason as an invitation: somebody who has just
			 * signed in somewhere else has said something more recent than whatever this tab
			 * remembers. The handle is spent immediately and taken out of the address bar either
			 * way, so a reload is never a second attempt at a handle that is already gone.
			 */
			if ( window.location.search.indexOf( 'sso=' ) > -1 ) {
				self.finishSso( new URLSearchParams( window.location.search ) );

				return;
			}

			if ( ! self.token ) {
				self.showLogin();

				return;
			}

			/*
			 * A token in hand is not the same as knowing what it may do. The stored profile is a
			 * tab's memory of a sign-in that may be a day old and a role that may since have been
			 * narrowed, so the truth is asked for before a single screen is offered — and a token
			 * the server no longer honours puts somebody back at the sign-in form instead of into
			 * a workspace where everything refuses.
			 */
			self.request( 'GET', '/auth/me' )
				.then( function ( me ) {
					self.remember( me, me.email || ( self.profile || {} ).email || '' );
					self.showWorkspace();
				} )
				.catch( function () {
					/*
					 * A token the server no longer honours has already put them back at the sign-in
					 * form — `request` does that on a 401. So anything landing here is the network
					 * having a bad moment, and the honest response is the tab's own memory of the
					 * last sign-in rather than throwing somebody out over one failed call.
					 */
					if ( self.token && self.profile ) {
						self.permissions = self.profile.permissions || null;
						// Including the calendar, which `remember` would have applied had the call
						// got through: a bad moment on the network should not quietly move a
						// venue's dates back to the reader's own calendar.
						i18n.useCalendar( self.profile.calendar );
						self.showWorkspace();
					}
				} );
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

	/* ------------------------------------------------------------------ the night in hand */

	/**
	 * Which night the panel is working on.
	 *
	 * Seven screens ask this — the counter, the door list, the tickets, the questions a checkout
	 * asks, the entry windows, the waiting list, the rehearsal — and each used to keep its own
	 * answer. So a clerk who chose Saturday on one screen was shown Friday on the next, from the
	 * same picker in the same place, with nothing to say the question had been asked again. It is
	 * one fact about what somebody is doing, so it is held once, and remembered for tomorrow.
	 *
	 * Per browser rather than on the account: two people at one box office are working on two
	 * different nights, and neither should move the other's screen.
	 */
	App.night = function ( id ) {
		if ( undefined === id ) {
			try {
				return window.localStorage.getItem( 'seatmap.night' ) || '';
			} catch ( error ) {
				return '';
			}
		}

		try {
			window.localStorage.setItem( 'seatmap.night', id || '' );
		} catch ( error ) {
			// A browser with storage off still works; the choice just lasts one screen.
		}

		return id;
	};

	/**
	 * The night a screen should open on.
	 *
	 * The one the panel is working on wins, and that is the point: a screen that remembered its own
	 * last answer would go on disagreeing with the one next to it, which is the thing being fixed.
	 * What a screen remembers is the fallback for when the shared night is not one it lists — the
	 * counter offers published nights only, so a draft chosen elsewhere cannot be its answer.
	 */
	App.pickNight = function ( events, current ) {
		var listed = function ( id ) {
			return !! id && ( events || [] ).some( function ( event ) { return event.id === id; } );
		};

		if ( listed( App.night() ) ) {
			return App.night();
		}

		if ( listed( current ) ) {
			return current;
		}

		return ( events || [] ).length ? events[ 0 ].id : '';
	};

	/** A whole branch of the catalogue — for the seat picker, which takes its words as an object. */
	App.catalogue = function ( key ) {
		return i18n.branch( key );
	};

	App.currencySymbol = function ( currency ) {
		return i18n.currencySymbol( currency );
	};

	/** Which of the two this panel is being read in — light or dark, whoever chose it. */
	App.theme = function () {
		return Theme.current();
	};

	/** The reader's locale, in the form Intl wants — the picker formats its own prices. */
	App.locale = function () {
		return i18n.icu;
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
		/*
		 * Which screen asked.
		 *
		 * Reads only: a mutation's answer is about something the reader *did*, and dropping it would
		 * lose a booking reference or leave a dialog spinning for ever. `options.keep` is the other
		 * exception — a read that belongs to the shell rather than to a screen, like the bell, which
		 * is filled once at sign-in and is still wanted three screens later.
		 */
		var visit = 'GET' === method && ! ( options && options.keep ) ? this.visit : null;
		var headers = { Accept: raw ? '*/*' : 'application/json' };

		// The panel's language travels with every call, so a message the *server* composes — a
		// module's name, a refusal — comes back in the language the panel is being read in.
		// Without this, the chrome is Persian and everything the API said is English.
		if ( i18n.locale ) {
			headers[ 'X-Seatmap-Locale' ] = i18n.locale;
		}

		/*
		 * A file is not JSON, and the boundary is not ours to invent.
		 *
		 * `FormData` goes through untouched and without a `Content-Type`: the browser writes one
		 * with the multipart boundary it chose, and a header set here would replace it with one
		 * that has no boundary in it — which the server reads as an empty request.
		 */
		var isForm = 'undefined' !== typeof FormData && body instanceof FormData;

		if ( body && ! isForm ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		if ( this.token ) {
			headers.Authorization = 'Bearer ' + this.token;
		}

		return fetch( this.api + path, {
			method: method,
			headers: headers,
			body: body ? ( isForm ? body : JSON.stringify( body ) ) : undefined,
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

				/*
				 * The reader moved on while this was in the air.
				 *
				 * Never settled rather than rejected: the caller's `.then()` would paint over the
				 * screen they are looking at now, and its `.catch()` would show them an error about
				 * a screen they have left. Letting the chain stop is the honest answer to "this is
				 * no longer wanted".
				 */
				if ( null !== visit && self.visit !== visit ) {
					return new Promise( function () {} );
				}

				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------------ auth shell */

	/**
	 * The other end of an invitation link.
	 *
	 * The token in the URL is asked about first, so somebody arrives at a screen that already knows
	 * who invited them and as what — a form that asks for a password before saying whose account it
	 * is asks for trust it has not earned. What it asks for depends on the answer: an address that
	 * already has an account proves itself with the password it has, and one that does not chooses
	 * one.
	 */
	App.showInvitation = function ( token ) {
		var self = this;

		this.root.innerHTML =
			'<div class="auth"><div class="auth__card">' +
				'<div class="auth__brand"><span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) +
				'</span>' + esc( this.t( 'panel.brand' ) ) + '</div>' +
				'<p class="muted">' + esc( this.t( 'panel.common.loading' ) ) + '</p>' +
			'</div></div>';

		this.request( 'POST', '/team/invitations/inspect', { token: token } )
			.then( function ( invitation ) { self.paintInvitation( token, invitation ); } )
			.catch( function ( error ) {
				// Spent, expired or never real. The way out is the sign-in form, because somebody
				// whose invitation is gone may well already have an account here.
				self.showLogin();
				self.toast( error.message, true );
				window.history.replaceState( {}, '', '/' );
			} );
	};

	App.paintInvitation = function ( token, invitation ) {
		var self = this;
		var known = !! invitation.has_account;
		// A built-in role has a translated name; one the account invented for itself does not.
		var role = this.has( 'team.roles.' + invitation.role )
			? this.t( 'team.roles.' + invitation.role )
			: titleCase( invitation.role );

		this.root.innerHTML =
			'<div class="auth"><form class="auth__card" id="join">' +
				'<div class="auth__brand"><span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) +
				'</span>' + esc( this.t( 'panel.brand' ) ) + '</div>' +
				'<h1 class="auth__title">' + esc( this.t( 'team.joinTitle', {
					organiser: invitation.organiser || '',
				} ) ) + '</h1>' +
				'<p class="auth__sub">' + esc( this.t( known ? 'team.joinBodyKnown' : 'team.joinBody', {
					role: role,
				} ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="join-email">' +
					esc( this.t( 'team.joinAs' ) ) + '</label>' +
					// Shown and not editable: the invitation is to this address and no other, so an
					// editable box would be a question with one right answer.
					'<input class="input" id="join-email" type="email" value="' +
					esc( invitation.email ) + '" readonly autocomplete="username"></div>' +
				( known ? '' :
					'<div class="field"><label class="field__label" for="join-name">' +
						esc( this.t( 'team.joinName' ) ) + '</label>' +
						'<input class="input" id="join-name" required autocomplete="name"></div>' ) +
				'<div class="field"><label class="field__label" for="join-password">' +
					esc( this.t( known ? 'team.joinExisting' : 'team.joinChoose' ) ) + '</label>' +
					'<input class="input" id="join-password" type="password" required minlength="12" ' +
					'autocomplete="' + ( known ? 'current-password' : 'new-password' ) + '">' +
					( known ? '' : '<p class="field__hint">' + esc( this.t( 'team.joinHint' ) ) + '</p>' ) +
				'</div>' +
				'<div class="issue issue--error" id="join-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">' +
					esc( this.t( 'team.joinSubmit' ) ) + '</button>' +
				'<p class="auth__foot"><button type="button" class="link" id="join-back">' +
					esc( this.t( 'team.joinBack' ) ) + '</button></p>' +
			'</form></div>';

		var form = document.getElementById( 'join' );
		var submit = form.querySelector( 'button[type=submit]' );
		var problem = document.getElementById( 'join-error' );
		var name = document.getElementById( 'join-name' );

		( name || document.getElementById( 'join-password' ) ).focus();

		document.getElementById( 'join-back' ).addEventListener( 'click', function () {
			window.history.replaceState( {}, '', '/' );
			self.showLogin();
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			problem.hidden = true;
			submit.disabled = true;
			submit.textContent = self.t( 'panel.common.working' );

			self.request( 'POST', '/team/invitations/accept', {
				token: token,
				name: name ? name.value : undefined,
				password: document.getElementById( 'join-password' ).value,
			} )
				.then( function ( response ) {
					// The link is spent now, so it comes out of the address bar before the workspace
					// paints: a reload must not send somebody back to a token that will refuse them.
					window.history.replaceState( {}, '', '/' );
					self.finishSignIn( response, invitation.email );
				} )
				.catch( function ( error ) {
					problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
					problem.hidden = false;
					submit.disabled = false;
					submit.textContent = self.t( 'team.joinSubmit' );
				} );
		} );
	};

	/**
	 * The other half of a sign-in that happened at the account's own provider.
	 *
	 * Four outcomes, and three of them are a sign-in screen with a sentence on it. The fourth
	 * trades a one-time handle for a token — a handle rather than the token itself, because a
	 * bearer credential in a URL is written into browser history, the next referrer and any proxy
	 * log between here and there.
	 */
	App.finishSso = function ( query ) {
		var self = this;
		var outcome = query.get( 'sso' );
		var handoff = query.get( 'handoff' );

		window.history.replaceState( {}, '', '/' );

		if ( 'ok' !== outcome || ! handoff ) {
			this.showLogin();

			// "unavailable" is one answer for an account nobody has heard of and an account that
			// does not sign in this way: telling them apart would answer a question nobody signed
			// in has any business asking.
			this.loginProblem( this.t( {
				unavailable: 'panel.sso.errUnavailable',
				stranger: 'panel.sso.errStranger',
			}[ outcome ] || 'panel.sso.errRefused' ) );

			return;
		}

		this.request( 'POST', '/auth/sso/claim', { handoff: handoff, device_name: 'panel' } )
			.then( function ( response ) {
				self.finishSignIn( response, '' );
			} )
			.catch( function ( error ) {
				self.showLogin();
				self.loginProblem( error.message );
			} );
	};

	/** Say something on the sign-in screen, whoever put it there. */
	App.loginProblem = function ( message ) {
		var problem = document.getElementById( 'login-error' );

		if ( problem ) {
			problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( message ) + '</span>';
			problem.hidden = false;
		}
	};

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
				'<p class="auth__foot"><button type="button" class="link" id="go-sso">' +
					esc( this.t( 'panel.sso.useYourOwn' ) ) + '</button></p>' +
				'<p class="auth__foot"><button type="button" class="link" id="go-signup">' +
					esc( this.t( 'signup.newAccount' ) ) + '</button></p>' +
			'</form></div>';

		var form = document.getElementById( 'login' );
		var submit = form.querySelector( 'button[type=submit]' );
		var problem = document.getElementById( 'login-error' );

		form.querySelector( '#email' ).focus();

		document.getElementById( 'go-signup' )
			.addEventListener( 'click', function () { window.SeatmapSignup.render( self ); } );

		/*
		 * Somebody whose venue signs in through its own provider.
		 *
		 * They are asked for their account's address rather than their own: an email typed here
		 * would have to be answered with "that account uses single sign-on" or "it does not", and
		 * that is a question about other people's organisations that nobody signed in should be
		 * able to ask a thousand times.
		 */
		document.getElementById( 'go-sso' ).addEventListener( 'click', function () {
			self.modal( {
				title: self.t( 'panel.sso.useYourOwn' ),
				submitLabel: self.t( 'panel.auth.signIn' ),
				body: '<div class="field"><label class="field__label" for="sso-slug">' +
					esc( self.t( 'panel.sso.accountAddress' ) ) + '</label>' +
					'<input class="input" id="sso-slug" name="slug" required autocomplete="off"></div>' +
					'<p class="field__hint">' + esc( self.t( 'panel.sso.accountAddressHint' ) ) + '</p>',
				onSubmit: function ( data ) {
					var slug = String( data.get( 'slug' ) || '' ).trim().toLowerCase();

					if ( ! /^[a-z0-9-]{1,80}$/.test( slug ) ) {
						return Promise.reject( new Error( self.t( 'panel.sso.accountAddressBad' ) ) );
					}

					window.location.href = '/sso/' + encodeURIComponent( slug );

					return Promise.resolve();
				},
			} );
		} );

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
					// The password was right and the account wants a second step. Not a refusal:
					// a half-finished sign-in, holding a challenge worth nothing on its own.
					if ( response.two_factor_required ) {
						return self.askForCode( response.challenge, String( data.get( 'email' ) || '' ) );
					}

					self.finishSignIn( response, String( data.get( 'email' ) || '' ) );
				} )
				.catch( function ( error ) {
					problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
					problem.hidden = false;
					submit.disabled = false;
					submit.textContent = self.t( 'panel.auth.signIn' );
				} );
		} );
	};

	/**
	 * What to keep once a sign-in is done, whichever half finished it.
	 *
	 * sessionStorage, not localStorage: the token dies with the tab rather than lingering on a
	 * shared machine.
	 */
	App.finishSignIn = function ( response, email ) {
		this.token = response.token;
		window.sessionStorage.setItem( STORE.token, response.token );
		this.remember( response, email );

		this.showWorkspace();

		if ( this.profile.must_set_up_two_factor ) {
			// The account requires it and this person has not set it up. Signing them in anyway
			// would make the requirement a suggestion; refusing would leave them no way to comply.
			this.toast( this.t( 'panel.security.mustSetUp' ), true );
			this.route( 'security' );
		}
	};

	/**
	 * Keep what the server said about whoever is holding this token.
	 *
	 * Both halves of a sign-in and every boot land here, so there is one shape of profile rather
	 * than three that drifted. Permissions are part of it: a nav built from anything else is a nav
	 * that offers screens the server refuses.
	 */
	App.remember = function ( response, email ) {
		this.permissions = Array.isArray( response.permissions ) ? response.permissions : null;
		this.profile = {
			email: email,
			tenant: response.tenant ? response.tenant.name : '',
			role: response.role || '',
			email_verified: false !== response.email_verified,
			must_set_up_two_factor: !! response.must_set_up_two_factor,
			permissions: this.permissions,
			agent: response.agent || null,
			programme_manager: !! response.programme_manager,
			// Which calendar this account writes its dates in. Remembered with the rest of the
			// sign-in so a reopened tab reads the same way before `/auth/me` has answered.
			calendar: ( response.tenant && response.tenant.calendar ) || 'auto',
		};

		window.sessionStorage.setItem( STORE.profile, JSON.stringify( this.profile ) );

		/*
		 * And applied, here, at the one moment both halves are in hand.
		 *
		 * The catalogue carries the calendar the *language* implies — it is cached publicly and must
		 * say nothing about an account — and this is the account's. Everything that formats a date
		 * reads the result, so this line is the whole of it.
		 */
		i18n.useCalendar( this.profile.calendar );
	};

	/**
	 * Whether this person holds a permission.
	 *
	 * An unknown answer is a yes, not a no: the server is the one that refuses, and a panel that
	 * hid everything because it had not been told yet would be a panel that broke the moment this
	 * list was not in hand. Hiding is a courtesy; the refusal is the rule.
	 */
	App.may = function ( permission ) {
		var held = this.permissions;

		if ( ! permission || ! Array.isArray( held ) ) {
			return true;
		}

		if ( Array.isArray( permission ) ) {
			return permission.some( function ( one ) { return -1 !== held.indexOf( one ); } );
		}

		return -1 !== held.indexOf( permission );
	};

	/** Whether one nav entry belongs to this person: what they hold, and who they are. */
	App.offers = function ( entry ) {
		if ( 'agent' === entry.when ) {
			return !! ( this.profile || {} ).agent;
		}

		/*
		 * A screen about the account rather than about a night.
		 *
		 * A programme manager holds `orders.view` exactly as the box office does — the difference is
		 * which bookings it reaches, and there is no honest way to show a promoter a quarter of the
		 * customer directory. The server refuses these; this stops them being offered.
		 */
		if ( 'manager' === entry.notFor && ( this.profile || {} ).programme_manager ) {
			return false;
		}

		return this.may( entry.needs );
	};

	/** Whether a view is one this person may open. An unlisted view is the panel's own business. */
	App.mayOpen = function ( view ) {
		var found = null;

		NAV.forEach( function ( section ) {
			section.items.forEach( function ( entry ) {
				if ( entry.key === view ) {
					found = entry;
				}
			} );
		} );

		return found ? this.offers( found ) : true;
	};

	/** The views this person may open, in nav order. */
	App.allowed = function () {
		var self = this;
		var keys = [];

		NAV.forEach( function ( section ) {
			section.items.forEach( function ( entry ) {
				if ( self.offers( entry ) ) {
					keys.push( entry.key );
				}
			} );
		} );

		return keys;
	};

	/**
	 * The second half of a sign-in.
	 *
	 * A modal that cannot be dismissed into a half-signed-in state: closing it leaves the login
	 * form exactly as it was, which is the honest outcome of not finishing.
	 */
	App.askForCode = function ( challenge, email ) {
		var self = this;
		var submit = document.querySelector( '#login button[type=submit]' );

		if ( submit ) {
			submit.disabled = false;
			submit.textContent = this.t( 'panel.auth.signIn' );
		}

		this.modal( {
			title: this.t( 'panel.security.codeTitle' ),
			submitLabel: this.t( 'panel.auth.signIn' ),
			body:
				'<div class="stack">' +
					'<p class="hint">' + esc( this.t( 'panel.security.codeBody' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="tfa-code">' +
						esc( this.t( 'panel.security.code' ) ) + '</label>' +
						'<input class="input input--code" id="tfa-code" inputmode="numeric" ' +
							'autocomplete="one-time-code" required></div>' +
					'<p class="field__hint">' + esc( this.t( 'panel.security.orRecovery' ) ) + '</p>' +
				'</div>',
			onSubmit: function () {
				return self.request( 'POST', '/auth/login/two-factor', {
					challenge: challenge,
					code: document.getElementById( 'tfa-code' ).value.trim(),
				} ).then( function ( response ) {
					self.finishSignIn( response, email );
				} );
			},
		} );
	};

	App.signOut = function () {
		window.sessionStorage.removeItem( STORE.token );
		window.sessionStorage.removeItem( STORE.profile );
		this.token = null;
		this.profile = null;
		this.permissions = null;
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
						'<div class="sidebar__language">' + this.languageField( 'locale' ) +
							this.calendarField() + '</div>' +
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

			// The seat picker at the counter is a canvas too, and it is a guest that has been told
			// which theme to wear rather than left to ask the operating system.
			if ( window.SeatmapCounter ) {
				window.SeatmapCounter.syncTheme();
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

		var calendar = document.getElementById( 'calendar' );

		if ( calendar ) {
			calendar.addEventListener( 'change', function () {
				self.request( 'PATCH', '/account/calendar', { calendar: calendar.value } )
					.then( function () {
						/*
						 * Reloaded rather than repainted, exactly as a language change is.
						 *
						 * The calendar is inside the locale string every date on every screen is
						 * formatted with, and half of them were drawn before this moment. A reload
						 * is one line and is right; repainting "the screens that show dates" is a
						 * list somebody has to keep.
						 */
						window.location.reload();
					} )
					.catch( function ( error ) { self.toast( error.message, true ); } );
			} );
		}

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

		// `keep`: the bell belongs to the shell, not to whichever screen happened to be open when it
		// was asked for, so its answer survives the reader moving on.
		return this.request( 'GET', '/notifications', null, { keep: true } )
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
				// An empty bell is the good outcome, not a job — see emptyState.
				: this.emptyState( 'info', this.t( 'panel.notices.none' ),
					esc( this.t( 'panel.notices.noneHint' ) ), { waiting: true } ),
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

	/**
	 * Which calendar this account writes its dates in.
	 *
	 * Beside the language rather than on a settings screen, because it is the same kind of thing —
	 * how the panel reads — and because there is no account settings screen for it to be the only
	 * inhabitant of. Offered only to somebody who may change the account: it is the venue's
	 * decision, not the reader's, and a clerk moving the whole organisation's dates is not a
	 * setting anybody wanted.
	 *
	 * Nothing at all for everybody else, rather than a disabled control. A dead select is a
	 * question about who they are; its absence is not.
	 */
	App.calendarField = function () {
		if ( ! this.may( 'account.manage' ) ) {
			return '';
		}

		var current = ( this.profile || {} ).calendar || 'auto';

		return '<select class="select select--sm" id="calendar" aria-label="' +
			esc( this.t( 'panel.shell.calendar' ) ) + '">' +
			[ 'auto', 'persian', 'gregory' ].map( function ( key ) {
				return '<option value="' + key + '"' + ( key === current ? ' selected' : '' ) + '>' +
					esc( App.t( 'panel.shell.calendar_' + key ) ) + '</option>';
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
		 * A way to get to a screen by naming it.
		 *
		 * Thirty-five screens is more than anybody holds in their head, and the sidebar answers
		 * "what is there" rather than "where is the thing I already know I want". Typing is the
		 * shortest path for the second question, and slash is where every application this
		 * audience uses has put it.
		 *
		 * A filter over the list that is already there, not a separate palette: the reader stays in
		 * the sidebar they were reading, and pressing Escape gives it back whole.
		 */
		var finder = node( 'div', 'nav-find' );
		var box = node( 'input', 'nav-find__input' );

		box.type = 'search';
		box.id = 'nav-find';
		box.value = self.navFilter || '';
		box.setAttribute( 'placeholder', self.t( 'panel.shell.findScreen' ) );
		box.setAttribute( 'aria-label', self.t( 'panel.shell.findScreen' ) );

		box.addEventListener( 'input', function () {
			self.navFilter = box.value;
			self.renderNav();
			// Focus is lost with the element it was on: the list was rebuilt under the typing.
			var again = document.getElementById( 'nav-find' );

			if ( again ) {
				again.focus();
				again.setSelectionRange( again.value.length, again.value.length );
			}
		} );

		box.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				self.navFilter = '';
				self.renderNav();
			}

			// Enter opens the only thing left, which is what typing three letters is usually for.
			if ( 'Enter' === event.key ) {
				var only = document.querySelectorAll( '#nav .nav-item' );

				if ( 1 === only.length ) {
					only[ 0 ].click();
				}
			}
		} );

		finder.appendChild( box );
		host.appendChild( finder );

		var wanted = ( self.navFilter || '' ).trim().toLowerCase();
		var found = 0;

		/*
		 * Grouped, because twelve unlabelled rows is a list somebody reads every time rather than
		 * learns once. The headings name the job, not the table: "Venue" is where the room lives,
		 * whether that is a chart or the building it is in.
		 */
		NAV.forEach( function ( section ) {
			var items = section.items.filter( function ( entry ) {
				if ( ! self.offers( entry ) ) {
					return false;
				}

				// Matched against the name in the reader's own language, which is the only name
				// they know the screen by.
				return '' === wanted
					|| self.t( 'panel.nav.' + entry.key ).toLowerCase().indexOf( wanted ) > -1;
			} );

			found += items.length;

			// A heading over nothing is worse than no heading: an external agency's nav is four rows
			// and a group label with an empty space under it would read as a screen that failed.
			if ( ! items.length ) {
				return;
			}

			var group = node( 'div', 'nav-group' );

			if ( section.group ) {
				var heading = node( 'p', 'nav-group__label' );
				heading.textContent = self.t( 'panel.navGroups.' + section.group );
				group.appendChild( heading );
			}

			items.forEach( function ( entry ) {
				var button = node( 'button', 'nav-item' );
				button.type = 'button';
				button.dataset.view = entry.key;
				button.innerHTML = icon( entry.icon, { size: 16 } ) +
					'<span>' + esc( self.t( 'panel.nav.' + entry.key ) ) + '</span>';

				if ( self.current === entry.key ) {
					button.classList.add( 'is-active' );
					button.setAttribute( 'aria-current', 'page' );
				}

				button.addEventListener( 'click', function () {
					/*
					 * A message is about the thing somebody was just doing. Walking away from that
					 * screen ends it — a refusal from the counter still sitting over an account
					 * page reads as a refusal from the account page.
					 *
					 * Here rather than in `route()`, because the panel sometimes sends somebody
					 * somewhere *and* tells them why, and that message must survive its own move.
					 */
					self.clearToast();
					self.route( entry.key );
				} );
				group.appendChild( button );
			} );

			host.appendChild( group );
		} );

		if ( wanted && ! found ) {
			var nothing = node( 'p', 'nav-find__none' );

			nothing.textContent = self.t( 'panel.shell.findNothing' );
			host.appendChild( nothing );
		}
	};

	App.route = function ( view ) {
		/*
		 * A view nobody offered them, reached by a stale deep link or by landing on the default.
		 * Sending them to the first screen they *may* open is the honest answer: the alternative is
		 * a page of refusals on a panel that chose the page itself.
		 */
		if ( ! this.mayOpen( view ) ) {
			view = this.allowed()[ 0 ] || 'security';
		}

		this.current = view;
		/*
		 * Which screen the reader is on, counted rather than named.
		 *
		 * A screen paints when its answer comes back, and an answer can come back after the reader
		 * has gone somewhere else — click the overview, click the baskets before it lands, and the
		 * overview paints over the baskets. Counting the moves lets a stale answer be dropped; see
		 * `request()`, which is the one place that knows an answer is late.
		 */
		this.visit = ( this.visit || 0 ) + 1;
		this.renderNav();

		switch ( view ) {
			case 'overview': return this.renderOverview();
			case 'venues': return this.renderVenues();
			case 'maps': return this.renderMaps();
			case 'connections': return this.renderConnections();
			case 'sites': return window.SeatmapSites.renderList( this );
			case 'themes': return window.SeatmapThemes.render( this );
			case 'promoters': return window.SeatmapPromoters.render( this );
			case 'baskets': return window.SeatmapBaskets.render( this );
			case 'reports': return window.SeatmapReports.render( this );
			case 'settlement': return window.SeatmapSettlement.render( this );
			case 'messaging': return window.SeatmapMessaging.render( this );
			case 'modules': return window.SeatmapModules.render( this );
			case 'team': return window.SeatmapTeam.render( this );
			case 'managers': return window.SeatmapManagers.render( this );
			case 'audit': return window.SeatmapAudit.render( this );
			case 'tickets': return window.SeatmapTickets.render( this );
			case 'customers': return window.SeatmapCustomers.render( this );
			case 'counter': return window.SeatmapCounter.render( this );
			case 'tills': return window.SeatmapTills.render( this );
			case 'agents': return window.SeatmapAgents.render( this );
			case 'myagency': return window.SeatmapAgents.mine( this );
			case 'doorlist': return window.SeatmapDoorList.render( this );
			case 'questions': return window.SeatmapQuestions.render( this );
			case 'entryslots': return window.SeatmapEntrySlots.render( this );
			case 'billing': return window.SeatmapBilling.render( this );
			case 'security': return window.SeatmapSecurity.render( this );
			case 'access': return window.SeatmapAccess.render( this );
			case 'vouchers': return window.SeatmapVouchers.render( this );
			case 'wallet': return window.SeatmapWallet.render( this );
			case 'waitlist': return window.SeatmapWaitlist.render( this );
			case 'rehearsal': return window.SeatmapRehearsal.render( this );
			case 'leaving': return window.SeatmapLeaving.render( this );
			case 'loyalty': return window.SeatmapLoyalty.render( this );
			case 'memberships': return window.SeatmapMemberships.render( this );
			case 'scanners': return window.SeatmapScanners.render( this );
			case 'webapp': return window.SeatmapWebApp.render( this );
			case 'orders': return window.SeatmapOrders.render( this );
			case 'plans': return window.SeatmapPlans.render( this );
			case 'discounts': return window.SeatmapDiscounts.render( this );
			case 'seasons': return window.SeatmapSeasons.render( this );
			case 'productions': return window.SeatmapProductions.render( this );
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

		/*
		 * Picture fields come to life here rather than at every call site.
		 *
		 * A media field is markup plus behaviour, and a screen that renders the markup and forgets
		 * the wiring shows an organiser a dead grey rectangle. Doing it once, where every screen
		 * already passes through, means a field added tomorrow works without anybody remembering.
		 */
		richFields();
	};

	/**
	 * Wake up the fields that are more than markup. Harmless when the screen has none.
	 *
	 * All three are done here, where every screen already passes, rather than at each call site: a
	 * picture field that was drawn and not wired is a dead grey rectangle, a date field is a box an
	 * Iranian organiser has to convert in their head, and a field that should have waited its turn
	 * is a question about something the reader has not decided yet.
	 */
	function richFields() {
		if ( window.SeatmapMedia ) {
			window.SeatmapMedia.wire( App );
		}

		if ( window.SeatmapCalendar ) {
			window.SeatmapCalendar.wire( App );
		}

		App.applyWhen();
		App.markRequired();
	}

	/**
	 * A field that has to be answered says so, before it is answered.
	 *
	 * The `required` attribute was already on the controls that need it — the browser enforces it
	 * and a screen reader announces it — and none of that reaches somebody looking at the form and
	 * deciding how much work it is. Marking it in the markup of forty forms is forty chances to
	 * forget; marking it from the attribute is one rule that cannot drift from what is enforced.
	 *
	 * The star is hidden from screen readers because they have already said "required" from the
	 * attribute, and twice is worse than once.
	 */
	App.markRequired = function ( scope ) {
		var root = scope || document;

		root.querySelectorAll( '[required]' ).forEach( function ( control ) {
			var label = ( control.id && root.querySelector( 'label[for="' + CSS.escape( control.id ) + '"]' ) ) ||
				control.closest( 'label' );

			if ( ! label || label.querySelector( '.req' ) ) {
				return;
			}

			var star = document.createElement( 'span' );

			star.className = 'req';
			star.setAttribute( 'aria-hidden', 'true' );
			star.textContent = ' *';
			label.appendChild( star );
		} );
	};

	/* ----------------------------------------------------------------------- form structure */

	/**
	 * A named part of a form.
	 *
	 * Long forms were the thing people could not work out, and the reason was structural rather
	 * than verbal: twenty-eight fields in one column, in no stated order, with nothing to say which
	 * of them had to be answered now and which were policies that could wait. No amount of help
	 * text fixes that — the shape of the form is what is being read.
	 *
	 * So a form is a handful of named parts, each one a question somebody can already answer:
	 * *the night*, *when it goes on sale*, *if a buyer cannot come*. The first is open; the rest
	 * are closed, and each says on its own line what it currently holds — so a part can be skipped
	 * without wondering what was skipped.
	 *
	 * `<details>` rather than a scripted accordion: it opens with a keyboard, it is found by the
	 * browser's own page search when it is closed, and it needs no JavaScript to work at all.
	 *
	 * @param {object} options
	 *   - title  what this part of the form is about
	 *   - state  one line saying what it holds at the moment, shown beside the title
	 *   - body   the fields themselves
	 *   - open   true for the part that must be answered now
	 *   - when   show the whole part only when a control says so — see App.applyWhen
	 */
	App.group = function ( options ) {
		var settings = options || {};

		return '<details class="form-group"' + ( settings.open ? ' open' : '' ) +
			( settings.when ? ' data-when="' + esc( settings.when ) + '"' : '' ) + '>' +
			'<summary class="form-group__head">' +
				'<span class="form-group__title">' + esc( settings.title ) + '</span>' +
				( settings.state
					? '<span class="form-group__state">' + esc( settings.state ) + '</span>'
					: '' ) +
				icon( 'chevronDown', { size: 16, className: 'form-group__mark' } ) +
			'</summary>' +
			'<div class="stack form-group__body">' + ( settings.body || '' ) + '</div>' +
		'</details>';
	};

	/**
	 * Fields that appear when they apply, and not before.
	 *
	 * Mark anything — a field, a pair of fields, a whole group — with `data-when`:
	 *
	 *   data-when="waiting_room"          shown while that checkbox is ticked
	 *   data-when="refunds=until"         shown while that control holds that value
	 *   data-when="exchanges=until|always"  … or any of these values
	 *
	 * The word before the `=` is a control's `name`, or its `id` where a screen has no form to
	 * serialise.
	 *
	 * One rule, wired once, for every screen and every dialog in the panel. It replaces the thing
	 * that made these forms unreadable: *How many people may choose at once* sitting in plain view
	 * on a night with no queue, *Hours before* under a refund policy of never. A control whose
	 * answer cannot matter yet is not a control, it is a puzzle.
	 *
	 * Hidden fields keep their values and still submit them — hiding is about what is being asked,
	 * not about throwing away what was already decided.
	 */
	App.applyWhen = function ( scope ) {
		var root = scope || document;

		root.querySelectorAll( '[data-when]' ).forEach( function ( node ) {
			var rule = String( node.dataset.when || '' ).split( '=' );
			var name = rule[ 0 ];
			var wanted = rule.slice( 1 ).join( '=' );
			// From the parent, so a node that *is* the form — a whole settings form that waits on a
			// switch beside it — looks outside itself for what governs it rather than within.
			var from = node.parentElement || root;
			var host = from.closest( 'form' ) || from.closest( '.modal' ) || root;
			// By name, which is what a form is made of — or by id, for the screens whose controls
			// are read back one by one rather than serialised.
			var control = host.querySelector( '[name="' + name + '"]' ) ||
				host.querySelector( '#' + CSS.escape( name ) );

			if ( ! control ) {
				return;
			}

			if ( ! control.dataset.governs ) {
				control.dataset.governs = '1';
				// Both, because a select answers to change and a text box to input, and a rule may
				// hang off either.
				control.addEventListener( 'change', function () { App.applyWhen( host ); } );
				control.addEventListener( 'input', function () { App.applyWhen( host ); } );
			}

			var value = 'checkbox' === control.type ? ( control.checked ? 'on' : 'off' ) : control.value;

			node.hidden = wanted
				? wanted.split( '|' ).indexOf( value ) === -1
				: 'on' !== value;
		} );
	};

	// Shared with the website and ticket screens, which build the same furniture.
	App.table = function ( headings, rows, emptyMarkup ) {
		return table( headings, rows, emptyMarkup );
	};

	/**
	 * A question with two answers, asked the way the rest of the panel asks everything else.
	 *
	 * `window.confirm` is the browser's own grey box: its buttons are in the browser's language
	 * rather than the reader's, it cannot say in more than a line what is about to be lost, and a
	 * browser is free to suppress it — which turns a guard against losing an afternoon's work into
	 * no guard at all. `done` is called only if the answer is yes.
	 */
	App.confirm = function ( options, done ) {
		this.modal( {
			title: options.title,
			submitLabel: options.confirmLabel,
			danger: !! options.danger,
			body: '<p>' + esc( options.body ) + '</p>',
			onSubmit: function () { done(); },
		} );
	};

	App.emptyState = function ( iconName, title, body, action ) {
		return emptyState( iconName, title, body, action );
	};

	/**
	 * The way to the screen where a thing is actually made.
	 *
	 * The label is built from the name that screen carries in the sidebar, so the button says where
	 * it goes in the words the reader already knows it by — and a screen renamed in the catalogue
	 * renames itself here too.
	 */
	App.goesTo = function ( view ) {
		return { goes: view, label: this.t( 'panel.empty.goTo', { screen: this.t( 'panel.nav.' + view ) } ) };
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

	/**
	 * Markup, but only for somebody who may use what it opens.
	 *
	 * `permission` is a name, or a list — any-of by default, all-of when `every` is set, which is
	 * what an action needing two authorities wants: cancelling a night is an event change *and*
	 * money back.
	 */
	function only( permission, markup, every ) {
		var names = Array.isArray( permission ) ? permission : [ permission ];
		var held = every
			? names.every( function ( name ) { return App.may( name ); } )
			: App.may( names );

		return held ? markup : '';
	}

	/**
	 * Nothing here yet — and what to do about it.
	 *
	 * An empty screen is the first thing most people see on most screens, and every one of these
	 * used to be a sentence and a full stop: *No discount codes yet. Make one and it works on your
	 * own site straight away.* Made where? The button was in the corner of the page, or, worse, on
	 * another screen entirely — the season tickets screen said to go and put dates in a series, and
	 * offered no way of getting there. Reading is not doing.
	 *
	 * So an empty state carries the way out of itself, and there are exactly two of those:
	 *
	 *   `{ does: 'add-event', label: … }`  presses the screen's own action, whatever it does. Not a
	 *   copy of it — the same button, pressed from here — so the two cannot come apart.
	 *
	 *   `{ goes: 'events', label: … }`  opens the screen where the thing is actually made.
	 *
	 * And a third possibility that is not an action at all: `{ waiting: true }`, for the screens
	 * where an empty list is the right answer and nobody should do anything about it — nobody has
	 * left a basket, nobody is waiting for a returned seat. That one is *declared* rather than
	 * omitted, so the decision is in the markup where it can be read, and a screen cannot be given
	 * a dead end by accident. `forms_smoke` holds both halves of that up.
	 */
	function emptyState( iconName, title, body, action ) {
		var settings = action || {};
		var way = '';

		if ( settings.does ) {
			way = '<button class="btn btn--primary empty__action" type="button" data-does="' +
				esc( settings.does ) + '">' + esc( settings.label ) + '</button>';
		} else if ( settings.goes ) {
			way = '<button class="btn empty__action" type="button" data-goes="' +
				esc( settings.goes ) + '">' + esc( settings.label ) + '</button>';
		}

		return '<div class="empty' + ( settings.waiting ? ' empty--waiting' : '' ) + '"' +
			( settings.waiting ? ' data-waiting' : '' ) + '>' +
			'<span class="empty__icon">' + icon( iconName, { size: 22 } ) + '</span>' +
			'<p class="empty__title">' + esc( title ) + '</p>' +
			'<p class="empty__body">' + body + '</p>' + way + '</div>';
	}

	/**
	 * The two ways out of an empty screen, wired once for the whole panel.
	 *
	 * One listener on the document rather than one per screen: an empty state is markup a screen
	 * returns as a string, and a screen that returned the markup and forgot the wiring would offer
	 * a button that does nothing — which is worse than the sentence it replaced.
	 */
	document.addEventListener( 'click', function ( event ) {
		var press = event.target.closest && event.target.closest( '[data-does]' );

		if ( press ) {
			var action = document.getElementById( press.dataset.does );

			if ( action ) {
				action.click();
			}

			return;
		}

		var go = event.target.closest && event.target.closest( '[data-goes]' );

		if ( go ) {
			App.route( go.dataset.goes );
		}
	} );

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

	/**
	 * An ISO instant as the value a datetime-local input wants.
	 *
	 * The browser's own clock, deliberately: somebody moving tomorrow's date is sitting at the
	 * venue, and a field showing UTC would have them typing an hour they do not mean.
	 */
	function localStamp( iso ) {
		if ( ! iso ) {
			return '';
		}

		var when = new Date( iso );
		var pad = function ( n ) { return String( n ).padStart( 2, '0' ); };

		return when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() ) +
			'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );
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
						// A destructive action does not get the same button as saving a name.
						? '<button type="submit" class="btn btn--' +
							( options.danger ? 'danger' : 'primary' ) + '">' +
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

		// A dialog is a screen too: the events form asks for a poster and a date inside one.
		richFields();

		host.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === host ) {
				close();
			}
		} );

		host.querySelectorAll( '[data-close]' ).forEach( function ( button ) {
			button.addEventListener( 'click', close );
		} );

		if ( isForm ) {
			/*
			 * A part that is closed over an unanswered question opens itself.
			 *
			 * The browser refuses to submit while a required field is empty, and a required field
			 * inside a closed `<details>` is a refusal with nothing on screen to act on. `invalid`
			 * fires on the control itself and does not bubble, so it is caught on the way down.
			 */
			host.querySelector( 'form' ).addEventListener( 'invalid', function ( event ) {
				var part = event.target.closest( 'details' );

				if ( part ) {
					part.open = true;
				}
			}, true );

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
							esc( self.t( 'panel.venues.emptyBody' ) ),
							{ does: 'add-venue', label: self.t( 'panel.venues.new' ) } )
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
						actionButton( 'map-views', map.id, self.t( 'panel.seatViews.action' ), 'image' ) +
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
								esc( self.t( 'panel.maps.emptyBody' ) ),
								{ does: 'add-map', label: self.t( 'panel.maps.new' ) } )
							// A chart belongs to a building, so the way out of this one is the
							// screen where buildings are made.
							: emptyState( 'building', self.t( 'panel.maps.needVenueTitle' ),
								esc( self.t( 'panel.maps.needVenueBody' ) ), self.goesTo( 'venues' ) )
					),
				} );

				var add = document.getElementById( 'add-map' );

				if ( add ) {
					add.addEventListener( 'click', function () { self.newMap( results[ 1 ].data ); } );
				}

				self.main().querySelectorAll( '[data-map]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.openDesigner( button.dataset.map ); } );
				} );

				// What the stage looks like from each section. Opened from the list rather than
				// from inside the designer: it is a property of the room, not of the drawing, and
				// somebody attaching photographs is not editing the chart.
				self.main().querySelectorAll( '[data-map-views]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						window.SeatmapSeatViews.open( self, { id: button.dataset.mapViews } );
					} );
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

		/*
		 * Two requests, and the second one is allowed to fail.
		 *
		 * The checklist is behind `account.manage`, which most of the people who open this screen
		 * do not hold — so it is asked for only when they do, and a refusal or an outage on it
		 * still leaves the overview itself perfectly usable. A first-run aid that can take down the
		 * first screen is a bad trade.
		 */
		var steps = this.may( 'account.manage' )
			? this.request( 'GET', '/first-steps' ).catch( function () { return null; } )
			: Promise.resolve( null );

		Promise.all( [ this.request( 'GET', '/overview' ), steps ] )
			.then( function ( answers ) {
				var data = answers[ 0 ];

				self.page( {
					title: self.t( 'panel.nav.overview' ),
					description: self.t( 'panel.overview.description' ),
					body: firstSteps( self, answers[ 1 ] ) +
						'<div class="stat-strip">' + overviewStats( self, data ) + '</div>' +
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

	/**
	 * The first afternoon, as a list of eight things with the next one marked.
	 *
	 * Above the numbers rather than below them, and only for as long as it is true: an account that
	 * has taken a real booking never sees this again. That rule is the difference between a
	 * checklist and a nag — and it is decided by the server, from whether a live confirmed order
	 * exists, so it cannot be dismissed into a state that disagrees with the account.
	 *
	 * Each row is a button into the screen that finishes it, because a checklist you cannot act on
	 * from where you are reading it is homework.
	 */
	function firstSteps( app, steps ) {
		if ( ! steps || steps.settled ) {
			return '';
		}

		if ( steps.complete ) {
			return '<section class="card card--pad first-steps first-steps--done">' +
				'<h2 class="card__title">' + esc( app.t( 'panel.firstSteps.allDone' ) ) + '</h2>' +
				'<p class="hint">' + esc( app.t( 'panel.firstSteps.allDoneHint' ) ) + '</p>' +
				'</section>';
		}

		var rows = ( steps.steps || [] ).map( function ( step ) {
			var isNext = step.key === steps.next;

			return '<li class="first-step' + ( step.done ? ' is-done' : '' ) +
				( isNext ? ' is-next' : '' ) + '">' +
				// The number is drawn by CSS from a counter, and a tick replaces it once the step
				// is done — so the word "done" is said here for anybody who is being read to.
				'<span class="first-step__mark">' + ( step.done
					? '<span class="sr-only">' + esc( app.t( 'panel.firstSteps.done' ) ) + '</span>' +
						icon( 'check', { size: 14 } )
					: '' ) + '</span>' +
				'<span class="first-step__text">' +
					'<span class="first-step__title">' +
						esc( app.t( 'panel.firstSteps.steps.' + step.key + '.title' ) ) +
						( isNext ? ' <span class="badge badge--ok">' +
							esc( app.t( 'panel.firstSteps.next' ) ) + '</span>' : '' ) +
					'</span>' +
					'<span class="first-step__hint">' +
						esc( app.t( 'panel.firstSteps.steps.' + step.key + '.hint' ) ) + '</span>' +
				'</span>' +
				( step.done || ! app.mayOpen( step.view )
					? ''
					: '<button type="button" class="btn btn--sm" data-goto="' + esc( step.view ) + '">' +
						esc( app.t( 'panel.firstSteps.open' ) ) + '</button>' ) +
				'</li>';
		} ).join( '' );

		return '<section class="card card--pad first-steps">' +
			'<div class="row--between">' +
				'<h2 class="card__title">' + esc( app.t( 'panel.firstSteps.title' ) ) + '</h2>' +
				'<span class="muted tnum">' + esc( app.t( 'panel.firstSteps.progress', {
					done: app.number( steps.done ),
					total: app.number( steps.total ),
				} ) ) + '</span>' +
			'</div>' +
			'<p class="hint">' + esc( app.t( 'panel.firstSteps.subtitle' ) ) + '</p>' +
			'<ol class="first-steps__list">' + rows + '</ol>' +
			'</section>';
	}

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
			// Nothing on means nothing sold, and the way out of that is a night to sell.
			return emptyState( 'calendar', app.t( 'panel.overview.nothingOn' ),
				esc( app.t( 'panel.overview.nothingOnBody' ) ), app.goesTo( 'events' ) );
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
							STATUS_TONE[ event.status ] ) +
							// Beside the status rather than instead of it: a rehearsal is still
							// draft or published, and both facts matter.
							( event.is_rehearsal
								? ' ' + badge( self.t( 'panel.rehearsal.badge' ), 'warn' )
								: '' ) + '</td>' +
						'<td class="tnum">' + priceRange( event ) + '</td>' +
						'<td><code>' + esc( event.public_id ) + '</code>' +
						'<button class="icon-btn icon-btn--sm" data-copy="' + esc( event.public_id ) +
						'" data-tip="' + esc( self.t( 'panel.common.copy' ) ) + '" aria-label="' +
						esc( self.t( 'panel.events.copyPublicId' ) ) + '">' + icon( 'copy', { size: 14 } ) +
						'</button></td>' +
						'<td class="table__actions">' +
						/*
						 * Each of these opens something the server checks on arrival, so each is
						 * offered only to somebody who would get through. An agency reading the
						 * nights it may sell should not be shown eight buttons that refuse — the
						 * row is the same row, with fewer things to do in it.
						 */
						only( 'events.manage',
							actionButton( 'event-edit', event.id, self.t( 'panel.events.edit' ), 'settings' ) ) +
						only( 'pricing.manage',
							actionButton( 'prices', event.id, App.t( 'pricing.openPrices' ), 'tag' ) ) +
						// What this night's ticket looks like. Beside the prices because both are
						// decisions about the night rather than about the account, and an organiser
						// setting one up usually does the other in the same sitting.
						only( 'events.view',
							actionButton( 'ticket-design', event.id,
								self.t( 'panel.ticketDesign.title' ), 'ticket' ) ) +
						only( 'reports.attendance.view',
							actionButton( 'stats', event.id, self.t( 'panel.events.inventory' ), 'layers' ) ) +
						// Only where there is a door to watch. On every other night the button
						// would open a screen reading "nobody is queueing", for ever.
						( event.waiting_room
							? actionButton( 'queue', event.id, self.t( 'panel.room.title' ), 'users' )
							: '' ) +
						actionButton( 'pace', event.id, self.t( 'panel.pace.title' ), 'chart' ) +
						only( 'pricing.manage',
							actionButton( 'quotas', event.id, self.t( 'panel.quotas.title' ), 'plug' ) ) +
						// Only where the organiser takes tickets back. Elsewhere the screen would
						// read "nobody has offered anything", for ever.
						( event.resale
							? only( 'orders.view',
								actionButton( 'resale', event.id, self.t( 'panel.resale.title' ), 'tag' ) )
							: '' ) +
						// Only on a night whose chart has been republished since. Everywhere else the
						// button would say "use the latest chart" about the chart already in use.
						( event.chart_outdated
							? only( 'events.manage',
								actionButton( 'rechart', event.id, self.t( 'panel.events.useLatestChart' ), 'map' ) )
							: '' ) +
						only( 'events.manage',
							actionButton( 'repeat', event.id, self.t( 'panel.events.repeat' ), 'calendar' ) +
							actionButton( 'words', event.id, self.t( 'panel.events.translations' ), 'globe' ) +
							actionButton( 'move', event.id, self.t( 'panel.events.reschedule' ), 'clock' ) ) +
						// Not offered on a night that is already off: there is nothing left to
						// cancel, and the button would only invite somebody to try. Cancelling also
						// puts money back, so it takes both permissions.
						( 'cancelled' === event.status
							? ''
							: only( [ 'events.manage', 'orders.refund' ],
								actionButton( 'call-off', event.id, self.t( 'panel.events.cancel' ), 'close' ),
								true ) ) +
						'</td></tr>';
				} ).join( '' );

				self.page( {
					// The one description that carries markup of its own: the shortcode is code, and
					// showing it as text would leave an organiser copying the wrong thing.
					title: self.t( 'panel.nav.events' ),
					description: self.t( 'panel.events.description' ),
					actions: sellable.length
						? only( 'events.manage', '<button class="btn btn--primary" id="add-event">' +
							icon( 'plus', { size: 15 } ) + esc( self.t( 'panel.events.new' ) ) + '</button>' )
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
								esc( self.t( 'panel.events.emptyBody' ) ),
								{ does: 'add-event', label: self.t( 'panel.events.new' ) } )
							// An event sells against a published chart, so that is where to start.
							: emptyState( 'map', self.t( 'panel.events.needChartTitle' ),
								esc( self.t( 'panel.events.needChartBody' ) ), self.goesTo( 'maps' ) )
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

				self.main().querySelectorAll( '[data-ticket-design]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						window.SeatmapTicketDesign.open( self, button.dataset.ticketDesign );
					} );
				} );

				self.main().querySelectorAll( '[data-rechart]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.request( 'POST', '/events/' + button.dataset.rechart + '/chart-version', {} )
							.then( function () {
								self.toast( self.t( 'panel.events.chartUpdated' ) );
								self.renderEvents();
							} )
							.catch( function ( error ) { self.toast( error.message, true ); } );
					} );
				} );

				self.main().querySelectorAll( '[data-repeat]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var event = results[ 0 ].data.filter( function ( row ) {
							return row.id === button.dataset.repeat;
						} )[ 0 ];

						if ( event ) {
							self.repeatEvent( event );
						}
					} );
				} );

				self.main().querySelectorAll( '[data-words]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var event = results[ 0 ].data.filter( function ( row ) {
							return row.id === button.dataset.words;
						} )[ 0 ];

						if ( event ) {
							self.translateEvent( event );
						}
					} );
				} );

				[ [ 'move', 'rescheduleEvent' ], [ 'call-off', 'cancelEvent' ] ].forEach( function ( pair ) {
					self.main().querySelectorAll( '[data-' + pair[ 0 ] + ']' ).forEach( function ( button ) {
						button.addEventListener( 'click', function () {
							var event = results[ 0 ].data.filter( function ( row ) {
								return row.id === button.dataset[ 'move' === pair[ 0 ] ? 'move' : 'callOff' ];
							} )[ 0 ];

							if ( event ) {
								self[ pair[ 1 ] ]( event );
							}
						} );
					} );
				} );

				self.main().querySelectorAll( '[data-pace]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						window.SeatmapPace.open( self, button.dataset.pace,
							button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
					} );
				} );

				self.main().querySelectorAll( '[data-quotas]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.showQuotas( button.dataset.quotas,
							button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
					} );
				} );

				self.main().querySelectorAll( '[data-resale]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.showResale( button.dataset.resale,
							button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
					} );
				} );

				self.main().querySelectorAll( '[data-queue]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.showQueue( button.dataset.queue,
							button.closest( 'tr' ).querySelector( '.table__primary' ).textContent );
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
	 *
	 * It is five named parts rather than twenty-eight boxes in a column, and that is the whole of
	 * the change: the old form asked for the name of the night and the terms of a ticket exchange
	 * with the same weight, in no order anybody could see, and the reader had no way to tell which
	 * answers were needed to get a night on sale. Now the night itself is open and everything else
	 * is a closed line that says what it currently holds — and inside each part, a field that
	 * cannot matter yet is not shown at all. *Hours before* under a refund policy of never was a
	 * question about nothing.
	 */
	function eventFields( event, maps ) {
		var zone = ( event && event.timezone ) || hereZone();
		var t = function ( key, replace ) { return App.t( key, replace ); };

		/* ---------------------------------------------------------------- the night itself */

		var night =
			'<div class="field"><label class="field__label" for="e-name">' +
			esc( t( 'panel.common.name' ) ) + '</label>' +
			'<input class="input" id="e-name" name="name" required maxlength="200" ' +
			'placeholder="' + esc( t( 'panel.events.namePlaceholder' ) ) + '" value="' +
			esc( event ? event.name : '' ) + '"></div>' +

			( maps
				? '<div class="field"><label class="field__label" for="e-map">' +
					esc( t( 'panel.events.seatMap' ) ) + '</label>' +
					'<select class="select" id="e-map" name="seat_map_id" required>' +
					maps.map( function ( map ) {
						return '<option value="' + esc( map.id ) + '">' + esc( map.name ) +
							' — v' + map.published_version.version + '</option>';
					} ).join( '' ) +
					'</select><span class="field__hint">' + esc( t( 'panel.events.seatMapHint' ) ) +
					'</span></div>'
				: '' ) +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-starts">' +
			esc( t( 'panel.events.starts' ) ) + '</label>' +
			'<input class="input" id="e-starts" name="starts_at" type="datetime-local" required value="' +
			esc( event ? wallClock( event.starts_at, zone ) : '' ) + '"></div>' +
			'<div class="field"><label class="field__label" for="e-timezone">' +
			esc( t( 'panel.events.timezone' ) ) + '</label>' +
			'<input class="input" id="e-timezone" name="timezone" list="e-timezones" required value="' +
			esc( zone ) + '">' +
			'<datalist id="e-timezones">' + timezoneOptions() + '</datalist>' +
			'<span class="field__hint">' + esc( t( 'panel.events.timezoneHint' ) ) + '</span></div>' +
			'</div>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-currency">' +
			esc( t( 'pricing.currency' ) ) + '</label>' +
			'<input class="input input--code" id="e-currency" name="currency" list="e-currencies" ' +
			'maxlength="3" required value="' +
			esc( ( event && event.currency ) || window.SeatmapPricing.CURRENCIES[ 0 ] ) + '">' +
			'<datalist id="e-currencies">' +
			window.SeatmapPricing.CURRENCIES.map( function ( code ) {
				return '<option value="' + code + '">';
			} ).join( '' ) +
			'</datalist>' +
			'<span class="field__hint">' + esc( t( 'pricing.currencyHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="e-status">' +
			esc( t( 'panel.common.status' ) ) + '</label>' +
			'<select class="select" id="e-status" name="status">' +
			[ 'draft', 'published', 'closed', 'cancelled' ].map( function ( status ) {
				return '<option value="' + status + '"' +
					( event && event.status === status ? ' selected' : '' ) + '>' +
					esc( t( 'panel.eventStatus.' + status ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>' +
			'</div>' +

			'<div class="field"><label class="field__label" for="e-category">' +
			esc( t( 'panel.events.category' ) ) + '</label>' +
			'<input class="input" id="e-category" name="category" maxlength="40" ' +
			'placeholder="' + esc( t( 'panel.events.categoryPlaceholder' ) ) + '" value="' +
			esc( ( event && event.category ) || '' ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.categoryHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="e-image">' +
			esc( t( 'panel.events.artwork' ) ) + '</label>' +
			window.SeatmapMedia.field( {
				id: 'e-image',
				name: 'image_url',
				kind: 'image',
				value: ( event && event.image_url ) || '',
			} ) +
			'<span class="field__hint">' + esc( t( 'panel.events.artworkHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="e-about">' +
			esc( t( 'panel.events.about' ) ) + '</label>' +
			'<textarea class="input" id="e-about" name="description" rows="4" maxlength="5000">' +
			esc( ( event && event.description ) || '' ) + '</textarea>' +
			'<span class="field__hint">' + esc( t( 'panel.events.aboutHint' ) ) + '</span></div>';

		/* ------------------------------------------------------------- when it goes on sale */

		/*
		 * Two instants rather than a status, because a status has to be flipped by somebody at
		 * midnight and nobody is awake at midnight. Both empty is what every night that has never
		 * heard of a presale carries, and it means on sale as soon as it is published.
		 */
		var sale =
			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-presale">' +
			esc( t( 'panel.events.presaleFrom' ) ) + '</label>' +
			'<input class="input" id="e-presale" name="presale_starts_at" type="datetime-local" value="' +
			esc( localStamp( event && event.presale_starts_at ) ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.presaleFromHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="e-onsale">' +
			esc( t( 'panel.events.onSaleFrom' ) ) + '</label>' +
			'<input class="input" id="e-onsale" name="on_sale_at" type="datetime-local" value="' +
			esc( localStamp( event && event.on_sale_at ) ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.onSaleFromHint' ) ) + '</span></div>' +
			'</div>' +

			/*
			 * The door, for a sale that needs one. Off for almost every night, and rightly: a queue
			 * in front of a sale nobody is queueing for is a page between a buyer and their ticket
			 * for no reason at all — which is why the two numbers that shape it wait until it is on.
			 */
			'<label class="switch switch--row"><input type="checkbox" id="e-room" name="waiting_room"' +
			( event && event.waiting_room ? ' checked' : '' ) + '>' +
			'<span class="switch__track"><span class="switch__thumb"></span></span>' +
			'<span>' + esc( t( 'panel.events.waitingRoom' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( t( 'panel.events.waitingRoomHint' ) ) + '</p>' +

			'<div class="field-duo" data-when="waiting_room">' +
			'<div class="field"><label class="field__label" for="e-room-capacity">' +
			esc( t( 'panel.events.roomCapacity' ) ) + '</label>' +
			'<input class="input tnum" id="e-room-capacity" name="waiting_room_capacity" ' +
			'type="number" min="1" max="100000" value="' +
			esc( ( event && event.waiting_room_capacity ) || 100 ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.roomCapacityHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="e-room-minutes">' +
			esc( t( 'panel.events.roomMinutes' ) ) + '</label>' +
			'<input class="input tnum" id="e-room-minutes" name="waiting_room_minutes" ' +
			'type="number" min="1" max="120" value="' +
			esc( ( event && event.waiting_room_minutes ) || 10 ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.roomMinutesHint' ) ) + '</span></div>' +
			'</div>';

		/* ------------------------------------------------------------ limits at the checkout */

		/*
		 * How many one person may have, and how fast is too fast. The first is the limit that means
		 * anything: a cap per basket stops nothing, because four at a time six times over is
		 * twenty-four. The second is the whole of the bot defence — a checkout form takes a person
		 * fifteen seconds and a script none.
		 */
		var limits =
			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-per-buyer">' +
			esc( t( 'panel.events.perBuyer' ) ) + '</label>' +
			'<input class="input tnum" id="e-per-buyer" name="max_per_buyer" ' +
			'type="number" min="1" max="1000" placeholder="' +
			esc( t( 'panel.events.perBuyerNone' ) ) + '" value="' +
			esc( ( event && event.max_per_buyer ) || '' ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.perBuyerHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="e-min-seconds">' +
			esc( t( 'panel.events.checkoutSeconds' ) ) + '</label>' +
			'<input class="input tnum" id="e-min-seconds" name="checkout_min_seconds" ' +
			'type="number" min="0" max="120" value="' +
			esc( ( event && event.checkout_min_seconds ) || 0 ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.checkoutSecondsHint' ) ) + '</span></div>' +
			'</div>';

		/* ------------------------------------------------------- if a buyer cannot come */

		/*
		 * Three answers to one situation, in the order a venue thinks about them: money back,
		 * another night, or somebody else's money. Each brings its own terms into view only once it
		 * has been offered at all.
		 */
		var changes =
			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-refunds">' +
			esc( t( 'panel.events.refunds' ) ) + '</label>' +
			'<select class="select" id="e-refunds" name="refunds">' +
			[ 'never', 'until', 'always' ].map( function ( kind ) {
				return '<option value="' + kind + '"' +
					( event && event.refunds === kind ? ' selected' : '' ) + '>' +
					esc( t( 'panel.events.refundKinds.' + kind ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>' +
			'<div class="field" data-when="refunds=until"><label class="field__label" for="e-refund-hours">' +
			esc( t( 'panel.events.refundHours' ) ) + '</label>' +
			'<input class="input tnum" id="e-refund-hours" name="refund_window_hours" type="number" ' +
			'min="0" max="8760" value="' +
			esc( event && null != event.refund_window_hours ? event.refund_window_hours : 48 ) + '">' +
			'</div>' +
			'</div>' +
			'<label class="perms__row" data-when="refunds=until|always"><input type="checkbox" class="checkbox" ' +
			'id="e-refund-fee" name="refund_keeps_fee"' +
			( ! event || event.refund_keeps_fee ? ' checked' : '' ) + '>' +
			'<span>' + esc( t( 'panel.events.refundKeepsFee' ) ) + '</span></label>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-exchanges">' +
			esc( t( 'panel.events.exchanges' ) ) + '</label>' +
			'<select class="select" id="e-exchanges" name="exchanges">' +
			[ 'never', 'until', 'always' ].map( function ( kind ) {
				return '<option value="' + kind + '"' +
					( event && event.exchanges === kind ? ' selected' : '' ) + '>' +
					esc( t( 'panel.events.refundKinds.' + kind ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>' +
			'<div class="field" data-when="exchanges=until"><label class="field__label" for="e-exchange-hours">' +
			esc( t( 'panel.events.exchangeHours' ) ) + '</label>' +
			'<input class="input tnum" id="e-exchange-hours" name="exchange_window_hours" ' +
			'type="number" min="0" max="8760" value="' +
			esc( ( event && event.exchange_window_hours ) || 48 ) + '"></div>' +
			'</div>' +
			'<div class="field" data-when="exchanges=until|always">' +
			'<label class="field__label" for="e-exchange-fee">' +
			esc( t( 'panel.events.exchangeFee' ) ) + '</label>' +
			'<input class="input tnum" id="e-exchange-fee" name="exchange_fee_amount" ' +
			'type="number" min="0" value="' +
			esc( ( event && event.exchange_fee_amount ) || 0 ) + '">' +
			'<span class="field__hint">' + esc( t( 'panel.events.exchangeFeeHint' ) ) + '</span></div>' +

			'<label class="switch switch--row"><input type="checkbox" id="e-resale" name="resale"' +
			( event && event.resale ? ' checked' : '' ) + '>' +
			'<span class="switch__track"><span class="switch__thumb"></span></span>' +
			'<span>' + esc( t( 'panel.events.resale' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( t( 'panel.events.resaleHint' ) ) + '</p>' +
			'<div class="field" data-when="resale"><label class="field__label" for="e-resale-pays">' +
			esc( t( 'panel.events.resalePays' ) ) + '</label>' +
			'<select class="select" id="e-resale-pays" name="resale_pays">' +
			[ 'credit', 'refund' ].map( function ( kind ) {
				return '<option value="' + kind + '"' +
					( event && event.resale_pays === kind ? ' selected' : '' ) + '>' +
					esc( t( 'panel.events.resalePayKinds.' + kind ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>';

		/* ------------------------------------------------------------------ getting in */

		// Who may buy the wheelchair spaces, and when. Held back means off the public plan and
		// still sellable at the window, which is the only version of "held back" that helps the
		// person it is being held for.
		var access =
			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="e-accessible-sale">' +
			esc( t( 'panel.events.accessibleSale' ) ) + '</label>' +
			'<select class="select" id="e-accessible-sale" name="accessible_sale">' +
			[ 'always', 'until', 'counter' ].map( function ( kind ) {
				return '<option value="' + kind + '"' +
					( event && event.accessible_sale === kind ? ' selected' : '' ) + '>' +
					esc( t( 'panel.events.accessibleSaleKinds.' + kind ) ) + '</option>';
			} ).join( '' ) +
			'</select></div>' +
			'<div class="field" data-when="accessible_sale=until">' +
			'<label class="field__label" for="e-accessible-hours">' +
			esc( t( 'panel.events.accessibleHours' ) ) + '</label>' +
			'<input class="input tnum" id="e-accessible-hours" name="accessible_release_hours" ' +
			'type="number" min="0" max="8760" value="' +
			esc( event && null != event.accessible_release_hours ? event.accessible_release_hours : 0 ) + '">' +
			'</div>' +
			'</div>' +
			'<label class="perms__row"><input type="checkbox" class="checkbox" ' +
			'id="e-access-needs" name="ask_access_needs"' +
			( event && event.ask_access_needs ? ' checked' : '' ) + '>' +
			'<span>' + esc( t( 'panel.events.askAccessNeeds' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( t( 'panel.events.askAccessNeedsHint' ) ) + '</p>';

		return '<div class="stack">' +
			App.group( { title: t( 'panel.events.groups.night' ), open: true, body: night } ) +
			App.group( {
				title: t( 'panel.events.groups.sale' ),
				state: saleState( event ),
				body: sale,
			} ) +
			App.group( {
				title: t( 'panel.events.groups.limits' ),
				state: limitState( event ),
				body: limits,
			} ) +
			App.group( {
				title: t( 'panel.events.groups.changes' ),
				state: changeState( event ),
				body: changes,
			} ) +
			App.group( {
				title: t( 'panel.events.groups.access' ),
				state: accessState( event ),
				body: access,
			} ) +
			'</div>';
	}

	/**
	 * What a closed part of the event form is holding, in one line.
	 *
	 * Composed from the labels and the option names the fields themselves use, so a line cannot
	 * describe a setting the form no longer has — and so it is already translated.
	 */
	function stateLine( pairs ) {
		return pairs
			.filter( function ( pair ) { return pair[ 1 ]; } )
			.map( function ( pair ) { return pair[ 0 ] + ': ' + pair[ 1 ]; } )
			.join( ' · ' );
	}

	function saleState( event ) {
		var t = function ( key ) { return App.t( key ); };
		var when = function ( stamp ) {
			return stamp ? App.date( stamp ) : t( 'panel.common.notSet' );
		};

		return stateLine( [
			[ t( 'panel.events.presaleFrom' ), when( event && event.presale_starts_at ) ],
			[ t( 'panel.events.onSaleFrom' ), when( event && event.on_sale_at ) ],
			[ t( 'panel.events.waitingRoom' ),
				t( event && event.waiting_room ? 'panel.common.on' : 'panel.common.off' ) ],
		] );
	}

	function limitState( event ) {
		var t = function ( key ) { return App.t( key ); };

		return stateLine( [
			[ t( 'panel.events.perBuyer' ), event && event.max_per_buyer
				? App.number( event.max_per_buyer )
				: t( 'panel.events.perBuyerNone' ) ],
			[ t( 'panel.events.checkoutSeconds' ),
				App.number( ( event && event.checkout_min_seconds ) || 0 ) ],
		] );
	}

	function changeState( event ) {
		var t = function ( key ) { return App.t( key ); };

		return stateLine( [
			[ t( 'panel.events.refunds' ),
				t( 'panel.events.refundKinds.' + ( ( event && event.refunds ) || 'never' ) ) ],
			[ t( 'panel.events.exchanges' ),
				t( 'panel.events.refundKinds.' + ( ( event && event.exchanges ) || 'never' ) ) ],
		] );
	}

	function accessState( event ) {
		var t = function ( key ) { return App.t( key ); };

		return stateLine( [
			[ t( 'panel.events.accessibleSale' ), t( 'panel.events.accessibleSaleKinds.' +
				( ( event && event.accessible_sale ) || 'always' ) ) ],
		] );
	}

	/** A local wall-clock string from a datetime-local input, as the instant the API is told. */
	function instantOrNull( value ) {
		var text = String( value == null ? '' : value ).trim();

		return '' === text ? null : new Date( text ).toISOString();
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
			refunds: data.get( 'refunds' ),
			refund_window_hours: Number( data.get( 'refund_window_hours' ) ) || 0,
			// An unticked checkbox is absent from a form, which is not the same as false — and a
			// fee quietly kept because a box was empty is a fee somebody complains about.
			refund_keeps_fee: null !== data.get( 'refund_keeps_fee' ),
			// An empty box is "no presale", not the epoch.
			presale_starts_at: instantOrNull( data.get( 'presale_starts_at' ) ),
			on_sale_at: instantOrNull( data.get( 'on_sale_at' ) ),
			// An unticked checkbox is absent from a form, which is not the same as false.
			waiting_room: null !== data.get( 'waiting_room' ),
			waiting_room_capacity: Number( data.get( 'waiting_room_capacity' ) ) || 100,
			waiting_room_minutes: Number( data.get( 'waiting_room_minutes' ) ) || 10,
			// An empty box is "no limit", which is the ordinary case — not a limit of nought.
			max_per_buyer: Number( data.get( 'max_per_buyer' ) ) || null,
			exchanges: data.get( 'exchanges' ),
			exchange_window_hours: Number( data.get( 'exchange_window_hours' ) ) || 0,
			exchange_fee_amount: Number( data.get( 'exchange_fee_amount' ) ) || 0,
			// An unticked checkbox is absent from a form, which is not the same as false.
			resale: null !== data.get( 'resale' ),
			resale_pays: data.get( 'resale_pays' ),
			checkout_min_seconds: Number( data.get( 'checkout_min_seconds' ) ) || 0,
			accessible_sale: data.get( 'accessible_sale' ),
			accessible_release_hours: Number( data.get( 'accessible_release_hours' ) ) || 0,
			ask_access_needs: null !== data.get( 'ask_access_needs' ),
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
	/**
	 * What the event is called, in each of the six languages.
	 *
	 * One language on screen at a time rather than twelve fields at once: an organiser writes
	 * these a language at a time, usually with somebody else's help, and a wall of boxes is a wall
	 * nobody finishes. What is typed is kept as the picker moves between languages and the whole
	 * set is saved together.
	 *
	 * A language left blank is a language deliberately not written. It falls back to the words the
	 * event was typed in — an organiser who has done Persian and German has not thereby broken
	 * their French page.
	 */
	/** Every language this platform speaks, each under its own name for itself. */
	App.locales = function () {
		return i18n.locales || [];
	};

	/** A language's own name for itself, which is what somebody choosing one looks for. */
	App.languageName = function ( code ) {
		var found = ( i18n.locales || [] ).filter( function ( entry ) {
			return entry.code === code;
		} )[ 0 ];

		return found ? found.native : code;
	};

	App.translateEvent = function ( event ) {
		var self = this;

		this.request( 'GET', '/events/' + event.id + '/translations' )
			.then( function ( response ) {
				var words = response.data || {};
				var locales = response.locales || [];
				var showing = locales[ 0 ];

				var field = function ( id, label, value, rows ) {
					return '<div class="field"><label class="field__label" for="' + id + '">' +
						esc( label ) + '</label>' +
						( rows
							? '<textarea class="input" id="' + id + '" rows="' + rows + '">' +
								esc( value ) + '</textarea>'
							: '<input class="input" id="' + id + '" maxlength="200" value="' +
								esc( value ) + '">' ) +
					'</div>';
				};

				var read = function () {
					var name = document.getElementById( 'tr-name' );
					var description = document.getElementById( 'tr-description' );

					if ( ! name ) {
						return;
					}

					words[ showing ] = {
						name: name.value.trim(),
						description: description.value.trim(),
						category: document.getElementById( 'tr-category' ).value.trim(),
					};
				};

				var paint = function () {
					var current = words[ showing ] || {};

					document.getElementById( 'tr-fields' ).innerHTML =
						field( 'tr-name', self.t( 'panel.events.nameIn', {
							language: self.languageName( showing ),
						} ), current.name || '', 0 ) +
						field( 'tr-category', self.t( 'panel.events.categoryIn', {
							language: self.languageName( showing ),
						} ), current.category || '', 0 ) +
						field( 'tr-description', self.t( 'panel.events.descriptionIn', {
							language: self.languageName( showing ),
						} ), current.description || '', 5 );
				};

				self.modal( {
					title: self.t( 'panel.events.translationsTitle', { name: event.name } ),
					submitLabel: self.t( 'panel.common.save' ),
					body:
						'<div class="stack">' +
							'<p class="hint">' + esc( self.t( 'panel.events.translationsHint' ) ) + '</p>' +
							'<div class="field"><label class="field__label" for="tr-locale">' +
								esc( self.t( 'panel.events.language' ) ) + '</label>' +
								'<select class="select" id="tr-locale">' +
									locales.map( function ( code ) {
										return '<option value="' + esc( code ) + '">' +
											esc( self.languageName( code ) ) +
											( ( words[ code ] || {} ).name ? ' ✓' : '' ) + '</option>';
									} ).join( '' ) +
								'</select></div>' +
							'<div id="tr-fields"></div>' +
							'<p class="hint">' + esc( self.t( 'panel.events.originalIs', {
								name: response.original.name,
							} ) ) + '</p>' +
						'</div>',
					onSubmit: function () {
						read();

						return self.request( 'PUT', '/events/' + event.id + '/translations', {
							translations: words,
						} ).then( function () {
							self.toast( self.t( 'panel.events.translationsSaved' ) );
						} );
					},
				} );

				var picker = document.getElementById( 'tr-locale' );

				picker.addEventListener( 'change', function () {
					// Keep what was typed before moving on: a picker that discarded it would be a
					// picker nobody uses twice.
					read();
					showing = picker.value;
					paint();
				} );

				paint();
			} )
			.catch( function ( error ) { self.toast( error.message, true ); } );
	};

	/**
	 * Move a night to another night.
	 *
	 * Every ticket already sold stays sold and stays valid, which is the whole difference from
	 * calling it off — and the first thing the modal says, because the commonest reaction to "your
	 * event has changed" is to assume the ticket has not survived it.
	 */
	App.rescheduleEvent = function ( event ) {
		var self = this;

		this.modal( {
			title: this.t( 'panel.events.rescheduleTitle', { name: event.name } ),
			submitLabel: this.t( 'panel.events.rescheduleSubmit' ),
			body:
				'<div class="stack">' +
					'<p class="hint">' + esc( this.t( 'panel.events.rescheduleHint' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="move-starts">' +
						esc( this.t( 'panel.events.rescheduleTo' ) ) + '</label>' +
						'<input class="input" id="move-starts" type="datetime-local" value="' +
						esc( localStamp( event.starts_at ) ) + '"></div>' +
					'<div class="field"><label class="field__label" for="move-reason">' +
						esc( this.t( 'panel.events.reason' ) ) + '</label>' +
						'<input class="input" id="move-reason" maxlength="300">' +
						'<span class="field__hint">' + esc( this.t( 'panel.events.reasonHint' ) ) +
						'</span></div>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="move-notify" checked>' +
						'<span>' + esc( this.t( 'panel.events.tellBuyers' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var when = document.getElementById( 'move-starts' ).value;

				if ( ! when ) {
					self.toast( self.t( 'panel.events.rescheduleNeedsDate' ), true );

					return true;
				}

				return self.request( 'POST', '/events/' + event.id + '/reschedule', {
					starts_at: new Date( when ).toISOString(),
					reason: document.getElementById( 'move-reason' ).value.trim() || null,
					notify: document.getElementById( 'move-notify' ).checked,
				} ).then( function () {
					self.toast( self.t( 'panel.events.rescheduled' ) );
					self.renderEvents();
				} );
			},
		} );
	};

	/**
	 * Call a night off.
	 *
	 * The one action on this platform that cannot be undone by pressing something else: the money
	 * goes back, the tickets are void, and everybody is told. So it asks for the event's own name
	 * rather than a yes — on a list of twelve dates, a mis-click on the wrong row would be a
	 * disaster with no way back.
	 */
	App.cancelEvent = function ( event ) {
		var self = this;

		this.modal( {
			title: this.t( 'panel.events.cancelTitle', { name: event.name } ),
			submitLabel: this.t( 'panel.events.cancelSubmit' ),
			danger: true,
			body:
				'<div class="stack">' +
					'<p class="hint">' + esc( this.t( 'panel.events.cancelHint' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="off-reason">' +
						esc( this.t( 'panel.events.reason' ) ) + '</label>' +
						'<input class="input" id="off-reason" maxlength="300" required>' +
						'<span class="field__hint">' + esc( this.t( 'panel.events.cancelReasonHint' ) ) +
						'</span></div>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="off-refund" checked>' +
						'<span>' + esc( this.t( 'panel.events.refundEverything' ) ) + '</span></label>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="off-notify" checked>' +
						'<span>' + esc( this.t( 'panel.events.tellBuyers' ) ) + '</span></label>' +
					'<div class="field"><label class="field__label" for="off-confirm">' +
						esc( this.t( 'panel.events.typeTheName', { name: event.name } ) ) + '</label>' +
						'<input class="input" id="off-confirm" autocomplete="off"></div>' +
				'</div>',
			onSubmit: function () {
				var reason = document.getElementById( 'off-reason' ).value.trim();

				if ( ! reason ) {
					self.toast( self.t( 'panel.events.cancelNeedsReason' ), true );

					return true;
				}

				return self.request( 'POST', '/events/' + event.id + '/cancel', {
					reason: reason,
					confirm: document.getElementById( 'off-confirm' ).value,
					refund: document.getElementById( 'off-refund' ).checked,
					notify: document.getElementById( 'off-notify' ).checked,
				} ).then( function () {
					self.toast( self.t( 'panel.events.cancelled' ) );
					self.renderEvents();
				} );
			},
		} );
	};

	/**
	 * Put the same production on again on other nights.
	 *
	 * Dates typed one per line rather than picked one at a time: a three-week run is twenty-one
	 * dates, and twenty-one visits to a date picker is not a feature, it is a punishment. Weekly
	 * and daily buttons fill the box, and what is in the box is still editable — a run that skips
	 * Mondays is a run somebody edits by deleting two lines.
	 */
	App.repeatEvent = function ( event ) {
		var self = this;
		var starts = event.starts_at ? new Date( event.starts_at ) : new Date();

		var stamp = function ( date ) {
			var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };

			return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() ) +
				'T' + pad( date.getHours() ) + ':' + pad( date.getMinutes() );
		};

		var host = this.modal( {
			title: this.t( 'panel.events.repeatTitle', { name: event.name } ),
			submitLabel: this.t( 'panel.events.repeatSubmit' ),
			body:
				'<div class="stack">' +
					'<p class="hint">' + esc( this.t( 'panel.events.repeatHint' ) ) + '</p>' +
					'<div class="row row--wrap">' +
						'<button class="btn btn--sm" type="button" data-fill="1">' +
							esc( this.t( 'panel.events.repeatDaily' ) ) + '</button>' +
						'<button class="btn btn--sm" type="button" data-fill="7">' +
							esc( this.t( 'panel.events.repeatWeekly' ) ) + '</button>' +
					'</div>' +
					'<div class="field"><label class="field__label" for="repeat-dates">' +
						esc( this.t( 'panel.events.repeatDates' ) ) + '</label>' +
						'<textarea class="input input--code" id="repeat-dates" rows="8" ' +
							'placeholder="' + esc( stamp( starts ) ) + '"></textarea>' +
						'<span class="field__hint">' + esc( this.t( 'panel.events.repeatFormat' ) ) +
						'</span></div>' +
				'</div>',
			onSubmit: function () {
				var dates = document.getElementById( 'repeat-dates' ).value
					.split( '\n' )
					.map( function ( line ) { return line.trim(); } )
					.filter( Boolean );

				if ( ! dates.length ) {
					self.toast( self.t( 'panel.events.repeatNeedDates' ), true );

					return true;
				}

				return self.request( 'POST', '/events/' + event.id + '/repeat', { dates: dates } )
					.then( function ( result ) {
						self.toast( self.t( 'panel.events.repeated', {
							count: self.number( result.data.length ),
						} ) );
						self.renderEvents();
					} );
			},
		} );

		host.querySelectorAll( '[data-fill]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var step = Number( button.dataset.fill );
				var lines = [];

				// Six more nights after this one, which is a week's run or a week of Fridays.
				for ( var i = 1; i <= 6; i++ ) {
					var next = new Date( starts.getTime() );

					next.setDate( next.getDate() + i * step );
					lines.push( stamp( next ) );
				}

				document.getElementById( 'repeat-dates' ).value = lines.join( '\n' );
			} );
		} );
	};

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

	/**
	 * The door, live, while a sale is running.
	 *
	 * It keeps asking, which is what an organiser wants at ten o'clock on the morning of a big
	 * onsale — and it stops asking on its own the moment the modal is gone, because the element it
	 * writes into is gone with it. No listener to remember to remove, and no timer left running
	 * behind a screen nobody is looking at.
	 */
	App.showQueue = function ( eventId, name ) {
		var self = this;

		function tiles( state ) {
			return [
				[ 'waiting', state.waiting ],
				[ 'inside', state.inside ],
				[ 'capacity', state.capacity ],
				[ 'minutes', state.minutes ],
			].map( function ( pair ) {
				return '<div class="stat stat--block"><span class="stat__value tnum">' +
					esc( self.number( pair[ 1 ] ) ) + '</span><span class="stat__label">' +
					esc( self.t( 'panel.room.' + pair[ 0 ] ) ) + '</span></div>';
			} ).join( '' );
		}

		function ask() {
			var host = document.getElementById( 'queue-tiles' );

			if ( ! host ) {
				return;
			}

			self.request( 'GET', '/events/' + eventId + '/queue' )
				.then( function ( state ) {
					var into = document.getElementById( 'queue-tiles' );

					if ( ! into ) {
						return;
					}

					into.innerHTML = tiles( state );
					document.getElementById( 'queue-state' ).textContent =
						self.t( state.open ? 'panel.room.doorsOpen' : 'panel.room.doorsShut' );

					window.setTimeout( ask, 5000 );
				} )
				.catch( function () {} );
		}

		this.modal( {
			title: name || this.t( 'panel.room.title' ),
			cancelLabel: null,
			doneLabel: this.t( 'panel.common.close' ),
			body: '<div class="stat-grid" id="queue-tiles"></div>' +
				'<p class="hint" id="queue-state"></p>' +
				'<p class="hint">' + esc( this.t( 'panel.room.hint' ) ) + '</p>',
		} );

		ask();
	};

	/**
	 * What is being offered back to the public tonight.
	 *
	 * The screen is mostly a list, and deliberately: there is nothing here that puts a seat up for
	 * resale, because that is the ticket holder's decision about the ticket they paid for. The one
	 * button takes a listing back down, for the caller who has rung the box office to say they can
	 * come after all — and taking it down gives nothing back and takes nothing away, since the
	 * seat was theirs the whole time it sat there.
	 */
	App.showResale = function ( eventId, name ) {
		var self = this;

		function draw() {
			var host = document.getElementById( 'resale-list' );

			if ( ! host ) {
				return;
			}

			self.request( 'GET', '/events/' + eventId + '/resale' ).then( function ( response ) {
				var into = document.getElementById( 'resale-list' );

				if ( ! into ) {
					return;
				}

				if ( ! response.data.length ) {
					into.innerHTML = '<p class="hint">' + esc( self.t( 'panel.resale.none' ) ) + '</p>';

					return;
				}

				into.innerHTML = response.data.map( function ( listing ) {
					return '<div class="quota-row">' +
						'<span><strong>' + esc( listing.seat || '—' ) + '</strong>' +
							'<span class="muted on-own-line">' + esc( listing.seller ) + ' · ' +
							esc( self.money( listing.amount, listing.currency ) ) + ' · ' +
							esc( self.t( 'panel.resale.states.' + listing.state ) ) +
							'</span></span>' +
						( 'open' === listing.state
							? '<button type="button" class="btn btn--sm" data-unlist="' +
								esc( listing.id ) + '">' +
								esc( self.t( 'panel.resale.withdraw' ) ) + '</button>'
							: '' ) +
					'</div>';
				} ).join( '' );

				into.querySelectorAll( '[data-unlist]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.request( 'DELETE', '/events/' + eventId + '/resale/' +
							button.dataset.unlist )
							.then( function () {
								self.toast( self.t( 'panel.resale.withdrawn' ) );
								draw();
							} )
							.catch( function ( error ) { self.toast( error.message, true ); } );
					} );
				} );
			} ).catch( function ( error ) { self.toast( error.message, true ); } );
		}

		this.modal( {
			title: name || this.t( 'panel.resale.title' ),
			cancelLabel: null,
			doneLabel: this.t( 'panel.common.close' ),
			body: '<p class="hint">' + esc( this.t( 'panel.resale.description' ) ) + '</p>' +
				'<div class="stack" id="resale-list"></div>',
		} );

		draw();
	};

	/**
	 * How much of one night each channel may sell.
	 *
	 * Every channel is listed, including the ones with no limit — an organiser deciding whether to
	 * promise an agent four hundred needs to see what the website is already doing. An empty box is
	 * "no limit", which is the ordinary case and is stored as no row at all rather than as a very
	 * large number somebody would later have to interpret.
	 */
	App.showQuotas = function ( eventId, name ) {
		var self = this;

		this.request( 'GET', '/events/' + eventId + '/quotas' ).then( function ( response ) {
			var rows = response.data.map( function ( channel ) {
				return '<label class="quota-row">' +
					'<span><strong>' + esc( channel.name ) + '</strong>' +
						'<span class="muted on-own-line">' +
						esc( self.t( 'panel.quotas.taken', {
							count: self.number( channel.taken ),
						} ) ) +
						( null === channel.left
							? ''
							: ' · ' + esc( self.t( 'panel.quotas.left', {
								count: self.number( channel.left ),
							} ) ) ) +
					'</span></span>' +
					'<input class="input tnum" type="number" min="0" ' +
						'data-channel="' + esc( channel.api_client_id ) + '" ' +
						'placeholder="' + esc( self.t( 'panel.quotas.noLimit' ) ) + '" value="' +
						esc( null === channel.places ? '' : channel.places ) + '">' +
				'</label>';
			} ).join( '' );

			self.modal( {
				title: name || self.t( 'panel.quotas.title' ),
				submitLabel: self.t( 'panel.common.save' ),
				body:
					'<p class="hint">' + esc( self.t( 'panel.quotas.description' ) ) + '</p>' +
					'<div class="stack">' + ( rows || '<p class="hint">' +
						esc( self.t( 'panel.quotas.noChannels' ) ) + '</p>' ) + '</div>',
				onSubmit: function () {
					var quotas = [];

					document.querySelectorAll( '[data-channel]' ).forEach( function ( field ) {
						quotas.push( {
							api_client_id: field.dataset.channel,
							// Empty is "no limit", and no limit is no row: a channel left out of
							// the payload is one whose promise is finished with.
							places: '' === field.value.trim() ? null : Number( field.value ),
						} );
					} );

					return self.request( 'PUT', '/events/' + eventId + '/quotas', { quotas: quotas } )
						.then( function () { self.toast( self.t( 'panel.quotas.saved' ) ); } );
				},
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
			// The other half of connecting a shop: a key lets it ask us things, a webhook means it
			// does not have to. Same screen, because it is the same afternoon's work.
			window.SeatmapWebhooks.load( this ),
			// Which websites may draw this organiser's halls. The other half of the snippet below:
			// copying it is free, and this is what decides where the copy works.
			this.request( 'GET', '/embed-origins' ).catch( function () { return { data: [] }; } ),
		] )
			.then( function ( results ) {
				var response = results[ 0 ];
				var events = results[ 1 ].data || [];
				var hooks = results[ 2 ];
				var origins = results[ 3 ].data || [];
				var rows = response.data.map( function ( client ) {
					var keys = client.keys.map( function ( key ) {
						return '<div class="row"><code>' + esc( key.key_id ) + '</code>' +
							'<span class="muted">…' + esc( key.secret_hint || '' ) + '</span>' +
							// What this one may do. "Everything" in words rather than by listing
							// the catalogue: the two mean the same today and different tomorrow.
							'<span class="muted">' + esc( key.scopes
								? key.scopes.map( function ( scope ) {
									// The catalogue is keyed by the scope's last word: t() resolves
									// a dotted path, so "orders.read" could never be one key.
									return self.t( 'panel.connections.scopes.' + scope.split( '.' ).pop() );
								} ).join( ', ' )
								: self.t( 'panel.connections.scopeAll' ) ) + '</span>' +
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
							esc( self.t( 'panel.connections.emptyBody' ) ),
							{ does: 'add-client', label: self.t( 'panel.connections.connect' ) } )
					) + window.SeatmapWebhooks.markup( self, hooks ) +
						self.embedMarkup( events ) + self.originsMarkup( origins ),
				} );

				self.bindEmbed( events );
				self.bindOrigins();
				window.SeatmapWebhooks.bind( self, hooks );

				document.getElementById( 'add-client' ).addEventListener( 'click', function () {
					self.newClient();
				} );

				self.main().querySelectorAll( '[data-rotate]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.newKey( button.dataset.rotate ); } );
				} );

				self.main().querySelectorAll( '[data-revoke]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.revokeKey( button.dataset.revoke, button.dataset.key ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	/**
	 * A new key, and what it is for.
	 *
	 * Asked before the key exists rather than edited afterwards, because a key's powers cannot be
	 * changed once it is out in the world: the thing holding it is a shop somebody else configured,
	 * and narrowing it silently is how a working checkout stops taking money on a Friday night. A
	 * key that needs different powers is a new key and a revoked one.
	 */
	App.newKey = function ( clientId ) {
		var self = this;
		var scopes = [ 'orders.read', 'orders.write', 'orders.refund' ];

		this.modal( {
			title: this.t( 'panel.connections.newKey' ),
			submitLabel: this.t( 'panel.connections.rotate' ),
			body: '<p>' + esc( this.t( 'panel.connections.newKeyBody' ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="key-label">' +
				esc( this.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="key-label" name="label" maxlength="100" placeholder="' +
				esc( this.t( 'panel.connections.keyLabelPlaceholder' ) ) + '"></div>' +
				scopes.map( function ( scope ) {
					// Everything ticked to begin with: this is what a key could do before it could
					// be narrowed, and a dialogue that starts by taking powers away would have
					// somebody issue a key that cannot sell.
					return '<label class="perms__row"><input type="checkbox" class="checkbox" ' +
						'name="scopes" value="' + scope + '" checked>' +
						'<span>' + esc( self.t( 'panel.connections.scopes.' + scope.split( '.' ).pop() ) ) +
						'</span></label>';
				} ).join( '' ) +
				'<p class="field__hint">' + esc( this.t( 'panel.connections.scopesHint' ) ) + '</p>',
			onSubmit: function ( data ) {
				var chosen = data.getAll( 'scopes' );

				if ( ! chosen.length ) {
					return Promise.reject( new Error( self.t( 'panel.connections.scopesNone' ) ) );
				}

				return self.request( 'POST', '/api-clients/' + clientId + '/keys', {
					label: data.get( 'label' ) || undefined,
					// All three is the same as "no limit", and stored as no limit: the two read the
					// same today and differently the day a fourth is added.
					scopes: chosen.length === scopes.length ? undefined : chosen,
				} ).then( function ( credentials ) {
					self.showCredentials( credentials, true );
				} );
			},
		} );
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

	/**
	 * The websites this organiser's seat map may be drawn on.
	 *
	 * Directly under the snippet, and that placement is the whole design: the snippet is three
	 * lines with no key in it, which is what makes it usable and also what makes it copyable.
	 * Somebody reading the thing they are about to paste into a page should read, in the same
	 * glance, where that paste will and will not work.
	 */
	App.originsMarkup = function ( origins ) {
		var self = this;

		var rows = origins.map( function ( origin ) {
			return '<tr><td class="table__primary"><code>' + esc( origin.hostname ) + '</code>' +
				( origin.label
					? '<span class="muted on-own-line">' + esc( origin.label ) + '</span>'
					: '' ) +
				'</td>' +
				'<td class="muted">' + ( origin.last_seen_at
					? esc( self.date( origin.last_seen_at ) )
					: esc( self.t( 'panel.connections.originNeverUsed' ) ) ) + '</td>' +
				'<td class="table__actions">' +
					'<button class="btn btn--sm btn--danger" data-origin-remove="' + esc( origin.id ) +
						'" data-origin-name="' + esc( origin.hostname ) + '">' +
						esc( self.t( 'panel.common.remove' ) ) + '</button>' +
				'</td></tr>';
		} ).join( '' );

		return '<h3 class="subhead">' + esc( this.t( 'panel.connections.originsTitle' ) ) + '</h3>' +
			// Named, because this screen now carries two tables and anything looking for one of
			// them by tag alone finds both.
			'<div class="card card--pad" id="embed-origins">' +
				'<p class="hint">' + esc( this.t( 'panel.connections.originsHint' ) ) + '</p>' +
				/*
				 * Said out loud rather than left to be discovered.
				 *
				 * An organiser who has just pasted the snippet onto a page that is not on this list
				 * sees a refusal and no cause. One sentence here is the difference between that and
				 * a support ticket.
				 */
				( origins.length
					? ''
					: '<p class="notice notice--warn">' +
						esc( this.t( 'panel.connections.originsEmpty' ) ) + '</p>' ) +
				'<div class="filters spaced">' +
					// A placeholder is not a name: it disappears the moment somebody types, which is
					// exactly when they are most likely to want to know which box is which.
					'<input class="input" id="origin-host" type="text" aria-label="' +
						esc( this.t( 'panel.connections.originPlaceholder' ) ) + '" placeholder="' +
						esc( this.t( 'panel.connections.originPlaceholder' ) ) + '">' +
					'<input class="input" id="origin-label" type="text" aria-label="' +
						esc( this.t( 'panel.connections.originLabel' ) ) + '" placeholder="' +
						esc( this.t( 'panel.connections.originLabel' ) ) + '">' +
					'<button class="btn btn--primary" id="origin-add">' +
						esc( this.t( 'panel.connections.originAdd' ) ) + '</button>' +
				'</div>' +
				( origins.length
					? this.table(
						[
							this.t( 'panel.connections.originWebsite' ),
							this.t( 'panel.connections.originLastDrew' ),
							'',
						],
						rows,
						''
					)
					: '' ) +
			'</div>';
	};

	App.bindOrigins = function () {
		var self = this;
		var host = document.getElementById( 'origin-host' );
		var label = document.getElementById( 'origin-label' );
		var add = document.getElementById( 'origin-add' );

		if ( add && host ) {
			var submit = function () {
				if ( ! host.value.trim() ) {
					return;
				}

				self.request( 'POST', '/embed-origins', {
					hostname: host.value.trim(),
					label: label && label.value.trim() ? label.value.trim() : null,
				} )
					.then( function () {
						self.toast( self.t( 'panel.connections.originAdded' ) );
						self.renderConnections();
					} )
					.catch( function ( error ) { self.error( error ); } );
			};

			add.addEventListener( 'click', submit );
			// Typing an address and pressing enter is what somebody does with a one-field form.
			host.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					submit();
				}
			} );
		}

		this.main().querySelectorAll( '[data-origin-remove]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				self.modal( {
					title: self.t( 'panel.connections.originRemoveTitle', {
						name: button.dataset.originName,
					} ),
					submitLabel: self.t( 'panel.common.remove' ),
					danger: true,
					body: '<p>' + esc( self.t( 'panel.connections.originRemoveBody' ) ) + '</p>',
					onSubmit: function () {
						return self.request( 'DELETE', '/embed-origins/' + button.dataset.originRemove )
							.then( function () {
								self.toast( self.t( 'panel.connections.originRemoved' ) );
								self.renderConnections();
							} );
					},
				} );
			} );
		} );
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

		/*
		 * The photographs alongside the chart itself.
		 *
		 * Fetched with the map rather than on demand, so the inspector can show the one belonging
		 * to a section the moment somebody selects it. Failure is silent and leaves an empty set:
		 * a designer whose photographs could not be listed should still be able to draw.
		 */
		this.seatViews = {};

		this.request( 'GET', '/seat-maps/' + mapId + '/views' )
			.then( function ( answer ) {
				( answer.data || [] ).forEach( function ( row ) {
					self.seatViews[ row.section_key ] = { url: row.url || '', caption: row.caption || '' };
				} );
			} )
			.catch( function () {} );

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

	/**
	 * Write the whole set of section photographs back.
	 *
	 * Whole, because the endpoint replaces the set: a row whose address has just been cleared is a
	 * photograph being taken away, and sending only the filled ones would mean "no change".
	 */
	App.saveSeatViews = function () {
		var self = this;
		var views = this.seatViews || {};

		return this.request( 'PUT', '/seat-maps/' + this.map.id + '/views', {
			views: Object.keys( views ).map( function ( key ) {
				return {
					section_key: key,
					url: views[ key ].url || null,
					caption: views[ key ].caption || '',
				};
			} ),
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
					// The plan, or the room the plan describes. Beside preview because both
					// answer "what will somebody else see", one for a buyer's screen and one for
					// somebody sitting in the hall.
					toolbarButton( 'dz-3d', 'cube', t( 'panel.designer.seeInThreeD' ) ) +
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

		// A designer opened twice must not leave the first one listening to the window — see
		// Editor.prototype.destroy for what that looked like.
		if ( this.editor ) {
			this.editor.destroy();
		}

		var editor = new window.SeatmapEditor( document.getElementById( 'dz-canvas' ), {
			chart: chart,
			onChange: function () { self.refreshDesigner(); },
			onSelectionChange: function () { self.refreshInspector(); self.refreshStatus(); },
			onContextChange: function () { self.refreshDesigner(); },
			onStatus: function ( message ) { document.getElementById( 'dz-status' ).textContent = message; },
			// A question gets the panel's own dialog rather than the browser's grey box, and a
			// picture gets the media library. Both are asynchronous; see the editor's own note.
			ask: function ( question, value, done ) { self.askInDesigner( question, value, done ); },
			pickPicture: function ( done ) { self.pickPicture( done ); },
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
			{
				onManageCategories: function () { self.manageCategories(); },
				// The photographs a buyer sees from each section. Handed in rather than fetched by
				// the inspector: they belong to the map, and the map is this screen's business.
				seatViews: function () { return self.seatViews || {}; },
				onSeatViewChange: function () { self.saveSeatViews(); },
				// One picture field, the same one the rest of the panel uses.
				media: window.SeatmapMedia
					? function ( options, onChange ) {
						return window.SeatmapMedia.attach( self, options, onChange );
					}
					: null,
			}
		);

		// Exposed for the browser smoke test, and genuinely useful in the console when diagnosing
		// a chart a customer has sent in.
		window.__editor = editor;
		// And the panel itself, so a check can ask what the room it is showing looks like.
		window.__panel = self;

		this.bindDesigner();
		this.renderTools();
		editor.zoomToFit();
		this.refreshDesigner();
		editor.setTool( 'select' );

		this.onResize = function () { editor.resize(); };
		window.addEventListener( 'resize', this.onResize );
	};

	/** The tools that put something on the plan — the ones a locked chart has no use for. */
	var DRAWING_TOOLS = [
		'row', 'curvedRow', 'section', 'area', 'table', 'booth', 'shape', 'line', 'text', 'image', 'icon',
	];

	App.renderTools = function () {
		var self = this;
		var host = document.getElementById( 'dz-tools' );

		host.innerHTML = '';
		this.toolsLocked = this.chartLocked();

		this.TOOLS.forEach( function ( tool ) {
			if ( tool.separator ) {
				host.appendChild( node( 'div', 'tools__sep' ) );

				return;
			}

			var button = node( 'button', 'icon-btn' );
			var label = self.t( 'panel.tools.' + tool.key );
			/*
			 * A locked chart cannot be drawn on, so the tools that draw are off.
			 *
			 * Looking is still allowed: select, same-type, lasso and pan stay, because reading what
			 * a row is set to is not an edit. A tool that can be picked up and then refuses is the
			 * thing this replaces.
			 */
			var forbidden = self.chartLocked() && DRAWING_TOOLS.indexOf( tool.key ) !== -1;

			button.innerHTML = icon( tool.icon );
			button.dataset.tool = tool.key;
			button.disabled = forbidden;
			button.setAttribute( 'data-tip', forbidden ? self.t( 'panel.hints.readOnly' ) : label );
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
			editor.destroy();
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

		on( 'dz-3d', function () { self.toggleThreeD(); } );

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

	/* ------------------------------------------------------------------- the room, in the panel */

	/**
	 * The plan, or the room it describes.
	 *
	 * The same canvas: switching is not opening a second window but looking at the same chart from
	 * inside it. The editor's own drawing and pointer handling are put aside while the room is up,
	 * because a click in a room is not a click on a plan — there is nothing to select at a point
	 * that is three metres above the floor.
	 */
	App.toggleThreeD = function () {
		var self = this;
		var editor = this.editor;

		if ( ! editor || ! window.SeatmapHall ) {
			return;
		}

		if ( this.hall ) {
			this.hall.unbind();
			this.hall = null;
			editor.draw = this.planDraw;
			editor.suspended = false;
			this.planDraw = null;
			document.getElementById( 'dz-3d' ).classList.remove( 'is-active' );
			document.querySelector( '.designer' ).classList.remove( 'is-room' );
			editor.resize();
			this.refreshInspector();

			return;
		}

		this.hall = new window.SeatmapHall( editor ).build().bind();

		// The editor keeps its own draw for the plan; while the room is up, every path that would
		// have redrawn the plan draws the room instead, so an edit made from the inspector is seen
		// in the room it changes.
		this.planDraw = editor.draw;
		editor.draw = function () {
			self.hall.build();
			self.hall.draw();
			self.refreshZoom();
		};

		// Nothing on the plan can be selected or dragged from inside the room.
		editor.suspended = true;
		editor.clearSelection();

		document.getElementById( 'dz-3d' ).classList.add( 'is-active' );
		document.querySelector( '.designer' ).classList.add( 'is-room' );
		document.getElementById( 'dz-inspector' ).innerHTML = '';
		this.roomFloorKey = null;
		this.roomLocked = null;
		this.hall.draw();
		this.refreshInspector();
	};

	/**
	 * Whether the chart may be edited at this moment.
	 *
	 * Two things used to answer this and they disagreed. `readOnly` is a fact about the *map* —
	 * published, with no draft forked from it yet — and is decided once, when the designer opens.
	 * `editor.locked` is what the padlock in the toolbar actually toggles, and it is the live
	 * answer. Reading the first where the second was meant is how the room's fields stayed greyed
	 * out for ever: the toast said "chart unlocked" and every number beside it still refused.
	 *
	 * One accessor, and the padlock is the only thing that moves it.
	 */
	App.chartLocked = function () {
		return !! ( this.editor && this.editor.locked );
	};

	/**
	 * The four numbers that make a plan a room.
	 *
	 * Shown in place of the object inspector while the room is up, because the room is what they
	 * change and an organiser typing a rake wants to watch it happen. Every one of them is a
	 * property of the chart rather than of a selection: a hall has one stage and each block has one
	 * rake, whatever happens to be selected at the time.
	 */
	App.roomInspector = function () {
		var self = this;
		var chart = this.editor.chart;
		var settings = window.SeatmapHall3D.settings( chart );
		var t = this.t.bind( this );
		var blocks = [];

		window.SeatmapChart.eachObject( chart, function ( object, container, floor ) {
			if ( 'section' === object.type && floor.key === self.editor.floorKey ) {
				blocks.push( object );
			}
		} );

		// A locked chart's numbers are locked too: a field that looks editable and silently refuses
		// is worse than one that says it is not. The padlock is what decides, not how the map
		// happened to arrive — see App.chartLocked.
		var locked = this.chartLocked() ? ' disabled' : '';

		var field = function ( id, label, value, hint, step ) {
			return '<label class="field"><span class="field__label">' + esc( label ) + '</span>' +
				'<input class="input" type="number" id="' + id + '" value="' + esc( value ) + '"' +
				' step="' + ( step || 1 ) + '"' + locked + '>' +
				( hint ? '<span class="field__hint">' + esc( hint ) + '</span>' : '' ) + '</label>';
		};

		var rows = blocks.map( function ( block ) {
			var own = window.SeatmapHall3D.forSection( settings, block.key );
			var name = ( block.labeling && ( block.labeling.displayedLabel || block.labeling.label ) ) ||
				block.label || block.key;

			return '<div class="room-block" data-block="' + esc( block.key ) + '">' +
				'<p class="room-block__name">' + esc( name ) + '</p>' +
				'<div class="room-block__fields">' +
					field( 'room-base-' + block.key, t( 'panel.hall3d.base' ), own.base,
						'', 5 ) +
					field( 'room-rake-' + block.key, t( 'panel.hall3d.rake' ), own.rake, '', 0.5 ) +
					field( 'room-depth-' + block.key, t( 'panel.hall3d.depth' ), own.depth, '', 10 ) +
				'</div>' +
			'</div>';
		} ).join( '' );

		return '<div class="inspector__panel">' +
			'<h3 class="inspector__title">' + esc( t( 'panel.hall3d.title' ) ) + '</h3>' +
			'<p class="inspector__hint">' + esc( t( 'panel.hall3d.lead' ) ) + '</p>' +
			( this.chartLocked()
				? '<p class="inspector__hint">' + esc( t( 'panel.hints.readOnly' ) ) + '</p>'
				: '' ) +
			'<label class="perms__row"><input type="checkbox" class="checkbox" id="room-enabled"' +
				( settings.enabled ? ' checked' : '' ) + locked + '>' +
				'<span>' + esc( t( 'panel.hall3d.showBuyers' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( t( 'panel.hall3d.showBuyersHint' ) ) + '</p>' +
			field( 'room-stage-height', t( 'panel.hall3d.stageHeight' ), settings.stage.height,
				t( 'panel.hall3d.unitsHint' ), 2 ) +
			field( 'room-stage-depth', t( 'panel.hall3d.stageDepth' ), settings.stage.depth, '', 10 ) +
			field( 'room-stage-width', t( 'panel.hall3d.stageWidth' ), settings.stage.width,
				t( 'panel.hall3d.stageWidthHint' ), 10 ) +
			field( 'room-rake', t( 'panel.hall3d.hallRake' ), settings.rake,
				t( 'panel.hall3d.rakeHint' ), 0.5 ) +
			( rows
				? '<h4 class="inspector__subtitle">' + esc( t( 'panel.hall3d.blocks' ) ) + '</h4>' + rows
				: '<p class="inspector__hint">' + esc( t( 'panel.hall3d.noBlocks' ) ) + '</p>' ) +
			'<button class="btn btn--sm" id="room-reset-view">' +
				esc( t( 'panel.hall3d.resetView' ) ) + '</button>' +
		'</div>';
	};

	/** Every field writes straight into the chart, and the room redraws as it is typed. */
	App.bindRoomInspector = function () {
		var self = this;
		var editor = this.editor;

		var write = function ( change ) {
			editor.mutate( function ( chart ) {
				chart.view3d = chart.view3d || {};
				chart.view3d.stage = chart.view3d.stage || {};
				chart.view3d.sections = chart.view3d.sections || {};
				change( chart.view3d );
			} );
		};

		var number = function ( id, apply ) {
			var input = document.getElementById( id );

			if ( ! input ) {
				return;
			}

			input.addEventListener( 'input', function () {
				var value = parseFloat( input.value );

				write( function ( view3d ) { apply( view3d, isFinite( value ) ? value : 0 ); } );
			} );
		};

		var enabled = document.getElementById( 'room-enabled' );

		if ( enabled ) {
			enabled.addEventListener( 'change', function () {
				write( function ( view3d ) { view3d.enabled = enabled.checked; } );
			} );
		}

		number( 'room-stage-height', function ( view3d, value ) { view3d.stage.height = value; } );
		number( 'room-stage-depth', function ( view3d, value ) { view3d.stage.depth = value; } );
		number( 'room-stage-width', function ( view3d, value ) { view3d.stage.width = value; } );
		number( 'room-rake', function ( view3d, value ) { view3d.rake = value; } );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-block]' ), function ( node ) {
			var key = node.dataset.block;

			[ [ 'base', 'room-base-' ], [ 'rake', 'room-rake-' ], [ 'depth', 'room-depth-' ] ]
				.forEach( function ( pair ) {
					number( pair[ 1 ] + key, function ( view3d, value ) {
						view3d.sections[ key ] = view3d.sections[ key ] || {};
						view3d.sections[ key ][ pair[ 0 ] ] = value;

						// A block that stands above the floor gets a wall under it unless somebody
						// says otherwise, because that is what a balcony is.
						if ( 'base' === pair[ 0 ] ) {
							view3d.sections[ key ].skirt = value > 0;
						}
					} );
				} );
		} );

		var reset = document.getElementById( 'room-reset-view' );

		if ( reset ) {
			reset.addEventListener( 'click', function () {
				self.hall.reset();
				self.hall.draw();
			} );
		}
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
		/*
		 * The palette is redrawn when the padlock moves and at no other time.
		 *
		 * Which tools are available is a fact about the lock, and the lock changes from three
		 * places: the padlock itself, saving a draft — which forks one from the published version
		 * and so unlocks the chart — and opening a map that has no draft. Redrawing it on every
		 * change instead would rebuild fifteen buttons after each nudge of a row.
		 */
		if ( this.toolsLocked !== this.chartLocked() ) {
			this.renderTools();
		}

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

		/*
		 * The buttons in the top bar that change the chart go off with the padlock.
		 *
		 * Undo among them: it looks like navigation and is an edit, and it was the one way left to
		 * change a chart that says it cannot be changed. Copy is not here — taking a copy of what
		 * is selected changes nothing — and neither are save and publish, which are what somebody
		 * does with a chart they have finished and locked.
		 */
		var frozen = this.chartLocked();

		[ 'dz-undo', 'dz-redo', 'dz-duplicate', 'dz-delete', 'dz-mirror-h', 'dz-mirror-v',
			'dz-focal', 'dz-add-floor' ].forEach( function ( id ) {
			var button = document.getElementById( id );

			if ( button ) {
				button.disabled = frozen;
			}
		} );

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
		/*
		 * While the room is up the inspector is the room's own numbers: there is nothing on a plan
		 * to inspect from inside it, and the fields that shape the room are what somebody switched
		 * to it to change.
		 *
		 * Rendered once, and again only when the floor changes. Every keystroke in a rake field
		 * redraws the room, and a panel rebuilt on each of them would take the cursor out of the
		 * field somebody is still typing in.
		 */
		if ( this.hall ) {
			var host = document.getElementById( 'dz-inspector' );
			/*
			 * Rebuilt when the floor changes — different blocks — and when the padlock moves,
			 * because every field in the panel is enabled or disabled by it. Not on every keystroke:
			 * a rake field redraws the room as it is typed, and a panel rebuilt on each of those
			 * would take the cursor out of the box somebody is still in.
			 */
			var stale = this.roomFloorKey !== this.editor.floorKey ||
				this.roomLocked !== this.chartLocked();

			if ( host && ( stale || ! host.querySelector( '.inspector__panel' ) ) ) {
				this.roomFloorKey = this.editor.floorKey;
				this.roomLocked = this.chartLocked();
				host.innerHTML = this.roomInspector();
				this.bindRoomInspector();
			}

			return;
		}

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

		/*
		 * The palette folds away.
		 *
		 * It floats over the top-left of the plan, two hundred pixels by a hundred and seventy-seven,
		 * and whatever is drawn under it cannot be clicked at all — a chart whose stage sits near the
		 * origin has a stage nobody can select. Folded it is a title bar; the choice is remembered
		 * per browser, because somebody who put it away meant it for more than one chart.
		 */
		var folded = this.layersFolded();
		var title = node( 'button', 'layers__title overline' );

		title.appendChild( node( 'span', null, this.t( 'panel.designer.selectionLayer' ) ) );
		title.innerHTML += icon( folded ? 'plus' : 'minus', { size: 13 } );
		title.setAttribute( 'data-tip', this.t( folded
			? 'panel.designer.showLayers'
			: 'panel.designer.hideLayers' ) );
		title.setAttribute( 'aria-expanded', folded ? 'false' : 'true' );
		title.addEventListener( 'click', function () {
			self.layersFolded( ! folded );
			self.refreshLayers();
		} );

		host.classList.toggle( 'is-folded', folded );
		host.appendChild( title );

		if ( folded ) {
			return;
		}

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

	/**
	 * Whether the layer palette is folded, remembered per browser.
	 *
	 * Read with no argument, written with one. `localStorage` because it is a preference about this
	 * person's screen rather than anything about the chart — a colleague opening the same map should
	 * get their own answer, and the chart should not carry it.
	 */
	App.layersFolded = function ( value ) {
		if ( undefined === value ) {
			try {
				return '1' === window.localStorage.getItem( 'seatmap.layersFolded' );
			} catch ( error ) {
				return false;
			}
		}

		try {
			window.localStorage.setItem( 'seatmap.layersFolded', value ? '1' : '0' );
		} catch ( error ) {
			// A browser with storage off still folds it; the choice just lasts one visit.
		}

		return value;
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

	/**
	 * A question the designer needs answered — the name of a section, the words on a label.
	 *
	 * The panel's own dialog, in the reader's language and in the panel's own type. `window.prompt`
	 * was what this replaced: an untranslated grey box, unstyled, ignored outright by some browsers
	 * when it is not a direct answer to a click, and impossible to put a preview in.
	 *
	 * `done` is called with the answer, or with nothing when the dialog is dismissed.
	 */
	App.askInDesigner = function ( question, value, done ) {
		var answered = false;

		this.modal( {
			title: question,
			submitLabel: this.t( 'panel.common.save' ),
			body: '<div class="field"><label class="field__label" for="dz-ask">' +
				esc( question ) + '</label>' +
				'<input class="input" id="dz-ask" name="answer" required maxlength="120" value="' +
				esc( value == null ? '' : value ) + '"></div>',
			onSubmit: function ( data ) {
				answered = true;
				done( String( data.get( 'answer' ) || '' ).trim() );
			},
			onClose: function () {
				if ( ! answered ) {
					done( null );
				}
			},
		} );
	};

	/**
	 * A picture for the plan: dragged on, chosen from the library, or given as an address.
	 *
	 * The same field as everywhere else on the platform, in a dialog — so the frame somebody has
	 * just dragged out on the canvas waits while they find the floor plan on their desktop.
	 */
	App.pickPicture = function ( done ) {
		var answered = false;

		var host = this.modal( {
			title: this.t( 'panel.prompt.picture' ),
			submitLabel: this.t( 'panel.common.save' ),
			body: '<div class="field"><span class="field__label">' +
				esc( this.t( 'panel.prompt.picture' ) ) + '</span>' +
				( window.SeatmapMedia
					? window.SeatmapMedia.field( { kind: 'image', name: 'href' } )
					: '<input class="input" type="url" name="href" placeholder="https://…">' ) +
				'</div>',
			onSubmit: function ( data, form ) {
				var field = form.querySelector( '[data-media-field]' );
				var href = field
					? window.SeatmapMedia.valueOf( field )
					: String( data.get( 'href' ) || '' ).trim();

				if ( ! href ) {
					return true; // Nothing chosen yet: leave the dialog up rather than losing the frame.
				}

				answered = true;
				done( href );
			},
			onClose: function () {
				if ( ! answered ) {
					done( null );
				}
			},
		} );

		return host;
	};

	App.addFloor = function () {
		var self = this;

		if ( this.chartLocked() ) {
			this.toast( this.t( 'panel.hints.readOnly' ), true );

			return;
		}

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

		if ( this.chartLocked() ) {
			this.toast( this.t( 'panel.hints.readOnly' ), true );

			return;
		}

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
			// The row gesture is the one nobody would guess: inside a section every point on a row
			// belongs to a chair, so the row itself is reached by its name or by holding Alt.
			[ [ 'Alt', this.t( 'panel.shortcuts.click' ) ], 'selectRow' ],
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

	App.clearToast = function () {
		var existing = document.querySelector( '.toast' );

		if ( existing ) {
			existing.remove();
		}
	};

	App.toast = function ( message, isError ) {
		this.clearToast();

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

	/*
	 * Slash puts the cursor in the screen finder.
	 *
	 * Only when nothing is being typed into already: a venue called "Slash" would otherwise be
	 * unnameable, and a seat number with a slash in it would jump the reader out of the field.
	 */
	document.addEventListener( 'keydown', function ( event ) {
		if ( '/' !== event.key || event.metaKey || event.ctrlKey ) {
			return;
		}

		var on = document.activeElement;
		var typing = on && ( /^(INPUT|TEXTAREA|SELECT)$/.test( on.tagName ) || on.isContentEditable );

		if ( typing ) {
			return;
		}

		var box = document.getElementById( 'nav-find' );

		if ( box ) {
			event.preventDefault();
			box.focus();
			box.select();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () { App.init(); } );
} )();
