/**
 * Points earned by coming, and what they are worth.
 *
 * Everything else in this panel is about one night. This screen is about the years either side of
 * it: the subscriber who has been in row F since 2011, the person who came to four things last
 * season and might come to six.
 *
 * Three cards, in the order the decisions are actually made: what earns points and what they buy,
 * the ladder people climb, and which nights let a rung in before everybody else — because a tier
 * that opens no door is a label.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Loyal = {
		App: null,
		programme: null,
		members: [],
		events: [],
	};

	Loyal.render = function ( App ) {
		Loyal.App = App;
		App.loading( App.t( 'panel.loyalty.title' ) );

		Promise.all( [
			App.request( 'GET', '/loyalty' ),
			App.request( 'GET', '/loyalty/members' ),
			App.request( 'GET', '/events?per_page=50' ),
		] )
			.then( function ( answers ) {
				Loyal.programme = answers[ 0 ];
				Loyal.members = answers[ 1 ].data || [];
				Loyal.events = answers[ 2 ].data || [];
				Loyal.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Loyal.paint = function () {
		var App = Loyal.App;

		App.page( {
			title: App.t( 'panel.loyalty.title' ),
			description: esc( App.t( 'panel.loyalty.description' ) ),
			body: Loyal.schemeMarkup( App ) + Loyal.ladderMarkup( App ) +
				Loyal.earlyMarkup( App ) + Loyal.membersMarkup( App ),
		} );

		Loyal.bind();
	};

	Loyal.schemeMarkup = function ( App ) {
		var scheme = Loyal.programme || {};

		return '<div class="card card--pad">' +
			'<label class="switch switch--row"><input type="checkbox" id="loy-on"' +
			( scheme.enabled ? ' checked' : '' ) + '>' +
			'<span class="switch__track"><span class="switch__thumb"></span></span>' +
			'<span>' + esc( App.t( 'panel.loyalty.running' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( App.t( 'panel.loyalty.runningHint' ) ) + '</p>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="loy-name">' +
			esc( App.t( 'panel.loyalty.name' ) ) + '</label>' +
			'<input class="input" id="loy-name" maxlength="80" placeholder="' +
			esc( App.t( 'panel.loyalty.namePlaceholder' ) ) + '" value="' +
			esc( scheme.name || '' ) + '"></div>' +
			'<div class="field"><label class="field__label" for="loy-currency">' +
			esc( App.t( 'panel.loyalty.currency' ) ) + '</label>' +
			'<input class="input" id="loy-currency" maxlength="3" value="' +
			esc( scheme.currency || 'EUR' ) + '">' +
			// One currency, and saying so here rather than in a help page nobody opens: a single
			// pool fed by two currencies is arithmetic nobody can explain at a counter.
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.currencyHint' ) ) + '</span></div>' +
			'</div>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="loy-earn">' +
			esc( App.t( 'panel.loyalty.earnRate' ) ) + '</label>' +
			'<input class="input tnum" id="loy-earn" type="number" min="1" max="1000" value="' +
			esc( scheme.earn_rate || 1 ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.earnHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="loy-per-unit">' +
			esc( App.t( 'panel.loyalty.pointsPerUnit' ) ) + '</label>' +
			'<input class="input tnum" id="loy-per-unit" type="number" min="1" value="' +
			esc( scheme.points_per_unit || 100 ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.pointsPerUnitHint' ) ) + '</span></div>' +
			'</div>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="loy-min">' +
			esc( App.t( 'panel.loyalty.minRedeem' ) ) + '</label>' +
			'<input class="input tnum" id="loy-min" type="number" min="0" value="' +
			esc( null == scheme.min_redeem ? 500 : scheme.min_redeem ) + '"></div>' +
			'<div class="field"><label class="field__label" for="loy-window">' +
			esc( App.t( 'panel.loyalty.window' ) ) + '</label>' +
			'<input class="input tnum" id="loy-window" type="number" min="1" max="120" value="' +
			esc( scheme.window_months || 12 ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.windowHint' ) ) + '</span></div>' +
			'</div>' +

			'<div class="field"><label class="field__label" for="loy-quiet">' +
			esc( App.t( 'panel.loyalty.quiet' ) ) + '</label>' +
			'<input class="input tnum" id="loy-quiet" type="number" min="1" max="120" placeholder="' +
			esc( App.t( 'panel.loyalty.quietNever' ) ) + '" value="' +
			esc( null == scheme.inactive_months ? '' : scheme.inactive_months ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.quietHint' ) ) + '</span></div>' +
		'</div>';
	};

	Loyal.ladderMarkup = function ( App ) {
		var tiers = ( Loyal.programme || {} ).tiers || [];

		return '<h3 class="subhead">' + esc( App.t( 'panel.loyalty.ladder' ) ) + '</h3>' +
			'<div class="card card--pad">' +
			'<p class="field__hint">' + esc( App.t( 'panel.loyalty.ladderHint' ) ) + '</p>' +
			'<div id="loy-tiers">' + tiers.map( Loyal.tierRow ).join( '' ) + '</div>' +
			'<p class="spaced"><button class="btn" id="loy-add">' + icon( 'plus', { size: 15 } ) +
			esc( App.t( 'panel.loyalty.addTier' ) ) + '</button>' +
			' <button class="btn btn--primary" id="loy-save">' +
			esc( App.t( 'panel.common.save' ) ) + '</button></p>' +
			'</div>';
	};

	Loyal.tierRow = function ( tier ) {
		var App = Loyal.App;

		return '<div class="field-duo tier-row">' +
			'<div class="field"><label class="field__label">' +
			esc( App.t( 'panel.loyalty.tierName' ) ) + '</label>' +
			'<input class="input" data-tier-name maxlength="60" value="' +
			esc( ( tier && tier.name ) || '' ) + '"></div>' +
			'<div class="field"><label class="field__label">' +
			esc( App.t( 'panel.loyalty.tierKey' ) ) + '</label>' +
			'<input class="input" data-tier-key maxlength="40" value="' +
			esc( ( tier && tier.key ) || '' ) + '">' +
			// The key is what a night points at, so it is shown rather than generated: a tier
			// renamed in March must not lock out everybody who reached it in February.
			'<span class="field__hint">' + esc( App.t( 'panel.loyalty.tierKeyHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label">' +
			esc( App.t( 'panel.loyalty.tierFrom' ) ) + '</label>' +
			'<input class="input tnum" data-tier-from type="number" min="0" value="' +
			esc( tier && null != tier.from_points ? tier.from_points : 0 ) + '"></div>' +
			'<div class="field"><label class="field__label">&nbsp;</label>' +
			'<button class="btn btn--danger" data-tier-remove>' +
			esc( App.t( 'panel.common.remove' ) ) + '</button></div>' +
		'</div>';
	};

	/** Which nights let a rung in early. A tier that opens no door is a label. */
	Loyal.earlyMarkup = function ( App ) {
		var tiers = ( Loyal.programme || {} ).tiers || [];
		var soon = Loyal.events.filter( function ( event ) {
			return 'published' === event.status && ! event.is_rehearsal;
		} );

		if ( ! tiers.length || ! soon.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.loyalty.early' ) ) + '</h3>' +
			'<div class="card card--pad">' +
			'<p class="field__hint">' + esc( App.t( 'panel.loyalty.earlyHint' ) ) + '</p>' +
			soon.map( function ( event ) {
				return '<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="loy-ev-' + esc( event.id ) + '">' +
					esc( event.name ) + '</label>' +
					'<select class="select" id="loy-ev-' + esc( event.id ) + '" data-event="' +
					esc( event.id ) + '">' +
					'<option value="">' + esc( App.t( 'panel.loyalty.earlyNobody' ) ) + '</option>' +
					tiers.map( function ( tier ) {
						return '<option value="' + esc( tier.key ) + '"' +
							( event.tier_presale === tier.key ? ' selected' : '' ) + '>' +
							esc( tier.name ) + '</option>';
					} ).join( '' ) +
					'</select></div>' +
					'<div class="field"><span class="field__hint">' +
					esc( event.presale_starts_at
						? App.t( 'panel.loyalty.earlyFrom', { date: App.date( event.presale_starts_at ) } )
						: App.t( 'panel.loyalty.earlyNoPresale' ) ) + '</span></div>' +
				'</div>';
			} ).join( '' ) +
			'</div>';
	};

	Loyal.membersMarkup = function ( App ) {
		if ( ! Loyal.members.length ) {
			return '<h3 class="subhead">' + esc( App.t( 'panel.loyalty.members' ) ) + '</h3>' +
				App.emptyState( 'users', App.t( 'panel.loyalty.nobodyTitle' ),
					esc( App.t( 'panel.loyalty.nobodyBody' ) ) );
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.loyalty.members' ) ) + '</h3>' +
			App.table(
				[
					App.t( 'panel.loyalty.person' ),
					{ label: App.t( 'panel.loyalty.points' ), numeric: true },
					App.t( 'panel.loyalty.standing' ),
					'',
				],
				Loyal.members.map( function ( row ) {
					return '<tr>' +
						'<td class="table__primary">' + esc( row.email ) + '</td>' +
						'<td class="tnum">' + esc( App.number( row.balance ) ) + '</td>' +
						'<td>' + ( row.name
							? '<span class="badge badge--ok">' + esc( row.name ) + '</span>'
							: '<span class="muted">—</span>' ) +
							'<span class="muted on-own-line">' +
							esc( App.t( 'panel.loyalty.earnedIn', {
								points: App.number( row.points || 0 ),
							} ) ) + '</span></td>' +
						'<td class="table__actions">' +
						'<button class="btn btn--sm" data-adjust="' + esc( row.email ) + '">' +
						esc( App.t( 'panel.loyalty.adjust' ) ) + '</button></td>' +
					'</tr>';
				} ).join( '' )
			);
	};

	Loyal.bind = function () {
		var App = Loyal.App;

		bind( 'loy-add', function () {
			var host = document.getElementById( 'loy-tiers' );

			host.insertAdjacentHTML( 'beforeend', Loyal.tierRow( null ) );
			Loyal.bindRemovals();
		} );

		bind( 'loy-save', function () { Loyal.save(); } );
		Loyal.bindRemovals();

		App.main().querySelectorAll( '[data-event]' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				App.request( 'PATCH', '/events/' + select.dataset.event, {
					tier_presale: select.value || null,
				} )
					.then( function () { App.toast( App.t( 'panel.loyalty.earlySaved' ) ); } )
					.catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		App.main().querySelectorAll( '[data-adjust]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { Loyal.adjust( button.dataset.adjust ); } );
		} );
	};

	Loyal.bindRemovals = function () {
		Loyal.App.main().querySelectorAll( '[data-tier-remove]' ).forEach( function ( button ) {
			button.onclick = function () { button.closest( '.tier-row' ).remove(); };
		} );
	};

	Loyal.save = function () {
		var App = Loyal.App;
		var tiers = [];

		App.main().querySelectorAll( '.tier-row' ).forEach( function ( row ) {
			var name = row.querySelector( '[data-tier-name]' ).value.trim();
			var key = row.querySelector( '[data-tier-key]' ).value.trim().toLowerCase();

			if ( ! name && ! key ) {
				return;
			}

			tiers.push( {
				name: name || key,
				// A key nobody typed is the name, tidied: an organiser should not have to invent a
				// machine-readable word to name a tier "Gold".
				key: key || name.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ),
				from_points: Number( row.querySelector( '[data-tier-from]' ).value ) || 0,
			} );
		} );

		var quiet = document.getElementById( 'loy-quiet' ).value;

		App.request( 'PUT', '/loyalty', {
			name: document.getElementById( 'loy-name' ).value,
			enabled: document.getElementById( 'loy-on' ).checked,
			currency: document.getElementById( 'loy-currency' ).value.toUpperCase(),
			earn_rate: Number( document.getElementById( 'loy-earn' ).value ) || 1,
			points_per_unit: Number( document.getElementById( 'loy-per-unit' ).value ) || 100,
			min_redeem: Number( document.getElementById( 'loy-min' ).value ) || 0,
			window_months: Number( document.getElementById( 'loy-window' ).value ) || 12,
			// Empty is "they never expire", which is a promise an organiser may make deliberately.
			inactive_months: '' === quiet ? null : Number( quiet ),
			tiers: tiers,
		} )
			.then( function ( scheme ) {
				Loyal.programme = scheme;
				App.toast( App.t( 'panel.loyalty.saved' ) );
				Loyal.render( App );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Loyal.adjust = function ( email ) {
		var App = Loyal.App;

		App.modal( {
			title: App.t( 'panel.loyalty.adjustTitle' ),
			submitLabel: App.t( 'panel.loyalty.adjust' ),
			body: '<p>' + esc( email ) + '</p>' +
				'<div class="field"><label class="field__label" for="loy-points">' +
				esc( App.t( 'panel.loyalty.pointsOnOrOff' ) ) + '</label>' +
				'<input class="input tnum" id="loy-points" name="points" type="number" required></div>' +
				// Every scheme needs this, and every scheme's worst day is the one where somebody
				// did it without saying why.
				'<div class="field"><label class="field__label" for="loy-why">' +
				esc( App.t( 'panel.loyalty.why' ) ) + '</label>' +
				'<input class="input" id="loy-why" name="why" required maxlength="200"></div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/loyalty/adjust', {
					email: email,
					points: Number( data.get( 'points' ) ),
					why: data.get( 'why' ),
				} ).then( function () {
					App.toast( App.t( 'panel.loyalty.adjusted' ) );
					Loyal.render( App );
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

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapLoyalty = Loyal;
}( typeof window !== 'undefined' ? window : globalThis ) );
