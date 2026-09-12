/**
 * Modules: what this account can do beyond the basics (ADR-0004).
 *
 * One list, and one panel per module holding its settings and its health. There is deliberately no
 * "install" button: what code runs on a server is an operator's decision, made by deploying, and a
 * screen that changed it would be a remote-code-execution feature with a nice icon.
 *
 * A secret is shown as set or not set, never as itself. The field round-trips a mask, which the
 * server reads as "unchanged" — so saving a form does not wipe the key the form is describing.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Modules = { open: null, saving: false };

	Modules.render = function ( App ) {
		App.loading( App.t( 'modules.title' ) );

		App.request( 'GET', '/modules' )
			.then( function ( response ) {
				Modules.paint( App, response.data );
			} )
			.catch( function ( error ) {
				App.toast( error.message, 'error' );
			} );
	};

	Modules.paint = function ( App, modules ) {
		App.page( {
			title: App.t( 'modules.title' ),
			description: App.t( 'modules.subtitle' ),
			body: modules.length
				? '<div class="modules">' + modules.map( function ( module ) {
					return Modules.card( App, module );
				} ).join( '' ) + '</div>'
				: App.emptyState( 'plug', App.t( 'modules.installed' ), App.t( 'modules.noFailures' ) ),
		} );

		Modules.bind( App, modules );
	};

	Modules.card = function ( App, module ) {
		var on = module.enabled;

		return '<article class="module' + ( on ? ' is-on' : '' ) + '" data-module="' + esc( module.key ) + '">' +
			'<header class="module__head">' +
				'<div class="module__identity">' +
					'<h3 class="module__name">' + esc( module.name ) + '</h3>' +
					'<p class="module__meta">' +
						( module.first_party
							? '<span class="badge badge--soft">' + esc( App.t( 'modules.firstParty' ) ) + '</span>'
							: '<span class="badge badge--soft">' +
								esc( App.t( 'modules.byVendor', { vendor: module.vendor } ) ) + '</span>' ) +
						' <span class="muted">' + esc( module.version ) + '</span>' +
						module.extends.map( function ( point ) {
							return ' <span class="badge">' + esc( App.t( 'modules.extends.' + point ) ) + '</span>';
						} ).join( '' ) +
					'</p>' +
				'</div>' +

				'<label class="switch">' +
					'<input type="checkbox" id="' + toggleId( module ) + '"' +
						' data-toggle="' + esc( module.key ) + '"' + ( on ? ' checked' : '' ) + '>' +
					'<span class="switch__track"><span class="switch__thumb"></span></span>' +
					'<span class="switch__label">' +
						esc( App.t( on ? 'modules.enabled' : 'modules.disabled' ) ) +
					'</span>' +
				'</label>' +
			'</header>' +

			'<p class="module__description">' + esc( module.description ) + '</p>' +

			( module.disabled_reason
				? '<p class="notice notice--warn">' +
					esc( App.t( 'modules.autoDisabled', { reason: module.disabled_reason } ) ) + '</p>'
				: '' ) +

			( module.settings.length ? Modules.settings( App, module ) : '' ) +
		'</article>';
	};

	/*
	 * What a module needs to work, shown once it is working.
	 *
	 * A switched-off module used to print its whole settings form anyway — an API key and a
	 * merchant id for something that is not running, which reads as a job somebody has to do. The
	 * form follows the switch beside it; see App.applyWhen.
	 */
	Modules.settings = function ( App, module ) {
		return '<form class="module__settings" data-settings="' + esc( module.key ) + '"' +
			' data-when="' + toggleId( module ) + '">' +
			module.settings.map( function ( setting ) {
				return Modules.field( App, module, setting );
			} ).join( '' ) +
			'<div class="row row--end">' +
				'<button class="btn btn--primary btn--sm" type="submit">' +
					esc( App.t( 'modules.configure' ) ) +
				'</button>' +
			'</div>' +
		'</form>';
	};

	/** One id for a module's switch, used by the switch and by the form that waits on it. */
	function toggleId( module ) {
		return 'm-on-' + module.key.replace( /[^a-z0-9]/gi, '-' );
	}

	Modules.field = function ( App, module, setting ) {
		var value = module.values[ setting.key ];
		var id = 'm-' + module.key.replace( /[^a-z0-9]/gi, '-' ) + '-' + setting.key;
		var control;

		if ( 'boolean' === setting.type ) {
			control = '<input class="checkbox" id="' + id + '" type="checkbox" name="' + esc( setting.key ) + '"' +
				( value ? ' checked' : '' ) + '>';
		} else if ( 'select' === setting.type ) {
			control = '<select class="select" id="' + id + '" name="' + esc( setting.key ) + '">' +
				( setting.options || [] ).map( function ( option ) {
					return '<option value="' + esc( option ) + '"' +
						( option === value ? ' selected' : '' ) + '>' + esc( option ) + '</option>';
				} ).join( '' ) +
				'</select>';
		} else {
			// A secret is rendered as a text field holding the mask, not a password field holding
			// nothing: "set — replace to change" is the honest state, and a blank password box
			// invites somebody to think their key has been lost.
			control = '<input class="input" id="' + id + '" name="' + esc( setting.key ) + '"' +
				' type="' + ( 'integer' === setting.type ? 'number' : 'text' ) + '"' +
				' value="' + esc( null === value || undefined === value ? '' : value ) + '"' +
				( 'secret' === setting.type && ! value
					? ' placeholder="' + esc( App.t( 'modules.secretUnset' ) ) + '"'
					: '' ) +
				'>';
		}

		return '<div class="field">' +
			'<label class="field__label" for="' + id + '">' + esc( setting.label ) +
				( setting.required ? ' <span class="req">*</span>' : '' ) + '</label>' +
			control +
			( setting.hint ? '<p class="field__hint">' + esc( setting.hint ) + '</p>' : '' ) +
			( 'secret' === setting.type && value
				? '<p class="field__hint">' + esc( App.t( 'modules.secretSet' ) ) + '</p>'
				: '' ) +
		'</div>';
	};

	Modules.bind = function ( App, modules ) {
		var byKey = {};

		modules.forEach( function ( module ) { byKey[ module.key ] = module; } );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-toggle]' ),
			function ( input ) {
				input.addEventListener( 'change', function () {
					Modules.save( App, input.dataset.toggle, { enabled: input.checked }, input );
				} );
			}
		);

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-settings]' ),
			function ( form ) {
				form.addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var module = byKey[ form.dataset.settings ];
					var settings = {};

					module.settings.forEach( function ( setting ) {
						var control = form.elements[ setting.key ];

						if ( ! control ) {
							return;
						}

						settings[ setting.key ] = 'boolean' === setting.type
							? control.checked
							: control.value;
					} );

					Modules.save( App, module.key, { settings: settings } );
				} );
			}
		);
	};

	Modules.save = function ( App, key, payload, toggle ) {
		if ( Modules.saving ) {
			return;
		}

		Modules.saving = true;

		// `vendor/name` travels as `vendor.name`: a slash inside a path segment is a fight with
		// every router and proxy between here and the server.
		App.request( 'PATCH', '/modules/' + key.replace( '/', '.' ), payload )
			.then( function () {
				Modules.saving = false;
				Modules.render( App );
			} )
			.catch( function ( error ) {
				Modules.saving = false;

				// Put the switch back where it was. A toggle that stays on after the server
				// refused is a toggle that lies about what is running.
				if ( toggle ) {
					toggle.checked = ! toggle.checked;
				}

				var problems = error.details && error.details.problems;

				App.toast( problems && problems.length ? problems.join( ' ' ) : error.message, 'error' );
			} );
	};

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapModules = Modules;
}( window ) );
