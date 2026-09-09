/**
 * Ticket management: find a booking, see whether its holder has come in, put a seat back on sale.
 *
 * Built around the thing a box office actually does — someone arrives saying they booked, and
 * staff have to find them from whatever they can remember. So one search box across name, email,
 * seat, reference and the visible part of the code, and nothing else in the way.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Tickets = { eventId: null, query: '', status: '' };

	Tickets.render = function ( App ) {
		App.loading( App.t( 'panel.tickets.title' ) );

		App.request( 'GET', '/events' )
			.then( function ( response ) {
				var events = response.data;

				if ( ! events.length ) {
					App.page( {
						title: App.t( 'panel.tickets.title' ),
						body: App.emptyState( 'ticket', App.t( 'panel.tickets.noEventsTitle' ),
							esc( App.t( 'panel.tickets.noEventsBody' ) ) ),
					} );

					return;
				}

				if ( ! Tickets.eventId || ! events.some( function ( e ) { return e.id === Tickets.eventId; } ) ) {
					Tickets.eventId = events[ 0 ].id;
				}

				App.page( {
					title: App.t( 'panel.tickets.title' ),
					description: esc( App.t( 'panel.tickets.description' ) ),
					body:
						'<div class="filters" id="ticket-filters">' +
							'<select class="select filters__event" id="ticket-event" aria-label="' +
							esc( App.t( 'panel.tickets.event' ) ) + '">' +
							events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Tickets.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
							'</select>' +
							'<input class="input grow" id="ticket-search" type="search" ' +
							'placeholder="' + esc( App.t( 'panel.tickets.searchPlaceholder' ) ) + '" ' +
							'value="' + esc( Tickets.query ) + '" ' +
							'aria-label="' + esc( App.t( 'panel.tickets.searchLabel' ) ) + '">' +
							'<select class="select filters__status" id="ticket-status" aria-label="' +
							esc( App.t( 'panel.common.status' ) ) + '">' +
							[ [ '', 'anyStatus' ], [ 'issued', 'notUsed' ], [ 'used', 'checkedIn' ],
								[ 'void', 'void' ] ]
								.map( function ( option ) {
									return '<option value="' + option[ 0 ] + '"' +
										( option[ 0 ] === Tickets.status ? ' selected' : '' ) + '>' +
										esc( App.t( 'panel.tickets.' + option[ 1 ] ) ) + '</option>';
								} ).join( '' ) +
							'</select>' +
						'</div>' +
						'<div id="ticket-results" class="spaced"></div>',
				} );

				document.getElementById( 'ticket-event' ).addEventListener( 'change', function ( event ) {
					Tickets.eventId = event.target.value;
					Tickets.load( App );
				} );

				document.getElementById( 'ticket-status' ).addEventListener( 'change', function ( event ) {
					Tickets.status = event.target.value;
					Tickets.load( App );
				} );

				var search = document.getElementById( 'ticket-search' );

				search.addEventListener( 'input', function () {
					Tickets.query = search.value;

					window.clearTimeout( Tickets._timer );
					Tickets._timer = window.setTimeout( function () { Tickets.load( App ); }, 300 );
				} );

				Tickets.load( App );
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Tickets.load = function ( App ) {
		var host = document.getElementById( 'ticket-results' );

		if ( ! host ) {
			return;
		}

		var query = '/tickets?event_id=' + encodeURIComponent( Tickets.eventId ) +
			( Tickets.query ? '&q=' + encodeURIComponent( Tickets.query ) : '' ) +
			( Tickets.status ? '&status=' + encodeURIComponent( Tickets.status ) : '' );

		App.request( 'GET', query )
			.then( function ( response ) {
				var rows = response.data.map( function ( ticket ) {
					var seat = [ ticket.seat.section, ticket.seat.row, ticket.seat.label ]
						.filter( Boolean ).join( ' · ' );

					var status = 'used' === ticket.status
						? badge( App.t( 'panel.tickets.checkedIn' ), 'ok' )
						: ( 'void' === ticket.status
							? badge( App.t( 'panel.tickets.void' ), 'danger' )
							: badge( App.t( 'panel.tickets.notUsed' ), 'neutral' ) );

					return '<tr><td class="table__primary">' +
						esc( seat || App.t( 'panel.tickets.standing' ) ) +
						( ticket.seat.quantity && ! seat
							? ' × ' + esc( App.number( ticket.seat.quantity ) )
							: '' ) + '</td>' +
						'<td>' + esc( ( ticket.order && ticket.order.buyer_name ) || ticket.holder_name || '—' ) +
						'<br><span class="muted">' +
						esc( ( ticket.order && ticket.order.buyer_email ) || '' ) + '</span></td>' +
						'<td><code>' + esc( ticket.token_prefix ) + '…</code></td>' +
						'<td>' + status +
						( ticket.used_at
							? '<br><span class="muted tnum">' + esc( App.date( ticket.used_at ) ) + '</span>'
							: '' ) + '</td>' +
						'<td><code>' + esc( ( ticket.order && ticket.order.reference ) || '—' ) + '</code></td>' +
						'<td class="table__actions">' +
						( 'issued' === ticket.status
							? '<button class="btn btn--sm btn--danger" data-release="' + esc( ticket.id ) +
								'">' + esc( App.t( 'panel.tickets.release' ) ) + '</button>'
							: '' ) +
						'</td></tr>';
				} ).join( '' );

				host.innerHTML = App.table(
					[
						App.t( 'panel.tickets.seat' ),
						App.t( 'panel.tickets.bookedBy' ),
						App.t( 'panel.tickets.code' ),
						App.t( 'panel.common.status' ),
						App.t( 'panel.tickets.order' ),
						'',
					],
					rows,
					App.emptyState( 'search', App.t( 'panel.tickets.nothingFound' ),
						esc( App.t( Tickets.query
							? 'panel.tickets.noMatch'
							: 'panel.tickets.nothingSold' ) ) )
				) + ( response.meta && response.meta.total
					? '<p class="hint spaced">' + esc( App.t( 'panel.tickets.shown', {
						count: App.number( response.data.length ),
						total: App.number( response.meta.total ),
					} ) ) + '</p>'
					: '' );

				host.querySelectorAll( '[data-release]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						Tickets.release( App, button.dataset.release );
					} );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Tickets.release = function ( App, ticketId ) {
		App.modal( {
			title: App.t( 'panel.tickets.releaseTitle' ),
			submitLabel: App.t( 'panel.tickets.releaseSubmit' ),
			body: '<p>' + esc( App.t( 'panel.tickets.releaseBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/tickets/' + ticketId + '/release', {} )
					.then( function () {
						App.toast( App.t( 'panel.tickets.released' ) );
						Tickets.load( App );
					} );
			},
		} );
	};

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value == null ? '' : value );

		return element.innerHTML;
	}

	function badge( text, tone ) {
		return '<span class="badge badge--' + tone + '">' + esc( text ) + '</span>';
	}

	global.SeatmapTickets = Tickets;
} )( typeof window !== 'undefined' ? window : globalThis );
