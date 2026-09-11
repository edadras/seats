/**
 * Webhooks: where an organiser's own systems are told what happened here.
 *
 * This sits under the API keys on the Connections screen because it is the second half of the same
 * job — a key lets somebody else's shop ask us things, a webhook means they do not have to.
 *
 * Three things on this screen were the whole reason for building it, and none of them existed
 * before: whether an endpoint is working, what was actually sent, and a way to send it again.
 * An integration that fails silently is an integration nobody can debug from either end.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Hooks = { App: null, data: { data: [], events: [] } };

	/** Fetch, so the Connections screen can paint us with everything else it paints. */
	Hooks.load = function ( App ) {
		Hooks.App = App;

		return App.request( 'GET', '/webhooks' )
			.then( function ( answer ) {
				Hooks.data = answer;

				return answer;
			} )
			// A panel whose key list is fine should still render when this one 403s or fails: the
			// two are separate screens that happen to share a page.
			.catch( function () { return { data: [], events: [] }; } );
	};

	Hooks.markup = function ( App, payload ) {
		var hooks = ( payload || {} ).data || [];

		var rows = hooks.map( function ( hook ) {
			return '<tr><td class="table__primary">' + esc( hook.name ) +
				'<span class="muted on-own-line">' + esc( hook.url ) + '</span></td>' +
				'<td>' + esc( App.t( 'panel.webhooks.countEvents', {
					count: App.number( ( hook.event_types || [] ).length ),
				} ) ) + '</td>' +
				'<td>' + Hooks.state( App, hook ) + '</td>' +
				'<td class="muted">' + ( hook.last_delivered_at
					? esc( App.date( hook.last_delivered_at ) )
					: esc( App.t( 'panel.common.never' ) ) ) +
				( hook.last_error
					? '<span class="muted on-own-line">' + esc( hook.last_error ) + '</span>'
					: '' ) + '</td>' +
				'<td class="table__actions">' +
				button( 'hook-log', hook.id, App.t( 'panel.webhooks.log' ), 'history' ) +
				button( 'hook-test', hook.id, App.t( 'panel.webhooks.test' ), 'plug' ) +
				button( 'hook-secret', hook.id, App.t( 'panel.webhooks.rotate' ), 'key' ) +
				button( 'hook-edit', hook.id, App.t( 'panel.common.edit' ), 'settings' ) +
				button( 'hook-delete', hook.id, App.t( 'panel.common.remove' ), 'trash' ) +
				'</td></tr>';
		} ).join( '' );

		return '<div class="card card--pad" id="webhooks">' +
			'<div class="row row--between">' +
			'<div><h2 class="card__title">' + esc( App.t( 'panel.webhooks.title' ) ) + '</h2>' +
			'<p class="subhead">' + esc( App.t( 'panel.webhooks.description' ) ) + '</p></div>' +
			'<button class="btn btn--primary" id="hook-add">' + icon( 'plus', { size: 15 } ) +
			esc( App.t( 'panel.webhooks.add' ) ) + '</button></div>' +
			( rows
				? '<div class="table-wrap spaced"><table class="table"><thead><tr>' +
					'<th>' + esc( App.t( 'panel.webhooks.endpoint' ) ) + '</th>' +
					'<th>' + esc( App.t( 'panel.webhooks.events' ) ) + '</th>' +
					'<th>' + esc( App.t( 'panel.common.status' ) ) + '</th>' +
					'<th>' + esc( App.t( 'panel.webhooks.lastDelivery' ) ) + '</th>' +
					'<th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="empty spaced"><span class="empty__icon">' +
					icon( 'plug', { size: 22 } ) + '</span>' +
					'<p class="empty__title">' + esc( App.t( 'panel.webhooks.emptyTitle' ) ) + '</p>' +
					'<p class="empty__body">' + esc( App.t( 'panel.webhooks.emptyBody' ) ) + '</p></div>' ) +
			'</div>';
	};

	/**
	 * Working, paused, or switched off by us — and, where we switched it off, why.
	 *
	 * The reason is the point. An endpoint that simply stopped is a support ticket; one that says
	 * "twenty deliveries in a row failed" is a thing somebody can go and fix.
	 */
	Hooks.state = function ( App, hook ) {
		if ( 'active' === hook.status ) {
			return hook.consecutive_failures
				? '<span class="badge badge--warn">' +
					esc( App.t( 'panel.webhooks.failing', {
						count: App.number( hook.consecutive_failures ),
					} ) ) + '</span>'
				: '<span class="badge badge--ok">' + esc( App.t( 'panel.webhooks.on' ) ) + '</span>';
		}

		if ( 'paused' === hook.status ) {
			return '<span class="badge badge--neutral">' +
				esc( App.t( 'panel.webhooks.paused' ) ) + '</span>';
		}

		return '<span class="badge badge--danger">' + esc( hook.disabled_reason
			? App.t( 'panel.webhooks.reasons.' + hook.disabled_reason )
			: App.t( 'panel.webhooks.off' ) ) + '</span>';
	};

	Hooks.bind = function ( App, payload ) {
		Hooks.App = App;
		Hooks.data = payload || Hooks.data;

		var hooks = ( Hooks.data || {} ).data || [];
		var find = function ( id ) {
			return hooks.filter( function ( hook ) { return hook.id === id; } )[ 0 ] || null;
		};

		on( 'hook-add', function () { Hooks.form( App, null ); } );

		each( 'hook-edit', function ( id ) { Hooks.form( App, find( id ) ); } );
		each( 'hook-log', function ( id ) { Hooks.log( App, find( id ) ); } );
		each( 'hook-test', function ( id ) {
			App.request( 'POST', '/webhooks/' + id + '/test' )
				.then( function () {
					App.toast( App.t( 'panel.webhooks.tested' ) );
					// Straight into the log, because the answer to "did it arrive" is there and
					// nowhere else — and it is the next thing anybody presses anyway.
					Hooks.log( App, find( id ) );
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		each( 'hook-secret', function ( id ) { Hooks.rotate( App, find( id ) ); } );
		each( 'hook-delete', function ( id ) { Hooks.remove( App, find( id ) ); } );
	};

	/**
	 * An endpoint, and what it wants to hear about.
	 *
	 * The event list comes from the server rather than from a copy kept here: a picker offering
	 * something nobody dispatches looks, from the receiving end, exactly like a broken integration.
	 */
	Hooks.form = function ( App, hook ) {
		var groups = ( Hooks.data || {} ).events || [];
		var chosen = hook ? ( hook.event_types || [] ) : [];

		var picker = groups.map( function ( group ) {
			return '<fieldset class="perms">' +
				'<legend class="overline">' + esc( group.name ) + '</legend>' +
				group.types.map( function ( entry ) {
					return '<label class="switch switch--row perms__row">' +
						'<input type="checkbox" name="events" value="' + esc( entry.type ) + '"' +
						( chosen.indexOf( entry.type ) >= 0 ? ' checked' : '' ) + '>' +
						'<span class="switch__track"><span class="switch__thumb"></span></span>' +
						'<span>' + esc( entry.name ) +
						'<code class="muted on-own-line">' + esc( entry.type ) + '</code></span></label>';
				} ).join( '' ) +
				'</fieldset>';
		} ).join( '' );

		App.modal( {
			title: App.t( hook ? 'panel.webhooks.editTitle' : 'panel.webhooks.addTitle' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="field"><label class="field__label" for="hook-name">' +
				esc( App.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="hook-name" name="name" required maxlength="120" value="' +
				esc( hook ? hook.name : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="hook-url">' +
				esc( App.t( 'panel.webhooks.url' ) ) + '</label>' +
				'<input class="input" id="hook-url" name="url" required maxlength="500" ' +
				'placeholder="https://shop.example.com/hooks/seatmap" value="' +
				esc( hook ? hook.url : '' ) + '">' +
				'<p class="field__hint">' + esc( App.t( 'panel.webhooks.urlHint' ) ) + '</p></div>' +
				( hook
					? '<label class="switch switch--row"><input type="checkbox" name="active"' +
						( 'paused' === hook.status ? '' : ' checked' ) + '>' +
						'<span class="switch__track"><span class="switch__thumb"></span></span>' +
						'<span>' + esc( App.t( 'panel.webhooks.sending' ) ) + '</span></label>'
					: '' ) +
				'<p class="overline spaced">' + esc( App.t( 'panel.webhooks.events' ) ) + '</p>' +
				picker,
			onSubmit: function ( data, host ) {
				var types = Array.prototype.slice.call(
					host.querySelectorAll( 'input[name=events]:checked' )
				).map( function ( box ) { return box.value; } );

				var body = {
					name: data.get( 'name' ),
					url: data.get( 'url' ),
					event_types: types,
				};

				if ( hook ) {
					body.status = data.get( 'active' ) ? 'active' : 'paused';

					return App.request( 'PATCH', '/webhooks/' + hook.id, body )
						.then( function () {
							App.toast( App.t( 'panel.webhooks.saved' ) );
							App.renderConnections();
						} );
				}

				return App.request( 'POST', '/webhooks', body ).then( function ( made ) {
					Hooks.showSecret( App, made.signing_secret );
				} );
			},
		} );
	};

	/** The secret, in the only response that will ever carry it. */
	Hooks.showSecret = function ( App, secret ) {
		App.modal( {
			title: App.t( 'panel.webhooks.secretTitle' ),
			cancelLabel: null,
			doneLabel: App.t( 'panel.connections.saved' ),
			body: '<div class="credentials">' +
				'<p>' + esc( App.t( 'panel.webhooks.secretBody' ) ) + '</p>' +
				'<div class="credentials__row"><code>' + esc( secret ) + '</code>' +
				'<button type="button" class="btn btn--sm" data-copy-secret>' +
				icon( 'copy', { size: 14 } ) + esc( App.t( 'panel.common.copy' ) ) + '</button></div>' +
				'<p class="hint">' + esc( App.t( 'panel.webhooks.secretHint' ) ) + '</p></div>',
			onClose: function () { App.renderConnections(); },
		} );

		var host = document.querySelectorAll( '.modal' );
		var last = host[ host.length - 1 ];

		last.querySelector( '[data-copy-secret]' ).addEventListener( 'click', function () {
			if ( global.navigator && global.navigator.clipboard ) {
				global.navigator.clipboard.writeText( secret );
			}

			App.toast( App.t( 'panel.connections.secretCopied' ) );
		} );
	};

	Hooks.rotate = function ( App, hook ) {
		if ( ! hook ) {
			return;
		}

		App.modal( {
			title: App.t( 'panel.webhooks.rotateTitle' ),
			submitLabel: App.t( 'panel.webhooks.rotate' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.webhooks.rotateBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'POST', '/webhooks/' + hook.id + '/secret' )
					.then( function ( answer ) {
						Hooks.showSecret( App, answer.signing_secret );
					} );
			},
		} );
	};

	Hooks.remove = function ( App, hook ) {
		if ( ! hook ) {
			return;
		}

		App.modal( {
			title: App.t( 'panel.webhooks.deleteTitle' ),
			submitLabel: App.t( 'panel.common.remove' ),
			danger: true,
			body: '<p>' + App.t( 'panel.webhooks.deleteBody', { name: esc( hook.name ) } ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/webhooks/' + hook.id ).then( function () {
					App.toast( App.t( 'panel.webhooks.deleted' ) );
					App.renderConnections();
				} );
			},
		} );
	};

	/**
	 * What was sent, what came back, and a way to send it again.
	 *
	 * The body is shown as it left, so a developer can hold it against their own log rather than
	 * against a description of it. Replaying makes a new delivery instead of resetting this one:
	 * the record of what was tried is the whole reason the log exists.
	 */
	Hooks.log = function ( App, hook ) {
		if ( ! hook ) {
			return;
		}

		var modal = App.modal( {
			title: App.t( 'panel.webhooks.logTitle', { name: hook.name } ),
			cancelLabel: null,
			body: '<p class="muted" id="hook-log-body">' + esc( App.t( 'panel.common.loading' ) ) + '</p>',
		} );

		var paint = function () {
			App.request( 'GET', '/webhook-deliveries?endpoint_id=' + encodeURIComponent( hook.id ) )
				.then( function ( answer ) {
					var rows = ( answer.data || [] ).map( function ( delivery ) {
						return '<tr><td class="table__primary"><code>' + esc( delivery.event_type ) +
							'</code><span class="muted on-own-line">' +
							esc( App.date( delivery.created_at ) ) + '</span></td>' +
							'<td>' + Hooks.deliveryState( App, delivery ) + '</td>' +
							'<td class="tnum">' + esc( App.number( delivery.attempts ) ) + '</td>' +
							'<td class="muted">' + esc( delivery.response_body || '' ) + '</td>' +
							'<td class="table__actions">' +
							button( 'hook-replay', delivery.id, App.t( 'panel.webhooks.replay' ), 'redo' ) +
							'</td></tr>';
					} ).join( '' );

					modal.querySelector( '.modal__body' ).innerHTML = rows
						? '<div class="table-wrap"><table class="table"><thead><tr>' +
							'<th>' + esc( App.t( 'panel.webhooks.event' ) ) + '</th>' +
							'<th>' + esc( App.t( 'panel.common.status' ) ) + '</th>' +
							'<th>' + esc( App.t( 'panel.webhooks.attempts' ) ) + '</th>' +
							'<th>' + esc( App.t( 'panel.webhooks.answer' ) ) + '</th>' +
							'<th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
						: '<p class="muted">' + esc( App.t( 'panel.webhooks.nothingYet' ) ) + '</p>';

					modal.querySelectorAll( '[data-hook-replay]' ).forEach( function ( element ) {
						element.addEventListener( 'click', function () {
							App.request( 'POST', '/webhook-deliveries/' +
								element.dataset.hookReplay + '/replay' )
								.then( function () {
									App.toast( App.t( 'panel.webhooks.replayed' ) );
									paint();
								} )
								.catch( function ( error ) { App.toast( error.message, true ); } );
						} );
					} );
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		};

		paint();
	};

	Hooks.deliveryState = function ( App, delivery ) {
		var tone = 'delivered' === delivery.status
			? 'ok'
			: ( 'dead' === delivery.status ? 'danger' : 'warn' );

		return '<span class="badge badge--' + tone + '">' +
			esc( App.t( 'panel.webhooks.deliveries.' + delivery.status ) ) +
			( delivery.response_code ? ' · ' + delivery.response_code : '' ) + '</span>';
	};

	/* ------------------------------------------------------------------------------ helpers */

	function button( attribute, value, label, iconName ) {
		return '<button class="icon-btn icon-btn--sm" data-' + attribute + '="' + esc( value ) +
			'" data-tip="' + esc( label ) + '" data-tip-side="bottom-end" aria-label="' +
			esc( label ) + '">' + icon( iconName, { size: 15 } ) + '</button>';
	}

	function on( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function each( attribute, handler ) {
		document.querySelectorAll( '[data-' + attribute + ']' ).forEach( function ( element ) {
			element.addEventListener( 'click', function () {
				handler( element.getAttribute( 'data-' + attribute ) );
			} );
		} );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapWebhooks = Hooks;
}( typeof window !== 'undefined' ? window : globalThis ) );
