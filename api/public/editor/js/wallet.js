/**
 * Where an organiser puts their own wallet credentials.
 *
 * This platform cannot issue these passes on anybody's behalf and the screen says so. An Apple
 * Wallet pass is signed with a certificate issued to a named Apple Developer account; a Google
 * Wallet pass is signed with a service account belonging to a named issuer. A pass signed by us
 * would say we sold the ticket.
 *
 * Secrets are write-only. The screen shows whether each half is configured, never what it holds —
 * and a "test it" button, because a certificate that cannot sign is indistinguishable from one
 * that can until a buyer presses a button.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Wallet = { App: null, state: null };

	Wallet.render = function ( App ) {
		Wallet.App = App;
		App.loading( App.t( 'panel.wallet.title' ) );

		App.request( 'GET', '/wallet' )
			.then( function ( response ) {
				Wallet.state = response;
				Wallet.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Wallet.paint = function () {
		var App = Wallet.App;
		var state = Wallet.state;

		App.page( {
			title: App.t( 'panel.wallet.title' ),
			description: esc( App.t( 'panel.wallet.description' ) ),
			body:
				'<div class="cards">' +
					card( App, 'apple', state.apple, [
						text( 'w-apple-pass', App.t( 'panel.wallet.passTypeId' ),
							state.apple.pass_type_id || '', App.t( 'panel.wallet.passTypeIdHint' ) ),
						text( 'w-apple-team', App.t( 'panel.wallet.teamId' ),
							state.apple.team_id || '', '' ),
						secret( App, 'w-apple-cert', App.t( 'panel.wallet.certificate' ),
							state.apple.has_certificate, App.t( 'panel.wallet.certificateHint' ) ),
						secret( App, 'w-apple-key', App.t( 'panel.wallet.privateKey' ),
							state.apple.has_key, '' ),
						text( 'w-apple-pw', App.t( 'panel.wallet.keyPassword' ), '',
							App.t( 'panel.wallet.keyPasswordHint' ), 'password' ),
						secret( App, 'w-apple-wwdr', App.t( 'panel.wallet.wwdr' ),
							state.apple.has_wwdr, App.t( 'panel.wallet.wwdrHint' ) ),
					] ) +
					card( App, 'google', state.google, [
						text( 'w-google-issuer', App.t( 'panel.wallet.issuerId' ),
							state.google.issuer_id || '', App.t( 'panel.wallet.issuerIdHint' ) ),
						secret( App, 'w-google-account', App.t( 'panel.wallet.serviceAccount' ),
							state.google.has_service_account, App.t( 'panel.wallet.serviceAccountHint' ) ),
					] ) +
				'</div>' +

				'<div class="card card--pad spaced"><h3 class="subhead">' +
					esc( App.t( 'panel.wallet.look' ) ) + '</h3>' +
					'<div class="field-duo">' +
						text( 'w-bg', App.t( 'panel.wallet.background' ),
							state.look.background_colour || '', '' ) +
						text( 'w-fg', App.t( 'panel.wallet.textColour' ),
							state.look.text_colour || '', '' ) +
					'</div>' +
					text( 'w-logo', App.t( 'panel.wallet.logoText' ), state.look.logo_text || '',
						App.t( 'panel.wallet.logoTextHint' ) ) +
				'</div>' +

				'<div class="row spaced">' +
					'<button class="btn btn--primary" id="w-save">' +
						esc( App.t( 'panel.common.save' ) ) + '</button>' +
				'</div>',
		} );

		bind( 'w-save', Wallet.save );

		[ 'apple', 'google' ].forEach( function ( platform ) {
			bind( 'w-test-' + platform, function () { Wallet.test( platform ); } );
		} );
	};

	Wallet.save = function () {
		var App = Wallet.App;

		App.request( 'PUT', '/wallet', {
			apple_enabled: checked( 'w-apple-on' ),
			apple_pass_type_id: value( 'w-apple-pass' ),
			apple_team_id: value( 'w-apple-team' ),
			// Sent only when something was typed: an untouched box means "leave what is there",
			// or an organiser would have to paste a certificate again to change a colour.
			apple_certificate: typed( 'w-apple-cert' ),
			apple_key: typed( 'w-apple-key' ),
			apple_key_password: typed( 'w-apple-pw' ),
			apple_wwdr: typed( 'w-apple-wwdr' ),
			google_enabled: checked( 'w-google-on' ),
			google_issuer_id: value( 'w-google-issuer' ),
			google_service_account: typed( 'w-google-account' ),
			background_colour: value( 'w-bg' ),
			text_colour: value( 'w-fg' ),
			logo_text: value( 'w-logo' ),
		} )
			.then( function ( response ) {
				Wallet.state = response;
				App.toast( App.t( 'panel.wallet.saved' ) );
				Wallet.paint();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/** Sign something and see, because "enabled" is not the same as "works". */
	Wallet.test = function ( platform ) {
		var App = Wallet.App;

		App.request( 'POST', '/wallet/test', { platform: platform } )
			.then( function ( response ) {
				App.toast( response.ok
					? App.t( 'panel.wallet.testPassed' )
					: App.t( 'panel.wallet.reasons.' + response.reason ), ! response.ok );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function card( App, platform, state, fields ) {
		return '<div class="card card--pad wallet-card">' +
			'<div class="card__head">' +
				'<h3 class="subhead">' + esc( App.t( 'panel.wallet.' + platform ) ) + '</h3>' +
				( state.ready
					? '<span class="badge badge--ok">' + esc( App.t( 'panel.wallet.ready' ) ) + '</span>'
					: '<span class="badge badge--neutral">' +
						esc( App.t( 'panel.wallet.notReady' ) ) + '</span>' ) +
			'</div>' +
			'<label class="perms__row"><input type="checkbox" class="checkbox" id="w-' + platform +
				'-on"' + ( state.enabled ? ' checked' : '' ) + '>' +
				'<span>' + esc( App.t( 'panel.wallet.offerIt' ) ) + '</span></label>' +
			fields.join( '' ) +
			'<button class="btn btn--sm" id="w-test-' + platform + '">' +
				icon( 'check', { size: 14 } ) + esc( App.t( 'panel.wallet.test' ) ) + '</button>' +
		'</div>';
	}

	function text( id, label, current, hint, type ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' + esc( label ) +
			'</label><input class="input" id="' + id + '" type="' + ( type || 'text' ) +
			'" value="' + esc( current ) + '">' +
			( hint ? '<span class="field__hint">' + esc( hint ) + '</span>' : '' ) + '</div>';
	}

	/**
	 * A secret already stored is shown as stored and nothing more.
	 *
	 * A box pre-filled with asterisks is a box somebody saves over by accident, and a box
	 * pre-filled with the real thing is a leak.
	 */
	function secret( App, id, label, present, hint ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' + esc( label ) +
			( present
				? ' <span class="badge badge--ok">' + esc( App.t( 'panel.wallet.stored' ) ) + '</span>'
				: '' ) +
			'</label><textarea class="input input--pem" id="' + id + '" rows="3" placeholder="' +
			esc( present ? App.t( 'panel.wallet.leaveBlank' ) : '' ) + '"></textarea>' +
			( hint ? '<span class="field__hint">' + esc( hint ) + '</span>' : '' ) + '</div>';
	}

	function typed( id ) {
		var field = document.getElementById( id );
		var text = field ? field.value.trim() : '';

		return '' === text ? undefined : text;
	}

	function value( id ) {
		var field = document.getElementById( id );

		return field ? field.value.trim() : '';
	}

	function checked( id ) {
		var field = document.getElementById( id );

		return !! ( field && field.checked );
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

	global.SeatmapWallet = Wallet;
}( typeof window !== 'undefined' ? window : globalThis ) );
