/**
 * The list the door works from when the scanner does not.
 *
 * Built for somebody standing up, holding a tablet, with a queue in front of them: one search box
 * that matches a name, an email, a booking reference or a seat, and rows big enough to read at
 * arm's length. Everything else is out of the way.
 *
 * The file is the point as much as the screen. A door list that only exists in a browser is no use
 * on the night the Wi-Fi is down, which is exactly the night it is needed.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Door = {
		App: null,
		events: [],
		eventId: '',
		filters: { q: '', state: '' },
		timer: null,
		meta: null,
	};

	Door.render = function ( App ) {
		Door.App = App;
		App.loading( App.t( 'panel.doorList.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Door.events = response.data || [];
				Door.eventId = Door.eventId || ( Door.events[ 0 ] || {} ).id || '';
				Door.paint();

				if ( Door.eventId ) {
					Door.load();
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Door.paint = function () {
		var App = Door.App;

		App.page( {
			title: App.t( 'panel.doorList.title' ),
			description: esc( App.t( 'panel.doorList.description' ) ),
			actions: Door.eventId
				? '<a class="btn" id="door-export" href="#">' + icon( 'download', { size: 15 } ) +
					esc( App.t( 'panel.doorList.export' ) ) + '</a>'
				: '',
			body:
				( Door.events.length
					? '<div class="filters">' +
						'<select class="select" id="door-event" aria-label="' +
							esc( App.t( 'panel.doorList.event' ) ) + '">' +
							Door.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Door.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
						'<input class="input grow" id="door-search" type="search" ' +
							'placeholder="' + esc( App.t( 'panel.doorList.search' ) ) + '" ' +
							'aria-label="' + esc( App.t( 'panel.doorList.search' ) ) + '" ' +
							'value="' + esc( Door.filters.q ) + '">' +
						'<select class="select" id="door-state" aria-label="' +
							esc( App.t( 'panel.doorList.anyone' ) ) + '">' +
							[ '', 'out', 'in' ].map( function ( state ) {
								return '<option value="' + state + '"' +
									( state === Door.filters.state ? ' selected' : '' ) + '>' +
									esc( App.t( 'panel.doorList.states.' + ( state || 'any' ) ) ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.doorList.noEventsTitle' ),
						esc( App.t( 'panel.doorList.noEventsBody' ) ) ) ) +
				'<div id="door-tally" class="spaced"></div>' +
				'<div id="door-rows"></div>',
		} );

		var search = document.getElementById( 'door-search' );

		if ( search ) {
			search.addEventListener( 'input', function () {
				Door.filters.q = search.value;

				global.clearTimeout( Door.timer );
				Door.timer = global.setTimeout( function () { Door.load(); }, 250 );
			} );
		}

		[ [ 'door-event', 'eventId' ], [ 'door-state', 'state' ] ].forEach( function ( pair ) {
			var field = document.getElementById( pair[ 0 ] );

			if ( ! field ) {
				return;
			}

			field.addEventListener( 'change', function () {
				if ( 'eventId' === pair[ 1 ] ) {
					Door.eventId = field.value;
				} else {
					Door.filters.state = field.value;
				}

				Door.load();
			} );
		} );

		var exporter = document.getElementById( 'door-export' );

		if ( exporter ) {
			exporter.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				Door.download();
			} );
		}
	};

	Door.query = function () {
		var parts = [];

		if ( Door.filters.q ) {
			parts.push( 'q=' + encodeURIComponent( Door.filters.q ) );
		}

		if ( Door.filters.state ) {
			parts.push( 'state=' + Door.filters.state );
		}

		return parts.length ? '?' + parts.join( '&' ) : '';
	};

	Door.load = function () {
		var App = Door.App;
		var host = document.getElementById( 'door-rows' );

		if ( ! host || ! Door.eventId ) {
			return;
		}

		App.request( 'GET', '/events/' + Door.eventId + '/door-list' + Door.query() )
			.then( function ( response ) {
				Door.meta = response.meta;
				document.getElementById( 'door-tally' ).innerHTML = Door.tallyMarkup( App, response.meta );
				host.innerHTML = Door.rowsMarkup( App, response );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Door.tallyMarkup = function ( App, meta ) {
		var waiting = Math.max( 0, ( meta.expected || 0 ) - ( meta.arrived || 0 ) );

		return '<div class="stat-strip">' +
			tile( App.t( 'panel.doorList.expected' ), App.number( meta.expected || 0 ) ) +
			tile( App.t( 'panel.doorList.arrived' ), App.number( meta.arrived || 0 ) ) +
			tile( App.t( 'panel.doorList.stillOut' ), App.number( waiting ) ) +
		'</div>';
	};

	Door.rowsMarkup = function ( App, response ) {
		if ( ! response.data.length ) {
			return App.emptyState(
				Door.filters.q ? 'search' : 'ticket',
				App.t( Door.filters.q ? 'panel.doorList.noMatchTitle' : 'panel.doorList.emptyTitle' ),
				esc( App.t( Door.filters.q ? 'panel.doorList.noMatchBody' : 'panel.doorList.emptyBody' ) )
			);
		}

		return App.table(
			[
				App.t( 'panel.doorList.name' ),
				App.t( 'panel.doorList.seat' ),
				App.t( 'panel.doorList.reference' ),
				App.t( 'panel.doorList.arrived' ),
			],
			response.data.map( function ( row ) {
				return '<tr class="door-row' + ( row.arrived ? ' is-in' : '' ) + '">' +
					'<td class="table__primary">' + esc( row.name || '—' ) +
						( row.ticket_type
							? '<span class="muted on-own-line">' + esc( row.ticket_type ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( row.seat || '—' ) +
						( row.quantity > 1
							? ' <span class="muted">× ' + esc( App.number( row.quantity ) ) + '</span>'
							: '' ) + '</td>' +
					'<td class="muted"><code>' + esc( row.reference ) + '</code></td>' +
					'<td>' + ( row.arrived
						? '<span class="badge badge--ok">' +
							esc( row.arrived_at
								? App.date( row.arrived_at )
								: App.t( 'panel.doorList.yes' ) ) + '</span>'
						: '<span class="muted">' + esc( App.t( 'panel.doorList.no' ) ) + '</span>' ) +
					'</td>' +
				'</tr>';
			} ).join( '' )
		) + ( response.meta && response.meta.total > response.data.length
			? '<p class="hint spaced">' + esc( App.t( 'panel.doorList.shown', {
				count: App.number( response.data.length ),
				total: App.number( response.meta.total ),
			} ) ) + '</p>'
			: '' );
	};

	/**
	 * The file.
	 *
	 * Fetched with the session's own token and handed to the browser as a blob rather than opened
	 * as a link: the export route is authenticated, and a plain <a href> carries no token.
	 */
	Door.download = function () {
		var App = Door.App;

		App.request( 'GET', '/events/' + Door.eventId + '/door-list/export' + Door.query(),
			null, { raw: true } )
			.then( function ( blob ) {
				var url = global.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = 'door-list.csv';
				document.body.appendChild( link );
				link.click();
				link.remove();
				global.URL.revokeObjectURL( url );
				App.toast( App.t( 'panel.doorList.exported' ) );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function tile( label, value ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
		'</div>';
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapDoorList = Door;
}( typeof window !== 'undefined' ? window : globalThis ) );
