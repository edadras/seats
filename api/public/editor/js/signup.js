/**
 * Setting yourself up, and proving the address is yours.
 *
 * One form makes four things — a person, an organiser, a subscription and a website — because four
 * forms would be four ways to end up half signed up, and the half without a subscription is the
 * one that produces a support call.
 *
 * Verification comes after, not before. Waiting for a code before anything can be looked at is how
 * a trial becomes a bounce; instead an unverified account can build everything and can put nothing
 * on the public internet, and the bar at the top says so and takes the code.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Signup = { App: null, plans: [] };

	Signup.render = function ( App ) {
		Signup.App = App;

		// `keep`: the plans are for the sign-up screen's own chrome and outlive a route change.
		App.request( 'GET', '/plans', null, { keep: true } ).then( function ( body ) {
			Signup.plans = body.data || [];
			Signup.paint();
		} ).catch( function () {
			Signup.plans = [];
			Signup.paint();
		} );
	};

	Signup.paint = function () {
		var App = Signup.App;

		App.root.innerHTML =
			'<div class="auth"><form class="auth__card auth__card--wide" id="signup">' +
				'<div class="auth__brand"><span class="sidebar__mark">' + icon( 'seat', { size: 16 } ) +
					'</span>Seatmap</div>' +
				'<h1 class="auth__title">' + esc( App.t( 'signup.title' ) ) + '</h1>' +
				'<p class="auth__sub">' + esc( App.t( 'signup.subtitle' ) ) + '</p>' +

				'<div class="field"><label class="field__label" for="s-org">' +
					esc( App.t( 'signup.organisation' ) ) + '</label>' +
					'<input class="input" id="s-org" name="organisation" required maxlength="120" ' +
					'autocomplete="organization"></div>' +

				'<div class="field"><label class="field__label" for="s-name">' +
					esc( App.t( 'signup.name' ) ) + '</label>' +
					'<input class="input" id="s-name" name="name" required maxlength="120" ' +
					'autocomplete="name"></div>' +

				'<div class="field"><label class="field__label" for="s-email">' +
					esc( App.t( 'signup.email' ) ) + '</label>' +
					'<input class="input" id="s-email" name="email" type="email" required ' +
					'autocomplete="username"></div>' +

				'<div class="field"><label class="field__label" for="s-password">' +
					esc( App.t( 'signup.password' ) ) + '</label>' +
					'<input class="input" id="s-password" name="password" type="password" required ' +
					'minlength="12" autocomplete="new-password">' +
					'<span class="field__hint">' + esc( App.t( 'signup.passwordHint' ) ) + '</span></div>' +

				( Signup.plans.length > 1 ? Signup.plansMarkup() : '' ) +

				'<div class="issue issue--error" id="signup-error" role="alert" hidden></div>' +
				'<button class="btn btn--primary btn--lg btn--block" type="submit">' +
					esc( App.t( 'signup.create' ) ) + '</button>' +
				'<p class="auth__foot"><button type="button" class="link" id="go-login">' +
					esc( App.t( 'signup.haveAccount' ) ) + '</button></p>' +
			'</form></div>';

		Signup.bind();
	};

	Signup.plansMarkup = function () {
		var App = Signup.App;

		return '<div class="field"><span class="field__label">' + esc( App.t( 'signup.plan' ) ) + '</span>' +
			'<div class="plans">' +
				Signup.plans.map( function ( plan, index ) {
					return '<label class="plan">' +
						'<input type="radio" name="plan" value="' + esc( plan.key ) + '"' +
							( 0 === index ? ' checked' : '' ) + '>' +
						'<span class="plan__name">' + esc( plan.name ) + '</span>' +
						'<span class="plan__price tnum">' + esc( Signup.price( plan ) ) + '</span>' +
						'<span class="plan__limits">' + esc( Signup.limits( plan ) ) + '</span>' +
					'</label>';
				} ).join( '' ) +
			'</div></div>';
	};

	Signup.price = function ( plan ) {
		var App = Signup.App;

		if ( ! plan.price_amount ) {
			return App.t( 'signup.free' );
		}

		return App.money( plan.price_amount, plan.currency ) + ' ' +
			App.t( 'year' === plan.interval ? 'signup.perYear' : 'signup.perMonth' );
	};

	Signup.limits = function ( plan ) {
		var App = Signup.App;
		var limits = plan.limits || {};

		return [ 'events', 'seats', 'sites' ].filter( function ( key ) {
			return undefined !== limits[ key ];
		} ).map( function ( key ) {
			return App.t( 'signup.limits.' + key, {
				count: null === limits[ key ]
					? App.t( 'signup.limits.unlimited' )
					: App.number( limits[ key ] ),
			} );
		} ).join( ' · ' );
	};

	Signup.bind = function () {
		var App = Signup.App;
		var form = document.getElementById( 'signup' );
		var submit = form.querySelector( 'button[type=submit]' );
		var problem = document.getElementById( 'signup-error' );

		document.getElementById( 's-org' ).focus();

		document.getElementById( 'go-login' )
			.addEventListener( 'click', function () { App.showLogin(); } );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( form );

			problem.hidden = true;
			submit.disabled = true;
			submit.textContent = App.t( 'signup.creating' );

			App.request( 'POST', '/signup', {
				organisation: data.get( 'organisation' ),
				name: data.get( 'name' ),
				email: data.get( 'email' ),
				password: data.get( 'password' ),
				plan: data.get( 'plan' ) || undefined,
				locale: global.SeatmapI18n.locale,
				timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
			} ).then( function ( response ) {
				App.token = response.token;
				App.profile = {
					email: String( data.get( 'email' ) || '' ),
					tenant: response.tenant ? response.tenant.name : '',
					role: response.role || 'owner',
					email_verified: false,
				};

				global.sessionStorage.setItem( 'seatmap_token', response.token );
				global.sessionStorage.setItem( 'seatmap_profile', JSON.stringify( App.profile ) );

				App.showWorkspace();
				App.toast( App.t( 'signup.welcome' ) );
			} ).catch( function ( error ) {
				problem.innerHTML = icon( 'alert', { size: 16 } ) + '<span>' + esc( error.message ) + '</span>';
				problem.hidden = false;
				submit.disabled = false;
				submit.textContent = App.t( 'signup.create' );
			} );
		} );
	};

	/* ------------------------------------------------------------------------- the banner */

	Signup.banner = function ( App ) {
		Signup.App = App;

		var profile = App.profile || {};

		if ( false !== profile.email_verified ) {
			return; // Verified, or an account that predates any of this.
		}

		var bar = document.createElement( 'div' );

		bar.className = 'verify-bar';
		bar.innerHTML =
			'<span class="verify-bar__text"><strong>' + esc( App.t( 'signup.verifyTitle' ) ) + '</strong> ' +
				esc( App.t( 'signup.verifyBody', { email: profile.email || '' } ) ) + '</span>' +
			'<input class="input input--code" id="verify-code" inputmode="numeric" maxlength="6" ' +
				'aria-label="' + esc( App.t( 'signup.code' ) ) + '">' +
			'<button class="btn btn--sm" id="verify-go">' + esc( App.t( 'signup.verify' ) ) + '</button>' +
			'<button class="btn btn--sm" id="verify-resend">' + esc( App.t( 'signup.resend' ) ) + '</button>';

		var main = document.getElementById( 'main' );

		main.parentNode.insertBefore( bar, main );

		document.getElementById( 'verify-go' ).addEventListener( 'click', function () {
			App.request( 'POST', '/signup/verify', {
				email: profile.email,
				code: document.getElementById( 'verify-code' ).value,
			} ).then( function () {
				App.profile.email_verified = true;
				global.sessionStorage.setItem( 'seatmap_profile', JSON.stringify( App.profile ) );
				bar.remove();
				App.toast( App.t( 'signup.verified' ) );
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		document.getElementById( 'verify-resend' ).addEventListener( 'click', function () {
			App.request( 'POST', '/signup/resend', { email: profile.email } ).then( function () {
				App.toast( App.t( 'signup.resent' ) );
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapSignup = Signup;
}( window ) );
