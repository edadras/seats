/**
 * Purchases somebody started and did not finish.
 *
 * One number is what this screen is for: of the people written to, how many came back. Everything
 * else on it is the working behind that number, and the tiles are arranged so it is the one an
 * organiser reads first.
 *
 * "Written to" deliberately excludes the baskets nobody has been written to yet. Counting those
 * would make the recovery rate look worse the better an organiser's checkout got, which is exactly
 * backwards — a checkout nobody abandons should not read as a recovery process that never works.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Baskets = {
		App: null,
		filters: { status: '', q: '' },
		timer: null,
	};

	Baskets.render = function ( App ) {
		Baskets.App = App;
		Baskets.paint();
		Baskets.load();
	};

	Baskets.paint = function () {
		var App = Baskets.App;

		App.page( {
			title: App.t( 'panel.baskets.title' ),
			description: esc( App.t( 'panel.baskets.description' ) ),
			body:
				'<div id="b-summary"></div>' +
				'<div class="filters">' +
					'<input class="input grow" id="b-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.baskets.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.baskets.search' ) ) + '" ' +
						'value="' + esc( Baskets.filters.q ) + '">' +
					'<select class="select" id="b-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						[ '', 'waiting', 'sent', 'recovered', 'declined', 'expired' ].map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Baskets.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.baskets.status.' + status )
									: App.t( 'panel.baskets.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="b-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 'b-search' );

		search.addEventListener( 'input', function () {
			Baskets.filters.q = search.value;

			global.clearTimeout( Baskets.timer );
			Baskets.timer = global.setTimeout( function () { Baskets.load(); }, 300 );
		} );

		document.getElementById( 'b-status' ).addEventListener( 'change', function () {
			Baskets.filters.status = this.value;
			Baskets.load();
		} );
	};

	Baskets.load = function () {
		var App = Baskets.App;
		var host = document.getElementById( 'b-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Baskets.filters )
			.filter( function ( key ) { return Baskets.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Baskets.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/baskets' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				document.getElementById( 'b-summary' ).innerHTML = Baskets.summaryMarkup( response.summary );
				host.innerHTML = Baskets.listMarkup( response );

				each( '[data-send]', function ( button ) {
					button.addEventListener( 'click', function () { Baskets.send( button ); } );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Baskets.summaryMarkup = function ( summary ) {
		var App = Baskets.App;

		if ( ! summary || ! summary.baskets ) {
			return '';
		}

		return '<div class="stat-strip">' +
			tile( App.t( 'panel.baskets.unfinished' ), App.number( summary.baskets ),
				App.t( 'panel.baskets.waitingCount', { count: App.number( summary.waiting ) } ) ) +
			tile( App.t( 'panel.baskets.writtenTo' ), App.number( summary.written ) ) +
			tile( App.t( 'panel.baskets.cameBack' ), App.number( summary.recovered ),
				App.t( 'panel.baskets.rate', { percent: App.number( summary.rate ) } ) ) +
			tile( App.t( 'panel.baskets.won' ), Baskets.money( summary.won ),
				App.t( 'panel.baskets.lost', { amount: Baskets.money( summary.lost ) } ) ) +
		'</div>';
	};

	/** Per currency, because an account selling in two of them has two answers and not one. */
	Baskets.money = function ( amounts ) {
		var App = Baskets.App;

		if ( ! amounts || ! amounts.length ) {
			return App.money( 0, 'EUR' );
		}

		return amounts.map( function ( entry ) {
			return App.money( entry.amount, entry.currency );
		} ).join( ' · ' );
	};

	Baskets.listMarkup = function ( response ) {
		var App = Baskets.App;

		if ( ! response.data.length ) {
			// Nobody abandoned a basket is the good outcome; there is nothing here to press.
			return App.emptyState(
				Baskets.filters.q ? 'search' : 'check',
				App.t( Baskets.filters.q ? 'panel.baskets.noMatchTitle' : 'panel.baskets.noneTitle' ),
				esc( App.t( Baskets.filters.q ? 'panel.baskets.noMatchBody' : 'panel.baskets.noneBody' ) ),
				{ waiting: true }
			);
		}

		return App.table(
			[
				App.t( 'panel.baskets.buyer' ),
				App.t( 'panel.baskets.event' ),
				{ label: App.t( 'panel.baskets.seats' ), numeric: true },
				{ label: App.t( 'panel.baskets.worth' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( row ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( row.name || row.email ) +
						'<span class="muted on-own-line">' + esc( row.email ) + '</span></td>' +
					'<td>' + esc( row.event_name || '—' ) +
						'<span class="muted on-own-line">' + esc( App.date( row.created_at ) ) + '</span></td>' +
					'<td class="tnum">' + esc( App.number( row.seats ) ) + '</td>' +
					'<td class="tnum">' + esc( App.money( row.total, row.currency ) ) + '</td>' +
					'<td>' + Baskets.badge( row ) + '</td>' +
					'<td class="table__actions">' +
						( 'waiting' === row.status
							? '<button class="btn btn--sm" data-send="' + esc( row.id ) + '">' +
								esc( App.t( 'panel.baskets.writeNow' ) ) + '</button>'
							: '' ) +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Baskets.badge = function ( row ) {
		var App = Baskets.App;
		var tone = { recovered: 'ok', declined: 'neutral', expired: 'neutral', sent: 'warn', waiting: 'neutral' };

		return '<span class="badge badge--' + ( tone[ row.status ] || 'neutral' ) + '">' +
			esc( App.t( 'panel.baskets.status.' + row.status ) ) + '</span>' +
			( row.recovered_reference
				? '<span class="muted on-own-line"><code>' +
					esc( row.recovered_reference ) + '</code></span>'
				: '' );
	};

	Baskets.send = function ( button ) {
		var App = Baskets.App;

		button.disabled = true;

		App.request( 'POST', '/baskets/' + button.dataset.send + '/send', {} )
			.then( function () {
				App.toast( App.t( 'panel.baskets.written' ) );
				Baskets.load();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				App.toast( error.message, true );
			} );
	};

	/* ----------------------------------------------------------------------- helpers */

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

	global.SeatmapBaskets = Baskets;
}( typeof window !== 'undefined' ? window : globalThis ) );
