/**
 * The people who bought, and what each of them bought.
 *
 * Two screens. The list is the question "who are our customers" — searchable, orderable, and
 * honest about the orders it cannot attribute to anybody. The profile is the question a box office
 * is actually asked on the phone: this person says they booked, what have they got?
 *
 * Nothing here is a stored customer record, because there is none: the platform derives both
 * screens from the orders themselves, and a person is addressed by the hash of their address
 * rather than by the address.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Customers = {
		App: null,
		filters: { q: '', event_id: '', sort: 'recent', currency: '' },
		page: 1,
		events: [],
		meta: null,
		timer: null,
	};

	var SORTS = [ 'recent', 'oldest', 'orders', 'spend', 'name' ];

	/* ------------------------------------------------------------------------------ list */

	Customers.render = function ( App ) {
		Customers.App = App;
		App.loading( App.t( 'panel.customers.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.catch( function () { return { data: [] }; } )
			.then( function ( response ) {
				Customers.events = response.data || [];
				Customers.paint();
				Customers.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Customers.paint = function () {
		var App = Customers.App;

		App.page( {
			title: App.t( 'panel.customers.title' ),
			description: esc( App.t( 'panel.customers.description' ) ),
			actions: '<button class="btn" id="customers-export">' +
				icon( 'download', { size: 15 } ) + esc( App.t( 'panel.customers.export' ) ) + '</button>',
			body:
				'<div class="filters">' +
					'<input class="input grow" id="customer-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.customers.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.customers.searchLabel' ) ) + '" ' +
						'value="' + esc( Customers.filters.q ) + '">' +
					'<select class="select" id="customer-event" aria-label="' +
						esc( App.t( 'panel.customers.allEvents' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.customers.allEvents' ) ) + '</option>' +
						Customers.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( event.id === Customers.filters.event_id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="customer-sort" aria-label="' +
						esc( App.t( 'panel.customers.sortLabel' ) ) + '">' +
						SORTS.map( function ( key ) {
							return '<option value="' + key + '"' +
								( key === Customers.filters.sort ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.customers.sort.' + key ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<span id="customer-currency"></span>' +
				'</div>' +
				'<div id="customer-results" class="spaced"></div>',
		} );

		document.getElementById( 'customers-export' )
			.addEventListener( 'click', function () { Customers.download(); } );

		var search = document.getElementById( 'customer-search' );

		search.addEventListener( 'input', function () {
			Customers.filters.q = search.value;
			Customers.page = 1;

			global.clearTimeout( Customers.timer );
			Customers.timer = global.setTimeout( function () { Customers.load(); }, 300 );
		} );

		[ [ 'customer-event', 'event_id' ], [ 'customer-sort', 'sort' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Customers.filters[ pair[ 1 ] ] = this.value;
				Customers.page = 1;
				Customers.load();
			} );
		} );
	};

	Customers.query = function () {
		var parts = [];

		Object.keys( Customers.filters ).forEach( function ( key ) {
			if ( Customers.filters[ key ] ) {
				parts.push( key + '=' + encodeURIComponent( Customers.filters[ key ] ) );
			}
		} );

		return parts.join( '&' );
	};

	Customers.load = function () {
		var App = Customers.App;
		var host = document.getElementById( 'customer-results' );

		if ( ! host ) {
			return;
		}

		var query = Customers.query();

		App.request( 'GET', '/customers?page=' + Customers.page + ( query ? '&' + query : '' ) )
			.then( function ( response ) {
				Customers.meta = response.meta;
				Customers.paintCurrency();
				host.innerHTML = Customers.listMarkup( response );
				Customers.bindList();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * The currency the sortable spend column is counted in.
	 *
	 * Only offered when this account has taken money in more than one, because a chooser with one
	 * item in it is a control that teaches somebody nothing and takes up a line.
	 */
	Customers.paintCurrency = function () {
		var App = Customers.App;
		var host = document.getElementById( 'customer-currency' );
		var currencies = ( Customers.meta && Customers.meta.currencies ) || [];

		if ( ! host ) {
			return;
		}

		if ( currencies.length < 2 ) {
			host.innerHTML = '';

			return;
		}

		host.innerHTML = '<select class="select" id="customer-currency-select" aria-label="' +
			esc( App.t( 'panel.customers.currencyLabel' ) ) + '">' +
			currencies.map( function ( code ) {
				return '<option value="' + esc( code ) + '"' +
					( code === Customers.meta.currency ? ' selected' : '' ) + '>' + esc( code ) +
					'</option>';
			} ).join( '' ) + '</select>';

		document.getElementById( 'customer-currency-select' ).addEventListener( 'change', function () {
			Customers.filters.currency = this.value;
			Customers.load();
		} );
	};

	Customers.listMarkup = function ( response ) {
		var App = Customers.App;
		var meta = response.meta;

		if ( ! response.data.length ) {
			return App.emptyState(
				Customers.filters.q ? 'search' : 'users',
				App.t( Customers.filters.q ? 'panel.customers.noMatchTitle' : 'panel.customers.noneTitle' ),
				esc( App.t( Customers.filters.q ? 'panel.customers.noMatchBody' : 'panel.customers.noneBody' ) )
			);
		}

		var rows = response.data.map( function ( person ) {
			return '<tr>' +
				'<td class="table__primary">' +
					esc( person.name || App.t( 'panel.customers.anonymous' ) ) +
					'<span class="muted on-own-line">' + esc( person.email ) + '</span>' +
				'</td>' +
				'<td class="tnum">' + esc( App.number( person.orders_count ) ) +
					( person.confirmed_count !== person.orders_count
						? '<span class="muted on-own-line">' + esc( App.t( 'panel.customers.paidCount', {
							count: App.number( person.confirmed_count ),
						} ) ) + '</span>'
						: '' ) +
				'</td>' +
				'<td class="tnum">' + esc( App.number( person.seats_count ) ) + '</td>' +
				'<td>' + Customers.spendMarkup( person ) + '</td>' +
				'<td class="muted nowrap tnum">' + esc( App.date( person.last_order_at ) ) + '</td>' +
				'<td class="table__actions">' +
					'<button class="btn btn--sm" data-person="' + esc( person.id ) + '">' +
						esc( App.t( 'panel.customers.open' ) ) + '</button>' +
				'</td>' +
			'</tr>';
		} ).join( '' );

		return App.table(
			[
				App.t( 'panel.customers.name' ),
				{ label: App.t( 'panel.customers.orders' ), numeric: true },
				{ label: App.t( 'panel.customers.seats' ), numeric: true },
				App.t( 'panel.customers.spend' ),
				App.t( 'panel.customers.lastOrder' ),
				'',
			],
			rows
		) +
		( meta.without_email
			? '<p class="hint spaced">' + esc( App.t( 'panel.customers.withoutEmail', {
				count: App.number( meta.without_email ),
			} ) ) + '</p>'
			: '' ) +
		Customers.pagerMarkup( meta );
	};

	/** Every currency this person has paid in, because there is no rate to add them with. */
	Customers.spendMarkup = function ( person ) {
		var App = Customers.App;

		if ( ! person.spend || ! person.spend.length ) {
			return '<span class="muted">—</span>';
		}

		return person.spend.map( function ( entry ) {
			return '<span class="tnum on-own-line">' +
				esc( App.money( entry.amount, entry.currency ) ) + '</span>';
		} ).join( '' );
	};

	Customers.pagerMarkup = function ( meta ) {
		var App = Customers.App;

		if ( meta.last_page < 2 ) {
			return '';
		}

		return '<div class="pager">' +
			'<button class="btn btn--sm" id="customer-prev"' + ( meta.page <= 1 ? ' disabled' : '' ) + '>' +
				esc( App.t( 'panel.common.previous' ) ) + '</button>' +
			'<span class="muted">' + esc( App.t( 'panel.customers.shown', {
				count: App.number( meta.page ), total: App.number( meta.last_page ),
			} ) ) + '</span>' +
			'<button class="btn btn--sm" id="customer-next"' +
				( meta.page >= meta.last_page ? ' disabled' : '' ) + '>' +
				esc( App.t( 'panel.common.next' ) ) + '</button>' +
		'</div>';
	};

	Customers.bindList = function () {
		each( '[data-person]', function ( button ) {
			button.addEventListener( 'click', function () {
				Customers.open( button.dataset.person );
			} );
		} );

		[ [ 'customer-prev', -1 ], [ 'customer-next', 1 ] ].forEach( function ( pair ) {
			var button = document.getElementById( pair[ 0 ] );

			if ( button ) {
				button.addEventListener( 'click', function () {
					Customers.page = Math.max( 1, Customers.page + pair[ 1 ] );
					Customers.load();
				} );
			}
		} );
	};

	/* --------------------------------------------------------------------------- profile */

	Customers.open = function ( id ) {
		var App = Customers.App;

		App.loading( App.t( 'panel.customers.title' ) );

		App.request( 'GET', '/customers/' + encodeURIComponent( id ) )
			.then( function ( person ) { Customers.paintProfile( person ); } )
			.catch( function ( error ) { App.error( error ); } );
	};

	Customers.paintProfile = function ( person ) {
		var App = Customers.App;

		App.page( {
			title: person.name || App.t( 'panel.customers.anonymous' ),
			description: esc( person.email ) +
				( person.phone ? ' · ' + esc( person.phone ) : '' ),
			actions: '<button class="btn" id="customer-back">' + icon( 'back', { size: 15 } ) +
				esc( App.t( 'panel.customers.back' ) ) + '</button>',
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.customers.orders' ), App.number( person.orders_count ),
						App.t( 'panel.customers.paidCount', { count: App.number( person.confirmed_count ) } ) ) +
					tile( App.t( 'panel.customers.seats' ), App.number( person.seats_count ) ) +
					tile( App.t( 'panel.customers.events' ), App.number( person.events_count ) ) +
					tile( App.t( 'panel.customers.spend' ),
						person.spend.length
							? person.spend.map( function ( entry ) {
								return App.money( entry.amount, entry.currency );
							} ).join( ' · ' )
							: '—',
						App.t( 'panel.customers.sinceLine', { date: App.date( person.first_order_at ) } ) ) +
				'</div>' +
				'<h3 class="subhead">' + esc( App.t( 'panel.customers.ordersHeading' ) ) + '</h3>' +
				person.orders.map( function ( order ) {
					return Customers.orderMarkup( order );
				} ).join( '' ),
		} );

		document.getElementById( 'customer-back' ).addEventListener( 'click', function () {
			Customers.render( App );
		} );
	};

	/**
	 * One order, with what was actually in it.
	 *
	 * A seat reads as section, row and seat; a standing place has none of those, so it reads as the
	 * area and how many of them there are. The two are different things and printing one in the
	 * shape of the other is how "Floor 2 places" ends up on a screen.
	 */
	Customers.orderMarkup = function ( order ) {
		var App = Customers.App;
		var tone = { confirmed: 'ok', pending: 'warn', cancelled: 'danger',
			refunded: 'danger', partially_refunded: 'warn' }[ order.status ] || 'neutral';

		var lines = order.lines.map( function ( line ) {
			var seat = [ line.section, line.row, line.seat ].filter( Boolean ).join( ' · ' );

			return '<li>' +
				'<span>' + esc( seat || line.section || App.t( 'panel.customers.standing' ) ) +
					( line.seat ? '' : ' <span class="muted">' +
						esc( App.t( 'panel.customers.quantity', { count: App.number( line.quantity ) } ) ) +
						'</span>' ) +
					( line.used_at
						? ' <span class="badge badge--ok">' + esc( App.t( 'panel.customers.used' ) ) + '</span>'
						: '' ) +
				'</span>' +
				'<span class="tnum">' + esc( App.money( line.amount, line.currency ) ) + '</span>' +
			'</li>';
		} ).join( '' );

		return '<article class="order-card">' +
			'<header class="order-card__head">' +
				'<div>' +
					'<h4>' + esc( ( order.event && order.event.name ) || '—' ) + '</h4>' +
					'<p class="muted">' + esc( App.t( 'panel.customers.placedOn', {
						date: App.date( order.placed_at ),
					} ) ) + ' · <code>' + esc( order.reference ) + '</code></p>' +
				'</div>' +
				'<div class="order-card__figures">' +
					'<span class="badge badge--' + tone + '">' +
						esc( App.t( 'panel.customers.orderStatus.' + order.status ) ) + '</span>' +
					'<span class="order-card__total tnum">' +
						esc( App.money( order.total_amount, order.currency ) ) + '</span>' +
				'</div>' +
			'</header>' +
			( lines
				? '<ul class="order-card__lines">' + lines + '</ul>'
				: '<p class="muted">' + esc( App.t( 'panel.customers.noSeats' ) ) + '</p>' ) +
			( order.checked_in
				? '<p class="hint">' + esc( App.t( 'panel.customers.checkedInLine', {
					count: App.number( order.checked_in ),
					total: App.number( order.lines.length ),
				} ) ) + '</p>'
				: '' ) +
		'</article>';
	};

	/**
	 * The list as a file.
	 *
	 * Fetched with the panel's own credentials and handed to the browser as a blob: an anchor
	 * carries no Authorization header, and this particular file is every buyer's address.
	 */
	Customers.download = function () {
		var App = Customers.App;
		var query = Customers.query();

		App.request( 'GET', '/customers/export' + ( query ? '?' + query : '' ), null, { raw: true } )
			.then( function ( blob ) {
				var url = global.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = 'customers.csv';
				document.body.appendChild( link );
				link.click();
				link.remove();
				global.URL.revokeObjectURL( url );
				App.toast( App.t( 'panel.customers.exported' ) );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* --------------------------------------------------------------------------- helpers */

	/** The overview's tile, without the hover: nothing here is a door into another screen. */
	function tile( label, value, hint ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
			( hint ? '<span class="tile__meta">' + esc( hint ) + '</span>' : '' ) +
		'</div>';
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapCustomers = Customers;
}( typeof window !== 'undefined' ? window : globalThis ) );
