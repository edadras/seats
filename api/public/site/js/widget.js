/* Generated from shared/seat-picker — edit that, then run tools/sync-seat-picker.sh. */
/**
 * Seat selection widget.
 *
 * Renders the seating plan on a canvas (a DOM node per seat does not survive 20,000 of them), but
 * keeps a parallel, focusable list of seats for keyboard and screen-reader users. The canvas is
 * marked aria-hidden so assistive technology reads the list, not the picture.
 *
 * The widget never decides a price. It shows what the API returned and sends seat ids to the store,
 * which asks the API again and puts the server's answer in the cart.
 */
( function () {
	'use strict';

	var SEAT_SIZE = 18;
	var SEAT_RADIUS = SEAT_SIZE / 2;
	var POLL_INTERVAL = 15000;

	function boot( config ) {
		var container = document.getElementById( config.containerId );

		if ( container ) {
			new SeatmapWidget( container, config ).init();
		}
	}

	function SeatmapWidget( container, config ) {
		this.container = container;
		this.config = config;
		this.i18n = config.i18n;
		this.geometry = config.geometry || { floors: [] };
		this.seats = [];
		this.seatsById = {};
		this.sections = [];
		this.areas = [];
		this.tables = [];
		this.decorations = [];
		this.floors = [];
		this.floorKey = null;
		this.availability = {};
		this.selected = [];
		this.cursor = null;
		this.view = { scale: 1, x: 0, y: 0 };
		this.maxSeats = config.event.max_seats_per_order || 10;
		this.busy = false;
	}

	SeatmapWidget.prototype.init = function () {
		this.flattenSeats();
		this.render();
		this.renderAreaList();
		this.fetchAvailability();
		this.startPolling();
	};

	/**
	 * Walk the chart once and build the two things everything else reads: a flat list of seats with
	 * their computed positions, and a list of capacity areas.
	 *
	 * Seat positions are computed here exactly as the designer computes them — a row stores an
	 * anchor, a rotation, a curve and a spacing, not a coordinate per chair.
	 */
	SeatmapWidget.prototype.flattenSeats = function () {
		var self = this;
		var floors = this.geometry.floors || [];

		this.floors = floors;
		this.floorKey = this.floorKey || ( floors[ 0 ] && floors[ 0 ].key );

		floors.forEach( function ( floor ) {
			self.walkObjects( floor.objects || [], null, floor.key );
		} );
	};

	SeatmapWidget.prototype.walkObjects = function ( objects, section, floorKey ) {
		var self = this;

		objects.forEach( function ( object ) {
			switch ( object.type ) {
				case 'section':
					self.sections.push( object );
					self.walkObjects( object.objects || [], object, floorKey );
					break;

				case 'row':
					self.collectSeats( object, seatPositions( object ), section, floorKey );
					break;

				case 'table':
					// A table sold whole is a single bookable place, not a set of chairs.
					if ( 'table' === object.bookAs ) {
						self.areas.push( self.describeArea( object, section, floorKey ) );
					} else {
						self.collectSeats( object, tableSeatPositions( object ), section, floorKey );
					}

					self.tables.push( { object: object, floorKey: floorKey } );
					break;

				case 'area':
				case 'booth':
					self.areas.push( self.describeArea( object, section, floorKey ) );
					break;

				case 'shape':
				case 'text':
				case 'icon':
					self.decorations.push( { object: object, floorKey: floorKey } );
					break;
			}
		} );
	};

	SeatmapWidget.prototype.collectSeats = function ( owner, positions, section, floorKey ) {
		var self = this;

		( owner.seats || [] ).forEach( function ( seat, index ) {
			if ( 'empty' === seat.type || ! positions[ index ] ) {
				return;
			}

			var record = {
				key: owner.key + '/' + seat.key,
				// Published inside the immutable geometry, so geometry and availability are joined
				// on a stable id rather than on array position.
				id: seat.seat_id || null,
				section: section ? labelOf( section ) : '',
				row: labelOf( owner ) || '',
				label: seat.label,
				x: positions[ index ].x,
				y: positions[ index ].y,
				zoneKey: seat.categoryKey || owner.categoryKey || null,
				accessible: !! seat.accessible,
				floorKey: floorKey,
				state: 'available',
				amount: null,
			};

			self.seats.push( record );

			if ( record.id ) {
				self.seatsById[ record.id ] = record;
			}
		} );
	};

	SeatmapWidget.prototype.describeArea = function ( object, section, floorKey ) {
		return {
			id: object.capacity_object_id || null,
			key: object.key,
			label: labelOf( object ) || '',
			section: section ? labelOf( section ) : '',
			kind: object.type,
			shape: object.shape,
			x: object.x,
			y: object.y,
			width: object.width,
			height: object.height,
			zoneKey: object.categoryKey || null,
			floorKey: floorKey,
			places: 0,
			remaining: 0,
			amount: null,
			quantity: 0,
		};
	};

	/** Row seat positions — the same maths the designer and the publisher use. */
	function seatPositions( row ) {
		var count = ( row.seats || [] ).length;

		if ( 0 === count ) {
			return [];
		}

		var pitch = SEAT_SIZE + ( Number( row.seatSpacing ) || 0 );
		var chord = ( count - 1 ) * pitch;
		var theta = ( ( Number( row.rotation ) || 0 ) * Math.PI ) / 180;
		var cos = Math.cos( theta );
		var sin = Math.sin( theta );
		var curve = Number( row.curve ) || 0;
		var local = [];
		var i;

		if ( 0 === curve || 1 === count ) {
			for ( i = 0; i < count; i++ ) {
				local.push( { x: i * pitch - chord / 2, y: 0 } );
			}
		} else {
			// An arc through both ends, with the sagitta given as a percentage of the chord. Seats
			// are spread along the arc so neighbours stay evenly spaced.
			var h = ( curve / 100 ) * chord;
			var radius = Math.abs( h ) / 2 + ( chord * chord ) / ( 8 * Math.abs( h ) );
			var sweep = 2 * Math.asin( Math.min( 1, chord / ( 2 * radius ) ) );
			var sign = h < 0 ? -1 : 1;

			for ( i = 0; i < count; i++ ) {
				var angle = -sweep / 2 + ( sweep * i ) / ( count - 1 );

				local.push( {
					x: radius * Math.sin( angle ),
					y: sign * ( radius * Math.cos( angle ) - radius * Math.cos( sweep / 2 ) ),
				} );
			}
		}

		return local.map( function ( point ) {
			return {
				x: row.x + point.x * cos - point.y * sin,
				y: row.y + point.x * sin + point.y * cos,
			};
		} );
	}

	function tableSeatPositions( table ) {
		var count = ( table.seats || [] ).length;

		if ( 0 === count ) {
			return [];
		}

		var margin = SEAT_SIZE * 0.9;
		var radius = Math.max( table.width, table.height ) / 2 + margin;
		var theta = ( ( Number( table.rotation ) || 0 ) * Math.PI ) / 180;
		var cos = Math.cos( theta );
		var sin = Math.sin( theta );
		var local = [];

		for ( var i = 0; i < count; i++ ) {
			var angle = ( 2 * Math.PI * i ) / count - Math.PI / 2;
			local.push( { x: radius * Math.cos( angle ), y: radius * Math.sin( angle ) } );
		}

		return local.map( function ( point ) {
			return {
				x: table.x + point.x * cos - point.y * sin,
				y: table.y + point.x * sin + point.y * cos,
			};
		} );
	}

	/**
	 * The picker's icons.
	 *
	 * Inline SVG rather than characters: `+`, `−` and `⟲` are a different weight in every theme's
	 * font, and `⟲` is missing from some of them entirely. These take the colour of the button
	 * they sit in and are the same shape everywhere.
	 */
	var ICONS = {
		plus: '<path d="M12 5v14M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		reset: '<path d="M4 9a8 8 0 1 1 .6 6"/><path d="M3.5 4v5h5"/>',
	};

	function iconMarkup( name ) {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
			'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			ICONS[ name ] + '</svg>';
	}

	/** A round icon button: zoom, reset and the quantity steppers are all the same control. */
	function iconButton( name, label, onClick ) {
		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'seatmap-widget__icon-button';
		button.innerHTML = iconMarkup( name );
		button.setAttribute( 'aria-label', label );
		button.addEventListener( 'click', onClick );

		return button;
	}

	function labelOf( object ) {
		if ( object.labeling ) {
			return object.labeling.displayedLabel || object.labeling.label || '';
		}

		return object.label || '';
	}

	SeatmapWidget.prototype.render = function () {
		this.container.innerHTML = '';
		this.container.setAttribute( 'dir', this.config.isRtl ? 'rtl' : 'ltr' );

		var heading = document.createElement( 'h2' );
		heading.className = 'seatmap-widget__title';
		heading.textContent = this.i18n.selectSeats;
		this.container.appendChild( heading );

		/*
		 * Two columns that decide for themselves whether they fit.
		 *
		 * A media query would be wrong here: the picker is dropped into whatever column a theme
		 * gives it, which is routinely 40rem wide on a 1600px screen. These are flex items with a
		 * stated ideal width, so they sit side by side when there is room for both and stack when
		 * there is not — measured against the space the picker actually has.
		 */
		var layout = document.createElement( 'div' );
		layout.className = 'seatmap-widget__layout';

		var main = document.createElement( 'div' );
		main.className = 'seatmap-widget__main';

		var side = document.createElement( 'div' );
		side.className = 'seatmap-widget__side';

		main.appendChild( this.buildLegend() );

		var stage = document.createElement( 'div' );
		stage.className = 'seatmap-widget__stage';

		this.canvas = document.createElement( 'canvas' );
		this.canvas.className = 'seatmap-widget__canvas';
		// The canvas is decorative: the accessible interface is the seat list below it.
		this.canvas.setAttribute( 'aria-hidden', 'true' );
		stage.appendChild( this.canvas );
		stage.appendChild( this.buildZoomControls() );

		var floors = this.buildFloorSwitcher();

		if ( floors ) {
			stage.appendChild( floors );
		}

		main.appendChild( stage );
		main.appendChild( this.buildAreaList() );
		main.appendChild( this.buildSeatList() );
		side.appendChild( this.buildSummary() );

		layout.appendChild( main );
		layout.appendChild( side );
		this.container.appendChild( layout );

		this.bindCanvasEvents();
		this.resize();

		var self = this;
		window.addEventListener( 'resize', function () {
			self.resize();
		} );

		// The plan is drawn, not styled, so a change of system theme has to be repainted by hand.
		var scheme = window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' );

		if ( scheme && scheme.addEventListener ) {
			scheme.addEventListener( 'change', function () { self.paint(); } );
		}
	};

	SeatmapWidget.prototype.buildLegend = function () {
		var legend = document.createElement( 'ul' );
		legend.className = 'seatmap-widget__legend';

		var zones = this.config.event.zones || [];
		var self = this;

		zones.forEach( function ( zone ) {
			var item = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'seatmap-widget__swatch';
			swatch.style.background = zone.color || '#2d6cdf';
			item.appendChild( swatch );
			item.appendChild( document.createTextNode( zone.name + ' · ' + self.formatMoney( zone.amount ) ) );
			legend.appendChild( item );
		} );

		[ 'selected', 'unavailable' ].forEach( function ( state ) {
			var item = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'seatmap-widget__swatch seatmap-widget__swatch--' + state;
			item.appendChild( swatch );
			item.appendChild( document.createTextNode( self.i18n[ state ] ) );
			legend.appendChild( item );
		} );

		return legend;
	};

	SeatmapWidget.prototype.buildZoomControls = function () {
		var wrap = document.createElement( 'div' );
		wrap.className = 'seatmap-widget__float seatmap-widget__zoom';

		var self = this;

		wrap.appendChild( iconButton( 'plus', this.i18n.zoomIn, function () { self.zoomBy( 1.25 ); } ) );
		wrap.appendChild( iconButton( 'minus', this.i18n.zoomOut, function () { self.zoomBy( 0.8 ); } ) );
		wrap.appendChild( iconButton( 'reset', this.i18n.resetView, function () { self.resetView(); } ) );

		return wrap;
	};

	/**
	 * The floor switcher.
	 *
	 * A multi-floor chart used to show the buyer only whichever floor happened to be first, with
	 * no way to reach the others — the balcony was simply unsellable. Built only when there is
	 * more than one floor, so a single-floor venue gets no dead furniture.
	 */
	SeatmapWidget.prototype.buildFloorSwitcher = function () {
		var self = this;
		var floors = this.floors || [];

		if ( floors.length < 2 ) {
			return null;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'seatmap-widget__float seatmap-widget__floors';
		wrap.setAttribute( 'role', 'group' );
		wrap.setAttribute( 'aria-label', this.i18n.floors );

		this.floorButtons = [];

		floors.forEach( function ( floor ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'seatmap-widget__floor';
			button.textContent = floor.key;
			button.setAttribute( 'aria-label', floor.name || floor.key );
			button.setAttribute( 'aria-pressed', floor.key === self.floorKey ? 'true' : 'false' );
			button.addEventListener( 'click', function () { self.setFloor( floor.key ); } );

			self.floorButtons.push( { key: floor.key, button: button } );
			wrap.appendChild( button );
		} );

		return wrap;
	};

	SeatmapWidget.prototype.setFloor = function ( key ) {
		if ( key === this.floorKey ) {
			return;
		}

		this.floorKey = key;

		( this.floorButtons || [] ).forEach( function ( entry ) {
			entry.button.setAttribute( 'aria-pressed', entry.key === key ? 'true' : 'false' );
		} );

		// A selection made on another floor stays in the basket — only the view moves.
		this.resetView();
		this.renderAreaList();
		this.renderSeatList();
	};

	/**
	 * The real interactive control.
	 *
	 * Every seat is a button with a full text label, so tabbing through the plan and hearing
	 * "Stalls, row A, seat 12 — £35.00" works without ever seeing the canvas.
	 */
	SeatmapWidget.prototype.buildSeatList = function () {
		var list = document.createElement( 'div' );
		list.className = 'seatmap-widget__seats';
		list.setAttribute( 'role', 'group' );
		list.setAttribute( 'aria-label', this.i18n.selectSeats );

		this.seatListEl = list;

		return list;
	};

	/**
	 * Standing room is chosen by quantity, so it gets steppers rather than a seat to click. They
	 * are real buttons with labels, which is also what makes them work by keyboard.
	 */
	SeatmapWidget.prototype.buildAreaList = function () {
		var list = document.createElement( 'div' );
		list.className = 'seatmap-widget__areas';
		this.areaListEl = list;

		return list;
	};

	SeatmapWidget.prototype.renderAreaList = function () {
		var self = this;

		if ( ! this.areaListEl ) {
			return;
		}

		var areas = this.areas.filter( function ( area ) {
			return area.floorKey === self.floorKey && area.id;
		} );

		this.areaListEl.innerHTML = '';
		this.areaListEl.hidden = 0 === areas.length;

		if ( ! areas.length ) {
			return;
		}

		var heading = document.createElement( 'h3' );
		heading.textContent = this.i18n.standingAreas;
		this.areaListEl.appendChild( heading );

		areas.forEach( function ( area ) {
			var row = document.createElement( 'div' );
			row.className = 'seatmap-widget__area';

			var name = document.createElement( 'span' );
			name.className = 'seatmap-widget__area-name';
			name.textContent = area.label +
				( area.amount != null ? ' — ' + self.formatMoney( area.amount ) : '' );
			row.appendChild( name );

			var left = document.createElement( 'span' );
			left.className = 'seatmap-widget__area-left';
			left.textContent = area.remaining > 0
				? self.i18n.placesLeft.replace( '%d', area.remaining )
				: self.i18n.soldOut;
			row.appendChild( left );

			var stepper = document.createElement( 'div' );
			stepper.className = 'seatmap-widget__stepper';

			var minus = iconButton( 'minus', self.i18n.removeOne.replace( '%s', area.label ),
				function () { self.changeAreaQuantity( area, -1 ); } );
			minus.disabled = area.quantity <= 0;

			var count = document.createElement( 'output' );
			count.textContent = String( area.quantity );
			count.setAttribute( 'aria-live', 'polite' );

			var plus = iconButton( 'plus', self.i18n.addOne.replace( '%s', area.label ),
				function () { self.changeAreaQuantity( area, 1 ); } );
			plus.disabled = area.quantity >= area.remaining || area.quantity >= self.maxSeats;

			stepper.appendChild( minus );
			stepper.appendChild( count );
			stepper.appendChild( plus );
			row.appendChild( stepper );

			self.areaListEl.appendChild( row );
		} );
	};

	SeatmapWidget.prototype.changeAreaQuantity = function ( area, delta ) {
		var next = area.quantity + delta;

		if ( next < 0 || next > area.remaining ) {
			return;
		}

		// Seats and standing places share the per-order limit, so the two have to be counted
		// together rather than each against the cap on its own.
		if ( delta > 0 && this.totalChosen() >= this.maxSeats ) {
			this.announce( this.i18n.maxSeats.replace( '%d', this.maxSeats ) );

			return;
		}

		area.quantity = next;
		this.paint();
		this.renderAreaList();
		this.renderSelection();
	};

	SeatmapWidget.prototype.totalChosen = function () {
		return this.selected.length + this.areas.reduce( function ( total, area ) {
			return total + area.quantity;
		}, 0 );
	};

	SeatmapWidget.prototype.buildSummary = function () {
		var summary = document.createElement( 'div' );
		summary.className = 'seatmap-widget__summary';

		var heading = document.createElement( 'h3' );
		heading.textContent = this.i18n.yourSelection;
		summary.appendChild( heading );

		this.selectionEl = document.createElement( 'ul' );
		this.selectionEl.className = 'seatmap-widget__selection';
		summary.appendChild( this.selectionEl );

		this.totalEl = document.createElement( 'p' );
		this.totalEl.className = 'seatmap-widget__total';
		summary.appendChild( this.totalEl );

		this.messageEl = document.createElement( 'p' );
		this.messageEl.className = 'seatmap-widget__message';
		// Selection changes and errors are announced without stealing focus.
		this.messageEl.setAttribute( 'role', 'status' );
		this.messageEl.setAttribute( 'aria-live', 'polite' );
		summary.appendChild( this.messageEl );

		var self = this;
		this.submitEl = document.createElement( 'button' );
		this.submitEl.type = 'button';
		this.submitEl.className = 'seatmap-widget__submit button';
		this.submitEl.textContent = this.i18n.addToCart;
		this.submitEl.disabled = true;
		this.submitEl.addEventListener( 'click', function () {
			self.reserve();
		} );
		summary.appendChild( this.submitEl );

		return summary;
	};

	SeatmapWidget.prototype.fetchAvailability = function () {
		var self = this;
		var url = this.config.restUrl + '/availability/' + encodeURIComponent( this.config.eventPublicId );

		if ( this.cursor ) {
			url += '?since=' + encodeURIComponent( this.cursor );
		}

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}

				// An unchanged cursor comes back with empty lists; leave the map alone.
				if ( ( data.seats || [] ).length ) {
					self.applyAvailability( data.seats );
				}

				if ( ( data.areas || [] ).length ) {
					self.applyAreaAvailability( data.areas );
				}

				self.cursor = data.cursor;
			} )
			.catch( function () {
				// A failed poll is not worth interrupting the buyer over; the next one may work,
				// and the hold request is authoritative anyway.
			} );
	};

	SeatmapWidget.prototype.applyAvailability = function ( seats ) {
		var self = this;

		seats.forEach( function ( update ) {
			var seat = self.seatsById[ update.seat_id ];

			if ( ! seat ) {
				return;
			}

			// A seat this browser is holding reads as `held` from the API too; keep showing it as
			// the buyer's own selection rather than greying out their own choice.
			seat.state = self.isSelected( seat ) ? 'selected' : update.state;
			seat.amount = update.amount;
			seat.zoneKey = update.zone_key || seat.zoneKey;
		} );

		this.paint();
		this.renderSeatList();
		this.renderSelection();
	};

	SeatmapWidget.prototype.applyAreaAvailability = function ( areas ) {
		var self = this;
		var byId = {};

		areas.forEach( function ( area ) {
			byId[ area.capacity_object_id ] = area;
		} );

		this.areas.forEach( function ( area ) {
			var update = area.id ? byId[ area.id ] : null;

			if ( ! update ) {
				return;
			}

			area.places = update.places;
			area.remaining = update.remaining;
			area.amount = update.amount;
			area.capacityType = update.capacity_type;

			// Never offer more than is left, and drop a chosen quantity that has been taken while
			// the buyer was deciding.
			if ( area.quantity > area.remaining ) {
				area.quantity = area.remaining;
			}
		} );

		this.paint();
		this.renderAreaList();
		this.renderSelection();
	};

	SeatmapWidget.prototype.startPolling = function () {
		var self = this;

		this.pollTimer = window.setInterval( function () {
			if ( ! document.hidden ) {
				self.fetchAvailability();
			}
		}, POLL_INTERVAL );
	};

	SeatmapWidget.prototype.canvasSize = function () {
		var floor = ( this.floors || [] ).filter( function ( entry ) {
			return entry.key === this.floorKey;
		}, this )[ 0 ] || this.floors[ 0 ];

		return ( floor && floor.canvas ) || { width: 1000, height: 800 };
	};

	SeatmapWidget.prototype.resize = function () {
		// Measured from the plan's own box, not the whole picker: on a wide screen the summary
		// sits alongside, and sizing to the container drew a canvas wider than the space for it.
		var host = this.canvas.parentNode;
		var width = ( host && host.clientWidth ) || this.container.clientWidth || 800;
		var size = this.canvasSize();
		var geometryWidth = size.width || 1000;
		var geometryHeight = size.height || 800;
		var ratio = geometryHeight / geometryWidth;

		var height = Math.min( Math.max( width * ratio, 320 ), 720 );
		var dpr = window.devicePixelRatio || 1;

		this.canvas.width = width * dpr;
		this.canvas.height = height * dpr;
		this.canvas.style.width = width + 'px';
		this.canvas.style.height = height + 'px';

		this.baseScale = Math.min( width / geometryWidth, height / geometryHeight );
		this.dpr = dpr;

		this.paint();
	};

	/**
	 * The canvas palette.
	 *
	 * A canvas has no cascade, so the two themes are written out here. The picker follows the
	 * reader's system preference rather than a theme class, because it is a guest inside someone
	 * else's stylesheet and cannot assume one exists.
	 */
	var PALETTES = {
		light: {
			ink: '#1b2030',
			text: '#3d4457',
			muted: '#6f7891',
			seatEdge: 'rgba(27,32,48,0.2)',
			seatTaken: '#d3d6dc',
			shapeEdge: 'rgba(27,32,48,0.25)',
			shapeLabel: '#ffffff',
			tableFill: 'rgba(27,32,48,0.06)',
			areaSoldOut: 'rgba(27,32,48,0.08)',
			areaSoldOutEdge: '#c7cddb',
			shapes: {
				stage: '#3d4457',
				entrance: '#2f8f63',
				exit: '#b3543a',
				aisle: '#e8eaee',
				wall: '#9aa3b7',
				fallback: '#c8ccd4',
			},
		},

		dark: {
			ink: '#e9ecf3',
			text: '#c3cad9',
			muted: '#838ca3',
			seatEdge: 'rgba(9,11,16,0.45)',
			seatTaken: '#394052',
			shapeEdge: 'rgba(233,236,243,0.22)',
			shapeLabel: '#e9ecf3',
			tableFill: 'rgba(233,236,243,0.07)',
			areaSoldOut: 'rgba(233,236,243,0.06)',
			areaSoldOutEdge: '#3b4256',
			shapes: {
				stage: '#394054',
				entrance: '#2c6f52',
				exit: '#8b453a',
				aisle: '#262c3c',
				wall: '#4a5266',
				fallback: '#3a4155',
			},
		},
	};

	SeatmapWidget.prototype.colours = function () {
		var query = window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' );

		return query && query.matches ? PALETTES.dark : PALETTES.light;
	};

	SeatmapWidget.prototype.paint = function () {
		if ( ! this.canvas ) {
			return;
		}

		var ctx = this.canvas.getContext( '2d' );
		var scale = this.baseScale * this.view.scale;

		ctx.setTransform( this.dpr, 0, 0, this.dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.canvas.width, this.canvas.height );
		ctx.save();
		ctx.translate( this.view.x, this.view.y );
		ctx.scale( scale, scale );

		this.paintDecorations( ctx );
		this.paintAreas( ctx );
		this.paintTables( ctx );
		this.paintSeats( ctx, scale );

		ctx.restore();
	};

	SeatmapWidget.prototype.paintDecorations = function ( ctx ) {
		var self = this;
		var colours = this.colours();

		this.decorations.filter( function ( entry ) {
			return entry.floorKey === self.floorKey;
		} ).forEach( function ( entry ) {
			var object = entry.object;

			if ( 'text' === object.type ) {
				ctx.save();
				ctx.fillStyle = object.color || colours.text;
				ctx.font = '500 ' + ( object.fontSize || 16 ) + 'px system-ui, sans-serif';
				ctx.fillText( object.text, object.x, object.y );
				ctx.restore();

				return;
			}

			if ( 'icon' === object.type ) {
				self.paintMarker( ctx, object, colours );

				return;
			}

			self.paintShape( ctx, object );
		} );
	};

	/**
	 * Venue markers, drawn from the same vector paths the designer uses.
	 *
	 * These were emoji, which meant the buyer saw a different symbol from the one the venue drew,
	 * at a different weight and colour on every device.
	 */
	var MARKERS = {
		wheelchair: 'M13.9 4.8a1.9 1.9 0 1 1-3.8 0 1.9 1.9 0 1 1 3.8 0M8 8.6h8M12 8.6v5h4.5M12 13.6 9.5 20M16.5 13.6 19 20',
		toilets: 'M8.6 5.4a1.4 1.4 0 1 1-2.8 0 1.4 1.4 0 1 1 2.8 0M7.2 8.4v5.8M5.7 9.8h3M6.1 14.2V20M8.3 14.2V20M17.7 5.4a1.4 1.4 0 1 1-2.8 0 1.4 1.4 0 1 1 2.8 0M16.3 8.4 14.1 14.6h4.4L16.3 8.4M15.3 14.6V20M17.3 14.6V20',
		bar: 'M4.5 5h15l-7.5 7.5zM12 12.5V19M8.5 19h7',
		food: 'M8 4v6.5M11 4v6.5M9.5 4v6.5M9.5 10.5V20M16.5 4c-1.4 1.4-2.1 3.1-2.1 5s.7 3.1 2.1 3.5V20',
		entrance: 'M13.5 4H19a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5.5M9.5 8l4 4-4 4M13.5 12H4',
		exit: 'M10.5 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5.5M14.5 8l4 4-4 4M18.5 12H8',
		stairs: 'M3.5 20h4.5v-4h4.5v-4H17V7.5h3.5',
		lift: 'M5 3.5h14a1.5 1.5 0 0 1 1.5 1.5v14a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V5A1.5 1.5 0 0 1 5 3.5ZM9.5 10.5 12 7l2.5 3.5M9.5 13.5 12 17l2.5-3.5',
	};

	SeatmapWidget.prototype.paintMarker = function ( ctx, marker, colours ) {
		var size = marker.size || 22;
		var data = MARKERS[ marker.name ];

		ctx.save();
		ctx.strokeStyle = colours.muted;
		ctx.fillStyle = colours.muted;

		if ( data && window.Path2D ) {
			// The paths are drawn on a 24-unit grid, so scale to the marker and keep the stroke
			// weight constant in that space rather than in chart units.
			ctx.translate( marker.x - size / 2, marker.y - size / 2 );
			ctx.scale( size / 24, size / 24 );
			ctx.lineWidth = 1.75;
			ctx.lineCap = 'round';
			ctx.lineJoin = 'round';
			ctx.stroke( new window.Path2D( data ) );
		} else {
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.font = '600 ' + Math.round( size * 0.7 ) + 'px system-ui, sans-serif';
			ctx.fillText( String( marker.name || '?' ).charAt( 0 ).toUpperCase(), marker.x, marker.y );
		}

		ctx.restore();
	};

	SeatmapWidget.prototype.paintShape = function ( ctx, shape ) {
		var self = this;
		var colours = this.colours();

		[ shape ].forEach( function ( shape ) {
			ctx.save();
			ctx.fillStyle = shape.fill || self.shapeColour( shape.kind );
			ctx.strokeStyle = colours.shapeEdge;

			if ( 'polygon' === shape.kind && shape.points ) {
				ctx.beginPath();
				for ( var i = 0; i < shape.points.length; i += 2 ) {
					if ( 0 === i ) {
						ctx.moveTo( shape.points[ i ], shape.points[ i + 1 ] );
					} else {
						ctx.lineTo( shape.points[ i ], shape.points[ i + 1 ] );
					}
				}
				ctx.closePath();
				ctx.fill();
			} else if ( 'ellipse' === shape.kind ) {
				ctx.beginPath();
				ctx.ellipse(
					shape.x + ( shape.width || 0 ) / 2,
					shape.y + ( shape.height || 0 ) / 2,
					( shape.width || 0 ) / 2,
					( shape.height || 0 ) / 2,
					0, 0, Math.PI * 2
				);
				ctx.fill();
			} else {
				ctx.fillRect( shape.x, shape.y, shape.width || 0, shape.height || 0 );
			}

			var label = shape.label || ( 'stage' === shape.kind ? self.i18n.stage : '' );

			if ( label ) {
				ctx.fillStyle = colours.shapeLabel;
				ctx.font = '600 16px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText(
					label,
					shape.x + ( shape.width || 0 ) / 2,
					shape.y + ( shape.height || 0 ) / 2
				);
			}

			ctx.restore();
		} );
	};

	/**
	 * A capacity area is drawn with its remaining places written on it, because "how many are
	 * left" is the only question a buyer can ask of standing room — there is no seat to click.
	 */
	SeatmapWidget.prototype.paintAreas = function ( ctx ) {
		var self = this;
		var colours = this.colours();

		this.areas.filter( function ( area ) {
			return area.floorKey === self.floorKey;
		} ).forEach( function ( area ) {
			var colour = self.zoneColour( area.zoneKey );
			var soldOut = area.remaining <= 0;
			var chosen = area.quantity > 0;
			var box = areaBox( area );

			ctx.save();
			ctx.beginPath();

			if ( 'ellipse' === ( area.shape && area.shape.kind ) ) {
				ctx.ellipse( box.x + box.width / 2, box.y + box.height / 2, box.width / 2, box.height / 2, 0, 0, Math.PI * 2 );
			} else if ( ctx.roundRect ) {
				ctx.roundRect( box.x, box.y, box.width, box.height, ( area.shape && area.shape.cornerRadius ) || 8 );
			} else {
				ctx.rect( box.x, box.y, box.width, box.height );
			}

			ctx.fillStyle = soldOut ? colours.areaSoldOut : withAlpha( colour, chosen ? 0.55 : 0.25 );
			ctx.fill();
			ctx.strokeStyle = chosen ? colours.ink : soldOut ? colours.areaSoldOutEdge : colour;
			ctx.lineWidth = chosen ? 3 : 1.5;
			ctx.stroke();

			ctx.fillStyle = soldOut ? colours.muted : colours.ink;
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.font = '600 15px system-ui, sans-serif';
			ctx.fillText( area.label, box.x + box.width / 2, box.y + box.height / 2 - 8 );

			ctx.font = '12px system-ui, sans-serif';
			ctx.fillText(
				soldOut
					? self.i18n.soldOut
					: self.i18n.placesLeft.replace( '%d', area.remaining ) +
						( area.amount != null ? ' · ' + self.formatMoney( area.amount ) : '' ),
				box.x + box.width / 2,
				box.y + box.height / 2 + 10
			);

			ctx.restore();
		} );
	};

	function areaBox( area ) {
		if ( area.shape && area.shape.width != null ) {
			return { x: area.shape.x, y: area.shape.y, width: area.shape.width, height: area.shape.height };
		}

		// A whole table has a centre and a size rather than a shape object.
		return {
			x: area.x - ( area.width || 0 ) / 2,
			y: area.y - ( area.height || 0 ) / 2,
			width: area.width || 0,
			height: area.height || 0,
		};
	}

	/** The table itself, under its chairs. Chairs are painted with the other seats. */
	SeatmapWidget.prototype.paintTables = function ( ctx ) {
		var self = this;
		var colours = this.colours();

		this.tables.filter( function ( entry ) {
			return entry.floorKey === self.floorKey && 'table' !== entry.object.bookAs;
		} ).forEach( function ( entry ) {
			var table = entry.object;

			ctx.save();
			ctx.translate( table.x, table.y );
			ctx.rotate( ( ( table.rotation || 0 ) * Math.PI ) / 180 );
			ctx.fillStyle = colours.tableFill;
			ctx.beginPath();

			if ( 'round' === table.shape ) {
				ctx.ellipse( 0, 0, table.width / 2, table.height / 2, 0, 0, Math.PI * 2 );
			} else {
				ctx.rect( -table.width / 2, -table.height / 2, table.width, table.height );
			}

			ctx.fill();
			ctx.restore();
		} );
	};

	SeatmapWidget.prototype.shapeColour = function ( kind ) {
		var shapes = this.colours().shapes;

		return shapes[ kind ] || shapes.fallback;
	};

	SeatmapWidget.prototype.paintSeats = function ( ctx, scale ) {
		var self = this;
		var colours = this.colours();

		this.seats.filter( function ( seat ) {
			return seat.floorKey === self.floorKey;
		} ).forEach( function ( seat ) {
			ctx.beginPath();
			ctx.fillStyle = self.seatColour( seat );
			ctx.strokeStyle = 'selected' === seat.state ? colours.ink : colours.seatEdge;
			ctx.lineWidth = 'selected' === seat.state ? 2.5 / scale : 1 / scale;

			ctx.arc( seat.x, seat.y, SEAT_RADIUS, 0, Math.PI * 2 );
			ctx.fill();
			ctx.stroke();
		} );
	};

	SeatmapWidget.prototype.seatColour = function ( seat ) {
		var colours = this.colours();

		if ( 'selected' === seat.state ) {
			return colours.ink;
		}

		if ( 'available' !== seat.state ) {
			return colours.seatTaken;
		}

		return this.zoneColour( seat.zoneKey );
	};

	SeatmapWidget.prototype.zoneColour = function ( zoneKey ) {
		var zones = this.config.event.zones || [];

		for ( var i = 0; i < zones.length; i++ ) {
			if ( zones[ i ].key === zoneKey ) {
				return zones[ i ].color || '#2d6cdf';
			}
		}

		return '#2d6cdf';
	};

	function withAlpha( colour, alpha ) {
		var hex = String( colour || '#2d6cdf' ).replace( '#', '' );

		if ( 3 === hex.length ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}

		var value = parseInt( hex, 16 );

		if ( isNaN( value ) ) {
			return 'rgba(45,108,223,' + alpha + ')';
		}

		return 'rgba(' + [ ( value >> 16 ) & 255, ( value >> 8 ) & 255, value & 255 ].join( ',' ) + ',' + alpha + ')';
	}

	SeatmapWidget.prototype.bindCanvasEvents = function () {
		var self = this;
		var dragging = false;
		var last = null;
		var moved = 0;

		this.canvas.addEventListener( 'pointerdown', function ( event ) {
			dragging = true;
			moved = 0;
			last = { x: event.clientX, y: event.clientY };
			self.canvas.setPointerCapture( event.pointerId );
		} );

		this.canvas.addEventListener( 'pointermove', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			var dx = event.clientX - last.x;
			var dy = event.clientY - last.y;
			moved += Math.abs( dx ) + Math.abs( dy );

			self.view.x += dx;
			self.view.y += dy;
			last = { x: event.clientX, y: event.clientY };
			self.paint();
		} );

		this.canvas.addEventListener( 'pointerup', function ( event ) {
			dragging = false;

			// A drag is a pan, not a click. Without this threshold, panning the map would
			// select whichever seat happened to be under the finger.
			if ( moved < 5 ) {
				self.handleCanvasClick( event );
			}
		} );

		this.canvas.addEventListener(
			'wheel',
			function ( event ) {
				event.preventDefault();
				self.zoomBy( event.deltaY < 0 ? 1.1 : 0.9, event );
			},
			{ passive: false }
		);
	};

	SeatmapWidget.prototype.handleCanvasClick = function ( event ) {
		var rect = this.canvas.getBoundingClientRect();
		var scale = this.baseScale * this.view.scale;
		var x = ( event.clientX - rect.left - this.view.x ) / scale;
		var y = ( event.clientY - rect.top - this.view.y ) / scale;

		var hit = null;
		var best = SEAT_RADIUS * 1.6;
		var self = this;

		this.seats.forEach( function ( seat ) {
			// Only the floor on screen can be clicked; two floors may occupy the same coordinates.
			if ( seat.floorKey !== self.floorKey ) {
				return;
			}

			var distance = Math.hypot( seat.x - x, seat.y - y );

			if ( distance < best ) {
				best = distance;
				hit = seat;
			}
		} );

		if ( hit ) {
			this.toggleSeat( hit );
		}
	};

	SeatmapWidget.prototype.zoomBy = function ( factor ) {
		this.view.scale = Math.min( 6, Math.max( 0.5, this.view.scale * factor ) );
		this.paint();
	};

	SeatmapWidget.prototype.resetView = function () {
		this.view = { scale: 1, x: 0, y: 0 };
		this.paint();
	};

	SeatmapWidget.prototype.isSelected = function ( seat ) {
		return this.selected.indexOf( seat ) !== -1;
	};

	SeatmapWidget.prototype.toggleSeat = function ( seat ) {
		if ( ! seat.id ) {
			return; // Not placed in the published version; not orderable.
		}

		if ( this.isSelected( seat ) ) {
			this.selected.splice( this.selected.indexOf( seat ), 1 );
			seat.state = 'available';
		} else {
			if ( 'available' !== seat.state ) {
				return;
			}

			if ( this.selected.length >= this.maxSeats ) {
				this.announce( this.i18n.maxSeats.replace( '%d', this.maxSeats ) );

				return;
			}

			this.selected.push( seat );
			seat.state = 'selected';
		}

		this.paint();
		this.renderSeatList();
		this.renderSelection();
	};

	/**
	 * The accessible control, grouped by section and row.
	 *
	 * Always in the document and always reachable by keyboard — hiding it from sighted users would
	 * leave keyboard users navigating an invisible grid.
	 */
	SeatmapWidget.prototype.renderSeatList = function () {
		if ( ! this.seatListEl ) {
			return;
		}

		var self = this;
		var active = document.activeElement;
		var activeKey = active && active.dataset ? active.dataset.seatKey : null;

		this.seatListEl.innerHTML = '';

		// Grouped in the order the seats were published, which is the order they were drawn.
		var groups = [];
		var index = {};

		this.seats.forEach( function ( seat ) {
			if ( seat.floorKey !== self.floorKey ) {
				return;
			}

			var groupKey = seat.section + '|' + seat.row;

			if ( ! index[ groupKey ] ) {
				index[ groupKey ] = { section: seat.section, row: seat.row, seats: [] };
				groups.push( index[ groupKey ] );
			}

			index[ groupKey ].seats.push( seat );
		} );

		var lastSection = null;

		groups.forEach( function ( group ) {
			if ( group.section !== lastSection ) {
				var title = document.createElement( 'h4' );
				title.textContent = group.section || self.i18n.selectSeats;
				self.seatListEl.appendChild( title );
				lastSection = group.section;
			}

			var rowEl = document.createElement( 'div' );
			rowEl.className = 'seatmap-widget__row';

			var rowLabel = document.createElement( 'span' );
			rowLabel.className = 'seatmap-widget__row-label';
			rowLabel.textContent = group.row;
			rowEl.appendChild( rowLabel );

			group.seats.forEach( function ( seat ) {
				rowEl.appendChild( self.buildSeatButton( seat ) );
			} );

			self.seatListEl.appendChild( rowEl );
		} );

		if ( activeKey ) {
			var restored = this.seatListEl.querySelector( '[data-seat-key="' + activeKey + '"]' );

			// Repainting the list must not throw a keyboard user back to the top of the page.
			if ( restored ) {
				restored.focus();
			}
		}
	};

	SeatmapWidget.prototype.buildSeatButton = function ( seat ) {
		var self = this;
		var button = document.createElement( 'button' );
		var unavailable = 'available' !== seat.state && 'selected' !== seat.state;

		button.type = 'button';
		button.className = 'seatmap-widget__seat is-' + seat.state;
		button.dataset.seatKey = seat.key;
		button.textContent = seat.label;
		button.disabled = unavailable;
		button.setAttribute( 'aria-pressed', 'selected' === seat.state ? 'true' : 'false' );

		var template = unavailable ? this.i18n.seatUnavailable : this.i18n.seatLabel;

		button.setAttribute(
			'aria-label',
			template
				.replace( '%1$s', seat.section || '' )
				.replace( '%2$s', seat.row || '' )
				.replace( '%3$s', seat.label )
				.replace( '%4$s', null == seat.amount ? '' : this.formatMoney( seat.amount ) )
		);

		if ( seat.accessible ) {
			button.classList.add( 'is-accessible' );
		}

		button.addEventListener( 'click', function () {
			self.toggleSeat( seat );
		} );

		return button;
	};

	SeatmapWidget.prototype.renderSelection = function () {
		var self = this;

		this.selectionEl.innerHTML = '';

		var chosenAreas = this.areas.filter( function ( area ) { return area.quantity > 0; } );

		if ( ! this.selected.length && ! chosenAreas.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'seatmap-widget__selection-empty';
			empty.textContent = this.i18n.noneSelected;
			this.selectionEl.appendChild( empty );
			this.totalEl.textContent = '';
			this.submitEl.disabled = true;

			return;
		}

		var total = 0;

		// Description on one side, money on the other: a column of prices is read down, not across.
		function line( description, amount ) {
			var item = document.createElement( 'li' );
			var left = document.createElement( 'span' );
			var right = document.createElement( 'span' );

			left.textContent = description;
			right.textContent = amount;
			item.appendChild( left );
			item.appendChild( right );

			return item;
		}

		this.selected.forEach( function ( seat ) {
			total += seat.amount || 0;

			self.selectionEl.appendChild( line(
				[ seat.section, seat.row, seat.label ].filter( Boolean ).join( ' · ' ),
				self.formatMoney( seat.amount )
			) );
		} );

		chosenAreas.forEach( function ( area ) {
			total += ( area.amount || 0 ) * area.quantity;

			self.selectionEl.appendChild( line(
				area.quantity + ' × ' + area.label,
				self.formatMoney( ( area.amount || 0 ) * area.quantity )
			) );
		} );

		this.totalEl.innerHTML = '';
		this.totalEl.appendChild( textSpan( this.i18n.total ) );
		this.totalEl.appendChild( textSpan( this.formatMoney( total ) ) );
		this.submitEl.disabled = false;
	};

	SeatmapWidget.prototype.reserve = function () {
		if ( this.busy || ! this.totalChosen() ) {
			return;
		}

		this.busy = true;
		this.submitEl.disabled = true;
		this.submitEl.textContent = this.i18n.working;

		var self = this;
		var seatIds = this.selected.map( function ( seat ) {
			return seat.id;
		} );

		var areas = {};

		this.areas.forEach( function ( area ) {
			if ( area.quantity > 0 && area.id ) {
				areas[ area.id ] = area.quantity;
			}
		} );

		fetch( this.config.restUrl + '/hold', {
			method: 'POST',
			credentials: 'same-origin',
			// The header is named by whoever booted the widget: WordPress wants X-WP-Nonce, a
			// first-party site wants its own CSRF header. The widget does not care which.
			headers: nonceHeaders( this.config, { 'Content-Type': 'application/json' } ),
			body: JSON.stringify( {
				event_public_id: this.config.eventPublicId,
				seat_ids: seatIds,
				areas: areas,
			} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					window.location.href = result.body.cart_url;

					return;
				}

				self.handleHoldFailure( result.body );
			} )
			.catch( function () {
				self.announce( self.i18n.genericError );
			} )
			.finally( function () {
				self.busy = false;
				self.submitEl.disabled = false;
				self.submitEl.textContent = self.i18n.addToCart;
			} );
	};

	/**
	 * Someone else got there first.
	 *
	 * Drop exactly the seats the API named, keep the rest of the selection, and refresh — the buyer
	 * should not have to start over because one seat went.
	 */
	SeatmapWidget.prototype.handleHoldFailure = function ( body ) {
		var taken = ( body && body.data && body.data.unavailable_seat_ids ) || [];
		var fullAreas = ( body && body.data && body.data.unavailable_capacity_object_ids ) || [];
		var self = this;

		if ( fullAreas.length ) {
			// Someone filled the area while this buyer was deciding. Drop the quantity they can no
			// longer have and let them re-choose, rather than clearing everything.
			this.areas.forEach( function ( area ) {
				if ( fullAreas.indexOf( area.id ) !== -1 ) {
					area.quantity = 0;
				}
			} );

			this.announce( this.i18n.areaFull );
		}

		if ( taken.length ) {
			this.selected = this.selected.filter( function ( seat ) {
				if ( taken.indexOf( seat.id ) === -1 ) {
					return true;
				}

				seat.state = 'allocated';

				return false;
			} );

			this.announce( this.i18n.seatTaken );
		} else {
			this.announce( ( body && body.message ) || this.i18n.genericError );
		}

		this.cursor = null; // Force a full refresh rather than an incremental one.
		this.fetchAvailability();
		this.paint();
		this.renderSeatList();
		this.renderAreaList();
		this.renderSelection();
	};

	function nonceHeaders( config, headers ) {
		if ( config.nonce ) {
			headers[ config.nonceHeader || 'X-WP-Nonce' ] = config.nonce;
		}

		return headers;
	}

	function textSpan( text ) {
		var span = document.createElement( 'span' );

		span.textContent = text;

		return span;
	}

	SeatmapWidget.prototype.announce = function ( message ) {
		this.messageEl.textContent = message;
	};

	/**
	 * Money, in the currency the event charges and the shape the reader reads.
	 *
	 * Two paths, and which one is taken depends on what the host handed us:
	 *
	 * A hosted site sends `locale` and a currency `code`, so the browser's own ICU data does the
	 * work — Persian digits, an Arabic decimal mark, a German comma, and the right number of
	 * decimals for a currency that has none. That last point is not cosmetic: the rial has no
	 * minor unit, and dividing by a hundred understates every Iranian price a hundredfold.
	 *
	 * WooCommerce sends a symbol, a decimal count and a position, because that is what a WordPress
	 * shop knows about itself, and the picker must keep matching the prices printed everywhere
	 * else on that shop. So that path stays exactly as it was.
	 */
	SeatmapWidget.prototype.formatMoney = function ( minorUnits ) {
		if ( null === minorUnits || undefined === minorUnits ) {
			return '';
		}

		var currency = this.config.currency;
		var decimals = 'number' === typeof currency.decimals ? currency.decimals : 2;
		var value = minorUnits / Math.pow( 10, decimals );

		if ( currency.code && this.config.locale && window.Intl && window.Intl.NumberFormat ) {
			try {
				return new Intl.NumberFormat( this.config.locale, {
					style: 'currency',
					currency: currency.code,
					minimumFractionDigits: decimals,
					maximumFractionDigits: decimals
				} ).format( value );
			} catch ( e ) {
				// An unknown currency code, or a browser without the data. Fall through rather
				// than show nothing: a price is the one thing on this screen that must appear.
			}
		}

		var amount = value.toFixed( decimals );

		switch ( currency.position ) {
			case 'right':
				return amount + currency.symbol;
			case 'left_space':
				return currency.symbol + ' ' + amount;
			case 'right_space':
				return amount + ' ' + currency.symbol;
			default:
				return currency.symbol + amount;
		}
	};

	function start() {
		( window.seatmapBoot || [] ).forEach( boot );

		// Anything queued after this point boots immediately.
		window.seatmapBoot = { push: boot };
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
