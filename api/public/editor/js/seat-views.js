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

		App.modal( {
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
			var field = element.querySelector( '[data-media-field]' );
			var url = ( field ? field.dataset.value : '' ).trim();
			var caption = element.querySelector( '[data-view-caption]' ).value.trim();

			out.push( {
				section_key: element.dataset.viewRow,
				url: url || null,
				caption: caption,
			} );
		} );

		return out;
	};

	function row( one ) {
		var App = Views.App;
		var key = one.section_key;

		return '<div class="card card--pad stack" data-view-row="' + esc( key ) + '">' +
			'<div class="row--between"><strong>' + esc( one.name ) + '</strong>' +
			'<span class="muted tnum">' + esc( key ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="view-url-' + esc( key ) + '">' +
			esc( App.t( 'panel.seatViews.url' ) ) + '</label>' +
			/*
			 * The field is its own preview, which is what the frame above this row used to be.
			 *
			 * One picture of the photograph rather than two — and now the photograph can be dragged
			 * straight onto it, which is how somebody who has just walked a hall with a camera
			 * actually works through twelve sections.
			 */
			global.SeatmapMedia.field( {
				id: 'view-url-' + key,
				kind: 'image',
				value: one.url || '',
			} ) +
			'<span class="field__hint">' + esc( App.t( 'panel.seatViews.urlHint' ) ) + '</span></div>' +
			'<div class="field"><label class="field__label" for="view-cap-' + esc( key ) + '">' +
			esc( App.t( 'panel.seatViews.caption' ) ) + '</label>' +
			'<input class="input" id="view-cap-' + esc( key ) + '" maxlength="200" ' +
			'data-view-caption="' + esc( key ) + '" value="' + esc( one.caption || '' ) + '" ' +
			'placeholder="' + esc( App.t( 'panel.seatViews.captionPlaceholder' ) ) + '">' +
			'<span class="field__hint">' + esc( App.t( 'panel.seatViews.captionHint' ) ) + '</span></div>' +
			'</div>';
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapSeatViews = Views;
}( typeof window !== 'undefined' ? window : globalThis ) );
