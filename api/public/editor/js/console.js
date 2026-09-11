/**
 * The platform's own console: every organiser, every site, the tariffs.
 *
 * A separate application from the panel, deliberately. It shares the stylesheet and the icon set —
 * there is no reason for an operator's screen to look like a different product — and shares no
 * code, no route and no token handling, because the day those merge is the day a bug in one is a
 * bug in the other and the blast radius is everybody.
 *
 * It speaks the same six languages as everything else. The catalogue arrives in the page rather
 * than from `/v1/i18n` — that endpoint is public and serves every panel visitor, and an organiser's
 * browser has no business downloading the words of a screen they may not open. Because the page is
 * rendered by the server, switching language is a reload with `?lang=`, which LocaleResolver then
 * remembers for the session.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var STORE = { token: 'seatmap_console_token', locale: 'seatmap.locale' };

	var Console = {
		root: null,
		api: '',
		token: null,
		level: 'support',
		view: 'overview',
		data: {},
		i18n: { locale: 'en', icu: 'en-GB', messages: {}, locales: [] },
	};

	/* Only the key and the icon: the label is looked up when the nav is painted. */
	var NAV = [
		{ key: 'overview', icon: 'chart' },
		{ key: 'tenants', icon: 'users' },
		{ key: 'sites', icon: 'globe' },
		{ key: 'plans', icon: 'tag' },
		{ key: 'invoices', icon: 'wallet' },
		{ key: 'audit', icon: 'history' },
	];

	Console.init = function () {
		Console.root = document.getElementById( 'console' );
		Console.api = Console.root.dataset.api;
		Console.token = global.sessionStorage.getItem( STORE.token );

		try {
			Console.i18n = JSON.parse( Console.root.dataset.i18n || '{}' );
		} catch ( error ) {
			// A console that opens in English beats a console that does not open.
		}

		if ( Console.adoptStoredLanguage() ) {
			return;
		}

		Console.token ? Console.load() : Console.showLogin();
	};

	/**
	 * Honour a language chosen anywhere in this browser.
	 *
	 * The panel and the console are different applications read by the same person, and somebody
	 * who set the panel to German meant the language, not the screen. The server cannot see
	 * localStorage, so the first load in a session redirects once with `?lang=`; the session then
	 * remembers it and no further redirect happens.
	 */
	Console.adoptStoredLanguage = function () {
		var stored = null;

		try {
			stored = global.localStorage.getItem( STORE.locale );
		} catch ( error ) {
			return false;
		}

		var known = ( Console.i18n.locales || [] ).some( function ( entry ) {
			return entry.code === stored;
		} );

		if ( ! stored || ! known || stored === Console.i18n.locale ) {
			return false;
		}

		global.location.replace( global.location.pathname + '?lang=' + encodeURIComponent( stored ) );

		return true;
	};

	/** A reload in another language, remembered for this browser and for this session. */
	Console.chooseLanguage = function ( locale ) {
		try {
			global.localStorage.setItem( STORE.locale, locale );
		} catch ( error ) {
			// Storage off: the session still carries the choice, it just does not outlive it.
		}

		global.location.assign( global.location.pathname + '?lang=' + encodeURIComponent( locale ) );
	};

	/**
	 * Look up `console.some.key`, substituting `:name`.
	 *
	 * A missing key returns its own last segment rather than nothing, for the same reason the
	 * panel's does: a button labelled "suspend" is usable and a button labelled nothing is not.
	 */
	Console.t = function ( key, replace ) {
		var value = key.split( '.' ).reduce( function ( carry, part ) {
			return carry && typeof carry === 'object' ? carry[ part ] : undefined;
		}, Console.i18n.messages );

		if ( 'string' !== typeof value ) {
			return key.split( '.' ).pop();
		}

		return replace
			? Object.keys( replace ).reduce( function ( carry, name ) {
				return carry.split( ':' + name ).join( String( replace[ name ] ) );
			}, value )
			: value;
	};

	/** Whether the catalogue really has this key — for the values operators invent themselves. */
	Console.has = function ( key ) {
		return 'string' === typeof key.split( '.' ).reduce( function ( carry, part ) {
			return carry && typeof carry === 'object' ? carry[ part ] : undefined;
		}, Console.i18n.messages );
	};

	/** The language menu, shown on the sign-in card and again in the sidebar. */
	Console.languageField = function ( id ) {
		return '<select class="select select--sm" id="' + id + '" aria-label="' +
			esc( t( 'console.language' ) ) + '">' +
			( Console.i18n.locales || [] ).map( function ( entry ) {
				return '<option value="' + esc( entry.code ) + '"' +
					( entry.code === Console.i18n.locale ? ' selected' : '' ) + '>' +
					esc( entry.native ) + '</option>';
			} ).join( '' ) +
			'</select>';
	};

	Console.bindLanguage = function ( id ) {
		var select = document.getElementById( id );

		if ( select ) {
			select.addEventListener( 'change', function () { Console.chooseLanguage( select.value ); } );
		}
	};

	/* --------------------------------------------------------------------------- transport */

	Console.request = function ( method, path, body ) {
		// The console's language travels with every call, so a refusal the *server* composes comes
		// back in the language the console is being read in.
		var headers = { Accept: 'application/json', 'X-Seatmap-Locale': Console.i18n.locale };

		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		if ( Console.token ) {
			headers.Authorization = 'Bearer ' + Console.token;
		}

		return fetch( Console.api + path, {
			method: method,
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					if ( 401 === response.status ) {
						Console.signOut();
					}

					var error = new Error(
						( data.error && data.error.message ) || t( 'console.failed' )
					);
					error.code = data.error && data.error.code;

					throw error;
				}

				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------------------- login */

	Console.showLogin = function () {
		Console.root.innerHTML =
			'<div class="auth"><form class="auth__card" id="console-login">' +
				'<div class="auth__brand"><span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) +
					'</span>' + esc( t( 'console.brand' ) ) + '</div>' +
				'<h1 class="auth__title">' + esc( t( 'console.title' ) ) + '</h1>' +
				'<p class="auth__sub">' + esc( t( 'console.subtitle' ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="c-email">' +
					esc( t( 'console.email' ) ) + '</label>' +
					'<input class="input" id="c-email" type="email" required autocomplete="username"></div>' +
				'<div class="field"><label class="field__label" for="c-password">' +
					esc( t( 'console.password' ) ) + '</label>' +
					'<input class="input" id="c-password" type="password" required ' +
					'autocomplete="current-password"></div>' +
				'<div class="issue issue--error" id="c-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">' +
					esc( t( 'console.signIn' ) ) + '</button>' +
				// On the sign-in card as well as inside: somebody who cannot read this screen
				// cannot get past it to the switch on the other side.
				'<p class="auth__foot">' + Console.languageField( 'c-login-lang' ) + '</p>' +
			'</form></div>';

		Console.bindLanguage( 'c-login-lang' );

		var form = document.getElementById( 'console-login' );
		var problem = document.getElementById( 'c-error' );

		document.getElementById( 'c-email' ).focus();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			problem.hidden = true;

			// The console's own login. An operator is a member of no organiser, so the panel's
			// endpoint would refuse them — correctly.
			Console.request( 'POST', '/admin/login', {
				email: document.getElementById( 'c-email' ).value,
				password: document.getElementById( 'c-password' ).value,
			} ).then( function ( response ) {
				Console.token = response.token;
				global.sessionStorage.setItem( STORE.token, response.token );
				Console.load();
			} ).catch( function ( error ) {
				problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
				problem.hidden = false;
			} );
		} );
	};

	Console.signOut = function () {
		global.sessionStorage.removeItem( STORE.token );
		Console.token = null;
		Console.showLogin();
	};

	/* ------------------------------------------------------------------------------- shell */

	Console.load = function () {
		Console.request( 'GET', '/admin/overview' ).then( function ( overview ) {
			Console.data.overview = overview;
			Console.level = overview.me.level;
			Console.paintShell();
			Console.go( 'overview' );
		} ).catch( function ( error ) {
			// The console shell is served to anybody; only a token that belongs to an operator
			// gets past the API. A signed-in organiser is told nothing beyond "not found".
			Console.signOut();
			global.setTimeout( function () {
				var problem = document.getElementById( 'c-error' );

				if ( problem ) {
					problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
					problem.hidden = false;
				}
			}, 50 );
		} );
	};

	Console.paintShell = function () {
		Console.root.innerHTML =
			'<div class="shell">' +
				'<aside class="sidebar">' +
					'<div class="sidebar__brand">' +
						'<span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) + '</span>' +
						'<span class="grow">' + esc( t( 'console.brand' ) ) + '</span>' +
					'</div>' +
					'<nav class="sidebar__nav" id="c-nav" aria-label="' +
						esc( t( 'console.sections' ) ) + '"></nav>' +
					'<div class="sidebar__footer">' +
						'<div class="sidebar__language">' + Console.languageField( 'c-lang' ) + '</div>' +
						'<div class="account">' +
						'<div class="account__body">' +
							'<div class="account__name">' + esc( t( 'console.platform' ) ) + '</div>' +
							'<div class="account__meta">' +
								esc( t( 'console.levels.' + Console.level ) ) + '</div>' +
						'</div>' +
						'<button class="icon-btn icon-btn--sm" id="c-signout" aria-label="' +
							esc( t( 'console.signOut' ) ) + '">' +
							icon( 'logout', { size: 16 } ) + '</button>' +
					'</div></div>' +
				'</aside>' +
				'<main class="main" id="c-main"></main>' +
			'</div>';

		var nav = document.getElementById( 'c-nav' );

		NAV.forEach( function ( entry ) {
			var button = document.createElement( 'button' );

			button.className = 'nav-item';
			button.dataset.view = entry.key;
			button.innerHTML = icon( entry.icon, { size: 17 } ) +
				'<span>' + esc( t( 'console.nav.' + entry.key ) ) + '</span>';
			button.addEventListener( 'click', function () { Console.go( entry.key ); } );
			nav.appendChild( button );
		} );

		document.getElementById( 'c-signout' )
			.addEventListener( 'click', function () { Console.signOut(); } );

		Console.bindLanguage( 'c-lang' );
	};

	Console.go = function ( view ) {
		Console.view = view;

		Array.prototype.forEach.call( document.querySelectorAll( '.nav-item' ), function ( button ) {
			button.classList.toggle( 'is-active', button.dataset.view === view );
		} );

		switch ( view ) {
			case 'tenants': return Console.tenants();
			case 'sites': return Console.sites();
			case 'plans': return Console.plans();
			case 'invoices': return Console.invoices();
			case 'audit': return Console.audit();
			default: return Console.overview();
		}
	};

	/**
	 * What the platform is owed, across every organiser.
	 *
	 * The one screen on this console that is about the platform's own money rather than about what
	 * an organiser is doing with theirs. Most accounts on most deployments pay by transfer, so the
	 * common action here is somebody with a bank statement open marking an invoice paid — which is
	 * why that button is the plain one and "retry the card" is not.
	 *
	 * Accounts whose retries have run out are named at the top rather than acted on. Cutting a
	 * venue off is a decision with a box office and a full house on the other end of it, and a
	 * scheduled command is not the thing that should make it.
	 */
	Console.invoices = function () {
		Console.request( 'GET', '/admin/invoices' ).then( function ( body ) {
			var operator = 'operator' === Console.level;
			var rows = ( body.data || [] ).map( function ( row ) {
				return '<tr' + ( 'void' === row.status ? ' class="is-muted"' : '' ) + '>' +
					'<td class="table__primary"><code>' + esc( row.number ) + '</code>' +
						'<span class="muted on-own-line">' +
							esc( ( row.tenant || {} ).name || '—' ) + '</span></td>' +
					'<td class="nowrap muted tnum">' +
						esc( t( 'console.invoices.range', { from: row.from, to: row.to } ) ) + '</td>' +
					'<td class="tnum">' + esc( money( row.total, row.currency ) ) + '</td>' +
					'<td>' + badge( row.status ) +
						( row.last_error
							? '<span class="muted on-own-line">' + esc( row.last_error ) + '</span>'
							: '' ) +
						( row.void_reason
							? '<span class="muted on-own-line">' + esc( row.void_reason ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( row.reference || '—' ) + '</td>' +
					'<td class="table__actions">' + ( operator && 'paid' !== row.status && 'void' !== row.status
						? '<button class="btn btn--sm" data-invoice-paid="' + esc( row.id ) + '">' +
								esc( t( 'console.invoices.markPaid' ) ) + '</button>' +
							' <button class="btn btn--sm" data-invoice-retry="' + esc( row.id ) + '">' +
								esc( t( 'console.invoices.retry' ) ) + '</button>' +
							' <button class="btn btn--sm btn--danger" data-invoice-void="' + esc( row.id ) + '">' +
								esc( t( 'console.invoices.void' ) ) + '</button>'
						: '' ) + '</td>' +
				'</tr>';
			} ).join( '' );

			Console.page(
				t( 'console.invoices.heading' ),
				t( 'console.invoices.outstanding', {
					amount: money( body.outstanding || 0, body.currency ),
				} ),
				( ( body.past_due || [] ).length
					? '<p class="issue issue--error">' + icon( 'alert', { size: 16 } ) + '<span>' +
						esc( t( 'console.invoices.pastDue', {
							accounts: body.past_due.map( function ( account ) {
								return account.name;
							} ).join( ', ' ),
						} ) ) + '</span></p>'
					: '' ) +
				( rows
					? Console.table( [
						t( 'console.invoices.number' ),
						t( 'console.invoices.period' ),
						t( 'console.invoices.amount' ),
						t( 'console.tenants.status' ),
						t( 'console.invoices.reference' ),
						'',
					], rows )
					: '<p class="muted">' + esc( t( 'console.invoices.none' ) ) + '</p>' )
			);

			each( '[data-invoice-paid]', function ( button ) {
				button.addEventListener( 'click', function () {
					var reference = global.prompt( t( 'console.invoices.askReference' ) );

					if ( null === reference ) {
						return;
					}

					Console.request( 'POST', '/admin/invoices/' + button.dataset.invoicePaid + '/paid', {
						method: 'transfer',
						reference: reference || null,
					} ).then( function () { Console.invoices(); } ).catch( fail );
				} );
			} );

			each( '[data-invoice-retry]', function ( button ) {
				button.addEventListener( 'click', function () {
					Console.request( 'POST', '/admin/invoices/' + button.dataset.invoiceRetry + '/retry' )
						.then( function () { Console.invoices(); } ).catch( fail );
				} );
			} );

			each( '[data-invoice-void]', function ( button ) {
				button.addEventListener( 'click', function () {
					// Required: an invoice number that was quoted and then vanished is a question
					// somebody answers from memory months later.
					var reason = global.prompt( t( 'console.invoices.whyVoid' ) );

					if ( ! reason ) {
						return;
					}

					Console.request( 'POST', '/admin/invoices/' + button.dataset.invoiceVoid + '/void', {
						reason: reason,
					} ).then( function () { Console.invoices(); } ).catch( fail );
				} );
			} );
		} ).catch( fail );
	};

	Console.page = function ( title, description, body, actions ) {
		document.getElementById( 'c-main' ).innerHTML =
			'<header class="page-head"><div class="page-head__text">' +
				'<h1>' + esc( title ) + '</h1>' +
				( description ? '<p class="page-head__desc">' + esc( description ) + '</p>' : '' ) +
			'</div>' + ( actions ? '<div class="page-head__actions">' + actions + '</div>' : '' ) +
			'</header><div class="page-body">' + body + '</div>';
	};

	Console.table = function ( headings, rows ) {
		return '<div class="table-wrap"><table class="table"><thead><tr>' +
			headings.map( function ( heading ) {
				return '<th>' + esc( heading ) + '</th>';
			} ).join( '' ) + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
	};

	/* ---------------------------------------------------------------------------- screens */

	Console.overview = function () {
		var data = Console.data.overview;

		var stats = [
			[ 'tenants', data.tenants.total, t( 'console.overview.tenantsMeta', {
				active: number( data.tenants.active ),
				suspended: number( data.tenants.suspended ),
			} ) ],
			[ 'newThisMonth', data.tenants.new_this_month, '' ],
			[ 'sitesLive', data.sites.live, t( 'console.overview.sitesMeta', {
				count: number( data.sites.total ),
			} ) ],
			[ 'domains', data.sites.domains_verified, '' ],
			[ 'events', data.selling.events, '' ],
			[ 'tickets', data.selling.tickets_issued, t( 'console.overview.ticketsMeta', {
				count: number( data.selling.seats_sold_this_month ),
			} ) ],
		].map( function ( entry ) {
			return '<div class="stat stat--block">' +
				'<span class="stat__value tnum">' + esc( number( entry[ 1 ] ) ) + '</span>' +
				'<span class="stat__label">' + esc( t( 'console.overview.' + entry[ 0 ] ) ) + '</span>' +
				( entry[ 2 ] ? '<span class="muted">' + esc( entry[ 2 ] ) + '</span>' : '' ) +
			'</div>';
		} ).join( '' );

		var takings = ( data.takings_this_month || [] ).length
			? Console.table( [
				t( 'console.overview.currency' ),
				t( 'console.overview.orders' ),
				t( 'console.overview.takenThisMonth' ),
			], data.takings_this_month.map( function ( row ) {
				return '<tr><td class="table__primary">' + esc( row.currency ) + '</td>' +
					'<td class="tnum">' + esc( number( row.orders ) ) + '</td>' +
					'<td class="tnum">' + esc( money( row.total, row.currency ) ) + '</td></tr>';
			} ).join( '' ) )
			: '<p class="muted">' + esc( t( 'console.overview.nothingSold' ) ) + '</p>';

		Console.page( t( 'console.nav.overview' ), t( 'console.overview.description' ),
			'<div class="stat-grid">' + stats + '</div>' +
			'<h3 class="subhead">' + esc( t( 'console.overview.takings' ) ) + '</h3>' + takings );
	};

	Console.tenants = function () {
		Console.request( 'GET', '/admin/tenants' ).then( function ( body ) {
			var rows = body.data.map( function ( tenant ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( tenant.name ) +
						'<span class="muted on-own-line">' + esc( tenant.slug ) + '</span></td>' +
					'<td>' + badge( tenant.status ) + '</td>' +
					'<td>' + esc( tenant.plan_name || t( 'console.none' ) ) + '</td>' +
					'<td class="tnum">' + esc( number( tenant.people ) ) + '</td>' +
					'<td class="tnum">' + esc( number( tenant.sites ) ) + '</td>' +
					'<td class="muted nowrap">' + esc( date( tenant.created_at ) ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-tenant="' + esc( tenant.id ) + '">' +
						esc( t( 'console.tenants.open' ) ) + '</button>' +
					'</td></tr>';
			} ).join( '' );

			Console.page( t( 'console.nav.tenants' ), t( 'console.tenants.description' ),
				Console.table( [
					t( 'console.tenants.name' ),
					t( 'console.tenants.status' ),
					t( 'console.tenants.plan' ),
					t( 'console.tenants.people' ),
					t( 'console.tenants.sites' ),
					t( 'console.tenants.since' ),
					'',
				], rows ) );

			each( '[data-tenant]', function ( button ) {
				button.addEventListener( 'click', function () { Console.tenant( button.dataset.tenant ); } );
			} );
		} ).catch( fail );
	};

	/**
	 * Paying an organiser, and the record of having paid them.
	 *
	 * On the organiser's own screen rather than a list of its own: a payout is about one account,
	 * and the question that leads to it — "have we settled these people" — is asked while looking
	 * at them.
	 *
	 * The form asks for a window and then shows what it would pay *before* anything is written,
	 * because the alternative is an operator clicking a button and finding out what it meant
	 * afterwards. Support sees the list and not the form; that is enforced at the server too,
	 * since a hidden button does not stop a request.
	 */
	Console.payouts = function ( id ) {
		var host = document.getElementById( 'c-payouts' );

		if ( ! host ) {
			return;
		}

		Console.request( 'GET', '/admin/tenants/' + id + '/payouts' ).then( function ( body ) {
			var operator = 'operator' === Console.level;
			var rows = ( body.data || [] ).map( function ( row ) {
				return '<tr' + ( 'void' === row.status ? ' class="is-muted"' : '' ) + '>' +
					'<td class="table__primary nowrap">' +
						esc( t( 'console.payouts.range', { from: row.from, to: row.to } ) ) +
						'<span class="muted on-own-line">' + esc( row.currency ) + '</span></td>' +
					'<td class="tnum">' + esc( money( row.charged, row.currency ) ) + '</td>' +
					'<td class="tnum">' + esc( money( row.commission, row.currency ) ) + '</td>' +
					'<td class="tnum">' + esc( money( row.payable, row.currency ) ) + '</td>' +
					'<td>' + badge( row.status ) +
						( row.void_reason
							? '<span class="muted on-own-line">' + esc( row.void_reason ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( row.reference || '—' ) + '</td>' +
					'<td class="table__actions">' + ( operator && 'void' !== row.status
						? ( 'paid' === row.status
							? ''
							: '<button class="btn btn--sm" data-paid="' + esc( row.id ) + '">' +
								esc( t( 'console.payouts.markPaid' ) ) + '</button>' ) +
							' <button class="btn btn--sm btn--danger" data-void="' + esc( row.id ) + '">' +
								esc( t( 'console.payouts.void' ) ) + '</button>'
						: '' ) + '</td>' +
				'</tr>';
			} ).join( '' );

			host.innerHTML =
				( rows
					? Console.table( [
						t( 'console.payouts.period' ),
						t( 'console.payouts.charged' ),
						t( 'console.payouts.commission' ),
						t( 'console.payouts.payable' ),
						t( 'console.tenants.status' ),
						t( 'console.payouts.reference' ),
						'',
					], rows )
					: '<p class="muted">' + esc( t( 'console.payouts.none' ) ) + '</p>' ) +
				( operator
					? '<div class="filters spaced">' +
						'<input class="input" type="date" id="c-pay-from" value="' +
							esc( body.next_from || '' ) + '" aria-label="' +
							esc( t( 'console.payouts.from' ) ) + '">' +
						'<input class="input" type="date" id="c-pay-to" aria-label="' +
							esc( t( 'console.payouts.to' ) ) + '">' +
						'<button class="btn" id="c-pay-preview">' +
							esc( t( 'console.payouts.preview' ) ) + '</button>' +
					'</div>' +
					'<div id="c-pay-preview-out"></div>'
					: '' );

			each( '[data-paid]', function ( button ) {
				button.addEventListener( 'click', function () {
					var reference = global.prompt( t( 'console.payouts.askReference' ) );

					if ( null === reference ) {
						return;
					}

					Console.request( 'POST', '/admin/payouts/' + button.dataset.paid + '/paid', {
						reference: reference || null,
					} ).then( function () { Console.payouts( id ); } ).catch( fail );
				} );
			} );

			each( '[data-void]', function ( button ) {
				button.addEventListener( 'click', function () {
					// Required, not optional: a period reopened with no explanation is a question
					// somebody answers from memory months later.
					var reason = global.prompt( t( 'console.payouts.whyVoid' ) );

					if ( ! reason ) {
						return;
					}

					Console.request( 'POST', '/admin/payouts/' + button.dataset.void + '/void', {
						reason: reason,
					} ).then( function () { Console.payouts( id ); } ).catch( fail );
				} );
			} );

			bind( 'c-pay-preview', function () { Console.previewPayout( id ); } );
		} ).catch( function () {
			host.innerHTML = '<p class="muted">' + esc( t( 'console.payouts.none' ) ) + '</p>';
		} );
	};

	/** What settling this window would pay — shown before it is written, never after. */
	Console.previewPayout = function ( id ) {
		var from = value( 'c-pay-from' );
		var to = value( 'c-pay-to' );
		var out = document.getElementById( 'c-pay-preview-out' );

		if ( ! from || ! to || ! out ) {
			return;
		}

		Console.request( 'GET', '/admin/tenants/' + id + '/payouts/preview?from=' +
			encodeURIComponent( from ) + '&to=' + encodeURIComponent( to )
		).then( function ( body ) {
			var clashes = body.clashes || [];
			var currencies = body.currencies || [];

			out.innerHTML =
				( clashes.length
					? '<p class="issue issue--error">' + esc( t( 'console.payouts.clash', {
						periods: clashes.map( function ( clash ) {
							return t( 'console.payouts.range', { from: clash.from, to: clash.to } );
						} ).join( ', ' ),
					} ) ) + '</p>'
					: '' ) +
				( currencies.length
					? Console.table( [
						t( 'console.payouts.currency' ),
						t( 'console.payouts.charged' ),
						t( 'console.payouts.refunded' ),
						t( 'console.payouts.commission' ),
						t( 'console.payouts.payable' ),
					], currencies.map( function ( row ) {
						return '<tr><td class="table__primary">' + esc( row.currency ) + '</td>' +
							'<td class="tnum">' + esc( money( row.charged, row.currency ) ) + '</td>' +
							'<td class="tnum">' + esc( money( row.refunded, row.currency ) ) + '</td>' +
							'<td class="tnum">' + esc( money( row.commission, row.currency ) ) + '</td>' +
							'<td class="tnum">' + esc( money( row.payable, row.currency ) ) + '</td></tr>';
					} ).join( '' ) ) +
					( clashes.length
						? ''
						: '<button class="btn btn--primary spaced" id="c-pay-settle">' +
							esc( t( 'console.payouts.settle' ) ) + '</button>' )
					: '<p class="muted">' + esc( t( 'console.payouts.nothing' ) ) + '</p>' );

			bind( 'c-pay-settle', function () {
				var reference = global.prompt( t( 'console.payouts.askReference' ) );

				if ( null === reference ) {
					return;
				}

				Console.request( 'POST', '/admin/tenants/' + id + '/payouts', {
					from: from,
					to: to,
					reference: reference || null,
				} ).then( function () { Console.payouts( id ); } ).catch( fail );
			} );
		} ).catch( fail );
	};

	Console.tenant = function ( id ) {
		Console.request( 'GET', '/admin/tenants/' + id ).then( function ( tenant ) {
			var people = tenant.members.map( function ( person ) {
				return '<tr><td class="table__primary">' + esc( person.name || t( 'console.none' ) ) + '</td>' +
					'<td>' + esc( person.email || '' ) + '</td>' +
					'<td>' + esc( role( person.role ) ) + '</td>' +
					'<td>' + ( person.suspended ? badge( 'suspended' ) : badge( 'active' ) ) + '</td></tr>';
			} ).join( '' );

			var sites = tenant.websites.map( function ( site ) {
				return '<tr><td class="table__primary">' + esc( site.name ) + '</td>' +
					'<td>' + badge( site.status ) + '</td>' +
					'<td>' + site.domains.map( function ( domain ) {
						return esc( domain.hostname ) + ( domain.verified
							? ''
							: ' <span class="muted">' + esc( t( 'console.sites.unverified' ) ) + '</span>' );
					} ).join( '<br>' ) + '</td></tr>';
			} ).join( '' );

			var operator = 'operator' === Console.level;

			Console.page( tenant.name,
				t( 'console.tenants.summary', {
					slug: tenant.slug,
					plan: tenant.plan_name || t( 'console.tenants.noPlan' ),
					events: number( tenant.events ),
					tickets: number( tenant.tickets ),
				} ),
				'<h3 class="subhead">' + esc( t( 'console.tenants.peopleHeading' ) ) + '</h3>' +
					Console.table( [
						t( 'console.tenants.name' ),
						t( 'console.tenants.email' ),
						t( 'console.tenants.role' ),
						'',
					], people ) +
				'<h3 class="subhead">' + esc( t( 'console.tenants.sitesHeading' ) ) + '</h3>' +
					Console.table( [
						t( 'console.tenants.siteName' ),
						t( 'console.tenants.status' ),
						t( 'console.tenants.addresses' ),
					], sites ) +
				'<h3 class="subhead">' + esc( t( 'console.payouts.heading' ) ) + '</h3>' +
					'<div id="c-payouts"><p class="muted">' + esc( t( 'console.loading' ) ) + '</p></div>',
				'<button class="btn" id="c-back">' + esc( t( 'console.tenants.back' ) ) + '</button>' +
				( operator
					? '<button class="btn" id="c-impersonate">' +
							esc( t( 'console.tenants.impersonate' ) ) + '</button>' +
						( 'active' === tenant.status
							? '<button class="btn btn--danger" id="c-suspend">' +
								esc( t( 'console.tenants.suspend' ) ) + '</button>'
							: '<button class="btn btn--primary" id="c-reinstate">' +
								esc( t( 'console.tenants.reinstate' ) ) + '</button>' )
					: '' ) );

			Console.payouts( id );

			bind( 'c-back', function () { Console.go( 'tenants' ); } );

			bind( 'c-suspend', function () {
				var reason = global.prompt( t( 'console.tenants.whySuspend' ) );

				if ( null === reason ) {
					return;
				}

				Console.request( 'PATCH', '/admin/tenants/' + id, {
					status: 'suspended',
					reason: reason,
				} ).then( function () { Console.tenant( id ); } ).catch( fail );
			} );

			bind( 'c-reinstate', function () {
				Console.request( 'PATCH', '/admin/tenants/' + id, { status: 'active' } )
					.then( function () { Console.tenant( id ); } ).catch( fail );
			} );

			bind( 'c-impersonate', function () {
				Console.request( 'POST', '/admin/tenants/' + id + '/impersonate' ).then( function ( body ) {
					// Handed over rather than used here: the panel is a different application, and
					// this token expires by itself in an hour whatever happens next.
					global.sessionStorage.setItem( 'seatmap_token', body.token );
					global.sessionStorage.setItem( 'seatmap_profile', JSON.stringify( {
						email: body.as.email,
						tenant: body.tenant.name,
						role: body.as.role,
						email_verified: true,
					} ) );
					global.open( '/', '_blank' );
				} ).catch( fail );
			} );
		} ).catch( fail );
	};

	Console.sites = function () {
		Console.request( 'GET', '/admin/sites' ).then( function ( body ) {
			var rows = body.data.map( function ( site ) {
				return '<tr><td class="table__primary">' + esc( site.name ) + '</td>' +
					'<td>' + esc( site.tenant || '' ) + '</td>' +
					'<td>' + badge( site.status ) + '</td>' +
					'<td>' + esc( site.theme ) + '</td>' +
					'<td>' + ( site.domains.length
						? site.domains.map( function ( domain ) {
							return esc( domain.hostname ) + ( domain.verified
								? ''
								: ' <span class="muted">' + esc( t( 'console.sites.unverified' ) ) + '</span>' );
						} ).join( '<br>' )
						: '<span class="muted">' + esc( t( 'console.sites.noAddress' ) ) + '</span>' ) +
						'</td></tr>';
			} ).join( '' );

			Console.page( t( 'console.nav.sites' ), t( 'console.sites.description' ),
				Console.table( [
					t( 'console.sites.site' ),
					t( 'console.sites.tenant' ),
					t( 'console.sites.status' ),
					t( 'console.sites.theme' ),
					t( 'console.sites.addresses' ),
				], rows ) );
		} ).catch( fail );
	};

	Console.plans = function () {
		Console.request( 'GET', '/admin/plans' ).then( function ( body ) {
			Console.data.limitKeys = body.limit_keys || [];

			var rows = body.data.map( function ( plan ) {
				return '<tr><td class="table__primary">' + esc( plan.name ) +
						'<span class="muted on-own-line">' + esc( plan.key ) + '</span></td>' +
					'<td class="tnum">' + esc( price( plan ) ) + '</td>' +
					'<td class="tnum">' + ( plan.commission_rate
						? esc( t( 'console.plans.commissionAt', {
							rate: number( plan.commission_rate / 100 ),
						} ) )
						: '<span class="muted">—</span>' ) + '</td>' +
					'<td class="muted">' + esc( limits( plan.limits ) ) + '</td>' +
					'<td class="tnum">' + esc( number( plan.subscribers ) ) + '</td>' +
					'<td>' + ( plan.is_active ? badge( 'active' ) : badge( 'draft' ) ) + '</td>' +
					'<td class="table__actions">' +
						( 'operator' === Console.level
							? '<button class="btn btn--sm" data-plan="' + esc( plan.id ) + '">' +
								esc( t( 'console.plans.edit' ) ) + '</button>'
							: '' ) +
					'</td></tr>';
			} ).join( '' );

			Console.page( t( 'console.nav.plans' ), t( 'console.plans.description' ),
				Console.table( [
					t( 'console.plans.plan' ),
					t( 'console.plans.price' ),
					t( 'console.plans.commission' ),
					t( 'console.plans.limits' ),
					t( 'console.plans.subscribers' ),
					t( 'console.plans.status' ),
					'',
				], rows ),
				'operator' === Console.level
					? '<button class="btn btn--primary" id="c-new-plan">' +
						esc( t( 'console.plans.new' ) ) + '</button>'
					: '' );

			bind( 'c-new-plan', function () { Console.editPlan( null ); } );

			each( '[data-plan]', function ( button ) {
				button.addEventListener( 'click', function () {
					Console.editPlan( body.data.filter( function ( plan ) {
						return plan.id === button.dataset.plan;
					} )[ 0 ] );
				} );
			} );
		} ).catch( fail );
	};

	/**
	 * A plan, in a form.
	 *
	 * The key is set once and never again: it is what a subscription points at and what a signup
	 * link names, and renaming it would quietly detach both.
	 */
	Console.editPlan = function ( plan ) {
		var editing = !! plan;

		Console.page( editing ? plan.name : t( 'console.plans.new' ),
			editing ? t( 'console.plans.editHint' ) : '',
			'<form class="stack" id="c-plan-form" style="max-inline-size:34rem">' +
				( editing
					? ''
					: '<div class="field"><label class="field__label" for="p-key">' +
						esc( t( 'console.plans.key' ) ) + '</label>' +
						'<input class="input" id="p-key" required pattern="[a-z0-9-]+" maxlength="40">' +
						'<span class="field__hint">' + esc( t( 'console.plans.keyHint' ) ) +
						'</span></div>' ) +
				'<div class="field"><label class="field__label" for="p-name">' +
					esc( t( 'console.plans.name' ) ) + '</label>' +
					'<input class="input" id="p-name" required maxlength="80" value="' +
					esc( editing ? plan.name : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="p-price">' +
					esc( t( 'console.plans.priceField' ) ) + '</label>' +
					'<input class="input tnum" id="p-price" type="number" min="0" required value="' +
					esc( editing ? plan.price_amount : 0 ) + '">' +
					'<span class="field__hint">' + esc( t( 'console.plans.priceHint' ) ) + '</span></div>' +
				'<div class="field"><label class="field__label" for="p-commission">' +
					esc( t( 'console.plans.commissionField' ) ) + '</label>' +
					'<input class="input tnum" id="p-commission" type="number" min="0" max="5000" ' +
					'value="' + esc( editing ? plan.commission_rate || 0 : 0 ) + '">' +
					'<span class="field__hint">' + esc( t( 'console.plans.commissionHint' ) ) +
					'</span></div>' +
				'<div class="field"><label class="field__label" for="p-currency">' +
					esc( t( 'console.plans.currency' ) ) + '</label>' +
					'<input class="input input--code" id="p-currency" maxlength="3" required value="' +
					esc( editing ? plan.currency : 'EUR' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="p-interval">' +
					esc( t( 'console.plans.billed' ) ) + '</label>' +
					'<select class="select" id="p-interval">' +
						'<option value="month"' + ( editing && 'month' === plan.interval ? ' selected' : '' ) +
							'>' + esc( t( 'console.plans.monthly' ) ) + '</option>' +
						'<option value="year"' + ( editing && 'year' === plan.interval ? ' selected' : '' ) +
							'>' + esc( t( 'console.plans.yearly' ) ) + '</option>' +
					'</select></div>' +
				( Console.data.limitKeys || [] ).map( function ( key ) {
					var value = editing && plan.limits && undefined !== plan.limits[ key ]
						? plan.limits[ key ]
						: '';

					return '<div class="field"><label class="field__label" for="p-' + key + '">' +
						esc( t( 'console.plans.limitField', { limit: limitName( key ) } ) ) + '</label>' +
						'<input class="input tnum" id="p-' + key + '" type="number" min="0" value="' +
						esc( null === value ? '' : value ) + '">' +
						'<span class="field__hint">' + esc( t( 'console.plans.limitHint' ) ) +
						'</span></div>';
				} ).join( '' ) +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="p-active"' +
					( ! editing || plan.is_active ? ' checked' : '' ) +
					'><span>' + esc( t( 'console.plans.onSignup' ) ) + '</span></label>' +
			'</form>',
			'<button class="btn" id="c-plans-back">' + esc( t( 'console.plans.back' ) ) + '</button>' +
			'<button class="btn btn--primary" id="c-plan-save">' +
				esc( t( 'console.save' ) ) + '</button>' );

		bind( 'c-plans-back', function () { Console.go( 'plans' ); } );

		bind( 'c-plan-save', function () {
			var payload = {
				name: value( 'p-name' ),
				price_amount: Number( value( 'p-price' ) ),
				commission_rate: Number( value( 'p-commission' ) ),
				currency: value( 'p-currency' ).toUpperCase(),
				interval: value( 'p-interval' ),
				is_active: document.getElementById( 'p-active' ).checked,
				limits: {},
			};

			( Console.data.limitKeys || [] ).forEach( function ( key ) {
				var raw = value( 'p-' + key );

				payload.limits[ key ] = '' === raw ? null : Number( raw );
			} );

			var request = editing
				? Console.request( 'PATCH', '/admin/plans/' + plan.id, payload )
				: Console.request( 'POST', '/admin/plans',
					Object.assign( { key: value( 'p-key' ) }, payload ) );

			request.then( function () { Console.go( 'plans' ); } ).catch( fail );
		} );
	};

	Console.audit = function () {
		Console.request( 'GET', '/admin/audit' ).then( function ( body ) {
			var rows = body.data.map( function ( entry ) {
				return '<tr><td class="muted nowrap">' + esc( date( entry.created_at ) ) + '</td>' +
					'<td class="table__primary">' + esc( entry.action ) + '</td>' +
					'<td>' + esc( entry.operator || t( 'console.none' ) ) + '</td>' +
					'<td>' + esc( entry.tenant || t( 'console.none' ) ) + '</td>' +
					'<td class="muted">' + esc( JSON.stringify( entry.context || {} ) ) + '</td>' +
					'<td class="muted">' + esc( entry.ip || '' ) + '</td></tr>';
			} ).join( '' );

			// The action names are not translated: they are stable identifiers written into a log
			// read back years later, and a log whose entries change wording is a log of nothing.
			Console.page( t( 'console.nav.audit' ), t( 'console.audit.description' ),
				Console.table( [
					t( 'console.audit.when' ),
					t( 'console.audit.action' ),
					t( 'console.audit.operator' ),
					t( 'console.audit.tenant' ),
					t( 'console.audit.detail' ),
					t( 'console.audit.from' ),
				], rows ) );
		} ).catch( fail );
	};

	/* --------------------------------------------------------------------------- helpers */

	function fail( error ) {
		var main = document.getElementById( 'c-main' );

		if ( main ) {
			main.innerHTML = '<div class="page-body"><p class="issue issue--error">' +
				icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span></p></div>';
		}
	}

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	/** Short, because it appears once per label. */
	function t( key, replace ) {
		return Console.t( key, replace );
	}

	function badge( status ) {
		var tone = { active: 'ok', live: 'ok', suspended: 'danger', cancelled: 'danger', draft: 'neutral' };
		var key = 'console.status.' + status;

		return '<span class="badge badge--' + ( tone[ status ] || 'neutral' ) + '">' +
			esc( Console.has( key ) ? t( key ) : titleCase( status ) ) + '</span>';
	}

	/**
	 * A role the platform defines has a name; one an organiser invented for itself does not, and
	 * inventing a translation for it would be worse than showing what they called it.
	 */
	function role( key ) {
		return Console.has( 'team.roles.' + key ) ? t( 'team.roles.' + key ) : titleCase( key );
	}

	function limitName( key ) {
		return Console.has( 'console.plans.limitNames.' + key )
			? t( 'console.plans.limitNames.' + key )
			: titleCase( key );
	}

	function price( plan ) {
		if ( ! plan.price_amount ) {
			return t( 'console.plans.free' );
		}

		return t( 'year' === plan.interval ? 'console.plans.perYear' : 'console.plans.perMonth', {
			price: money( plan.price_amount, plan.currency ),
		} );
	}

	function limits( set ) {
		var keys = Object.keys( set || {} );

		if ( ! keys.length ) {
			return t( 'console.plans.noLimits' );
		}

		// The separator is a translated string: a middle dot between Persian digits reads as
		// another digit, and this line is nothing but digits.
		return keys.map( function ( key ) {
			return limitName( key ) + ': ' +
				( null === set[ key ] ? t( 'console.plans.unlimited' ) : number( set[ key ] ) );
		} ).join( t( 'console.plans.separator' ) );
	}

	/**
	 * Money in the currency charged and the shape this operator reads.
	 *
	 * The decimal places come from the currency, not from a guess: assuming two turns 500,000 rials
	 * into 5,000, and an operator reading the month's takings is exactly who must not see that.
	 */
	function money( minorUnits, currency ) {
		try {
			var places = new Intl.NumberFormat( 'en', { style: 'currency', currency: currency } )
				.resolvedOptions().minimumFractionDigits;

			return new Intl.NumberFormat( Console.i18n.icu, {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: places,
				maximumFractionDigits: places,
			} ).format( minorUnits / Math.pow( 10, places ) );
		} catch ( error ) {
			return minorUnits + ' ' + currency;
		}
	}

	function number( value ) {
		try {
			return new Intl.NumberFormat( Console.i18n.icu ).format( value );
		} catch ( error ) {
			return String( value );
		}
	}

	/** The reader's language *and* calendar — the ICU locale carries both. */
	function date( value ) {
		if ( ! value ) {
			return '';
		}

		try {
			return new Intl.DateTimeFormat( Console.i18n.icu, {
				day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
			} ).format( new Date( value ) );
		} catch ( error ) {
			return String( value );
		}
	}

	function titleCase( value ) {
		return String( value || '' ).replace( /_/g, ' ' ).replace( /^./, function ( first ) {
			return first.toUpperCase();
		} );
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', Console.init );
	} else {
		Console.init();
	}

	global.SeatmapConsole = Console;
}( window ) );
