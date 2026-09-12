/**
 * Taking this account's data, and closing the account.
 *
 * One screen for both, because they are one act with a pause in the middle: somebody leaving takes
 * their history and then shuts the door. Two screens would be a platform where the door gets shut
 * first.
 *
 * The closing half is deliberately the quietest thing in the panel — no banner, no red, a plain
 * card at the bottom — with one exception: it says exactly what will happen, in order, before it
 * asks anybody to type their own account's name.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Leaving = {
		App: null,
		exports: [],
		keeps: 7,
		closure: null,
	};

	Leaving.render = function ( App ) {
		Leaving.App = App;
		App.loading( App.t( 'panel.leaving.title' ) );

		Promise.all( [
			App.request( 'GET', '/account/exports' ),
			App.request( 'GET', '/account/closure' ),
		] )
			.then( function ( answers ) {
				Leaving.exports = answers[ 0 ].data || [];
				Leaving.keeps = answers[ 0 ].keeps_days || 7;
				Leaving.closure = answers[ 1 ];
				Leaving.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Leaving.paint = function () {
		var App = Leaving.App;

		App.page( {
			title: App.t( 'panel.leaving.title' ),
			description: esc( App.t( 'panel.leaving.description' ) ),
			body: Leaving.dataCard( App ) + Leaving.closeCard( App ),
		} );

		Leaving.bind();
	};

	Leaving.dataCard = function ( App ) {
		return '<div class="card card--pad">' +
			'<h2 class="card__title">' + esc( App.t( 'panel.leaving.takeTitle' ) ) + '</h2>' +
			'<p>' + esc( App.t( 'panel.leaving.takeBody' ) ) + '</p>' +
			'<p class="field__hint">' +
				esc( App.t( 'panel.leaving.keeps', { days: App.number( Leaving.keeps ) } ) ) + '</p>' +
			'<p><button class="btn btn--primary" id="leave-export">' +
				icon( 'download', { size: 15 } ) +
				esc( App.t( 'panel.leaving.take' ) ) + '</button></p>' +
		'</div>' +
		'<div class="spaced">' + Leaving.exportsMarkup( App ) + '</div>';
	};

	Leaving.exportsMarkup = function ( App ) {
		if ( ! Leaving.exports.length ) {
			return App.emptyState( 'file', App.t( 'panel.leaving.emptyTitle' ),
				esc( App.t( 'panel.leaving.emptyBody' ) ),
				{ does: 'leave-export', label: App.t( 'panel.leaving.take' ) } );
		}

		return App.table(
			[
				App.t( 'panel.leaving.when' ),
				App.t( 'panel.leaving.what' ),
				{ label: App.t( 'panel.leaving.size' ), numeric: true },
				App.t( 'panel.common.status' ),
			],
			Leaving.exports.map( function ( row ) {
				return '<tr>' +
					'<td class="tnum">' + esc( App.date( row.created_at ) ) +
						( row.requested_by
							? '<span class="muted on-own-line">' + esc( row.requested_by ) + '</span>'
							: '' ) + '</td>' +
					// What is in it, counted. An archive an organiser is about to rely on should
					// not have to be opened to find out whether it holds anything.
					'<td>' + esc( App.t( 'panel.leaving.tables', {
						count: App.number( Object.keys( row.contents || {} ).length ),
					} ) ) + '</td>' +
					'<td class="tnum">' + esc( App.t( 'panel.leaving.kb', {
						size: App.number( Math.max( 1, Math.round( ( row.bytes || 0 ) / 1024 ) ) ),
					} ) ) + '</td>' +
					'<td>' + Leaving.statusCell( App, row ) + '</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Leaving.statusCell = function ( App, row ) {
		if ( 'failed' === row.status ) {
			return '<span class="badge badge--danger">' +
				esc( App.t( 'panel.leaving.failed' ) ) + '</span>' +
				( row.error ? '<span class="muted on-own-line">' + esc( row.error ) + '</span>' : '' );
		}

		if ( ! row.link ) {
			return '<span class="badge badge--neutral">' +
				esc( App.t( 'panel.leaving.gone' ) ) + '</span>';
		}

		return '<a class="btn btn--sm" href="' + esc( row.link ) + '">' +
			icon( 'download', { size: 14 } ) + esc( App.t( 'panel.leaving.download' ) ) + '</a>' +
			'<span class="muted on-own-line">' +
				esc( App.t( 'panel.leaving.until', { date: App.date( row.expires_at ) } ) ) + '</span>';
	};

	Leaving.closeCard = function ( App ) {
		var state = Leaving.closure || {};

		if ( 'cancelled' === state.status ) {
			return '<div class="card card--pad spaced">' +
				'<h2 class="card__title">' + esc( App.t( 'panel.leaving.closedTitle' ) ) + '</h2>' +
				'<p>' + esc( App.t( 'panel.leaving.closedBody', {
					date: App.date( state.erase_after ),
				} ) ) + '</p>' +
			'</div>';
		}

		return '<div class="card card--pad spaced">' +
			'<h2 class="card__title">' + esc( App.t( 'panel.leaving.closeTitle' ) ) + '</h2>' +
			'<p>' + esc( App.t( 'panel.leaving.closeBody', {
				days: App.number( state.keep_days || 30 ),
			} ) ) + '</p>' +

			// What is owed, said plainly rather than used as a refusal: an organiser leaving should
			// know whether the platform is going to invoice them for the days they have used.
			( ( state.owed || [] ).length
				? '<p class="field__hint">' + esc( App.t( 'panel.leaving.owed', {
					amount: ( state.owed || [] ).map( function ( row ) {
						return App.money( row.amount, row.currency );
					} ).join( ', ' ),
				} ) ) + '</p>'
				: '' ) +

			( state.can_close
				? '<p><button class="btn btn--danger" id="leave-close">' +
					esc( App.t( 'panel.leaving.close' ) ) + '</button></p>'
				: Leaving.blockedMarkup( App, state ) ) +
		'</div>';
	};

	/**
	 * Why the door will not open yet.
	 *
	 * Named nights rather than a count: "seven nights have tickets" is a refusal, and a list of
	 * which seven is something an organiser can act on this afternoon.
	 */
	Leaving.blockedMarkup = function ( App, state ) {
		return '<div class="issue issue--error">' +
			'<strong>' + esc( App.t( 'panel.leaving.blockedTitle' ) ) + '</strong>' +
			'<p>' + esc( App.t( 'panel.leaving.blockedBody' ) ) + '</p>' +
			'<ul>' + ( state.nights || [] ).map( function ( night ) {
				return '<li>' + esc( night.name ) + ' — ' +
					esc( App.t( 'panel.leaving.nightTickets', {
						count: App.number( night.tickets ),
					} ) ) + ' — ' + esc( App.date( night.starts_at ) ) + '</li>';
			} ).join( '' ) + '</ul>' +
		'</div>';
	};

	Leaving.bind = function () {
		var App = Leaving.App;
		var take = document.getElementById( 'leave-export' );
		var close = document.getElementById( 'leave-close' );

		if ( take ) {
			take.addEventListener( 'click', function () {
				take.disabled = true;
				App.toast( App.t( 'panel.leaving.taking' ) );

				App.request( 'POST', '/account/exports', {} )
					.then( function () {
						App.toast( App.t( 'panel.leaving.made' ) );
						Leaving.render( App );
					} )
					.catch( function ( error ) {
						take.disabled = false;
						App.toast( error.message, true );
					} );
			} );
		}

		if ( close ) {
			close.addEventListener( 'click', function () { Leaving.confirm(); } );
		}
	};

	Leaving.confirm = function () {
		var App = Leaving.App;
		var name = ( App.profile && App.profile.tenant ) || '';

		App.modal( {
			title: App.t( 'panel.leaving.confirmTitle' ),
			submitLabel: App.t( 'panel.leaving.close' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.leaving.confirmBody', {
				days: App.number( ( Leaving.closure || {} ).keep_days || 30 ),
			} ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="leave-name">' +
				esc( App.t( 'panel.leaving.confirmLabel', { name: name } ) ) + '</label>' +
				'<input class="input" id="leave-name" name="confirm" required autocomplete="off"></div>' +
				'<div class="field"><label class="field__label" for="leave-why">' +
				esc( App.t( 'panel.leaving.why' ) ) + '</label>' +
				'<input class="input" id="leave-why" name="reason" maxlength="200"></div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/account/closure', {
					confirm: data.get( 'confirm' ),
					reason: data.get( 'reason' ) || null,
				} ).then( function ( state ) {
					Leaving.closure = state;
					App.toast( App.t( 'panel.leaving.closed' ) );
					Leaving.paint();
				} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapLeaving = Leaving;
}( typeof window !== 'undefined' ? window : globalThis ) );
