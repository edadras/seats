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

		/*
		 * The programme, and — where the person at the counter sells for somebody else — their own
		 * account beside it.
		 *
		 * An agent selling on credit needs one number in front of them all afternoon: how much
		 * they have left to sell against. Finding out at the end of a transaction that they ran out
		 * three sales ago is a queue to apologise to.
		 */
		Promise.all( [
			App.request( 'GET', '/events?per_page=100' ),
			App.request( 'GET', '/sales-agents/summary' ).catch( function () { return { agent: null }; } ),
		] )
			.then( function ( answers ) {
				var response = answers[ 0 ];

				Counter.agent = answers[ 1 ].agent || null;
				Counter.events = ( response.data || [] ).filter( function ( event ) {
					return 'published' === event.status;
				} );

				// Settled before the screen is drawn, or the picker is painted with the night this
				// screen was last on rather than the one the panel is working on.
				Counter.eventId = App.pickNight( Counter.events, Counter.eventId );
				Counter.paint();

				if ( Counter.eventId ) {
					Counter.choose( Counter.eventId );
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
				Counter.agentStrip() +
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

	/** What this agent has left to sell against, where the seller is one. */
	Counter.agentStrip = function () {
		var App = Counter.App;
		var agent = Counter.agent;

		if ( ! agent ) {
			return '';
		}

		var account = agent.account || {};
		var short = ( account.available || 0 ) <= 0;

		return '<div class="stat-strip">' +
			'<div class="tile tile--static">' +
				'<span class="tile__label">' + esc( App.t( 'panel.agents.yourAccount' ) ) + '</span>' +
				'<span class="tile__value tnum">' + esc( agent.name ) + '</span>' +
				'<span class="tile__meta">' + esc( agent.code ) + '</span>' +
			'</div>' +
			'<div class="tile tile--static">' +
				'<span class="tile__label">' + esc( App.t( 'panel.agents.yourCredit' ) ) + '</span>' +
				'<span class="tile__value tnum' + ( short ? ' is-danger' : '' ) + '">' +
					esc( App.money( account.available || 0, account.currency ) ) + '</span>' +
				'<span class="tile__meta">' + esc( App.t( 'panel.agents.balance' ) ) + ' ' +
					esc( App.money( account.balance || 0, account.currency ) ) + '</span>' +
			'</div>' +
			'<div class="tile tile--static">' +
				'<span class="tile__label">' + esc( App.t( 'panel.agents.sold' ) ) + '</span>' +
				'<span class="tile__value tnum">' +
					esc( App.money( account.sold || 0, account.currency ) ) + '</span>' +
				'<span class="tile__meta">' + esc( App.t( 'panel.agents.seatsSold', {
					count: App.number( account.seats || 0 ),
				} ) ) + '</span>' +
			'</div>' +
		'</div>';
	};

	Counter.choose = function ( eventId ) {
		var App = Counter.App;

		// The night this screen is on is the night the whole panel is on — see App.night.
		Counter.eventId = App.night( eventId );

		// The hall on screen belongs to the night that was on screen. Taken down before the next
		// one is asked for, so an afternoon of switching between three events is not three pickers
		// polling three halls out of sight of each other.
		Counter.takeDown();

		App.request( 'GET', '/events/' + eventId + '/hall' )
			.then( function ( hall ) {
				Counter.hall = hall;
				Counter.mount();
			} )
			.catch( function ( error ) {
				Counter.hall = null;
				var host = document.getElementById( 'counter-hall' );

				if ( host ) {
					host.innerHTML = '<div class="issue issue--error" role="alert">' +
						icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span></div>';
				}
			} );
	};

	/**
	 * Follow the panel's own switch.
	 *
	 * The picker follows the reader's system preference wherever it is a guest. Here it is not a
	 * guest: the panel has a light/dark toggle, and a clerk who set it to dark on a machine whose
	 * system is light was getting a white hall inside a dark screen. Repainting afterwards because
	 * the plan is a canvas, which takes its palette from the colour of the box it is drawn in.
	 */
	Counter.syncTheme = function () {
		var host = document.getElementById( 'counter-picker' );

		if ( ! host || ! Counter.App ) {
			return;
		}

		host.setAttribute( 'data-seatmap-theme', Counter.App.theme() );

		if ( host.seatmapWidget ) {
			host.seatmapWidget.paint();
		}
	};

	Counter.takeDown = function () {
		var host = document.getElementById( 'counter-hall' );
		var mounted = host && host.firstElementChild && host.firstElementChild.seatmapWidget;

		if ( mounted ) {
			mounted.destroy();
		}

		Counter.picker = null;
	};

	/**
	 * Repaint the agent's own numbers, and nothing else.
	 *
	 * Not a repaint of the screen: the picker is on it, holding a zoom, a floor and a place in the
	 * room, and taking the hall down to change three figures in a strip above it would cost the
	 * clerk their position between one customer and the next.
	 */
	Counter.refreshStrip = function () {
		var strip = document.querySelector( '.page-body > .stat-strip' );

		if ( ! strip ) {
			return;
		}

		var replacement = document.createElement( 'div' );

		replacement.innerHTML = Counter.agentStrip();

		if ( replacement.firstElementChild ) {
			strip.replaceWith( replacement.firstElementChild );
		}
	};

	/* ------------------------------------------------------------------------------ the hall */

	/**
	 * The buyer's own picker, at the window.
	 *
	 * The counter used to draw its own thing: a grid of numbered buttons in rows, no plan, no zoom,
	 * no idea where in the room anything was. A clerk taking a booking over the telephone was
	 * describing a hall from a screen that looked nothing like the one the caller had open, and the
	 * seat kept for the director's mother looked exactly like every other free chair.
	 *
	 * So the panel runs the picker the buyer runs — the same file, the same plan, the same zoom,
	 * the same room in three dimensions — pointed at the counter's own availability, which sees the
	 * house seats and says whose they are. What the window keeps is what makes it a window: the sale
	 * happens here, in one dialog, with no cart and no hold left behind.
	 */
	Counter.mount = function () {
		var App = Counter.App;
		var host = document.getElementById( 'counter-hall' );
		var hall = Counter.hall;

		if ( ! host || ! hall ) {
			return;
		}

		host.innerHTML = '<div class="seatmap-widget counter__picker" id="counter-picker" ' +
			'data-seatmap-theme="' + esc( App.theme() ) + '"></div>';

		var currency = hall.event.currency;

		global.seatmapBoot.push( {
			containerId: 'counter-picker',
			eventPublicId: hall.event.public_id,
			event: hall.event,
			geometry: hall.geometry,
			// The counter's own availability: it sees a house seat the website may not sell, and
			// carries the name each one is being kept under.
			availabilityUrl: App.api + '/events/' + Counter.eventId + '/hall/availability',
			// Signed in as the clerk. The picker puts whatever this is on every call it makes.
			headers: { Authorization: 'Bearer ' + App.token },
			currency: {
				code: currency,
				symbol: App.currencySymbol( currency ),
				decimals: hall.event.currency_decimals,
				position: 'left',
			},
			// Given the reader's own locale, the picker formats its prices the way the rest of the
			// panel does rather than approximating with a symbol and a dot.
			locale: App.locale(),
			isRtl: 'rtl' === document.documentElement.dir,
			i18n: Counter.pickerStrings( App ),
			/*
			 * Where the two sides part, and the only place they do.
			 *
			 * A buyer's picker posts a hold and goes to a cart. There is no cart at a window and
			 * nobody to come back later, so the chosen seats come back here instead and the sale is
			 * made in one movement — hold and confirm together, the way the counter always has.
			 */
			onReserve: function ( chosen, widget ) {
				Counter.picker = widget;

				/*
				 * "Four together, please" — the commonest request at a window.
				 *
				 * On a website the server chooses and holds in one movement, because a suggestion
				 * the buyer had to confirm is a suggestion somebody else can take in between. At a
				 * window it is the other way round: the clerk is looking at the person, not at a
				 * clock, and a hold taken on their behalf is one somebody has to remember to
				 * release when the conversation goes another way. So the seats are put on the plan
				 * where they can be read out, and nothing is committed until the sale is.
				 */
				if ( chosen.best_available ) {
					Counter.suggest( App, chosen.best_available.quantity, widget );

					return;
				}

				Counter.sell( App, chosen, widget );
			},
		} );
	};

	/** Ask for n seats side by side and put them on the plan, chosen. */
	Counter.suggest = function ( App, quantity, widget ) {
		App.request( 'GET', '/events/' + Counter.eventId + '/best-available?quantity=' + quantity )
			.then( function ( response ) {
				var seats = response.data || [];

				if ( ! seats.length ) {
					widget.stumbled( App.t( 'panel.boxOffice.noneTogether' ) );

					return;
				}

				widget.chooseByIds( seats.map( function ( seat ) { return seat.seat_id; } ) );
			} )
			.catch( function ( error ) { widget.stumbled( error.message ); } );
	};

	/**
	 * The picker's vocabulary, in the reader's language.
	 *
	 * Taken from the same catalogue the hosted site hands it — one picker, one set of words — with
	 * the handful of sentences that are about buying swapped for the ones that are about selling.
	 * "Reserve and add to cart" is not what the button in front of a clerk does.
	 */
	Counter.pickerStrings = function ( App ) {
		var strings = App.catalogue( 'site.picker' );

		return Object.assign( {}, strings, {
			selectSeats: App.t( 'panel.boxOffice.pickSection' ),
			addToCart: App.t( 'panel.boxOffice.sell' ),
			reserveTickets: App.t( 'panel.boxOffice.sell' ),
			working: App.t( 'panel.common.working' ),
			// The one sentence the buyer's picker has no use for: a chair with somebody's name on it.
			heldFor: App.t( 'panel.boxOffice.heldFor', { name: '%s' } ),
		} );
	};

	/* ------------------------------------------------------------------------------ the sale */

	/**
	 * Who these are for, how it is being paid for, and done.
	 *
	 * `chosen` is what the picker handed over: the seats, the areas, the ticket type against each,
	 * and the arrival window where the event sells them. Nothing is re-derived from the screen —
	 * the seats in this sale are the seats that were on the plan when the clerk pressed the button.
	 */
	Counter.sell = function ( App, chosen, widget ) {
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
						[ 'paid', 'owed', 'comp', 'plan' ].map( function ( kind ) {
							return '<option value="' + kind + '">' +
								esc( App.t( 'panel.boxOffice.payments.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
				/*
				 * How it was paid for, which is a different question from whether it was.
				 *
				 * Only cash goes in the drawer, and a till that could not tell a card from a note
				 * would report every honest evening several hundred short. Cash first because at a
				 * window it usually is.
				 */
				'<div class="field" id="c-method-field"><label class="field__label" for="c-method">' +
					esc( App.t( 'panel.boxOffice.method' ) ) + '</label>' +
					'<select class="select" id="c-method">' +
						[ 'cash', 'card', 'transfer' ].map( function ( kind ) {
							return '<option value="' + kind + '">' +
								esc( App.t( 'panel.boxOffice.methods.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
				/*
				 * A deposit now and the rest on dates somebody agreed.
				 *
				 * Hidden until it is the way this booking is being paid for, because four more
				 * fields on every walk-up sale is four more things to tab past at a window.
				 */
				'<div id="c-plan-fields" hidden>' +
					'<div class="field"><label class="field__label" for="c-deposit">' +
						esc( App.t( 'panel.plans.deposit' ) ) + '</label>' +
						'<input class="input" id="c-deposit" type="number" min="0" step="0.01" value="0">' +
						'<span class="field__hint">' + esc( App.t( 'panel.plans.depositHint' ) ) +
						'</span></div>' +
					'<div class="field"><label class="field__label" for="c-instalments">' +
						esc( App.t( 'panel.plans.instalments' ) ) + '</label>' +
						'<input class="input" id="c-instalments" type="number" min="1" max="24" value="3"></div>' +
					'<div class="field"><label class="field__label" for="c-every">' +
						esc( App.t( 'panel.plans.everyDays' ) ) + '</label>' +
						'<input class="input" id="c-every" type="number" min="1" max="365" value="30"></div>' +
				'</div>' +
				// Who the party is, as against who signed for it.
				'<div class="field"><label class="field__label" for="c-group">' +
					esc( App.t( 'panel.plans.groupName' ) ) + '</label>' +
					'<input class="input" id="c-group" maxlength="160">' +
					'<span class="field__hint">' + esc( App.t( 'panel.plans.groupHint' ) ) +
					'</span></div>' +
				'<div class="field"><label class="field__label" for="c-note">' +
					esc( App.t( 'panel.boxOffice.note' ) ) + '</label>' +
					'<input class="input" id="c-note" maxlength="200"></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="c-send">' +
					'<span>' + esc( App.t( 'panel.boxOffice.sendTickets' ) ) + '</span></label>' +
				// Paper, as the sale completes. Remembered per browser, because whether this
				// counter has a printer beside it is a property of the counter, not of the sale.
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="c-print"' +
					( Counter.printing() ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'panel.printing.printNow' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var name = document.getElementById( 'c-name' ).value.trim();

				if ( ! name ) {
					App.toast( App.t( 'panel.boxOffice.needName' ), true );

					return true;
				}

				/*
				 * What the picker handed over, plus who and how.
				 *
				 * The seats are the picker's answer rather than anything read back off the screen:
				 * the sale is for the chairs that were on the plan when the button was pressed, and
				 * a second reading a moment later is a second chance to disagree with it.
				 */
				var payload = {
					seat_ids: chosen.seat_ids || [],
					areas: chosen.areas || {},
					seat_types: chosen.seat_types || {},
					area_types: chosen.area_types || {},
					buyer: {
						name: name,
						email: document.getElementById( 'c-email' ).value.trim() || null,
					},
					// Somebody walking up at ten past ten still has to be put in a window. The
					// picker asks for it on the plan, the way the website does, so it arrives here
					// already chosen rather than as one more field to tab past.
					entry_slot_id: chosen.entry_slot_id || null,
					payment: document.getElementById( 'c-payment' ).value,
					// A comp is a gift and an invoice is not paid yet: neither has a method, and
					// sending one would put a number against a drawer nothing went into.
					method: 'paid' === document.getElementById( 'c-payment' ).value
						? document.getElementById( 'c-method' ).value
						: null,
					note: document.getElementById( 'c-note' ).value.trim() || null,
					group_name: document.getElementById( 'c-group' ).value.trim() || null,
					send_tickets: document.getElementById( 'c-send' ).checked,
				};

				if ( 'plan' === payload.payment ) {
					// The deposit is typed in the currency people speak, and the API counts in the
					// minor unit — the same conversion the rest of the panel makes.
					payload.plan = {
						deposit: Math.round(
							( parseFloat( document.getElementById( 'c-deposit' ).value ) || 0 ) * 100
						),
						instalments: parseInt( document.getElementById( 'c-instalments' ).value, 10 ) || 1,
						every_days: parseInt( document.getElementById( 'c-every' ).value, 10 ) || 30,
					};
					// A plan takes its deposit at the window, so the method is the deposit's.
					payload.method = document.getElementById( 'c-method' ).value;
				}

				var printing = document.getElementById( 'c-print' ).checked;

				Counter.printing( printing );

				return App.request( 'POST', '/events/' + Counter.eventId + '/sell', payload )
					.then( function ( sale ) {
						App.toast( App.t( 'panel.boxOffice.sold', { reference: sale.reference } ) );

						if ( printing ) {
							global.SeatmapReceipts.send(
								App,
								'/orders/' + sale.id + '/receipts',
								sale.reference
							);
						}

						/*
						 * The chairs are somebody's now.
						 *
						 * The picker lets go of them and asks the hall again rather than being torn
						 * down and rebuilt: the clerk keeps their zoom, their floor and their place
						 * in the room, and the next customer is already standing there.
						 */
						widget.settled();

						// An agent has just spent some of their credit; the strip must not go on
						// saying what it was before the sale.
						if ( Counter.agent ) {
							App.request( 'GET', '/sales-agents/summary' )
								.then( function ( mine ) {
									Counter.agent = mine.agent || null;
									Counter.refreshStrip();
								} )
								.catch( function () {} );
						}
					} )
					.catch( function ( error ) {
						/*
						 * Refused: a seat taken while the dialog was open, a credit line reached.
						 *
						 * The selection stays exactly as it was — the clerk is mid-conversation and
						 * clearing their chairs out from under them would make them start it again —
						 * and the button comes back so they can try the sale a different way.
						 */
						widget.stumbled( error.message );

						throw error;
					} );
			},
		} );

		/*
		 * The method only means anything on a sale that is being paid for now.
		 *
		 * Bound after the modal exists, which is when its body is in the page — the shared modal
		 * has no "opened" hook, and every screen that needs one does it here instead.
		 */
		( function () {
			var payment = document.getElementById( 'c-payment' );
			var method = document.getElementById( 'c-method-field' );

			var plan = document.getElementById( 'c-plan-fields' );

			var sync = function () {
				// A deposit is money taken now, so a plan wants the method as much as a paid sale
				// does; a comp and an invoice are not paid at all and have none.
				method.hidden = 'paid' !== payment.value && 'plan' !== payment.value;
				plan.hidden = 'plan' !== payment.value;
			};

			payment.addEventListener( 'change', sync );
			sync();
		}() );
	};

	/* ------------------------------------------------------------------------------ helpers */

	/**
	 * Whether this browser prints as it sells. Read with no argument, set with one.
	 *
	 * Kept in the browser rather than against the user: the same person works the window on
	 * Tuesday and answers the telephone from a desk on Wednesday, and only one of those has a
	 * printer beside it.
	 */
	Counter.printing = function ( value ) {
		try {
			if ( undefined === value ) {
				return 'yes' === global.localStorage.getItem( 'seatmap.counter.print' );
			}

			global.localStorage.setItem( 'seatmap.counter.print', value ? 'yes' : 'no' );
		} catch ( error ) {
			// Storage switched off: the box stays unticked and the operator ticks it each time.
		}

		return !! value;
	};

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
