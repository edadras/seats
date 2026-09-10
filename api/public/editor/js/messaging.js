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
		// Saved audiences. Refused for anybody who may not write to buyers, like the
		// announcements beside them, so an empty list and a forbidden one look the same here.
		segments: [],
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
			App.request( 'GET', '/segments' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( results ) {
			Messaging.kinds = results[ 0 ].kinds || [];
			Messaging.channels = results[ 0 ].channels || [];
			Messaging.locales = results[ 0 ].locales || [];
			Messaging.log = results[ 1 ].data || [];
			Messaging.mayAnnounce = !! results[ 2 ];
			Messaging.announcements = results[ 2 ] ? ( results[ 2 ].data || [] ) : [];
			Messaging.events = results[ 3 ].data || [];
			Messaging.segments = results[ 4 ].data || [];
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
				Messaging.segmentsMarkup() +
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
							'<td>' + esc( ( function () {
								if ( 'segment' === note.audience ) {
									// A list deleted since is still an announcement that happened;
									// what it reached is written down as its deliveries.
									return note.segment || App.t( 'messaging.audienceSegmentGone' );
								}

								return 'event' === note.audience
									? ( note.event || App.t( 'messaging.audienceEvent' ) )
									: App.t( 'messaging.audienceEveryone' );
							}() ) ) + '</td>' +
							'<td>' + Messaging.announceStatus( note ) + '</td>' +
							'<td class="muted nowrap">' + esc( App.date( note.created_at ) ) + '</td>' +
						'</tr>';
					} ).join( '' )
				)
				: App.emptyState( 'mail', App.t( 'messaging.announceNone' ),
					esc( App.t( 'messaging.announceNoneHint' ) ) ) );
	};

	/**
	 * Saved audiences.
	 *
	 * "Everybody who came last season and has not booked this one" is the audience an organiser
	 * actually wants, and it is two clauses rather than one. Saved here so it can be asked again
	 * next season instead of rebuilt from memory each time.
	 *
	 * The list shows how many people each one reaches and never who they are: a segment is not a
	 * second customer directory, and a screen that listed its addresses would be a way to walk out
	 * of the building with an account's mailing list.
	 */
	Messaging.segmentsMarkup = function () {
		var App = Messaging.App;

		if ( ! Messaging.mayAnnounce ) {
			return '';
		}

		return '<div class="row spaced">' +
				'<div>' +
					'<h3 class="subhead spaced-none">' + esc( App.t( 'messaging.segmentsHeading' ) ) + '</h3>' +
					'<p class="hint">' + esc( App.t( 'messaging.segmentsIntro' ) ) + '</p>' +
				'</div>' +
				'<div class="page-head__actions">' +
					'<button class="btn" id="segment-new">' + icon( 'users', { size: 15 } ) +
						esc( App.t( 'messaging.segmentNew' ) ) + '</button>' +
				'</div>' +
			'</div>' +
			( Messaging.segments.length
				? App.table(
					[
						App.t( 'panel.common.name' ),
						App.t( 'messaging.segmentMeans' ),
						'',
					],
					Messaging.segments.map( function ( segment ) {
						return '<tr>' +
							'<td class="table__primary">' + esc( segment.name ) +
								( segment.description
									? '<span class="muted on-own-line">' + esc( segment.description ) + '</span>'
									: '' ) + '</td>' +
							'<td>' + esc( Messaging.segmentWords( segment ) ) + '</td>' +
							'<td class="table__actions">' +
								'<button class="icon-btn icon-btn--sm" data-segment-edit="' + esc( segment.id ) +
									'" aria-label="' + esc( App.t( 'panel.common.edit' ) ) + '" data-tip="' +
									esc( App.t( 'panel.common.edit' ) ) + '">' + icon( 'settings', { size: 14 } ) +
								'</button>' +
								'<button class="icon-btn icon-btn--sm" data-segment-delete="' + esc( segment.id ) +
									'" aria-label="' + esc( App.t( 'panel.common.delete' ) ) + '" data-tip="' +
									esc( App.t( 'panel.common.delete' ) ) + '">' + icon( 'trash', { size: 14 } ) +
								'</button>' +
							'</td>' +
						'</tr>';
					} ).join( '' )
				)
				: App.emptyState( 'users', App.t( 'messaging.segmentsNone' ),
					esc( App.t( 'messaging.segmentsNoneHint' ) ) ) );
	};

	/**
	 * What a saved audience means, in words.
	 *
	 * Built from the server's own reading of the rules rather than from the rules themselves: the
	 * server already turned event ids into names, and a second implementation of that in the
	 * browser is a second place for it to disagree.
	 */
	Messaging.segmentWords = function ( segment ) {
		var App = Messaging.App;
		var said = segment.explained || {};
		var parts = [];

		if ( said.bought_events ) {
			parts.push( App.t( 'messaging.segmentBought', { events: said.bought_events.join( ', ' ) } ) );
		}

		if ( said.not_bought_events ) {
			parts.push( App.t( 'messaging.segmentNotBought', { events: said.not_bought_events.join( ', ' ) } ) );
		}

		if ( said.categories ) {
			parts.push( App.t( 'messaging.segmentCategories', { list: said.categories.join( ', ' ) } ) );
		}

		if ( said.since ) {
			parts.push( App.t( 'messaging.segmentSince', { when: App.date( said.since, { dateStyle: 'medium' } ) } ) );
		}

		if ( said.until ) {
			parts.push( App.t( 'messaging.segmentUntil', { when: App.date( said.until, { dateStyle: 'medium' } ) } ) );
		}

		if ( said.min_orders ) {
			parts.push( App.t( 'messaging.segmentMinOrders', { count: App.number( said.min_orders ) } ) );
		}

		if ( said.min_spend ) {
			parts.push( App.t( 'messaging.segmentMinSpend', {
				amount: App.money( said.min_spend, said.currency || 'EUR' ),
			} ) );
		}

		if ( said.attended ) {
			parts.push( App.t( 'messaging.segmentAttended' ) );
		}

		return parts.length ? parts.join( ' · ' ) : App.t( 'messaging.segmentEverybody' );
	};

	/** The builder: the closed vocabulary, one control per clause. */
	Messaging.editSegment = function ( segment ) {
		var App = Messaging.App;
		var rules = ( segment && segment.rules ) || {};
		var categories = Messaging.categories();

		var picker = function ( id, chosen ) {
			return '<select class="select" id="' + id + '" multiple size="4">' +
				Messaging.events.map( function ( event ) {
					return '<option value="' + esc( event.id ) + '"' +
						( ( chosen || [] ).indexOf( event.id ) > -1 ? ' selected' : '' ) + '>' +
						esc( event.name ) + '</option>';
				} ).join( '' ) +
			'</select>';
		};

		App.modal( {
			title: segment ? segment.name : App.t( 'messaging.segmentNew' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<div class="field"><label class="field__label" for="s-name">' +
						esc( App.t( 'panel.common.name' ) ) + '</label>' +
					'<input class="input" id="s-name" maxlength="120" required value="' +
						esc( segment ? segment.name : '' ) + '"></div>' +

					'<div class="field"><label class="field__label" for="s-desc">' +
						esc( App.t( 'messaging.segmentDescription' ) ) + '</label>' +
					'<input class="input" id="s-desc" maxlength="300" value="' +
						esc( segment && segment.description ? segment.description : '' ) + '"></div>' +

					'<div class="field"><label class="field__label" for="s-bought">' +
						esc( App.t( 'messaging.segmentBoughtLabel' ) ) + '</label>' +
					picker( 's-bought', rules.bought_events ) + '</div>' +

					'<div class="field"><label class="field__label" for="s-not-bought">' +
						esc( App.t( 'messaging.segmentNotBoughtLabel' ) ) + '</label>' +
					picker( 's-not-bought', rules.not_bought_events ) +
					'<span class="field__hint">' + esc( App.t( 'messaging.segmentNotBoughtHint' ) ) +
					'</span></div>' +

					( categories.length
						? '<div class="field"><label class="field__label" for="s-categories">' +
							esc( App.t( 'messaging.segmentCategoriesLabel' ) ) + '</label>' +
						'<select class="select" id="s-categories" multiple size="3">' +
							categories.map( function ( category ) {
								return '<option value="' + esc( category ) + '"' +
									( ( rules.categories || [] ).indexOf( category ) > -1 ? ' selected' : '' ) +
									'>' + esc( category ) + '</option>';
							} ).join( '' ) +
						'</select></div>'
						: '' ) +

					'<div class="row">' +
						'<div class="field grow"><label class="field__label" for="s-since">' +
							esc( App.t( 'messaging.segmentSinceLabel' ) ) + '</label>' +
						'<input class="input" id="s-since" type="date" value="' +
							esc( rules.since ? String( rules.since ).slice( 0, 10 ) : '' ) + '"></div>' +
						'<div class="field grow"><label class="field__label" for="s-until">' +
							esc( App.t( 'messaging.segmentUntilLabel' ) ) + '</label>' +
						'<input class="input" id="s-until" type="date" value="' +
							esc( rules.until ? String( rules.until ).slice( 0, 10 ) : '' ) + '"></div>' +
					'</div>' +

					'<div class="row">' +
						'<div class="field grow"><label class="field__label" for="s-orders">' +
							esc( App.t( 'messaging.segmentMinOrdersLabel' ) ) + '</label>' +
						'<input class="input tnum" id="s-orders" type="number" min="1" max="1000" value="' +
							esc( rules.min_orders || '' ) + '"></div>' +
						'<div class="field grow"><label class="field__label" for="s-spend">' +
							esc( App.t( 'messaging.segmentMinSpendLabel' ) ) + '</label>' +
						'<input class="input tnum" id="s-spend" type="number" min="0" value="' +
							esc( rules.min_spend || '' ) + '"></div>' +
						'<div class="field grow"><label class="field__label" for="s-currency">' +
							esc( App.t( 'messaging.segmentCurrencyLabel' ) ) + '</label>' +
						'<input class="input" id="s-currency" maxlength="3" value="' +
							esc( rules.currency || '' ) + '"></div>' +
					'</div>' +

					'<label class="perms__row"><input type="checkbox" class="checkbox" id="s-attended"' +
						( rules.attended ? ' checked' : '' ) + '>' +
						'<span>' + esc( App.t( 'messaging.segmentAttendedLabel' ) ) + '</span></label>' +

					'<p class="hint" id="s-reach">&nbsp;</p>' +
				'</div>',
			onSubmit: function () {
				var payload = {
					name: String( document.getElementById( 's-name' ).value ).trim(),
					description: String( document.getElementById( 's-desc' ).value ).trim() || null,
					rules: Messaging.segmentRules(),
				};

				return App.request(
					segment ? 'PUT' : 'POST',
					segment ? '/segments/' + segment.id : '/segments',
					payload
				).then( function () {
					App.toast( App.t( 'messaging.segmentSaved' ) );
					Messaging.render( App );
				} );
			},
		} );

		Messaging.bindSegmentReach();
	};

	/** The clauses, read off the builder. Empty ones are left out rather than sent as nothing. */
	Messaging.segmentRules = function () {
		var chosen = function ( id ) {
			var field = document.getElementById( id );

			return field
				? Array.prototype.slice.call( field.selectedOptions ).map( function ( o ) { return o.value; } )
				: [];
		};

		var value = function ( id ) {
			var field = document.getElementById( id );

			return field ? String( field.value ).trim() : '';
		};

		var rules = {};

		if ( chosen( 's-bought' ).length ) { rules.bought_events = chosen( 's-bought' ); }
		if ( chosen( 's-not-bought' ).length ) { rules.not_bought_events = chosen( 's-not-bought' ); }
		if ( chosen( 's-categories' ).length ) { rules.categories = chosen( 's-categories' ); }
		if ( value( 's-since' ) ) { rules.since = value( 's-since' ); }
		if ( value( 's-until' ) ) { rules.until = value( 's-until' ); }
		if ( Number( value( 's-orders' ) ) > 1 ) { rules.min_orders = Number( value( 's-orders' ) ); }
		if ( Number( value( 's-spend' ) ) > 0 ) { rules.min_spend = Number( value( 's-spend' ) ); }
		if ( value( 's-currency' ) ) { rules.currency = value( 's-currency' ).toUpperCase(); }
		if ( document.getElementById( 's-attended' ).checked ) { rules.attended = true; }

		return rules;
	};

	/**
	 * "This reaches 412 people", kept honest while the clauses are being chosen.
	 *
	 * The count is the part that matters: a set of rules nobody can picture the size of is a set
	 * of rules somebody sends to by mistake.
	 */
	Messaging.bindSegmentReach = function () {
		var App = Messaging.App;
		var host = document.getElementById( 's-reach' );

		var count = function () {
			if ( ! host ) {
				return;
			}

			App.request( 'POST', '/segments/preview', { rules: Messaging.segmentRules() } )
				.then( function ( answer ) {
					host.textContent = App.t( 'messaging.segmentReach', {
						count: App.number( answer.people ),
					} );
				} )
				.catch( function () { host.textContent = ''; } );
		};

		[ 's-bought', 's-not-bought', 's-categories', 's-since', 's-until', 's-orders',
			's-spend', 's-currency', 's-attended' ].forEach( function ( id ) {
			var field = document.getElementById( id );

			field && field.addEventListener( 'change', count );
		} );

		count();
	};

	/** The categories this account actually uses. A list of every category there could be is noise. */
	Messaging.categories = function () {
		var seen = {};

		Messaging.events.forEach( function ( event ) {
			if ( event.category ) {
				seen[ event.category ] = true;
			}
		} );

		return Object.keys( seen ).sort();
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
					/*
					 * One control, three kinds of audience.
					 *
					 * A saved audience already says who it means, so it is offered *instead of* an
					 * event rather than beside one: "came last season and has not booked this one"
					 * narrowed to "bought this one" is the empty set, and a screen that let
					 * somebody build that would be a screen that sends nothing and says nothing.
					 */
					'<select class="select" id="a-event" name="audience">' +
						'<option value="">' + esc( App.t( 'messaging.audienceEveryone' ) ) + '</option>' +
						( Messaging.segments.length
							? '<optgroup label="' + esc( App.t( 'messaging.segmentsHeading' ) ) + '">' +
								Messaging.segments.map( function ( segment ) {
									return '<option value="seg:' + esc( segment.id ) + '">' +
										esc( segment.name ) + '</option>';
								} ).join( '' ) + '</optgroup>'
							: '' ) +
						'<optgroup label="' + esc( App.t( 'messaging.audienceOneEvent' ) ) + '">' +
							Messaging.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '">' + esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</optgroup>' +
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
		// Read before the confirmation opens: the first modal is gone by the time this is sent,
		// and with it the control the audience was chosen in.
		var audience = Messaging.chosenAudience();

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
						event_id: audience.event_id,
						segment_id: audience.segment_id,
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

	/**
	 * The audience control's value, as the two fields the API takes.
	 *
	 * A saved audience is marked with a prefix rather than by a second control, so there is exactly
	 * one place on the screen that says who this is going to.
	 */
	Messaging.chosenAudience = function () {
		var field = document.getElementById( 'a-event' );
		var value = field ? String( field.value ) : '';

		if ( 0 === value.indexOf( 'seg:' ) ) {
			return { event_id: null, segment_id: value.slice( 4 ) };
		}

		return { event_id: value || null, segment_id: null };
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

			var audience = Messaging.chosenAudience();
			var query = channels.map( function ( key ) {
				return 'channels[]=' + encodeURIComponent( key );
			} ).join( '&' ) +
				( audience.event_id ? '&event_id=' + encodeURIComponent( audience.event_id ) : '' ) +
				( audience.segment_id ? '&segment_id=' + encodeURIComponent( audience.segment_id ) : '' );

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

		var newSegment = document.getElementById( 'segment-new' );

		if ( newSegment ) {
			newSegment.addEventListener( 'click', function () { Messaging.editSegment( null ); } );
		}

		each( '[data-segment-edit]', function ( button ) {
			button.addEventListener( 'click', function () {
				Messaging.editSegment( Messaging.segments.filter( function ( segment ) {
					return segment.id === button.dataset.segmentEdit;
				} )[ 0 ] );
			} );
		} );

		each( '[data-segment-delete]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.modal( {
					title: App.t( 'messaging.segmentDelete' ),
					body: '<p>' + esc( App.t( 'messaging.segmentDeleteBody' ) ) + '</p>',
					submitLabel: App.t( 'panel.common.delete' ),
					danger: true,
					onSubmit: function () {
						return App.request( 'DELETE', '/segments/' + button.dataset.segmentDelete )
							.then( function () {
								App.toast( App.t( 'messaging.segmentDeleted' ) );
								Messaging.render( App );
							} );
					},
				} );
			} );
		} );

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
