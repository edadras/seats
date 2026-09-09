/**
 * The counter: selling to the person standing in front of you.
 *
 * A window sale is not a slower version of a website purchase. There is no cart, no session and no
 * gateway — the clerk knows which seats, knows the name, and knows whether money has changed hands.
 * So this is one screen: choose an event, choose seats, say who and how, done.
 *
 * Seats already sold or held are shown greyed rather than hidden, because a clerk asked for "A5"
 * needs to be told it has gone, not left to wonder why it is missing.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Counter = {
		App: null,
		events: [],
		eventId: '',
		hall: null,
		section: null,
		selected: {},   // seat id -> { label, amount }
		areas: {},      // capacity object id -> quantity
		types: {},      // seat id -> ticket type id
	};

	Counter.render = function ( App ) {
		Counter.App = App;
		App.loading( App.t( 'panel.boxOffice.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Counter.events = ( response.data || [] ).filter( function ( event ) {
					return 'published' === event.status;
				} );

				Counter.paint();

				if ( Counter.events.length ) {
					Counter.choose( Counter.events[ 0 ].id );
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Counter.paint = function () {
		var App = Counter.App;

		App.page( {
			title: App.t( 'panel.boxOffice.title' ),
			description: esc( App.t( 'panel.boxOffice.description' ) ),
			body:
				( Counter.events.length
					? '<div class="filters">' +
						'<select class="select grow" id="counter-event" aria-label="' +
							esc( App.t( 'panel.boxOffice.event' ) ) + '">' +
							Counter.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Counter.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.boxOffice.noEventsTitle' ),
						esc( App.t( 'panel.boxOffice.noEventsBody' ) ) ) ) +
				'<div id="counter-hall" class="spaced"></div>',
		} );

		var picker = document.getElementById( 'counter-event' );

		if ( picker ) {
			picker.addEventListener( 'change', function () { Counter.choose( picker.value ); } );
		}
	};

	Counter.choose = function ( eventId ) {
		var App = Counter.App;

		Counter.eventId = eventId;
		Counter.section = null;
		Counter.selected = {};
		Counter.areas = {};
		Counter.types = {};

		App.request( 'GET', '/events/' + eventId + '/counter' )
			.then( function ( hall ) {
				Counter.hall = hall;
				Counter.paintHall();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ the hall */

	Counter.paintHall = function () {
		var App = Counter.App;
		var host = document.getElementById( 'counter-hall' );
		var hall = Counter.hall;

		if ( ! host || ! hall ) {
			return;
		}

		host.innerHTML =
			'<div class="counter">' +
				'<div class="counter__hall">' +
					// `null !==`, not a truth test: the first section is index 0, and a falsy check
					// would send a clerk who clicked it straight back to the list of sections.
					( null !== Counter.section
						? Counter.sectionMarkup( App )
						: Counter.blocksMarkup( App ) ) +
					Counter.areasMarkup( App ) +
				'</div>' +
				'<aside class="counter__basket">' + Counter.basketMarkup( App ) + '</aside>' +
			'</div>';

		Counter.bindHall();
	};

	Counter.blocksMarkup = function ( App ) {
		var hall = Counter.hall;

		if ( ! hall.sections.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.boxOffice.pickSection' ) ) + '</h3>' +
			'<div class="counter__blocks">' +
				hall.sections.map( function ( section, index ) {
					var free = 0;

					section.rows.forEach( function ( row ) {
						row.seats.forEach( function ( seat ) {
							if ( 'available' === seat.state ) {
								free++;
							}
						} );
					} );

					return '<button class="counter__block" data-section="' + index + '"' +
						( free ? '' : ' disabled' ) + '>' +
						'<span class="counter__block-name">' + esc( section.name ) + '</span>' +
						'<span class="counter__block-free">' +
							esc( free
								? App.t( 'panel.boxOffice.freeSeats', { count: App.number( free ) } )
								: App.t( 'panel.boxOffice.sectionFull' ) ) +
						'</span>' +
					'</button>';
				} ).join( '' ) +
			'</div>';
	};

	Counter.sectionMarkup = function ( App ) {
		var section = Counter.hall.sections[ Counter.section ];

		return '<div class="row row--wrap spaced">' +
				'<button class="btn" id="counter-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.boxOffice.allSections' ) ) + '</button>' +
				'<strong>' + esc( section.name ) + '</strong>' +
			'</div>' +
			'<div class="counter__rows">' +
				section.rows.map( function ( row ) {
					return '<div class="counter__row">' +
						'<span class="counter__row-name">' + esc( row.name ) + '</span>' +
						row.seats.map( function ( seat ) {
							var free = 'available' === seat.state;
							var chosen = !! Counter.selected[ seat.id ];

							return '<button class="counter__seat' +
								( chosen ? ' is-chosen' : '' ) +
								( free ? '' : ' is-gone' ) + '"' +
								( free ? '' : ' disabled' ) +
								' data-seat="' + esc( seat.id ) + '"' +
								' data-amount="' + esc( seat.amount === null ? '' : seat.amount ) + '"' +
								' data-label="' + esc( [ section.name, row.name, seat.label ].join( ' · ' ) ) + '"' +
								' title="' + esc( seat.label ) + '">' +
								esc( seat.label ) +
							'</button>';
						} ).join( '' ) +
					'</div>';
				} ).join( '' ) +
			'</div>';
	};

	Counter.areasMarkup = function ( App ) {
		var areas = ( Counter.hall.areas || [] ).filter( function ( area ) {
			return area.places > 0;
		} );

		if ( ! areas.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.boxOffice.standing' ) ) + '</h3>' +
			areas.map( function ( area ) {
				var held = Counter.areas[ area.capacity_object_id ] || 0;

				return '<div class="counter__area">' +
					'<span>' + esc( area.label ) +
						'<span class="muted on-own-line">' +
							esc( App.t( 'panel.boxOffice.placesLeft', {
								count: App.number( area.remaining ),
							} ) ) + '</span>' +
					'</span>' +
					'<span class="row">' +
						'<button class="btn btn--sm" data-area-minus="' + esc( area.capacity_object_id ) + '"' +
							( held ? '' : ' disabled' ) + '>−</button>' +
						'<output class="counter__count tnum">' + esc( App.number( held ) ) + '</output>' +
						'<button class="btn btn--sm" data-area-plus="' + esc( area.capacity_object_id ) + '"' +
							( held < area.remaining ? '' : ' disabled' ) + '>+</button>' +
					'</span>' +
				'</div>';
			} ).join( '' );
	};

	/* --------------------------------------------------------------------------- the basket */

	Counter.basketMarkup = function ( App ) {
		var hall = Counter.hall;
		var seatIds = Object.keys( Counter.selected );
		var lines = [];
		var total = 0;

		seatIds.forEach( function ( id ) {
			var seat = Counter.selected[ id ];
			var amount = Counter.priceOf( seat.amount, Counter.types[ id ] );

			total += amount;
			lines.push( { key: id, label: seat.label, amount: amount, seat: true } );
		} );

		( hall.areas || [] ).forEach( function ( area ) {
			var held = Counter.areas[ area.capacity_object_id ] || 0;

			if ( held ) {
				total += ( area.amount || 0 ) * held;
				lines.push( {
					key: area.capacity_object_id,
					label: App.number( held ) + ' × ' + area.label,
					amount: ( area.amount || 0 ) * held,
				} );
			}
		} );

		if ( ! lines.length ) {
			return '<p class="hint">' + esc( App.t( 'panel.boxOffice.nothingChosen' ) ) + '</p>';
		}

		var types = hall.ticket_types || [];

		return '<h3 class="subhead">' + esc( App.t( 'panel.boxOffice.basket' ) ) + '</h3>' +
			'<ul class="counter__lines">' +
				lines.map( function ( line ) {
					return '<li><span>' + esc( line.label ) +
						( line.seat && types.length > 1
							? '<select class="select select--sm" data-seat-type="' + esc( line.key ) + '">' +
								types.map( function ( type ) {
									return '<option value="' + esc( type.id ) + '"' +
										( type.id === Counter.types[ line.key ] ? ' selected' : '' ) + '>' +
										esc( type.name ) + '</option>';
								} ).join( '' ) +
							'</select>'
							: '' ) +
					'</span>' +
					'<span class="tnum">' + esc( App.money( line.amount, hall.currency ) ) + '</span></li>';
				} ).join( '' ) +
			'</ul>' +
			'<p class="counter__total"><span>' + esc( App.t( 'panel.boxOffice.total' ) ) + '</span>' +
				'<span class="tnum">' + esc( App.money( total, hall.currency ) ) + '</span></p>' +
			'<button class="btn btn--primary btn--block" id="counter-sell">' +
				esc( App.t( 'panel.boxOffice.sell' ) ) + '</button>';
	};

	/** The same arithmetic as the picker and the server; see App\Models\TicketType::priceFrom. */
	Counter.priceOf = function ( base, typeId ) {
		var type = ( Counter.hall.ticket_types || [] ).filter( function ( entry ) {
			return entry.id === typeId;
		} )[ 0 ];

		base = base || 0;

		if ( ! type ) {
			return base;
		}

		if ( 'fixed' === type.kind ) {
			return Math.max( 0, type.value || 0 );
		}

		var amount = base;

		if ( 'percent_off' === type.kind ) {
			amount = base - Math.floor( ( base * Math.max( 0, Math.min( 100, type.value || 0 ) ) ) / 100 );
		} else if ( 'amount_off' === type.kind ) {
			amount = base - Math.max( 0, type.value || 0 );
		}

		return Math.max( 0, Math.min( base, amount ) );
	};

	Counter.bindHall = function () {
		var App = Counter.App;

		each( '[data-section]', function ( button ) {
			button.addEventListener( 'click', function () {
				Counter.section = Number( button.dataset.section );
				Counter.paintHall();
			} );
		} );

		bind( 'counter-back', function () {
			Counter.section = null;
			Counter.paintHall();
		} );

		each( '[data-seat]', function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.dataset.seat;

				if ( Counter.selected[ id ] ) {
					delete Counter.selected[ id ];
					delete Counter.types[ id ];
				} else {
					Counter.selected[ id ] = {
						label: button.dataset.label,
						amount: '' === button.dataset.amount ? 0 : Number( button.dataset.amount ),
					};

					var fallback = ( Counter.hall.ticket_types || [] ).filter( function ( type ) {
						return type.is_default;
					} )[ 0 ];

					if ( fallback ) {
						Counter.types[ id ] = fallback.id;
					}
				}

				Counter.paintHall();
			} );
		} );

		each( '[data-area-plus]', function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.dataset.areaPlus;

				Counter.areas[ id ] = ( Counter.areas[ id ] || 0 ) + 1;
				Counter.paintHall();
			} );
		} );

		each( '[data-area-minus]', function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.dataset.areaMinus;

				Counter.areas[ id ] = Math.max( 0, ( Counter.areas[ id ] || 0 ) - 1 );
				Counter.paintHall();
			} );
		} );

		each( '[data-seat-type]', function ( select ) {
			select.addEventListener( 'change', function () {
				Counter.types[ select.dataset.seatType ] = select.value;
				Counter.paintHall();
			} );
		} );

		bind( 'counter-sell', function () { Counter.sell( App ); } );
	};

	/* ------------------------------------------------------------------------------ the sale */

	Counter.sell = function ( App ) {
		App.modal( {
			title: App.t( 'panel.boxOffice.sellTitle' ),
			submitLabel: App.t( 'panel.boxOffice.sell' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="c-name">' +
					esc( App.t( 'panel.boxOffice.buyerName' ) ) + '</label>' +
					'<input class="input" id="c-name" maxlength="120" required></div>' +
				'<div class="field"><label class="field__label" for="c-email">' +
					esc( App.t( 'panel.boxOffice.buyerEmail' ) ) + '</label>' +
					'<input class="input" id="c-email" type="email" maxlength="190">' +
					'<span class="field__hint">' + esc( App.t( 'panel.boxOffice.buyerEmailHint' ) ) +
					'</span></div>' +
				'<div class="field"><label class="field__label" for="c-payment">' +
					esc( App.t( 'panel.boxOffice.payment' ) ) + '</label>' +
					'<select class="select" id="c-payment">' +
						[ 'paid', 'owed', 'comp' ].map( function ( kind ) {
							return '<option value="' + kind + '">' +
								esc( App.t( 'panel.boxOffice.payments.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
				'<div class="field"><label class="field__label" for="c-note">' +
					esc( App.t( 'panel.boxOffice.note' ) ) + '</label>' +
					'<input class="input" id="c-note" maxlength="200"></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="c-send">' +
					'<span>' + esc( App.t( 'panel.boxOffice.sendTickets' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var name = document.getElementById( 'c-name' ).value.trim();

				if ( ! name ) {
					App.toast( App.t( 'panel.boxOffice.needName' ), true );

					return true;
				}

				var payload = {
					seat_ids: Object.keys( Counter.selected ),
					areas: Counter.areas,
					seat_types: Counter.types,
					buyer: {
						name: name,
						email: document.getElementById( 'c-email' ).value.trim() || null,
					},
					payment: document.getElementById( 'c-payment' ).value,
					note: document.getElementById( 'c-note' ).value.trim() || null,
					send_tickets: document.getElementById( 'c-send' ).checked,
				};

				return App.request( 'POST', '/events/' + Counter.eventId + '/sell', payload )
					.then( function ( sale ) {
						App.toast( App.t( 'panel.boxOffice.sold', { reference: sale.reference } ) );
						Counter.choose( Counter.eventId );
					} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapCounter = Counter;
}( typeof window !== 'undefined' ? window : globalThis ) );
