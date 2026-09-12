/**
 * Prices for individual seats.
 *
 * A section called VIP is a name, not a price. The two seats behind the pillar are not worth what
 * the front row is, four in the middle of row A are worth more, and the house seats are never sold
 * at all. So this screen goes the other way round from the price list: sections first, as blocks,
 * then into one block and its actual chairs.
 *
 * Nothing is sent until Save, and only the seats that were touched are sent. A hall has twenty
 * thousand seats and a repricing usually touches eight; sending all twenty thousand to move eight
 * is a way to lose the other 19,992 to a dropped connection.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Seats = {
		App: null,
		eventId: null,
		eventName: '',
		currency: 'EUR',
		zones: [],
		sections: [],
		section: null,   // the block we are inside, or null at the overview
		selected: {},    // seat id -> true
		staged: {},      // seat id -> what it should become
		lastClicked: null,
	};

	Seats.open = function ( App, eventId, eventName ) {
		Seats.App = App;
		Seats.eventId = eventId;
		Seats.eventName = eventName || '';
		Seats.section = null;
		Seats.selected = {};
		Seats.staged = {};

		App.loading( App.t( 'pricing.seats.title' ) );

		App.request( 'GET', '/events/' + eventId + '/seat-prices' ).then( function ( body ) {
			Seats.currency = body.currency || 'EUR';
			Seats.zones = body.zones || [];
			Seats.sections = body.sections || [];
			Seats.paint();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------ reading state */

	/** What a seat costs once everything staged in this session is taken into account. */
	Seats.state = function ( seat ) {
		var staged = Seats.staged[ seat.id ];

		if ( ! staged ) {
			return {
				amount: seat.amount,
				own: seat.own_amount,
				zoneKey: seat.zone_key,
				blocked: seat.blocked,
			held_for: seat.held_for,
				dirty: false,
			};
		}

		var zone = Seats.zone( staged.zone_key );

		return {
			amount: null !== staged.amount ? staged.amount
				: ( zone ? zone.amount : Seats.chartAmount( seat ) ),
			own: staged.amount,
			zoneKey: staged.zone_key || seat.chart_zone_key,
			blocked: staged.blocked,
			held_for: staged.held_for,
			dirty: true,
		};
	};

	/** What the chart alone would charge for this seat — the zone the designer put it in. */
	Seats.chartAmount = function ( seat ) {
		var zone = Seats.zone( seat.chart_zone_key );

		return zone ? zone.amount : null;
	};

	Seats.zone = function ( key ) {
		for ( var i = 0; i < Seats.zones.length; i++ ) {
			if ( Seats.zones[ i ].key === key ) {
				return Seats.zones[ i ];
			}
		}

		return null;
	};

	Seats.seatsOf = function ( section ) {
		return section.rows.reduce( function ( all, row ) { return all.concat( row.seats ); }, [] );
	};

	Seats.money = function ( amount ) {
		return null === amount || undefined === amount
			? Seats.App.t( 'pricing.seats.unpriced' )
			: Seats.App.money( amount, Seats.currency );
	};

	/* ------------------------------------------------------------------------------ painting */

	Seats.paint = function () {
		var App = Seats.App;
		var dirty = Object.keys( Seats.staged ).length;

		App.page( {
			title: App.t( 'pricing.seats.title' ),
			description: App.t( 'pricing.seats.subtitle' ),
			actions:
				'<button class="btn" id="seats-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'pricing.back' ) ) + '</button>' +
				'<button class="btn btn--primary" id="seats-save"' + ( dirty ? '' : ' disabled' ) + '>' +
					esc( dirty
						? App.t( 'pricing.seats.saveCount', { count: App.number( dirty ) } )
						: App.t( 'pricing.seats.save' ) ) + '</button>',
			body: Seats.section ? Seats.sectionMarkup() : Seats.overviewMarkup(),
		} );

		Seats.bind();
	};

	/** The hall as blocks: how a section is chosen before any chair is. */
	Seats.overviewMarkup = function () {
		var App = Seats.App;

		if ( ! Seats.sections.length ) {
			// A section is drawn on the chart, which is where this one has to be answered.
			return App.emptyState( 'map', App.t( 'pricing.seats.noSections' ),
				App.t( 'pricing.seats.noSectionsHint' ), App.goesTo( 'maps' ) );
		}

		return '<div class="blocks-grid">' + Seats.sections.map( function ( section ) {
			var seats = Seats.seatsOf( section );
			var amounts = [];
			var own = 0;
			var blocked = 0;

			seats.forEach( function ( seat ) {
				var state = Seats.state( seat );

				if ( null !== state.amount && undefined !== state.amount ) {
					amounts.push( state.amount );
				}

				if ( null !== state.own && undefined !== state.own ) {
					own++;
				}

				if ( state.blocked ) {
					blocked++;
				}
			} );

			var low = amounts.length ? Math.min.apply( null, amounts ) : null;
			var high = amounts.length ? Math.max.apply( null, amounts ) : null;

			return '<button class="block-card" data-section="' + esc( section.key ) + '">' +
				'<span class="block-card__bar" style="background:' +
					esc( section.color || 'var(--accent)' ) + '"></span>' +
				'<span class="block-card__name">' + esc( section.name ) + '</span>' +
				'<span class="block-card__price tnum">' +
					esc( low === high ? Seats.money( low ) : Seats.money( low ) + ' – ' + Seats.money( high ) ) +
				'</span>' +
				'<span class="block-card__meta">' +
					esc( App.t( 'pricing.seats.seatCount', { count: App.number( seats.length ) } ) ) +
					( own ? ' · ' + esc( App.t( 'pricing.seats.ownPrices', { count: App.number( own ) } ) ) : '' ) +
					( blocked ? ' · ' + esc( App.t( 'pricing.seats.blockedCount', { count: App.number( blocked ) } ) ) : '' ) +
				'</span>' +
			'</button>';
		} ).join( '' ) + '</div>';
	};

	/** One block, opened: its rows and its chairs. */
	Seats.sectionMarkup = function () {
		var App = Seats.App;
		var section = Seats.section;
		var chosen = Object.keys( Seats.selected ).length;

		var rows = section.rows.map( function ( row ) {
			return '<div class="seat-row">' +
				'<button class="seat-row__name" data-row="' + esc( row.key ) + '" ' +
					'title="' + esc( App.t( 'pricing.seats.selectRow' ) ) + '">' +
					esc( row.name ) + '</button>' +
				'<div class="seat-row__seats">' +
					row.seats.map( function ( seat ) { return Seats.chip( seat, row ); } ).join( '' ) +
				'</div>' +
			'</div>';
		} ).join( '' );

		return '<div class="seats-head">' +
				'<button class="btn btn--sm" id="seats-all-blocks">' + icon( 'back', { size: 14 } ) +
					esc( App.t( 'pricing.seats.backToBlocks' ) ) + '</button>' +
				'<h3 class="seats-head__title">' + esc( section.name ) + '</h3>' +
				'<button class="btn btn--sm" id="seats-select-section">' +
					esc( App.t( 'pricing.seats.selectSection' ) ) + '</button>' +
			'</div>' +

			'<div class="legend">' +
				'<span class="legend__item"><span class="chip-key chip-key--own"></span>' +
					esc( App.t( 'pricing.seats.legendOwn' ) ) + '</span>' +
				'<span class="legend__item"><span class="chip-key chip-key--blocked"></span>' +
					esc( App.t( 'pricing.seats.legendBlocked' ) ) + '</span>' +
				'<span class="legend__item"><span class="chip-key chip-key--sold"></span>' +
					esc( App.t( 'pricing.seats.legendSold' ) ) + '</span>' +
			'</div>' +

			'<div class="seat-rows">' + rows + '</div>' +

			'<div class="seat-bar' + ( chosen ? ' is-open' : '' ) + '" id="seat-bar">' +
				'<span class="seat-bar__count tnum">' +
					esc( App.t( 'pricing.seats.selected', { count: App.number( chosen ) } ) ) + '</span>' +
				'<input class="input input--amount tnum" id="seat-amount" type="number" min="0" ' +
					'step="' + ( global.SeatmapI18n.currencyDecimals( Seats.currency )
						? Math.pow( 10, -global.SeatmapI18n.currencyDecimals( Seats.currency ) )
							.toFixed( global.SeatmapI18n.currencyDecimals( Seats.currency ) )
						: '1' ) + '" ' +
					'placeholder="' + esc( App.t( 'pricing.seats.amount' ) ) + '" ' +
					'aria-label="' + esc( App.t( 'pricing.seats.amount' ) ) + '">' +
				'<button class="btn btn--primary btn--sm" id="seat-apply">' +
					esc( App.t( 'pricing.seats.apply' ) ) + '</button>' +
				'<select class="select select--sm" id="seat-zone" aria-label="' +
					esc( App.t( 'pricing.seats.useZone' ) ) + '">' +
					'<option value="">' + esc( App.t( 'pricing.seats.useZone' ) ) + '</option>' +
					Seats.zones.map( function ( zone ) {
						return '<option value="' + esc( zone.key ) + '">' + esc( zone.name ) + ' — ' +
							esc( Seats.money( zone.amount ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
				'<button class="btn btn--sm" id="seat-block">' +
					esc( App.t( 'pricing.seats.block' ) ) + '</button>' +
				/*
				 * A house seat: off public sale, and sellable at the counter on the night.
				 *
				 * Beside "block" rather than instead of it, because they are different promises.
				 * A blocked seat is one nobody may have — a sightline, a camera position. A house
				 * seat is one the venue is keeping for somebody, and the box office can hand it
				 * over. The label is what tells the two apart, here and everywhere else.
				 */
				'<button class="btn btn--sm" id="seat-house">' +
					esc( App.t( 'pricing.seats.holdBack' ) ) + '</button>' +
				'<button class="btn btn--sm" id="seat-unblock">' +
					esc( App.t( 'pricing.seats.unblock' ) ) + '</button>' +
				'<button class="btn btn--sm" id="seat-reset">' +
					esc( App.t( 'pricing.seats.reset' ) ) + '</button>' +
				'<button class="btn btn--sm" id="seat-clear">' +
					esc( App.t( 'pricing.seats.clearSelection' ) ) + '</button>' +
			'</div>';
	};

	Seats.chip = function ( seat, row ) {
		var state = Seats.state( seat );
		var classes = [ 'chip' ];

		if ( Seats.selected[ seat.id ] ) {
			classes.push( 'is-on' );
		}

		if ( state.blocked ) {
			classes.push( state.held_for ? 'chip--house' : 'chip--blocked' );
		} else if ( null !== state.own && undefined !== state.own ) {
			classes.push( 'chip--own' );
		}

		if ( seat.sold ) {
			classes.push( 'chip--sold' );
		}

		if ( state.dirty ) {
			classes.push( 'is-dirty' );
		}

		// The price is on the chip's tooltip and in its accessible name, not printed inside it:
		// a hall of 2,000 chairs each showing "€45.00" is a wall of text nobody reads.
		var label = row.name + ' ' + seat.label + ' — ' +
			( state.blocked
				? ( state.held_for || Seats.App.t( 'pricing.seats.legendBlocked' ) )
				: Seats.money( state.amount ) );

		return '<button class="' + classes.join( ' ' ) + '" data-seat="' + esc( seat.id ) + '" ' +
			'data-row="' + esc( row.key ) + '" aria-pressed="' + ( Seats.selected[ seat.id ] ? 'true' : 'false' ) +
			'" title="' + esc( label ) + '" aria-label="' + esc( label ) + '">' + esc( seat.label ) + '</button>';
	};

	/* ------------------------------------------------------------------------------ binding */

	Seats.bind = function () {
		var App = Seats.App;

		document.getElementById( 'seats-back' ).addEventListener( 'click', function () {
			var leave = function () { global.SeatmapPricing.open( App, Seats.eventId ); };

			// Prices typed and not yet saved are worth one question — asked in the panel's own
			// dialog, since the browser's grey box may be suppressed and then guards nothing.
			if ( ! Object.keys( Seats.staged ).length ) {
				leave();

				return;
			}

			App.confirm( {
				title: App.t( 'pricing.seats.discardTitle' ),
				body: App.t( 'pricing.seats.discard' ),
				confirmLabel: App.t( 'pricing.seats.discardLeave' ),
				danger: true,
			}, leave );
		} );

		document.getElementById( 'seats-save' ).addEventListener( 'click', function () { Seats.save(); } );

		each( '[data-section]', function ( button ) {
			button.addEventListener( 'click', function () {
				Seats.section = Seats.sections.filter( function ( section ) {
					return section.key === button.dataset.section;
				} )[ 0 ];
				Seats.selected = {};
				Seats.paint();
			} );
		} );

		if ( ! Seats.section ) {
			return;
		}

		document.getElementById( 'seats-all-blocks' ).addEventListener( 'click', function () {
			Seats.section = null;
			Seats.selected = {};
			Seats.paint();
		} );

		document.getElementById( 'seats-select-section' ).addEventListener( 'click', function () {
			Seats.seatsOf( Seats.section ).forEach( function ( seat ) { Seats.selected[ seat.id ] = true; } );
			Seats.paint();
		} );

		each( '.seat-row__name', function ( button ) {
			button.addEventListener( 'click', function () {
				Seats.rowByKey( button.dataset.row ).seats.forEach( function ( seat ) {
					Seats.selected[ seat.id ] = true;
				} );
				Seats.paint();
			} );
		} );

		each( '[data-seat]', function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				// Shift extends from the last chip clicked, within its row — "12 to 18" is how a
				// box office speaks, and clicking seven chairs one at a time is how mistakes happen.
				if ( event.shiftKey && Seats.lastClicked ) {
					Seats.selectRange( Seats.lastClicked, button.dataset.seat );
				} else {
					if ( Seats.selected[ button.dataset.seat ] ) {
						delete Seats.selected[ button.dataset.seat ];
					} else {
						Seats.selected[ button.dataset.seat ] = true;
					}

					Seats.lastClicked = button.dataset.seat;
				}

				Seats.paint();
			} );
		} );

		bindClick( 'seat-apply', function () {
			var field = document.getElementById( 'seat-amount' );
			var decimals = global.SeatmapI18n.currencyDecimals( Seats.currency );

			if ( '' === field.value.trim() ) {
				App.toast( App.t( 'pricing.seats.needAmount' ), true );

				return;
			}

			Seats.stage( function ( current ) {
				return {
					amount: Math.round( Number( field.value ) * Math.pow( 10, decimals ) ),
					zone_key: null,
					blocked: current.blocked,
					held_for: current.held_for,
					note: current.note,
				};
			} );
		} );

		bindClick( 'seat-block', function () {
			Seats.stage( function ( current ) {
				return {
					amount: current.amount,
					zone_key: current.zone_key,
					blocked: true,
					// Blocked outright: nobody may have it, not even the counter.
					held_for: null,
					note: current.note,
				};
			} );
		} );

		bindClick( 'seat-house', function () {
			App.modal( {
				title: App.t( 'pricing.seats.holdBackTitle' ),
				submitLabel: App.t( 'pricing.seats.holdBack' ),
				body:
					'<p>' + esc( App.t( 'pricing.seats.holdBackBody' ) ) + '</p>' +
					'<div class="field">' +
						'<label class="field__label" for="seat-held-for">' +
							esc( App.t( 'pricing.seats.heldFor' ) ) + '</label>' +
						'<input class="input" id="seat-held-for" maxlength="60" required ' +
							'placeholder="' + esc( App.t( 'pricing.seats.heldForPlaceholder' ) ) + '">' +
						'<span class="field__hint">' +
							esc( App.t( 'pricing.seats.heldForHint' ) ) + '</span>' +
					'</div>',
				onSubmit: function () {
					var label = String( document.getElementById( 'seat-held-for' ).value ).trim();

					if ( ! label ) {
						App.toast( App.t( 'pricing.seats.heldForNeeded' ), true );

						return Promise.reject( new Error( 'no label' ) );
					}

					Seats.stage( function ( current ) {
						return {
							amount: current.amount,
							zone_key: current.zone_key,
							// A house seat is a blocked seat with a label. Both, always: the
							// database refuses a label without the block, and rightly.
							blocked: true,
							held_for: label,
							note: current.note,
						};
					} );

					return Promise.resolve();
				},
			} );
		} );

		bindClick( 'seat-unblock', function () {
			Seats.stage( function ( current ) {
				// Back on public sale, and no longer kept for anybody.
				return {
					amount: current.amount,
					zone_key: current.zone_key,
					blocked: false,
					held_for: null,
					note: current.note,
				};
			} );
		} );

		bindClick( 'seat-reset', function () {
			Seats.stage( function () {
				return { amount: null, zone_key: null, blocked: false, held_for: null, note: null };
			} );
		} );

		bindClick( 'seat-clear', function () {
			Seats.selected = {};
			Seats.paint();
		} );

		var zoneField = document.getElementById( 'seat-zone' );

		if ( zoneField ) {
			zoneField.addEventListener( 'change', function () {
				if ( ! zoneField.value ) {
					return;
				}

				Seats.stage( function ( current ) {
					return {
						amount: null,
						zone_key: zoneField.value,
						blocked: current.blocked,
						held_for: current.held_for,
						note: current.note,
					};
				} );
			} );
		}
	};

	Seats.rowByKey = function ( key ) {
		return Seats.section.rows.filter( function ( row ) { return row.key === key; } )[ 0 ];
	};

	Seats.selectRange = function ( fromId, toId ) {
		Seats.section.rows.forEach( function ( row ) {
			var ids = row.seats.map( function ( seat ) { return seat.id; } );
			var from = ids.indexOf( fromId );
			var to = ids.indexOf( toId );

			// Only within one row: a rectangle across rows is a different gesture, and guessing
			// which one somebody meant is how twenty seats get repriced by accident.
			if ( from < 0 || to < 0 ) {
				return;
			}

			for ( var i = Math.min( from, to ); i <= Math.max( from, to ); i++ ) {
				Seats.selected[ ids[ i ] ] = true;
			}
		} );
	};

	/**
	 * Record what the chosen seats should become.
	 *
	 * Staged, not sent: somebody pricing a hall makes twenty of these decisions in a row, and a
	 * request per click is twenty chances to end up half applied.
	 */
	Seats.stage = function ( decide ) {
		var touched = 0;

		Seats.eachSelected( function ( seat ) {
			var current = Seats.staged[ seat.id ] || {
				amount: seat.own_amount,
				zone_key: seat.own_amount === null && seat.zone_key !== seat.chart_zone_key ? seat.zone_key : null,
				blocked: seat.blocked,
			held_for: seat.held_for,
				note: seat.note,
			};

			Seats.staged[ seat.id ] = decide( current );
			touched++;
		} );

		if ( ! touched ) {
			Seats.App.toast( Seats.App.t( 'pricing.seats.needSelection' ), true );

			return;
		}

		Seats.paint();
	};

	Seats.eachSelected = function ( visit ) {
		Seats.sections.forEach( function ( section ) {
			Seats.seatsOf( section ).forEach( function ( seat ) {
				if ( Seats.selected[ seat.id ] ) {
					visit( seat );
				}
			} );
		} );
	};

	Seats.save = function () {
		var App = Seats.App;
		var ids = Object.keys( Seats.staged );

		if ( ! ids.length ) {
			App.toast( App.t( 'pricing.seats.nothingToSave' ), true );

			return;
		}

		var sold = 0;

		Seats.sections.forEach( function ( section ) {
			Seats.seatsOf( section ).forEach( function ( seat ) {
				if ( Seats.staged[ seat.id ] && seat.sold ) {
					sold++;
				}
			} );
		} );

		App.request( 'PUT', '/events/' + Seats.eventId + '/seat-prices', {
			seats: ids.map( function ( id ) {
				return {
					seat_id: id,
					amount: Seats.staged[ id ].amount,
					zone_key: Seats.staged[ id ].zone_key,
					blocked: !! Seats.staged[ id ].blocked,
					held_for: Seats.staged[ id ].held_for || null,
					note: Seats.staged[ id ].note || null,
				};
			} ),
		} ).then( function () {
			App.toast( App.t( 'pricing.seats.saved', { count: App.number( ids.length ) } ) );

			if ( sold ) {
				// Said plainly, because the alternative is somebody believing they have just
				// changed what a ticket already sold is worth.
				App.toast( App.t( 'pricing.seats.soldWarning', { count: App.number( sold ) } ), true );
			}

			var sectionKey = Seats.section ? Seats.section.key : null;

			Seats.open( App, Seats.eventId, Seats.eventName );

			// Reopening refetches, which is the point — the server is the answer about what a seat
			// costs. Coming back to the block that was being worked on is only courtesy.
			if ( sectionKey ) {
				global.setTimeout( function () {
					var card = document.querySelector( '[data-section="' + sectionKey + '"]' );

					if ( card ) {
						card.click();
					}
				}, 120 );
			}
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function bindClick( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapSeatPrices = Seats;
}( window ) );
