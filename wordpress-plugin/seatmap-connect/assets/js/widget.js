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

		this.container.appendChild( this.buildLegend() );

		var stage = document.createElement( 'div' );
		stage.className = 'seatmap-widget__stage';

		this.canvas = document.createElement( 'canvas' );
		this.canvas.className = 'seatmap-widget__canvas';
		// The canvas is decorative: the accessible interface is the seat list below it.
		this.canvas.setAttribute( 'aria-hidden', 'true' );
		stage.appendChild( this.canvas );
		stage.appendChild( this.buildZoomControls() );

		this.container.appendChild( stage );
		this.container.appendChild( this.buildAreaList() );
		this.container.appendChild( this.buildSeatList() );
		this.container.appendChild( this.buildSummary() );

		this.bindCanvasEvents();
		this.resize();

		var self = this;
		window.addEventListener( 'resize', function () {
			self.resize();
		} );
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
		wrap.className = 'seatmap-widget__zoom';

		var self = this;
		var buttons = [
			[ '+', this.i18n.zoomIn, function () { self.zoomBy( 1.25 ); } ],
			[ '−', this.i18n.zoomOut, function () { self.zoomBy( 0.8 ); } ],
			[ '⟲', this.i18n.resetView, function () { self.resetView(); } ]
		];

		buttons.forEach( function ( spec ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.textContent = spec[ 0 ];
			button.setAttribute( 'aria-label', spec[ 1 ] );
			button.addEventListener( 'click', spec[ 2 ] );
			wrap.appendChild( button );
		} );

		return wrap;
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

			var minus = document.createElement( 'button' );
			minus.type = 'button';
			minus.textContent = '−';
			minus.disabled = area.quantity <= 0;
			minus.setAttribute( 'aria-label', self.i18n.removeOne.replace( '%s', area.label ) );

			var count = document.createElement( 'output' );
			count.textContent = String( area.quantity );
			count.setAttribute( 'aria-live', 'polite' );

			var plus = document.createElement( 'button' );
			plus.type = 'button';
			plus.textContent = '+';
			plus.disabled = area.quantity >= area.remaining || area.quantity >= self.maxSeats;
			plus.setAttribute( 'aria-label', self.i18n.addOne.replace( '%s', area.label ) );

			minus.addEventListener( 'click', function () { self.changeAreaQuantity( area, -1 ); } );
			plus.addEventListener( 'click', function () { self.changeAreaQuantity( area, 1 ); } );

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
		var width = this.container.clientWidth || 800;
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

		this.decorations.filter( function ( entry ) {
			return entry.floorKey === self.floorKey;
		} ).forEach( function ( entry ) {
			var object = entry.object;

			if ( 'text' === object.type ) {
				ctx.save();
				ctx.fillStyle = object.color || '#3a3f4b';
				ctx.font = '500 ' + ( object.fontSize || 16 ) + 'px system-ui, sans-serif';
				ctx.fillText( object.text, object.x, object.y );
				ctx.restore();

				return;
			}

			if ( 'icon' === object.type ) {
				ctx.save();
				ctx.font = ( object.size || 22 ) + 'px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText( ICONS[ object.name ] || '•', object.x, object.y );
				ctx.restore();

				return;
			}

			self.paintShape( ctx, object );
		} );
	};

	var ICONS = {
		wheelchair: '♿', toilets: '🚻', bar: '🍸', food: '🍴',
		entrance: '⇥', exit: '⇤', stairs: '⌁', lift: '⇕',
	};

	SeatmapWidget.prototype.paintShape = function ( ctx, shape ) {
		var self = this;

		[ shape ].forEach( function ( shape ) {
			ctx.save();
			ctx.fillStyle = shape.fill || self.shapeColour( shape.kind );
			ctx.strokeStyle = 'rgba(0,0,0,0.25)';

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
				ctx.fillStyle = '#ffffff';
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

			ctx.fillStyle = soldOut ? 'rgba(0,0,0,0.08)' : withAlpha( colour, chosen ? 0.55 : 0.25 );
			ctx.fill();
			ctx.strokeStyle = chosen ? '#12263f' : soldOut ? '#b6bcc7' : colour;
			ctx.lineWidth = chosen ? 3 : 1.5;
			ctx.stroke();

			ctx.fillStyle = soldOut ? '#6b7280' : '#1c2129';
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

		this.tables.filter( function ( entry ) {
			return entry.floorKey === self.floorKey && 'table' !== entry.object.bookAs;
		} ).forEach( function ( entry ) {
			var table = entry.object;

			ctx.save();
			ctx.translate( table.x, table.y );
			ctx.rotate( ( ( table.rotation || 0 ) * Math.PI ) / 180 );
			ctx.fillStyle = 'rgba(0,0,0,0.06)';
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
		switch ( kind ) {
			case 'stage':
				return '#3a3f4b';
			case 'entrance':
				return '#3f9c6d';
			case 'exit':
				return '#b3543a';
			case 'wall':
				return '#8a8f99';
			default:
				return '#c8ccd4';
		}
	};

	SeatmapWidget.prototype.paintSeats = function ( ctx, scale ) {
		var self = this;

		this.seats.filter( function ( seat ) {
			return seat.floorKey === self.floorKey;
		} ).forEach( function ( seat ) {
			ctx.beginPath();
			ctx.fillStyle = self.seatColour( seat );
			ctx.strokeStyle = 'selected' === seat.state ? '#12263f' : 'rgba(0,0,0,0.2)';
			ctx.lineWidth = 'selected' === seat.state ? 2.5 / scale : 1 / scale;

			ctx.arc( seat.x, seat.y, SEAT_RADIUS, 0, Math.PI * 2 );
			ctx.fill();
			ctx.stroke();
		} );
	};

	SeatmapWidget.prototype.seatColour = function ( seat ) {
		if ( 'selected' === seat.state ) {
			return '#12263f';
		}

		if ( 'available' !== seat.state ) {
			return '#d3d6dc';
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

		this.seats.forEach( function ( seat ) {
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
			empty.textContent = this.i18n.noneSelected;
			this.selectionEl.appendChild( empty );
			this.totalEl.textContent = '';
			this.submitEl.disabled = true;

			return;
		}

		var total = 0;

		this.selected.forEach( function ( seat ) {
			total += seat.amount || 0;

			var item = document.createElement( 'li' );
			item.textContent = [ seat.section, seat.row, seat.label ].filter( Boolean ).join( ' · ' ) +
				' — ' + self.formatMoney( seat.amount );
			self.selectionEl.appendChild( item );
		} );

		chosenAreas.forEach( function ( area ) {
			total += ( area.amount || 0 ) * area.quantity;

			var item = document.createElement( 'li' );
			item.textContent = area.quantity + ' × ' + area.label + ' — ' +
				self.formatMoney( ( area.amount || 0 ) * area.quantity );
			self.selectionEl.appendChild( item );
		} );

		this.totalEl.textContent = this.i18n.total + ': ' + this.formatMoney( total );
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
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.config.nonce,
			},
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

	SeatmapWidget.prototype.announce = function ( message ) {
		this.messageEl.textContent = message;
	};

	SeatmapWidget.prototype.formatMoney = function ( minorUnits ) {
		if ( null === minorUnits || undefined === minorUnits ) {
			return '';
		}

		var currency = this.config.currency;
		var amount = ( minorUnits / Math.pow( 10, currency.decimals ) ).toFixed( currency.decimals );

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
