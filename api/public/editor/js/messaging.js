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
		announcements: [],
		events: [],
		// The last "who this reaches" answer, so the confirmation can say how many.
		reach: 0,
		mayAnnounce: false,

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
			// Both are allowed to be refused: writing to buyers and configuring the platform are
			// different permissions, and somebody may hold one without the other.
			App.request( 'GET', '/messaging/announcements' ).catch( function () { return null; } ),
			App.request( 'GET', '/events?per_page=100' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( results ) {
			Messaging.kinds = results[ 0 ].kinds || [];
			Messaging.channels = results[ 0 ].channels || [];
			Messaging.locales = results[ 0 ].locales || [];
			Messaging.log = results[ 1 ].data || [];
			Messaging.mayAnnounce = !! results[ 2 ];
			Messaging.announcements = results[ 2 ] ? ( results[ 2 ].data || [] ) : [];
			Messaging.events = results[ 3 ].data || [];
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
				Messaging.announcementsMarkup() +
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

	/**
	 * Announcements: what has been said to buyers, and a button to say something.
	 *
	 * Absent entirely for somebody who may not send: a screen that shows a control and refuses it
	 * has told them about a permission they do not have and wasted the trip.
	 */
	Messaging.announcementsMarkup = function () {
		var App = Messaging.App;

		if ( ! Messaging.mayAnnounce ) {
			return '';
		}

		return '<div class="page-head page-head--inline spaced">' +
				'<div class="page-head__text">' +
					'<h3 class="subhead spaced-none">' + esc( App.t( 'messaging.announceHeading' ) ) + '</h3>' +
					'<p class="hint">' + esc( App.t( 'messaging.announceIntro' ) ) + '</p>' +
				'</div>' +
				'<div class="page-head__actions">' +
					'<button class="btn btn--primary" id="announce-new">' + icon( 'mail', { size: 15 } ) +
						esc( App.t( 'messaging.announceNew' ) ) + '</button>' +
				'</div>' +
			'</div>' +
			( Messaging.announcements.length
				? App.table(
					[
						App.t( 'messaging.announceSubject' ),
						App.t( 'messaging.announceAudience' ),
						App.t( 'panel.common.status' ),
						App.t( 'messaging.announceWhen' ),
					],
					Messaging.announcements.map( function ( note ) {
						return '<tr>' +
							'<td class="table__primary">' +
								esc( note.subject || note.body.slice( 0, 60 ) ) + '</td>' +
							'<td>' + esc( 'event' === note.audience
								? ( note.event || App.t( 'messaging.audienceEvent' ) )
								: App.t( 'messaging.audienceEveryone' ) ) + '</td>' +
							'<td>' + Messaging.announceStatus( note ) + '</td>' +
							'<td class="muted nowrap">' + esc( App.date( note.created_at ) ) + '</td>' +
						'</tr>';
					} ).join( '' )
				)
				: App.emptyState( 'mail', App.t( 'messaging.announceNone' ),
					esc( App.t( 'messaging.announceNoneHint' ) ) ) );
	};

	Messaging.announceStatus = function ( note ) {
		var App = Messaging.App;
		var label = {
			draft: 'messaging.announceStatusDraft',
			sending: 'messaging.announceStatusSending',
			sent: 'messaging.announceStatusSent',
		}[ note.status ] || 'messaging.announceStatusDraft';

		return '<span class="badge badge--' + ( 'sent' === note.status ? 'ok' : 'neutral' ) + '">' +
			esc( App.t( label ) ) + '</span>' +
			'<span class="muted on-own-line">' +
				esc( App.t( 'messaging.announceProgress', {
					sent: App.number( note.sent ),
					total: App.number( note.total ),
				} ) ) +
				( note.failed
					? ' · ' + esc( App.t( 'messaging.announceFailed', { count: App.number( note.failed ) } ) )
					: '' ) +
			'</span>';
	};

	/**
	 * Write one.
	 *
	 * The reach is counted before anything is sent, and again in the confirmation, because "send
	 * to everybody" is a sentence people say before they have thought about how many that is.
	 */
	Messaging.compose = function () {
		var App = Messaging.App;

		App.modal( {
			title: App.t( 'messaging.announceNew' ),
			submitLabel: App.t( 'messaging.announceSend' ),
			body:
				'<div class="stack">' +
					'<div class="field"><label class="field__label" for="a-event">' +
						esc( App.t( 'messaging.announceAudience' ) ) + '</label>' +
					'<select class="select" id="a-event" name="event_id">' +
						'<option value="">' + esc( App.t( 'messaging.audienceEveryone' ) ) + '</option>' +
						Messaging.events.map( function ( event ) {
							return '<option value="' + esc( event.id ) + '">' + esc( event.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<span class="field__hint">' + esc( App.t( 'messaging.announceOnlyPaid' ) ) + '</span></div>' +

					'<div class="field"><span class="field__label">' +
						esc( App.t( 'messaging.announceChannels' ) ) + '</span>' +
					'<div class="perms">' + Messaging.channels.map( function ( channel, index ) {
						return '<label class="perms__row">' +
							'<input type="checkbox" class="checkbox" data-announce-channel="' +
								esc( channel.key ) + '"' + ( 0 === index ? ' checked' : '' ) + '>' +
							'<span>' + esc( channel.name ) + '</span></label>';
					} ).join( '' ) + '</div></div>' +

					'<p class="hint" id="a-reach">&nbsp;</p>' +

					'<div class="field"><label class="field__label" for="a-subject">' +
						esc( App.t( 'messaging.announceSubject' ) ) + '</label>' +
					'<input class="input" id="a-subject" name="subject" maxlength="200">' +
					'<span class="field__hint">' + esc( App.t( 'messaging.announceSubjectHint' ) ) + '</span></div>' +

					'<div class="field"><label class="field__label" for="a-body">' +
						esc( App.t( 'messaging.announceBody' ) ) + '</label>' +
					'<textarea class="textarea" id="a-body" name="body" rows="6" maxlength="2000" required></textarea>' +
					'<span class="field__hint">' + esc( App.t( 'messaging.announceBodyHint' ) ) + '</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				var channels = Messaging.chosenChannels();

				if ( ! channels.length ) {
					App.toast( App.t( 'messaging.announceChannels' ), true );

					return Promise.reject( new Error( App.t( 'messaging.announceChannels' ) ) );
				}

				return Messaging.confirmThenSend( data, channels );
			},
		} );

		Messaging.bindReach();
	};

	/**
	 * Ask once more, with the number on it.
	 *
	 * This is the one action on the panel that reaches thousands of strangers and cannot be taken
	 * back — there is no unsending an SMS. The count is the part that matters: "send this?" is
	 * easy to click through, "this goes to 4,312 messages and cannot be unsent" is not.
	 *
	 * Backing out resolves truthy, which is how App.modal is told to leave the draft on screen
	 * rather than throwing away what somebody just wrote.
	 */
	Messaging.confirmThenSend = function ( data, channels ) {
		var App = Messaging.App;

		return new Promise( function ( resolve, reject ) {
			App.modal( {
				title: App.t( 'messaging.announceConfirm' ),
				body: '<p>' + esc( App.t( 'messaging.announceConfirmBody', {
					messages: App.number( Messaging.reach || 0 ),
				} ) ) + '</p>',
				submitLabel: App.t( 'messaging.announceSend' ),
				danger: true,
				onSubmit: function () {
					return App.request( 'POST', '/messaging/announcements', {
						event_id: data.get( 'event_id' ) || null,
						channels: channels,
						subject: data.get( 'subject' ) || null,
						body: data.get( 'body' ),
					} ).then( function () {
						App.toast( App.t( 'messaging.announceSent' ) );
						Messaging.render( App );
						resolve( false );
					} ).catch( function ( error ) {
						reject( error );

						throw error;
					} );
				},
				// Fires on a cancel and on a successful send alike; the first resolve wins.
				onClose: function () { resolve( true ); },
			} );
		} );
	};

	Messaging.chosenChannels = function () {
		var chosen = [];

		each( '[data-announce-channel]', function ( box ) {
			box.checked && chosen.push( box.dataset.announceChannel );
		} );

		return chosen;
	};

	/** Keep "who this reaches" honest while the audience and channels are being chosen. */
	Messaging.bindReach = function () {
		var App = Messaging.App;
		var host = document.getElementById( 'a-reach' );
		var event = document.getElementById( 'a-event' );

		var count = function () {
			var channels = Messaging.chosenChannels();

			if ( ! host || ! channels.length ) {
				Messaging.reach = 0;
				host && ( host.textContent = App.t( 'messaging.announceReachNobody' ) );

				return;
			}

			var query = channels.map( function ( key ) {
				return 'channels[]=' + encodeURIComponent( key );
			} ).join( '&' ) + ( event.value ? '&event_id=' + encodeURIComponent( event.value ) : '' );

			App.request( 'GET', '/messaging/announcements/audience?' + query )
				.then( function ( reach ) {
					Messaging.reach = reach.messages || 0;
					host.textContent = reach.messages
						? App.t( 'messaging.announceReach', {
							people: App.number( reach.people ),
							messages: App.number( reach.messages ),
						} )
						: App.t( 'messaging.announceReachNobody' );
				} )
				.catch( function () { host.textContent = ''; } );
		};

		event.addEventListener( 'change', count );
		each( '[data-announce-channel]', function ( box ) {
			box.addEventListener( 'change', count );
		} );

		count();
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
			( entry.reason ? '<span class="muted on-own-line">' + esc( entry.reason ) + '</span>' : '' );
	};

	/**
	 * What the log calls a kind.
	 *
	 * Not every kind is one an organiser writes wording for — an announcement carries their own
	 * words, and a system notice is the platform talking to them — so those are not in the list
	 * the settings above are built from, and the log looks their names up separately.
	 */
	Messaging.kindName = function ( key ) {
		var found = Messaging.kinds.filter( function ( kind ) { return kind.key === key; } )[ 0 ];

		return found ? found.name : Messaging.App.t( 'messaging.logKinds.' + key.replace( /\./g, '_' ) );
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

		var announce = document.getElementById( 'announce-new' );

		if ( announce ) {
			announce.addEventListener( 'click', function () { Messaging.compose(); } );
		}

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
