/**
 * A rehearsal: the buyer's path walked through before anybody real is on it.
 *
 * The screen is deliberately small, because the work does not happen here — it happens on the
 * organiser's own site, with the organiser pretending to be a customer. So this is three things: a
 * switch, an address to go to, and an honest account of what the rehearsal has left lying about,
 * with the one button that sweeps it away.
 *
 * The tally is what makes the clear safe to offer. "Twelve bookings, fourteen seats, €418 that
 * never moved" is a sentence somebody can check against what they remember doing; a bare
 * "Clear test data" button is one they have to trust.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Rehearsal = {
		App: null,
		events: [],
		eventId: '',
		state: null,
	};

	Rehearsal.render = function ( App ) {
		Rehearsal.App = App;
		App.loading( App.t( 'panel.rehearsal.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Rehearsal.events = response.data || [];
				Rehearsal.eventId = Rehearsal.eventId || ( Rehearsal.events[ 0 ] || {} ).id || '';
				Rehearsal.paint();

				if ( Rehearsal.eventId ) {
					Rehearsal.load();
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Rehearsal.paint = function () {
		var App = Rehearsal.App;

		App.page( {
			title: App.t( 'panel.rehearsal.title' ),
			description: esc( App.t( 'panel.rehearsal.description' ) ),
			body:
				( Rehearsal.events.length
					? '<div class="filters">' +
						'<select class="select grow" id="reh-event" aria-label="' +
							esc( App.t( 'panel.rehearsal.event' ) ) + '">' +
							Rehearsal.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Rehearsal.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) +
									( event.is_rehearsal
										? ' — ' + esc( App.t( 'panel.rehearsal.badge' ) )
										: '' ) +
								'</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.rehearsal.noEventsTitle' ),
						esc( App.t( 'panel.rehearsal.noEventsBody' ) ) ) ) +
				'<div id="reh-body" class="spaced"></div>',
		} );

		var picker = document.getElementById( 'reh-event' );

		if ( picker ) {
			picker.addEventListener( 'change', function () {
				Rehearsal.eventId = picker.value;
				Rehearsal.load();
			} );
		}
	};

	Rehearsal.load = function () {
		var App = Rehearsal.App;
		var host = document.getElementById( 'reh-body' );

		if ( ! host || ! Rehearsal.eventId ) {
			return;
		}

		App.request( 'GET', '/events/' + Rehearsal.eventId + '/rehearsal' )
			.then( function ( state ) {
				Rehearsal.state = state;
				host.innerHTML = Rehearsal.markup( App, state );
				Rehearsal.bind();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Rehearsal.markup = function ( App, state ) {
		return '<div class="card card--pad">' +
			'<label class="switch switch--row"><input type="checkbox" id="reh-on"' +
			( state.rehearsing ? ' checked' : '' ) + '>' +
			'<span class="switch__track"><span class="switch__thumb"></span></span>' +
			'<span>' + esc( App.t( 'panel.rehearsal.rehearsing' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( App.t( 'panel.rehearsal.rehearsingHint' ) ) + '</p>' +

			/*
			 * The address, offered only while the night is actually being rehearsed.
			 *
			 * Shown at all because the rehearsal happens there rather than here, and an organiser
			 * should not have to assemble their own site's URL out of a public id.
			 */
			( state.rehearsing && state.link
				? '<p class="spaced"><a class="btn" href="' + esc( state.link ) + '" ' +
					'target="_blank" rel="noopener">' + icon( 'external', { size: 15 } ) +
					esc( App.t( 'panel.rehearsal.walk' ) ) + '</a></p>' +
					'<p class="field__hint">' + esc( App.t( 'panel.rehearsal.walkHint' ) ) + '</p>'
				: '' ) +
		'</div>' +

		( state.bookings
			? '<div class="stat-strip">' +
				tile( App.t( 'panel.rehearsal.bookings' ), App.number( state.bookings ) ) +
				tile( App.t( 'panel.rehearsal.seats' ), App.number( state.seats ) ) +
				tile( App.t( 'panel.rehearsal.tickets' ), App.number( state.tickets ) ) +
				tile( App.t( 'panel.rehearsal.scans' ), App.number( state.scans ) ) +
				// The figure that says what a rehearsal is: money that was quoted, agreed and
				// never taken from anybody.
				tile( App.t( 'panel.rehearsal.notCharged' ),
					App.money( state.not_charged, state.currency ) ) +
			'</div>' +
			'<div class="card card--pad spaced">' +
				'<p>' + esc( App.t( 'panel.rehearsal.clearBody' ) ) + '</p>' +
				'<p><button class="btn btn--danger" id="reh-clear">' +
					icon( 'trash', { size: 15 } ) +
					esc( App.t( 'panel.rehearsal.clear' ) ) + '</button></p>' +
			'</div>'
			: '<p class="is-muted spaced">' +
				esc( App.t( state.rehearsing
					? 'panel.rehearsal.nothingYet'
					: 'panel.rehearsal.notRehearsing' ) ) + '</p>' );
	};

	Rehearsal.bind = function () {
		var App = Rehearsal.App;
		var flag = document.getElementById( 'reh-on' );
		var clear = document.getElementById( 'reh-clear' );

		if ( flag ) {
			flag.addEventListener( 'change', function () {
				var wanted = flag.checked;

				App.request( 'PUT', '/events/' + Rehearsal.eventId + '/rehearsal', {
					rehearsing: wanted,
				} )
					.then( function () {
						App.toast( App.t( wanted
							? 'panel.rehearsal.started'
							: 'panel.rehearsal.stopped' ) );
						Rehearsal.refresh();
					} )
					.catch( function ( error ) {
						// Put the switch back where it was: it now says something the server
						// refused, and a switch that lies is worse than a switch that sticks.
						flag.checked = ! wanted;
						App.toast( error.message, true );
					} );
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () { Rehearsal.clear(); } );
		}
	};

	Rehearsal.clear = function () {
		var App = Rehearsal.App;
		var state = Rehearsal.state || {};

		App.modal( {
			title: App.t( 'panel.rehearsal.clearTitle' ),
			submitLabel: App.t( 'panel.rehearsal.clear' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.rehearsal.clearConfirm', {
				count: App.number( state.bookings || 0 ),
				seats: App.number( state.seats || 0 ),
			} ) ) + '</p>' +
				'<p class="field__hint">' + esc( App.t( 'panel.rehearsal.clearHint' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/events/' + Rehearsal.eventId + '/rehearsal', {} )
					.then( function () {
						App.toast( App.t( 'panel.rehearsal.cleared' ) );
						Rehearsal.refresh();
					} );
			},
		} );
	};

	/** Both lists: the tally, and the event names the picker labels with "rehearsing". */
	Rehearsal.refresh = function () {
		var App = Rehearsal.App;

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Rehearsal.events = response.data || [];
				Rehearsal.paint();
				Rehearsal.load();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function tile( label, value, hint ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
			( hint ? '<span class="tile__meta">' + esc( hint ) + '</span>' : '' ) +
		'</div>';
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapRehearsal = Rehearsal;
}( typeof window !== 'undefined' ? window : globalThis ) );
