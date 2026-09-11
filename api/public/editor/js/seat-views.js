/**
 * What a buyer would see from each section, and the screen an organiser attaches it on.
 *
 * A seat plan answers "where" and a price answers "how much". The question somebody choosing
 * between the stalls and the balcony is actually asking — what does the stage look like from
 * there — had no answer at all, and it is the one that decides the sale.
 *
 * This is a list of the chart's own sections rather than a list of pictures: every section is a
 * row whether or not anything is attached to it, because the work is going through the room and
 * saying what each part of it looks like. Clearing an address takes the picture away.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Views = {
		App: null,
		map: null,
		rows: [],
	};

	/** Open the pictures for one chart, over whatever screen asked for them. */
	Views.open = function ( App, map ) {
		Views.App = App;
		Views.map = map;

		App.request( 'GET', '/seat-maps/' + map.id + '/views' )
			.then( function ( answer ) {
				Views.rows = answer.data || [];
				Views.paint();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Views.paint = function () {
		var App = Views.App;

		var body = Views.rows.length
			? '<p class="hint">' + esc( App.t( 'panel.seatViews.hint' ) ) + '</p>' +
				'<div class="stack">' + Views.rows.map( row ).join( '' ) + '</div>'
			: '<div class="empty"><p>' + esc( App.t( 'panel.seatViews.noSections' ) ) + '</p>' +
				'<p class="hint">' + esc( App.t( 'panel.seatViews.noSectionsHint' ) ) + '</p></div>';

		var host = App.modal( {
			title: App.t( 'panel.seatViews.title' ),
			submitLabel: App.t( 'panel.seatViews.save' ),
			body: body,
			onSubmit: Views.rows.length ? function ( data, panel ) {
				return App.request( 'PUT', '/seat-maps/' + Views.map.id + '/views', {
					views: Views.collect( panel ),
				} ).then( function () {
					App.toast( App.t( 'panel.seatViews.saved' ) );
				} );
			} : null,
		} );

		// A picture of the address as it is typed, so somebody who pasted the wrong link finds out
		// here rather than from a buyer.
		host.querySelectorAll( '[data-view-url]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () { preview( host, input ); } );
			preview( host, input );
		} );
	};

	/**
	 * Every row, including the emptied ones.
	 *
	 * Sent whole rather than only the filled rows: a row with its address cleared is a picture the
	 * organiser has just taken away, and leaving it out would mean "no change" instead.
	 */
	Views.collect = function ( panel ) {
		var out = [];

		panel.querySelectorAll( '[data-view-row]' ).forEach( function ( element ) {
			var url = element.querySelector( '[data-view-url]' ).value.trim();
			var caption = element.querySelector( '[data-view-caption]' ).value.trim();

			out.push( {
				section_key: element.dataset.viewRow,
				url: url || null,
				caption: caption,
			} );
		} );

		return out;
	};

	function preview( host, input ) {
		var frame = host.querySelector( '[data-view-shot="' + cssEscape( input.dataset.viewUrl ) + '"]' );
		var url = input.value.trim();

		if ( ! frame ) {
			return;
		}

		// Only an address this platform would actually serve. The server refuses anything else,
		// and showing a javascript: link a frame here would be showing it working.
		if ( /^https?:\/\//i.test( url ) ) {
			frame.innerHTML = '<img src="' + esc( url ) + '" alt="">';
		} else {
			frame.innerHTML = icon( 'image', { size: 18 } );
		}
	}

	function row( one ) {
		var App = Views.App;
		var key = one.section_key;

		return '<div class="card card--pad stack" data-view-row="' + esc( key ) + '">' +
			'<div class="row--between"><strong>' + esc( one.name ) + '</strong>' +
			'<span class="muted tnum">' + esc( key ) + '</span></div>' +
			'<div class="view-shot" data-view-shot="' + esc( key ) + '"></div>' +
			'<div class="field"><label class="field__label" for="view-url-' + esc( key ) + '">' +
			esc( App.t( 'panel.seatViews.url' ) ) + '</label>' +
			'<input class="input" id="view-url-' + esc( key ) + '" type="url" maxlength="500" ' +
			'data-view-url="' + esc( key ) + '" value="' + esc( one.url || '' ) + '" ' +
			'placeholder="https://">' +
			'<span class="field__hint">' + esc( App.t( 'panel.seatViews.urlHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="view-cap-' + esc( key ) + '">' +
			esc( App.t( 'panel.seatViews.caption' ) ) + '</label>' +
			'<input class="input" id="view-cap-' + esc( key ) + '" maxlength="200" ' +
			'data-view-caption="' + esc( key ) + '" value="' + esc( one.caption || '' ) + '" ' +
			'placeholder="' + esc( App.t( 'panel.seatViews.captionPlaceholder' ) ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.seatViews.captionHint' ) ) + '</span></div>' +
			'</div>';
	}

	/** Section keys are the designer's own words, so they are quoted before going into a selector. */
	function cssEscape( value ) {
		return String( value ).replace( /["\\]/g, '\\$&' );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapSeatViews = Views;
}( typeof window !== 'undefined' ? window : globalThis ) );
