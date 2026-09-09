/**
 * The platform's own console: every organiser, every site, the tariffs.
 *
 * A separate application from the panel, deliberately. It shares the stylesheet and the icon set —
 * there is no reason for an operator's screen to look like a different product — and shares no
 * code, no route and no token handling, because the day those merge is the day a bug in one is a
 * bug in the other and the blast radius is everybody.
 *
 * Its strings are English only, and that is a decision rather than an oversight: this screen is
 * read by the handful of people who run the platform, not by its customers, and pretending
 * otherwise would mean six translations nobody reads.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Console = {
		root: null,
		api: '',
		token: null,
		level: 'support',
		view: 'overview',
		data: {},
	};

	var NAV = [
		{ key: 'overview', label: 'Overview', icon: 'chart' },
		{ key: 'tenants', label: 'Organisers', icon: 'users' },
		{ key: 'sites', label: 'Websites', icon: 'globe' },
		{ key: 'plans', label: 'Plans', icon: 'tag' },
		{ key: 'audit', label: 'Operator log', icon: 'history' },
	];

	Console.init = function () {
		Console.root = document.getElementById( 'console' );
		Console.api = Console.root.dataset.api;
		Console.token = global.sessionStorage.getItem( 'seatmap_console_token' );

		Console.token ? Console.load() : Console.showLogin();
	};

	/* --------------------------------------------------------------------------- transport */

	Console.request = function ( method, path, body ) {
		var headers = { Accept: 'application/json' };

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

					var error = new Error( ( data.error && data.error.message ) || 'Request failed' );
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
					'</span>Console</div>' +
				'<h1 class="auth__title">Platform console</h1>' +
				'<p class="auth__sub">For the people who run this, not the people who use it.</p>' +
				'<div class="field"><label class="field__label" for="c-email">Email</label>' +
					'<input class="input" id="c-email" type="email" required autocomplete="username"></div>' +
				'<div class="field"><label class="field__label" for="c-password">Password</label>' +
					'<input class="input" id="c-password" type="password" required ' +
					'autocomplete="current-password"></div>' +
				'<div class="issue issue--error" id="c-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">Sign in</button>' +
			'</form></div>';

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
				global.sessionStorage.setItem( 'seatmap_console_token', response.token );
				Console.load();
			} ).catch( function ( error ) {
				problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
				problem.hidden = false;
			} );
		} );
	};

	Console.signOut = function () {
		global.sessionStorage.removeItem( 'seatmap_console_token' );
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
						'<span class="grow">Console</span>' +
					'</div>' +
					'<nav class="sidebar__nav" id="c-nav" aria-label="Sections"></nav>' +
					'<div class="sidebar__footer"><div class="account">' +
						'<div class="account__body">' +
							'<div class="account__name">Platform</div>' +
							'<div class="account__meta">' + esc( titleCase( Console.level ) ) + '</div>' +
						'</div>' +
						'<button class="icon-btn icon-btn--sm" id="c-signout" aria-label="Sign out">' +
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
			button.innerHTML = icon( entry.icon, { size: 17 } ) + '<span>' + esc( entry.label ) + '</span>';
			button.addEventListener( 'click', function () { Console.go( entry.key ); } );
			nav.appendChild( button );
		} );

		document.getElementById( 'c-signout' )
			.addEventListener( 'click', function () { Console.signOut(); } );
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
			case 'audit': return Console.audit();
			default: return Console.overview();
		}
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
			[ 'Organisers', data.tenants.total, data.tenants.active + ' active · ' + data.tenants.suspended + ' suspended' ],
			[ 'New this month', data.tenants.new_this_month, '' ],
			[ 'Websites live', data.sites.live, data.sites.total + ' in total' ],
			[ 'Verified domains', data.sites.domains_verified, '' ],
			[ 'Events on sale', data.selling.events, '' ],
			[ 'Tickets issued', data.selling.tickets_issued, data.selling.seats_sold_this_month + ' seats this month' ],
		].map( function ( entry ) {
			return '<div class="stat stat--block">' +
				'<span class="stat__value tnum">' + esc( entry[ 1 ] ) + '</span>' +
				'<span class="stat__label">' + esc( entry[ 0 ] ) + '</span>' +
				( entry[ 2 ] ? '<span class="muted">' + esc( entry[ 2 ] ) + '</span>' : '' ) +
			'</div>';
		} ).join( '' );

		var takings = ( data.takings_this_month || [] ).length
			? Console.table( [ 'Currency', 'Orders', 'Taken this month' ],
				data.takings_this_month.map( function ( row ) {
					return '<tr><td class="table__primary">' + esc( row.currency ) + '</td>' +
						'<td class="tnum">' + esc( row.orders ) + '</td>' +
						'<td class="tnum">' + esc( money( row.total, row.currency ) ) + '</td></tr>';
				} ).join( '' ) )
			: '<p class="muted">Nothing has been sold this month.</p>';

		Console.page( 'Overview', 'The platform, in numbers.',
			'<div class="stat-grid">' + stats + '</div>' +
			'<h3 class="subhead">Takings, by currency</h3>' + takings );
	};

	Console.tenants = function () {
		Console.request( 'GET', '/admin/tenants' ).then( function ( body ) {
			var rows = body.data.map( function ( tenant ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( tenant.name ) +
						'<span class="muted on-own-line">' + esc( tenant.slug ) + '</span></td>' +
					'<td>' + badge( tenant.status ) + '</td>' +
					'<td>' + esc( tenant.plan_name || '—' ) + '</td>' +
					'<td class="tnum">' + esc( tenant.people ) + '</td>' +
					'<td class="tnum">' + esc( tenant.sites ) + '</td>' +
					'<td class="muted nowrap">' + esc( date( tenant.created_at ) ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-tenant="' + esc( tenant.id ) + '">Open</button>' +
					'</td></tr>';
			} ).join( '' );

			Console.page( 'Organisers', 'Every account on the platform.',
				Console.table( [ 'Name', 'Status', 'Plan', 'People', 'Sites', 'Since', '' ], rows ) );

			each( '[data-tenant]', function ( button ) {
				button.addEventListener( 'click', function () { Console.tenant( button.dataset.tenant ); } );
			} );
		} ).catch( fail );
	};

	Console.tenant = function ( id ) {
		Console.request( 'GET', '/admin/tenants/' + id ).then( function ( tenant ) {
			var people = tenant.members.map( function ( person ) {
				return '<tr><td class="table__primary">' + esc( person.name || '—' ) + '</td>' +
					'<td>' + esc( person.email || '' ) + '</td>' +
					'<td>' + esc( titleCase( person.role ) ) + '</td>' +
					'<td>' + ( person.suspended ? badge( 'suspended' ) : badge( 'active' ) ) + '</td></tr>';
			} ).join( '' );

			var sites = tenant.websites.map( function ( site ) {
				return '<tr><td class="table__primary">' + esc( site.name ) + '</td>' +
					'<td>' + badge( site.status ) + '</td>' +
					'<td>' + site.domains.map( function ( domain ) {
						return esc( domain.hostname ) + ( domain.verified ? '' : ' <span class="muted">(unverified)</span>' );
					} ).join( '<br>' ) + '</td></tr>';
			} ).join( '' );

			var operator = 'operator' === Console.level;

			Console.page( tenant.name,
				tenant.slug + ' · ' + ( tenant.plan_name || 'no plan' ) + ' · ' +
					tenant.events + ' events · ' + tenant.tickets + ' tickets issued',
				'<h3 class="subhead">People</h3>' +
					Console.table( [ 'Name', 'Email', 'Role', '' ], people ) +
				'<h3 class="subhead">Websites</h3>' +
					Console.table( [ 'Name', 'Status', 'Addresses' ], sites ),
				'<button class="btn" id="c-back">All organisers</button>' +
				( operator
					? '<button class="btn" id="c-impersonate">Open their panel</button>' +
						( 'active' === tenant.status
							? '<button class="btn btn--danger" id="c-suspend">Suspend</button>'
							: '<button class="btn btn--primary" id="c-reinstate">Reinstate</button>' )
					: '' ) );

			bind( 'c-back', function () { Console.go( 'tenants' ); } );

			bind( 'c-suspend', function () {
				var reason = global.prompt( 'Why is this account being suspended?' );

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
							return esc( domain.hostname ) +
								( domain.verified ? '' : ' <span class="muted">(unverified)</span>' );
						} ).join( '<br>' )
						: '<span class="muted">no address yet</span>' ) + '</td></tr>';
			} ).join( '' );

			Console.page( 'Websites', 'Every site this platform serves.',
				Console.table( [ 'Site', 'Organiser', 'Status', 'Theme', 'Addresses' ], rows ) );
		} ).catch( fail );
	};

	Console.plans = function () {
		Console.request( 'GET', '/admin/plans' ).then( function ( body ) {
			Console.data.limitKeys = body.limit_keys || [];

			var rows = body.data.map( function ( plan ) {
				return '<tr><td class="table__primary">' + esc( plan.name ) +
						'<span class="muted on-own-line">' + esc( plan.key ) + '</span></td>' +
					'<td class="tnum">' + esc( plan.price_amount
						? money( plan.price_amount, plan.currency ) + ' / ' + plan.interval
						: 'Free' ) + '</td>' +
					'<td class="muted">' + esc( limits( plan.limits ) ) + '</td>' +
					'<td class="tnum">' + esc( plan.subscribers ) + '</td>' +
					'<td>' + ( plan.is_active ? badge( 'active' ) : badge( 'draft' ) ) + '</td>' +
					'<td class="table__actions">' +
						( 'operator' === Console.level
							? '<button class="btn btn--sm" data-plan="' + esc( plan.id ) + '">Edit</button>'
							: '' ) +
					'</td></tr>';
			} ).join( '' );

			Console.page( 'Plans', 'What an account costs, and what it may do.',
				Console.table( [ 'Plan', 'Price', 'Limits', 'On it', 'Status', '' ], rows ),
				'operator' === Console.level
					? '<button class="btn btn--primary" id="c-new-plan">New plan</button>'
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

		Console.page( editing ? plan.name : 'New plan',
			editing ? 'Changing a price changes what people pay at their next renewal.' : '',
			'<form class="stack" id="c-plan-form" style="max-inline-size:34rem">' +
				( editing
					? ''
					: '<div class="field"><label class="field__label" for="p-key">Key</label>' +
						'<input class="input" id="p-key" required pattern="[a-z0-9-]+" maxlength="40">' +
						'<span class="field__hint">Lower case and dashes. Cannot be changed later — ' +
						'a subscription points at it.</span></div>' ) +
				'<div class="field"><label class="field__label" for="p-name">Name</label>' +
					'<input class="input" id="p-name" required maxlength="80" value="' +
					esc( editing ? plan.name : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="p-price">Price, in minor units</label>' +
					'<input class="input tnum" id="p-price" type="number" min="0" required value="' +
					esc( editing ? plan.price_amount : 0 ) + '">' +
					'<span class="field__hint">4900 is €49.00. Zero is free.</span></div>' +
				'<div class="field"><label class="field__label" for="p-currency">Currency</label>' +
					'<input class="input input--code" id="p-currency" maxlength="3" required value="' +
					esc( editing ? plan.currency : 'EUR' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="p-interval">Billed</label>' +
					'<select class="select" id="p-interval">' +
						'<option value="month"' + ( editing && 'month' === plan.interval ? ' selected' : '' ) +
							'>Monthly</option>' +
						'<option value="year"' + ( editing && 'year' === plan.interval ? ' selected' : '' ) +
							'>Yearly</option>' +
					'</select></div>' +
				( Console.data.limitKeys || [] ).map( function ( key ) {
					var value = editing && plan.limits && undefined !== plan.limits[ key ]
						? plan.limits[ key ]
						: '';

					return '<div class="field"><label class="field__label" for="p-' + key + '">' +
						esc( titleCase( key ) ) + ' limit</label>' +
						'<input class="input tnum" id="p-' + key + '" type="number" min="0" value="' +
						esc( null === value ? '' : value ) + '">' +
						'<span class="field__hint">Blank means no limit.</span></div>';
				} ).join( '' ) +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="p-active"' +
					( ! editing || plan.is_active ? ' checked' : '' ) +
					'><span>On the signup screen</span></label>' +
			'</form>',
			'<button class="btn" id="c-plans-back">All plans</button>' +
			'<button class="btn btn--primary" id="c-plan-save">Save</button>' );

		bind( 'c-plans-back', function () { Console.go( 'plans' ); } );

		bind( 'c-plan-save', function () {
			var payload = {
				name: value( 'p-name' ),
				price_amount: Number( value( 'p-price' ) ),
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
					'<td>' + esc( entry.operator || '—' ) + '</td>' +
					'<td>' + esc( entry.tenant || '—' ) + '</td>' +
					'<td class="muted">' + esc( JSON.stringify( entry.context || {} ) ) + '</td>' +
					'<td class="muted">' + esc( entry.ip || '' ) + '</td></tr>';
			} ).join( '' );

			Console.page( 'Operator log',
				'What the people who run this platform did inside other people’s accounts.',
				Console.table( [ 'When', 'Action', 'Operator', 'Organiser', 'Detail', 'From' ], rows ) );
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

	function badge( status ) {
		var tone = { active: 'ok', live: 'ok', suspended: 'danger', cancelled: 'danger', draft: 'neutral' };

		return '<span class="badge badge--' + ( tone[ status ] || 'neutral' ) + '">' +
			esc( titleCase( status ) ) + '</span>';
	}

	function limits( set ) {
		var keys = Object.keys( set || {} );

		if ( ! keys.length ) {
			return 'No limits';
		}

		return keys.map( function ( key ) {
			return titleCase( key ) + ': ' + ( null === set[ key ] ? '∞' : set[ key ] );
		} ).join( ' · ' );
	}

	function money( minorUnits, currency ) {
		try {
			var places = new Intl.NumberFormat( 'en', { style: 'currency', currency: currency } )
				.resolvedOptions().minimumFractionDigits;

			return new Intl.NumberFormat( 'en', {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: places,
				maximumFractionDigits: places,
			} ).format( minorUnits / Math.pow( 10, places ) );
		} catch ( error ) {
			return minorUnits + ' ' + currency;
		}
	}

	function date( value ) {
		if ( ! value ) {
			return '';
		}

		return new Date( value ).toLocaleString( 'en-GB', {
			day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
		} );
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
