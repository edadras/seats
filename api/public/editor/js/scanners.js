/**
 * The phones and tablets at the doors.
 *
 * This screen is the missing half of a feature that already shipped. The scanner has always paired
 * by exchanging a single-use code for a device token, and `devices.manage` has existed since
 * permissions did — granted to four roles and referred to by nothing. There was no way to issue a
 * code, so there was no way to put a scanner in a volunteer's hand.
 *
 * Four things a duty manager needs, in the order they need them at six o'clock: which devices there
 * are, which nights each may scan, whether it has been seen lately, and when it last took a copy of
 * the door list. That last column is the one that decides whether a device will work when the venue
 * wifi does not, and it is the only place anybody can see it — a tablet cannot tell you how old the
 * copy in its pocket is until you have already switched the wifi off.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Scanners = { App: null, devices: [], events: [] };

	Scanners.render = function ( App ) {
		Scanners.App = App;
		App.loading( App.t( 'panel.scanners.title' ) );

		App.request( 'GET', '/scanners' )
			.then( function ( answer ) {
				Scanners.devices = answer.data || [];
				Scanners.events = answer.events || [];
				Scanners.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Scanners.paint = function () {
		var App = Scanners.App;

		var rows = Scanners.devices.map( function ( device ) {
			return '<tr><td class="table__primary">' + esc( device.name ) +
				'<span class="muted on-own-line">' + esc( App.t( 'panel.scanners.countEvents', {
					count: App.number( ( device.event_ids || [] ).length ),
				} ) ) + '</span></td>' +
				'<td>' + Scanners.state( App, device ) + '</td>' +
				'<td class="muted">' + ( device.last_seen_at
					? esc( App.date( device.last_seen_at ) )
					: esc( App.t( 'panel.common.never' ) ) ) + '</td>' +
				'<td>' + Scanners.list( App, device ) + '</td>' +
				'<td class="table__actions">' +
				button( 'scanner-edit', device.id, App.t( 'panel.common.edit' ), 'settings' ) +
				button( 'scanner-code', device.id, App.t( 'panel.scanners.recode' ), 'key' ) +
				button( 'scanner-delete', device.id, App.t( 'panel.common.remove' ), 'trash' ) +
				'</td></tr>';
		} ).join( '' );

		App.page( {
			title: App.t( 'panel.scanners.title' ),
			description: esc( App.t( 'panel.scanners.description' ) ),
			actions: '<button class="btn btn--primary" id="scanner-add">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.scanners.add' ) ) + '</button>',
			body: App.table(
				[
					App.t( 'panel.common.name' ),
					App.t( 'panel.common.status' ),
					App.t( 'panel.scanners.lastSeen' ),
					App.t( 'panel.scanners.doorList' ),
					'',
				],
				rows,
				App.emptyState(
					'check',
					App.t( 'panel.scanners.emptyTitle' ),
					esc( App.t( 'panel.scanners.emptyBody' ) ),
					{ does: 'scanner-add', label: App.t( 'panel.scanners.add' ) }
				)
			),
		} );

		on( '#scanner-add', function () { Scanners.form( null ); } );

		each( '[data-scanner-edit]', function ( element ) {
			element.addEventListener( 'click', function () {
				Scanners.form( Scanners.find( element.dataset.scannerEdit ) );
			} );
		} );

		each( '[data-scanner-code]', function ( element ) {
			element.addEventListener( 'click', function () {
				Scanners.recode( Scanners.find( element.dataset.scannerCode ) );
			} );
		} );

		each( '[data-scanner-delete]', function ( element ) {
			element.addEventListener( 'click', function () {
				Scanners.remove( Scanners.find( element.dataset.scannerDelete ) );
			} );
		} );
	};

	/** Paired, or waiting for somebody to type the code into a phone. */
	Scanners.state = function ( App, device ) {
		if ( 'active' === device.status ) {
			return badge( App.t( 'panel.scanners.paired' ), 'ok' );
		}

		// A code that has expired is not "waiting": nobody can use it, and the only thing to do
		// about it is issue another.
		var expired = device.pairing_expires_at && new Date( device.pairing_expires_at ) < new Date();

		return badge(
			App.t( expired ? 'panel.scanners.expired' : 'panel.scanners.waiting' ),
			expired ? 'danger' : 'warn'
		);
	};

	/**
	 * How old the copy on this device is.
	 *
	 * Coloured by age rather than shown as a date alone, because "17:04" means nothing at a glance
	 * and "four hours ago, on a night that opened an hour ago" means everything.
	 */
	Scanners.list = function ( App, device ) {
		if ( ! device.door_list_taken_at ) {
			return badge( App.t( 'panel.scanners.noList' ), 'neutral' );
		}

		var hours = ( Date.now() - new Date( device.door_list_taken_at ) ) / 3600000;

		return badge( App.date( device.door_list_taken_at ), hours > 12 ? 'warn' : 'ok' );
	};

	Scanners.find = function ( id ) {
		return Scanners.devices.filter( function ( device ) { return device.id === id; } )[ 0 ];
	};

	/** Name it, and say which nights it may scan. */
	Scanners.form = function ( device ) {
		var App = Scanners.App;
		var held = device ? device.event_ids || [] : [];

		App.modal( {
			title: App.t( device ? 'panel.scanners.edit' : 'panel.scanners.add' ),
			submitLabel: App.t( device ? 'panel.common.save' : 'panel.scanners.create' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="sc-name">' +
				esc( App.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="sc-name" name="name" required maxlength="120" ' +
				'value="' + esc( device ? device.name : '' ) + '" ' +
				'placeholder="' + esc( App.t( 'panel.scanners.namePlaceholder' ) ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.scanners.nameHint' ) ) + '</span></div>' +
				'<div class="field"><span class="field__label">' +
				esc( App.t( 'panel.scanners.nights' ) ) + '</span>' +
				( Scanners.events.length
					? '<div class="perms">' + Scanners.events.map( function ( event ) {
						return '<label class="perms__row"><input type="checkbox" name="event_ids[]" ' +
							'value="' + esc( event.id ) + '"' +
							( held.indexOf( event.id ) > -1 ? ' checked' : '' ) + '>' +
							'<span>' + esc( event.name ) +
							( event.starts_at
								? '<span class="muted on-own-line">' + esc( App.date( event.starts_at ) ) + '</span>'
								: '' ) +
							'</span></label>';
					} ).join( '' ) + '</div>'
					: '<p class="hint">' + esc( App.t( 'panel.scanners.noNights' ) ) + '</p>' ) +
				'<span class="field__hint">' + esc( App.t( 'panel.scanners.nightsHint' ) ) + '</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				var payload = {
					name: data.get( 'name' ),
					event_ids: data.getAll( 'event_ids[]' ),
				};

				var call = device
					? App.request( 'PATCH', '/scanners/' + device.id, payload )
					: App.request( 'POST', '/scanners', payload );

				return call.then( function ( answer ) {
					App.toast( App.t( 'panel.scanners.saved' ) );

					if ( answer.pairing_code ) {
						Scanners.showCode( answer.pairing_code );
					}

					Scanners.render( App );
				} );
			},
		} );
	};

	Scanners.recode = function ( device ) {
		var App = Scanners.App;

		App.modal( {
			title: App.t( 'panel.scanners.recode' ),
			submitLabel: App.t( 'panel.scanners.recodeConfirm' ),
			body: '<p>' + esc( App.t( 'panel.scanners.recodeBody', { name: device.name } ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/scanners/' + device.id + '/code', {} )
					.then( function ( answer ) {
						Scanners.showCode( answer.pairing_code );
						Scanners.render( App );
					} );
			},
		} );
	};

	/**
	 * The code, once.
	 *
	 * Shown in a dialogue of its own rather than in a toast: somebody has to read it aloud across a
	 * foyer or type it into a phone somebody else is holding, and a message that disappears after
	 * four seconds is a message that has to be asked for again.
	 */
	Scanners.showCode = function ( code ) {
		var App = Scanners.App;

		App.modal( {
			title: App.t( 'panel.scanners.codeTitle' ),
			cancelLabel: null,
			doneLabel: App.t( 'panel.common.done' ),
			body: '<p class="hint">' + esc( App.t( 'panel.scanners.codeBody' ) ) + '</p>' +
				'<p class="credentials">' + esc( code ) + '</p>' +
				'<p class="hint">' + esc( App.t( 'panel.scanners.codeWhere' ) ) + '</p>',
		} );
	};

	Scanners.remove = function ( device ) {
		var App = Scanners.App;

		App.modal( {
			title: App.t( 'panel.scanners.remove' ),
			submitLabel: App.t( 'panel.common.remove' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.scanners.removeBody', { name: device.name } ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/scanners/' + device.id )
					.then( function () {
						App.toast( App.t( 'panel.scanners.removed' ) );
						Scanners.render( App );
					} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function button( attribute, value, label, iconName ) {
		return '<button class="btn btn--sm" data-' + attribute + '="' + esc( value ) + '">' +
			icon( iconName, { size: 14 } ) + esc( label ) + '</button>';
	}

	function badge( text, tone ) {
		return '<span class="badge badge--' + tone + '">' + esc( text ) + '</span>';
	}

	function on( selector, handler ) {
		var element = document.querySelector( selector );

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

	global.SeatmapScanners = Scanners;
}( typeof window !== 'undefined' ? window : globalThis ) );
