/**
 * The box office: find a booking, see everything on it, put it right.
 *
 * Built around what somebody at a window is actually holding — a reference on a phone screen, a
 * name, or an email address — so one search box covers all three and nothing else is in the way.
 *
 * Two of the three actions here cannot be undone, and the screen says so before it does them: a
 * refund releases the seats and voids the tickets, and sending somebody their tickets again mints
 * new codes and stops the old ones working.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Orders = {
		App: null,
		events: [],
		filters: { event_id: '', status: '', q: '' },
		timer: null,
		order: null,
	};

	var STATUSES = [ '', 'pending', 'confirmed', 'partially_refunded', 'refunded', 'cancelled' ];

	Orders.render = function ( App ) {
		Orders.App = App;
		App.loading( App.t( 'panel.orders.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.catch( function () { return { data: [] }; } )
			.then( function ( response ) {
				Orders.events = response.data || [];
				Orders.paint();
				// Once, on arrival, rather than on every keystroke of the search box: the queue
				// does not change because somebody is typing a name.
				Orders.loadRequests();
				Orders.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Orders.paint = function () {
		var App = Orders.App;

		App.page( {
			title: App.t( 'panel.orders.title' ),
			description: esc( App.t( 'panel.orders.description' ) ),
			body:
				'<div class="filters">' +
					'<input class="input grow" id="order-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.orders.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.orders.search' ) ) + '" ' +
						'value="' + esc( Orders.filters.q ) + '">' +
					'<select class="select" id="order-event" aria-label="' +
						esc( App.t( 'panel.orders.allEvents' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.orders.allEvents' ) ) + '</option>' +
						Orders.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( event.id === Orders.filters.event_id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="order-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						STATUSES.map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Orders.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.customers.orderStatus.' + status )
									: App.t( 'panel.orders.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				// Above the list, because somebody is waiting on it. Absent entirely when nobody
				// is — a heading over an empty box is a screen that looks broken.
				'<div id="order-requests"></div>' +
				'<div id="order-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 'order-search' );

		search.addEventListener( 'input', function () {
			Orders.filters.q = search.value;

			global.clearTimeout( Orders.timer );
			Orders.timer = global.setTimeout( function () { Orders.load(); }, 300 );
		} );

		[ [ 'order-event', 'event_id' ], [ 'order-status', 'status' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Orders.filters[ pair[ 1 ] ] = this.value;
				Orders.load();
			} );
		} );
	};

	/**
	 * The buyers waiting for an answer about their money.
	 *
	 * Here rather than on a screen of its own: this is where the box office already works, and a
	 * queue behind another navigation item is a queue nobody opens.
	 */
	Orders.loadRequests = function () {
		var App = Orders.App;
		var host = document.getElementById( 'order-requests' );

		if ( ! host ) {
			return;
		}

		App.request( 'GET', '/refund-requests?status=pending' )
			.then( function ( response ) {
				var waiting = response.data || [];

				if ( ! waiting.length ) {
					host.innerHTML = '';

					return;
				}

				host.innerHTML = '<div class="card spaced">' +
					'<h3 class="subhead">' + esc( App.t( 'panel.refunds.waiting', {
						count: App.number( waiting.length ),
					} ) ) + '</h3>' +
					'<ul class="asked">' +
						waiting.map( function ( row ) {
							return '<li>' +
								'<div><strong>' + esc( ( row.order || {} ).buyer || '—' ) + '</strong>' +
									'<span class="muted on-own-line">' +
									esc( ( row.event || {} ).name || '' ) + ' · ' +
									esc( ( row.order || {} ).reference || '' ) + ' · ' +
									esc( ( row.order || {} ).total || '' ) + '</span>' +
									( row.reason
										? '<span class="muted on-own-line" dir="auto">' +
											esc( row.reason ) + '</span>'
										: '' ) + '</div>' +
								'<div class="asked__actions">' +
									'<button class="btn btn--sm" data-decline="' + esc( row.id ) + '">' +
										esc( App.t( 'panel.refunds.decline' ) ) + '</button>' +
									'<button class="btn btn--sm btn--primary" data-grant="' +
										esc( row.id ) + '">' +
										esc( App.t( 'panel.refunds.grant' ) ) + '</button>' +
								'</div>' +
							'</li>';
						} ).join( '' ) +
					'</ul>' +
				'</div>';

				Orders.bindRequests();
			} )
			.catch( function () {
				// A box office without `orders.refund` sees no queue rather than an error: the
				// screen is still theirs, they simply have no say in this part of it.
				host.innerHTML = '';
			} );
	};

	Orders.bindRequests = function () {
		var App = Orders.App;

		each( '[data-grant]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.request( 'POST', '/refund-requests/' + button.dataset.grant + '/grant', {} )
					.then( function () {
						App.toast( App.t( 'panel.refunds.granted' ) );
						Orders.loadRequests();
						Orders.load();
					} )
					.catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		each( '[data-decline]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.modal( {
					title: App.t( 'panel.refunds.decline' ),
					submitLabel: App.t( 'panel.refunds.decline' ),
					danger: true,
					body: '<div class="field"><label class="field__label" for="decline-why">' +
						esc( App.t( 'panel.refunds.why' ) ) + '</label>' +
						'<input class="input" id="decline-why" maxlength="300" required>' +
						'<span class="field__hint">' + esc( App.t( 'panel.refunds.whyHint' ) ) +
						'</span></div>',
					onSubmit: function () {
						var why = document.getElementById( 'decline-why' ).value.trim();

						if ( ! why ) {
							App.toast( App.t( 'panel.refunds.needWhy' ), true );

							return true;
						}

						return App.request(
							'POST', '/refund-requests/' + button.dataset.decline + '/decline',
							{ reason: why }
						).then( function () {
							App.toast( App.t( 'panel.refunds.declined' ) );
							Orders.loadRequests();
						} );
					},
				} );
			} );
		} );
	};

	Orders.load = function () {
		var App = Orders.App;
		var host = document.getElementById( 'order-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Orders.filters )
			.filter( function ( key ) { return Orders.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Orders.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/orders' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				host.innerHTML = Orders.listMarkup( response );

				each( '[data-order]', function ( button ) {
					button.addEventListener( 'click', function () { Orders.open( button.dataset.order ); } );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Orders.listMarkup = function ( response ) {
		var App = Orders.App;

		if ( ! response.data.length ) {
			return App.emptyState(
				Orders.filters.q ? 'search' : 'ticket',
				App.t( Orders.filters.q ? 'panel.orders.noMatchTitle' : 'panel.orders.noneTitle' ),
				esc( App.t( Orders.filters.q ? 'panel.orders.noMatchBody' : 'panel.orders.noneBody' ) )
			);
		}

		return App.table(
			[
				App.t( 'panel.orders.reference' ),
				App.t( 'panel.orders.buyer' ),
				App.t( 'panel.customers.events' ),
				{ label: App.t( 'panel.orders.seats' ), numeric: true },
				{ label: App.t( 'panel.orders.total' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( order ) {
				return '<tr>' +
					'<td class="table__primary"><code>' + esc( order.reference ) + '</code>' +
						'<span class="muted on-own-line tnum">' + esc( App.date( order.placed_at ) ) + '</span></td>' +
					'<td>' + esc( order.buyer_name || '—' ) +
						'<span class="muted on-own-line">' + esc( order.buyer_email || '' ) + '</span></td>' +
					'<td>' + esc( ( order.event && order.event.name ) || '—' ) + '</td>' +
					'<td class="tnum">' + esc( App.number( order.seats ) ) + '</td>' +
					'<td class="tnum">' + esc( App.money( order.total_amount, order.currency ) ) + '</td>' +
					'<td>' + Orders.badge( order.status ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-order="' + esc( order.id ) + '">' +
							esc( App.t( 'panel.orders.open' ) ) + '</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		) + ( response.meta && response.meta.total
			? '<p class="hint spaced">' + esc( App.t( 'panel.orders.shown', {
				count: App.number( response.data.length ),
				total: App.number( response.meta.total ),
			} ) ) + '</p>'
			: '' );
	};

	Orders.badge = function ( status ) {
		var App = Orders.App;
		var tone = {
			confirmed: 'ok', pending: 'warn', cancelled: 'danger',
			refunded: 'danger', partially_refunded: 'warn',
		}[ status ] || 'neutral';

		return '<span class="badge badge--' + tone + '">' +
			esc( App.t( 'panel.customers.orderStatus.' + status ) ) + '</span>';
	};

	/* --------------------------------------------------------------------------- one order */

	Orders.open = function ( id ) {
		var App = Orders.App;

		App.loading( App.t( 'panel.orders.title' ) );

		App.request( 'GET', '/orders/' + id )
			.then( function ( order ) {
				Orders.order = order;
				Orders.paintOrder();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Orders.paintOrder = function () {
		var App = Orders.App;
		var order = Orders.order;
		var open = order.lines.filter( function ( line ) { return 'active' === line.status; } );

		App.page( {
			title: order.reference,
			description: esc( ( order.event && order.event.name ) || '' ),
			actions:
				'<button class="btn" id="order-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.orders.back' ) ) + '</button>' +
				( order.can_resend
					? '<button class="btn" id="order-resend">' + icon( 'mail', { size: 15 } ) +
						esc( App.t( 'panel.orders.resend' ) ) + '</button>'
					: '' ) +
				( open.length && ( 'confirmed' === order.status || 'partially_refunded' === order.status )
					? '<button class="btn btn--danger" id="order-refund">' +
						esc( App.t( 'panel.orders.refund' ) ) + '</button>'
					: '' ) +
				( 'pending' === order.status
					? '<button class="btn btn--danger" id="order-cancel">' +
						esc( App.t( 'panel.orders.cancel' ) ) + '</button>'
					: '' ) +
				// The bank taking the money back, which is not a refund and must not be recorded
				// as one: the settlement has to be able to tell what was given from what was taken.
				( 'confirmed' === order.status || 'partially_refunded' === order.status
					? '<button class="btn btn--danger" id="order-chargeback">' +
						esc( App.t( 'panel.orders.chargeback' ) ) + '</button>'
					: '' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.orders.total' ), App.money( order.total_amount, order.currency ),
						Orders.badgeText( order.status ) ) +
					tile( App.t( 'panel.orders.seats' ), App.number( order.seats ),
						App.t( 'panel.orders.placedOn', { date: App.date( order.placed_at ) } ) ) +
					tile( App.t( 'panel.orders.buyer' ), order.buyer.name || '—',
						order.buyer.email || '' ) +
					tile( App.t( 'panel.orders.channel' ), order.channel || '—', '' ) +
				'</div>' +

				Orders.breakdown( App, order ) +
				Orders.answers( App, order ) +

				'<h3 class="subhead">' + esc( App.t( 'panel.orders.whatWasBought' ) ) + '</h3>' +
				App.table(
					[
						App.t( 'panel.orders.seat' ),
						{ label: App.t( 'panel.orders.price' ), numeric: true },
						App.t( 'panel.common.status' ),
						App.t( 'panel.orders.ticket' ),
					],
					order.lines.map( function ( line ) {
						var seat = [ line.section, line.row, line.seat ].filter( Boolean ).join( ' · ' );

						return '<tr>' +
							'<td class="table__primary">' + esc( seat || App.t( 'panel.customers.standing' ) ) +
								( line.seat ? '' : ' <span class="muted">' +
									esc( App.t( 'panel.customers.quantity', { count: App.number( line.quantity ) } ) ) +
									'</span>' ) +
								// When this person was told to arrive, on a timed-entry event.
								( line.entry
									? '<span class="muted on-own-line">' + esc( line.entry ) + '</span>'
									: '' ) + '</td>' +
							'<td class="tnum">' + esc( App.money( line.amount, order.currency ) ) + '</td>' +
							'<td>' + esc( App.t( 'panel.orders.allocation.' + line.status ) ) + '</td>' +
							'<td>' + ( line.used_at
								? '<span class="badge badge--ok">' + esc( App.t( 'panel.tickets.checkedIn' ) ) +
									'</span><span class="muted on-own-line tnum">' +
									esc( App.date( line.used_at ) ) + '</span>'
								: '<span class="muted">' + esc( line.ticket_status
									? App.t( 'panel.orders.ticketStatus.' + line.ticket_status )
									: '—' ) + '</span>' ) + '</td>' +
						'</tr>';
					} ).join( '' )
				) +

				'<h3 class="subhead">' + esc( App.t( 'panel.orders.whatTheyWereTold' ) ) + '</h3>' +
				( order.messages.length
					? App.table(
						[
							App.t( 'panel.orders.when' ),
							App.t( 'panel.orders.to' ),
							App.t( 'panel.orders.message' ),
							App.t( 'panel.common.status' ),
						],
						order.messages.map( function ( entry ) {
							return '<tr>' +
								'<td class="muted nowrap tnum">' + esc( App.date( entry.created_at ) ) + '</td>' +
								'<td>' + esc( entry.recipient ) + '</td>' +
								'<td>' + esc( entry.kind ) + ' · ' + esc( entry.channel ) + '</td>' +
								'<td>' + esc( App.t( 'messaging.status.' + entry.status ) ) +
									( entry.reason
										? '<span class="muted on-own-line">' + esc( entry.reason ) + '</span>'
										: '' ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="muted">' + esc( App.t( 'panel.orders.nothingSent' ) ) + '</p>' ),
		} );

		document.getElementById( 'order-back' ).addEventListener( 'click', function () {
			Orders.render( App );
		} );

		bind( 'order-refund', function () { Orders.refund( open ); } );
		bind( 'order-chargeback', function () { Orders.chargeback(); } );
		bind( 'order-cancel', function () { Orders.cancel(); } );
		bind( 'order-resend', function () { Orders.resend(); } );
	};

	Orders.badgeText = function ( status ) {
		return Orders.App.t( 'panel.customers.orderStatus.' + status );
	};

	/**
	 * Recording that the bank took the money back.
	 *
	 * Not a refund, and deliberately a different button: this releases the seats and voids the
	 * tickets whatever the organiser's refund policy says, because a booking nobody paid for is not
	 * a booking. Barring the person is offered here and ticked by hand — a disputed payment is
	 * sometimes a stolen card and sometimes somebody who could not reach anybody about a train.
	 */
	Orders.chargeback = function () {
		var App = Orders.App;
		var order = Orders.order;

		App.modal( {
			title: App.t( 'panel.orders.chargebackTitle' ),
			submitLabel: App.t( 'panel.orders.chargeback' ),
			danger: true,
			body:
				'<p>' + esc( App.t( 'panel.orders.chargebackBody' ) ) + '</p>' +
				'<div class="field"><label class="field__label" for="cb-reason">' +
					esc( App.t( 'panel.orders.chargebackReason' ) ) + '</label>' +
					'<input class="input" id="cb-reason" name="reason" maxlength="190" required></div>' +
				'<div class="field"><label class="field__label" for="cb-fee">' +
					esc( App.t( 'panel.orders.chargebackFee' ) ) + '</label>' +
					'<input class="input tnum" id="cb-fee" name="fee" type="number" min="0" ' +
						'step="0.01" value="0">' +
					'<span class="field__hint">' +
						esc( App.t( 'panel.orders.chargebackFeeHint' ) ) + '</span></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="cb-block" name="block">' +
					'<span>' + esc( App.t( 'panel.orders.chargebackBlock' ) ) +
					'<span class="muted on-own-line">' +
						esc( App.t( 'panel.orders.chargebackBlockHint' ) ) + '</span></span></label>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/orders/' + order.id + '/chargeback', {
					reason: data.get( 'reason' ),
					// Minor units on the wire, whole money on the screen, as everywhere else.
					fee: Math.round( Number( data.get( 'fee' ) || 0 ) * 100 ),
					block: null !== data.get( 'block' ),
				} ).then( function () {
					App.toast( App.t( 'panel.orders.chargebackDone' ) );
					Orders.render( App );
				} );
			},
		} );
	};

	/**
	 * Refunding.
	 *
	 * Every open seat is ticked to begin with, because refunding the whole booking is what happens
	 * nine times in ten — and unticking one is how the tenth is done, rather than a second screen.
	 */
	Orders.refund = function ( open ) {
		var App = Orders.App;
		var order = Orders.order;

		App.modal( {
			title: App.t( 'panel.orders.refundTitle' ),
			submitLabel: App.t( 'panel.orders.refund' ),
			body:
				'<p>' + esc( App.t( 'panel.orders.refundBody' ) ) + '</p>' +
				'<div class="perms">' + open.map( function ( line ) {
					var seat = [ line.section, line.row, line.seat ].filter( Boolean ).join( ' · ' );

					return '<label class="perms__row">' +
						'<input type="checkbox" class="checkbox" data-refund="' +
							esc( line.seat_id || '' ) + '" checked' +
							( line.seat_id ? '' : ' disabled' ) + '>' +
						'<span>' + esc( seat || App.t( 'panel.customers.standing' ) ) + ' · ' +
							esc( App.money( line.amount, order.currency ) ) + '</span>' +
					'</label>';
				} ).join( '' ) + '</div>' +
				'<p class="field__hint">' + esc( App.t( 'panel.orders.refundMoneyHint' ) ) + '</p>' +
				/*
				 * The other way to give the money back.
				 *
				 * Offered on the whole booking only, because credit is issued against what was
				 * charged and a part refund leaves the rest of the booking to be paid for out of
				 * exactly that money. The buyer has to have agreed to it — this is a box the
				 * person on the telephone ticks after asking, not a default.
				 */
				'<label class="perms__row" id="order-credit-row">' +
					'<input type="checkbox" class="checkbox" id="order-credit">' +
					'<span>' + esc( App.t( 'panel.orders.refundAsCredit' ) ) +
						'<span class="muted on-own-line">' +
						esc( App.t( 'panel.orders.refundAsCreditHint' ) ) + '</span></span>' +
				'</label>',
			onSubmit: function () {
				var chosen = [];
				var all = true;

				each( '[data-refund]', function ( box ) {
					if ( box.checked && box.dataset.refund ) {
						chosen.push( box.dataset.refund );
					}

					if ( ! box.checked ) {
						all = false;
					}
				} );

				var credit = document.getElementById( 'order-credit' );
				var payload = all ? {} : { seat_ids: chosen };

				if ( all && credit && credit.checked ) {
					payload.as_credit = true;
				}

				// No `seat_ids` at all means the whole order, which is also the only way to refund
				// a standing place — those have no seat id to name.
				return App.request( 'POST', '/orders/' + order.id + '/refund', payload )
					.then( function () {
						App.toast( App.t( 'panel.orders.refunded' ) );
						Orders.open( order.id );
					} );
			},
		} );

		// Kept in step with the tick boxes above it: credit is for the whole booking, so the offer
		// disappears the moment somebody unticks a seat rather than failing silently at the server.
		each( '[data-refund]', function ( box ) {
			box.addEventListener( 'change', function () {
				var all = true;

				each( '[data-refund]', function ( other ) {
					if ( ! other.checked ) {
						all = false;
					}
				} );

				var row = document.getElementById( 'order-credit-row' );
				var credit = document.getElementById( 'order-credit' );

				if ( row ) {
					row.hidden = ! all;
				}

				if ( credit && ! all ) {
					credit.checked = false;
				}
			} );
		} );
	};

	Orders.cancel = function () {
		var App = Orders.App;

		App.modal( {
			title: App.t( 'panel.orders.cancelTitle' ),
			submitLabel: App.t( 'panel.orders.cancel' ),
			body: '<p>' + esc( App.t( 'panel.orders.cancelBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/orders/' + Orders.order.id + '/cancel', {} )
					.then( function () {
						App.toast( App.t( 'panel.orders.cancelled' ) );
						Orders.open( Orders.order.id );
					} );
			},
		} );
	};

	Orders.resend = function () {
		var App = Orders.App;

		App.modal( {
			title: App.t( 'panel.orders.resendTitle' ),
			submitLabel: App.t( 'panel.orders.resend' ),
			body: '<p>' + esc( App.t( 'panel.orders.resendBody' ) ) + '</p>' +
				'<p class="field__hint">' + esc( App.t( 'panel.orders.resendWarning' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/orders/' + Orders.order.id + '/resend', {} )
					.then( function ( result ) {
						App.toast( App.t( 'panel.orders.resent', { to: result.to } ) );
						Orders.open( Orders.order.id );
					} );
			},
		} );
	};

	/**
	 * Where the money went: tickets, discount, fee, tax.
	 *
	 * Read back from what was written at the moment of sale, never recomputed. An event whose fee
	 * or tax rate changed since must not rewrite what an old booking was charged — and a box office
	 * asked "why is this €38 for €45 of seats" needs the answer that was true then.
	 */
	Orders.breakdown = function ( App, order ) {
		var totals = order.totals;

		if ( ! totals ) {
			return '';
		}

		var rows = [
			[ App.t( 'panel.orders.totals.tickets' ), totals.tickets ],
		];

		if ( totals.discount ) {
			rows.push( [
				order.discount
					? App.t( 'panel.orders.totals.discountWith', { code: order.discount.code } )
					: App.t( 'panel.orders.totals.discount' ),
				-totals.discount,
			] );
		}

		if ( totals.fee ) {
			rows.push( [ totals.fee_label || App.t( 'panel.orders.totals.fee' ), totals.fee ] );
		}

		if ( totals.tax ) {
			rows.push( [
				App.t( totals.tax_included
					? 'panel.orders.totals.taxIncluded'
					: 'panel.orders.totals.tax', {
					name: totals.tax_label || App.t( 'panel.orders.totals.taxName' ),
					rate: App.number( totals.tax_rate / 100 ),
				} ),
				totals.tax,
			] );
		}

		rows.push( [ App.t( 'panel.orders.totals.total' ), totals.total ] );

		return '<h3 class="subhead">' + esc( App.t( 'panel.orders.totals.title' ) ) + '</h3>' +
			App.table(
				[ App.t( 'panel.orders.totals.what' ), { label: App.t( 'panel.orders.total' ), numeric: true } ],
				rows.map( function ( row, index ) {
					return '<tr' + ( index === rows.length - 1 ? ' class="is-strong"' : '' ) + '>' +
						'<td>' + esc( row[ 0 ] ) + '</td>' +
						'<td class="tnum">' + esc( App.money( row[ 1 ], order.currency ) ) + '</td>' +
					'</tr>';
				} ).join( '' )
			);
	};

	/**
	 * What this buyer was asked at checkout, and said.
	 *
	 * Shown with the wording they saw rather than the wording the question carries now: an
	 * organiser who rewords a question must not change what a past answer appears to answer.
	 */
	Orders.answers = function ( App, order ) {
		if ( ! order.answers || ! order.answers.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.orders.answers' ) ) + '</h3>' +
			App.table(
				[ App.t( 'panel.orders.question' ), App.t( 'panel.orders.answer' ) ],
				order.answers.map( function ( answer ) {
					return '<tr><td>' + esc( answer.label ) + '</td>' +
						'<td>' + esc( answer.value || '—' ) + '</td></tr>';
				} ).join( '' )
			);
	};

	/* --------------------------------------------------------------------------- helpers */

	function tile( label, value, hint ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
			( hint ? '<span class="tile__meta">' + esc( hint ) + '</span>' : '' ) +
		'</div>';
	}

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

	global.SeatmapOrders = Orders;
}( typeof window !== 'undefined' ? window : globalThis ) );
