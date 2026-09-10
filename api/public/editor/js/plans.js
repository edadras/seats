/**
 * What is still owed, in the order somebody rings about it.
 *
 * A school pays a deposit in November and the balance in March, and the thing that loses a night's
 * seats is nobody remembering to chase the March. This screen is that list: the late ones first,
 * with the whole booking's balance rather than one line of it, because a party three payments
 * behind is a different telephone call from one a week late.
 */
( function ( global ) {
	'use strict';

	var Plans = {};

	Plans.filter = 'all';

	Plans.render = function ( App ) {
		Plans.App = App;
		App.loading( App.t( 'panel.nav.plans' ) );
		Plans.load();
	};

	Plans.load = function () {
		var App = Plans.App;

		App.request( 'GET', '/instalments?state=' + encodeURIComponent( Plans.filter ) )
			.then( function ( response ) {
				Plans.rows = response.data || [];
				Plans.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Plans.paint = function () {
		var App = Plans.App;

		App.page( {
			title: App.t( 'panel.nav.plans' ),
			description: App.t( 'panel.plans.subtitle' ),
			actions:
				'<select class="select select--sm" id="plan-state" aria-label="' +
					esc( App.t( 'panel.plans.show' ) ) + '">' +
					[ 'all', 'overdue', 'due' ].map( function ( state ) {
						return '<option value="' + state + '"' +
							( state === Plans.filter ? ' selected' : '' ) + '>' +
							esc( App.t( 'panel.plans.filters.' + state ) ) + '</option>';
					} ).join( '' ) +
				'</select>',
			body: Plans.rows.length
				? App.table(
					[
						App.t( 'panel.plans.due' ),
						App.t( 'panel.plans.who' ),
						App.t( 'panel.plans.event' ),
						{ label: App.t( 'panel.plans.thisPayment' ), numeric: true },
						{ label: App.t( 'panel.plans.balance' ), numeric: true },
						'',
					],
					Plans.rows.map( function ( row ) {
						return '<tr>' +
							'<td class="nowrap tnum">' + esc( App.date( row.due_on, { dateStyle: 'medium' } ) ) +
								( 'overdue' === row.state
									? ' <span class="badge badge--danger">' +
										esc( App.t( 'panel.plans.states.overdue' ) ) + '</span>'
									: '' ) + '</td>' +
							'<td class="table__primary">' +
								esc( row.group_name || row.buyer || '—' ) +
								( row.group_name && row.buyer
									? '<span class="muted on-own-line">' + esc( row.buyer ) + '</span>'
									: '' ) +
								( row.email
									? '<span class="muted on-own-line">' + esc( row.email ) + '</span>'
									: '' ) + '</td>' +
							'<td>' + esc( row.event || '—' ) + '</td>' +
							'<td class="tnum">' + esc( App.money( row.amount, row.currency ) ) + '</td>' +
							'<td class="tnum">' + esc( App.money( row.balance, row.currency ) ) + '</td>' +
							'<td><button class="btn btn--sm" data-booking="' + esc( row.order_id ) + '">' +
								esc( App.t( 'panel.plans.openBooking' ) ) + '</button></td>' +
						'</tr>';
					} ).join( '' )
				)
				: '<p class="muted">' + esc( App.t( 'panel.plans.nothing' ) ) + '</p>',
		} );

		document.getElementById( 'plan-state' ).addEventListener( 'change', function () {
			Plans.filter = this.value;
			Plans.load();
		} );

		// Straight into the booking, because what somebody does after reading this list is take a
		// payment — and that lives on the order.
		each( '[data-booking]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.current = 'orders';
				App.renderNav();
				// The orders screen holds its own reference to the app, and this may be the first
				// time anybody has been on it this session.
				global.SeatmapOrders.App = App;
				global.SeatmapOrders.open( button.dataset.booking );
			} );
		} );
	};

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapPlans = Plans;
}( typeof window !== 'undefined' ? window : globalThis ) );
