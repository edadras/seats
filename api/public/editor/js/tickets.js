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
		App.loading( 'Tickets' );

		App.request( 'GET', '/events' )
			.then( function ( response ) {
				var events = response.data;

				if ( ! events.length ) {
					App.page( {
						title: 'Tickets',
						body: App.emptyState( 'ticket', 'No events yet',
							'Tickets appear here once an event has sold something.' ),
					} );

					return;
				}

				if ( ! Tickets.eventId || ! events.some( function ( e ) { return e.id === Tickets.eventId; } ) ) {
					Tickets.eventId = events[ 0 ].id;
				}

				App.page( {
					title: 'Tickets',
					description: 'Every ticket for one event. Search by name, email, seat or reference.',
					body:
						'<div class="filters" id="ticket-filters">' +
							'<select class="select filters__event" id="ticket-event" aria-label="Event">' +
							events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Tickets.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
							'</select>' +
							'<input class="input grow" id="ticket-search" type="search" ' +
							'placeholder="Name, email, seat or reference" value="' + esc( Tickets.query ) + '" ' +
							'aria-label="Search tickets">' +
							'<select class="select filters__status" id="ticket-status" aria-label="Status">' +
							[ [ '', 'Any status' ], [ 'issued', 'Not used' ], [ 'used', 'Checked in' ], [ 'void', 'Void' ] ]
								.map( function ( option ) {
									return '<option value="' + option[ 0 ] + '"' +
										( option[ 0 ] === Tickets.status ? ' selected' : '' ) + '>' +
										option[ 1 ] + '</option>';
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
						? badge( 'Checked in', 'ok' )
						: ( 'void' === ticket.status ? badge( 'Void', 'danger' ) : badge( 'Not used', 'neutral' ) );

					return '<tr><td class="table__primary">' + esc( seat || 'Standing' ) +
						( ticket.seat.quantity && ! seat ? ' × ' + esc( ticket.seat.quantity ) : '' ) + '</td>' +
						'<td>' + esc( ( ticket.order && ticket.order.buyer_name ) || ticket.holder_name || '—' ) +
						'<br><span class="muted">' +
						esc( ( ticket.order && ticket.order.buyer_email ) || '' ) + '</span></td>' +
						'<td><code>' + esc( ticket.token_prefix ) + '…</code></td>' +
						'<td>' + status +
						( ticket.used_at ? '<br><span class="muted tnum">' + esc( formatTime( ticket.used_at ) ) +
							'</span>' : '' ) + '</td>' +
						'<td><code>' + esc( ( ticket.order && ticket.order.reference ) || '—' ) + '</code></td>' +
						'<td class="table__actions">' +
						( 'issued' === ticket.status
							? '<button class="btn btn--sm btn--danger" data-release="' + esc( ticket.id ) +
								'">Release seat</button>'
							: '' ) +
						'</td></tr>';
				} ).join( '' );

				host.innerHTML = App.table(
					[ 'Seat', 'Booked by', 'Code', 'Status', 'Order', '' ],
					rows,
					App.emptyState( 'search', 'Nothing found',
						Tickets.query
							? 'No ticket matches that. Try part of a name, or the seat.'
							: 'Nothing has been sold for this event yet.' )
				) + ( response.meta && response.meta.total
					? '<p class="hint spaced">' + response.data.length + ' of ' + response.meta.total + ' shown.</p>'
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
			title: 'Release this seat?',
			submitLabel: 'Release',
			body: '<p>The ticket is voided and the seat goes back on sale straight away. The booking ' +
				'is recorded as refunded for that seat — settle the money in whatever took the payment.</p>',
			onSubmit: function () {
				return App.request( 'POST', '/tickets/' + ticketId + '/release', {} )
					.then( function () {
						App.toast( 'Seat released.' );
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

	function formatTime( value ) {
		var date = new Date( value );

		return isNaN( date.getTime() ) ? '' : date.toLocaleString( undefined, {
			day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
		} );
	}

	global.SeatmapTickets = Tickets;
} )( typeof window !== 'undefined' ? window : globalThis );
