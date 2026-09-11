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

	var Pricing = {
		eventId: null,
		event: null,
		zones: [],
		types: [],
		addons: [],
		// What this night asks for beside a price, which is a different question from what it
		// charges — so it is edited here and stored on the event.
		donations: { offered: false, prompt: '', suggested: null },
		CURRENCIES: COMMON,
	};

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
			// Same reasoning: an account that has never sold a programme opens this screen too.
			App.request( 'GET', '/events/' + eventId + '/addons' )
				.catch( function () { return { data: [], donations: {} }; } ),
			// And one that has never dated a price change.
			App.request( 'GET', '/events/' + eventId + '/price-tiers' )
				.catch( function () { return { data: [], active: null }; } ),
			// And one that has never let the room's own fullness move a price.
			App.request( 'GET', '/events/' + eventId + '/demand-pricing' )
				.catch( function () {
					return { data: [], demand_pricing: false, sold_percent: 0, capacity: 0 };
				} ),
		] ).then( function ( answers ) {
			Pricing.event = answers[ 0 ];
			Pricing.zones = Pricing.seed( answers[ 0 ] );
			Pricing.types = answers[ 1 ].data || [];
			Pricing.addons = answers[ 2 ].data || [];
			Pricing.donations = answers[ 2 ].donations || { offered: false, prompt: '', suggested: null };
			Pricing.tiers = answers[ 3 ].data || [];
			Pricing.activeTier = answers[ 3 ].active || null;
			Pricing.demand = answers[ 4 ] || {};
			Pricing.steps = ( answers[ 4 ] && answers[ 4 ].data ) || [];
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

				Pricing.tiersSection( App, currency ) +
				Pricing.demandSection( App, currency ) +

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
							'step="' + Pricing.step( currency ) + '" ' +
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
				'</p>' +

				'<h3 class="subhead">' + esc( App.t( 'pricing.addons.title' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'pricing.addons.subtitle' ) ) + '</p>' +
				( Pricing.addons.length
					? App.table(
						[
							App.t( 'pricing.addons.name' ),
							App.t( 'pricing.addons.price' ),
							App.t( 'pricing.addons.stock' ),
							App.t( 'pricing.addons.sold' ),
							'',
						],
						Pricing.addons.map( function ( addon, index ) {
							return Pricing.addonRow( App, addon, index, currency );
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'pricing.addons.none' ) ) + '</p>' ) +
				'<p class="spaced">' +
					'<button class="btn" id="pricing-addon-add">' + icon( 'plus', { size: 15 } ) +
						esc( App.t( 'pricing.addons.add' ) ) + '</button>' +
				'</p>' +

				'<h3 class="subhead">' + esc( App.t( 'pricing.donations.title' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'pricing.donations.subtitle' ) ) + '</p>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="donations-on"' +
					( Pricing.donations.offered ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'pricing.donations.ask' ) ) + '</span></label>' +
				'<div class="field-duo spaced">' +
					'<div class="field"><label class="field__label" for="donation-prompt">' +
						esc( App.t( 'pricing.donations.prompt' ) ) + '</label>' +
						'<input class="input" id="donation-prompt" maxlength="200" value="' +
							esc( Pricing.donations.prompt || '' ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'pricing.donations.promptHint' ) ) +
						'</span></div>' +
					'<div class="field"><label class="field__label" for="donation-suggested">' +
						esc( App.t( 'pricing.donations.suggested' ) ) + '</label>' +
						'<input class="input tnum" id="donation-suggested" type="number" min="0" ' +
							'step="' + Pricing.step( currency ) + '" value="' +
							esc( Pricing.asMajor( Pricing.donations.suggested, currency ) ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'pricing.donations.suggestedHint' ) ) +
						'</span></div>' +
				'</div>',
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

	/* ------------------------------------------------------------------------- add-ons */

	Pricing.addonRow = function ( App, addon, index, currency ) {
		var stock = null === addon.stock
			? App.t( 'pricing.addons.unlimited' )
			: App.t( 'pricing.addons.ofStock', {
				left: App.number( addon.remaining ),
				stock: App.number( addon.stock ),
			} );

		return '<tr' + ( addon.visible ? '' : ' class="is-muted"' ) + '>' +
			'<td class="table__primary">' + esc( addon.name ) +
				( addon.visible
					? ''
					: ' <span class="badge badge--neutral">' +
						esc( App.t( 'pricing.addons.hidden' ) ) + '</span>' ) +
				( 'ticket' === addon.per
					? ' <span class="badge badge--neutral">' +
						esc( App.t( 'pricing.addons.perTicket' ) ) + '</span>'
					: '' ) +
				( addon.description
					? '<span class="muted on-own-line">' + esc( addon.description ) + '</span>'
					: '' ) +
			'</td>' +
			'<td class="tnum">' + esc( App.money( addon.price, addon.currency || currency ) ) + '</td>' +
			'<td class="muted">' + esc( stock ) + '</td>' +
			'<td class="tnum">' + esc( App.number( addon.sold || 0 ) ) + '</td>' +
			'<td class="table__actions">' +
				'<button class="btn btn--sm" data-addon-edit="' + index + '">' +
					esc( App.t( 'pricing.addons.edit' ) ) + '</button>' +
				( addon.sold
					? ''
					: '<button class="btn btn--sm" data-addon-drop="' + index + '">' +
						esc( App.t( 'pricing.addons.remove' ) ) + '</button>' ) +
			'</td>' +
		'</tr>';
	};

	/**
	 * The form for one thing on the counter.
	 *
	 * The price is asked for in the currency's own minor unit, the same trick the zone rows use:
	 * two boxes for a euro, none for a rial.
	 */
	Pricing.addonForm = function ( App, index ) {
		var currency = normaliseCode( document.getElementById( 'pricing-currency' ).value );
		var decimals = Pricing.decimals( currency );
		var addon = null === index ? {
			name: '', description: '', price: 0, stock: null, max_per_order: 10,
			per: 'order', visible: true,
		} : Pricing.addons[ index ];

		App.modal( {
			title: App.t( null === index ? 'pricing.addons.add' : 'pricing.addons.edit' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<div class="field"><label class="field__label" for="x-name">' +
						esc( App.t( 'pricing.addons.name' ) ) + '</label>' +
						'<input class="input" id="x-name" maxlength="120" required value="' +
							esc( addon.name || '' ) + '"></div>' +

					'<div class="field"><label class="field__label" for="x-description">' +
						esc( App.t( 'pricing.addons.description' ) ) + '</label>' +
						'<input class="input" id="x-description" maxlength="400" value="' +
							esc( addon.description || '' ) + '"></div>' +

					'<div class="field-duo">' +
						'<div class="field"><label class="field__label" for="x-price">' +
							esc( App.t( 'pricing.addons.price' ) ) + '</label>' +
							'<input class="input tnum" id="x-price" type="number" min="0" step="' +
								Pricing.step( currency ) + '" value="' +
								esc( Pricing.asMajor( addon.price, currency ) ) + '"></div>' +
						'<div class="field"><label class="field__label" for="x-per">' +
							esc( App.t( 'pricing.addons.per' ) ) + '</label>' +
							'<select class="select" id="x-per">' +
								[ 'order', 'ticket' ].map( function ( kind ) {
									return '<option value="' + kind + '"' +
										( kind === ( addon.per || 'order' ) ? ' selected' : '' ) + '>' +
										esc( App.t( 'pricing.addons.pers.' + kind ) ) + '</option>';
								} ).join( '' ) +
							'</select>' +
							'<span class="field__hint" id="x-per-hint"></span></div>' +
					'</div>' +

					'<div class="field-duo">' +
						'<div class="field"><label class="field__label" for="x-stock">' +
							esc( App.t( 'pricing.addons.stock' ) ) + '</label>' +
							'<input class="input tnum" id="x-stock" type="number" min="0" value="' +
								esc( null === addon.stock ? '' : addon.stock ) + '">' +
							'<span class="field__hint">' + esc( App.t( 'pricing.addons.stockHint' ) ) +
							'</span></div>' +
						'<div class="field" id="x-max-field"><label class="field__label" for="x-max">' +
							esc( App.t( 'pricing.addons.maxPerOrder' ) ) + '</label>' +
							'<input class="input tnum" id="x-max" type="number" min="1" max="999" value="' +
								esc( addon.max_per_order || 10 ) + '"></div>' +
					'</div>' +

					'<label class="perms__row"><input type="checkbox" class="checkbox" id="x-visible"' +
						( false === addon.visible ? '' : ' checked' ) + '>' +
						'<span>' + esc( App.t( 'pricing.addons.visible' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var typed = document.getElementById( 'x-price' ).value;
				var stock = document.getElementById( 'x-stock' ).value;
				var row = {
					id: addon.id || null,
					name: document.getElementById( 'x-name' ).value.trim(),
					description: document.getElementById( 'x-description' ).value.trim() || null,
					price: Math.round( Number( typed || 0 ) * Math.pow( 10, decimals ) ),
					stock: '' === stock ? null : Math.max( 0, parseInt( stock, 10 ) || 0 ),
					max_per_order: parseInt( document.getElementById( 'x-max' ).value, 10 ) || 10,
					per: document.getElementById( 'x-per' ).value,
					visible: document.getElementById( 'x-visible' ).checked,
				};

				if ( '' === row.name ) {
					return Promise.reject( new Error( App.t( 'pricing.addons.needsName' ) ) );
				}

				if ( null === index ) {
					Pricing.addons.push( row );
				} else {
					Pricing.addons[ index ] = row;
				}

				return Pricing.saveAddons( App );
			},
		} );

		var per = document.getElementById( 'x-per' );

		// One per ticket is not a choice the buyer makes, so the "how many at once" box has
		// nothing to say about it.
		function shape() {
			document.getElementById( 'x-max-field' ).hidden = 'ticket' === per.value;
			document.getElementById( 'x-per-hint' ).textContent =
				App.t( 'pricing.addons.perHints.' + per.value );
		}

		per.addEventListener( 'change', shape );
		shape();
	};

	/** The whole list, every time — the same contract the zone prices and the types are saved under. */
	Pricing.saveAddons = function ( App ) {
		var suggested = document.getElementById( 'donation-suggested' );
		var currency = normaliseCode( document.getElementById( 'pricing-currency' ).value );
		var decimals = Pricing.decimals( currency );

		return App.request( 'PUT', '/events/' + Pricing.eventId + '/addons', {
			addons: Pricing.addons.map( function ( addon ) {
				return {
					id: addon.id || null,
					name: addon.name,
					description: addon.description || null,
					price: addon.price,
					stock: null === addon.stock || undefined === addon.stock ? null : addon.stock,
					max_per_order: addon.max_per_order || 10,
					per: addon.per || 'order',
					visible: false !== addon.visible,
				};
			} ),
			donations: document.getElementById( 'donations-on' ).checked,
			donation_prompt: document.getElementById( 'donation-prompt' ).value.trim() || null,
			donation_suggested: '' === suggested.value
				? null
				: Math.round( Number( suggested.value ) * Math.pow( 10, decimals ) ),
		} ).then( function ( response ) {
			Pricing.addons = response.data || [];
			Pricing.donations = response.donations || Pricing.donations;
			App.toast( App.t( 'pricing.addons.saved' ) );
			Pricing.paint( App );
		} );
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

		document.getElementById( 'tier-add' ).addEventListener( 'click', function () {
			Pricing.readTiers( document.getElementById( 'pricing-currency' ).value );

			/*
			 * A new tier begins where the last one ended.
			 *
			 * Anything else is an overlap the moment it appears — two windows both claiming today —
			 * and an organiser would meet a refusal for a row they have not finished typing. Where
			 * the last tier ran on for ever, adding another one is *saying* when it stops, so it
			 * is given that end rather than being left to argue with its successor.
			 */
			var last = Pricing.tiers[ Pricing.tiers.length - 1 ];
			var week = new Date();

			week.setDate( week.getDate() + 7 );

			var from = last && last.ends_at ? last.ends_at : week.toISOString();

			if ( last && ! last.ends_at ) {
				last.ends_at = from;
			}

			Pricing.tiers.push( {
				name: App.t( 'pricing.tiers.newName' ), starts_at: from, ends_at: null,
				kind: 'percent', value: 0, active: false,
			} );
			Pricing.paint( App );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-tier-drop]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.readTiers( document.getElementById( 'pricing-currency' ).value );
				Pricing.tiers.splice( Number( button.dataset.tierDrop ), 1 );
				Pricing.saveTiers( App ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-tier-name], [data-tier-from], [data-tier-until], [data-tier-kind], [data-tier-value]' ),
			function ( input ) {
				// Saved when the box is left rather than on every keystroke: a half-typed date is
				// an overlap the server would have to refuse, loudly, for no reason.
				input.addEventListener( 'change', function () {
					Pricing.saveTiers( App ).catch( function ( error ) { App.toast( error.message, true ); } );
				} );
			}
		);

		document.getElementById( 'demand-add' ).addEventListener( 'click', function () {
			Pricing.readDemand( document.getElementById( 'pricing-currency' ).value );

			/*
			 * A new rung starts above the last one.
			 *
			 * Two rungs at the same percentage is a refusal, and meeting one for a row somebody has
			 * not finished typing is the same unhelpfulness the tiers avoid by starting each new
			 * window where the last one ended.
			 */
			var last = Pricing.steps[ Pricing.steps.length - 1 ];
			var from = last ? Math.min( 100, last.sold_from + 25 ) : 50;

			Pricing.steps.push( {
				name: App.t( 'pricing.demand.newName' ),
				sold_from: from, kind: 'percent', value: 0,
			} );
			Pricing.paint( App );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-step-drop]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.readDemand( document.getElementById( 'pricing-currency' ).value );
				Pricing.steps.splice( Number( button.dataset.stepDrop ), 1 );
				Pricing.saveDemand( App ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		Array.prototype.forEach.call(
			document.querySelectorAll( '#demand-on, #demand-floor, #demand-ceiling, [data-step-name], [data-step-from], [data-step-kind], [data-step-value]' ),
			function ( input ) {
				input.addEventListener( 'change', function () {
					Pricing.saveDemand( App ).catch( function ( error ) { App.toast( error.message, true ); } );
				} );
			}
		);

		document.getElementById( 'pricing-addon-add' ).addEventListener( 'click', function () {
			Pricing.addonForm( App, null );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-addon-edit]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.addonForm( App, Number( button.dataset.addonEdit ) );
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-addon-drop]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Pricing.addons.splice( Number( button.dataset.addonDrop ), 1 );
				Pricing.saveAddons( App ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		// The donation settings ride with the add-ons, because they are saved by the same endpoint
		// and an organiser who ticks the box expects it to stick without hunting for a button.
		[ 'donations-on', 'donation-prompt', 'donation-suggested' ].forEach( function ( id ) {
			document.getElementById( id ).addEventListener( 'change', function () {
				Pricing.saveAddons( App ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );
	};

	/** An ISO instant as the local wall-clock string a datetime-local input wants. */
	function localInput( iso ) {
		if ( ! iso ) {
			return '';
		}

		var when = new Date( iso );
		var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };

		return when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() ) +
			'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );
	}

	/** And back again — the input is local time, the API is told an instant. */
	function isoOrNull( local ) {
		return local ? new Date( local ).toISOString() : null;
	}

	/** A deadline, written the way the reader's own language writes one. */
	function localWhen( iso ) {
		return iso ? new Date( iso ).toLocaleString() : '';
	}

	/**
	 * When the prices above change, and by how much.
	 *
	 * Deliberately underneath the zone table and not a screen of its own: a tier is a sentence
	 * about the numbers directly above it, and an organiser who cannot see both at once is
	 * guessing what "minus twenty per cent" will actually charge.
	 */
	Pricing.tiersSection = function ( App, currency ) {
		var live = Pricing.activeTier;

		return '<h3 class="subhead">' + esc( App.t( 'pricing.tiers.title' ) ) + '</h3>' +
			'<p class="hint">' + esc( App.t( 'pricing.tiers.subtitle' ) ) + '</p>' +
			( live
				? '<p class="notice notice--info" id="tier-live">' +
					esc( live.ends_at
						? App.t( 'pricing.tiers.liveUntil', { name: live.name, until: localWhen( live.ends_at ) } )
						: App.t( 'pricing.tiers.live', { name: live.name } ) ) + '</p>'
				: '' ) +
			( Pricing.tiers.length
				? App.table(
					[
						App.t( 'pricing.tiers.name' ), App.t( 'pricing.tiers.from' ),
						App.t( 'pricing.tiers.until' ), App.t( 'pricing.tiers.change' ), '',
					],
					Pricing.tiers.map( function ( tier, index ) {
						return Pricing.tierRow( App, tier, index, currency );
					} ).join( '' )
				)
				: App.emptyState( 'clock', App.t( 'pricing.tiers.none' ), App.t( 'pricing.tiers.noneHint' ) ) ) +
			'<button class="btn" id="tier-add">' + esc( App.t( 'pricing.tiers.add' ) ) + '</button>';
	};

	Pricing.tierRow = function ( App, tier, index, currency ) {
		var isAmount = 'amount' === tier.kind;

		return '<tr' + ( tier.active ? ' class="is-live"' : '' ) + '>' +
			'<td><input class="input" data-tier-name="' + index + '" maxlength="120" value="' +
				esc( tier.name || '' ) + '"></td>' +
			'<td><input class="input" type="datetime-local" data-tier-from="' + index + '" value="' +
				esc( localInput( tier.starts_at ) ) + '"></td>' +
			'<td><input class="input" type="datetime-local" data-tier-until="' + index + '" value="' +
				esc( localInput( tier.ends_at ) ) + '"></td>' +
			'<td class="row row--wrap">' +
				'<select class="select" data-tier-kind="' + index + '">' +
					[ 'percent', 'amount' ].map( function ( kind ) {
						return '<option value="' + kind + '"' + ( kind === tier.kind ? ' selected' : '' ) + '>' +
							esc( App.t( 'pricing.tiers.kinds.' + kind ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
				'<input class="input tnum" type="number" data-tier-value="' + index + '" ' +
					( isAmount ? 'step="' + Pricing.step( currency ) + '" ' : 'step="1" ' ) +
					'value="' + esc( isAmount ? Pricing.asMajor( tier.value, currency ) : ( tier.value || 0 ) ) + '">' +
			'</td>' +
			'<td class="row row--end"><button class="btn btn--quiet" data-tier-drop="' + index + '">' +
				esc( App.t( 'pricing.tiers.remove' ) ) + '</button></td></tr>';
	};

	/**
	 * Pricing by how much is left.
	 *
	 * Under the timed tiers because that is the order the two apply in, and because an organiser
	 * reading down the screen is reading the arithmetic in the order it happens: the published
	 * price for this window, then what the room's own fullness does to it, then the rails.
	 *
	 * The switch, the ladder and the rails are one section and one save. Turning this on without a
	 * ceiling is the mistake it is most able to make, and a screen that let somebody do it in two
	 * steps would let them stop after the first.
	 */
	Pricing.demandSection = function ( App, currency ) {
		var state = Pricing.demand || {};
		var on = !! state.demand_pricing;

		return '<h3 class="subhead">' + esc( App.t( 'pricing.demand.title' ) ) + '</h3>' +
			'<p class="hint">' + esc( App.t( 'pricing.demand.subtitle' ) ) + '</p>' +

			// How the night is actually going, said whether or not the switch is on: that is the
			// number somebody needs in order to decide whether to turn it on at all.
			'<p class="notice notice--info">' + esc( App.t( 'pricing.demand.soldNow', {
				percent: App.number( state.sold_percent || 0 ),
				capacity: App.number( state.capacity || 0 ),
			} ) ) + '</p>' +

			'<label class="perms__row"><input type="checkbox" class="checkbox" id="demand-on"' +
				( on ? ' checked' : '' ) + '>' +
				'<span>' + esc( App.t( 'pricing.demand.switchOn' ) ) +
					'<span class="muted on-own-line">' +
					esc( App.t( 'pricing.demand.switchHint' ) ) + '</span></span></label>' +

			'<div class="field-duo">' +
				'<div class="field"><label class="field__label" for="demand-floor">' +
					esc( App.t( 'pricing.demand.floor' ) ) + '</label>' +
					'<input class="input tnum" id="demand-floor" type="number" min="0" step="' +
						Pricing.step( currency ) + '" value="' +
						esc( null === state.price_floor || undefined === state.price_floor
							? ''
							: Pricing.asMajor( state.price_floor, currency ) ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'pricing.demand.floorHint' ) ) + '</span></div>' +
				'<div class="field"><label class="field__label" for="demand-ceiling">' +
					esc( App.t( 'pricing.demand.ceiling' ) ) + '</label>' +
					'<input class="input tnum" id="demand-ceiling" type="number" min="0" step="' +
						Pricing.step( currency ) + '" value="' +
						esc( null === state.price_ceiling || undefined === state.price_ceiling
							? ''
							: Pricing.asMajor( state.price_ceiling, currency ) ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'pricing.demand.ceilingHint' ) ) + '</span></div>' +
			'</div>' +

			( Pricing.steps.length
				? App.table(
					[
						App.t( 'pricing.demand.name' ), App.t( 'pricing.demand.soldFrom' ),
						App.t( 'pricing.tiers.change' ), '',
					],
					Pricing.steps.map( function ( step, index ) {
						return Pricing.demandRow( App, step, index, currency );
					} ).join( '' )
				)
				: App.emptyState( 'chart', App.t( 'pricing.demand.none' ),
					App.t( 'pricing.demand.noneHint' ) ) ) +
			'<button class="btn" id="demand-add">' + esc( App.t( 'pricing.demand.add' ) ) + '</button>';
	};

	Pricing.demandRow = function ( App, step, index, currency ) {
		var isAmount = 'amount' === step.kind;
		var live = ( Pricing.demand || {} ).in_force || {};
		var inForce = live.demand && live.demand.sold_from === step.sold_from;

		return '<tr' + ( inForce ? ' class="is-live"' : '' ) + '>' +
			'<td><input class="input" data-step-name="' + index + '" maxlength="80" value="' +
				esc( step.name || '' ) + '"></td>' +
			'<td><input class="input tnum" type="number" min="0" max="100" data-step-from="' +
				index + '" value="' + esc( step.sold_from ) + '"></td>' +
			'<td class="row row--wrap">' +
				'<select class="select" data-step-kind="' + index + '">' +
					[ 'percent', 'amount' ].map( function ( kind ) {
						return '<option value="' + kind + '"' + ( kind === step.kind ? ' selected' : '' ) + '>' +
							esc( App.t( 'pricing.tiers.kinds.' + kind ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
				'<input class="input tnum" type="number" data-step-value="' + index + '" ' +
					( isAmount ? 'step="' + Pricing.step( currency ) + '" ' : 'step="1" ' ) +
					'value="' + esc( isAmount ? Pricing.asMajor( step.value, currency ) : ( step.value || 0 ) ) + '">' +
			'</td>' +
			'<td class="row row--end"><button class="btn btn--quiet" data-step-drop="' + index + '">' +
				esc( App.t( 'pricing.tiers.remove' ) ) + '</button></td></tr>';
	};

	/** Read the demand boxes back, in the currency an amount rung was typed under. */
	Pricing.readDemand = function ( currency ) {
		var decimals = Pricing.decimals( normaliseCode( currency ) );
		var major = function ( id ) {
			var box = document.getElementById( id );

			// An empty box is "no rail on this side", which is not the same as a rail at nothing.
			return box && '' !== String( box.value ).trim()
				? Math.round( Number( box.value ) * Math.pow( 10, decimals ) )
				: null;
		};

		Pricing.demand = Pricing.demand || {};
		Pricing.demand.demand_pricing = !! ( document.getElementById( 'demand-on' ) || {} ).checked;
		Pricing.demand.price_floor = major( 'demand-floor' );
		Pricing.demand.price_ceiling = major( 'demand-ceiling' );

		Pricing.steps.forEach( function ( step, index ) {
			var value = document.querySelector( '[data-step-value="' + index + '"]' );

			if ( ! value ) {
				return;
			}

			step.name = document.querySelector( '[data-step-name="' + index + '"]' ).value;
			step.kind = document.querySelector( '[data-step-kind="' + index + '"]' ).value;
			step.sold_from = Math.max( 0, Math.min( 100, Math.round(
				Number( document.querySelector( '[data-step-from="' + index + '"]' ).value || 0 )
			) ) );
			step.value = 'amount' === step.kind
				? Math.round( Number( value.value || 0 ) * Math.pow( 10, decimals ) )
				: Math.round( Number( value.value || 0 ) );
		} );
	};

	Pricing.saveDemand = function ( App ) {
		var currency = document.getElementById( 'pricing-currency' ).value;

		Pricing.readDemand( currency );

		return App.request( 'PUT', '/events/' + Pricing.eventId + '/demand-pricing', {
			demand_pricing: Pricing.demand.demand_pricing,
			price_floor: Pricing.demand.price_floor,
			price_ceiling: Pricing.demand.price_ceiling,
			steps: Pricing.steps.map( function ( step ) {
				return {
					name: step.name || null,
					sold_from: step.sold_from,
					kind: step.kind,
					value: step.value,
				};
			} ),
		} ).then( function ( answer ) {
			Pricing.demand = answer;
			Pricing.steps = answer.data || [];
			Pricing.paint( App );
			App.toast( App.t( 'pricing.demand.saved' ) );
		} );
	};

	/** Read the tier boxes back, in the currency an amount tier was typed under. */
	Pricing.readTiers = function ( currency ) {
		var decimals = Pricing.decimals( normaliseCode( currency ) );

		Pricing.tiers.forEach( function ( tier, index ) {
			var value = document.querySelector( '[data-tier-value="' + index + '"]' );
			var kind = document.querySelector( '[data-tier-kind="' + index + '"]' );
			var name = document.querySelector( '[data-tier-name="' + index + '"]' );
			var from = document.querySelector( '[data-tier-from="' + index + '"]' );
			var until = document.querySelector( '[data-tier-until="' + index + '"]' );

			if ( ! value ) {
				return;
			}

			tier.name = name.value;
			tier.kind = kind.value;
			tier.starts_at = isoOrNull( from.value );
			tier.ends_at = isoOrNull( until.value );
			tier.value = 'amount' === tier.kind
				? Math.round( Number( value.value || 0 ) * Math.pow( 10, decimals ) )
				: Math.round( Number( value.value || 0 ) );
		} );
	};

	Pricing.saveTiers = function ( App ) {
		var currency = normaliseCode( document.getElementById( 'pricing-currency' ).value );

		Pricing.readTiers( currency );

		return App.request( 'PUT', '/events/' + Pricing.eventId + '/price-tiers', {
			tiers: Pricing.tiers.map( function ( tier ) {
				return {
					name: tier.name, starts_at: tier.starts_at || null, ends_at: tier.ends_at || null,
					kind: tier.kind, value: tier.value,
				};
			} ),
		} ).then( function ( answer ) {
			Pricing.tiers = answer.data || [];
			Pricing.activeTier = answer.active || null;
			Pricing.paint( App );
			App.toast( App.t( 'pricing.tiers.saved' ) );
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
	 * The step a number input should take in this currency.
	 *
	 * 0.01 for a euro, 1 for a rial. A rial box that steps in hundredths invites somebody to type
	 * a hundredth of a rial, which does not exist.
	 */
	Pricing.step = function ( currency ) {
		var decimals = Pricing.decimals( currency );

		return decimals ? Math.pow( 10, -decimals ).toFixed( decimals ) : '1';
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
