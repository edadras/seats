/**
 * The queue for a sold-out night, from the organiser's side.
 *
 * Mostly a thing to look at — how many people want in, and who has already been told — with one
 * action for the moment an organiser releases a block of house seats and does not want to wait for
 * the next scheduled round.
 *
 * The tally is the part that matters: "eleven people want twenty-three places, and there are four"
 * is the sentence an organiser is trying to form, and a list of names alone does not say it.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Wait = {
		App: null,
		events: [],
		eventId: '',
		status: '',
		summary: null,
	};

	Wait.render = function ( App ) {
		Wait.App = App;
		App.loading( App.t( 'panel.waitlist.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Wait.events = response.data || [];
				Wait.eventId = App.pickNight( Wait.events, Wait.eventId );
				Wait.paint();

				if ( Wait.eventId ) {
					Wait.load();
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Wait.paint = function () {
		var App = Wait.App;

		App.page( {
			title: App.t( 'panel.waitlist.title' ),
			description: esc( App.t( 'panel.waitlist.description' ) ),
			actions: Wait.eventId
				? '<button class="btn" id="wait-notify">' + icon( 'mail', { size: 15 } ) +
					esc( App.t( 'panel.waitlist.notify' ) ) + '</button>'
				: '',
			body:
				( Wait.events.length
					? '<div class="filters">' +
						'<select class="select grow" id="wait-event" aria-label="' +
							esc( App.t( 'panel.waitlist.event' ) ) + '">' +
							Wait.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Wait.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
						'<select class="select" id="wait-status" aria-label="' +
							esc( App.t( 'panel.waitlist.anyStatus' ) ) + '">' +
							[ '', 'waiting', 'notified', 'converted', 'lapsed', 'left' ].map( function ( status ) {
								return '<option value="' + status + '"' +
									( status === Wait.status ? ' selected' : '' ) + '>' +
									esc( App.t( 'panel.waitlist.statuses.' + ( status || 'any' ) ) ) +
								'</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.waitlist.noEventsTitle' ),
						esc( App.t( 'panel.waitlist.noEventsBody' ) ), App.goesTo( 'events' ) ) ) +
				'<div id="wait-summary" class="spaced"></div>' +
				'<div id="wait-rows"></div>',
		} );

		[ [ 'wait-event', 'eventId' ], [ 'wait-status', 'status' ] ].forEach( function ( pair ) {
			var field = document.getElementById( pair[ 0 ] );

			if ( ! field ) {
				return;
			}

			field.addEventListener( 'change', function () {
				Wait[ pair[ 1 ] ] = 'eventId' === pair[ 1 ]
					? App.night( field.value )
					: field.value;
				Wait.load();
			} );
		} );

		var notify = document.getElementById( 'wait-notify' );

		if ( notify ) {
			notify.addEventListener( 'click', function () { Wait.tellThem(); } );
		}
	};

	Wait.load = function () {
		var App = Wait.App;
		var host = document.getElementById( 'wait-rows' );

		if ( ! host || ! Wait.eventId ) {
			return;
		}

		App.request( 'GET', '/events/' + Wait.eventId + '/waiting-list' +
			( Wait.status ? '?status=' + Wait.status : '' ) )
			.then( function ( response ) {
				Wait.summary = response.summary;
				document.getElementById( 'wait-summary' ).innerHTML =
					Wait.summaryMarkup( App, response.summary );
				host.innerHTML = Wait.rowsMarkup( App, response );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Wait.summaryMarkup = function ( App, summary ) {
		return '<div class="stat-strip">' +
			tile( App.t( 'panel.waitlist.waiting' ), App.number( summary.waiting ),
				App.t( 'panel.waitlist.wantPlaces', { count: App.number( summary.waiting_places ) } ) ) +
			tile( App.t( 'panel.waitlist.freeNow' ), App.number( summary.free_places ) ) +
			tile( App.t( 'panel.waitlist.told' ), App.number( summary.notified ) ) +
			// The only figure that says whether keeping the list was worth anything. Beside the
			// ones who went quiet, because the two together are the shape of the queue.
			tile( App.t( 'panel.waitlist.bought' ), App.number( summary.converted || 0 ),
				App.t( 'panel.waitlist.quiet', { count: App.number( summary.lapsed || 0 ) } ) ) +
			tile( App.t( 'panel.waitlist.gone' ), App.number( summary.left ) ) +
		'</div>';
	};

	Wait.rowsMarkup = function ( App, response ) {
		if ( ! response.data.length ) {
			// Somebody joins from the event's own page once it sells out; nothing to press here.
			return App.emptyState( 'users', App.t( 'panel.waitlist.emptyTitle' ),
				esc( App.t( 'panel.waitlist.emptyBody' ) ), { waiting: true } );
		}

		return App.table(
			[
				App.t( 'panel.waitlist.name' ),
				{ label: App.t( 'panel.waitlist.wants' ), numeric: true },
				App.t( 'panel.waitlist.joined' ),
				App.t( 'panel.common.status' ),
			],
			response.data.map( function ( entry ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( entry.name ) +
						'<span class="muted on-own-line">' + esc( entry.email ) + '</span></td>' +
					'<td class="tnum">' + esc( App.number( entry.quantity ) ) + '</td>' +
					'<td class="tnum muted">' + esc( App.date( entry.joined_at ) ) + '</td>' +
					'<td>' + Wait.badge( App, entry ) +
						// A name that keeps coming round is worth telling apart from a new one:
						// somebody on their third turn has had three emails and answered none.
						( entry.times_told > 1
							? '<span class="muted on-own-line">' +
								esc( App.t( 'panel.waitlist.turns', {
									count: App.number( entry.times_told ),
								} ) ) + '</span>'
							: '' ) + '</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	/**
	 * Told and still able to act, told and out of time, waiting, bought, quiet, or gone.
	 *
	 * "Notified" on its own would be a half-truth an hour later: their turn has a window, and an
	 * organiser deciding whether to release more seats needs to know which of those two it is.
	 * A turn that has run out is now momentary — the next round puts that person back in the queue
	 * — so the badge for it exists for exactly the minutes in between.
	 */
	Wait.badge = function ( App, entry ) {
		if ( 'notified' === entry.status ) {
			return entry.claiming
				? '<span class="badge badge--warn">' + esc( App.t( 'panel.waitlist.claiming' ) ) + '</span>'
				: '<span class="badge badge--neutral">' + esc( App.t( 'panel.waitlist.missed' ) ) + '</span>';
		}

		var tone = {
			waiting: 'ok',
			converted: 'ok',
			lapsed: 'neutral',
			left: 'neutral',
		}[ entry.status ] || 'neutral';

		return '<span class="badge badge--' + tone + '">' +
			esc( App.t( 'panel.waitlist.statuses.' + entry.status ) ) + '</span>';
	};

	Wait.tellThem = function () {
		var App = Wait.App;

		App.modal( {
			title: App.t( 'panel.waitlist.notifyTitle' ),
			submitLabel: App.t( 'panel.waitlist.notify' ),
			body: '<p>' + esc( App.t( 'panel.waitlist.notifyBody', {
				count: App.number( ( Wait.summary || {} ).free_places || 0 ),
			} ) ) + '</p>' +
				'<p class="field__hint">' + esc( App.t( 'panel.waitlist.notifyHint' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/events/' + Wait.eventId + '/waiting-list/notify', {} )
					.then( function ( result ) {
						App.toast( result.told
							? App.t( 'panel.waitlist.toldCount', { count: App.number( result.told ) } )
							: App.t( 'panel.waitlist.nobodyTold' ) );
						Wait.load();
					} );
			},
		} );
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

	global.SeatmapWaitlist = Wait;
}( typeof window !== 'undefined' ? window : globalThis ) );
