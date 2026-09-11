/**
 * What this account owes the platform, and how it pays.
 *
 * The other side of the settlement screen. That one answers "what did we take"; this one answers
 * "what do we owe for the software", and it is behind `account.manage` rather than any money
 * permission — a box office manager who can refund a booking has no business seeing the card the
 * account is paid with.
 *
 * Nothing here can change an invoice. They are raised by the platform and frozen when they are
 * raised, so what an organiser does on this screen is choose how the bills get paid and read what
 * has already been sent.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Billing = { App: null, data: null };

	Billing.render = function ( App ) {
		Billing.App = App;
		App.loading( App.t( 'panel.billing.title' ) );

		App.request( 'GET', '/billing' )
			.then( function ( response ) {
				Billing.data = response;
				Billing.paint();
				Billing.settleReturn();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Billing.paint = function () {
		var App = Billing.App;
		var data = Billing.data;
		var plan = data.plan || {};
		var subscription = data.subscription || {};

		App.page( {
			title: App.t( 'panel.billing.title' ),
			description: esc( App.t( 'panel.billing.description' ) ),
			body:
				/*
				 * Said first and said loudly.
				 *
				 * An account whose payments have stopped going through should not have to work that
				 * out from a list of invoices — and this is the one screen where the sentence can be
				 * acted on in the same breath as it is read.
				 */
				( subscription.past_due_since
					? '<p class="issue issue--error">' + icon( 'alert', { size: 16 } ) + '<span>' +
						esc( App.t( 'panel.billing.pastDue', {
							since: App.date( subscription.past_due_since, { dateStyle: 'medium' } ),
						} ) ) + '</span></p>'
					: '' ) +

				'<div class="stat-strip">' +
					tile( App.t( 'panel.billing.plan' ), plan.name || '—',
						plan.price_amount
							? App.t( 'panel.billing.perInterval', {
								amount: App.money( plan.price_amount, plan.currency || data.currency ),
								interval: App.t( 'panel.billing.intervals.' + ( plan.interval || 'month' ) ),
							} )
							: App.t( 'panel.billing.noFee' ) ) +
					tile( App.t( 'panel.billing.commission' ),
						plan.commission_rate
							? App.t( 'panel.billing.rate', {
								rate: App.number( plan.commission_rate / 100 ),
							} )
							: '—',
						App.t( 'panel.billing.commissionHint' ) ) +
					tile( App.t( 'panel.billing.owed' ),
						App.money( data.owed || 0, data.currency ),
						data.owed
							? App.t( 'panel.billing.owedHint' )
							: App.t( 'panel.billing.nothingOwed' ) ) +
					tile( App.t( 'panel.billing.renews' ),
						subscription.current_period_end
							? App.date( subscription.current_period_end, { dateStyle: 'medium' } )
							: '—', '' ) +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'panel.billing.howYouPay' ) ) + '</h3>' +
				Billing.methodMarkup( App, data ) +

				'<h3 class="subhead">' + esc( App.t( 'panel.billing.invoices' ) ) + '</h3>' +
				Billing.invoicesMarkup( App, data ),
		} );

		bind( 'billing-add-card', function () { Billing.addCard(); } );
		bind( 'billing-invoice-me', function () { Billing.invoiceMe(); } );
	};

	/**
	 * How the bills get paid, and the choice between the two.
	 *
	 * "Invoice me" is a first-class answer rather than the absence of a card: a great many venues
	 * are public bodies that cannot put a card on a form and pay everything by transfer against a
	 * purchase order. An account that has said so should be left alone, not nagged for ever.
	 */
	Billing.methodMarkup = function ( App, data ) {
		var method = data.method;
		var isCard = method && 'card' === method.kind && method.last4;

		return '<div class="card card--pad">' +
			( isCard
				? '<p>' + esc( App.t( 'panel.billing.cardOnFile', {
					brand: method.brand || 'card',
					last4: method.last4,
					expiry: [ method.exp_month, method.exp_year ].filter( Boolean ).join( '/' ),
				} ) ) + '</p>' +
					( method.expiring
						? '<p class="issue issue--warn">' + icon( 'alert', { size: 15 } ) + '<span>' +
							esc( App.t( 'panel.billing.cardExpiring' ) ) + '</span></p>'
						: '' )
				: '<p class="muted">' + esc( App.t( method && 'invoice' === method.kind
					? 'panel.billing.byTransfer'
					: 'panel.billing.nothingChosen' ) ) + '</p>' ) +
			'<div class="filters">' +
				( data.takes_cards
					? '<button class="btn btn--primary" id="billing-add-card">' +
						icon( 'wallet', { size: 15 } ) +
						esc( App.t( isCard ? 'panel.billing.replaceCard' : 'panel.billing.addCard' ) ) +
						'</button>'
					// No button where no card can be taken, and a sentence saying why instead: an
					// offer that cannot work is worse than none.
					: '<span class="muted">' + esc( App.t( 'panel.billing.cardsNotTaken' ) ) + '</span>' ) +
				( isCard || ! method
					? ' <button class="btn" id="billing-invoice-me">' +
						esc( App.t( 'panel.billing.invoiceMe' ) ) + '</button>'
					: '' ) +
			'</div>' +
		'</div>';
	};

	Billing.invoicesMarkup = function ( App, data ) {
		var rows = data.invoices || [];

		if ( ! rows.length ) {
			return '<p class="muted">' + esc( App.t( 'panel.billing.noInvoices' ) ) + '</p>';
		}

		return App.table(
			[
				App.t( 'panel.billing.number' ),
				App.t( 'panel.billing.period' ),
				{ label: App.t( 'panel.billing.amount' ), numeric: true },
				App.t( 'panel.common.status' ),
			],
			rows.map( function ( row ) {
				var badge = 'paid' === row.status
					? 'badge--ok'
					: ( 'void' === row.status
						? 'badge--danger'
						: ( row.overdue ? 'badge--danger' : 'badge--warn' ) );

				return '<tr' + ( 'void' === row.status ? ' class="is-muted"' : '' ) + '>' +
					'<td class="table__primary"><code>' + esc( row.number ) + '</code>' +
						'<span class="muted on-own-line">' +
							( row.lines || [] ).map( function ( line ) {
								return esc( line.label );
							} ).join( ' · ' ) + '</span></td>' +
					'<td class="muted nowrap tnum">' + esc( App.t( 'panel.billing.periodRange', {
						from: App.date( row.from, { dateStyle: 'medium' } ),
						to: App.date( row.to, { dateStyle: 'medium' } ),
					} ) ) + '</td>' +
					'<td class="tnum">' + esc( App.money( row.total, row.currency ) ) +
						( row.tax_amount
							? '<span class="muted on-own-line">' +
								esc( App.t( 'panel.billing.includesTax', {
									amount: App.money( row.tax_amount, row.currency ),
								} ) ) + '</span>'
							: '' ) + '</td>' +
					'<td><span class="badge ' + badge + '">' +
						esc( App.t( 'panel.billing.statuses.' + row.status ) ) + '</span>' +
						( 'open' === row.status && row.due_on
							? '<span class="muted on-own-line tnum">' +
								esc( App.t( 'panel.billing.dueOn', {
									date: App.date( row.due_on, { dateStyle: 'medium' } ),
								} ) ) + '</span>'
							: '' ) +
						// What the gateway said, on the row it happened to. An organiser chasing a
						// failed payment needs the reason, not a status.
						( row.last_error
							? '<span class="muted on-own-line">' + esc( row.last_error ) + '</span>'
							: '' ) + '</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/** Off to the gateway's own page, because a card number has no business on ours. */
	Billing.addCard = function () {
		var App = Billing.App;

		App.request( 'POST', '/billing/card/setup', {
			return_url: global.location.origin + global.location.pathname + '#billing',
		} )
			.then( function ( response ) { global.location.href = response.url; } )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * They have come back from that page with a session on the URL.
	 *
	 * Read once and then wiped off the address bar: a setup session is single use, and leaving it
	 * in a URL somebody might bookmark or paste is leaving something that looks like a secret.
	 */
	Billing.settleReturn = function () {
		var App = Billing.App;
		var match = /[?&]session=([^&]+)/.exec( global.location.search );

		if ( ! match ) {
			return;
		}

		global.history.replaceState( {}, '', global.location.pathname + '#billing' );

		App.request( 'POST', '/billing/card/finish', { session: decodeURIComponent( match[ 1 ] ) } )
			.then( function () {
				App.toast( App.t( 'panel.billing.cardAdded' ) );
				Billing.render( App );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Billing.invoiceMe = function () {
		var App = Billing.App;
		var method = Billing.data.method || {};

		App.modal( {
			title: App.t( 'panel.billing.invoiceMeTitle' ),
			submitLabel: App.t( 'panel.billing.invoiceMe' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.billing.invoiceMeBody' ) ) + '</p>' +
					field( 'bill-name', App.t( 'panel.billing.billingName' ), method.billing_name ) +
					field( 'bill-email', App.t( 'panel.billing.billingEmail' ), method.billing_email ) +
					field( 'bill-vat', App.t( 'panel.billing.vatNumber' ), method.vat_number ) +
					'<div class="field"><label class="field__label" for="bill-address">' +
						esc( App.t( 'panel.billing.billingAddress' ) ) + '</label>' +
						'<textarea class="input" id="bill-address" rows="3">' +
							esc( method.billing_address || '' ) + '</textarea></div>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/billing/invoice-me', {
					billing_name: value( 'bill-name' ) || null,
					billing_email: value( 'bill-email' ) || null,
					billing_address: value( 'bill-address' ) || null,
					vat_number: value( 'bill-vat' ) || null,
				} ).then( function () {
					App.toast( App.t( 'panel.billing.saved' ) );
					Billing.render( App );
				} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function field( id, label, current ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' +
			esc( label ) + '</label>' +
			'<input class="input" id="' + id + '" value="' + esc( current || '' ) + '"></div>';
	}

	// The same furniture the settlement and order screens use, rather than a second set of class
	// names that would drift away from them the first time either is restyled.
	function tile( label, value, meta ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
			( meta ? '<span class="tile__meta">' + esc( meta ) + '</span>' : '' ) +
		'</div>';
	}

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value ).replace(
			/[&<>"']/g,
			function ( character ) {
				return {
					'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
				}[ character ];
			}
		);
	}

	global.SeatmapBilling = Billing;
}( window ) );
