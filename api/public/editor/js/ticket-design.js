/**
 * The ticket one night prints.
 *
 * Every ticket this platform produced used to look the same: a bordered box, the event, the seat, a
 * QR. That is a correct document and it is nobody's. A theatre with a poster, a festival with a
 * sponsor along the foot, a club whose entire brand is one photograph — each wants the ticket to
 * look like the night, and none of them can say so in a layout somebody else chose.
 *
 * So: a picture, and fields dragged onto it.
 *
 * Two decisions shape the whole screen.
 *
 *   **Positions are percentages, and the surface is the page.** The board below is drawn at
 *   whatever width the browser gives it, with the page's own aspect ratio; the PDF is drawn at a
 *   fixed size in millimetres. Percentages are the only numbers that mean the same thing to both,
 *   which is why what somebody sees here is where it prints.
 *
 *   **The preview is the real document.** Rendered by the server, through the renderer that makes
 *   the real ticket, from a booking that was never made. A canvas mock-up would agree with itself
 *   and disagree with the file a buyer opens — and the first time anybody found out would be the
 *   night they printed four hundred of them.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Design = { App: null, eventId: null, event: null, state: null, offered: [], pages: [], picked: null, stored: false };

	Design.open = function ( App, eventId, event ) {
		Design.App = App;
		Design.eventId = eventId;
		Design.event = event || null;
		Design.picked = null;

		App.loading( App.t( 'panel.ticketDesign.title' ) );

		App.request( 'GET', '/events/' + eventId + '/ticket-design' )
			.then( function ( answer ) {
				Design.offered = answer.fields || [];
				// Whether this night has a ticket of its own, which decides whether there is
				// anything to go back from.
				Design.stored = !! answer.design;
				// Each paper as a name and its millimetres, which is what the board is shaped by.
				Design.pages = answer.pages || [ { name: 'A5', width: 148, height: 210 } ];
				Design.starter = answer.starter || [];
				// A night with no design starts from the platform's own ticket, which is a real
				// answer rather than an empty board: most organisers want their picture behind
				// roughly this, not a blank page to invent a ticket on.
				Design.state = answer.design || Design.platform();

				Design.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/**
	 * The platform's own ticket, as something the organiser can then move about.
	 *
	 * A copy of the starter rather than the starter itself: the board is edited in place, and
	 * handing out the same array twice would mean the second night began where the first was left.
	 */
	Design.platform = function () {
		return {
			background_url: '',
			page_size: 'A5',
			orientation: 'landscape',
			fields: JSON.parse( JSON.stringify( Design.starter || [] ) ),
		};
	};

	Design.paint = function () {
		var App = Design.App;
		var state = Design.state;

		App.page( {
			title: App.t( 'panel.ticketDesign.title' ),
			description: esc( App.t( 'panel.ticketDesign.subtitle' ) ),
			actions: '<button class="btn" id="td-back">' +
					esc( App.t( 'panel.ticketDesign.back' ) ) + '</button>' +
				( Design.stored
					? '<button class="btn btn--danger" id="td-clear">' +
						esc( App.t( 'panel.ticketDesign.clear' ) ) + '</button>'
					: '' ) +
				'<button class="btn" id="td-preview">' + icon( 'file', { size: 15 } ) +
					esc( App.t( 'panel.ticketDesign.preview' ) ) + '</button>' +
				'<button class="btn btn--primary" id="td-save">' +
					esc( App.t( 'panel.common.save' ) ) + '</button>',
			body: '<div class="split">' +
					'<section class="card card--pad">' +
						Design.settings( App, state ) +
						Design.board( state ) +
						'<p class="hint">' + esc( App.t( 'panel.ticketDesign.boardHint' ) ) + '</p>' +
					'</section>' +
					'<section class="card card--pad">' +
						Design.palette( App, state ) +
						Design.inspector( App, state ) +
					'</section>' +
				'</div>',
		} );

		Design.wire();
	};

	Design.settings = function ( App, state ) {
		return '<div class="field-duo">' +
			// A `label for` on a button: the drop target is one, and buttons are labelable, so the
			// field is announced as "Background" rather than as its own instructions.
			'<div class="field"><label class="field__label" for="td-bg">' +
				esc( App.t( 'panel.ticketDesign.background' ) ) + '</label>' +
				global.SeatmapMedia.field( {
					id: 'td-bg',
					kind: 'image',
					value: state.background_url || '',
				} ) +
				'<span class="field__hint">' + esc( App.t( 'panel.ticketDesign.backgroundHint' ) ) + '</span>' +
			'</div>' +
			'<div class="field"><label class="field__label" for="td-page">' +
				esc( App.t( 'panel.ticketDesign.page' ) ) + '</label>' +
				'<select class="select" id="td-page">' + Design.pages.map( function ( paper ) {
					return '<option value="' + esc( paper.name ) + '"' +
						( paper.name === state.page_size ? ' selected' : '' ) + '>' +
						esc( paper.name ) + '</option>';
				} ).join( '' ) + '</select>' +
			'</div>' +
			'<div class="field"><label class="field__label" for="td-orient">' +
				esc( App.t( 'panel.ticketDesign.orientation' ) ) + '</label>' +
				'<select class="select" id="td-orient">' +
					[ 'landscape', 'portrait' ].map( function ( way ) {
						return '<option value="' + way + '"' +
							( way === state.orientation ? ' selected' : '' ) + '>' +
							esc( App.t( 'panel.ticketDesign.' + way ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
		'</div>';
	};

	/**
	 * The page, at the page's own shape.
	 *
	 * `aspect-ratio` from the chosen paper's millimetres rather than a fixed height: a landscape A5
	 * and a portrait A4 are different documents, and a board that is always the same rectangle
	 * would put every field in the wrong place on one of them. The millimetres come from the
	 * server, which is the same constant the renderer measures the document with — A4, A5 and A6
	 * are within half a per cent of one another and letter is not, so a shape assumed here would
	 * look right until the first venue that prints on letter.
	 */
	Design.board = function ( state ) {
		var paper = Design.paper( state.page_size );
		var ratio = 'landscape' === state.orientation
			? paper.height + ' / ' + paper.width
			: paper.width + ' / ' + paper.height;

		return '<div class="ticket-board" id="td-board" style="aspect-ratio:' + ratio + '">' +
			( state.background_url
				? '<img class="ticket-board__art" src="' + esc( state.background_url ) + '" alt="">'
				: '' ) +
			( state.fields || [] ).map( function ( field, index ) {
				return Design.chip( field, index );
			} ).join( '' ) +
		'</div>';
	};

	/** One paper by name, falling back to the first offered rather than to a guess. */
	Design.paper = function ( name ) {
		var papers = Design.pages || [];
		var found = null;

		papers.forEach( function ( paper ) {
			if ( paper.name === name ) {
				found = paper;
			}
		} );

		return found || papers[ 0 ] || { name: 'A5', width: 148, height: 210 };
	};

	Design.chip = function ( field, index ) {
		var App = Design.App;

		return '<button type="button" class="ticket-field' +
			( index === Design.picked ? ' is-picked' : '' ) +
			'" data-field="' + index + '" style="' +
				'inset-inline-start:' + field.x + '%;inset-block-start:' + field.y + '%;' +
				'inline-size:' + field.width + '%;' +
				'font-size:' + Math.max( 8, field.size * 0.9 ) + 'px;' +
				'color:' + esc( field.colour ) + ';' +
				'text-align:' + ( 'center' === field.align ? 'center' : ( 'end' === field.align ? 'end' : 'start' ) ) + ';' +
				( 'bold' === field.weight ? 'font-weight:700;' : '' ) +
			'">' + esc( App.t( 'panel.ticketDesign.fields.' + field.key ) ) + '</button>';
	};

	/** The fields not on the page yet. A field can be placed once: two of the same is a mistake. */
	Design.palette = function ( App, state ) {
		var used = ( state.fields || [] ).map( function ( field ) { return field.key; } );
		var spare = Design.offered.filter( function ( key ) { return used.indexOf( key ) === -1; } );

		return '<h3 class="card__title">' + esc( App.t( 'panel.ticketDesign.add' ) ) + '</h3>' +
			( spare.length
				? '<div class="field-chips">' + spare.map( function ( key ) {
					return '<button type="button" class="field-chip" data-add="' + esc( key ) + '">' +
						icon( 'plus', { size: 13 } ) +
						'<span>' + esc( App.t( 'panel.ticketDesign.fields.' + key ) ) + '</span></button>';
				} ).join( '' ) + '</div>'
				: '<p class="hint">' + esc( App.t( 'panel.ticketDesign.allPlaced' ) ) + '</p>' );
	};

	/** What the chosen field looks like. Nothing at all when nothing is chosen. */
	Design.inspector = function ( App, state ) {
		var field = ( state.fields || [] )[ Design.picked ];

		if ( ! field ) {
			return '<h3 class="card__title spaced">' + esc( App.t( 'panel.ticketDesign.chosen' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'panel.ticketDesign.chooseOne' ) ) + '</p>';
		}

		return '<h3 class="card__title spaced">' +
				esc( App.t( 'panel.ticketDesign.fields.' + field.key ) ) + '</h3>' +
			'<div class="field-duo">' +
				number( 'td-size', App.t( 'panel.ticketDesign.size' ), field.size, 6, 72, 0.5 ) +
				number( 'td-width', App.t( 'panel.ticketDesign.width' ), field.width, 4, 100, 1 ) +
			'</div>' +
			'<div class="field-duo">' +
				'<div class="field"><label class="field__label" for="td-align">' +
					esc( App.t( 'panel.ticketDesign.align' ) ) + '</label>' +
					'<select class="select" id="td-align">' +
						[ 'start', 'center', 'end' ].map( function ( way ) {
							return '<option value="' + way + '"' +
								( way === field.align ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.ticketDesign.align_' + way ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div class="field"><label class="field__label" for="td-colour">' +
					esc( App.t( 'panel.ticketDesign.colour' ) ) + '</label>' +
					// `swatch`, the panel's own class for a colour well — `input` stretches it into a
					// full-width black bar, which is what the theme editor stopped doing long ago.
					'<input class="swatch" id="td-colour" type="color" value="' + esc( field.colour ) + '">' +
				'</div>' +
			'</div>' +
			// The track and the thumb are the switch: the checkbox itself is hidden by the panel's
			// stylesheet, so a label without them is a word with nothing to press.
			'<label class="switch switch--row"><input type="checkbox" id="td-bold"' +
				( 'bold' === field.weight ? ' checked' : '' ) + '>' +
				'<span class="switch__track"><span class="switch__thumb"></span></span>' +
				'<span>' + esc( App.t( 'panel.ticketDesign.bold' ) ) + '</span></label>' +
			'<div class="row--between spaced"><span></span>' +
				'<button class="btn btn--sm btn--danger" id="td-remove">' +
					esc( App.t( 'panel.common.remove' ) ) + '</button></div>';
	};

	/* ------------------------------------------------------------------------------- wiring */

	Design.wire = function () {
		var App = Design.App;
		var board = document.getElementById( 'td-board' );

		on( '#td-back', function () { App.renderEvents(); } );
		on( '#td-save', Design.save );
		on( '#td-preview', Design.preview );
		on( '#td-clear', Design.clear );

		/*
		 * The picture field says when it changed, rather than being read.
		 *
		 * It is four controls in one — a drop target, a file dialog, the account's library and a
		 * pasted address — and every one of them ends in the same event. Listening for that is what
		 * keeps this screen from having to know which of the four somebody used.
		 */
		var background = document.querySelector( '[data-media-field][data-id="td-bg"]' );

		if ( background ) {
			background.addEventListener( 'media:change', function ( event ) {
				Design.state.background_url = event.detail.url;
				Design.paint();
			} );
		}
		change( '#td-page', function ( value ) { Design.state.page_size = value; Design.paint(); } );
		change( '#td-orient', function ( value ) { Design.state.orientation = value; Design.paint(); } );

		each( '[data-add]', function ( button ) {
			button.addEventListener( 'click', function () {
				// Dropped in the middle rather than at the corner: a field at 0,0 is under the
				// page's edge and the first thing anybody does is drag it off it.
				Design.state.fields.push( {
					key: button.dataset.add,
					x: 35, y: 45, width: 30, size: 12,
					weight: 'normal', align: 'start', colour: '#111111',
				} );

				Design.picked = Design.state.fields.length - 1;
				Design.paint();
			} );
		} );

		on( '#td-remove', function () {
			Design.state.fields.splice( Design.picked, 1 );
			Design.picked = null;
			Design.paint();
		} );

		var field = function () { return Design.state.fields[ Design.picked ]; };

		change( '#td-size', function ( v ) { field().size = Number( v ); Design.paint(); } );
		change( '#td-width', function ( v ) { field().width = Number( v ); Design.paint(); } );
		change( '#td-align', function ( v ) { field().align = v; Design.paint(); } );
		change( '#td-colour', function ( v ) { field().colour = v; Design.paint(); } );

		var bold = document.getElementById( 'td-bold' );

		if ( bold ) {
			bold.addEventListener( 'change', function () {
				field().weight = bold.checked ? 'bold' : 'normal';
				Design.paint();
			} );
		}

		if ( board ) {
			Design.drag();
		}
	};

	/**
	 * Dragging a field about, in pointer events.
	 *
	 * Pointer rather than mouse so it works with a pen and a finger, and listened for on the window
	 * so the drag survives the pointer leaving the chip — which it does immediately, because the
	 * chip is smaller than the distance anybody drags.
	 *
	 * Every node is read again after the repaint. Choosing a field repaints the screen, which
	 * replaces the board and the chips with new elements — and a detached element measures zero, so
	 * a drag that kept the old one would divide a distance by a width of nothing and pin the field
	 * to the edge of the page on the first movement.
	 *
	 * Positions are clamped to the page. A field dragged off the edge is not a design decision; it
	 * is a field somebody will never find again.
	 */
	Design.drag = function () {
		each( '[data-field]', function ( chip ) {
			var index = Number( chip.dataset.field );

			chip.addEventListener( 'pointerdown', function ( event ) {
				event.preventDefault();

				Design.picked = index;
				Design.paint();

				var surface = document.getElementById( 'td-board' );
				var live = document.querySelector( '[data-field="' + index + '"]' );
				var field = Design.state.fields[ index ];

				if ( ! surface || ! live || ! field ) {
					return;
				}

				var rect = surface.getBoundingClientRect();

				// A board with no width is a board that is not on the screen yet. Nothing can be
				// dragged across it, and the arithmetic below would be a division by zero.
				if ( ! rect.width || ! rect.height ) {
					return;
				}

				var grabX = event.clientX - ( rect.left + ( field.x / 100 ) * rect.width );
				var grabY = event.clientY - ( rect.top + ( field.y / 100 ) * rect.height );

				var move = function ( moved ) {
					// The far edge stays on the page, not just the near one: a field is as wide as
					// its text box, and stopping its left edge at the margin would leave the words
					// printing into the air off the right of the ticket.
					field.x = clamp( ( ( moved.clientX - grabX - rect.left ) / rect.width ) * 100,
						100 - ( field.width || 0 ) );
					field.y = clamp( ( ( moved.clientY - grabY - rect.top ) / rect.height ) * 100 );

					// Moved directly rather than by repainting the board: a repaint per pointer
					// event throws away the node the drag is attached to.
					live.style.insetInlineStart = field.x + '%';
					live.style.insetBlockStart = field.y + '%';
				};

				var up = function () {
					window.removeEventListener( 'pointermove', move );
					window.removeEventListener( 'pointerup', up );
				};

				window.addEventListener( 'pointermove', move );
				window.addEventListener( 'pointerup', up );
			} );
		} );
	};

	Design.save = function () {
		var App = Design.App;

		App.request( 'PUT', '/events/' + Design.eventId + '/ticket-design', Design.state )
			.then( function ( answer ) {
				Design.state = answer.design;
				Design.stored = true;
				App.toast( App.t( 'panel.ticketDesign.saved' ) );
				Design.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/**
	 * Back to the ticket the platform prints.
	 *
	 * The board is left showing the starter layout rather than emptied, because what somebody who
	 * presses this wants is the standard ticket — and an empty board would read as though the night
	 * now prints a blank page.
	 */
	Design.clear = function () {
		var App = Design.App;

		App.request( 'DELETE', '/events/' + Design.eventId + '/ticket-design' )
			.then( function () {
				Design.stored = false;
				Design.picked = null;
				Design.state = Design.platform();

				App.toast( App.t( 'panel.ticketDesign.cleared' ) );
				Design.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/**
	 * The real document, in a new tab.
	 *
	 * Saved first, deliberately. A preview of unsaved changes would need the whole design posted to
	 * a second endpoint that renders without storing — two paths to the same document, one of which
	 * is exercised only by a button. Saving and then printing means the thing previewed is the
	 * thing that will be sent to a buyer.
	 */
	Design.preview = function () {
		var App = Design.App;

		App.request( 'PUT', '/events/' + Design.eventId + '/ticket-design', Design.state )
			.then( function ( answer ) {
				Design.state = answer.design;
				Design.stored = true;

				return App.request( 'GET', '/events/' + Design.eventId + '/ticket-design/preview', null, {
					raw: true,
				} );
			} )
			.then( function ( blob ) {
				var url = URL.createObjectURL( blob );

				window.open( url, '_blank', 'noopener' );
				// Released on the next turn: revoking immediately races the tab that is opening it.
				window.setTimeout( function () { URL.revokeObjectURL( url ); }, 60000 );
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function number( id, label, value, min, max, step ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' + esc( label ) +
			'</label><input class="input" id="' + id + '" type="number" value="' + esc( value ) +
			'" min="' + min + '" max="' + max + '" step="' + step + '"></div>';
	}

	function clamp( value, ceiling ) {
		var top = undefined === ceiling ? 100 : Math.max( 0, ceiling );

		return Math.round( Math.max( 0, Math.min( top, value ) ) * 10 ) / 10;
	}

	function on( selector, handler ) {
		var element = document.querySelector( selector );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function change( selector, handler ) {
		var element = document.querySelector( selector );

		if ( element ) {
			element.addEventListener( 'change', function () { handler( element.value ); } );
		}
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapTicketDesign = Design;
}( typeof window !== 'undefined' ? window : globalThis ) );
