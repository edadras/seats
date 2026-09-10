/**
 * Gift vouchers and account credit.
 *
 * The screen next to discounts, and deliberately *not* shaped like it. A discount is a rule the
 * organiser writes and can rewrite; a voucher is money they owe, and the difference shows up here
 * as the two things missing from this screen: there is no edit, and there is no delete. A voucher's
 * amount is a ledger entry, and the way one is stopped is to void it — which writes the row saying
 * the money was written off, rather than removing the evidence that it existed.
 *
 * The balance is never a column in the database. It is the sum of the movements, and the movements
 * are on the detail page underneath it, because "why does this say forty euros" is the question an
 * organiser is asked at the counter and a number with no working shown cannot answer it.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Vouchers = {
		App: null,
		filters: { kind: '', status: '', q: '' },
		timer: null,
		voucher: null,
	};

	Vouchers.render = function ( App ) {
		Vouchers.App = App;
		Vouchers.paint();
		Vouchers.load();
	};

	Vouchers.paint = function () {
		var App = Vouchers.App;

		App.page( {
			title: App.t( 'panel.vouchers.title' ),
			description: esc( App.t( 'panel.vouchers.description' ) ),
			actions: '<button class="btn btn--primary" id="v-new">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.vouchers.create' ) ) + '</button>',
			body:
				'<div class="filters">' +
					'<input class="input grow" id="v-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.vouchers.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.vouchers.search' ) ) + '" ' +
						'value="' + esc( Vouchers.filters.q ) + '">' +
					'<select class="select" id="v-kind" aria-label="' +
						esc( App.t( 'panel.vouchers.kind' ) ) + '">' +
						[ '', 'gift', 'credit' ].map( function ( kind ) {
							return '<option value="' + kind + '"' +
								( kind === Vouchers.filters.kind ? ' selected' : '' ) + '>' +
								esc( kind
									? App.t( 'panel.vouchers.kinds.' + kind )
									: App.t( 'panel.vouchers.anyKind' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="v-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						[ '', 'active', 'void' ].map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Vouchers.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.vouchers.status.' + status )
									: App.t( 'panel.vouchers.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="v-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 'v-search' );

		search.addEventListener( 'input', function () {
			Vouchers.filters.q = search.value;

			global.clearTimeout( Vouchers.timer );
			Vouchers.timer = global.setTimeout( function () { Vouchers.load(); }, 300 );
		} );

		[ [ 'v-kind', 'kind' ], [ 'v-status', 'status' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Vouchers.filters[ pair[ 1 ] ] = this.value;
				Vouchers.load();
			} );
		} );

		bind( 'v-new', function () { Vouchers.form(); } );
	};

	Vouchers.load = function () {
		var App = Vouchers.App;
		var host = document.getElementById( 'v-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Vouchers.filters )
			.filter( function ( key ) { return Vouchers.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Vouchers.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/vouchers' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				host.innerHTML = Vouchers.listMarkup( response );

				each( '[data-open-voucher]', function ( button ) {
					button.addEventListener( 'click', function () {
						Vouchers.open( button.dataset.openVoucher );
					} );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Vouchers.listMarkup = function ( response ) {
		var App = Vouchers.App;

		if ( ! response.data.length ) {
			return App.emptyState(
				Vouchers.filters.q ? 'search' : 'wallet',
				App.t( Vouchers.filters.q ? 'panel.vouchers.noMatchTitle' : 'panel.vouchers.noneTitle' ),
				esc( App.t( Vouchers.filters.q ? 'panel.vouchers.noMatchBody' : 'panel.vouchers.noneBody' ) )
			);
		}

		return App.table(
			[
				App.t( 'panel.vouchers.voucher' ),
				App.t( 'panel.vouchers.kind' ),
				{ label: App.t( 'panel.vouchers.issued' ), numeric: true },
				{ label: App.t( 'panel.vouchers.balance' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( voucher ) {
				return '<tr>' +
					'<td class="table__primary"><code>' +
						esc( voucher.code || voucher.email ) + '</code>' +
						( voucher.note
							? '<span class="muted on-own-line">' + esc( voucher.note ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( App.t( 'panel.vouchers.kinds.' + voucher.kind ) ) + '</td>' +
					'<td class="tnum">' + esc( App.money( voucher.amount, voucher.currency ) ) + '</td>' +
					'<td class="tnum">' + esc( App.money( voucher.balance, voucher.currency ) ) + '</td>' +
					'<td>' + Vouchers.badge( voucher ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-open-voucher="' + esc( voucher.id ) + '">' +
							esc( App.t( 'panel.vouchers.open' ) ) + '</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/**
	 * Spendable is the honest answer, not the stored status.
	 *
	 * A voucher marked active with nothing left in it buys nothing, and a voucher marked active
	 * whose date has passed buys nothing either — telling an organiser both are "active" is how
	 * somebody at a counter argues with a customer who is right.
	 */
	Vouchers.badge = function ( voucher ) {
		var App = Vouchers.App;

		if ( voucher.live ) {
			return '<span class="badge badge--ok">' +
				esc( App.t( 'panel.vouchers.spendable' ) ) + '</span>';
		}

		var why = 'void' === voucher.status
			? 'voided'
			: ( voucher.balance > 0 ? 'expired' : 'spent' );

		return '<span class="badge badge--neutral">' +
			esc( App.t( 'panel.vouchers.' + why ) ) + '</span>';
	};

	/* ----------------------------------------------------------------------- one voucher */

	Vouchers.open = function ( id ) {
		var App = Vouchers.App;

		App.loading( App.t( 'panel.vouchers.title' ) );

		App.request( 'GET', '/vouchers/' + id )
			.then( function ( voucher ) {
				Vouchers.voucher = voucher;
				Vouchers.paintVoucher();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Vouchers.paintVoucher = function () {
		var App = Vouchers.App;
		var voucher = Vouchers.voucher;

		App.page( {
			title: voucher.code || voucher.email,
			description: esc( voucher.note || App.t( 'panel.vouchers.noNote' ) ),
			actions:
				'<button class="btn" id="v-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.vouchers.back' ) ) + '</button>' +
				( 'void' === voucher.status
					? ''
					: '<button class="btn btn--danger" id="v-void">' +
						esc( App.t( 'panel.vouchers.void' ) ) + '</button>' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.vouchers.issued' ),
						App.money( voucher.amount, voucher.currency ),
						voucher.created_at ? App.date( voucher.created_at ) : '' ) +
					tile( App.t( 'panel.vouchers.spent' ),
						App.money( voucher.spent, voucher.currency ) ) +
					tile( App.t( 'panel.vouchers.balance' ),
						App.money( voucher.balance, voucher.currency ) ) +
					tile( App.t( 'panel.common.status' ),
						App.t( 'panel.vouchers.status.' + voucher.status ),
						voucher.expires_at
							? App.t( 'panel.vouchers.until', { date: App.date( voucher.expires_at ) } )
							: App.t( 'panel.vouchers.noExpiry' ) ) +
				'</div>' +
				( voucher.recipient
					? '<p class="hint">' +
						esc( App.t( 'panel.vouchers.forWhom', { name: voucher.recipient } ) ) + '</p>'
					: '' ) +
				'<h3 class="subhead">' + esc( App.t( 'panel.vouchers.movements' ) ) + '</h3>' +
				( voucher.movements.length
					? App.table(
						[
							App.t( 'panel.vouchers.what' ),
							App.t( 'panel.orders.reference' ),
							{ label: App.t( 'panel.vouchers.amount' ), numeric: true },
							App.t( 'panel.vouchers.when' ),
						],
						voucher.movements.map( function ( movement ) {
							return '<tr>' +
								'<td class="table__primary">' +
									esc( App.t( 'panel.vouchers.kindsOfMovement.' + movement.kind ) ) + '</td>' +
								'<td>' + ( movement.order_id
									? '<code>' + esc( movement.order_id ) + '</code>'
									: '—' ) + '</td>' +
								'<td class="tnum">' +
									esc( App.money( movement.amount, voucher.currency ) ) + '</td>' +
								'<td class="tnum">' + esc( App.date( movement.at ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'panel.vouchers.noMovements' ) ) + '</p>' ),
		} );

		bind( 'v-back', function () { Vouchers.render( App ); } );
		bind( 'v-void', function () { Vouchers.confirmVoid(); } );
	};

	Vouchers.confirmVoid = function () {
		var App = Vouchers.App;
		var voucher = Vouchers.voucher;

		App.modal( {
			title: App.t( 'panel.vouchers.voidTitle' ),
			submitLabel: App.t( 'panel.vouchers.void' ),
			danger: true,
			body:
				'<p>' + esc( App.t( 'panel.vouchers.voidBody', {
					amount: App.money( voucher.balance, voucher.currency ),
				} ) ) + '</p>' +
				'<div class="field">' +
					'<label class="field__label" for="v-why">' +
						esc( App.t( 'panel.vouchers.whyVoid' ) ) + '</label>' +
					'<input class="input" id="v-why" maxlength="200">' +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/vouchers/' + voucher.id + '/void', {
					reason: value( 'v-why' ) || null,
				} ).then( function () {
					App.toast( App.t( 'panel.vouchers.voidedNow' ) );
					Vouchers.open( voucher.id );
				} );
			},
		} );
	};

	/* ----------------------------------------------------------------------- the form */

	Vouchers.form = function () {
		var App = Vouchers.App;

		App.modal( {
			title: App.t( 'panel.vouchers.createTitle' ),
			submitLabel: App.t( 'panel.vouchers.create' ),
			body:
				'<div class="stack">' +
				'<div class="field">' +
					'<label class="field__label" for="v-form-kind">' +
						esc( App.t( 'panel.vouchers.kind' ) ) + '</label>' +
					'<select class="select" id="v-form-kind">' +
						[ 'gift', 'credit' ].map( function ( kind ) {
							return '<option value="' + kind + '">' +
								esc( App.t( 'panel.vouchers.kinds.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<span class="field__hint" id="v-kind-hint"></span>' +
				'</div>' +

				'<div class="field" id="v-code-field">' +
					'<label class="field__label" for="v-code">' +
						esc( App.t( 'panel.vouchers.code' ) ) + '</label>' +
					'<div class="filters">' +
						'<input class="input grow" id="v-code" maxlength="40" ' +
							'autocomplete="off" spellcheck="false">' +
						'<button class="btn" type="button" id="v-suggest">' +
							esc( App.t( 'panel.vouchers.suggest' ) ) + '</button>' +
					'</div>' +
					'<span class="field__hint">' + esc( App.t( 'panel.vouchers.codeHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field" id="v-email-field" hidden>' +
					'<label class="field__label" for="v-email">' +
						esc( App.t( 'panel.vouchers.email' ) ) + '</label>' +
					'<input class="input" id="v-email" type="email" maxlength="190" ' +
						'autocomplete="off">' +
					'<span class="field__hint">' + esc( App.t( 'panel.vouchers.emailHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="v-amount">' +
							esc( App.t( 'panel.vouchers.amount' ) ) + '</label>' +
						'<input class="input" id="v-amount" type="number" min="0" step="0.01" ' +
							'inputmode="decimal">' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="v-currency">' +
							esc( App.t( 'panel.vouchers.currency' ) ) + '</label>' +
						'<input class="input" id="v-currency" maxlength="3" value="EUR" ' +
							'autocomplete="off" spellcheck="false">' +
						'<span class="field__hint">' +
							esc( App.t( 'panel.vouchers.currencyHint' ) ) + '</span>' +
					'</div>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="v-recipient">' +
						esc( App.t( 'panel.vouchers.recipient' ) ) + '</label>' +
					'<input class="input" id="v-recipient" maxlength="160">' +
					'<span class="field__hint">' +
						esc( App.t( 'panel.vouchers.recipientHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="v-note">' +
						esc( App.t( 'panel.vouchers.note' ) ) + '</label>' +
					'<input class="input" id="v-note" maxlength="200">' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="v-expires">' +
						esc( App.t( 'panel.vouchers.expires' ) ) + '</label>' +
					'<input class="input" id="v-expires" type="datetime-local">' +
					'<span class="field__hint">' + esc( App.t( 'panel.vouchers.expiresHint' ) ) + '</span>' +
				'</div>' +
				'</div>',
			onSubmit: function () {
				var kind = value( 'v-form-kind' );
				var currency = ( value( 'v-currency' ) || 'EUR' ).toUpperCase();

				return App.request( 'POST', '/vouchers', {
					kind: kind,
					code: 'gift' === kind ? value( 'v-code' ) : null,
					email: 'credit' === kind ? value( 'v-email' ) : null,
					// Typed in the currency's own units and sent in minor ones, through the same
					// conversion the pricing screen uses — so a rial voucher is not a hundredth of
					// what its author meant, and a euro one is not a hundred times it.
					amount: minorUnits( value( 'v-amount' ), currency ),
					currency: currency,
					note: value( 'v-note' ) || null,
					recipient: value( 'v-recipient' ) || null,
					expires_at: isoOrNull( value( 'v-expires' ) ),
				} ).then( function ( saved ) {
					App.toast( App.t( 'panel.vouchers.created' ) );
					Vouchers.open( saved.id );
				} );
			},
		} );

		var kind = document.getElementById( 'v-form-kind' );
		var currency = document.getElementById( 'v-currency' );

		function explain() {
			var gift = 'gift' === kind.value;

			document.getElementById( 'v-kind-hint' ).textContent =
				App.t( 'panel.vouchers.kindHints.' + kind.value );
			document.getElementById( 'v-code-field' ).hidden = ! gift;
			document.getElementById( 'v-email-field' ).hidden = gift;
			// A name on a gift card is decoration; a name on somebody's credit is the wrong field
			// entirely, since the address is who it belongs to.
			document.getElementById( 'v-recipient' ).closest( '.field' ).hidden = ! gift;
		}

		function restep() {
			document.getElementById( 'v-amount' ).step =
				global.SeatmapPricing.step( ( currency.value || 'EUR' ).toUpperCase() );
		}

		kind.addEventListener( 'change', explain );
		currency.addEventListener( 'input', restep );
		explain();
		restep();

		bind( 'v-suggest', function () {
			App.request( 'GET', '/vouchers/suggest' )
				.then( function ( result ) {
					document.getElementById( 'v-code' ).value = result.code;
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	/* ----------------------------------------------------------------------- helpers */

	/** What somebody typed, in the smallest unit the currency has. */
	function minorUnits( typed, currency ) {
		var decimals = global.SeatmapI18n.currencyDecimals( currency );

		return Math.round( ( parseFloat( typed ) || 0 ) * Math.pow( 10, decimals ) );
	}

	/** The input is local wall-clock time; the API is told an instant. */
	function isoOrNull( local ) {
		return local ? new Date( local ).toISOString() : null;
	}

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

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

	global.SeatmapVouchers = Vouchers;
}( typeof window !== 'undefined' ? window : globalThis ) );
