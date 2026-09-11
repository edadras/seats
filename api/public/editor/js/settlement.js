/**
 * The end-of-run screen: what came in, what went back out, and what is owed.
 *
 * Read by somebody sitting down with a venue's contract in front of them, so it is laid out the
 * way a settlement is argued: the money in, the money back, the platform's share, the figure at
 * the bottom. Nothing is rolled up across currencies — an organiser selling in two is settling
 * two amounts, and one number combining them would be a number nobody can bank.
 *
 * The two files are the point as much as the table: a CSV for whoever does the books, and a PDF
 * statement to send the venue.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Settle = {
		App: null,
		events: [],
		filters: { from: '', to: '', event_id: '', basis: 'paid' },
		data: null,
	};

	Settle.render = function ( App ) {
		Settle.App = App;
		App.loading( App.t( 'panel.settlement.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Settle.events = response.data || [];
				Settle.paint();
				Settle.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Settle.paint = function () {
		var App = Settle.App;

		App.page( {
			title: App.t( 'panel.settlement.title' ),
			description: esc( App.t( 'panel.settlement.description' ) ),
			actions:
				'<a class="btn" id="settle-csv" href="#">' + icon( 'download', { size: 15 } ) +
					esc( App.t( 'panel.settlement.exportCsv' ) ) + '</a>' +
				'<a class="btn btn--primary" id="settle-pdf" href="#">' + icon( 'file', { size: 15 } ) +
					esc( App.t( 'panel.settlement.statement' ) ) + '</a>',
			body:
				'<div class="filters">' +
					field( 'settle-from', App.t( 'panel.settlement.from' ), Settle.filters.from ) +
					field( 'settle-to', App.t( 'panel.settlement.to' ), Settle.filters.to ) +
					'<select class="select" id="settle-event" aria-label="' +
						esc( App.t( 'panel.settlement.event' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.settlement.allEvents' ) ) + '</option>' +
						Settle.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( event.id === Settle.filters.event_id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="settle-basis" aria-label="' +
						esc( App.t( 'panel.settlement.basis' ) ) + '">' +
						[ 'paid', 'event' ].map( function ( basis ) {
							return '<option value="' + basis + '"' +
								( basis === Settle.filters.basis ? ' selected' : '' ) + '>' +
								esc( App.t( 'paid' === basis
									? 'panel.settlement.basisPaid'
									: 'panel.settlement.basisEvent' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="settle-totals" class="spaced"></div>' +
				'<div id="settle-rows"></div>' +
				'<p class="hint spaced">' + esc( App.t( 'panel.settlement.note' ) ) + '</p>' +
				'<div id="settle-payouts"></div>',
		} );

		[ 'settle-from', 'settle-to', 'settle-event', 'settle-basis' ].forEach( function ( id ) {
			var input = document.getElementById( id );

			if ( input ) {
				input.addEventListener( 'change', function () {
					Settle.filters.from = value( 'settle-from' );
					Settle.filters.to = value( 'settle-to' );
					Settle.filters.event_id = value( 'settle-event' );
					Settle.filters.basis = value( 'settle-basis' ) || 'paid';
					Settle.load();
				} );
			}
		} );

		[ [ 'settle-csv', 'csv' ], [ 'settle-pdf', 'pdf' ] ].forEach( function ( pair ) {
			var link = document.getElementById( pair[ 0 ] );

			if ( link ) {
				link.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					Settle.download( pair[ 1 ] );
				} );
			}
		} );
	};

	Settle.query = function () {
		var parts = [];

		[ 'from', 'to', 'event_id', 'basis' ].forEach( function ( key ) {
			if ( Settle.filters[ key ] ) {
				parts.push( key + '=' + encodeURIComponent( Settle.filters[ key ] ) );
			}
		} );

		return parts.length ? '?' + parts.join( '&' ) : '';
	};

	Settle.load = function () {
		var App = Settle.App;
		var host = document.getElementById( 'settle-rows' );

		if ( ! host ) {
			return;
		}

		App.request( 'GET', '/settlement' + Settle.query() )
			.then( function ( response ) {
				Settle.data = response;
				document.getElementById( 'settle-totals' ).innerHTML = Settle.totalsMarkup( App, response );
				host.innerHTML = Settle.rowsMarkup( App, response );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );

		Settle.loadPayouts();
	};

	/**
	 * What has actually been paid, under the report of what is owed.
	 *
	 * The two are different questions and the screen says so by keeping them apart. Above: what
	 * this window is worth today. Below: the periods that were closed, at the figures they had when
	 * the money left — which a later refund does not rewrite, and should not.
	 *
	 * Not filtered by the dates at the top. An organiser looking at March still needs to see that
	 * February was paid, and a payout list that moved with the filter would be a list somebody
	 * reads as "nothing has ever been paid".
	 */
	Settle.loadPayouts = function () {
		var App = Settle.App;
		var host = document.getElementById( 'settle-payouts' );

		if ( ! host ) {
			return;
		}

		App.request( 'GET', '/settlement/payouts' )
			.then( function ( response ) {
				host.innerHTML = Settle.payoutsMarkup( App, response );
			} )
			// Quiet on purpose: the settlement above it is the screen, and a toast about a second
			// request failing would land on top of figures that are perfectly fine.
			.catch( function () { host.innerHTML = ''; } );
	};

	Settle.payoutsMarkup = function ( App, response ) {
		var rows = response.data || [];

		if ( ! rows.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.settlement.payouts' ) ) + '</h3>' +
			'<p class="hint">' + esc( App.t( response.next_from
				? 'panel.settlement.paidUpTo'
				: 'panel.settlement.payoutsNote', { date: App.date( response.next_from, { dateStyle: 'medium' } ) } ) ) +
				'</p>' +
			App.table(
				[
					App.t( 'panel.settlement.period' ),
					App.t( 'panel.settlement.charged' ),
					App.t( 'panel.settlement.commission' ),
					{ label: App.t( 'panel.settlement.payable' ), numeric: true },
					App.t( 'panel.common.status' ),
					App.t( 'panel.settlement.reference' ),
				],
				rows.map( function ( row ) {
					var badge = 'paid' === row.status
						? 'badge--ok'
						: ( 'void' === row.status ? 'badge--danger' : '' );

					return '<tr' + ( 'void' === row.status ? ' class="is-muted"' : '' ) + '>' +
						'<td class="table__primary nowrap">' +
							esc( App.t( 'panel.settlement.periodRange', {
								from: App.date( row.from, { dateStyle: 'medium' } ),
								to: App.date( row.to, { dateStyle: 'medium' } ),
							} ) ) +
							'<span class="muted on-own-line">' + esc( row.currency ) + ' · ' +
								esc( App.t( 'panel.settlement.eventsCounted', {
									count: App.number( ( row.events || [] ).length ),
								} ) ) + '</span></td>' +
						'<td class="tnum">' + esc( App.money( row.charged, row.currency ) ) + '</td>' +
						'<td class="tnum">' + esc( App.money( row.commission, row.currency ) ) + '</td>' +
						'<td class="tnum">' + esc( App.money( row.payable, row.currency ) ) + '</td>' +
						'<td><span class="badge ' + badge + '">' +
							esc( App.t( 'panel.settlement.payoutStatus.' + row.status ) ) + '</span>' +
							( row.paid_at
								? '<span class="muted on-own-line tnum">' +
									esc( App.date( row.paid_at, { dateStyle: 'medium' } ) ) + '</span>'
								: '' ) +
							// Why a period was reopened, said on the row rather than left as a
							// question somebody answers from memory months later.
							( row.void_reason
								? '<span class="muted on-own-line">' + esc( row.void_reason ) + '</span>'
								: '' ) + '</td>' +
						'<td>' + esc( row.reference || '—' ) +
							( row.method
								? '<span class="muted on-own-line">' + esc( row.method ) + '</span>'
								: '' ) + '</td>' +
					'</tr>';
				} ).join( '' )
			);
	};

	Settle.totalsMarkup = function ( App, response ) {
		if ( ! response.totals.length ) {
			return '';
		}

		return response.totals.map( function ( total ) {
			return '<div class="stat-strip">' +
				tile( App.t( 'panel.settlement.charged' ),
					App.money( total.charged, total.currency ) ) +
				tile( App.t( 'panel.settlement.refunded' ),
					App.money( total.refunded, total.currency ) ) +
				tile( App.t( 'panel.settlement.commission' ),
					App.money( total.commission, total.currency ) ) +
				tile( App.t( 'panel.settlement.payable' ),
					App.money( total.payable, total.currency ), true ) +
			'</div>';
		} ).join( '' );
	};

	Settle.rowsMarkup = function ( App, response ) {
		if ( ! response.rows.length ) {
			return App.emptyState( 'chart', App.t( 'panel.settlement.emptyTitle' ),
				esc( App.t( 'panel.settlement.emptyBody' ) ) );
		}

		return App.table(
			[
				App.t( 'panel.settlement.event' ),
				App.t( 'panel.settlement.seats' ),
				App.t( 'panel.settlement.charged' ),
				App.t( 'panel.settlement.refunded' ),
				App.t( 'panel.settlement.commission' ),
				App.t( 'panel.settlement.payable' ),
			],
			response.rows.map( function ( row ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( row.event.name ) +
						( row.event.starts_at
							? '<span class="muted on-own-line">' +
								esc( App.date( row.event.starts_at ) ) + '</span>'
							: '' ) + '</td>' +
					'<td class="tnum">' + esc( App.number( row.seats ) ) +
						( row.seats_refunded
							? '<span class="muted on-own-line">−' +
								esc( App.number( row.seats_refunded ) ) + '</span>'
							: '' ) + '</td>' +
					'<td class="tnum">' + esc( App.money( row.charged, row.currency ) ) +
						( row.discount
							? '<span class="muted on-own-line">' +
								esc( App.t( 'panel.settlement.lessDiscount', {
									amount: App.money( row.discount, row.currency ),
								} ) ) + '</span>'
							: '' ) +
						/*
						 * Said under the total rather than beside it, because it is part of that
						 * total and not another one. What it answers is the question somebody asks
						 * with a bank statement in front of them: why is less here than there.
						 */
						( row.voucher
							? '<span class="muted on-own-line">' +
								esc( App.t( 'panel.settlement.byVoucher', {
									amount: App.money( row.voucher, row.currency ),
								} ) ) + '</span>'
							: '' ) + '</td>' +
					'<td class="tnum">' + ( row.refunded
						? esc( App.money( row.refunded, row.currency ) )
						: '<span class="muted">—</span>' ) + '</td>' +
					'<td class="tnum">' + ( row.commission
						? esc( App.money( row.commission, row.currency ) )
						: '<span class="muted">—</span>' ) + '</td>' +
					'<td class="tnum"><strong>' +
						esc( App.money( row.payable, row.currency ) ) + '</strong></td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/**
	 * The files.
	 *
	 * Fetched with the session's own token and handed to the browser as a blob: both routes are
	 * authenticated, and a plain <a href> carries no token.
	 */
	Settle.download = function ( kind ) {
		var App = Settle.App;
		var path = 'csv' === kind ? '/settlement/export' : '/settlement/statement';

		App.request( 'GET', path + Settle.query(), null, { raw: true } )
			.then( function ( blob ) {
				var url = global.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = 'csv' === kind ? 'settlement.csv' : 'settlement.pdf';
				document.body.appendChild( link );
				link.click();
				link.remove();
				global.URL.revokeObjectURL( url );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function field( id, label, current ) {
		return '<label class="filters__dated" for="' + id + '">' +
			'<span>' + esc( label ) + '</span>' +
			'<input class="input" type="date" id="' + id + '" value="' + esc( current ) + '">' +
		'</label>';
	}

	function value( id ) {
		var input = document.getElementById( id );

		return input ? input.value : '';
	}

	function tile( label, amount, strong ) {
		return '<div class="tile tile--static' + ( strong ? ' tile--accent' : '' ) + '">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( amount ) + '</span>' +
		'</div>';
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapSettlement = Settle;
}( typeof window !== 'undefined' ? window : globalThis ) );
