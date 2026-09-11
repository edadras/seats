/**
 * The Friends scheme: the rungs, the nights they open early, and the people on them.
 *
 * Loyalty is earned by coming and a season ticket is one run. This is the oldest arrangement a
 * theatre has: a fee, a year, and a standing promise — a bit off every seat, and the chance to book
 * before everybody else.
 *
 * It is sold as an add-on beside a ticket rather than through a checkout of its own, which is why
 * "on the website" here is a switch and not a form: the add-on is the platform's to create and keep
 * priced, and an organiser only says whether it is offered.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Members = {
		App: null,
		schemes: [],
		members: [],
		events: [],
		filter: 'current',
	};

	Members.render = function ( App ) {
		Members.App = App;
		App.loading( App.t( 'panel.memberships.title' ) );

		Promise.all( [
			App.request( 'GET', '/memberships' ),
			App.request( 'GET', '/memberships/members?state=' + Members.filter ),
			App.request( 'GET', '/events?per_page=50' ).catch( function () { return { data: [] }; } ),
		] )
			.then( function ( answers ) {
				Members.schemes = answers[ 0 ].data || [];
				Members.members = answers[ 1 ].data || [];
				Members.events = answers[ 2 ].data || [];
				Members.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Members.paint = function () {
		var App = Members.App;

		App.page( {
			title: App.t( 'panel.memberships.title' ),
			description: esc( App.t( 'panel.memberships.description' ) ),
			actions: '<button class="btn btn--primary" id="mem-add">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.memberships.addScheme' ) ) + '</button>',
			body: Members.schemesMarkup() + Members.earlyMarkup() + Members.listMarkup(),
		} );

		Members.bind();
	};

	Members.schemesMarkup = function () {
		var App = Members.App;

		if ( ! Members.schemes.length ) {
			return App.emptyState( 'users', App.t( 'panel.memberships.noneTitle' ),
				esc( App.t( 'panel.memberships.noneBody' ) ) );
		}

		return App.table(
			[
				App.t( 'panel.memberships.scheme' ),
				{ label: App.t( 'panel.memberships.price' ), numeric: true },
				App.t( 'panel.memberships.lasts' ),
				App.t( 'panel.memberships.worth' ),
				{ label: App.t( 'panel.memberships.people' ), numeric: true },
				'',
			],
			Members.schemes.map( function ( scheme ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( scheme.name ) +
					( scheme.enabled
						? ''
						: ' <span class="badge badge--neutral">' +
							esc( App.t( 'panel.memberships.off' ) ) + '</span>' ) +
					( scheme.description
						? '<span class="muted on-own-line">' + esc( scheme.description ) + '</span>'
						: '' ) + '</td>' +
					'<td class="tnum">' + esc( App.money( scheme.price, scheme.currency ) ) + '</td>' +
					// Chosen here rather than written as a plural string: the panel's `t()` substitutes
					// `:name` and does not choose between forms, so a "{1}one|:count many" entry
					// renders its own braces at a reader.
					'<td>' + esc( 1 === Number( scheme.months )
						? App.t( 'panel.memberships.monthsOne' )
						: App.t( 'panel.memberships.months', { count: App.number( scheme.months ) } ) ) +
					'</td>' +
					'<td>' + ( scheme.discount_percent
						? esc( App.t( 'panel.memberships.percentOff', {
							percent: App.number( scheme.discount_percent ),
						} ) )
						: '<span class="muted">—</span>' ) +
						( scheme.presale
							? '<span class="badge badge--ok on-own-line">' +
								esc( App.t( 'panel.memberships.booksEarly' ) ) + '</span>'
							: '' ) +
						( scheme.sell_online
							? '<span class="muted on-own-line">' +
								esc( App.t( 'panel.memberships.onTheWebsite' ) ) + '</span>'
							: '' ) + '</td>' +
					'<td class="tnum">' + esc( App.number( scheme.members ) ) + '</td>' +
					'<td class="table__actions">' +
					'<button class="btn btn--sm" data-mem-edit="' + esc( scheme.id ) + '">' +
					esc( App.t( 'panel.common.edit' ) ) + '</button>' +
					'<button class="icon-btn icon-btn--sm" data-mem-delete="' + esc( scheme.id ) +
					'" aria-label="' + esc( App.t( 'panel.common.remove' ) ) + '">' +
					icon( 'trash', { size: 14 } ) + '</button></td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/** Which nights let a Friend in before everybody else. A promise that opens no door is a label. */
	Members.earlyMarkup = function () {
		var App = Members.App;
		var opening = Members.schemes.filter( function ( scheme ) { return scheme.presale; } );
		var soon = Members.events.filter( function ( event ) {
			return 'published' === event.status && ! event.is_rehearsal;
		} );

		if ( ! opening.length || ! soon.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.memberships.early' ) ) + '</h3>' +
			'<div class="card card--pad">' +
			'<p class="field__hint">' + esc( App.t( 'panel.memberships.earlyHint' ) ) + '</p>' +
			soon.map( function ( event ) {
				return '<label class="switch switch--row spaced">' +
					'<input type="checkbox" data-mem-event="' + esc( event.id ) + '"' +
					( event.member_presale ? ' checked' : '' ) + '>' +
					'<span class="switch__track"><span class="switch__thumb"></span></span>' +
					'<span>' + esc( event.name ) +
					'<span class="muted on-own-line">' + esc( event.presale_starts_at
						? App.t( 'panel.memberships.earlyFrom', {
							date: App.date( event.presale_starts_at ),
						} )
						: App.t( 'panel.memberships.earlyNoPresale' ) ) + '</span></span></label>';
			} ).join( '' ) +
			'</div>';
	};

	Members.listMarkup = function () {
		var App = Members.App;

		var head = '<h3 class="subhead">' + esc( App.t( 'panel.memberships.people' ) ) + '</h3>' +
			'<div class="filters">' +
			[ 'current', 'lapsed' ].map( function ( state ) {
				return '<button class="btn btn--sm' +
					( Members.filter === state ? ' is-active' : '' ) +
					'" data-mem-filter="' + state + '">' +
					esc( App.t( 'panel.memberships.' + state ) ) + '</button>';
			} ).join( '' ) +
			( Members.schemes.length
				? '<button class="btn btn--sm btn--primary" id="mem-join">' +
					esc( App.t( 'panel.memberships.join' ) ) + '</button>'
				: '' ) +
			'</div>';

		if ( ! Members.members.length ) {
			return head + App.emptyState( 'users', App.t( 'panel.memberships.nobodyTitle' ),
				esc( App.t( 'panel.memberships.nobodyBody' ) ) );
		}

		return head + App.table(
			[
				App.t( 'panel.memberships.person' ),
				App.t( 'panel.memberships.scheme' ),
				App.t( 'panel.memberships.until' ),
				'',
			],
			Members.members.map( function ( row ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( row.name || row.email ) +
					( row.name
						? '<span class="muted on-own-line">' + esc( row.email ) + '</span>'
						: '' ) + '</td>' +
					'<td>' + esc( row.scheme.name || '' ) + '</td>' +
					'<td>' + esc( App.date( row.ends_at ) ) +
					( row.current
						? ''
						: ' <span class="badge badge--neutral">' +
							esc( App.t( 'panel.memberships.lapsedOne' ) ) + '</span>' ) + '</td>' +
					'<td class="table__actions">' +
					( row.current
						? '<button class="btn btn--sm btn--danger" data-mem-cancel="' + esc( row.id ) +
							'">' + esc( App.t( 'panel.memberships.end' ) ) + '</button>'
						: '' ) + '</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Members.bind = function () {
		var App = Members.App;

		bind( 'mem-add', function () { Members.form( null ); } );
		bind( 'mem-join', function () { Members.joinForm(); } );

		each( '[data-mem-edit]', function ( button ) {
			button.addEventListener( 'click', function () {
				Members.form( Members.schemes.filter( function ( scheme ) {
					return scheme.id === button.dataset.memEdit;
				} )[ 0 ] );
			} );
		} );

		each( '[data-mem-delete]', function ( button ) {
			button.addEventListener( 'click', function () { Members.remove( button.dataset.memDelete ); } );
		} );

		each( '[data-mem-filter]', function ( button ) {
			button.addEventListener( 'click', function () {
				Members.filter = button.dataset.memFilter;
				Members.render( App );
			} );
		} );

		each( '[data-mem-cancel]', function ( button ) {
			button.addEventListener( 'click', function () { Members.end( button.dataset.memCancel ); } );
		} );

		each( '[data-mem-event]', function ( box ) {
			box.addEventListener( 'change', function () {
				App.request( 'PATCH', '/events/' + box.dataset.memEvent, {
					member_presale: box.checked,
				} )
					.then( function () { App.toast( App.t( 'panel.memberships.earlySaved' ) ); } )
					.catch( function ( error ) {
						box.checked = ! box.checked;
						App.toast( error.message, true );
					} );
			} );
		} );
	};

	Members.form = function ( scheme ) {
		var App = Members.App;

		App.modal( {
			title: App.t( scheme ? 'panel.memberships.editScheme' : 'panel.memberships.addScheme' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="field"><label class="field__label" for="mem-name">' +
				esc( App.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="mem-name" name="name" required maxlength="80" value="' +
				esc( scheme ? scheme.name : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="mem-desc">' +
				esc( App.t( 'panel.memberships.what' ) ) + '</label>' +
				'<input class="input" id="mem-desc" name="description" maxlength="300" value="' +
				esc( scheme ? scheme.description : '' ) + '"></div>' +
				'<div class="field-duo">' +
				'<div class="field"><label class="field__label" for="mem-price">' +
				esc( App.t( 'panel.memberships.price' ) ) + '</label>' +
				'<input class="input tnum" id="mem-price" name="price" type="number" min="0" required value="' +
				esc( scheme ? scheme.price : 0 ) + '">' +
				'<p class="field__hint">' + esc( App.t( 'panel.memberships.priceHint' ) ) + '</p></div>' +
				'<div class="field"><label class="field__label" for="mem-currency">' +
				esc( App.t( 'panel.memberships.currency' ) ) + '</label>' +
				'<input class="input" id="mem-currency" name="currency" required maxlength="3" value="' +
				esc( scheme ? scheme.currency : 'EUR' ) + '"></div>' +
				'</div>' +
				'<div class="field-duo">' +
				'<div class="field"><label class="field__label" for="mem-months">' +
				esc( App.t( 'panel.memberships.lasts' ) ) + '</label>' +
				'<input class="input tnum" id="mem-months" name="months" type="number" min="1" max="120" required value="' +
				esc( scheme ? scheme.months : 12 ) + '"></div>' +
				'<div class="field"><label class="field__label" for="mem-percent">' +
				esc( App.t( 'panel.memberships.worth' ) ) + '</label>' +
				'<input class="input tnum" id="mem-percent" name="discount_percent" type="number" min="0" max="100" value="' +
				esc( scheme ? scheme.discount_percent : 0 ) + '">' +
				'<p class="field__hint">' + esc( App.t( 'panel.memberships.worthHint' ) ) + '</p></div>' +
				'</div>' +
				'<label class="switch switch--row"><input type="checkbox" name="presale"' +
				( scheme && scheme.presale ? ' checked' : '' ) + '>' +
				'<span class="switch__track"><span class="switch__thumb"></span></span>' +
				'<span>' + esc( App.t( 'panel.memberships.booksEarly' ) ) + '</span></label>' +
				'<label class="switch switch--row"><input type="checkbox" name="sell_online"' +
				( scheme && scheme.sell_online ? ' checked' : '' ) + '>' +
				'<span class="switch__track"><span class="switch__thumb"></span></span>' +
				'<span>' + esc( App.t( 'panel.memberships.sellOnline' ) ) + '</span></label>' +
				'<p class="field__hint">' + esc( App.t( 'panel.memberships.sellOnlineHint' ) ) + '</p>' +
				'<label class="switch switch--row"><input type="checkbox" name="enabled"' +
				( ! scheme || scheme.enabled ? ' checked' : '' ) + '>' +
				'<span class="switch__track"><span class="switch__thumb"></span></span>' +
				'<span>' + esc( App.t( 'panel.memberships.running' ) ) + '</span></label>',
			onSubmit: function ( data ) {
				var body = {
					name: data.get( 'name' ),
					description: data.get( 'description' ),
					currency: ( data.get( 'currency' ) || 'EUR' ).toUpperCase(),
					price: Number( data.get( 'price' ) || 0 ),
					months: Number( data.get( 'months' ) || 12 ),
					discount_percent: Number( data.get( 'discount_percent' ) || 0 ),
					presale: !! data.get( 'presale' ),
					sell_online: !! data.get( 'sell_online' ),
					enabled: !! data.get( 'enabled' ),
				};

				var call = scheme
					? App.request( 'PATCH', '/memberships/' + scheme.id, body )
					: App.request( 'POST', '/memberships', body );

				return call.then( function () {
					App.toast( App.t( 'panel.memberships.saved' ) );
					Members.render( App );
				} );
			},
		} );
	};

	/** Somebody joins at the window, or an old paper list is brought across one line at a time. */
	Members.joinForm = function () {
		var App = Members.App;

		App.modal( {
			title: App.t( 'panel.memberships.join' ),
			submitLabel: App.t( 'panel.memberships.join' ),
			body:
				'<div class="field"><label class="field__label" for="mem-scheme">' +
				esc( App.t( 'panel.memberships.scheme' ) ) + '</label>' +
				'<select class="select" id="mem-scheme" name="scheme_id">' +
				Members.schemes.map( function ( scheme ) {
					return '<option value="' + esc( scheme.id ) + '">' + esc( scheme.name ) + '</option>';
				} ).join( '' ) + '</select></div>' +
				'<div class="field"><label class="field__label" for="mem-email">' +
				esc( App.t( 'panel.memberships.email' ) ) + '</label>' +
				'<input class="input" id="mem-email" name="email" type="email" required maxlength="190"></div>' +
				'<div class="field"><label class="field__label" for="mem-person">' +
				esc( App.t( 'panel.memberships.person' ) ) + '</label>' +
				'<input class="input" id="mem-person" name="name" maxlength="120"></div>' +
				'<p class="field__hint">' + esc( App.t( 'panel.memberships.joinHint' ) ) + '</p>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/memberships/members', {
					scheme_id: data.get( 'scheme_id' ),
					email: data.get( 'email' ),
					name: data.get( 'name' ),
				} ).then( function () {
					App.toast( App.t( 'panel.memberships.joined' ) );
					Members.filter = 'current';
					Members.render( App );
				} );
			},
		} );
	};

	Members.remove = function ( id ) {
		var App = Members.App;

		App.modal( {
			title: App.t( 'panel.memberships.deleteTitle' ),
			submitLabel: App.t( 'panel.common.remove' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.memberships.deleteBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/memberships/' + id ).then( function () {
					App.toast( App.t( 'panel.memberships.deleted' ) );
					Members.render( App );
				} );
			},
		} );
	};

	Members.end = function ( id ) {
		var App = Members.App;

		App.modal( {
			title: App.t( 'panel.memberships.endTitle' ),
			submitLabel: App.t( 'panel.memberships.end' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.memberships.endBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/memberships/members/' + id ).then( function () {
					App.toast( App.t( 'panel.memberships.ended' ) );
					Members.render( App );
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

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapMemberships = Members;
}( typeof window !== 'undefined' ? window : globalThis ) );
