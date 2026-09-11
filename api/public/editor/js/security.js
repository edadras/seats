/**
 * A second step at the door of an account that can move money.
 *
 * Everything on this screen is about the person looking at it. There is no way to set up, inspect
 * or remove somebody else's second step — an administrator who could is an administrator who could
 * sign in as them, which is the thing this exists to prevent.
 *
 * The recovery codes are shown exactly once, at the moment they are made, and the screen says so
 * before it shows them. They are hashed the instant they leave.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Security = { App: null, state: null, sso: null };

	Security.render = function ( App ) {
		Security.App = App;
		App.loading( App.t( 'panel.security.title' ) );

		/*
		 * The account's own provider is asked for only by somebody who may change it.
		 *
		 * Everything else on this screen is about the person looking at it; this one card is about
		 * the whole account, so it is fetched and shown under the permission that owns it rather
		 * than rendered greyed out for everybody else.
		 */
		var asks = [ App.request( 'GET', '/auth/two-factor' ) ];

		if ( App.may( 'account.manage' ) ) {
			asks.push( App.request( 'GET', '/sso' ) );
		}

		Promise.all( asks )
			.then( function ( answers ) {
				Security.state = answers[ 0 ];
				Security.sso = answers[ 1 ] || null;
				Security.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Security.paint = function () {
		var App = Security.App;
		var state = Security.state;

		App.page( {
			title: App.t( 'panel.security.title' ),
			description: esc( App.t( 'panel.security.description' ) ),
			body:
				'<div class="card card--pad">' +
					'<div class="stat-strip">' +
						tile( App.t( 'panel.security.status' ), App.t( state.enabled
							? 'panel.security.on'
							: 'panel.security.off' ) ) +
						tile( App.t( 'panel.security.codesLeft' ),
							App.number( state.recovery_codes_left || 0 ) ) +
					'</div>' +
					'<p class="hint spaced">' + esc( App.t( state.enabled
						? 'panel.security.onHint'
						: 'panel.security.offHint' ) ) + '</p>' +
					'<p class="spaced">' +
						( state.enabled
							? '<button class="btn" id="sec-codes">' +
								esc( App.t( 'panel.security.newCodes' ) ) + '</button>' +
								( state.required_by_account
									? ''
									: ' <button class="btn btn--danger" id="sec-off">' +
										esc( App.t( 'panel.security.turnOff' ) ) + '</button>' )
							: '<button class="btn btn--primary" id="sec-on">' +
								icon( 'lock', { size: 15 } ) +
								esc( App.t( 'panel.security.turnOn' ) ) + '</button>' ) +
					'</p>' +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'panel.security.accountTitle' ) ) + '</h3>' +
				'<div class="card card--pad">' +
					'<label class="switch switch--row">' +
						'<input type="checkbox" id="sec-require"' +
							( state.required_by_account ? ' checked' : '' ) + '>' +
						'<span class="switch__track"><span class="switch__thumb"></span></span>' +
						'<span>' + esc( App.t( 'panel.security.requireAll' ) ) + '</span>' +
					'</label>' +
					'<p class="hint spaced">' + esc( App.t( 'panel.security.requireHint' ) ) + '</p>' +
				'</div>' +

				Security.ssoMarkup( App ),
		} );

		Security.bindSso();

		bind( 'sec-on', function () { Security.begin(); } );
		bind( 'sec-off', function () { Security.turnOff(); } );
		bind( 'sec-codes', function () { Security.newCodes(); } );

		var toggle = document.getElementById( 'sec-require' );

		if ( toggle ) {
			toggle.addEventListener( 'change', function () {
				App.request( 'POST', '/account/two-factor-requirement', { required: toggle.checked } )
					.then( function ( result ) {
						Security.state.required_by_account = result.required_by_account;
						App.toast( App.t( result.required_by_account
							? 'panel.security.nowRequired'
							: 'panel.security.notRequired' ) );
						Security.paint();
					} )
					.catch( function ( error ) {
						toggle.checked = ! toggle.checked;
						App.toast( error.message, true );
					} );
			} );
		}
	};

	/**
	 * Where this account's people really sign in.
	 *
	 * The two addresses are the point of the card as much as the form is: one goes into the
	 * provider's own configuration and the other goes to the staff, and an organiser copying either
	 * out of a help page gets it wrong once in five.
	 */
	Security.ssoMarkup = function ( App ) {
		var sso = Security.sso;

		if ( ! sso ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'panel.sso.title' ) ) + '</h3>' +
			'<div class="card card--pad">' +
			'<p>' + esc( App.t( 'panel.sso.description' ) ) + '</p>' +

			( sso.configured
				? '<div class="stat-strip spaced">' +
					tile( App.t( 'panel.sso.provider' ), sso.label ) +
					tile( App.t( 'panel.sso.passwords' ), App.t( sso.required
						? 'panel.sso.passwordsOff'
						: 'panel.sso.passwordsOn' ) ) +
				'</div>'
				: '' ) +

			'<div class="field"><label class="field__label" for="sso-label">' +
			esc( App.t( 'panel.sso.label' ) ) + '</label>' +
			'<input class="input" id="sso-label" maxlength="80" placeholder="' +
			esc( App.t( 'panel.sso.labelPlaceholder' ) ) + '" value="' +
			esc( sso.label || '' ) + '"></div>' +

			'<div class="field"><label class="field__label" for="sso-issuer">' +
			esc( App.t( 'panel.sso.issuer' ) ) + '</label>' +
			'<input class="input" id="sso-issuer" type="url" placeholder="https://login.example.org" value="' +
			esc( sso.issuer || '' ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.sso.issuerHint' ) ) + '</span></div>' +

			'<div class="field-duo">' +
			'<div class="field"><label class="field__label" for="sso-client">' +
			esc( App.t( 'panel.sso.clientId' ) ) + '</label>' +
			'<input class="input" id="sso-client" value="' + esc( sso.client_id || '' ) + '"></div>' +
			'<div class="field"><label class="field__label" for="sso-secret">' +
			esc( App.t( 'panel.sso.clientSecret' ) ) + '</label>' +
			'<input class="input" id="sso-secret" type="password" autocomplete="new-password" placeholder="' +
			esc( App.t( sso.has_secret ? 'panel.sso.secretKept' : 'panel.sso.secretNeeded' ) ) + '">' +
			'</div>' +
			'</div>' +

			// Off until somebody has actually signed in through it. An account that switches this
			// on before it has tried is an account that has locked itself out.
			'<label class="perms__row"><input type="checkbox" class="checkbox" id="sso-required"' +
			( sso.required ? ' checked' : '' ) + '>' +
			'<span>' + esc( App.t( 'panel.sso.requireIt' ) ) + '</span></label>' +
			'<p class="field__hint">' + esc( App.t( 'panel.sso.requireHint' ) ) + '</p>' +

			'<p class="spaced"><button class="btn btn--primary" id="sso-save">' +
			esc( App.t( 'panel.sso.save' ) ) + '</button>' +
			( sso.configured
				? ' <button class="btn btn--danger" id="sso-remove">' +
					esc( App.t( 'panel.sso.remove' ) ) + '</button>'
				: '' ) + '</p>' +

			'<div class="field spaced"><label class="field__label" for="sso-redirect">' +
			esc( App.t( 'panel.sso.redirectUri' ) ) + '</label>' +
			'<input class="input" id="sso-redirect" readonly value="' + esc( sso.redirect_uri ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.sso.redirectHint' ) ) + '</span></div>' +

			'<div class="field"><label class="field__label" for="sso-link">' +
			esc( App.t( 'panel.sso.signInUrl' ) ) + '</label>' +
			'<input class="input" id="sso-link" readonly value="' + esc( sso.sign_in_url ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.sso.signInHint' ) ) + '</span></div>' +
			'</div>';
	};

	Security.bindSso = function () {
		var App = Security.App;

		bind( 'sso-save', function () {
			var secret = document.getElementById( 'sso-secret' ).value;
			var payload = {
				label: document.getElementById( 'sso-label' ).value,
				issuer: document.getElementById( 'sso-issuer' ).value,
				client_id: document.getElementById( 'sso-client' ).value,
				required: document.getElementById( 'sso-required' ).checked,
			};

			// An empty box means "keep the one you have", not "replace it with nothing": a secret
			// that had to be retyped to change a label is a secret that ends up in somebody's notes.
			if ( secret ) {
				payload.client_secret = secret;
			}

			App.request( 'PUT', '/sso', payload )
				.then( function ( sso ) {
					Security.sso = sso;
					App.toast( App.t( 'panel.sso.saved' ) );
					Security.paint();
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		bind( 'sso-remove', function () {
			App.modal( {
				title: App.t( 'panel.sso.removeTitle' ),
				submitLabel: App.t( 'panel.sso.remove' ),
				danger: true,
				body: '<p>' + esc( App.t( 'panel.sso.removeBody' ) ) + '</p>',
				onSubmit: function () {
					return App.request( 'DELETE', '/sso', {} ).then( function ( sso ) {
						Security.sso = sso;
						App.toast( App.t( 'panel.sso.removed' ) );
						Security.paint();
					} );
				},
			} );
		} );
	};

	/** A QR code to point a phone at, and the code it produces typed back. */
	Security.begin = function () {
		var App = Security.App;

		App.request( 'POST', '/auth/two-factor', {} )
			.then( function ( started ) {
				App.modal( {
					title: App.t( 'panel.security.setUpTitle' ),
					submitLabel: App.t( 'panel.security.confirm' ),
					body:
						'<div class="stack">' +
							'<p class="hint">' + esc( App.t( 'panel.security.setUpBody' ) ) + '</p>' +
							'<p><img class="tfa-qr" alt="" width="220" height="220" src="' +
								esc( started.qr ) + '"></p>' +
							// The secret in text as well as in the picture: a phone that cannot
							// scan is an ordinary phone, and typing it is the way through.
							'<p class="hint">' + esc( App.t( 'panel.security.orType' ) ) +
								' <code>' + esc( started.secret ) + '</code></p>' +
							'<div class="field"><label class="field__label" for="sec-code">' +
								esc( App.t( 'panel.security.code' ) ) + '</label>' +
								'<input class="input input--code" id="sec-code" inputmode="numeric" ' +
									'autocomplete="one-time-code" required></div>' +
						'</div>',
					onSubmit: function () {
						return App.request( 'POST', '/auth/two-factor/confirm', {
							code: document.getElementById( 'sec-code' ).value.trim(),
						} ).then( function ( result ) {
							Security.showCodes( result.recovery_codes );
							Security.render( App );
						} );
					},
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * The recovery codes, shown once.
	 *
	 * Not a toast and not a line on a page somebody scrolls past: a dialogue that has to be
	 * dismissed, because after this they are gone.
	 */
	Security.showCodes = function ( codes ) {
		var App = Security.App;

		App.modal( {
			title: App.t( 'panel.security.codesTitle' ),
			doneLabel: App.t( 'panel.security.codesKept' ),
			body:
				'<p>' + esc( App.t( 'panel.security.codesBody' ) ) + '</p>' +
				'<ul class="codes">' + codes.map( function ( code ) {
					return '<li><code>' + esc( code ) + '</code></li>';
				} ).join( '' ) + '</ul>',
		} );
	};

	Security.newCodes = function () {
		var App = Security.App;

		App.modal( {
			title: App.t( 'panel.security.newCodes' ),
			submitLabel: App.t( 'panel.security.newCodes' ),
			body:
				'<div class="stack">' +
					'<p class="hint">' + esc( App.t( 'panel.security.newCodesBody' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="sec-code2">' +
						esc( App.t( 'panel.security.code' ) ) + '</label>' +
						'<input class="input input--code" id="sec-code2" required></div>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/auth/two-factor/recovery-codes', {
					code: document.getElementById( 'sec-code2' ).value.trim(),
				} ).then( function ( result ) {
					Security.showCodes( result.recovery_codes );
					Security.render( App );
				} );
			},
		} );
	};

	Security.turnOff = function () {
		var App = Security.App;

		App.modal( {
			title: App.t( 'panel.security.turnOff' ),
			submitLabel: App.t( 'panel.security.turnOff' ),
			body:
				'<div class="stack">' +
					'<p>' + esc( App.t( 'panel.security.turnOffBody' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="sec-code3">' +
						esc( App.t( 'panel.security.code' ) ) + '</label>' +
						'<input class="input input--code" id="sec-code3" required></div>' +
					'<div class="field"><label class="field__label" for="sec-pass">' +
						esc( App.t( 'panel.security.password' ) ) + '</label>' +
						'<input class="input" id="sec-pass" type="password" ' +
							'autocomplete="current-password" required></div>' +
				'</div>',
			onSubmit: function () {
				return App.request( 'DELETE', '/auth/two-factor', {
					code: document.getElementById( 'sec-code3' ).value.trim(),
					password: document.getElementById( 'sec-pass' ).value,
				} ).then( function () {
					App.toast( App.t( 'panel.security.turnedOff' ) );
					Security.render( App );
				} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function tile( label, value ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value">' + esc( value ) + '</span>' +
		'</div>';
	}

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapSecurity = Security;
}( typeof window !== 'undefined' ? window : globalThis ) );
