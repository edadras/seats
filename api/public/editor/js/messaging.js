/**
 * What a buyer is told, on which channels, in which language — and whether it arrived.
 *
 * Two halves, deliberately on one screen. The wording is upstairs and the delivery log is
 * downstairs, because the question "what do we say" and the question "did they get it" are asked
 * by the same person, usually within a minute of each other and usually with somebody waiting.
 *
 * A tenant that has written nothing still sends real messages: the platform's own wording, in the
 * buyer's language. The editor says so rather than showing an empty box, because an empty box
 * looks like something broken.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Messaging = {
		App: null,
		kinds: [],
		channels: [],
		locales: [],
		log: [],
		filter: '',

		// What the editor is looking at.
		kind: null,
		channel: 'email',
		locale: 'en',
		draft: null,
	};

	Messaging.render = function ( App ) {
		Messaging.App = App;

		App.loading( App.t( 'messaging.title' ) );

		Promise.all( [
			App.request( 'GET', '/messaging' ),
			App.request( 'GET', '/messaging/log' ),
		] ).then( function ( results ) {
			Messaging.kinds = results[ 0 ].kinds || [];
			Messaging.channels = results[ 0 ].channels || [];
			Messaging.locales = results[ 0 ].locales || [];
			Messaging.log = results[ 1 ].data || [];
			Messaging.kind = Messaging.kind || ( Messaging.kinds[ 0 ] || {} ).key;
			Messaging.locale = global.SeatmapI18n.locale || 'en';
			Messaging.paint();
			Messaging.loadTemplate();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	Messaging.paint = function () {
		var App = Messaging.App;

		App.page( {
			title: App.t( 'messaging.title' ),
			description: App.t( 'messaging.subtitle' ),
			body:
				'<div class="theme-editor">' +
					'<div class="theme-editor__controls">' + Messaging.kindsMarkup() + '</div>' +
					'<div class="theme-editor__preview">' + Messaging.editorMarkup() + '</div>' +
				'</div>' +
				'<h3 class="subhead">' + esc( App.t( 'messaging.log' ) ) + '</h3>' +
				Messaging.logMarkup(),
		} );

		Messaging.bind();
	};

	/** Every kind of message, with the channels it goes out on. */
	Messaging.kindsMarkup = function () {
		var App = Messaging.App;

		return Messaging.kinds.map( function ( kind ) {
			return '<section class="theme-group' + ( kind.key === Messaging.kind ? ' is-open' : '' ) + '">' +
				'<button class="kind" data-kind="' + esc( kind.key ) + '">' +
					'<strong>' + esc( kind.name ) + '</strong>' +
					'<span class="muted">' + esc( kind.description ) + '</span>' +
				'</button>' +
				'<div class="kind__channels">' +
					'<span class="overline">' + esc( App.t( 'messaging.channelsOn' ) ) + '</span>' +
					Messaging.channels.map( function ( channel ) {
						var on = kind.channels.indexOf( channel.key ) !== -1;
						var locked = 'email' === channel.key && ! kind.optional;

						return '<label class="perms__row">' +
							'<input type="checkbox" class="checkbox" data-channel="' + esc( channel.key ) +
								'" data-for="' + esc( kind.key ) + '"' + ( on ? ' checked' : '' ) +
								( locked ? ' disabled' : '' ) + '>' +
							'<span>' + esc( channel.name ) +
								( locked ? ' <span class="muted">· ' + esc( App.t( 'messaging.required' ) ) +
									'</span>' : '' ) +
							'</span>' +
						'</label>';
					} ).join( '' ) +
					'<p class="field__hint">' + esc( App.t( 'messaging.channelHint' ) ) + '</p>' +
				'</div>' +
			'</section>';
		} ).join( '' );
	};

	Messaging.editorMarkup = function () {
		var App = Messaging.App;
		var draft = Messaging.draft;

		if ( ! draft ) {
			return '<p class="muted">' + esc( App.t( 'messaging.template' ) ) + '</p>';
		}

		var kind = Messaging.kinds.filter( function ( entry ) {
			return entry.key === Messaging.kind;
		} )[ 0 ] || { placeholders: [] };

		return '<section class="theme-group">' +
			'<div class="filters">' +
				'<select class="select" id="msg-channel">' +
					Messaging.channels.map( function ( channel ) {
						return '<option value="' + esc( channel.key ) + '"' +
							( channel.key === Messaging.channel ? ' selected' : '' ) + '>' +
							esc( channel.name ) + '</option>';
					} ).join( '' ) +
				'</select>' +
				'<select class="select" id="msg-locale">' +
					Messaging.locales.map( function ( entry ) {
						return '<option value="' + esc( entry.code ) + '"' +
							( entry.code === Messaging.locale ? ' selected' : '' ) + '>' +
							esc( entry.native ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +

			( draft.is_default
				? '<p class="muted">' + esc( App.t( 'messaging.usingDefault' ) ) + '</p>'
				: '' ) +

			( 'email' === Messaging.channel
				? '<div class="field"><label class="field__label" for="msg-subject">' +
					esc( App.t( 'messaging.subject' ) ) + '</label>' +
					'<input class="input" id="msg-subject" value="' + esc( draft.subject ) + '"></div>'
				: '' ) +

			'<div class="field"><label class="field__label" for="msg-body">' +
				esc( App.t( 'messaging.body' ) ) + '</label>' +
				'<textarea class="code" id="msg-body" rows="10">' + esc( draft.body ) + '</textarea>' +
				'<p class="field__hint">' + esc( App.t( 'messaging.placeholders', {
					list: ( kind.placeholders || [] ).map( function ( name ) {
						return '{' + name + '}';
					} ).join( ' ' ),
				} ) ) + '</p>' +
			'</div>' +

			'<div class="seat-bar is-open">' +
				'<button class="btn btn--primary btn--sm" id="msg-save">' +
					esc( App.t( 'messaging.save' ) ) + '</button>' +
				( draft.is_default
					? ''
					: '<button class="btn btn--sm" id="msg-reset">' +
						esc( App.t( 'messaging.reset' ) ) + '</button>' ) +
				'<input class="input" id="msg-test-to" placeholder="' +
					esc( App.t( 'messaging.testTo' ) ) + '" aria-label="' +
					esc( App.t( 'messaging.testTo' ) ) + '">' +
				'<button class="btn btn--sm" id="msg-test">' +
					icon( 'mail', { size: 14 } ) + esc( App.t( 'messaging.test' ) ) + '</button>' +
			'</div>' +
			'<p class="field__hint">' + esc( App.t( 'messaging.testHint' ) ) + '</p>' +
		'</section>';
	};

	Messaging.logMarkup = function () {
		var App = Messaging.App;

		if ( ! Messaging.log.length ) {
			return App.emptyState( 'mail', App.t( 'messaging.noLog' ), App.t( 'messaging.noLogHint' ) );
		}

		return '<div class="filters">' +
				'<select class="select" id="msg-filter">' +
					'<option value="">' + esc( App.t( 'messaging.filterAll' ) ) + '</option>' +
					[ 'sent', 'refused', 'unavailable', 'queued' ].map( function ( status ) {
						return '<option value="' + status + '"' +
							( status === Messaging.filter ? ' selected' : '' ) + '>' +
							esc( App.t( 'messaging.status.' + status ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
			App.table(
				[ App.t( 'messaging.when' ), App.t( 'messaging.recipient' ),
					App.t( 'messaging.template' ), App.t( 'messaging.status.sent' ),
					App.t( 'messaging.preview' ) ],
				Messaging.log.map( function ( entry ) {
					return '<tr><td class="tnum nowrap">' + esc( App.date( entry.created_at ) ) + '</td>' +
						'<td class="table__primary">' + esc( entry.recipient ) + '</td>' +
						'<td>' + esc( Messaging.kindName( entry.kind ) ) + ' · ' +
							esc( Messaging.channelName( entry.channel ) ) + '</td>' +
						'<td>' + Messaging.badge( entry ) + '</td>' +
						'<td class="muted">' + esc( ( entry.preview || '' ).slice( 0, 60 ) ) + '</td></tr>';
				} ).join( '' )
			);
	};

	Messaging.badge = function ( entry ) {
		var App = Messaging.App;
		var tone = { sent: 'ok', refused: 'danger', unavailable: 'warn', queued: 'neutral' }[ entry.status ];

		return '<span class="badge badge--' + ( tone || 'neutral' ) + '">' +
			esc( App.t( 'messaging.status.' + entry.status ) ) + '</span>' +
			( entry.reason ? '<span class="muted block">' + esc( entry.reason ) + '</span>' : '' );
	};

	Messaging.kindName = function ( key ) {
		var found = Messaging.kinds.filter( function ( kind ) { return kind.key === key; } )[ 0 ];

		return found ? found.name : key;
	};

	Messaging.channelName = function ( key ) {
		var found = Messaging.channels.filter( function ( channel ) { return channel.key === key; } )[ 0 ];

		return found ? found.name : key;
	};

	/* -------------------------------------------------------------------------- behaviour */

	Messaging.loadTemplate = function () {
		var App = Messaging.App;

		App.request( 'GET', '/messaging/' + Messaging.kind + '/' + Messaging.channel + '/' +
			Messaging.locale ).then( function ( template ) {
			Messaging.draft = template;
			Messaging.paint();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Messaging.bind = function () {
		var App = Messaging.App;

		each( '[data-kind]', function ( button ) {
			button.addEventListener( 'click', function () {
				Messaging.kind = button.dataset.kind;
				Messaging.loadTemplate();
			} );
		} );

		each( '[data-channel]', function ( box ) {
			box.addEventListener( 'change', function () {
				App.request( 'PUT', '/messaging/' + box.dataset.for + '/channels/' + box.dataset.channel, {
					enabled: box.checked,
				} ).then( function () {
					App.toast( App.t( 'messaging.saved' ) );
					Messaging.render( App );
				} ).catch( function ( error ) {
					box.checked = ! box.checked;
					App.toast( error.message, true );
				} );
			} );
		} );

		bind( 'msg-channel', 'change', function ( control ) {
			Messaging.channel = control.value;
			Messaging.loadTemplate();
		} );

		bind( 'msg-locale', 'change', function ( control ) {
			Messaging.locale = control.value;
			Messaging.loadTemplate();
		} );

		bind( 'msg-filter', 'change', function ( control ) {
			Messaging.filter = control.value;

			App.request( 'GET', '/messaging/log' + ( control.value ? '?status=' + control.value : '' ) )
				.then( function ( body ) {
					Messaging.log = body.data || [];
					Messaging.paint();
				} );
		} );

		bind( 'msg-save', 'click', function () {
			var subject = document.getElementById( 'msg-subject' );

			App.request( 'PUT', '/messaging/' + Messaging.kind + '/' + Messaging.channel + '/' +
				Messaging.locale, {
				subject: subject ? subject.value : null,
				body: document.getElementById( 'msg-body' ).value,
			} ).then( function () {
				App.toast( App.t( 'messaging.saved' ) );
				Messaging.loadTemplate();
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		bind( 'msg-reset', 'click', function () {
			App.request( 'DELETE', '/messaging/' + Messaging.kind + '/' + Messaging.channel + '/' +
				Messaging.locale ).then( function () {
				App.toast( App.t( 'messaging.resetDone' ) );
				Messaging.loadTemplate();
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		bind( 'msg-test', 'click', function () {
			var to = document.getElementById( 'msg-test-to' ).value.trim();

			if ( ! to ) {
				App.toast( App.t( 'messaging.testTo' ), true );

				return;
			}

			App.request( 'POST', '/messaging/test', {
				kind: Messaging.kind,
				channel: Messaging.channel,
				locale: Messaging.locale,
				to: to,
			} ).then( function () {
				App.toast( App.t( 'messaging.testSent' ) );
				Messaging.render( App );
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	/* --------------------------------------------------------------------------- helpers */

	function bind( id, event, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( event, function () { handler( element ); } );
		}
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapMessaging = Messaging;
}( window ) );
