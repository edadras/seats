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
	// A single block in a large venue can be a small part of the plan, so the ceiling has to be
	// high enough to fill the canvas with it.
	/*
	 * How many pixels across a chair has to be before it is drawn as a chair.
	 *
	 * Below this the plan is blocks: a three-pixel circle cannot be aimed at with a finger, cannot
	 * carry a colour anybody can tell from its neighbour's, and cannot be told apart from the
	 * hundred beside it. Four is where a row stops being a smudge.
	 */
	var SEAT_LEGIBLE_PX = 4;

	var MAX_ZOOM = 14;
	var MIN_ZOOM = 0.5;
	var POLL_INTERVAL = 15000;

	function boot( config ) {
		var container = document.getElementById( config.containerId );

		if ( ! container ) {
			return;
		}

		var widget = new SeatmapWidget( container, config );

		/*
		 * Hung off the element it was given.
		 *
		 * The plan is the picking interface now — the list under it is there for keyboards and
		 * screen readers — so anything that needs to know where a seat is on screen has to ask the
		 * widget, including the checks that drive it. A host page that wants to do something with
		 * the picker has the same handle rather than a private one.
		 */
		container.seatmapWidget = widget;

		widget.init();
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
		this.blocks = [];
		// 'plan' shows the venue as blocks; 'section' shows the chairs inside one of them.
		this.mode = 'plan';
		this.blockId = null;
		this.selected = [];
		/*
		 * Timed entry.
		 *
		 * Empty on nearly every event. Where it is not, the constraint is not a chair but how many
		 * people may be in the room between ten and half past, and nothing can be reserved until
		 * the buyer has said which window they are coming in.
		 */
		this.entrySlots = [];
		this.entrySlotId = null;
		this.entryEl = null;
		/*
		 * "Four together, please."
		 *
		 * The commonest thing anybody asks a box office, and a puzzle rather than a purchase on a
		 * plan that is three-quarters full. The server chooses and holds in one movement — a
		 * suggestion the buyer had to confirm is a suggestion somebody else can take in between.
		 */
		this.together = 0;
		this.cursor = null;
		this.view = { scale: 1, x: 0, y: 0 };
		this.maxSeats = config.event.max_seats_per_order || 10;
		/*
		 * Who the tickets are for.
		 *
		 * An event with none, or with one, gets no chooser at all: a select with a single option
		 * is a question with one answer, and asking it makes every buyer slower for nothing. The
		 * server applies the default in that case, so the request looks exactly as it did before
		 * concessions existed.
		 */
		this.ticketTypes = ( config.event.ticket_types || [] ).slice();
		this.defaultType = this.ticketTypes.filter( function ( type ) {
			return type.is_default;
		} )[ 0 ] || this.ticketTypes[ 0 ] || null;
		this.hasTypes = this.ticketTypes.length > 1;
		this.busy = false;
	}

	/**
	 * What one place costs at a given type.
	 *
	 * The same arithmetic as App\Models\TicketType::priceFrom, deliberately: the buyer watches the
	 * total change as they choose, and the server prices the hold from its own copy a moment later.
	 * If these two ever disagree the buyer is shown one number and charged another, so the
	 * rounding matches — floor, in both — and a discount never takes a price below zero or above
	 * what the seat itself costs.
	 */
	SeatmapWidget.prototype.priceForType = function ( base, type ) {
		base = base || 0;

		if ( ! type ) {
			return base;
		}

		if ( 'fixed' === type.kind ) {
			return Math.max( 0, type.value || 0 );
		}

		var amount = base;

		if ( 'percent_off' === type.kind ) {
			var percent = Math.max( 0, Math.min( 100, type.value || 0 ) );
			amount = base - Math.floor( ( base * percent ) / 100 );
		} else if ( 'amount_off' === type.kind ) {
			amount = base - Math.max( 0, type.value || 0 );
		}

		return Math.max( 0, Math.min( base, amount ) );
	};

	SeatmapWidget.prototype.typeById = function ( id ) {
		if ( ! id ) {
			return this.defaultType;
		}

		return this.ticketTypes.filter( function ( type ) {
			return type.id === id;
		} )[ 0 ] || this.defaultType;
	};

	/**
	 * A chooser for who a ticket is for, wired to one line of the summary.
	 *
	 * A `select` and not a row of chips: the list is written by the organiser and can hold six
	 * entries as easily as two, and a native control is the one every browser and every screen
	 * reader already knows how to drive.
	 */
	SeatmapWidget.prototype.typeSelect = function ( chosenId, onChange ) {
		var self = this;
		var select = document.createElement( 'select' );

		select.className = 'seatmap-widget__type';
		select.setAttribute( 'aria-label', this.i18n.ticketTypeFor || this.i18n.ticketTypes );

		this.ticketTypes.forEach( function ( type ) {
			var option = document.createElement( 'option' );

			option.value = type.id;
			option.textContent = type.name;
			option.selected = type.id === ( chosenId || ( self.defaultType && self.defaultType.id ) );
			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () { onChange( select.value ); } );

		return select;
	};

	SeatmapWidget.prototype.init = function () {
		this.flattenSeats();

		/*
		 * Two kinds of room, and the difference runs through the whole picker.
		 *
		 * A theatre has named chairs: you zoom into a section and click the one you want. A
		 * warehouse has areas with a capacity and nothing to click — you say how many of you there
		 * are. Half the events on this platform are the second kind, and showing them a legend of
		 * seat states, an empty seat list and a heading that says "Select your seats" is showing
		 * them a broken version of somebody else's page.
		 */
		this.seated = this.seats.length > 0;

		this.buildBlocks();
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
				sectionKey: section ? section.key : null,
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

	/**
	 * The venue as blocks.
	 *
	 * A plan of two thousand chairs is not something a person chooses from; it is something they
	 * get lost in. So the first view is the venue the way the building itself is signposted —
	 * Stalls, Balcony, Boxes — and the chairs appear once one of those has been chosen.
	 *
	 * Every seat belongs to exactly one block, including seats a chart left outside any section:
	 * those get a block of their own rather than becoming unreachable, which is the failure this
	 * view would otherwise introduce. A floor with only one block never shows the overview, for
	 * the same reason the floor switcher only appears when there are floors to switch between.
	 */
	SeatmapWidget.prototype.buildBlocks = function () {
		var self = this;
		var index = {};
		var blocks = [];
		var named = {};

		this.sections.forEach( function ( section ) {
			named[ section.key ] = section;
		} );

		this.seats.forEach( function ( seat ) {
			var id = seat.floorKey + '|' + ( seat.sectionKey || '' );
			var section = seat.sectionKey ? named[ seat.sectionKey ] : null;

			if ( ! index[ id ] ) {
				index[ id ] = {
					id: id,
					floorKey: seat.floorKey,
					sectionKey: seat.sectionKey || null,
					name: section
						? ( labelOf( section ) || section.key )
						: ( self.floorName( seat.floorKey ) || self.i18n.selectSeats ),
					colour: ( section && section.color ) || null,
					seats: [],
				};
				blocks.push( index[ id ] );
			}

			index[ id ].seats.push( seat );
		} );

		blocks.forEach( function ( block ) {
			var section = block.sectionKey ? named[ block.sectionKey ] : null;

			block.box = boundsOf( block.seats );

			// The outline the chart already carries, because the designer drew it and the two
			// canvases must agree about the shape of a room. Only a block the chart gave no
			// section — loose rows on a floor — has its outline worked out from its own chairs.
			block.outline = section && section.polygon && section.polygon.length > 2
				? section.polygon
				: hullOf( block.seats.map( function ( seat ) { return [ seat.x, seat.y ]; } ), SEAT_SIZE * 1.4 );
		} );

		this.blocks = blocks;
		this.settleMode();
	};

	/** One block on this floor means there is nothing to choose between: go straight in. */
	SeatmapWidget.prototype.settleMode = function () {
		var here = this.blocksOnFloor();

		this.mode = here.length > 1 ? 'plan' : 'section';
		this.blockId = here.length > 1 ? null : ( here[ 0 ] ? here[ 0 ].id : null );
	};

	SeatmapWidget.prototype.blocksOnFloor = function () {
		var self = this;

		return this.blocks.filter( function ( block ) {
			return block.floorKey === self.floorKey && block.box;
		} );
	};

	SeatmapWidget.prototype.currentBlock = function () {
		var self = this;

		return this.blocks.filter( function ( block ) { return block.id === self.blockId; } )[ 0 ] || null;
	};

	SeatmapWidget.prototype.floorName = function ( key ) {
		var floor = ( this.floors || [] ).filter( function ( entry ) { return entry.key === key; } )[ 0 ];

		return floor ? ( floor.name || floor.key ) : '';
	};

	/** How much of a block is still buyable, and what the cheapest seat in it costs. */
	SeatmapWidget.prototype.blockStats = function ( block ) {
		var available = 0;
		var chosen = 0;
		var cheapest = null;

		block.seats.forEach( function ( seat ) {
			if ( 'selected' === seat.state ) {
				chosen++;
			}

			if ( 'available' !== seat.state && 'selected' !== seat.state ) {
				return;
			}

			available++;

			if ( null != seat.amount && ( null === cheapest || seat.amount < cheapest ) ) {
				cheapest = seat.amount;
			}
		} );

		return { available: available, chosen: chosen, cheapest: cheapest, total: block.seats.length };
	};

	/** The line under a block's name: what it costs to sit there, or that it is gone. */
	SeatmapWidget.prototype.blockSummary = function ( block ) {
		var stats = this.blockStats( block );

		if ( ! stats.available ) {
			return this.i18n.sectionSoldOut;
		}

		var left = this.i18n.sectionSeatsLeft.replace( '%d', this.formatCount( stats.available ) );

		return null === stats.cheapest
			? left
			: this.i18n.sectionFrom.replace( '%s', this.formatMoney( stats.cheapest ) ) + ' · ' + left;
	};

	SeatmapWidget.prototype.describeArea = function ( object, section, floorKey ) {
		return {
			id: object.capacity_object_id || null,
			key: object.key,
			label: labelOf( object ) || '',
			section: section ? labelOf( section ) : '',
			sectionKey: section ? section.key : null,
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
			// Places chosen, keyed by ticket type. The empty key is "no types on this event".
			byType: {},
		};
	};

	/** A box round a set of seats, with enough air that the outermost chair is not on the line. */
	/**
	 * The shape a set of chairs makes, as a convex outline around them.
	 *
	 * Used when a chart gives a block no outline of its own. A bounding rectangle would put
	 * corners where the room has none — a fan-shaped block leaning nine degrees is a trapezium,
	 * and boxing it overlaps the block beside it and tells a buyer their seats are somewhere they
	 * are not.
	 */
	function hullOf( points, padding ) {
		if ( points.length < 3 ) {
			return null;
		}

		var sorted = points.slice().sort( function ( a, b ) {
			return a[ 0 ] === b[ 0 ] ? a[ 1 ] - b[ 1 ] : a[ 0 ] - b[ 0 ];
		} );

		var cross = function ( o, a, b ) {
			return ( a[ 0 ] - o[ 0 ] ) * ( b[ 1 ] - o[ 1 ] ) - ( a[ 1 ] - o[ 1 ] ) * ( b[ 0 ] - o[ 0 ] );
		};

		var hull = [];

		[ sorted.slice().reverse(), sorted ].forEach( function ( pass ) {
			var floor = hull.length;

			pass.forEach( function ( point ) {
				while ( hull.length >= floor + 2 && cross( hull[ hull.length - 2 ], hull[ hull.length - 1 ], point ) <= 0 ) {
					hull.pop();
				}

				hull.push( point );
			} );

			hull.pop();
		} );

		if ( hull.length < 3 ) {
			return null;
		}

		var cx = 0, cy = 0;

		hull.forEach( function ( point ) { cx += point[ 0 ]; cy += point[ 1 ]; } );
		cx /= hull.length;
		cy /= hull.length;

		// Pushed outward from the middle so the outline clears the chairs rather than cutting them.
		return hull.map( function ( point ) {
			var length = Math.max( 0.001, Math.hypot( point[ 0 ] - cx, point[ 1 ] - cy ) );

			return [
				point[ 0 ] + ( point[ 0 ] - cx ) / length * padding,
				point[ 1 ] + ( point[ 1 ] - cy ) / length * padding,
			];
		} );
	}

	/**
	 * The middle of a shape, weighted by its area.
	 *
	 * Not the middle of its bounding box: a block leaning fifteen degrees has a box whose centre
	 * is off the block entirely, which is where a dozen names of a dozen leaning blocks all pile
	 * up on top of each other in the middle of the room.
	 */
	function centroidOf( outline, box ) {
		var area = 0, cx = 0, cy = 0;

		if ( outline && outline.length > 2 ) {
			for ( var i = 0, j = outline.length - 1; i < outline.length; j = i++ ) {
				var step = outline[ j ][ 0 ] * outline[ i ][ 1 ] - outline[ i ][ 0 ] * outline[ j ][ 1 ];

				area += step;
				cx += ( outline[ j ][ 0 ] + outline[ i ][ 0 ] ) * step;
				cy += ( outline[ j ][ 1 ] + outline[ i ][ 1 ] ) * step;
			}

			if ( Math.abs( area ) > 0.0001 ) {
				return { x: cx / ( 3 * area ), y: cy / ( 3 * area ) };
			}
		}

		return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
	}

	/**
	 * How wide a shape is at one particular height.
	 *
	 * The width of the bounding box is the wrong answer for anything that leans: a block at
	 * fifteen degrees has a box half again as wide as the block, and a name measured against the
	 * box is a name written across the aisle and into the block next door.
	 */
	function widthAt( outline, box, y ) {
		if ( ! outline || outline.length < 3 ) {
			return box.width;
		}

		var crossings = [];

		for ( var i = 0, j = outline.length - 1; i < outline.length; j = i++ ) {
			var y1 = outline[ j ][ 1 ], y2 = outline[ i ][ 1 ];

			if ( ( y1 > y ) === ( y2 > y ) ) {
				continue;
			}

			crossings.push( outline[ j ][ 0 ] + ( ( y - y1 ) / ( y2 - y1 ) ) * ( outline[ i ][ 0 ] - outline[ j ][ 0 ] ) );
		}

		if ( crossings.length < 2 ) {
			return 0;
		}

		return Math.max.apply( null, crossings ) - Math.min.apply( null, crossings );
	}

	/** "Block 06" shortened to "06": what a venue prints on the sign is the number, not the word. */
	function shortNameOf( name ) {
		var last = String( name || '' ).split( /\s+/ ).pop();

		return last && last !== name ? last : null;
	}

	/** Is this point inside that outline? Ray casting, which handles any shape a section can be. */
	function insideOutline( outline, point ) {
		var inside = false;

		for ( var i = 0, j = outline.length - 1; i < outline.length; j = i++ ) {
			var xi = outline[ i ][ 0 ], yi = outline[ i ][ 1 ];
			var xj = outline[ j ][ 0 ], yj = outline[ j ][ 1 ];

			if ( ( yi > point.y ) !== ( yj > point.y ) &&
				point.x < ( ( xj - xi ) * ( point.y - yi ) ) / ( yj - yi ) + xi ) {
				inside = ! inside;
			}
		}

		return inside;
	}

	function overlaps( a, b ) {
		return !! a && !! b &&
			a.x < b.x + b.width && a.x + a.width > b.x &&
			a.y < b.y + b.height && a.y + a.height > b.y;
	}

	function boundsOf( seats ) {
		if ( ! seats.length ) {
			return null;
		}

		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

		seats.forEach( function ( seat ) {
			minX = Math.min( minX, seat.x );
			minY = Math.min( minY, seat.y );
			maxX = Math.max( maxX, seat.x );
			maxY = Math.max( maxY, seat.y );
		} );

		var pad = SEAT_SIZE * 1.5;

		return {
			x: minX - pad,
			y: minY - pad,
			width: maxX - minX + pad * 2,
			height: maxY - minY + pad * 2,
		};
	}

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
		close: '<path d="M6 6l12 12M18 6 6 18"/>',
		expand: '<path d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"/>',
		shrink: '<path d="M4 9h5V4M20 9h-5V4M20 15h-5v5M4 15h5v5"/>',
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
		heading.textContent = this.seated ? this.i18n.selectSeats : this.i18n.chooseTickets;
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
		// Kept, because the canvas takes its palette from the colour of the box it is drawn in.
		this.stageEl = stage;

		this.canvas = document.createElement( 'canvas' );
		this.canvas.className = 'seatmap-widget__canvas';
		// The canvas is decorative: the accessible interface is the seat list below it.
		this.canvas.setAttribute( 'aria-hidden', 'true' );
		stage.appendChild( this.canvas );
		stage.appendChild( this.buildZoomControls() );
		stage.appendChild( this.buildBackControl() );

		// Decorative: everything it says is already on the seat's own button, which is what a
		// screen reader reads. Announcing it twice would be worse than not announcing it.
		this.tipEl = document.createElement( 'div' );
		this.tipEl.className = 'seatmap-widget__tip';
		this.tipEl.setAttribute( 'aria-hidden', 'true' );
		this.tipEl.hidden = true;
		stage.appendChild( this.tipEl );

		var floors = this.buildFloorSwitcher();

		if ( floors ) {
			stage.appendChild( floors );
		}

		main.appendChild( stage );

		/*
		 * Seats first, then the areas.
		 *
		 * Under the plan is where somebody looks next, and in a seated room what they are looking
		 * for is the way into a section. Standing room and tables are a second way to buy the same
		 * evening, so they follow rather than lead. In a room with no seats the list below is the
		 * only list there is, and it lands in the same place anyway.
		 */
		if ( this.seated ) {
			main.appendChild( this.buildSeatList() );
		}

		main.appendChild( this.buildAreaList() );

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

		/*
		 * Full screen gives the plan whatever height the rest of the picker is not using, and that
		 * changes underneath it: the block chooser is a dozen cards tall, and folds to one line the
		 * moment a block is opened. Watching the stage catches that, and a theme resizing its own
		 * column, without either having to remember to say so.
		 */
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( function () {
				if ( self.isFullScreen() && self.stageEl &&
					Math.abs( self.stageEl.clientHeight - self.canvas.clientHeight ) > 2 ) {
					self.resize();
				}
			} ).observe( this.stageEl );
		}

		// Leaving full screen is not always our doing — Escape, the browser's own chrome, or a
		// gesture — so the button and the canvas size follow the browser rather than the press.
		document.addEventListener( 'fullscreenchange', function () { self.syncFullScreen(); } );

		// Escape out of the pinned fallback, where there is no browser chrome to do it for us.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && self.container &&
				self.container.classList.contains( 'seatmap-widget--filling' ) ) {
				self.setFilling( false );
			}
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

		// "Selected" and "Unavailable" describe a chair. Where there are none, they describe nothing.
		( this.seated ? [ 'selected', 'unavailable' ] : [] ).forEach( function ( state ) {
			var item = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'seatmap-widget__swatch seatmap-widget__swatch--' + state;
			item.appendChild( swatch );
			item.appendChild( document.createTextNode( self.i18n[ state ] ) );
			legend.appendChild( item );
		} );

		return legend;
	};

	/**
	 * The way back out of a block.
	 *
	 * Built once and hidden, rather than added and removed: a control that appears in a different
	 * place each time is a control people stop trusting. Escape does the same thing, because
	 * anyone who has just zoomed into the wrong section reaches for it.
	 */
	SeatmapWidget.prototype.buildBackControl = function () {
		var self = this;
		var wrap = document.createElement( 'div' );

		wrap.className = 'seatmap-widget__float seatmap-widget__back';

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'seatmap-widget__back-button';
		button.textContent = this.i18n.backToPlan;
		button.addEventListener( 'click', function () { self.leaveBlock(); } );

		wrap.appendChild( button );
		this.backEl = wrap;

		this.container.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && 'section' === self.mode ) {
				self.leaveBlock();
			}
		} );

		this.syncStageControls();

		return wrap;
	};

	/** Only offer the way out when there is somewhere to go back to. */
	/**
	 * Inside one block, with a whole venue to go back to.
	 *
	 * The second half matters: a room with a single block never shows the overview at all, so a
	 * buyer there is not "inside" anything — there is nowhere else to be, and hiding things from
	 * them would hide them for good.
	 */
	SeatmapWidget.prototype.insideABlock = function () {
		return 'section' === this.mode && this.blocksOnFloor().length > 1;
	};

	SeatmapWidget.prototype.syncStageControls = function () {
		if ( this.backEl ) {
			this.backEl.hidden = ! this.insideABlock();
		}
	};

	SeatmapWidget.prototype.buildZoomControls = function () {
		var wrap = document.createElement( 'div' );
		wrap.className = 'seatmap-widget__float seatmap-widget__zoom';

		var self = this;

		wrap.appendChild( iconButton( 'plus', this.i18n.zoomIn, function () { self.zoomBy( 1.25 ); } ) );
		wrap.appendChild( iconButton( 'minus', this.i18n.zoomOut, function () { self.zoomBy( 0.8 ); } ) );
		wrap.appendChild( iconButton( 'reset', this.i18n.resetView, function () { self.resetView(); } ) );

		this.fullScreenEl = iconButton( 'expand', this.i18n.fullScreen, function () { self.toggleFullScreen(); } );
		wrap.appendChild( this.fullScreenEl );

		return wrap;
	};

	/**
	 * The plan, filling the screen.
	 *
	 * A hall of four thousand seats inside a column a theme gave the picker is a plan read through
	 * a letterbox. This is the one control that is worth more than any amount of zooming: the same
	 * map, the whole window.
	 *
	 * Two ways of doing it, because one of them is not available everywhere. The Fullscreen API is
	 * the right answer and is used where it works; an iPhone does not offer it for an element at
	 * all, so the fallback pins the stage over the page with position: fixed — which looks the same
	 * to the buyer and is the difference between the feature existing on a phone and not.
	 */
	SeatmapWidget.prototype.toggleFullScreen = function () {
		var self = this;
		var host = this.container;

		if ( ! host ) {
			return;
		}

		if ( this.isFullScreen() ) {
			if ( document.fullscreenElement === host ) {
				document.exitFullscreen();
			} else {
				this.setFilling( false );
			}

			return;
		}

		if ( host.requestFullscreen ) {
			// A refusal is not a failure to report: some browsers refuse without a gesture they
			// recognise, and the buyer gets the fallback rather than a button that does nothing.
			host.requestFullscreen().catch( function () { self.setFilling( true ); } );

			return;
		}

		this.setFilling( true );
	};

	/**
	 * The whole picker, not only the plan.
	 *
	 * Taking just the canvas full screen is the obvious thing and the wrong one: the buyer can then
	 * pan around a beautiful map and cannot see what they have chosen, what it costs, or the button
	 * that reserves it. The legend, the list and the summary come too.
	 */
	SeatmapWidget.prototype.isFullScreen = function () {
		return !! this.container && ( document.fullscreenElement === this.container ||
			this.container.classList.contains( 'seatmap-widget--filling' ) );
	};

	/** The fallback: the picker pinned over the page, and the page held still behind it. */
	SeatmapWidget.prototype.setFilling = function ( on ) {
		this.container.classList.toggle( 'seatmap-widget--filling', on );
		document.body.classList.toggle( 'seatmap-widget-filling', on );
		this.syncFullScreen();
	};

	/** Whichever way it was done, the button has to say what it will do next. */
	SeatmapWidget.prototype.syncFullScreen = function () {
		if ( ! this.fullScreenEl ) {
			return;
		}

		var open = this.isFullScreen();

		this.fullScreenEl.innerHTML = iconMarkup( open ? 'shrink' : 'expand' );
		this.fullScreenEl.setAttribute( 'aria-label', open ? this.i18n.exitFullScreen : this.i18n.fullScreen );
		this.fullScreenEl.setAttribute( 'aria-pressed', open ? 'true' : 'false' );

		/*
		 * The canvas is sized in pixels, and it has just been given a different number of them.
		 *
		 * Twice, and the second one is not superstition: the canvas is the stage's content, the
		 * stage's height is what the layout has left over, and the leftover is not known until the
		 * canvas has been resized once. The first pass settles the layout; the second measures it.
		 */
		var self = this;

		this.resize();
		window.requestAnimationFrame( function () { self.resize(); } );
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
		this.settleMode();
		this.resetView();
		this.syncStageControls();
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

		/*
		 * Standing room and tables belong to the venue, not to one block of it.
		 *
		 * Once somebody has zoomed into the Stalls they are choosing a chair, and a list of tables
		 * printed under the chairs is a different offer interrupting the one they are making. It is
		 * still there on the way back out, which is where they met it in the first place.
		 */
		var hidden = 0 === areas.length || this.insideABlock();

		this.areaListEl.innerHTML = '';
		this.areaListEl.hidden = hidden;

		if ( hidden ) {
			return;
		}

		var heading = document.createElement( 'h3' );
		heading.textContent = this.seated ? this.i18n.standingAreas : this.i18n.ticketTypes;
		this.areaListEl.appendChild( heading );

		areas.forEach( function ( area ) {
			var row = document.createElement( 'div' );
			row.className = 'seatmap-widget__area';

			var name = document.createElement( 'span' );
			name.className = 'seatmap-widget__area-name';
			// The venue named this area, in whatever language it names things. That is not
			// necessarily the language the picker is being read in.
			name.setAttribute( 'dir', 'auto' );
			name.textContent = area.label +
				( area.amount != null ? ' — ' + self.formatMoney( area.amount ) : '' );
			row.appendChild( name );

			var left = document.createElement( 'span' );
			left.className = 'seatmap-widget__area-left';
			left.textContent = area.remaining > 0
				? self.i18n.placesLeft.replace( '%d', self.formatCount( area.remaining ) )
				: self.i18n.soldOut;
			row.appendChild( left );

			/*
			 * One stepper per kind of ticket, or one stepper when there is only one kind.
			 *
			 * The alternative — a single stepper and a type chooser beside it — cannot express
			 * "two adults and a child", which is the commonest thing anybody asks a standing
			 * event for.
			 */
			var kinds = self.hasTypes
				? self.ticketTypes
				: [ self.defaultType || { id: '', name: '' } ];

			kinds.forEach( function ( type, index ) {
				var key = self.hasTypes ? type.id : '';
				var held = ( area.byType || {} )[ key ] || 0;
				var typed = self.priceForType( area.amount, self.hasTypes ? type : null );
				var host = row;

				if ( self.hasTypes ) {
					host = document.createElement( 'div' );
					host.className = 'seatmap-widget__area-kind';

					var kindName = document.createElement( 'span' );
					kindName.className = 'seatmap-widget__area-kind-name';
					kindName.setAttribute( 'dir', 'auto' );
					kindName.textContent = type.name +
						( area.amount != null ? ' — ' + self.formatMoney( typed ) : '' );
					host.appendChild( kindName );

					if ( type.proof_note ) {
						var note = document.createElement( 'span' );
						note.className = 'seatmap-widget__area-kind-note';
						note.setAttribute( 'dir', 'auto' );
						note.textContent = type.proof_note;
						host.appendChild( note );
					}
				}

				var stepper = document.createElement( 'div' );
				stepper.className = 'seatmap-widget__stepper';

				var forWhat = self.hasTypes ? area.label + ' · ' + type.name : area.label;

				var minus = iconButton( 'minus', self.i18n.removeOne.replace( '%s', forWhat ),
					function () { self.changeAreaQuantity( area, -1, key ); } );
				minus.disabled = held <= 0;

				var count = document.createElement( 'output' );
				count.textContent = self.formatCount( held );
				// The raw number as well as the shaped one: the row is highlighted from CSS when
				// it holds anything, and Persian digits are not something a stylesheet can compare.
				count.dataset.chosen = String( held );
				count.setAttribute( 'aria-live', 'polite' );

				var plus = iconButton( 'plus', self.i18n.addOne.replace( '%s', forWhat ),
					function () { self.changeAreaQuantity( area, 1, key ); } );
				plus.disabled = area.quantity >= area.remaining || area.quantity >= self.maxSeats;

				stepper.appendChild( minus );
				stepper.appendChild( count );
				stepper.appendChild( plus );
				host.appendChild( stepper );

				if ( host !== row ) {
					row.appendChild( host );
				}
			} );

			self.areaListEl.appendChild( row );
		} );
	};

	/**
	 * One area's chosen places, split by who they are for.
	 *
	 * An area holds a map of type id to quantity rather than a single number, because two children
	 * and one adult standing in the same pit are three places at two prices. With no types the map
	 * has one entry under the empty key and everything reads as it did before.
	 *
	 * @return {Array} [{ typeId, type, quantity }]
	 */
	SeatmapWidget.prototype.areaLines = function ( area ) {
		var self = this;
		var byType = area.byType || {};

		return Object.keys( byType )
			.filter( function ( key ) { return byType[ key ] > 0; } )
			.map( function ( key ) {
				return {
					typeId: key || null,
					type: key ? self.typeById( key ) : self.defaultType,
					quantity: byType[ key ],
				};
			} );
	};

	SeatmapWidget.prototype.changeAreaQuantity = function ( area, delta, typeId ) {
		var key = typeId || ( this.hasTypes && this.defaultType ? this.defaultType.id : '' );

		area.byType = area.byType || {};

		var held = area.byType[ key ] || 0;
		var next = held + delta;
		var total = area.quantity + delta;

		if ( next < 0 || total > area.remaining ) {
			return;
		}

		// Seats and standing places share the per-order limit, so the two have to be counted
		// together rather than each against the cap on its own.
		if ( delta > 0 && this.totalChosen() >= this.maxSeats ) {
			this.announce( ( this.seated ? this.i18n.maxSeats : this.i18n.maxTickets )
				.replace( '%d', this.formatCount( this.maxSeats ) ) );

			return;
		}

		area.byType[ key ] = next;
		// The running total stays a plain number, because everything that counts places — the
		// per-order cap, the sold-out check, the highlight on a chosen row — asks that question
		// and not "how many children".
		area.quantity = total;

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

		// Before "your selection", not under it: this is the way to make a selection, and putting
		// it beneath that heading reads as though four seats had already been chosen.
		if ( this.seated ) {
			summary.appendChild( this.buildTogether() );
		}

		var heading = document.createElement( 'h3' );
		heading.textContent = this.i18n.yourSelection;
		summary.appendChild( heading );

		// Above the selection, because it is the first thing a buyer of a timed-entry event has
		// to decide and the last thing they should discover at the button.
		this.entryEl = document.createElement( 'div' );
		this.entryEl.className = 'seatmap-widget__entry';
		this.entryEl.hidden = true;
		summary.appendChild( this.entryEl );

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
		this.submitEl.textContent = this.seated ? this.i18n.addToCart : this.i18n.reserveTickets;
		this.submitEl.disabled = true;
		this.submitEl.addEventListener( 'click', function () {
			self.reserve();
		} );
		summary.appendChild( this.submitEl );

		return summary;
	};

	/**
	 * The shortcut past the plan: how many, side by side.
	 *
	 * Offered before the seats rather than after them, because a buyer who wants four together is
	 * not going to find them by clicking and would otherwise leave. Choosing chairs by hand still
	 * works and is still the thing the plan is for; this is for everybody else.
	 */
	SeatmapWidget.prototype.buildTogether = function () {
		var self = this;
		var wrap = document.createElement( 'div' );

		wrap.className = 'seatmap-widget__together';

		var label = document.createElement( 'label' );

		label.className = 'seatmap-widget__together-label';
		label.textContent = this.i18n.seatsTogether;
		label.setAttribute( 'for', this.id( 'together' ) );

		var picker = document.createElement( 'select' );

		picker.className = 'seatmap-widget__together-count';
		picker.id = this.id( 'together' );

		for ( var n = 1; n <= Math.min( 10, this.maxSeats ); n++ ) {
			var option = document.createElement( 'option' );

			option.value = String( n );
			option.textContent = this.formatCount( n );
			picker.appendChild( option );
		}

		var button = document.createElement( 'button' );

		button.type = 'button';
		// Its own look rather than the host page's button class: this sits beside a select inside
		// the widget's own panel, and a shop's primary button there would outshout the one that
		// actually takes the money.
		button.className = 'seatmap-widget__together-go';
		button.textContent = this.i18n.findSeats;
		button.addEventListener( 'click', function () {
			self.reserveTogether( Number( picker.value ) || 1 );
		} );

		wrap.appendChild( label );
		wrap.appendChild( picker );
		wrap.appendChild( button );

		this.togetherEl = wrap;

		return wrap;
	};

	/** An id nobody else on the page shares, for a label that has to point at its own field. */
	SeatmapWidget.prototype.id = function ( name ) {
		this.idPrefix = this.idPrefix ||
			'seatmap-' + Math.random().toString( 36 ).slice( 2, 8 );

		return this.idPrefix + '-' + name;
	};

	/**
	 * Where availability and holds live.
	 *
	 * A shop puts its own routes in front of the seating API, because the price a buyer is charged
	 * has to be set by a server. A page with no server of its own — somebody's own website with
	 * this widget pasted into it — talks to the platform's public embed API instead, and is handed
	 * to the organiser's hosted checkout to pay. Both are given as whole URLs by whoever boots the
	 * widget; the widget does not know which kind of host it is in.
	 */
	SeatmapWidget.prototype.availabilityEndpoint = function () {
		return this.config.availabilityUrl ||
			this.config.restUrl + '/availability/' + encodeURIComponent( this.config.eventPublicId );
	};

	SeatmapWidget.prototype.holdEndpoint = function () {
		return this.config.holdUrl || this.config.restUrl + '/hold';
	};

	SeatmapWidget.prototype.fetchAvailability = function () {
		var self = this;
		var url = this.availabilityEndpoint();

		if ( this.cursor ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'since=' + encodeURIComponent( this.cursor );
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

				if ( data.entry_slots ) {
					self.applyEntrySlots( data.entry_slots );
				}

				self.cursor = data.cursor;
			} )
			.catch( function () {
				// A failed poll is not worth interrupting the buyer over; the next one may work,
				// and the hold request is authoritative anyway.
			} );
	};

	/**
	 * The arrival windows, and how full each one is.
	 *
	 * Redrawn on every poll, because a window fills up while somebody is choosing seats and the
	 * honest thing is to say so before they press the button rather than after. A window the buyer
	 * had already chosen and which has since filled is unchosen here — leaving it selected would
	 * be showing them a choice the server is about to refuse.
	 */
	SeatmapWidget.prototype.applyEntrySlots = function ( slots ) {
		this.entrySlots = slots || [];

		if ( ! this.entryEl ) {
			return;
		}

		if ( ! this.entrySlots.length ) {
			this.entryEl.hidden = true;
			this.entrySlotId = null;
			this.updateSubmitState();

			return;
		}

		var self = this;
		var chosen = this.entrySlots.filter( function ( slot ) {
			return slot.id === self.entrySlotId;
		} )[ 0 ];

		if ( chosen && chosen.sold_out ) {
			this.entrySlotId = null;
			this.announce( this.i18n.arrivalFull );
		}

		this.entryEl.hidden = false;
		this.entryEl.innerHTML = '';

		var heading = document.createElement( 'h4' );
		heading.className = 'seatmap-widget__entry-title';
		heading.textContent = this.i18n.arrivalTime;
		this.entryEl.appendChild( heading );

		var select = document.createElement( 'select' );
		select.className = 'seatmap-widget__entry-select';
		select.setAttribute( 'aria-label', this.i18n.arrivalTime );

		var prompt = document.createElement( 'option' );
		prompt.value = '';
		prompt.textContent = this.i18n.chooseArrival;
		select.appendChild( prompt );

		this.entrySlots.forEach( function ( slot ) {
			var option = document.createElement( 'option' );

			option.value = slot.id;
			option.disabled = !! slot.sold_out;
			option.selected = slot.id === self.entrySlotId;
			option.textContent = slot.label + ' — ' + ( slot.sold_out
				? self.i18n.soldOut
				: ( null === slot.remaining || undefined === slot.remaining
					? ''
					: self.i18n.placesLeft.replace( '%d', self.formatCount( slot.remaining ) ) ) );
			// A window with no limit of its own says only its own name; the dash would be the
			// start of a sentence that never arrives.
			option.textContent = option.textContent.replace( /\s+—\s*$/, '' );

			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () {
			self.entrySlotId = select.value || null;
			self.updateSubmitState();
		} );

		this.entryEl.appendChild( select );
		this.updateSubmitState();
	};

	/** Nothing may be reserved without an arrival time on an event that sells them. */
	SeatmapWidget.prototype.needsEntrySlot = function () {
		return this.entrySlots.length > 0 && ! this.entrySlotId;
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
			// the buyer was deciding. Trimmed from the largest kind first, so a family losing one
			// place loses the ticket they have most of rather than their only child's ticket.
			while ( area.quantity > area.remaining ) {
				var keys = Object.keys( area.byType || {} ).filter( function ( key ) {
					return area.byType[ key ] > 0;
				} );

				if ( ! keys.length ) {
					break;
				}

				keys.sort( function ( a, b ) { return area.byType[ b ] - area.byType[ a ]; } );
				area.byType[ keys[ 0 ] ] -= 1;
				area.quantity -= 1;
			}

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
		// Full screen: the stage is stretched by the layout to whatever is left over, so its own
		// measured box is the answer for both dimensions rather than the shape of the room.
		var filling = this.isFullScreen() && this.stageEl;
		var width = ( filling ? this.stageEl.clientWidth : ( host && host.clientWidth ) ) ||
			this.container.clientWidth || 800;
		var size = this.canvasSize();
		var geometryWidth = size.width || 1000;
		var geometryHeight = size.height || 800;
		var ratio = geometryHeight / geometryWidth;

		// Full screen means the whole screen: the shape of the room no longer decides how tall the
		// plan is allowed to be, because there is nothing else on the page to leave room for.
		var height = filling
			? this.stageEl.clientHeight
			: Math.min( Math.max( width * ratio, 320 ), 720 );
		var dpr = window.devicePixelRatio || 1;

		this.canvas.width = width * dpr;
		this.canvas.height = height * dpr;
		this.canvas.style.width = width + 'px';
		this.canvas.style.height = height + 'px';

		this.baseScale = Math.min( width / geometryWidth, height / geometryHeight );
		this.dpr = dpr;

		var open = 'section' === this.mode ? this.currentBlock() : null;

		// The view offsets were solved for the old canvas. Reframing is cheaper than leaving
		// somebody's section half off the edge after they rotate a phone.
		if ( open && open.box && this.blocksOnFloor().length > 1 ) {
			this.fitTo( open.box );
			this.zoomToChairs( open.box );
		} else {
			this.paint();
		}
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
			paper: '#f4f5f8',
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
			paper: '#141821',
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

	/**
	 * Which palette the plan is drawn in — measured, not asked for.
	 *
	 * It used to ask the operating system. That is right for a picker sitting on a shop that
	 * follows the reader's preference, and wrong for every site that has committed to a look of its
	 * own: a venue running a dark theme, read in a browser set to light, got a dark stage with
	 * light-mode ink painted on it, and the section labels disappeared.
	 *
	 * The colour of the box the canvas sits in already answers the question, whoever set it —
	 * the system preference, a site theme, or an organiser's own brand colour. So read that.
	 */
	SeatmapWidget.prototype.colours = function () {
		var background = this.stageEl
			? window.getComputedStyle( this.stageEl ).backgroundColor
			: '';
		var channels = background.match( /\d+(?:\.\d+)?/g );

		if ( channels && channels.length >= 3 ) {
			// Rec. 709 luma. Precise enough for the only question being asked of it.
			var luma = ( 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ] ) / 255;

			return luma < 0.5 ? PALETTES.dark : PALETTES.light;
		}

		// A transparent or unreadable background — fall back to what the reader asked their
		// browser for, which is what this did before it could measure anything.
		var query = window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' );

		return query && query.matches ? PALETTES.dark : PALETTES.light;
	};

	/**
	 * Whatever the canvas is sitting on, for writing a name legibly over anything.
	 *
	 * A block's name can land on the stage, on a traced wall, or on its own chairs; the ink of the
	 * theme is only readable against the theme's paper, so a thin outline of the paper goes behind
	 * every word the plan writes.
	 */
	SeatmapWidget.prototype.paper = function () {
		var background = this.stageEl
			? window.getComputedStyle( this.stageEl ).backgroundColor
			: '';

		return /^rgba?\(/.test( background ) && ! /,\s*0\s*\)$/.test( background )
			? background
			: this.colours().paper;
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
		this.paintPlan( ctx, scale );

		ctx.restore();
	};

	/**
	 * The room, at whatever distance the buyer happens to be standing.
	 *
	 * There is no longer a moment where the plan stops being blocks and starts being chairs, and
	 * no block whose chairs are hidden because a different one is open. Whether a chair is drawn
	 * is a question about size: below a few pixels across it is a dot nobody can aim at, so the
	 * block is the answer instead; above it every block in view draws its chairs, and the outlines
	 * stay underneath so a buyer still knows which part of the building they are looking at.
	 *
	 * That is what makes the map one thing to move around in. Somebody comparing the end of row A
	 * in one block with the start of row A in the next can see both at once and drag between them,
	 * rather than leaving one block to look at the other.
	 */
	SeatmapWidget.prototype.paintPlan = function ( ctx, scale ) {
		var self = this;
		var chairs = this.chairsShown();
		var view = this.viewBox();

		this.blocksOnFloor().forEach( function ( block ) {
			if ( ! chairs || overlaps( block.box, view ) ) {
				self.paintBlock( ctx, block, scale, chairs );
			}
		} );

		if ( chairs ) {
			this.paintTables( ctx );
			this.paintSeats( ctx, scale, view );
		}
	};

	/**
	 * Is a chair big enough on screen to be worth drawing and possible to aim at?
	 *
	 * Measured in real pixels rather than in zoom steps, because the same zoom means something
	 * different in a 500-seat theatre and a 4,000-seat amphitheatre, and on a phone and a desk.
	 */
	SeatmapWidget.prototype.chairsLegible = function () {
		return SEAT_SIZE * this.baseScale * this.view.scale >= SEAT_LEGIBLE_PX;
	};

	/**
	 * Are there chairs on the plan at the moment?
	 *
	 * Two conditions, and both are the point. Somebody who has not yet said which part of the
	 * building they want is choosing between blocks, and five hundred chairs is not that choice —
	 * so the overview stays an overview. Once they are inside one, every block big enough draws
	 * its chairs, theirs and their neighbours' alike, and zooming back out returns them to the
	 * blocks by the same rule rather than by a different screen.
	 */
	SeatmapWidget.prototype.chairsShown = function () {
		return 'section' === this.mode && this.chairsLegible();
	};

	/** What is on screen, in the chart's own coordinates, with a margin for what is half off it. */
	SeatmapWidget.prototype.viewBox = function () {
		var scale = this.baseScale * this.view.scale;
		var pad = SEAT_SIZE * 3;

		return {
			x: -this.view.x / scale - pad,
			y: -this.view.y / scale - pad,
			width: ( this.canvas.clientWidth || 800 ) / scale + pad * 2,
			height: ( this.canvas.clientHeight || 600 ) / scale + pad * 2,
		};
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

	/**
	 * The outline of a shape, in the coordinates the chart stores.
	 *
	 * `points` is a list of [ x, y ] pairs, the same as the designer writes and the same as a
	 * section's polygon — a shape drawn by tracing a floor plan has to come back looking like what
	 * was traced, and the two surfaces reading the same field two different ways is how it stops.
	 */
	function shapePoints( shape ) {
		if ( ! shape.points || ! shape.points.length ) {
			return null;
		}

		if ( Array.isArray( shape.points[ 0 ] ) ) {
			return shape.points;
		}

		var pairs = [];

		for ( var i = 0; i + 1 < shape.points.length; i += 2 ) {
			pairs.push( [ shape.points[ i ], shape.points[ i + 1 ] ] );
		}

		return pairs;
	}

	/** Turn the canvas about a shape's own middle, so a rotated stage is drawn rotated. */
	function turn( ctx, object, box ) {
		var angle = ( ( object.rotation || 0 ) * Math.PI ) / 180;

		if ( ! angle ) {
			return;
		}

		var cx = box.x + box.width / 2;
		var cy = box.y + box.height / 2;

		ctx.translate( cx, cy );
		ctx.rotate( angle );
		ctx.translate( -cx, -cy );
	}

	SeatmapWidget.prototype.paintShape = function ( ctx, shape ) {
		var self = this;
		var colours = this.colours();

		[ shape ].forEach( function ( shape ) {
			var points = shapePoints( shape );
			var width = shape.width || 0;
			var height = shape.height || 0;

			ctx.save();
			ctx.fillStyle = shape.fill || self.shapeColour( shape.kind );
			ctx.strokeStyle = colours.shapeEdge;
			turn( ctx, shape, { x: shape.x, y: shape.y, width: width, height: height } );

			if ( 'line' === shape.kind && points ) {
				// An open path: walls, aisles and the outline of a traced floor plan. Filling it
				// would paint the room in, which is what a missing branch here used to do — and
				// with no width or height on a line, what it actually did was draw nothing at all.
				ctx.beginPath();
				points.forEach( function ( point, index ) {
					index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
				} );
				ctx.strokeStyle = shape.fill || colours.shapeEdge;
				ctx.lineWidth = shape.strokeWidth || 3;
				ctx.stroke();
				ctx.restore();

				return;
			}

			if ( points ) {
				ctx.beginPath();
				points.forEach( function ( point, index ) {
					index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
				} );
				ctx.closePath();
				ctx.fill();
			} else if ( 'ellipse' === shape.kind ) {
				ctx.beginPath();
				ctx.ellipse(
					shape.x + width / 2,
					shape.y + height / 2,
					width / 2,
					height / 2,
					0, 0, Math.PI * 2
				);
				ctx.fill();
			} else {
				ctx.fillRect( shape.x, shape.y, width, height );
			}

			var label = shape.label || ( 'stage' === shape.kind ? self.i18n.stage : '' );

			if ( label ) {
				ctx.fillStyle = colours.shapeLabel;
				ctx.font = '600 ' + ( shape.fontSize || 16 ) + 'px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText( label, shape.x + width / 2, shape.y + height / 2 );
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
			turn( ctx, ( area.shape || area ), box );
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
					: self.i18n.placesLeft.replace( '%d', self.formatCount( area.remaining ) ) +
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

	SeatmapWidget.prototype.paintSeats = function ( ctx, scale, view ) {
		var self = this;
		var colours = this.colours();

		this.seats.filter( function ( seat ) {
			// Every block's chairs, not just the open one's — and only the ones on screen. A hall
			// of four thousand redraws on every pixel of a drag, and the ones behind the edge of
			// the canvas cost exactly as much to draw as the ones in front of it.
			return seat.floorKey === self.floorKey &&
				seat.x >= view.x && seat.x <= view.x + view.width &&
				seat.y >= view.y && seat.y <= view.y + view.height;
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

	/**
	 * A block: the shape its own chairs make, with its name on it.
	 *
	 * `quiet` is the same block seen from close up, where the chairs themselves are drawn and this
	 * is only the wall around them — so it recedes to an outline and a name, and stops shouting a
	 * price at somebody who is already reading the seats.
	 */
	SeatmapWidget.prototype.paintBlock = function ( ctx, block, scale, quiet ) {
		var stats = this.blockStats( block );
		var soldOut = 0 === stats.available;
		var colours = this.colours();
		var colour = block.colour || this.zoneColour( this.blockZone( block ) );
		var box = block.box;

		ctx.save();
		this.traceBlock( ctx, block );

		ctx.fillStyle = soldOut && ! quiet
			? colours.areaSoldOut
			: withAlpha( colour, quiet ? 0.08 : ( stats.chosen ? 0.42 : 0.22 ) );
		ctx.fill();

		// Everything written on a block is sized in screen pixels rather than in chart units: the
		// same 18 units is a headline in a studio theatre and an unreadable speck in a hall six
		// thousand units across, and the name of the block a buyer is looking for has to be
		// readable in both.
		ctx.lineWidth = ( quiet ? 1 : ( stats.chosen ? 3 : 1.5 ) ) / scale;
		ctx.strokeStyle = quiet
			? withAlpha( colour, 0.45 )
			: ( stats.chosen ? colours.ink : soldOut ? colours.areaSoldOutEdge : colour );
		ctx.stroke();

		var middle = centroidOf( block.outline, box );

		ctx.textAlign = 'center';

		if ( quiet ) {
			/*
			 * Above the block rather than on it.
			 *
			 * The chairs are drawn after the outlines — they are the thing being chosen, so they
			 * win every overlap — and a name written across row A is a name with a row of dots
			 * through it. Measured at the block's shoulders so that a leaning block's name leans
			 * with it, then lifted clear.
			 */
			var shoulders = box.y + box.height * 0.06;

			ctx.textBaseline = 'bottom';
			ctx.fillStyle = colours.muted;
			this.fitText( ctx, block.name, this.middleAt( block, box, shoulders ), box.y - 3 / scale,
				widthAt( block.outline, box, shoulders ) * scale - 8, 13, scale, '600 ', shortNameOf( block.name ) );
			ctx.restore();

			return;
		}

		var room = widthAt( block.outline, box, middle.y ) * scale - 10;

		ctx.textBaseline = 'middle';
		ctx.fillStyle = soldOut ? colours.muted : colours.ink;

		var wrote = this.fitText( ctx, block.name, middle.x, middle.y - 11 / scale, room, 18, scale,
			'600 ', shortNameOf( block.name ) );

		// The second line only where there is room for it. In a fan of a dozen narrow blocks there
		// is not, and a price written across three of its neighbours helps nobody — the list
		// beside the plan carries the same sentence for every block, legibly, always.
		if ( wrote ) {
			ctx.fillStyle = soldOut ? colours.muted : colours.text;
			this.fitText( ctx, this.blockSummary( block ), middle.x, middle.y + 11 / scale, room, 13, scale, '' );
		}

		ctx.restore();
	};

	/**
	 * Write a line at the largest size that fits the space, or not at all.
	 *
	 * Sizes are screen pixels, not chart units — the same 18 units is a headline in a studio
	 * theatre and an invisible speck in a hall six thousand units across — and a line that will
	 * not fit even at its smallest is left out rather than allowed to run across the room.
	 */
	/** The middle of a block across one particular height, so a leaning block's name leans with it. */
	SeatmapWidget.prototype.middleAt = function ( block, box, y ) {
		var outline = block.outline;

		if ( ! outline || outline.length < 3 ) {
			return box.x + box.width / 2;
		}

		var crossings = [];

		for ( var i = 0, j = outline.length - 1; i < outline.length; j = i++ ) {
			var y1 = outline[ j ][ 1 ], y2 = outline[ i ][ 1 ];

			if ( ( y1 > y ) === ( y2 > y ) ) {
				continue;
			}

			crossings.push( outline[ j ][ 0 ] + ( ( y - y1 ) / ( y2 - y1 ) ) * ( outline[ i ][ 0 ] - outline[ j ][ 0 ] ) );
		}

		return crossings.length < 2
			? box.x + box.width / 2
			: ( Math.max.apply( null, crossings ) + Math.min.apply( null, crossings ) ) / 2;
	};

	SeatmapWidget.prototype.fitText = function ( ctx, text, x, y, room, size, scale, weight ) {
		if ( ! text || room <= 0 ) {
			return false;
		}

		// "Block 06" where it fits, "06" where only that does: the number is the half a buyer is
		// looking for, and a nameless shape on a plan of a dozen of them is no help at all.
		var forms = [ text ];
		var last = String( text ).split( /\s+/ ).pop();

		if ( last && last !== text ) {
			forms.push( last );
		}

		for ( var i = 0; i < forms.length; i++ ) {
			var trying = size;

			while ( trying >= 9 ) {
				ctx.font = weight + trying / scale + 'px system-ui, sans-serif';

				if ( ctx.measureText( forms[ i ] ).width * scale <= room ) {
					var ink = ctx.fillStyle;

					ctx.lineWidth = 3 / scale;
					ctx.strokeStyle = this.paper();
					ctx.lineJoin = 'round';
					ctx.strokeText( forms[ i ], x, y );
					ctx.fillStyle = ink;
					ctx.fillText( forms[ i ], x, y );

					return i === 0;
				}

				trying -= 1;
			}
		}

		return false;
	};

	/** The path of a block: its own outline where it has one, its box where it does not. */
	SeatmapWidget.prototype.traceBlock = function ( ctx, block ) {
		ctx.beginPath();

		if ( block.outline && block.outline.length > 2 ) {
			block.outline.forEach( function ( point, index ) {
				index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
			} );
			ctx.closePath();

			return;
		}

		if ( ctx.roundRect ) {
			ctx.roundRect( block.box.x, block.box.y, block.box.width, block.box.height, 12 );
		} else {
			ctx.rect( block.box.x, block.box.y, block.box.width, block.box.height );
		}
	};

	/** The zone most of a block sits in — used only to colour it. */
	SeatmapWidget.prototype.blockZone = function ( block ) {
		var counts = {};
		var best = null;

		block.seats.forEach( function ( seat ) {
			var key = seat.zoneKey || '';

			counts[ key ] = ( counts[ key ] || 0 ) + 1;

			if ( null === best || counts[ key ] > counts[ best ] ) {
				best = key;
			}
		} );

		return best;
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

		this.canvas.addEventListener( 'pointerleave', function () {
			if ( self.tipEl ) {
				self.tipEl.hidden = true;
			}

			self.canvas.style.cursor = '';
		} );

		this.canvas.addEventListener( 'pointermove', function ( event ) {
			if ( ! dragging ) {
				// A pen or a finger has no hover: the tooltip would appear under the fingertip
				// that is about to tap, which is the one place it cannot be read.
				if ( 'mouse' === event.pointerType ) {
					self.hover( event );
				}

				return;
			}

			if ( self.tipEl ) {
				self.tipEl.hidden = true;
			}

			var dx = event.clientX - last.x;
			var dy = event.clientY - last.y;
			moved += Math.abs( dx ) + Math.abs( dy );

			self.view.x += dx;
			self.view.y += dy;
			self.clampView();
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

	/** Where a pointer event landed, in the chart's own coordinates. */
	SeatmapWidget.prototype.pointOn = function ( event ) {
		var rect = this.canvas.getBoundingClientRect();
		var scale = this.baseScale * this.view.scale;

		return {
			x: ( event.clientX - rect.left - this.view.x ) / scale,
			y: ( event.clientY - rect.top - this.view.y ) / scale,
		};
	};

	/** The seat under a point, if there is one close enough to have been meant. */
	SeatmapWidget.prototype.seatAt = function ( point ) {
		var hit = null;
		var best = SEAT_RADIUS * 1.6;
		var self = this;

		if ( ! this.chairsShown() ) {
			return null; // Nothing is drawn as a chair, so nothing can be aimed at as one.
		}

		this.seats.forEach( function ( seat ) {
			// Only what is on screen can be hit, and two floors may occupy the same coordinates.
			// Which block a seat is in no longer comes into it: what is drawn can be chosen.
			if ( seat.floorKey !== self.floorKey ) {
				return;
			}

			var distance = Math.hypot( seat.x - point.x, seat.y - point.y );

			if ( distance < best ) {
				best = distance;
				hit = seat;
			}
		} );

		return hit;
	};

	/**
	 * The block under a point, tested against its real shape.
	 *
	 * Against the bounding box, the corner outside a leaning block belongs to it as far as a click
	 * is concerned — and in a fan of blocks those corners overlap, so a buyer aiming at one block
	 * opens another.
	 */
	SeatmapWidget.prototype.blockAt = function ( point ) {
		return this.blocksOnFloor().filter( function ( block ) {
			if ( block.outline && block.outline.length > 2 ) {
				return insideOutline( block.outline, point );
			}

			return point.x >= block.box.x && point.x <= block.box.x + block.box.width &&
				point.y >= block.box.y && point.y <= block.box.y + block.box.height;
		} )[ 0 ] || null;
	};

	/**
	 * A chair if one was aimed at, and otherwise the block that was.
	 *
	 * In that order rather than by which view is open, because the two are now the same view: at a
	 * distance a click lands on a block and goes into it; up close it lands on a chair. Clicking
	 * the empty floor of a block whose chairs are already drawn does nothing — refitting the view
	 * under somebody who was reaching for a seat is not help.
	 */
	SeatmapWidget.prototype.handleCanvasClick = function ( event ) {
		var point = this.pointOn( event );
		var hit = this.seatAt( point );

		if ( hit ) {
			this.toggleSeat( hit );

			return;
		}

		if ( ! this.chairsShown() ) {
			var block = this.blockAt( point );

			if ( block ) {
				this.enterBlock( block.id );
			}
		}
	};

	/**
	 * What is under the pointer, said in words.
	 *
	 * The plan is a picture, and a picture of four hundred circles cannot label any of them at a
	 * size anybody could read. So the label follows the pointer instead — the same sentence the
	 * seat's button carries for a screen reader.
	 */
	SeatmapWidget.prototype.hover = function ( event ) {
		if ( ! this.tipEl ) {
			return;
		}

		var point = this.pointOn( event );
		var seat = this.seatAt( point );
		var text = '';

		if ( seat ) {
			text = this.describeSeat( seat );
		} else if ( ! this.chairsShown() ) {
			var block = this.blockAt( point );

			text = block ? block.name + ' — ' + this.blockSummary( block ) : '';
		}

		this.canvas.style.cursor = text ? 'pointer' : '';

		if ( ! text ) {
			this.tipEl.hidden = true;

			return;
		}

		var rect = this.canvas.getBoundingClientRect();

		this.tipEl.textContent = text;
		this.tipEl.hidden = false;

		// Placed against the stage rather than the page, and kept inside it: a tooltip that hangs
		// off the edge of the plan is a tooltip half of which cannot be read.
		var left = event.clientX - rect.left;
		var top = event.clientY - rect.top;
		var width = this.tipEl.offsetWidth;

		this.tipEl.style.insetInlineStart = 'auto';
		this.tipEl.style.left = Math.max( 4, Math.min( left - width / 2, rect.width - width - 4 ) ) + 'px';
		this.tipEl.style.top = Math.max( 4, top - this.tipEl.offsetHeight - 12 ) + 'px';
	};

	/**
	 * Is this seat in the block currently open?
	 *
	 * A question about the list beside the plan, not about the plan: the canvas draws whatever is
	 * legible and in view, but a keyboard list of four thousand chairs is not a list, so that one
	 * stays with the block the buyer is working in.
	 */
	SeatmapWidget.prototype.inOpenBlock = function ( seat ) {
		var block = this.currentBlock();

		return !! block && block.floorKey === seat.floorKey && block.sectionKey === ( seat.sectionKey || null );
	};

	SeatmapWidget.prototype.enterBlock = function ( id ) {
		var block = this.blocks.filter( function ( entry ) { return entry.id === id; } )[ 0 ];

		if ( ! block ) {
			return;
		}

		this.mode = 'section';
		this.blockId = id;
		this.fitTo( block.box );
		this.zoomToChairs( block.box );
		this.syncStageControls();
		this.renderSeatList();
		this.renderAreaList();
		this.announce( this.i18n.inSection.replace( '%s', block.name ), true );

		// Focus follows the view. The button that was clicked has just been replaced by this
		// block's chairs, and leaving focus on nothing means the next Tab starts at the top of
		// the page and Escape reaches no one.
		var back = this.backEl && ! this.backEl.hidden ? this.backEl.querySelector( 'button' ) : null;

		if ( back ) {
			back.focus();
		}
	};

	SeatmapWidget.prototype.leaveBlock = function () {
		if ( this.blocksOnFloor().length < 2 ) {
			return; // Nothing to go back to.
		}

		this.mode = 'plan';
		this.blockId = null;
		this.resetView();
		this.syncStageControls();
		this.renderSeatList();
		this.renderAreaList();
		this.announce( this.i18n.chooseSection, true );

		var first = this.seatListEl && this.seatListEl.querySelector( '.seatmap-widget__block' );

		if ( first ) {
			first.focus();
		}
	};

	/**
	 * Put a box on screen, centred, with a margin.
	 *
	 * The transform is the one `paint` uses — translate by the view, then scale — so this is that
	 * arithmetic solved for the offset rather than a second idea about where things are.
	 */
	SeatmapWidget.prototype.fitTo = function ( box ) {
		if ( ! box || ! this.canvas ) {
			return;
		}

		var width = this.canvas.clientWidth || 800;
		var height = this.canvas.clientHeight || 600;
		var fit = Math.min( width / box.width, height / box.height ) * 0.88;

		this.view.scale = Math.min( MAX_ZOOM, Math.max( MIN_ZOOM, fit / this.baseScale ) );

		var scale = this.baseScale * this.view.scale;

		this.view.x = width / 2 - ( box.x + box.width / 2 ) * scale;
		this.view.y = height / 2 - ( box.y + box.height / 2 ) * scale;

		this.paint();
	};

	/**
	 * Zoom towards what is being looked at.
	 *
	 * Scaling about the canvas origin walks whatever the buyer was reading off the screen, so a
	 * wheel over a particular row keeps that row under the pointer, and the buttons keep the
	 * middle of the view where it was.
	 */
	/**
	 * Going into a block means seeing its chairs.
	 *
	 * Fitting the whole block to the canvas is the right framing for most rooms, but in a very
	 * large hall a whole block still shrinks its chairs to specks — and a buyer who has just
	 * chosen "Block 02" and been handed another outline has been given nothing. So the view is
	 * pushed in until chairs are chairs, and a block too tall to fit is shown from its front,
	 * because row A is what somebody who picked a block is looking for.
	 */
	SeatmapWidget.prototype.zoomToChairs = function ( box ) {
		if ( ! box || this.chairsLegible() ) {
			return;
		}

		this.view.scale = Math.min( MAX_ZOOM, SEAT_LEGIBLE_PX / ( SEAT_SIZE * this.baseScale ) );

		var scale = this.baseScale * this.view.scale;
		var width = this.canvas.clientWidth || 800;
		var height = this.canvas.clientHeight || 600;

		this.view.x = width / 2 - ( box.x + box.width / 2 ) * scale;
		this.view.y = box.height * scale > height
			? 24 - box.y * scale
			: height / 2 - ( box.y + box.height / 2 ) * scale;

		this.clampView();
		this.paint();
	};

	SeatmapWidget.prototype.zoomBy = function ( factor, event ) {
		var before = this.view.scale;
		var next = Math.min( MAX_ZOOM, Math.max( MIN_ZOOM, before * factor ) );

		if ( next === before ) {
			return;
		}

		var rect = this.canvas.getBoundingClientRect();
		var anchorX = event ? event.clientX - rect.left : rect.width / 2;
		var anchorY = event ? event.clientY - rect.top : rect.height / 2;
		var ratio = next / before;

		this.view.x = anchorX - ( anchorX - this.view.x ) * ratio;
		this.view.y = anchorY - ( anchorY - this.view.y ) * ratio;
		this.view.scale = next;
		this.clampView();
		this.paint();
	};

	/**
	 * Keep the plan on the screen.
	 *
	 * Panning with no limit is how somebody ends up looking at an empty rectangle with no way back
	 * except a reset button they have not noticed. The plan may be dragged until a quarter of it
	 * is left, and no further.
	 */
	SeatmapWidget.prototype.clampView = function () {
		if ( ! this.canvas ) {
			return;
		}

		var width = this.canvas.clientWidth || 800;
		var height = this.canvas.clientHeight || 600;
		var size = this.canvasSize();
		var scale = this.baseScale * this.view.scale;
		var planWidth = ( size.width || 1000 ) * scale;
		var planHeight = ( size.height || 800 ) * scale;
		var keep = 0.25;

		this.view.x = Math.min( width - planWidth * keep, Math.max( planWidth * ( keep - 1 ), this.view.x ) );
		this.view.y = Math.min( height - planHeight * keep, Math.max( planHeight * ( keep - 1 ), this.view.y ) );
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
				this.announce( ( this.seated ? this.i18n.maxSeats : this.i18n.maxTickets )
				.replace( '%d', this.formatCount( this.maxSeats ) ) );

				return;
			}

			// A seat arrives at full price; the buyer changes that in the summary if they want to.
			seat.ticketTypeId = this.defaultType ? this.defaultType.id : null;
			this.selected.push( seat );
			seat.state = 'selected';
		}

		// Only once the seat has really changed hands: a click refused for being sold or over the
		// limit must not quietly move the list to another block.
		this.followSeat( seat );

		this.paint();
		this.renderSeatList();
		this.renderSelection();
	};

	/**
	 * Let the list follow the buyer across the plan.
	 *
	 * Now that the chairs of every block are on screen at once, somebody can reach past the block
	 * they arrived in and take a seat in the next one. The list beside the plan follows them —
	 * *without* moving the view, because a map that jumps when a seat is taken is a map that
	 * loses the seat next to it.
	 */
	SeatmapWidget.prototype.followSeat = function ( seat ) {
		var id = seat.floorKey + '|' + ( seat.sectionKey || '' );

		if ( id === this.blockId || ! this.blocks.some( function ( block ) { return block.id === id; } ) ) {
			return;
		}

		this.mode = 'section';
		this.blockId = id;
		this.syncStageControls();
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

		/*
		 * The keyboard interface follows the plan: blocks while the plan is showing blocks, and one
		 * block's chairs while one is open. A list of every chair in the building beside a picture
		 * of six sections is two different answers to "what am I choosing from".
		 *
		 * The two are presented differently, though. The block list names things the picture cannot
		 * say legibly — what a section costs, how much of it is left — so it stays on the page. The
		 * chairs *are* the picture: four hundred numbered buttons under the plan is the same offer
		 * made a second time, and worse. So they go behind a disclosure.
		 *
		 * Behind a disclosure rather than clipped out of sight. Something that folds itself away
		 * when focus leaves it moves the page between a mouse going down and coming up, and a click
		 * then lands where nobody aimed — which is exactly what happened when this was tried. A
		 * <summary> is a real, named, focusable control that opens when a person asks it to and at
		 * no other time.
		 */
		if ( 'plan' === this.mode ) {
			this.renderBlockList();

			return;
		}

		/*
		 * There is no second way back here.
		 *
		 * The list used to carry its own "back to the whole venue", because it used to sit a long
		 * way below the one floating over the plan, with the standing offer in between. It does not
		 * any more: the two ended up stacked, and two identical buttons a centimetre apart is a
		 * question rather than an answer. The floating one takes focus the moment a block is
		 * opened, and Escape does the same thing, so nobody is stranded.
		 */
		var disclosure = document.createElement( 'details' );
		var summary = document.createElement( 'summary' );
		var body = document.createElement( 'div' );

		disclosure.className = 'seatmap-widget__list';
		// Whether it is open survives a repaint: a buyer who opened the list and then chose a seat
		// from it would otherwise have it shut in their face, with their focus inside it.
		disclosure.open = !! this.listOpen;
		summary.textContent = this.i18n.seatList;
		body.className = 'seatmap-widget__list-body';

		disclosure.addEventListener( 'toggle', function () {
			self.listOpen = disclosure.open;
		} );

		disclosure.appendChild( summary );
		disclosure.appendChild( body );
		this.seatListEl.appendChild( disclosure );

		// Grouped in the order the seats were published, which is the order they were drawn.
		var groups = [];
		var index = {};

		this.seats.forEach( function ( seat ) {
			if ( seat.floorKey !== self.floorKey || ! self.inOpenBlock( seat ) ) {
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
				body.appendChild( title );
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

			body.appendChild( rowEl );
		} );

		if ( activeKey ) {
			var restored = this.seatListEl.querySelector( '[data-seat-key="' + activeKey + '"]' );

			// Repainting the list must not throw a keyboard user back to the top of the page.
			if ( restored ) {
				restored.focus();
			}
		}
	};

	/**
	 * The blocks, as buttons.
	 *
	 * This is the whole overview for anyone not using the canvas — which is anyone on a screen
	 * reader, and anyone who simply prefers a list. It carries the same two facts the drawn block
	 * carries: what it costs to sit there and whether there is anything left.
	 */
	SeatmapWidget.prototype.renderBlockList = function () {
		var self = this;
		var heading = document.createElement( 'h4' );

		heading.textContent = this.i18n.chooseSection;
		this.seatListEl.appendChild( heading );

		var list = document.createElement( 'div' );
		list.className = 'seatmap-widget__blocks';

		this.blocksOnFloor().forEach( function ( block ) {
			var stats = self.blockStats( block );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'seatmap-widget__block';
			button.dataset.block = block.id;
			button.disabled = 0 === stats.available;
			button.setAttribute( 'aria-label', self.i18n.openSection.replace( '%s', block.name ) );

			var name = document.createElement( 'span' );
			name.className = 'seatmap-widget__block-name';
			name.textContent = block.name;

			var meta = document.createElement( 'span' );
			meta.className = 'seatmap-widget__block-meta';
			meta.textContent = self.blockSummary( block );

			button.appendChild( name );
			button.appendChild( meta );
			button.addEventListener( 'click', function () { self.enterBlock( block.id ); } );

			list.appendChild( button );
		} );

		this.seatListEl.appendChild( list );
	};

	/**
	 * A seat in words: where it is, what it costs, or that it is gone.
	 *
	 * One sentence, used by the button's label and by the tooltip that follows the pointer over the
	 * plan — so what a screen reader is told and what a sighted buyer reads are the same sentence,
	 * and neither can quietly fall behind the other.
	 */
	SeatmapWidget.prototype.describeSeat = function ( seat ) {
		var unavailable = 'available' !== seat.state && 'selected' !== seat.state;
		var template = unavailable ? this.i18n.seatUnavailable : this.i18n.seatLabel;

		return template
			.replace( '%1$s', seat.section || '' )
			.replace( '%2$s', seat.row || '' )
			.replace( '%3$s', seat.label )
			.replace( '%4$s', null == seat.amount ? '' : this.formatMoney( seat.amount ) );
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

		button.setAttribute( 'aria-label', this.describeSeat( seat ) );

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
			empty.textContent = this.seated ? this.i18n.noneSelected : this.i18n.noneChosen;
			this.selectionEl.appendChild( empty );
			this.totalEl.textContent = '';
			this.submitEl.disabled = true;

			return;
		}


		var total = 0;

		/*
		 * Description on one side, money on the other, and a way to take the line back.
		 *
		 * Clicking the chair again on the plan already removes it, but only if you can find it
		 * again — and after zooming into another section you cannot. A booking is undone here,
		 * where it was made, at any point before it is paid for.
		 */
		function line( description, amount, drop, chooser ) {
			var item = document.createElement( 'li' );
			var left = document.createElement( 'span' );
			var right = document.createElement( 'span' );

			left.textContent = description;

			if ( chooser ) {
				// Under the seat it belongs to, not beside it: on a phone the summary is a narrow
				// column, and a select sharing a line with a seat name and a price fits nowhere.
				left.appendChild( chooser );
				left.classList.add( 'seatmap-widget__line-start' );
			}

			right.className = 'seatmap-widget__line-end';
			right.appendChild( textSpan( amount ) );

			var remove = iconButton( 'close', self.i18n.removeLine.replace( '%s', description ), drop );
			remove.classList.add( 'seatmap-widget__drop' );
			right.appendChild( remove );

			item.appendChild( left );
			item.appendChild( right );

			return item;
		}

		this.selected.forEach( function ( seat ) {
			var type = self.typeById( seat.ticketTypeId );
			var amount = self.priceForType( seat.amount, type );

			total += amount;

			self.selectionEl.appendChild( line(
				[ seat.section, seat.row, seat.label ].filter( Boolean ).join( ' · ' ),
				self.formatMoney( amount ),
				function () { self.toggleSeat( seat ); },
				self.hasTypes
					? self.typeSelect( seat.ticketTypeId, function ( id ) {
						seat.ticketTypeId = id;
						self.renderSelection();
					} )
					: null
			) );
		} );

		chosenAreas.forEach( function ( area ) {
			self.areaLines( area ).forEach( function ( part ) {
				var amount = self.priceForType( area.amount, part.type ) * part.quantity;

				total += amount;

				self.selectionEl.appendChild( line(
					self.formatCount( part.quantity ) + ' × ' + area.label +
						( part.type && self.hasTypes ? ' · ' + part.type.name : '' ),
					self.formatMoney( amount ),
					function () { self.changeAreaQuantity( area, -part.quantity, part.typeId ); }
				) );
			} );
		} );

		this.totalEl.innerHTML = '';
		this.totalEl.appendChild( textSpan( this.i18n.total ) );
		this.totalEl.appendChild( textSpan( this.formatMoney( total ) ) );
		this.updateSubmitState();
	};

	/**
	 * Whether the button may be pressed.
	 *
	 * Two conditions, and both have to be asked from two places — the selection changes, and so
	 * does the arrival time — so the question lives here rather than being answered twice.
	 */
	SeatmapWidget.prototype.updateSubmitState = function () {
		if ( ! this.submitEl || this.busy ) {
			return;
		}

		this.submitEl.disabled = ! this.totalChosen() || this.needsEntrySlot();
	};

	/**
	 * Ask the server for n seats side by side, and take them.
	 *
	 * Anything the buyer had already picked is left alone and not sent: this is a different request
	 * from theirs, and quietly merging the two would hand somebody six seats when they asked for
	 * four.
	 */
	SeatmapWidget.prototype.reserveTogether = function ( quantity ) {
		if ( this.busy ) {
			return;
		}

		if ( this.needsEntrySlot() ) {
			this.announce( this.i18n.arrivalNeeded );

			return;
		}

		this.busy = true;
		this.announce( this.i18n.working );
		this.post( {
			event_public_id: this.config.eventPublicId,
			best_available: { quantity: quantity },
			entry_slot_id: this.entrySlotId,
			session_id: this.config.sessionId,
		} );
	};

	SeatmapWidget.prototype.reserve = function () {
		if ( this.busy || ! this.totalChosen() ) {
			return;
		}

		if ( this.needsEntrySlot() ) {
			this.announce( this.i18n.arrivalNeeded );

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
		var seatTypes = {};
		var areaTypes = {};

		if ( this.hasTypes ) {
			this.selected.forEach( function ( seat ) {
				if ( seat.ticketTypeId ) {
					seatTypes[ seat.id ] = seat.ticketTypeId;
				}
			} );
		}

		this.areas.forEach( function ( area ) {
			if ( ! ( area.quantity > 0 && area.id ) ) {
				return;
			}

			areas[ area.id ] = area.quantity;

			if ( ! self.hasTypes ) {
				return;
			}

			// The split, not the total: the server re-adds the parts and checks the sum against
			// what the area has left, so a request cannot claim one number and mean another.
			var split = {};

			self.areaLines( area ).forEach( function ( part ) {
				if ( part.typeId ) {
					split[ part.typeId ] = ( split[ part.typeId ] || 0 ) + part.quantity;
				}
			} );

			if ( Object.keys( split ).length ) {
				areaTypes[ area.id ] = split;
			}
		} );

		this.post( {
			event_public_id: this.config.eventPublicId,
			seat_ids: seatIds,
			areas: areas,
			seat_types: seatTypes,
			area_types: areaTypes,
			// Null on nearly every event. Where it is not, it is the window this booking is for,
			// and the server refuses the hold without it rather than guessing one.
			entry_slot_id: this.entrySlotId,
			// Only the public embed API asks for this — it has no session to know a browser by.
			// A shop's own route already knows whose cart this is and ignores it.
			session_id: this.config.sessionId,
		} );
	};

	/**
	 * Ask for a hold, and deal with every way that can end.
	 *
	 * Shared by the two ways of asking — chairs picked by hand, and n seats side by side chosen by
	 * the server — because what happens after the request is identical and two copies of it is two
	 * places for a lost error message to hide.
	 */
	SeatmapWidget.prototype.post = function ( body ) {
		var self = this;

		this.submitEl.disabled = true;

		fetch( this.holdEndpoint(), {
			method: 'POST',
			credentials: 'same-origin',
			// The header is named by whoever booted the widget: WordPress wants X-WP-Nonce, a
			// first-party site wants its own CSRF header. The widget does not care which.
			headers: nonceHeaders( this.config, { 'Content-Type': 'application/json' } ),
			body: JSON.stringify( body ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.body.cart_url ) {
					window.location.href = result.body.cart_url;

					return;
				}

				if ( result.ok ) {
					/*
					 * Held, with nowhere to send them.
					 *
					 * An organiser whose event has no website yet can still put this widget on a
					 * page; the seats are genuinely held, and saying so is better than navigating
					 * to nothing. What they cannot do here is pay, and that is the organiser's
					 * missing checkout rather than the buyer's mistake.
					 */
					self.announce( self.i18n.held.replace( '%s', formatClock( result.body.expires_at ) ) );

					return;
				}

				self.handleHoldFailure( result.body );
			} )
			.catch( function () {
				self.announce( self.i18n.genericError );
			} )
			.finally( function () {
				self.busy = false;
				self.submitEl.textContent = self.seated ? self.i18n.addToCart : self.i18n.reserveTickets;
				self.updateSubmitState();
			} );
	};

	/**
	 * Someone else got there first.
	 *
	 * Drop exactly the seats the API named, keep the rest of the selection, and refresh — the buyer
	 * should not have to start over because one seat went.
	 */
	/**
	 * Why a hold was refused, whichever host said so.
	 *
	 * The two hosts wrap a refusal differently and always have: a hosted site answers with the
	 * platform's own envelope, `{ error: { code, details } }`, and WordPress answers with a
	 * WP_Error, `{ code, data }`. Reading only one of them meant that on a hosted site every
	 * refusal — including "somebody just took that seat" — came out as "something went wrong",
	 * with the taken chair still sitting in the buyer's selection.
	 */
	SeatmapWidget.prototype.refusal = function ( body ) {
		var error = ( body && body.error ) || body || {};

		return {
			code: error.code || '',
			message: error.message || '',
			details: error.details || ( body && body.data ) || {},
		};
	};

	SeatmapWidget.prototype.handleHoldFailure = function ( body ) {
		var refusal = this.refusal( body );
		var taken = refusal.details.unavailable_seat_ids || [];
		var fullAreas = refusal.details.unavailable_capacity_object_ids || [];
		var self = this;

		/*
		 * There is no run of that many seats side by side.
		 *
		 * The server says how many there are, because "no" on its own sends the buyer back to the
		 * plan to work out for themselves whether three together exist. Zero means the house has
		 * nothing at all left, which is a different sentence.
		 */
		if ( 'no_seats_together' === refusal.code ) {
			var largest = refusal.details.largest_together || 0;

			this.announce( largest
				? this.i18n.someTogether.replace( '%d', this.formatCount( largest ) )
				: this.i18n.noneTogether );

			return;
		}

		// The window filled between this buyer choosing it and pressing the button. Unchoose it
		// and say so: everything they picked is still in the summary, and the next poll will show
		// which windows are left.
		if ( 'entry_slot_full' === refusal.code ) {
			this.entrySlotId = null;
			this.announce( this.i18n.arrivalFull );
			this.updateSubmitState();

			return;
		}

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
			this.announce( refusal.message || this.i18n.genericError );
		}

		this.cursor = null; // Force a full refresh rather than an incremental one.
		this.fetchAvailability();
		this.paint();
		this.renderSeatList();
		this.renderAreaList();
		this.renderSelection();
	};

	/** A time a person reads, in their own locale, from an ISO string a server wrote. */
	function formatClock( iso ) {
		var when = new Date( iso );

		if ( isNaN( when.getTime() ) ) {
			return '';
		}

		try {
			return when.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
		} catch ( error ) {
			return when.toISOString().slice( 11, 16 );
		}
	}

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

	/**
	 * Say something in the live region beside the summary.
	 *
	 * `quiet` is for navigation — "In Stalls" — which is news, not a problem. Without it every
	 * announcement wears the same red as "that seat was just taken", and a buyer who has done
	 * nothing wrong is told twice a minute that something is wrong.
	 */
	SeatmapWidget.prototype.announce = function ( message, quiet ) {
		this.messageEl.textContent = message;
		this.messageEl.classList.toggle( 'seatmap-widget__message--quiet', !! quiet );
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
	/**
	 * A plain count, in the digits the rest of the page uses.
	 *
	 * "250 places left" beside "€۶۵٬۰۰" is two writing systems in one line, and the price is the
	 * one that got attention first. Anything a buyer reads as a number goes through here.
	 */
	SeatmapWidget.prototype.formatCount = function ( value ) {
		if ( this.config.locale && window.Intl && window.Intl.NumberFormat ) {
			try {
				return new Intl.NumberFormat( this.config.locale ).format( value );
			} catch ( e ) {
				// A locale this browser has no data for. The plain digits are still a number.
			}
		}

		return String( value );
	};

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
