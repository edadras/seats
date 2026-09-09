/**
 * What an event charges, and in what.
 *
 * The currency belongs to the event, not to the account: a company that tours plays Tehran in rials
 * and Berlin in euros, and asking them to keep two accounts for that would be asking them to keep
 * two seat maps. The *formatting* follows whoever is reading (ADR-0005 §5), so the same price
 * appears as ۵۰۰٬۰۰۰ ریال to one member of staff and 500,000 IRR to another.
 *
 * Zones are offered from the categories the published chart actually has, because retyping "Premium"
 * to match a map is exactly the step where somebody types "Premuim" and one section stops pricing.
 *
 * Saving replaces the whole price list in one request. The endpoint is a PUT for the same reason
 * this screen sends everything: partial price edits across zones are where "half the map is priced
 * from last season" comes from.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	/**
	 * Currencies offered by default.
	 *
	 * Not a list of every ISO code — a select with 180 entries is a select nobody scrolls. These are
	 * the ones this platform's organisers actually charge in, and anything else can be typed.
	 */
	var COMMON = [ 'IRR', 'EUR', 'GBP', 'USD', 'AED', 'TRY', 'CHF', 'SEK', 'CAD', 'AUD' ];

	var Pricing = { eventId: null, event: null, zones: [], types: [], CURRENCIES: COMMON };

	/** The four ways a ticket type can relate to the seat's own price. */
	var KINDS = [ 'standard', 'percent_off', 'amount_off', 'fixed' ];

	Pricing.open = function ( App, eventId ) {
		Pricing.eventId = eventId;

		App.loading( App.t( 'pricing.title' ) );

		Promise.all( [
			App.request( 'GET', '/events/' + eventId ),
			// A missing types list is an event that sells one kind of ticket, not an error: this
			// screen has to open on an account that has never touched concessions.
			App.request( 'GET', '/events/' + eventId + '/ticket-types' )
				.catch( function () { return { data: [] }; } ),
		] ).then( function ( answers ) {
			Pricing.event = answers[ 0 ];
			Pricing.zones = Pricing.seed( answers[ 0 ] );
			Pricing.types = answers[ 1 ].data || [];
			Pricing.paint( App );
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * The rows to edit: every zone already priced, plus every category on the map that is not.
	 *
	 * A category with no price is the failure this prevents — the seats render, the buyer clicks,
	 * and the hold is refused because there is no amount for that zone.
	 */
	Pricing.seed = function ( event ) {
		var byKey = {};

		( event.price_zones || [] ).forEach( function ( zone ) { byKey[ zone.key ] = zone; } );

		var rows = ( event.categories || [] ).map( function ( category ) {
			var existing = byKey[ category.key ];
			delete byKey[ category.key ];

			return {
				key: category.key,
				name: existing ? existing.name : category.label,
				amount: existing ? existing.amount : null,
				color: category.color || ( existing && existing.color ) || null,
				onMap: true,
			};
		} );

		// A zone priced for a category the map no longer has. Kept and marked, never dropped
		// silently: it may be the reason a past order has the amount it does.
		Object.keys( byKey ).forEach( function ( key ) {
			rows.push( {
				key: key,
				name: byKey[ key ].name,
				amount: byKey[ key ].amount,
				color: byKey[ key ].color,
				onMap: false,
			} );
		} );

		return rows;
	};

	Pricing.paint = function ( App ) {
		var event = Pricing.event;
		var currency = event.currency || 'EUR';

		App.page( {
			title: App.t( 'pricing.title' ),
			description: App.t( 'pricing.subtitle', { event: event.name } ),
			actions:
				'<button class="btn" id="pricing-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'pricing.back' ) ) + '</button>' +
				'<button class="btn" id="pricing-seats">' + icon( 'layers', { size: 15 } ) +
					esc( App.t( 'pricing.seats.open' ) ) + '</button>' +
				'<button class="btn btn--primary" id="pricing-save">' +
					esc( App.t( 'pricing.save' ) ) + '</button>',
			body:
				'<div class="field field--inline">' +
					'<label class="field__label" for="pricing-currency">' +
						esc( App.t( 'pricing.currency' ) ) + '</label>' +
					'<input class="input input--code" id="pricing-currency" list="pricing-currencies" ' +
						'maxlength="3" value="' + esc( currency ) + '">' +
					'<datalist id="pricing-currencies">' +
						COMMON.map( function ( code ) {
							return '<option value="' + code + '">';
						} ).join( '' ) +
					'</datalist>' +
					'<p class="field__hint">' + esc( App.t( 'pricing.currencyHint' ) ) + '</p>' +
				'</div>' +

				( Pricing.zones.length
					? App.table(
						[ App.t( 'pricing.zone' ), App.t( 'pricing.amount' ), '' ],
						Pricing.zones.map( function ( zone, index ) {
							return Pricing.row( App, zone, index, currency );
						} ).join( '' )
					)
					: App.emptyState( 'tag', App.t( 'pricing.noZones' ), App.t( 'pricing.noZonesHint' ) ) ) +

				'<h3 class="subhead">' + esc( App.t( 'pricing.extras.title' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'pricing.extras.subtitle' ) ) + '</p>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="fee-kind">' +
						esc( App.t( 'pricing.extras.feeKind' ) ) + '</label>' +
						'<select class="select" id="fee-kind">' +
							[ 'none', 'per_order', 'per_ticket' ].map( function ( kind ) {
								return '<option value="' + kind + '"' +
									( kind === ( event.booking_fee_kind || 'none' ) ? ' selected' : '' ) + '>' +
									esc( App.t( 'pricing.extras.feeKinds.' + kind ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					'<div class="field"><label class="field__label" for="fee-amount">' +
						esc( App.t( 'pricing.extras.feeAmount' ) ) + '</label>' +
						'<input class="input tnum" id="fee-amount" type="number" min="0" ' +
							'step="' + ( Pricing.decimals( currency ) ? Math.pow( 10, -Pricing.decimals( currency ) ).toFixed( Pricing.decimals( currency ) ) : '1' ) + '" ' +
							'value="' + esc( Pricing.asMajor( event.booking_fee_amount, currency ) ) + '"></div>' +
					'<div class="field"><label class="field__label" for="fee-percent">' +
						esc( App.t( 'pricing.extras.feePercent' ) ) + '</label>' +
						'<input class="input tnum" id="fee-percent" type="number" min="0" max="100" value="' +
							esc( event.booking_fee_percent || 0 ) + '"></div>' +
				'</div>' +
				'<div class="field"><label class="field__label" for="fee-label">' +
					esc( App.t( 'pricing.extras.feeLabel' ) ) + '</label>' +
					'<input class="input" id="fee-label" maxlength="60" value="' +
						esc( event.booking_fee_label || '' ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'pricing.extras.feeLabelHint' ) ) + '</span></div>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="tax-rate">' +
						esc( App.t( 'pricing.extras.taxRate' ) ) + '</label>' +
						// Basis points on the wire, per cent on the screen: nobody types 1900 for 19%.
						'<input class="input tnum" id="tax-rate" type="number" min="0" max="100" step="0.01" value="' +
							esc( ( ( event.tax_rate || 0 ) / 100 ).toFixed( 2 ) ) + '"></div>' +
					'<div class="field"><label class="field__label" for="tax-label">' +
						esc( App.t( 'pricing.extras.taxLabel' ) ) + '</label>' +
						'<input class="input" id="tax-label" maxlength="40" value="' +
							esc( event.tax_label || '' ) + '"></div>' +
				'</div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="tax-included"' +
					( false === event.tax_included ? '' : ' checked' ) + '>' +
					'<span>' + esc( App.t( 'pricing.extras.taxIncluded' ) ) + '</span></label>' +
				'<p class="hint">' + esc( App.t( 'pricing.extras.taxIncludedHint' ) ) + '</p>' +

				'<h3 class="subhead">' + esc( App.t( 'pricing.types.title' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'pricing.types.subtitle' ) ) + '</p>' +
				( Pricing.types.length
					? App.table(
						[
							App.t( 'pricing.types.name' ),
							App.t( 'pricing.types.takesOff' ),
							App.t( 'pricing.types.limits' ),
							App.t( 'pricing.types.sold' ),
							'',
						],
						Pricing.types.map( function ( type, index ) {
							return Pricing.typeRow( App, type, index, currency );
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'pricing.types.none' ) ) + '</p>' ) +
				'<p class="spaced">' +
					'<button class="btn" id="pricing-type-add">' + icon( 'plus', { size: 15 } ) +
						esc( App.t( 'pricing.types.add' ) ) + '</button>' +
				'</p>',
		} );

		Pricing.bind( App );
	};

	Pricing.row = function ( App, zone, index, currency ) {
		// Shown in the currency's own minor unit: two boxes for a euro, none for a rial. Asking
		// for "500000.00" tomans is asking somebody to type a number they never say out loud.
		var decimals = Pricing.decimals( currency );
		var value = null === zone.amount ? '' : ( zone.amount / Math.pow( 10, decimals ) ).toFixed( decimals );

		return '<tr' + ( zone.onMap ? '' : ' class="is-muted"' ) + '>' +
			'<td class="table__primary">' +
				( zone.color
					? '<span class="zone-dot" style="background:' + esc( zone.color ) + '"></span>'
					: '' ) +
				esc( zone.name ) +
				( zone.onMap
					? ''
					: '<span class="muted on-own-line">' + esc( App.t( 'pricing.notOnMap' ) ) + '</span>' ) +
			'</td>' +
			'<td>' +
				'<input class="input input--amount tnum" type="number" min="0" ' +
					'step="' + ( decimals ? Math.pow( 10, -decimals ).toFixed( decimals ) : '1' ) + '" ' +
					'data-amount="' + index + '" value="' + esc( value ) + '" ' +
					'aria-label="' + esc( zone.name ) + '">' +
			'</td>' +
			'<td class="muted tnum" data-preview="' + index + '">' +
				esc( null === zone.amount ? '' : App.money( zone.amount, currency, decimals ) ) +
			'</td>' +
		'</tr>';
	};

	/**
	 * One ticket type, said in terms of the seat's price rather than in terms of itself.
	 *
	 * "25% off" and not "75%": an organiser thinks in what they are giving away, and a table that
	 * makes them do the subtraction is a table somebody mis-reads at the end of a long day.
	 */
	Pricing.typeRow = function ( App, type, index, currency ) {
		var decimals = Pricing.decimals( currency );

		var takesOff = 'percent_off' === type.kind
			? App.t( 'pricing.types.percentOff', { value: App.number( type.value ) } )
			: ( 'amount_off' === type.kind
				? App.t( 'pricing.types.amountOff', { amount: App.money( type.value, currency, decimals ) } )
				: ( 'fixed' === type.kind
					? App.t( 'pricing.types.flat', { amount: App.money( type.value, currency, decimals ) } )
					: App.t( 'pricing.types.fullPrice' ) ) );

		var limits = [];

		if ( type.min_per_order ) {
			limits.push( App.t( 'pricing.types.atLeast', { count: App.number( type.min_per_order ) } ) );
		}

		if ( type.max_per_order ) {
			limits.push( App.t( 'pricing.types.atMost', { count: App.number( type.max_per_order ) } ) );
		}

		return '<tr' + ( 'hidden' === type.status ? ' class="is-muted"' : '' ) + '>' +
			'<td class="table__primary">' + esc( type.name ) +
				( type.is_default
					? ' <span class="badge badge--neutral">' + esc( App.t( 'pricing.types.default' ) ) + '</span>'
					: '' ) +
				( 'hidden' === type.status
					? ' <span class="badge badge--neutral">' + esc( App.t( 'pricing.types.hidden' ) ) + '</span>'
					: '' ) +
				( type.proof_note
					? '<span class="muted on-own-line">' + esc( type.proof_note ) + '</span>'
					: '' ) +
			'</td>' +
			'<td>' + esc( takesOff ) + '</td>' +
			'<td class="muted">' + esc( limits.join( ' · ' ) || '—' ) + '</td>' +
			'<td class="tnum">' + esc( App.number( type.sold || 0 ) ) + '</td>' +
			'<td class="table__actions">' +
				'<button class="btn btn--sm" data-type-edit="' + index + '">' +
					esc( App.t( 'pricing.types.edit' ) ) + '</button>' +
				( type.sold
					? ''
					: '<button class="btn btn--sm" data-type-drop="' + index + '">' +
						esc( App.t( 'pricing.types.remove' ) ) + '</button>' ) +
			'</td>' +
		'</tr>';
	};

	/**
	 * The form for one type.
	 *
	 * `value` is asked for in the currency's own minor unit for the two money kinds and as a plain
	 * number for the percentage — the same trick the zone rows use, and for the same reason.
	 */
	Pricing.typeForm = function ( App, index ) {
		var currency = normaliseCode( document.getElementById( 'pricing-currency' ).value );
		var decimals = Pricing.decimals( currency );
		var type = null === index ? {
			name: '', kind: 'percent_off', value: 0, is_default: ! Pricing.types.length,
			min_per_order: 0, max_per_order: null, proof_note: '', status: 'active',
		} : Pricing.types[ index ];

		var asMoney = function ( minor ) {
			return decimals ? ( ( minor || 0 ) / Math.pow( 10, decimals ) ).toFixed( decimals ) : String( minor || 0 );
		};

		App.modal( {
			title: App.t( null === index ? 'pricing.types.add' : 'pricing.types.edit' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="t-name">' +
					esc( App.t( 'pricing.types.name' ) ) + '</label>' +
					'<input class="input" id="t-name" maxlength="80" required value="' +
						esc( type.name ) + '"></div>' +
				'<div class="field"><label class="field__label" for="t-note">' +
					esc( App.t( 'pricing.types.proof' ) ) + '</label>' +
					'<input class="input" id="t-note" maxlength="160" value="' +
						esc( type.proof_note || '' ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'pricing.types.proofHint' ) ) + '</span></div>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="t-kind">' +
						esc( App.t( 'pricing.types.takesOff' ) ) + '</label>' +
						'<select class="select" id="t-kind">' +
							KINDS.map( function ( kind ) {
								return '<option value="' + kind + '"' +
									( kind === type.kind ? ' selected' : '' ) + '>' +
									esc( App.t( 'pricing.types.kinds.' + kind ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					'<div class="field" id="t-value-field"><label class="field__label" for="t-value">' +
						esc( App.t( 'pricing.types.value' ) ) + '</label>' +
						'<input class="input tnum" id="t-value" type="number" min="0" value="' +
							esc( 'percent_off' === type.kind ? ( type.value || 0 ) : asMoney( type.value ) ) +
						'"><span class="field__hint" id="t-value-hint"></span></div>' +
				'</div>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="t-min">' +
						esc( App.t( 'pricing.types.min' ) ) + '</label>' +
						'<input class="input tnum" id="t-min" type="number" min="0" value="' +
							esc( type.min_per_order || 0 ) + '"></div>' +
					'<div class="field"><label class="field__label" for="t-max">' +
						esc( App.t( 'pricing.types.max' ) ) + '</label>' +
						'<input class="input tnum" id="t-max" type="number" min="1" value="' +
							esc( type.max_per_order || '' ) + '"></div>' +
				'</div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="t-default"' +
					( type.is_default ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'pricing.types.isDefault' ) ) + '</span></label>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="t-hidden"' +
					( 'hidden' === type.status ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'pricing.types.isHidden' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var kind = document.getElementById( 't-kind' ).value;
				var raw = Number( document.getElementById( 't-value' ).value || 0 );
				var next = {
					id: type.id || null,
					name: document.getElementById( 't-name' ).value.trim(),
					kind: kind,
					value: 'percent_off' === kind
						? Math.round( raw )
						: Math.round( raw * Math.pow( 10, decimals ) ),
					is_default: document.getElementById( 't-default' ).checked,
					min_per_order: Number( document.getElementById( 't-min' ).value || 0 ),
					max_per_order: document.getElementById( 't-max' ).value
						? Number( document.getElementById( 't-max' ).value )
						: null,
					proof_note: document.getElementById( 't-note' ).value.trim() || null,
					status: document.getElementById( 't-hidden' ).checked ? 'hidden' : 'active',
					sold: type.sold || 0,
				};

				if ( ! next.name ) {
					App.toast( App.t( 'pricing.types.needName' ), true );

					return true;
				}

				if ( next.is_default ) {
					Pricing.types.forEach( function ( other ) { other.is_default = false; } );
				}

				if ( null === index ) {
					Pricing.types.push( next );
				} else {
					Pricing.types[ index ] = next;
				}

				return Pricing.saveTypes( App );
			},
		} );

		var kindField = document.getElementById( 't-kind' );

		function shape() {
			var isPercent = 'percent_off' === kindField.value;

			document.getElementById( 't-value-field' ).hidden = 'standard' === kindField.value;
			document.getElementById( 't-value-hint' ).textContent = App.t( isPercent
				? 'pricing.types.valuePercent'
				: 'pricing.types.valueMoney' );
		}

		kindField.addEventListener( 'change', shape );
		shape();
	};

	/** The whole list, every time — the same contract the zone prices are saved under. */
	Pricing.saveTypes = function ( App ) {
		return App.request( 'PUT', '/events/' + Pricing.eventId + '/ticket-types', {
			types: Pricing.types.map( function ( type ) {
				return {
					id: type.id || null,
					name: type.name,
					description: type.description || null,
					kind: type.kind,
					value: type.value,
					is_default: !! type.is_default,
					min_per_order: type.min_per_order || 0,
					max_per_order: type.max_per_order,
					proof_note: type.proof_note,
					status: type.status || 'active',
				};
			} ),
		} ).then( function ( response ) {
			Pricing.types = response.data || [];
			App.toast( App.t( 'pricing.types.saved' ) );
			Pricing.paint( App );
		} );
	};

	Pricing.bind = function ( App ) {
		var currencyField = document.getElementById( 'pricing-currency' );

		currencyField.addEventListener( 'change', function () {
			// Repainting on a currency change is not laziness: the number of decimal boxes and the
			// preview beside each row both depend on it, and a rial priced through a euro's two
			// decimal places is a hundredfold error waiting to be saved.
			Pricing.readAmounts( currencyField.value );
			Pricing.event.currency = normaliseCode( currencyField.value );
			Pricing.paint( App );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-amount]' ), function ( input ) {
			input.addEventListener( 'input', function () {
				var index = Number( input.dataset.amount );
				var currency = normaliseCode( currencyField.value );
				var decimals = Pricing.decimals( currency );
				var amount = '' === input.value ? null
					: Math.round( Number( input.value ) * Math.pow( 10, decimals ) );

				Pricing.zones[ index ].amount = amount;

				var preview = document.querySelector( '[data-preview="' + index + '"]' );

				if ( preview ) {
					preview.textContent = null === amount ? '' : App.money( amount, currency, decimals );
				}
			} );
		} );

		document.getElementById( 'pricing-back' )
			.addEventListener( 'click', function () { App.renderEvents(); } );

		document.getElementById( 'pricing-seats' ).addEventListener( 'click', function () {
			// Zone prices are not carried over unsaved: the seat screen reads what the server
			// holds, and showing it amounts that exist only in this tab would be a lie about the
			// hall. Anything typed here and not saved stays behind, which is why it says so.
			global.SeatmapSeatPrices.open( App, Pricing.eventId, Pricing.event.name );
		} );

		document.getElementById( 'pricing-save' )
			.addEventListener( 'click', function () { Pricing.save( App ); } );

		document.getElementById( 'pricing-type-add' ).addEventListener( 'click', function () {
			Pricing.typeForm( App, null );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-type-edit]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.typeForm( App, Number( button.dataset.typeEdit ) );
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-type-drop]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.types.splice( Number( button.dataset.typeDrop ), 1 );
				Pricing.saveTypes( App ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );
	};

	/** Take whatever is in the boxes right now, in the currency they were typed under. */
	Pricing.readAmounts = function ( currencyCode ) {
		var decimals = Pricing.decimals( normaliseCode( currencyCode ) );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-amount]' ), function ( input ) {
			var index = Number( input.dataset.amount );

			Pricing.zones[ index ].amount = '' === input.value
				? null
				: Math.round( Number( input.value ) * Math.pow( 10, decimals ) );
		} );
	};

	Pricing.save = function ( App ) {
		var currencyField = document.getElementById( 'pricing-currency' );
		var currency = normaliseCode( currencyField.value );

		Pricing.readAmounts( currency );

		var priced = Pricing.zones.filter( function ( zone ) { return null !== zone.amount; } );

		if ( ! priced.length ) {
			App.toast( App.t( 'pricing.needOne' ), true );

			return;
		}

		// A category on the map with no price is a section a buyer can click and not book. Said
		// before saving, not discovered by a customer at eleven at night.
		var unpriced = Pricing.zones.filter( function ( zone ) {
			return zone.onMap && null === zone.amount;
		} );

		var decimals = Pricing.decimals( currency );

		App.request( 'PUT', '/events/' + Pricing.eventId + '/pricing', {
			currency: currency,
			zones: priced.map( function ( zone ) {
				return { key: zone.key, name: zone.name, amount: zone.amount, color: zone.color };
			} ),
			booking_fee_kind: document.getElementById( 'fee-kind' ).value,
			booking_fee_amount: Math.round(
				Number( document.getElementById( 'fee-amount' ).value || 0 ) * Math.pow( 10, decimals )
			),
			booking_fee_percent: Math.round( Number( document.getElementById( 'fee-percent' ).value || 0 ) ),
			booking_fee_label: document.getElementById( 'fee-label' ).value.trim() || null,
			// Back to basis points, so 8.75% survives the round trip as 875 rather than as 9.
			tax_rate: Math.round( Number( document.getElementById( 'tax-rate' ).value || 0 ) * 100 ),
			tax_included: document.getElementById( 'tax-included' ).checked,
			tax_label: document.getElementById( 'tax-label' ).value.trim() || null,
		} ).then( function () {
			App.toast( unpriced.length
				? App.t( 'pricing.savedWithGaps', { count: App.number( unpriced.length ) } )
				: App.t( 'pricing.saved' ) );

			App.renderEvents();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/** Minor units as the number a person types, in the currency's own decimal places. */
	Pricing.asMajor = function ( minor, currency ) {
		var decimals = Pricing.decimals( currency );

		return decimals
			? ( ( minor || 0 ) / Math.pow( 10, decimals ) ).toFixed( decimals )
			: String( minor || 0 );
	};

	/**
	 * How many decimal places this currency has — asked of the one place that knows.
	 *
	 * The browser's own ICU data answers it there, so this file does not carry a second copy of
	 * the table the server keeps, and the two cannot disagree about the rial.
	 */
	Pricing.decimals = function ( currency ) {
		return global.SeatmapI18n.currencyDecimals( currency );
	};

	function normaliseCode( value ) {
		return String( value || '' ).trim().toUpperCase().slice( 0, 3 ) || 'EUR';
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapPricing = Pricing;
}( window ) );
