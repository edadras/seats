/**
 * Presale codes.
 *
 * The screen next door does discounts, and this one deliberately looks like it: an organiser
 * thinks of both as "codes I hand out", and making them two different shapes would be making them
 * remember which. What differs is what the columns say — a discount is worth an amount, an access
 * code opens a door — and the one number that matters here is how many uses are left, because a
 * code emailed to a mailing list of four hundred is a code somebody is watching.
 *
 * "Used" is live uses, not presses: a basket somebody opened and abandoned gave its use back, and
 * a screen that counted presses would tell an organiser their presale was full when it was not.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Access = {
		App: null,
		events: [],
		filters: { event_id: '', status: '', q: '' },
		timer: null,
		code: null,
	};

	Access.render = function ( App ) {
		Access.App = App;
		App.loading( App.t( 'panel.access.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.catch( function () { return { data: [] }; } )
			.then( function ( response ) {
				Access.events = response.data || [];
				Access.paint();
				Access.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Access.paint = function () {
		var App = Access.App;

		App.page( {
			title: App.t( 'panel.access.title' ),
			description: esc( App.t( 'panel.access.description' ) ),
			actions: '<button class="btn btn--primary" id="access-new">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.access.create' ) ) + '</button>',
			body:
				'<div class="filters">' +
					'<input class="input grow" id="access-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.access.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.access.search' ) ) + '" ' +
						'value="' + esc( Access.filters.q ) + '">' +
					'<select class="select" id="access-event" aria-label="' +
						esc( App.t( 'panel.access.allEvents' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.access.allEvents' ) ) + '</option>' +
						Access.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( event.id === Access.filters.event_id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="access-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						[ '', 'active', 'paused' ].map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Access.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.access.status.' + status )
									: App.t( 'panel.access.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="access-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 'access-search' );

		search.addEventListener( 'input', function () {
			Access.filters.q = search.value;

			global.clearTimeout( Access.timer );
			Access.timer = global.setTimeout( function () { Access.load(); }, 300 );
		} );

		[ [ 'access-event', 'event_id' ], [ 'access-status', 'status' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Access.filters[ pair[ 1 ] ] = this.value;
				Access.load();
			} );
		} );

		bind( 'access-new', function () { Access.form( null ); } );
	};

	Access.load = function () {
		var App = Access.App;
		var host = document.getElementById( 'access-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Access.filters )
			.filter( function ( key ) { return Access.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Access.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/access-codes' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				host.innerHTML = Access.listMarkup( response );

				each( '[data-open-code]', function ( button ) {
					button.addEventListener( 'click', function () { Access.open( button.dataset.openCode ); } );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Access.listMarkup = function ( response ) {
		var App = Access.App;

		if ( ! response.data.length ) {
			return App.emptyState(
				Access.filters.q ? 'search' : 'lock',
				App.t( Access.filters.q ? 'panel.access.noMatchTitle' : 'panel.access.noneTitle' ),
				esc( App.t( Access.filters.q ? 'panel.access.noMatchBody' : 'panel.access.noneBody' ) ),
				// A search that found nothing is answered by searching differently, not by a button.
				Access.filters.q
					? { waiting: true }
					: { does: 'access-new', label: App.t( 'panel.access.create' ) }
			);
		}

		return App.table(
			[
				App.t( 'panel.access.code' ),
				App.t( 'panel.access.opens' ),
				App.t( 'panel.access.appliesTo' ),
				{ label: App.t( 'panel.access.used' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( code ) {
				return '<tr>' +
					'<td class="table__primary"><code>' + esc( code.code ) + '</code>' +
						( code.label
							? '<span class="muted on-own-line">' + esc( code.label ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( App.t( 'panel.access.opensKinds.' + code.opens ) ) + '</td>' +
					'<td>' + esc( code.event_name || App.t( 'panel.access.everyEvent' ) ) +
						'<span class="muted on-own-line">' + esc( Access.window( code ) ) + '</span></td>' +
					'<td class="tnum">' + esc( Access.usage( code ) ) + '</td>' +
					'<td>' + Access.badge( code ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-open-code="' + esc( code.id ) + '">' +
							esc( App.t( 'panel.access.open' ) ) + '</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Access.usage = function ( code ) {
		var App = Access.App;

		return code.max_uses
			? App.t( 'panel.access.ofMax', {
				used: App.number( code.used_count ),
				max: App.number( code.max_uses ),
			} )
			: App.number( code.used_count );
	};

	Access.window = function ( code ) {
		var App = Access.App;

		if ( code.starts_at && code.ends_at ) {
			return App.t( 'panel.access.between', {
				from: App.date( code.starts_at ),
				to: App.date( code.ends_at ),
			} );
		}

		if ( code.ends_at ) {
			return App.t( 'panel.access.until', { to: App.date( code.ends_at ) } );
		}

		if ( code.starts_at ) {
			return App.t( 'panel.access.from', { from: App.date( code.starts_at ) } );
		}

		return App.t( 'panel.access.always' );
	};

	/**
	 * Live is the honest answer, not the stored status.
	 *
	 * A code marked active whose window has passed, or whose uses are gone, opens nothing — and
	 * telling an organiser it is "active" is how they spend an afternoon wondering why their
	 * mailing list is complaining.
	 */
	Access.badge = function ( code ) {
		var App = Access.App;

		if ( code.live ) {
			return '<span class="badge badge--ok">' + esc( App.t( 'panel.access.live' ) ) + '</span>';
		}

		var why = 'paused' === code.status
			? 'paused'
			: ( code.max_uses && code.used_count >= code.max_uses ? 'usedUp' : 'notNow' );

		return '<span class="badge badge--neutral">' +
			esc( App.t( 'panel.access.' + why ) ) + '</span>';
	};

	/* ----------------------------------------------------------------------- one code */

	Access.open = function ( id ) {
		var App = Access.App;

		App.loading( App.t( 'panel.access.title' ) );

		App.request( 'GET', '/access-codes/' + id )
			.then( function ( code ) {
				Access.code = code;
				Access.paintCode();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Access.paintCode = function () {
		var App = Access.App;
		var code = Access.code;
		var seats = code.uses.reduce( function ( sum, use ) { return sum + ( use.seats || 0 ); }, 0 );

		App.page( {
			title: code.code,
			description: esc( code.label || App.t( 'panel.access.noLabel' ) ),
			actions:
				'<button class="btn" id="access-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.access.back' ) ) + '</button>' +
				'<button class="btn" id="access-toggle">' +
					esc( App.t( 'active' === code.status
						? 'panel.access.pause'
						: 'panel.access.resume' ) ) + '</button>' +
				'<button class="btn" id="access-edit">' + icon( 'edit', { size: 15 } ) +
					esc( App.t( 'panel.access.edit' ) ) + '</button>' +
				( code.used_count
					? ''
					: '<button class="btn btn--danger" id="access-delete">' +
						esc( App.t( 'panel.access.delete' ) ) + '</button>' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.access.opens' ), App.t( 'panel.access.opensKinds.' + code.opens ) ) +
					tile( App.t( 'panel.access.used' ), Access.usage( code ) ) +
					tile( App.t( 'panel.access.seatsTaken' ), App.number( seats ) ) +
					tile( App.t( 'panel.common.status' ), App.t( 'panel.access.status.' + code.status ),
						Access.window( code ) ) +
				'</div>' +
				'<h3 class="subhead">' + esc( App.t( 'panel.access.whoUsedIt' ) ) + '</h3>' +
				( code.uses.length
					? App.table(
						[
							App.t( 'panel.orders.reference' ),
							App.t( 'panel.orders.buyer' ),
							{ label: App.t( 'panel.access.seats' ), numeric: true },
							App.t( 'panel.access.when' ),
						],
						code.uses.map( function ( use ) {
							return '<tr>' +
								'<td class="table__primary"><code>' +
									esc( use.order_id || App.t( 'panel.access.stillChoosing' ) ) + '</code></td>' +
								'<td>' + esc( use.buyer || '—' ) + '</td>' +
								'<td class="tnum">' + esc( App.number( use.seats ) ) + '</td>' +
								'<td class="tnum">' + esc( App.date( use.at ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'panel.access.notUsedYet' ) ) + '</p>' ),
		} );

		bind( 'access-back', function () { Access.render( App ); } );
		bind( 'access-edit', function () { Access.form( code ); } );
		bind( 'access-toggle', function () { Access.toggle(); } );
		bind( 'access-delete', function () { Access.remove(); } );
	};

	Access.toggle = function () {
		var App = Access.App;
		var code = Access.code;
		var next = 'active' === code.status ? 'paused' : 'active';

		App.request( 'PATCH', '/access-codes/' + code.id, { status: next } )
			.then( function () {
				App.toast( App.t( 'panel.access.' + ( 'paused' === next ? 'pausedNow' : 'resumedNow' ) ) );
				Access.open( code.id );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Access.remove = function () {
		var App = Access.App;

		App.modal( {
			title: App.t( 'panel.access.deleteTitle' ),
			submitLabel: App.t( 'panel.access.delete' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.access.deleteBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/access-codes/' + Access.code.id )
					.then( function () {
						App.toast( App.t( 'panel.access.deleted' ) );
						Access.render( App );
					} );
			},
		} );
	};

	/* ----------------------------------------------------------------------- the form */

	Access.form = function ( code ) {
		var App = Access.App;
		var editing = !! code;

		App.modal( {
			title: App.t( editing ? 'panel.access.editTitle' : 'panel.access.createTitle' ),
			submitLabel: App.t( editing ? 'panel.common.save' : 'panel.access.create' ),
			body:
				'<div class="stack">' +
				( editing
					? '<p class="hint">' + esc( App.t( 'panel.access.codeIsFixed', { code: code.code } ) ) + '</p>'
					: '<div class="field">' +
						'<label class="field__label" for="a-code">' + esc( App.t( 'panel.access.code' ) ) + '</label>' +
						'<div class="filters">' +
							'<input class="input grow" id="a-code" maxlength="40" required ' +
								'autocomplete="off" spellcheck="false">' +
							'<button class="btn" type="button" id="a-suggest">' +
								esc( App.t( 'panel.access.suggest' ) ) + '</button>' +
						'</div>' +
						'<span class="field__hint">' + esc( App.t( 'panel.access.codeHint' ) ) + '</span>' +
					'</div>' ) +

				'<div class="field">' +
					'<label class="field__label" for="a-label">' + esc( App.t( 'panel.access.whatFor' ) ) + '</label>' +
					'<input class="input" id="a-label" maxlength="160" value="' +
						esc( ( code && code.label ) || '' ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'panel.access.whatForHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="a-opens">' + esc( App.t( 'panel.access.opens' ) ) + '</label>' +
					'<select class="select" id="a-opens">' +
						[ 'presale', 'always' ].map( function ( kind ) {
							return '<option value="' + kind + '"' +
								( code && kind === code.opens ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.access.opensKinds.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<span class="field__hint" id="a-opens-hint"></span>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="a-event">' + esc( App.t( 'panel.access.appliesTo' ) ) + '</label>' +
					'<select class="select" id="a-event">' +
						'<option value="">' + esc( App.t( 'panel.access.everyEvent' ) ) + '</option>' +
						Access.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '"' +
								( code && code.event_id === event.id ? ' selected' : '' ) + '>' +
								esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="a-starts">' + esc( App.t( 'panel.access.startsAt' ) ) + '</label>' +
						'<input class="input" id="a-starts" type="datetime-local" value="' +
							esc( localDateTime( code && code.starts_at ) ) + '">' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="a-ends">' + esc( App.t( 'panel.access.endsAt' ) ) + '</label>' +
						'<input class="input" id="a-ends" type="datetime-local" value="' +
							esc( localDateTime( code && code.ends_at ) ) + '">' +
					'</div>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="a-max">' + esc( App.t( 'panel.access.maxUses' ) ) + '</label>' +
						'<input class="input" id="a-max" type="number" min="1" value="' +
							esc( ( code && code.max_uses ) || '' ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.access.maxUsesHint' ) ) + '</span>' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="a-max-seats">' + esc( App.t( 'panel.access.maxSeats' ) ) + '</label>' +
						'<input class="input" id="a-max-seats" type="number" min="1" value="' +
							esc( ( code && code.max_seats ) || '' ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.access.maxSeatsHint' ) ) + '</span>' +
					'</div>' +
				'</div>' +
				'</div>',
			onSubmit: function () {
				var payload = {
					label: value( 'a-label' ) || null,
					event_id: value( 'a-event' ) || null,
					opens: value( 'a-opens' ),
					starts_at: isoOrNull( value( 'a-starts' ) ),
					ends_at: isoOrNull( value( 'a-ends' ) ),
					max_uses: value( 'a-max' ) ? parseInt( value( 'a-max' ), 10 ) : null,
					max_seats: value( 'a-max-seats' ) ? parseInt( value( 'a-max-seats' ), 10 ) : null,
				};

				if ( ! editing ) {
					payload.code = value( 'a-code' );
				}

				var request = editing
					? App.request( 'PATCH', '/access-codes/' + code.id, payload )
					: App.request( 'POST', '/access-codes', payload );

				return request.then( function ( saved ) {
					App.toast( App.t( editing ? 'panel.access.saved' : 'panel.access.created' ) );
					Access.open( saved.id );
				} );
			},
		} );

		var opens = document.getElementById( 'a-opens' );

		function explain() {
			document.getElementById( 'a-opens-hint' ).textContent =
				App.t( 'panel.access.opensHints.' + opens.value );
		}

		opens.addEventListener( 'change', explain );
		explain();

		bind( 'a-suggest', function () {
			App.request( 'GET', '/access-codes/suggest' )
				.then( function ( result ) {
					document.getElementById( 'a-code' ).value = result.code;
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

	global.SeatmapAccess = Access;
}( typeof window !== 'undefined' ? window : globalThis ) );
