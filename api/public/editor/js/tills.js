/**
 * The till: what is in the drawer, and whether it balances.
 *
 * Every venue asks the same question at eleven o'clock, and the screen is built around answering it
 * in one glance: what was expected, what was counted, and the difference between them. The
 * difference is the finding — a till four euros over is as interesting as one four short — so it is
 * never hidden and never quietly corrected.
 *
 * Somebody working a window sees their own evening. A manager sees everybody's, because the server
 * widens the list for whoever holds the reports permission; this screen does not decide that.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Tills = {
		App: null,
		current: null,
		shifts: [],
	};

	Tills.render = function ( App ) {
		Tills.App = App;

		App.loading( App.t( 'panel.tills.title' ) );

		Promise.all( [
			App.request( 'GET', '/shifts/current' ),
			App.request( 'GET', '/shifts' ),
		] ).then( function ( results ) {
			Tills.current = results[ 0 ].data;
			Tills.shifts = results[ 1 ].data || [];
			Tills.paint();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	Tills.paint = function () {
		var App = Tills.App;

		App.page( {
			title: App.t( 'panel.tills.title' ),
			description: esc( App.t( 'panel.tills.description' ) ),
			actions: Tills.current
				? ''
				: '<button class="btn btn--primary" id="till-open">' + icon( 'wallet', { size: 15 } ) +
					esc( App.t( 'panel.tills.open' ) ) + '</button>',
			body: Tills.currentMarkup() + Tills.historyMarkup(),
		} );

		Tills.bind();
	};

	/** The open drawer, if there is one. */
	Tills.currentMarkup = function () {
		var App = Tills.App;
		var till = Tills.current;

		if ( ! till ) {
			return App.emptyState( 'wallet', App.t( 'panel.tills.noneOpen' ),
				esc( App.t( 'panel.tills.noneOpenHint' ) ),
				{ does: 'till-open', label: App.t( 'panel.tills.open' ) } );
		}

		var money = function ( amount ) { return App.money( amount, till.currency ); };

		return '<div class="till">' +
			'<div class="stat-grid">' +
				Tills.tile( App.t( 'panel.tills.expected' ), money( till.expected_cash ),
					App.t( 'panel.tills.float', { amount: money( till.opening_float ) } ) ) +
				Tills.tile( App.t( 'panel.tills.cash' ), money( till.takings.cash ),
					App.t( 'panel.tills.refunded', { amount: money( till.takings.cash_refunded ) } ) ) +
				Tills.tile( App.t( 'panel.tills.card' ), money( till.takings.card ),
					App.t( 'panel.tills.owed', { amount: money( till.takings.owed ) } ) ) +
				Tills.tile( App.t( 'panel.tills.movements' ),
					money( till.movements.in - till.movements.out ),
					App.t( 'panel.tills.since', { when: App.date( till.opened_at ) } ) ) +
			'</div>' +
			'<div class="row">' +
				'<button class="btn" id="till-in">' + esc( App.t( 'panel.tills.moveIn' ) ) + '</button>' +
				'<button class="btn" id="till-out">' + esc( App.t( 'panel.tills.moveOut' ) ) + '</button>' +
				'<button class="btn btn--primary" id="till-close">' +
					esc( App.t( 'panel.tills.close' ) ) + '</button>' +
			'</div>' +
		'</div>';
	};

	Tills.tile = function ( label, value, note ) {
		return '<div class="stat stat--block">' +
			'<span class="stat__value tnum">' + esc( value ) + '</span>' +
			'<span class="stat__label">' + esc( label ) + '</span>' +
			( note ? '<span class="stat__note">' + esc( note ) + '</span>' : '' ) +
		'</div>';
	};

	Tills.historyMarkup = function () {
		var App = Tills.App;

		if ( ! Tills.shifts.length ) {
			return '';
		}

		return '<h2 class="subhead">' + esc( App.t( 'panel.tills.history' ) ) + '</h2>' +
			App.table(
				[
					App.t( 'panel.tills.who' ),
					App.t( 'panel.tills.when' ),
					App.t( 'panel.tills.expected' ),
					App.t( 'panel.tills.counted' ),
					App.t( 'panel.tills.difference' ),
				],
				Tills.shifts.map( function ( shift ) {
					return '<tr>' +
						'<td class="table__primary">' + esc( shift.user || '—' ) +
							( shift.event
								? '<span class="muted on-own-line">' + esc( shift.event ) + '</span>'
								: '' ) + '</td>' +
						'<td class="nowrap">' + esc( App.date( shift.opened_at ) ) +
							'<span class="muted on-own-line">' +
							esc( shift.open
								? App.t( 'panel.tills.stillOpen' )
								: App.date( shift.closed_at ) ) + '</span></td>' +
						'<td class="tnum">' + esc( App.money( shift.expected_cash, shift.currency ) ) + '</td>' +
						'<td class="tnum">' + esc( shift.open
							? '—'
							: App.money( shift.counted_cash, shift.currency ) ) + '</td>' +
						'<td class="tnum">' + Tills.difference( shift ) + '</td>' +
					'</tr>';
				} ).join( '' )
			);
	};

	/**
	 * The finding, written the way a person says it.
	 *
	 * A positive number is an over and a negative one a short, and neither is dressed up: a till
	 * that balanced says so, and one that did not says by how much.
	 */
	Tills.difference = function ( shift ) {
		var App = Tills.App;

		if ( shift.open ) {
			return '<span class="muted">—</span>';
		}

		if ( 0 === shift.difference ) {
			return '<span class="badge badge--ok">' + esc( App.t( 'panel.tills.balanced' ) ) + '</span>';
		}

		return '<span class="badge badge--' + ( shift.difference > 0 ? 'neutral' : 'warn' ) + '">' +
			esc( ( shift.difference > 0 ? '+' : '−' ) +
				App.money( Math.abs( shift.difference ), shift.currency ) ) + '</span>';
	};

	/* ------------------------------------------------------------------------------ actions */

	Tills.bind = function () {
		var App = Tills.App;

		var on = function ( id, handler ) {
			var button = document.getElementById( id );

			button && button.addEventListener( 'click', handler );
		};

		on( 'till-open', function () {
			App.modal( {
				title: App.t( 'panel.tills.openTitle' ),
				submitLabel: App.t( 'panel.tills.open' ),
				body:
					'<div class="stack">' +
						'<p class="hint">' + esc( App.t( 'panel.tills.openHint' ) ) + '</p>' +
						'<div class="field"><label class="field__label" for="till-float">' +
							esc( App.t( 'panel.tills.floatLabel' ) ) + '</label>' +
						'<input class="input tnum" id="till-float" type="number" min="0" value="0"></div>' +
						'<div class="field"><label class="field__label" for="till-currency">' +
							esc( App.t( 'panel.tills.currencyLabel' ) ) + '</label>' +
						'<input class="input" id="till-currency" maxlength="3" value="EUR" required></div>' +
					'</div>',
				onSubmit: function () {
					return App.request( 'POST', '/shifts', {
						currency: String( document.getElementById( 'till-currency' ).value ).toUpperCase(),
						opening_float: Number( document.getElementById( 'till-float' ).value ) || 0,
					} ).then( function () {
						App.toast( App.t( 'panel.tills.opened' ) );
						Tills.render( App );
					} );
				},
			} );
		} );

		on( 'till-in', function () { Tills.move( 'in' ); } );
		on( 'till-out', function () { Tills.move( 'out' ); } );

		on( 'till-close', function () {
			var till = Tills.current;

			App.modal( {
				title: App.t( 'panel.tills.closeTitle' ),
				submitLabel: App.t( 'panel.tills.close' ),
				body:
					'<div class="stack">' +
						/*
						 * What the drawer should hold is shown, deliberately, before the count is
						 * typed. Hiding it to make the count "honest" would mean a clerk who
						 * miscounts by a hundred finds out from a manager the next morning instead
						 * of from the drawer in front of them.
						 */
						'<p class="hint">' + esc( App.t( 'panel.tills.closeHint', {
							amount: App.money( till.expected_cash, till.currency ),
						} ) ) + '</p>' +
						'<div class="field"><label class="field__label" for="till-counted">' +
							esc( App.t( 'panel.tills.countedLabel' ) ) + '</label>' +
						'<input class="input tnum" id="till-counted" type="number" min="0" required ' +
							'value="' + esc( till.expected_cash ) + '"></div>' +
						'<div class="field"><label class="field__label" for="till-note">' +
							esc( App.t( 'panel.tills.noteLabel' ) ) + '</label>' +
						'<input class="input" id="till-note" maxlength="300"></div>' +
					'</div>',
				onSubmit: function () {
					return App.request( 'POST', '/shifts/' + till.id + '/close', {
						counted_cash: Number( document.getElementById( 'till-counted' ).value ) || 0,
						note: String( document.getElementById( 'till-note' ).value ).trim() || null,
					} ).then( function ( closed ) {
						App.toast( 0 === closed.difference
							? App.t( 'panel.tills.balanced' )
							: App.t( 'panel.tills.closedOut', {
								amount: App.money( Math.abs( closed.difference ), closed.currency ),
							} ) );
						Tills.render( App );
					} );
				},
			} );
		} );
	};

	Tills.move = function ( kind ) {
		var App = Tills.App;

		App.modal( {
			title: App.t( 'in' === kind ? 'panel.tills.moveIn' : 'panel.tills.moveOut' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<div class="field"><label class="field__label" for="move-amount">' +
						esc( App.t( 'panel.tills.amountLabel' ) ) + '</label>' +
					'<input class="input tnum" id="move-amount" type="number" min="1" required></div>' +
					'<div class="field"><label class="field__label" for="move-reason">' +
						esc( App.t( 'panel.tills.reasonLabel' ) ) + '</label>' +
					'<input class="input" id="move-reason" maxlength="200" required>' +
					// Required, because "somebody typed 40" is not an answer anybody can give a
					// month later.
					'<span class="field__hint">' + esc( App.t( 'panel.tills.reasonHint' ) ) + '</span></div>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/shifts/' + Tills.current.id + '/movements', {
					kind: kind,
					amount: Number( document.getElementById( 'move-amount' ).value ) || 0,
					reason: String( document.getElementById( 'move-reason' ).value ).trim(),
				} ).then( function () {
					App.toast( App.t( 'panel.tills.moved' ) );
					Tills.render( App );
				} );
			},
		} );
	};

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	global.SeatmapTills = Tills;
}( window ) );
