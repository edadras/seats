/**
 * Programme managers: an administrator for one concert rather than for the account.
 *
 * A promoter puts on four nights in somebody else's venue. This is where an organiser appoints
 * them, hands over the nights, and takes one back — and the whole screen is that: a list of people
 * and, against each, the list of nights they run. There is nothing else to set, because everything
 * else a manager may do is decided by the role and multiplied by that list.
 */
( function ( global ) {
	'use strict';

	var Managers = {};

	Managers.render = function ( App ) {
		Managers.App = App;
		App.loading( App.t( 'panel.nav.managers' ) );

		Promise.all( [
			App.request( 'GET', '/programme-managers' ),
			App.request( 'GET', '/events?per_page=100' ),
		] )
			.then( function ( answers ) {
				Managers.list = answers[ 0 ].data || [];
				Managers.events = answers[ 1 ].data || [];
				Managers.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Managers.paint = function () {
		var App = Managers.App;

		App.page( {
			title: App.t( 'panel.nav.managers' ),
			description: esc( App.t( 'panel.managers.subtitle' ) ),
			actions: '<button class="btn btn--primary" id="mgr-add">' +
				esc( App.t( 'panel.managers.add' ) ) + '</button>',
			body: App.table(
				[
					App.t( 'panel.common.name' ),
					App.t( 'panel.managers.nights' ),
					'',
				],
				Managers.list.map( function ( row ) {
					return '<tr><td class="table__primary">' + esc( row.name || row.email ) +
						'<span class="muted on-own-line">' + esc( row.email ) + '</span></td>' +
						'<td>' + ( row.events.length
							? row.events.map( function ( event ) {
								return esc( event.name );
							} ).join( '<span class="muted"> · </span>' )
							: '<span class="muted">' + esc( App.t( 'panel.managers.noNights' ) ) + '</span>' ) +
						'</td>' +
						'<td class="table__actions">' +
							'<button class="btn btn--sm" data-mgr-events="' + esc( row.user_id ) + '">' +
								esc( App.t( 'panel.managers.chooseNights' ) ) + '</button>' +
						'</td></tr>';
				} ).join( '' ),
				App.emptyState( 'users', App.t( 'panel.managers.emptyTitle' ),
					esc( App.t( 'panel.managers.emptyBody' ) ) )
			),
		} );

		bind( 'mgr-add', function () { Managers.add(); } );

		each( '[data-mgr-events]', function ( button ) {
			button.addEventListener( 'click', function () {
				Managers.chooseNights( button.dataset.mgrEvents );
			} );
		} );
	};

	/* ------------------------------------------------------------------------------- forms */

	Managers.add = function () {
		var App = Managers.App;

		App.modal( {
			title: App.t( 'panel.managers.add' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.managers.addHint' ) ) + '</p>' +
					field( 'mgr-name', App.t( 'panel.common.name' ), '', 120 ) +
					field( 'mgr-email', App.t( 'panel.agents.email' ), '', 190 ) +
					'<fieldset class="perms"><legend class="perms__legend">' +
						esc( App.t( 'panel.managers.nights' ) ) + '</legend>' +
						Managers.nightBoxes( [] ) +
					'</fieldset>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/programme-managers', {
					name: value( 'mgr-name' ),
					email: value( 'mgr-email' ),
					event_ids: Managers.ticked(),
				} ).then( function ( made ) {
					Managers.list = made.data || [];
					Managers.paint();
					Managers.showSignIn( made.email, made.password );
				} );
			},
		} );
	};

	Managers.chooseNights = function ( userId ) {
		var App = Managers.App;
		var manager = Managers.list.filter( function ( row ) {
			return row.user_id === userId;
		} )[ 0 ];

		if ( ! manager ) {
			return;
		}

		var held = manager.events.map( function ( event ) { return event.id; } );

		App.modal( {
			title: manager.name || manager.email,
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.managers.nightsHint' ) ) + '</p>' +
					'<fieldset class="perms"><legend class="perms__legend">' +
						esc( App.t( 'panel.managers.nights' ) ) + '</legend>' +
						Managers.nightBoxes( held ) +
					'</fieldset>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'PUT', '/programme-managers/' + userId + '/events', {
					event_ids: Managers.ticked(),
				} ).then( function ( response ) {
					Managers.list = response.data || [];
					Managers.paint();
					App.toast( App.t( 'panel.managers.saved' ) );
				} );
			},
		} );
	};

	/** One tick box per night, ticked where this manager already runs it. */
	Managers.nightBoxes = function ( held ) {
		var App = Managers.App;

		if ( ! Managers.events.length ) {
			return '<p class="muted">' + esc( App.t( 'panel.managers.noEvents' ) ) + '</p>';
		}

		return '<div id="mgr-events">' + Managers.events.map( function ( event ) {
			return '<label class="perms__row">' +
				'<input type="checkbox" class="checkbox" value="' + esc( event.id ) + '"' +
				( held.indexOf( event.id ) > -1 ? ' checked' : '' ) + '>' +
				'<span>' + esc( event.name ) +
					'<span class="muted on-own-line">' + esc( App.date( event.starts_at ) ) + '</span>' +
				'</span></label>';
		} ).join( '' ) + '</div>';
	};

	Managers.ticked = function () {
		return Array.prototype.slice
			.call( document.querySelectorAll( '#mgr-events input:checked' ) )
			.map( function ( box ) { return box.value; } );
	};

	/**
	 * The password, once.
	 *
	 * The same bargain the platform makes with an API secret and with an agency's sign-in: it is on
	 * this screen and nowhere else, so the screen has to say so while it is up.
	 */
	Managers.showSignIn = function ( email, password ) {
		var App = Managers.App;

		App.modal( {
			title: App.t( 'panel.managers.signInTitle' ),
			submitLabel: App.t( 'panel.common.done' ),
			body:
				'<div class="stack">' +
					'<p>' + esc( App.t( 'panel.managers.signInBody' ) ) + '</p>' +
					'<ul class="kbd-list">' +
						'<li><code>' + esc( email ) + '</code></li>' +
						'<li><code>' + esc( password ) + '</code></li>' +
					'</ul>' +
					'<p class="hint">' + esc( App.t( 'panel.agents.signInOnce' ) ) + '</p>' +
				'</div>',
			onSubmit: function () { return Promise.resolve(); },
		} );
	};

	/* ----------------------------------------------------------------------------- helpers */

	function field( id, label, current, max ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' +
			esc( label ) + '</label>' +
			'<input class="input" id="' + id + '" maxlength="' + max + '" value="' +
			esc( current || '' ) + '"></div>';
	}

	function value( id ) {
		return ( document.getElementById( id ).value || '' ).trim();
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

	global.SeatmapManagers = Managers;
}( typeof window !== 'undefined' ? window : globalThis ) );
