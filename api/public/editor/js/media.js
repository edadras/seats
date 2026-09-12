/**
 * A picture, put there by dragging it.
 *
 * Every image and every film on this platform used to be a URL: an event's artwork, a site's hero,
 * a slideshow, the logo in the masthead, the view from a seat, the background of a ticket. That is
 * a fine thing to allow and a poor thing to require — it asks a theatre with a poster on their
 * desktop to go and find a web host first, which is not what anybody opened this screen to do.
 *
 * So this is one field, used in all of those places, and it does four things in one control:
 *
 *   **Drag a file onto it, or choose one.** The drop target is the preview itself, which is the
 *   thing somebody is already looking at and aiming for. Both routes end in the same upload.
 *
 *   **Pick one already uploaded.** A season's poster goes on the event, the page and the ticket;
 *   asking for the same file three times is how a library fills up with three copies of it.
 *
 *   **Paste an address.** Kept, and deliberately: a venue whose poster is already on their own
 *   server should not have to upload it here to use it, and an organiser moving in from another
 *   platform has a hundred of them.
 *
 *   **Say what it is.** The name, the size, and — for a picture — its dimensions, because "why
 *   does my hero look soft" is answered by "it is 480 pixels wide" and by nothing else.
 *
 * What it hands back is a URL, always. That is the whole reason this could be added without
 * touching a single column that already holds a picture: a file kept here looks exactly like a
 * file kept anywhere else.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Media = { App: null };

	/**
	 * The markup for one field. Wire it with {@link Media.wire} once it is on the page.
	 *
	 * @param {object} options
	 *   - value    the address it currently holds, or ''
	 *   - kind     'image' or 'video' — decides what the file dialog offers and how it previews
	 *   - name     a form field name, when the surrounding form is serialised
	 *   - id       an id for the control, so a label can point at it
	 */
	Media.field = function ( options ) {
		var settings = options || {};

		return '<div class="media-field" data-media-field' +
			' data-kind="' + esc( settings.kind || 'image' ) + '"' +
			( settings.id ? ' data-id="' + esc( settings.id ) + '"' : '' ) +
			( settings.name ? ' data-name="' + esc( settings.name ) + '"' : '' ) +
			' data-value="' + esc( settings.value || '' ) + '"></div>';
	};

	/**
	 * Bring every field on the screen to life.
	 *
	 * Called after a paint, like the rest of the panel's wiring. Fields already built are left
	 * alone, so a screen that repaints one card does not throw away an upload in progress in
	 * another.
	 */
	Media.wire = function ( App ) {
		Media.App = App;

		each( '[data-media-field]', function ( node ) {
			if ( ! node.dataset.built ) {
				Media.build( App, node );
			}
		} );
	};

	/**
	 * A field as an element, for a screen that builds its controls rather than writing markup.
	 *
	 * The site builder is the one that does — its inspector is a column of real nodes appended to a
	 * card that is not on the page yet, and {@link Media.wire} finds fields by looking at the
	 * document. This builds one directly and hands it back, attached to nothing.
	 */
	Media.attach = function ( App, options, onChange ) {
		var settings = options || {};
		var holder = document.createElement( 'div' );

		holder.innerHTML = Media.field( settings );

		var node = holder.firstChild;

		Media.build( App, node );

		if ( onChange ) {
			node.addEventListener( 'media:change', function ( event ) {
				onChange( event.detail.url );
			} );
		}

		return node;
	};

	/** The address a field is holding, for a caller that reads rather than listens. */
	Media.valueOf = function ( node ) {
		return node && node.dataset ? ( node.dataset.value || '' ) : '';
	};

	Media.build = function ( App, node ) {
		node.dataset.built = '1';

		var kind = 'video' === node.dataset.kind ? 'video' : 'image';

		node.innerHTML =
			'<button type="button" class="media-drop" data-role="drop">' +
				'<span class="media-drop__empty">' + icon( 'image', { size: 20 } ) +
					'<span>' + esc( App.t( 'panel.media.drop' ) ) + '</span></span>' +
				'<span class="media-drop__art" hidden></span>' +
			'</button>' +
			'<p class="media-field__about hint" data-role="about"></p>' +
			'<div class="media-field__actions">' +
				'<button type="button" class="btn btn--sm" data-role="choose">' +
					esc( App.t( 'panel.media.choose' ) ) + '</button>' +
				'<button type="button" class="btn btn--sm" data-role="library">' +
					esc( App.t( 'panel.media.library' ) ) + '</button>' +
				'<button type="button" class="btn btn--sm" data-role="address">' +
					esc( App.t( 'panel.media.address' ) ) + '</button>' +
				'<button type="button" class="btn btn--sm btn--danger" data-role="clear" hidden>' +
					esc( App.t( 'panel.media.remove' ) ) + '</button>' +
			'</div>' +
			// Both are named: the address box appears on request and the file box is reached
			// through the drop target, and a control nobody can see is still a control somebody's
			// screen reader will land on.
			'<input class="input media-field__url" type="url" maxlength="1024" hidden' +
				' aria-label="' + esc( App.t( 'panel.media.address' ) ) + '"' +
				' placeholder="https://…" data-role="url">' +
			'<input type="file" hidden data-role="file"' +
				' aria-label="' + esc( App.t( 'panel.media.choose' ) ) + '"' +
				' accept="' + ( 'video' === kind ? 'video/*' : 'image/*' ) + '">' +
			( node.dataset.name
				? '<input type="hidden" name="' + esc( node.dataset.name ) + '" data-role="carry">'
				: '' );

		var parts = {
			drop: node.querySelector( '[data-role=drop]' ),
			art: node.querySelector( '.media-drop__art' ),
			empty: node.querySelector( '.media-drop__empty' ),
			about: node.querySelector( '[data-role=about]' ),
			file: node.querySelector( '[data-role=file]' ),
			url: node.querySelector( '[data-role=url]' ),
			carry: node.querySelector( '[data-role=carry]' ),
			clear: node.querySelector( '[data-role=clear]' ),
		};

		if ( node.dataset.id ) {
			parts.drop.id = node.dataset.id;
		}

		Media.show( node, parts, node.dataset.value || '' );

		parts.drop.addEventListener( 'click', function () { parts.file.click(); } );
		node.querySelector( '[data-role=choose]' )
			.addEventListener( 'click', function () { parts.file.click(); } );
		node.querySelector( '[data-role=library]' )
			.addEventListener( 'click', function () { Media.browse( App, node, parts ); } );

		node.querySelector( '[data-role=address]' ).addEventListener( 'click', function () {
			parts.url.hidden = ! parts.url.hidden;

			if ( ! parts.url.hidden ) {
				parts.url.value = node.dataset.value || '';
				parts.url.focus();
			}
		} );

		parts.url.addEventListener( 'change', function () {
			Media.set( node, parts, parts.url.value.trim() );
		} );

		parts.clear.addEventListener( 'click', function () {
			Media.set( node, parts, '' );
		} );

		parts.file.addEventListener( 'change', function () {
			if ( parts.file.files && parts.file.files[ 0 ] ) {
				Media.send( App, node, parts, parts.file.files[ 0 ] );
			}

			// Cleared so choosing the same file twice still fires a change — which is exactly what
			// somebody does after an upload failed.
			parts.file.value = '';
		} );

		/*
		 * Dropping a file.
		 *
		 * `dragover` has to be cancelled or the browser navigates to the file, which loses whatever
		 * was being edited — the single most annoying thing an upload field can do.
		 */
		[ 'dragenter', 'dragover' ].forEach( function ( name ) {
			parts.drop.addEventListener( name, function ( event ) {
				event.preventDefault();
				parts.drop.classList.add( 'is-over' );
			} );
		} );

		[ 'dragleave', 'dragend' ].forEach( function ( name ) {
			parts.drop.addEventListener( name, function () {
				parts.drop.classList.remove( 'is-over' );
			} );
		} );

		parts.drop.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			parts.drop.classList.remove( 'is-over' );

			var dropped = event.dataTransfer && event.dataTransfer.files;

			if ( dropped && dropped[ 0 ] ) {
				Media.send( App, node, parts, dropped[ 0 ] );
			}
		} );
	};

	/** Upload one file, and take what comes back as the field's value. */
	Media.send = function ( App, node, parts, file ) {
		var form = new FormData();

		form.append( 'file', file );

		node.classList.add( 'is-working' );
		parts.about.textContent = App.t( 'panel.media.sending' );

		App.request( 'POST', '/media', form )
			.then( function ( answer ) {
				node.classList.remove( 'is-working' );
				Media.set( node, parts, answer.media.url, answer.media );
			} )
			.catch( function ( error ) {
				node.classList.remove( 'is-working' );

				/*
				 * A toast and a line under the field, never `App.error`.
				 *
				 * That one replaces the whole screen with an error page — which, for an upload
				 * that failed inside the events dialog, would throw away everything the organiser
				 * had typed into it. A refused file is one field's problem.
				 */
				parts.about.textContent = error.message;
				App.toast( error.message, true );
			} );
	};

	/** Everything this account has uploaded, to choose from. */
	Media.browse = function ( App, node, parts ) {
		var kind = 'video' === node.dataset.kind ? 'video' : 'image';

		App.request( 'GET', '/media?kind=' + kind )
			.then( function ( answer ) {
				var files = answer.data || [];

				var host = App.modal( {
					title: App.t( 'panel.media.library' ),
					// No "cancel": picking one closes it and so does the corner, so a second way
					// out is a button that says nothing.
					cancelLabel: null,
					body: files.length
						? '<div class="media-grid">' + files.map( function ( file ) {
							return '<button type="button" class="media-tile" data-pick="' +
								esc( file.url ) + '" title="' + esc( file.name ) + '">' +
								( 'image' === file.kind
									? '<img src="' + esc( file.url ) + '" alt="">'
									: '<span class="media-tile__film">' +
										icon( 'play', { size: 18 } ) + '</span>' ) +
								'<span class="media-tile__name">' + esc( file.name ) + '</span>' +
							'</button>';
						} ).join( '' ) + '</div>'
						: '<p class="hint">' + esc( App.t( 'panel.media.libraryEmpty' ) ) + '</p>',
				} );

				Array.prototype.forEach.call(
					host.querySelectorAll( '[data-pick]' ),
					function ( tile ) {
						tile.addEventListener( 'click', function () {
							Media.set( node, parts, tile.dataset.pick );
							host.close();
						} );
					}
				);
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/** Take a new address, tell the screen, and say so out loud. */
	Media.set = function ( node, parts, url, about ) {
		node.dataset.value = url || '';

		Media.show( node, parts, url, about );

		/*
		 * A real event, so a screen can hear it.
		 *
		 * Some callers serialise a form and need nothing but the hidden input; others — a site's
		 * blocks, a ticket's background — keep their own state and have to be told. One event
		 * serves both rather than a callback the markup cannot carry.
		 */
		node.dispatchEvent( new CustomEvent( 'media:change', {
			bubbles: true,
			detail: { url: url || '' },
		} ) );
	};

	Media.show = function ( node, parts, url, about ) {
		var kind = 'video' === node.dataset.kind ? 'video' : 'image';

		if ( parts.carry ) {
			parts.carry.value = url || '';
		}

		parts.clear.hidden = ! url;
		parts.url.hidden = true;

		if ( ! url ) {
			parts.art.hidden = true;
			parts.art.innerHTML = '';
			parts.empty.hidden = false;
			parts.about.textContent = '';

			return;
		}

		parts.empty.hidden = true;
		parts.art.hidden = false;
		parts.art.innerHTML = 'video' === kind
			// Muted and without controls: this is a thumbnail of a film, not somewhere to watch one.
			? '<video src="' + esc( url ) + '" muted playsinline preload="metadata"></video>'
			: '<img src="' + esc( url ) + '" alt="">';

		parts.about.textContent = about
			? [ about.name, about.width ? about.width + '×' + about.height : '', megabytes( about.bytes ) ]
				.filter( Boolean ).join( ' · ' )
			: '';
	};

	function megabytes( bytes ) {
		if ( ! bytes ) {
			return '';
		}

		return bytes > 1048576
			? ( bytes / 1048576 ).toFixed( 1 ) + ' MB'
			: Math.round( bytes / 1024 ) + ' KB';
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapMedia = Media;
}( typeof window !== 'undefined' ? window : globalThis ) );
