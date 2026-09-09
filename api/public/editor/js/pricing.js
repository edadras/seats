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

	var Pricing = { eventId: null, event: null, zones: [], CURRENCIES: COMMON };

	Pricing.open = function ( App, eventId ) {
		Pricing.eventId = eventId;

		App.loading( App.t( 'pricing.title' ) );

		App.request( 'GET', '/events/' + eventId ).then( function ( event ) {
			Pricing.event = event;
			Pricing.zones = Pricing.seed( event );
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
					: App.emptyState( 'tag', App.t( 'pricing.noZones' ), App.t( 'pricing.noZonesHint' ) ) ),
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
					: '<span class="muted block">' + esc( App.t( 'pricing.notOnMap' ) ) + '</span>' ) +
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

		document.getElementById( 'pricing-save' )
			.addEventListener( 'click', function () { Pricing.save( App ); } );
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

		App.request( 'PUT', '/events/' + Pricing.eventId + '/pricing', {
			currency: currency,
			zones: priced.map( function ( zone ) {
				return { key: zone.key, name: zone.name, amount: zone.amount, color: zone.color };
			} ),
		} ).then( function () {
			App.toast( unpriced.length
				? App.t( 'pricing.savedWithGaps', { count: App.number( unpriced.length ) } )
				: App.t( 'pricing.saved' ) );

			App.renderEvents();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
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
