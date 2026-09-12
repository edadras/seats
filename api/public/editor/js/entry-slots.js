/**
 * When people may come in.
 *
 * For the events where the constraint is not a chair but how many people may be in the room at
 * once — a museum, an exhibition, a Christmas market. The day is cut into windows, each holding a
 * number of people, and a buyer picks the one they will arrive in.
 *
 * The generator is the point of the screen. Nobody types "10:00 – 10:30" twenty times without
 * giving the eleven o'clock a capacity of 3 by accident, so a whole day is filled in one step and
 * then edited window by window.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Slots = {
		App: null,
		events: [],
		eventId: '',
		list: [],
		timezone: null,
	};

	Slots.render = function ( App ) {
		Slots.App = App;
		App.loading( App.t( 'panel.entrySlots.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Slots.events = response.data || [];
				Slots.eventId = App.pickNight( Slots.events, Slots.eventId );
				Slots.paint();

				if ( Slots.eventId ) {
					Slots.load();
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Slots.paint = function () {
		var App = Slots.App;

		App.page( {
			title: App.t( 'panel.entrySlots.title' ),
			description: esc( App.t( 'panel.entrySlots.description' ) ),
			actions: Slots.eventId
				? '<button class="btn" id="es-fill">' + icon( 'clock', { size: 15 } ) +
						esc( App.t( 'panel.entrySlots.fillDay' ) ) + '</button>' +
					'<button class="btn btn--primary" id="es-add">' + icon( 'plus', { size: 15 } ) +
						esc( App.t( 'panel.entrySlots.add' ) ) + '</button>'
				: '',
			body:
				( Slots.events.length
					? '<div class="filters">' +
						'<select class="select grow" id="es-event" aria-label="' +
							esc( App.t( 'panel.entrySlots.event' ) ) + '">' +
							Slots.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Slots.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.entrySlots.noEventsTitle' ),
						esc( App.t( 'panel.entrySlots.noEventsBody' ) ), App.goesTo( 'events' ) ) ) +
				'<div id="es-rows" class="spaced"></div>',
		} );

		var picker = document.getElementById( 'es-event' );

		if ( picker ) {
			picker.addEventListener( 'change', function () {
				Slots.eventId = App.night( picker.value );
				Slots.load();
			} );
		}

		bind( 'es-add', function () { Slots.form( null ); } );
		bind( 'es-fill', function () { Slots.fill(); } );
	};

	Slots.load = function () {
		var App = Slots.App;
		var host = document.getElementById( 'es-rows' );

		if ( ! host || ! Slots.eventId ) {
			return;
		}

		App.request( 'GET', '/events/' + Slots.eventId + '/entry-slots' )
			.then( function ( response ) {
				Slots.list = response.data || [];
				Slots.timezone = response.timezone || null;
				host.innerHTML = Slots.rowsMarkup( App );
				Slots.bindRows();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Slots.rowsMarkup = function ( App ) {
		if ( ! Slots.list.length ) {
			return App.emptyState( 'clock', App.t( 'panel.entrySlots.emptyTitle' ),
				esc( App.t( 'panel.entrySlots.emptyBody' ) ),
				{ does: 'es-add', label: App.t( 'panel.entrySlots.add' ) } );
		}

		return App.table(
			[
				App.t( 'panel.entrySlots.window' ),
				{ label: App.t( 'panel.entrySlots.capacity' ), numeric: true },
				{ label: App.t( 'panel.entrySlots.sold' ), numeric: true },
				{ label: App.t( 'panel.entrySlots.left' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			Slots.list.map( function ( slot, index ) {
				return '<tr' + ( 'open' === slot.status ? '' : ' class="is-muted"' ) + '>' +
					// The clock is the label and the venue's own; only the day goes beneath it.
					// Printing a second time here in the reader's own zone would put two different
					// hours on one row.
					'<td class="table__primary">' + esc( slot.label ) +
						'<span class="muted on-own-line">' +
						esc( App.date( slot.starts_at, { dateStyle: 'medium' } ) ) + '</span></td>' +
					'<td class="tnum">' + ( null === slot.capacity || undefined === slot.capacity
						? '<span class="muted">' + esc( App.t( 'panel.entrySlots.noLimit' ) ) + '</span>'
						: esc( App.number( slot.capacity ) ) ) + '</td>' +
					'<td class="tnum">' + esc( App.number( slot.sold || 0 ) ) + '</td>' +
					'<td class="tnum">' + ( null === slot.remaining || undefined === slot.remaining
						? '<span class="muted">—</span>'
						: esc( App.number( slot.remaining ) ) ) + '</td>' +
					'<td>' + ( slot.sold_out
						? '<span class="badge badge--warn">' +
							esc( App.t( 'panel.entrySlots.full' ) ) + '</span>'
						: '<span class="badge badge--' + ( 'open' === slot.status ? 'ok' : 'neutral' ) +
							'">' + esc( App.t( 'panel.entrySlots.states.' + slot.status ) ) +
							'</span>' ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-es-edit="' + index + '">' +
							esc( App.t( 'panel.entrySlots.edit' ) ) + '</button>' +
						// A window somebody has been sold into cannot be removed: their ticket
						// says to arrive in it, and the door would have nothing to look up.
						( slot.sold
							? ''
							: '<button class="btn btn--sm" data-es-drop="' + index + '">' +
								esc( App.t( 'panel.entrySlots.remove' ) ) + '</button>' ) +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Slots.bindRows = function () {
		var App = Slots.App;

		each( '[data-es-edit]', function ( button ) {
			button.addEventListener( 'click', function () {
				Slots.form( Number( button.dataset.esEdit ) );
			} );
		} );

		each( '[data-es-drop]', function ( button ) {
			button.addEventListener( 'click', function () {
				Slots.list.splice( Number( button.dataset.esDrop ), 1 );
				Slots.save().catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );
	};

	Slots.form = function ( index ) {
		var App = Slots.App;
		var slot = null === index
			? { label: '', starts_at: null, ends_at: null, capacity: null, status: 'open' }
			: Slots.list[ index ];

		App.modal( {
			title: App.t( null === index ? 'panel.entrySlots.add' : 'panel.entrySlots.edit' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
				'<div class="field-duo">' +
					field( 'es-from', App.t( 'panel.entrySlots.from' ), local( slot.starts_at ) ) +
					field( 'es-to', App.t( 'panel.entrySlots.to' ), local( slot.ends_at ) ) +
				'</div>' +
				'<div class="field"><label class="field__label" for="es-capacity">' +
					esc( App.t( 'panel.entrySlots.capacity' ) ) + '</label>' +
					'<input class="input tnum" id="es-capacity" type="number" min="1" value="' +
					esc( null === slot.capacity || undefined === slot.capacity ? '' : slot.capacity ) +
					'"><span class="field__hint">' +
					esc( App.t( 'panel.entrySlots.capacityHint' ) ) + '</span></div>' +
				'<div class="field"><label class="field__label" for="es-label">' +
					esc( App.t( 'panel.entrySlots.label' ) ) + '</label>' +
					'<input class="input" id="es-label" maxlength="80" value="' +
					esc( slot.name || '' ) +
					'"><span class="field__hint">' +
					esc( App.t( 'panel.entrySlots.labelHint' ) ) + '</span></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="es-closed"' +
					( 'open' === slot.status ? '' : ' checked' ) + '>' +
					'<span>' + esc( App.t( 'panel.entrySlots.closedLabel' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var from = document.getElementById( 'es-from' ).value;
				var to = document.getElementById( 'es-to' ).value;

				if ( ! from || ! to || to <= from ) {
					App.toast( App.t( 'panel.entrySlots.needWindow' ), true );

					return true;
				}

				var capacity = document.getElementById( 'es-capacity' ).value;

				var next = {
					id: slot.id || null,
					label: document.getElementById( 'es-label' ).value.trim() || null,
					starts_at: instant( from ),
					ends_at: instant( to ),
					capacity: '' === capacity ? null : Number( capacity ),
					status: document.getElementById( 'es-closed' ).checked ? 'closed' : 'open',
					sold: slot.sold || 0,
				};

				if ( null === index ) {
					Slots.list.push( next );
				} else {
					Slots.list[ index ] = next;
				}

				return Slots.save();
			},
		} );
	};

	/**
	 * Fill a day.
	 *
	 * The generator hands back rows rather than writing them, so an organiser sees the timetable
	 * they are about to create and can change one window before anything exists. The windows join
	 * whatever is already there, and the server refuses the save if any two of them overlap.
	 */
	Slots.fill = function () {
		var App = Slots.App;

		App.modal( {
			title: App.t( 'panel.entrySlots.fillDay' ),
			submitLabel: App.t( 'panel.entrySlots.fillAction' ),
			body:
				'<div class="stack">' +
				'<div class="field-duo">' +
					field( 'es-fill-from', App.t( 'panel.entrySlots.dayFrom' ), '' ) +
					field( 'es-fill-to', App.t( 'panel.entrySlots.dayTo' ), '' ) +
				'</div>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="es-fill-minutes">' +
						esc( App.t( 'panel.entrySlots.everyMinutes' ) ) + '</label>' +
						'<input class="input tnum" id="es-fill-minutes" type="number" min="5" ' +
						'max="720" value="30"></div>' +
					'<div class="field"><label class="field__label" for="es-fill-capacity">' +
						esc( App.t( 'panel.entrySlots.capacity' ) ) + '</label>' +
						'<input class="input tnum" id="es-fill-capacity" type="number" min="1"></div>' +
				'</div>' +
				'</div>',
			onSubmit: function () {
				var from = document.getElementById( 'es-fill-from' ).value;
				var to = document.getElementById( 'es-fill-to' ).value;
				var capacity = document.getElementById( 'es-fill-capacity' ).value;

				if ( ! from || ! to || to <= from ) {
					App.toast( App.t( 'panel.entrySlots.needWindow' ), true );

					return true;
				}

				return App.request( 'POST', '/events/' + Slots.eventId + '/entry-slots/generate', {
					starts_at: instant( from ),
					ends_at: instant( to ),
					minutes: Number( document.getElementById( 'es-fill-minutes' ).value ) || 30,
					capacity: '' === capacity ? null : Number( capacity ),
				} ).then( function ( response ) {
					Slots.list = Slots.list.concat( response.data || [] );

					return Slots.save();
				} );
			},
		} );
	};

	/** The whole timetable, every time: a day's windows are one decision about the event. */
	Slots.save = function () {
		var App = Slots.App;

		return App.request( 'PUT', '/events/' + Slots.eventId + '/entry-slots', {
			slots: Slots.list.map( function ( slot ) {
				return {
					id: slot.id || null,
					label: slot.label || null,
					starts_at: slot.starts_at,
					ends_at: slot.ends_at,
					capacity: null === slot.capacity || undefined === slot.capacity
						? null
						: Number( slot.capacity ),
					status: slot.status || 'open',
				};
			} ),
		} ).then( function ( response ) {
			Slots.list = response.data || [];
			App.toast( App.t( 'panel.entrySlots.saved' ) );
			Slots.load();
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function field( id, label, value ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' + esc( label ) +
			'</label><input class="input" id="' + id + '" type="datetime-local" value="' +
			esc( value ) + '"></div>';
	}

	/**
	 * An ISO instant as the value a datetime-local input wants.
	 *
	 * The browser's own clock, deliberately: an organiser editing tomorrow's timetable is sitting
	 * at the venue, and a field that showed UTC would have them typing an hour they do not mean.
	 */
	/**
	 * And back: what the organiser typed, as the instant they meant.
	 *
	 * A datetime-local value is a wall clock with no zone on it. Sent as it stands, a server
	 * running in UTC would read "10:00" as ten in Greenwich, and an organiser in Berlin would find
	 * their morning windows an hour out.
	 */
	function instant( value ) {
		return value ? new Date( value ).toISOString() : null;
	}

	function local( iso ) {
		if ( ! iso ) {
			return '';
		}

		var when = new Date( iso );
		var pad = function ( number ) { return String( number ).padStart( 2, '0' ); };

		return when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() ) +
			'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );
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

	global.SeatmapEntrySlots = Slots;
}( typeof window !== 'undefined' ? window : globalThis ) );
