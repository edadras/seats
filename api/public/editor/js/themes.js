/**
 * Themes: the controls, the stylesheet, and a page to watch while you move them.
 *
 * Two ways to work, one object underneath. The controls cover what most sites need; the stylesheet
 * covers the rest, because refusing to offer one only means the answer to "can I move that
 * heading" is no. Neither is code — a theme on this platform is tokens and CSS (ADR-0003), which
 * is why choosing or writing one is never a security decision.
 *
 * The preview is the real site stylesheet, the real theme file and the real markup, in an iframe.
 * A swatch of colours would be quicker to build and would not answer the question anybody actually
 * has, which is "what does my site look like now".
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Themes = {
		App: null,
		builtIn: [],
		custom: [],
		tokens: [],
		fonts: [],
		maxCss: 40000,

		// The theme being edited, and the draft that has not been saved yet.
		theme: null,
		draft: { tokens: {}, css: '' },
		versions: [],
		dirty: false,
	};

	/* ------------------------------------------------------------------------- the gallery */

	Themes.render = function ( App ) {
		Themes.App = App;
		Themes.theme = null;

		App.loading( App.t( 'themes.title' ) );

		App.request( 'GET', '/themes' ).then( function ( body ) {
			Themes.builtIn = body.built_in || [];
			Themes.custom = body.data || [];
			Themes.tokens = body.tokens || [];
			Themes.fonts = body.fonts || [];
			Themes.maxCss = body.max_css_bytes || 40000;
			Themes.paintGallery();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	Themes.paintGallery = function () {
		var App = Themes.App;

		App.page( {
			title: App.t( 'themes.title' ),
			description: App.t( 'themes.subtitle' ),
			actions: '<button class="btn btn--primary" id="theme-new">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'themes.new' ) ) + '</button>',
			body:
				'<h3 class="subhead">' + esc( App.t( 'themes.ours' ) ) + '</h3>' +
				'<div class="look-grid">' +
					Themes.builtIn.map( function ( theme ) {
						return Themes.card( theme, false );
					} ).join( '' ) +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'themes.yours' ) ) + '</h3>' +
				( Themes.custom.length
					? '<div class="look-grid">' +
						Themes.custom.map( function ( theme ) {
							return Themes.card( theme, true );
						} ).join( '' ) +
					'</div>'
					: App.emptyState( 'palette', App.t( 'themes.noneYet' ), App.t( 'themes.noneYetHint' ) ) ),
		} );

		Themes.bindGallery();
	};

	/** A card that is actually painted in the theme it is offering. */
	Themes.card = function ( theme, mine ) {
		var App = Themes.App;
		var t = theme.tokens || {};
		var swatch = 'background:' + esc( t.surface || '#fff' ) + ';color:' + esc( t.text || '#000' ) +
			';border-color:' + esc( t.border || '#ddd' );

		return '<div class="look">' +
			'<div class="look__preview" style="' + swatch + '">' +
				'<span class="look__title" style="color:' + esc( t.text || '#000' ) + '">Aa</span>' +
				'<span class="look__dot" style="background:' + esc( t.accent || '#333' ) + '"></span>' +
				'<span class="look__bar" style="background:' + esc( t.surface_sunken || '#eee' ) + '"></span>' +
			'</div>' +
			'<div class="look__body">' +
				'<strong>' + esc( theme.name ) + '</strong>' +
				'<p class="muted">' + esc( mine
					? App.t( 'themes.basedOn', { theme: Themes.baseName( theme.base_key ) } )
					: theme.description ) + '</p>' +
				'<div class="look__actions">' +
					( mine
						? '<button class="btn btn--sm" data-edit="' + esc( theme.id ) + '">' +
								esc( App.t( 'themes.edit' ) ) + '</button>' +
							'<button class="btn btn--sm" data-delete="' + esc( theme.id ) + '">' +
								esc( App.t( 'themes.delete' ) ) + '</button>' +
							( theme.sites_count
								? '<span class="muted">' + esc( App.t( 'themes.wornBy', {
									count: App.number( theme.sites_count ),
								} ) ) + '</span>'
								: '' )
						: '<button class="btn btn--sm" data-copy="' + esc( theme.key ) + '">' +
							esc( App.t( 'themes.duplicate' ) ) + '</button>' ) +
				'</div>' +
			'</div>' +
		'</div>';
	};

	Themes.baseName = function ( key ) {
		var found = Themes.builtIn.filter( function ( theme ) { return theme.key === key; } )[ 0 ];

		return found ? found.name : key;
	};

	Themes.bindGallery = function () {
		var App = Themes.App;

		each( '[data-copy]', function ( button ) {
			button.addEventListener( 'click', function () { Themes.create( button.dataset.copy ); } );
		} );

		each( '[data-edit]', function ( button ) {
			button.addEventListener( 'click', function () { Themes.edit( button.dataset.edit ); } );
		} );

		each( '[data-delete]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.modal( {
					title: App.t( 'themes.deleteTitle' ),
					submitLabel: App.t( 'themes.delete' ),
					body: '<p>' + esc( App.t( 'themes.deleteBody' ) ) + '</p>',
					onSubmit: function () {
						return App.request( 'DELETE', '/themes/' + button.dataset.delete )
							.then( function () {
								App.toast( App.t( 'themes.deleted' ) );
								Themes.render( App );
							} );
					},
				} );
			} );
		} );

		document.getElementById( 'theme-new' ).addEventListener( 'click', function () {
			Themes.create( 'aurora' );
		} );
	};

	Themes.create = function ( baseKey ) {
		var App = Themes.App;

		App.modal( {
			title: App.t( 'themes.newTitle' ),
			submitLabel: App.t( 'themes.create' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="t-name">' +
					esc( App.t( 'themes.name' ) ) + '</label>' +
				'<input class="input" id="t-name" name="name" required maxlength="80" value="' +
					esc( Themes.baseName( baseKey ) + ' — ' + App.t( 'themes.mine' ) ) + '"></div>' +
				'<div class="field"><label class="field__label" for="t-base">' +
					esc( App.t( 'themes.base' ) ) + '</label>' +
				'<select class="select" id="t-base" name="base_key">' +
					Themes.builtIn.map( function ( theme ) {
						return '<option value="' + esc( theme.key ) + '"' +
							( theme.key === baseKey ? ' selected' : '' ) + '>' + esc( theme.name ) +
							'</option>';
					} ).join( '' ) +
				'</select><span class="field__hint">' + esc( App.t( 'themes.baseHint' ) ) +
				'</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/themes', {
					name: data.get( 'name' ),
					base_key: data.get( 'base_key' ),
				} ).then( function ( theme ) {
					App.toast( App.t( 'themes.created' ) );
					Themes.edit( theme.id );
				} );
			},
		} );
	};

	/* -------------------------------------------------------------------------- the editor */

	Themes.edit = function ( id ) {
		var App = Themes.App;

		App.loading( App.t( 'themes.title' ) );

		App.request( 'GET', '/themes/' + id ).then( function ( theme ) {
			Themes.theme = theme;
			Themes.versions = theme.versions || [];
			Themes.draft = {
				tokens: JSON.parse( JSON.stringify( theme.own_tokens || {} ) ),
				css: theme.css || '',
			};
			Themes.dirty = false;
			Themes.paintEditor();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Themes.paintEditor = function () {
		var App = Themes.App;
		var theme = Themes.theme;

		App.page( {
			title: theme.name,
			description: App.t( 'themes.basedOn', { theme: Themes.baseName( theme.base_key ) } ),
			actions:
				'<button class="btn" id="theme-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'themes.backToThemes' ) ) + '</button>' +
				'<button class="btn btn--primary" id="theme-save">' +
					esc( App.t( 'themes.save' ) ) + '</button>',
			body:
				'<div class="theme-editor">' +
					'<div class="theme-editor__controls">' +
						Themes.groupsMarkup() +
						Themes.cssMarkup() +
						Themes.versionsMarkup() +
					'</div>' +
					'<div class="theme-editor__preview">' +
						/*
						 * `allow-same-origin` without `allow-scripts`: the parent needs to reach
						 * in to rewrite the two style elements as the controls move, and nothing
						 * inside may run. A theme is CSS, so there is nothing to run anyway.
						 */
						'<iframe class="theme-preview" id="theme-preview" title="' +
							esc( App.t( 'themes.preview' ) ) + '" sandbox="allow-same-origin"></iframe>' +
					'</div>' +
				'</div>',
		} );

		Themes.mountPreview();
		Themes.bindEditor();
	};

	Themes.groupsMarkup = function () {
		var App = Themes.App;
		var groups = [ 'colour', 'type', 'shape', 'layout' ];

		return groups.map( function ( group ) {
			var fields = Themes.tokens.filter( function ( token ) { return token.group === group; } );

			if ( ! fields.length ) {
				return '';
			}

			return '<section class="theme-group">' +
				'<h3 class="subhead">' + esc( App.t( 'themes.groups.' + group ) ) + '</h3>' +
				'<div class="theme-group__fields">' +
					fields.map( Themes.field ).join( '' ) +
				'</div>' +
			'</section>';
		} ).join( '' );
	};

	Themes.field = function ( token ) {
		var App = Themes.App;
		var value = Themes.value( token );
		var label = App.t( 'themes.tokens.' + token.key );

		if ( 'colour' === token.kind ) {
			return '<div class="field field--swatch">' +
				'<label class="field__label" for="tk-' + esc( token.key ) + '">' + esc( label ) + '</label>' +
				'<span class="field__row">' +
					'<input class="swatch" type="color" id="tk-' + esc( token.key ) + '" ' +
						'data-token="' + esc( token.key ) + '" value="' + esc( value ) + '">' +
					'<input class="input input--code" data-hex="' + esc( token.key ) + '" ' +
						'value="' + esc( value ) + '" maxlength="7">' +
				'</span>' +
			'</div>';
		}

		return '<div class="field">' +
			'<label class="field__label" for="tk-' + esc( token.key ) + '">' + esc( label ) + '</label>' +
			'<select class="select" id="tk-' + esc( token.key ) + '" data-token="' + esc( token.key ) + '">' +
				token.options.map( function ( option ) {
					return '<option value="' + esc( option ) + '"' +
						( option === value ? ' selected' : '' ) + '>' +
						esc( App.t( 'themes.options.' + option ) ) + '</option>';
				} ).join( '' ) +
			'</select>' +
		'</div>';
	};

	/** What a control shows: the draft if it says anything, otherwise what the theme resolves to. */
	Themes.value = function ( token ) {
		if ( Object.prototype.hasOwnProperty.call( Themes.draft.tokens, token.key ) ) {
			return Themes.draft.tokens[ token.key ];
		}

		return Themes.theme.tokens[ token.key ];
	};

	Themes.cssMarkup = function () {
		var App = Themes.App;

		return '<section class="theme-group">' +
			'<h3 class="subhead">' + esc( App.t( 'themes.stylesheet' ) ) + '</h3>' +
			'<p class="muted">' + esc( App.t( 'themes.stylesheetHint' ) ) + '</p>' +
			'<textarea class="code" id="theme-css" spellcheck="false" rows="14" ' +
				'aria-label="' + esc( App.t( 'themes.stylesheet' ) ) + '">' +
				esc( Themes.draft.css ) + '</textarea>' +
			'<p class="muted tnum" id="theme-css-count"></p>' +
		'</section>';
	};

	Themes.versionsMarkup = function () {
		var App = Themes.App;

		if ( ! Themes.versions.length ) {
			return '';
		}

		return '<section class="theme-group">' +
			'<h3 class="subhead">' + esc( App.t( 'themes.history' ) ) + '</h3>' +
			'<ul class="versions">' +
				Themes.versions.map( function ( version ) {
					return '<li class="version">' +
						'<span class="version__what"><b>v' + esc( version.version ) + '</b> ' +
							esc( version.note || '' ) + '</span>' +
						'<span class="muted">' + esc( App.date( version.created_at ) ) +
							( version.author ? ' · ' + esc( version.author ) : '' ) + '</span>' +
						'<button class="btn btn--sm" data-revert="' + esc( version.id ) + '">' +
							esc( App.t( 'themes.revert' ) ) + '</button>' +
					'</li>';
				} ).join( '' ) +
			'</ul>' +
		'</section>';
	};

	/* ------------------------------------------------------------------------- the preview */

	/**
	 * The preview page.
	 *
	 * The site's own stylesheet and the base theme's file are linked, not copied, so this cannot
	 * drift from what a visitor sees. The two `<style>` elements below them are what the controls
	 * and the editor write into.
	 */
	Themes.mountPreview = function () {
		var frame = document.getElementById( 'theme-preview' );

		frame.srcdoc =
			'<!doctype html><html><head><meta charset="utf-8">' +
			'<link rel="stylesheet" href="' + Themes.assetUrl( 'site/css/site.css' ) + '">' +
			'<link rel="stylesheet" href="' + Themes.assetUrl(
				'site/css/themes/' + Themes.theme.base_key + '.css' ) + '">' +
			'<style id="tokens"></style><style id="custom"></style>' +
			'</head><body class="theme-' + Themes.theme.base_key + '">' + Themes.sample() + '</body></html>';

		frame.addEventListener( 'load', function () { Themes.refreshPreview(); } );
	};

	Themes.assetUrl = function ( path ) {
		return new URL( '/' + path, global.location.origin ).href;
	};

	/** A page with one of everything the tokens touch. */
	Themes.sample = function () {
		var App = Themes.App;

		return '<header class="masthead"><div class="shell masthead__inner">' +
				'<a class="brand" href="#"><span class="brand__name">' +
					esc( App.t( 'themes.sample.venue' ) ) + '</span></a>' +
				'<nav class="nav"><a class="nav__link" href="#">' +
					esc( App.t( 'themes.sample.whatsOn' ) ) + '</a>' +
					'<a class="nav__link" href="#">' + esc( App.t( 'themes.sample.visit' ) ) + '</a></nav>' +
			'</div></header>' +
			'<main><div class="shell section">' +
				'<h1>' + esc( App.t( 'themes.sample.heading' ) ) + '</h1>' +
				'<p>' + esc( App.t( 'themes.sample.body' ) ) + '</p>' +
				'<p><a class="button" href="#">' + esc( App.t( 'themes.sample.book' ) ) + '</a> ' +
					'<a class="button button--secondary" href="#">' +
					esc( App.t( 'themes.sample.more' ) ) + '</a></p>' +
				'<div class="event-list">' +
					[ 1, 2 ].map( function ( n ) {
						// The same markup the event list block renders, so the preview is the page
						// and not an impression of it.
						return '<a class="event-card" href="#">' +
							'<time class="event-card__when">' +
								'<span class="event-card__day">' + ( 11 + n ) + '</span>' +
								'<span class="event-card__month">' +
									esc( App.t( 'themes.sample.date' + n ) ) + '</span>' +
							'</time>' +
							'<div class="event-card__body">' +
								'<h3 class="event-card__name">' +
									esc( App.t( 'themes.sample.event' + n ) ) + '</h3>' +
								'<p class="event-card__meta">19:30 · ' +
									esc( App.t( 'themes.sample.venue' ) ) + '</p>' +
							'</div>' +
							'<span class="event-card__cta">' +
								esc( App.t( 'themes.sample.book' ) ) + '</span>' +
						'</a>';
					} ).join( '' ) +
				'</div>' +
			'</div></main>' +
			'<footer class="footer"><div class="shell footer__inner"><p class="footer__name">' +
				esc( App.t( 'themes.sample.venue' ) ) + '</p></div></footer>';
	};

	/**
	 * Compile the draft into the preview.
	 *
	 * The token values came from the server with the token list, so this compiles the same table
	 * the server does rather than a second copy of what "roomy" means.
	 */
	Themes.refreshPreview = function () {
		var frame = document.getElementById( 'theme-preview' );
		var doc = frame && frame.contentDocument;

		if ( ! doc || ! doc.getElementById( 'tokens' ) ) {
			return;
		}

		var declarations = Themes.tokens.map( function ( token ) {
			var value = Themes.value( token );

			return token.var + ':' + ( 'colour' === token.kind ? value : token.values[ value ] );
		} );

		doc.getElementById( 'tokens' ).textContent = ':root{' + declarations.join( ';' ) + '}';
		// The editor's own copy is not the sanitised one — the server does that on save, and the
		// difference is reported then. Angle brackets are dropped here so a stray `</style>`
		// cannot break the preview while somebody is still typing.
		doc.getElementById( 'custom' ).textContent = Themes.draft.css.replace( /[<>]/g, '' );
	};

	/* -------------------------------------------------------------------------- the wiring */

	Themes.bindEditor = function () {
		var App = Themes.App;

		each( '[data-token]', function ( control ) {
			control.addEventListener( 'input', function () {
				Themes.draft.tokens[ control.dataset.token ] = control.value;
				Themes.dirty = true;

				var hex = document.querySelector( '[data-hex="' + control.dataset.token + '"]' );

				if ( hex ) {
					hex.value = control.value;
				}

				Themes.refreshPreview();
			} );
		} );

		each( '[data-hex]', function ( field ) {
			field.addEventListener( 'change', function () {
				if ( ! /^#[0-9a-fA-F]{6}$/.test( field.value.trim() ) ) {
					App.toast( App.t( 'themes.badColour' ), true );

					return;
				}

				var key = field.dataset.hex;

				Themes.draft.tokens[ key ] = field.value.trim().toLowerCase();
				Themes.dirty = true;
				document.getElementById( 'tk-' + key ).value = Themes.draft.tokens[ key ];
				Themes.refreshPreview();
			} );
		} );

		var css = document.getElementById( 'theme-css' );
		var count = document.getElementById( 'theme-css-count' );
		var timer = null;

		function countBytes() {
			count.textContent = App.t( 'themes.bytes', {
				used: App.number( css.value.length ),
				max: App.number( Themes.maxCss ),
			} );
		}

		css.addEventListener( 'input', function () {
			Themes.draft.css = css.value;
			Themes.dirty = true;
			countBytes();

			// Debounced: repainting a stylesheet on every keystroke makes the preview flicker
			// through every half-typed selector.
			global.clearTimeout( timer );
			timer = global.setTimeout( Themes.refreshPreview, 250 );
		} );

		countBytes();

		each( '[data-revert]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.request( 'POST', '/themes/' + Themes.theme.id + '/versions/' +
					button.dataset.revert + '/revert' ).then( function () {
					App.toast( App.t( 'themes.reverted' ) );
					Themes.edit( Themes.theme.id );
				} ).catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		document.getElementById( 'theme-back' ).addEventListener( 'click', function () {
			if ( Themes.dirty && ! global.confirm( App.t( 'themes.discard' ) ) ) {
				return;
			}

			Themes.render( App );
		} );

		document.getElementById( 'theme-save' ).addEventListener( 'click', function () { Themes.save(); } );
	};

	Themes.save = function () {
		var App = Themes.App;

		App.request( 'PATCH', '/themes/' + Themes.theme.id, {
			tokens: Themes.draft.tokens,
			css: Themes.draft.css,
		} ).then( function ( saved ) {
			Themes.dirty = false;
			App.toast( App.t( 'themes.saved' ) );

			if ( saved.stylesheet_trimmed ) {
				App.toast( App.t( 'themes.trimmed' ), true );
			}

			Themes.edit( Themes.theme.id );
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* --------------------------------------------------------------------------- helpers */

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapThemes = Themes;
}( window ) );
