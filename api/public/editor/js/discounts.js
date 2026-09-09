/**
 * Discount codes.
 *
 * The list is the screen: an organiser wants to see, at a glance, which codes are working right
 * now and how much of each is left. So "live" is computed and shown as a badge rather than left
 * for the reader to work out from three date columns.
 *
 * A code that has been used cannot be deleted, only paused — the orders it paid for still point at
 * it — and the screen offers the two actions in that order so the destructive one is not the
 * obvious one.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Discounts = {
		App: null,
		events: [],
		filters: { event_id: '', status: '', q: '' },
		timer: null,
		code: null,
	};

	Discounts.render = function ( App ) {
		Discounts.App = App;
		App.loading( App.t( 'panel.discounts.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.catch( function () { return { data: [] }; } )
			.then( function ( response ) {
				Discounts.events = response.data || [];
				Discounts.paint();
				Discounts.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Discounts.paint = function () {
		var App = Discounts.App;

		App.page( {
			title: App.t( 'panel.discounts.title' ),
			description: esc( App.t( 'panel.discounts.description' ) ),
			actions: '<button class="btn btn--primary" id="discount-new">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.discounts.create' ) ) + '</button>',
			body:
				'<div class="filters">' +
					'<input class="input grow" id="discount-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.discounts.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.discounts.search' ) ) + '" ' +
						'value="' + esc( Discounts.filters.q ) + '">' +
					'<select class="select" id="discount-event" aria-label="' +
						esc( App.t( 'panel.discounts.allEvents' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.discounts.allEvents' ) ) + '</option>' +
						Discounts.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( event.id === Discounts.filters.event_id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="discount-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						[ '', 'active', 'paused' ].map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Discounts.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.discounts.status.' + status )
									: App.t( 'panel.discounts.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="discount-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 'discount-search' );

		search.addEventListener( 'input', function () {
			Discounts.filters.q = search.value;

			global.clearTimeout( Discounts.timer );
			Discounts.timer = global.setTimeout( function () { Discounts.load(); }, 300 );
		} );

		[ [ 'discount-event', 'event_id' ], [ 'discount-status', 'status' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Discounts.filters[ pair[ 1 ] ] = this.value;
				Discounts.load();
			} );
		} );

		bind( 'discount-new', function () { Discounts.form( null ); } );
	};

	Discounts.load = function () {
		var App = Discounts.App;
		var host = document.getElementById( 'discount-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Discounts.filters )
			.filter( function ( key ) { return Discounts.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Discounts.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/discounts' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				host.innerHTML = Discounts.listMarkup( response );

				each( '[data-open]', function ( button ) {
					button.addEventListener( 'click', function () { Discounts.open( button.dataset.open ); } );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Discounts.listMarkup = function ( response ) {
		var App = Discounts.App;

		if ( ! response.data.length ) {
			return App.emptyState(
				Discounts.filters.q ? 'search' : 'tag',
				App.t( Discounts.filters.q ? 'panel.discounts.noMatchTitle' : 'panel.discounts.noneTitle' ),
				esc( App.t( Discounts.filters.q ? 'panel.discounts.noMatchBody' : 'panel.discounts.noneBody' ) )
			);
		}

		return App.table(
			[
				App.t( 'panel.discounts.code' ),
				App.t( 'panel.discounts.worth' ),
				App.t( 'panel.discounts.appliesTo' ),
				{ label: App.t( 'panel.discounts.used' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( code ) {
				return '<tr>' +
					'<td class="table__primary"><code>' + esc( code.code ) + '</code>' +
						( code.description
							? '<span class="muted on-own-line">' + esc( code.description ) + '</span>'
							: '' ) + '</td>' +
					'<td class="tnum">' + esc( Discounts.worth( code ) ) + '</td>' +
					'<td>' + esc( code.event_name || App.t( 'panel.discounts.everyEvent' ) ) +
						'<span class="muted on-own-line">' + esc( Discounts.window( code ) ) + '</span></td>' +
					'<td class="tnum">' + esc( Discounts.usage( code ) ) + '</td>' +
					'<td>' + Discounts.badge( code ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-open="' + esc( code.id ) + '">' +
							esc( App.t( 'panel.discounts.open' ) ) + '</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/** What the code takes off, in the reader's digits and the code's own currency. */
	Discounts.worth = function ( code ) {
		var App = Discounts.App;

		return 'percent' === code.kind
			? App.t( 'panel.discounts.percentOff', { value: App.number( code.value ) } )
			: App.money( code.value, code.currency );
	};

	Discounts.usage = function ( code ) {
		var App = Discounts.App;

		return code.max_uses
			? App.t( 'panel.discounts.ofMax', {
				used: App.number( code.used_count ),
				max: App.number( code.max_uses ),
			} )
			: App.number( code.used_count );
	};

	Discounts.window = function ( code ) {
		var App = Discounts.App;

		if ( code.starts_at && code.ends_at ) {
			return App.t( 'panel.discounts.between', {
				from: App.date( code.starts_at ),
				to: App.date( code.ends_at ),
			} );
		}

		if ( code.ends_at ) {
			return App.t( 'panel.discounts.until', { to: App.date( code.ends_at ) } );
		}

		if ( code.starts_at ) {
			return App.t( 'panel.discounts.from', { from: App.date( code.starts_at ) } );
		}

		return App.t( 'panel.discounts.always' );
	};

	/**
	 * Live is the honest answer, not the stored status: a code marked active whose end date has
	 * passed takes nothing off anything, and saying "active" about it is how an organiser spends
	 * an afternoon wondering why nobody can use it.
	 */
	Discounts.badge = function ( code ) {
		var App = Discounts.App;

		if ( code.live ) {
			return '<span class="badge badge--ok">' + esc( App.t( 'panel.discounts.live' ) ) + '</span>';
		}

		var why = 'paused' === code.status
			? 'paused'
			: ( code.max_uses && code.used_count >= code.max_uses ? 'usedUp' : 'notNow' );

		return '<span class="badge badge--neutral">' +
			esc( App.t( 'panel.discounts.' + why ) ) + '</span>';
	};

	/* ----------------------------------------------------------------------- one code */

	Discounts.open = function ( id ) {
		var App = Discounts.App;

		App.loading( App.t( 'panel.discounts.title' ) );

		App.request( 'GET', '/discounts/' + id )
			.then( function ( code ) {
				Discounts.code = code;
				Discounts.paintCode();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Discounts.paintCode = function () {
		var App = Discounts.App;
		var code = Discounts.code;
		var taken = code.redemptions.reduce( function ( sum, use ) { return sum + use.amount; }, 0 );

		App.page( {
			title: code.code,
			description: esc( code.description || App.t( 'panel.discounts.noDescription' ) ),
			actions:
				'<button class="btn" id="discount-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.discounts.back' ) ) + '</button>' +
				'<button class="btn" id="discount-toggle">' +
					esc( App.t( 'active' === code.status
						? 'panel.discounts.pause'
						: 'panel.discounts.resume' ) ) + '</button>' +
				'<button class="btn" id="discount-edit">' + icon( 'edit', { size: 15 } ) +
					esc( App.t( 'panel.discounts.edit' ) ) + '</button>' +
				( code.used_count
					? ''
					: '<button class="btn btn--danger" id="discount-delete">' +
						esc( App.t( 'panel.discounts.delete' ) ) + '</button>' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.discounts.worth' ), Discounts.worth( code ) ) +
					tile( App.t( 'panel.discounts.used' ), Discounts.usage( code ) ) +
					tile( App.t( 'panel.discounts.givenAway' ),
						App.money( taken, code.currency || ( code.redemptions[ 0 ] || {} ).currency || '' ) ) +
					tile( App.t( 'panel.common.status' ), App.t( 'panel.discounts.status.' + code.status ),
						Discounts.window( code ) ) +
				'</div>' +
				'<h3 class="subhead">' + esc( App.t( 'panel.discounts.whoUsedIt' ) ) + '</h3>' +
				( code.redemptions.length
					? App.table(
						[
							App.t( 'panel.orders.reference' ),
							App.t( 'panel.orders.buyer' ),
							{ label: App.t( 'panel.discounts.tookOff' ), numeric: true },
							App.t( 'panel.discounts.when' ),
						],
						code.redemptions.map( function ( use ) {
							return '<tr>' +
								'<td class="table__primary"><code>' + esc( use.order_id || '—' ) + '</code></td>' +
								'<td>' + esc( use.buyer || '—' ) + '</td>' +
								'<td class="tnum">' + esc( App.money( use.amount, use.currency ) ) + '</td>' +
								'<td class="tnum">' + esc( App.date( use.at ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'panel.discounts.notUsedYet' ) ) + '</p>' ),
		} );

		bind( 'discount-back', function () { Discounts.render( App ); } );
		bind( 'discount-edit', function () { Discounts.form( code ); } );
		bind( 'discount-toggle', function () { Discounts.toggle(); } );
		bind( 'discount-delete', function () { Discounts.remove(); } );
	};

	Discounts.toggle = function () {
		var App = Discounts.App;
		var code = Discounts.code;
		var next = 'active' === code.status ? 'paused' : 'active';

		App.request( 'PATCH', '/discounts/' + code.id, { status: next } )
			.then( function () {
				App.toast( App.t( 'panel.discounts.' + ( 'paused' === next ? 'pausedNow' : 'resumedNow' ) ) );
				Discounts.open( code.id );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Discounts.remove = function () {
		var App = Discounts.App;

		App.modal( {
			title: App.t( 'panel.discounts.deleteTitle' ),
			submitLabel: App.t( 'panel.discounts.delete' ),
			body: '<p>' + esc( App.t( 'panel.discounts.deleteBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/discounts/' + Discounts.code.id )
					.then( function () {
						App.toast( App.t( 'panel.discounts.deleted' ) );
						Discounts.render( App );
					} );
			},
		} );
	};

	/* ----------------------------------------------------------------------- the form */

	Discounts.form = function ( code ) {
		var App = Discounts.App;
		var editing = !! code;

		App.modal( {
			title: App.t( editing ? 'panel.discounts.editTitle' : 'panel.discounts.createTitle' ),
			submitLabel: App.t( editing ? 'panel.common.save' : 'panel.discounts.create' ),
			body:
				'<div class="stack">' +
				( editing
					? '<p class="hint">' + esc( App.t( 'panel.discounts.codeIsFixed', {
						code: code.code,
					} ) ) + '</p>'
					: '<div class="field">' +
						'<label class="field__label" for="d-code">' + esc( App.t( 'panel.discounts.code' ) ) + '</label>' +
						'<div class="filters">' +
							'<input class="input grow" id="d-code" maxlength="40" required ' +
								'autocomplete="off" spellcheck="false">' +
							'<button class="btn" type="button" id="d-suggest">' +
								esc( App.t( 'panel.discounts.suggest' ) ) + '</button>' +
						'</div>' +
						'<span class="field__hint">' + esc( App.t( 'panel.discounts.codeHint' ) ) + '</span>' +
					'</div>' ) +

				'<div class="field">' +
					'<label class="field__label" for="d-description">' + esc( App.t( 'panel.discounts.whatFor' ) ) + '</label>' +
					'<input class="input" id="d-description" maxlength="160" value="' +
						esc( ( code && code.description ) || '' ) + '">' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="d-kind">' + esc( App.t( 'panel.discounts.kind' ) ) + '</label>' +
						'<select class="select" id="d-kind">' +
							'<option value="percent"' +
								( ! code || 'percent' === code.kind ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.discounts.kinds.percent' ) ) + '</option>' +
							'<option value="fixed"' +
								( code && 'fixed' === code.kind ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.discounts.kinds.fixed' ) ) + '</option>' +
						'</select>' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="d-value">' + esc( App.t( 'panel.discounts.amount' ) ) + '</label>' +
						'<input class="input" id="d-value" type="number" min="1" required value="' +
							esc( code ? code.value : 10 ) + '">' +
						'<span class="field__hint" id="d-value-hint"></span>' +
					'</div>' +
					'<div class="field" id="d-currency-field">' +
						'<label class="field__label" for="d-currency">' + esc( App.t( 'panel.discounts.currency' ) ) + '</label>' +
						'<input class="input" id="d-currency" maxlength="3" value="' +
							esc( ( code && code.currency ) || '' ) + '">' +
					'</div>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="d-event">' + esc( App.t( 'panel.discounts.appliesTo' ) ) + '</label>' +
					'<select class="select" id="d-event">' +
						'<option value="">' + esc( App.t( 'panel.discounts.everyEvent' ) ) + '</option>' +
						Discounts.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( code && code.event_id === event.id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="d-starts">' + esc( App.t( 'panel.discounts.startsAt' ) ) + '</label>' +
						'<input class="input" id="d-starts" type="datetime-local" value="' +
							esc( localDateTime( code && code.starts_at ) ) + '">' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="d-ends">' + esc( App.t( 'panel.discounts.endsAt' ) ) + '</label>' +
						'<input class="input" id="d-ends" type="datetime-local" value="' +
							esc( localDateTime( code && code.ends_at ) ) + '">' +
					'</div>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="d-max">' + esc( App.t( 'panel.discounts.maxUses' ) ) + '</label>' +
						'<input class="input" id="d-max" type="number" min="1" value="' +
							esc( ( code && code.max_uses ) || '' ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.discounts.maxUsesHint' ) ) + '</span>' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="d-min-seats">' + esc( App.t( 'panel.discounts.minSeats' ) ) + '</label>' +
						'<input class="input" id="d-min-seats" type="number" min="1" value="' +
							esc( ( code && code.min_seats ) || 1 ) + '">' +
					'</div>' +
				'</div>' +
				'</div>',
			onSubmit: function () {
				var kind = value( 'd-kind' );
				var payload = {
					description: value( 'd-description' ) || null,
					event_id: value( 'd-event' ) || null,
					kind: kind,
					value: parseInt( value( 'd-value' ), 10 ),
					currency: 'fixed' === kind ? ( value( 'd-currency' ) || null ) : null,
					starts_at: isoOrNull( value( 'd-starts' ) ),
					ends_at: isoOrNull( value( 'd-ends' ) ),
					max_uses: value( 'd-max' ) ? parseInt( value( 'd-max' ), 10 ) : null,
					min_seats: parseInt( value( 'd-min-seats' ), 10 ) || 1,
				};

				if ( ! editing ) {
					payload.code = value( 'd-code' );
				}

				var request = editing
					? App.request( 'PATCH', '/discounts/' + code.id, payload )
					: App.request( 'POST', '/discounts', payload );

				return request.then( function ( saved ) {
					App.toast( App.t( editing ? 'panel.discounts.saved' : 'panel.discounts.created' ) );
					Discounts.open( saved.id );
				} );
			},
		} );

		var kind = document.getElementById( 'd-kind' );

		// A percentage has no currency and a fixed amount has nothing else it could mean, so the
		// field that does not apply is hidden rather than shown and quietly ignored.
		function shape() {
			document.getElementById( 'd-currency-field' ).hidden = 'fixed' !== kind.value;
			document.getElementById( 'd-value-hint' ).textContent = App.t( 'fixed' === kind.value
				? 'panel.discounts.amountMinor'
				: 'panel.discounts.amountPercent' );
		}

		kind.addEventListener( 'change', shape );
		shape();

		bind( 'd-suggest', function () {
			App.request( 'GET', '/discounts/suggest' )
				.then( function ( result ) {
					document.getElementById( 'd-code' ).value = result.code;
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	/* ----------------------------------------------------------------------- helpers */

	/** An ISO instant as the local wall-clock string a datetime-local input wants. */
	function localDateTime( iso ) {
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

	global.SeatmapDiscounts = Discounts;
}( typeof window !== 'undefined' ? window : globalThis ) );
