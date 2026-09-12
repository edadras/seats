/**
 * The chart model.
 *
 * A chart is floors, each holding an ordered list of objects. Everything the designer can place is
 * an object with a `type`, and the property panel is chosen by that type.
 *
 * Three ideas carry most of the design:
 *
 *   1. **Seat positions are computed, not stored.** A row is an anchor, a rotation, a curve and a
 *      seat spacing; where seat 7 sits follows from those. That is what lets someone type "17" into
 *      Number of seats, or drag Rotation to 198°, and have the row rearrange itself. Storing each
 *      seat's coordinates instead would make those fields impossible to implement honestly.
 *
 *   2. **Keys are identity; labels are presentation.** A seat's key never changes once created, so
 *      renumbering a row, renaming a section or flipping the whole chart cannot detach a seat from
 *      the ticket already sold for it.
 *
 *   3. **No availability lives here.** A chart describes a venue, not an event. Whether B12 is free
 *      is computed per event from overrides, holds and allocations (ADR-0002).
 */
( function ( global ) {
	'use strict';

	var Chart = {};

	/**
	 * A translated string.
	 *
	 * The chart model produces words — layer names, the publish checklist, and every refusal in
	 * `validate()` — so it reads the same catalogue the panel does rather than carrying English of
	 * its own. Resolved on each call, not captured: `SeatmapI18n` is loaded first but its catalogue
	 * arrives over the network afterwards, and a string captured at load time would be the fallback
	 * for the rest of the session.
	 */
	function t( key, replace ) {
		return global.SeatmapI18n.t( key, replace );
	}

	Chart.VERSION = 2;

	/** Seat diameter in chart units. Spacing is the gap between seats, so pitch = size + spacing. */
	Chart.SEAT_SIZE = 18;

	/**
	 * Layers, in back-to-front paint order.
	 *
	 * Only `interactive` holds things a customer can book. The rest are scenery, and separating them
	 * is what makes "select all objects in this layer" useful on a busy chart.
	 */
	Chart.LAYERS = [ 'surroundings', 'background', 'interactive', 'foreground' ];


	/** Object types that carry bookable places. */
	Chart.BOOKABLE_TYPES = [ 'row', 'section', 'area', 'table', 'booth' ];

	Chart.SEAT_LABEL_SCHEMES = {
		numeric: { label: '1, 2, 3', of: function ( i ) { return String( i + 1 ); } },
		alpha: { label: 'a, b, c', of: function ( i ) { return Chart.indexToLetters( i ).toLowerCase(); } },
		ALPHA: { label: 'A, B, C', of: function ( i ) { return Chart.indexToLetters( i ); } },
		odd: { label: '1, 3, 5', of: function ( i ) { return String( i * 2 + 1 ); } },
		even: { label: '2, 4, 6', of: function ( i ) { return String( ( i + 1 ) * 2 ); } },
		reverse: { label: 'n … 1', of: function ( i, total ) { return String( total - i ); } },
	};

	Chart.ROW_LABEL_POSITIONS = [ 'both', 'start', 'end', 'none' ];

	Chart.empty = function ( name ) {
		return {
			version: Chart.VERSION,
			name: name || t( 'panel.chart.untitled' ),
			focalPoint: null,
			categories: [],
			floors: [ Chart.newFloor( '1', t( 'panel.floors.level', { number: 1 } ) ) ],
		};
	};

	Chart.newFloor = function ( key, name ) {
		return {
			key: key,
			name: name,
			canvas: { width: 1200, height: 900, background: null },
			objects: [],
		};
	};

	/* ------------------------------------------------------------------ identity and naming */

	/** A, B … Z, AA, AB — the convention every venue already prints on its seats. */
	Chart.indexToLetters = function ( index ) {
		var name = '';

		index += 1;

		while ( index > 0 ) {
			var remainder = ( index - 1 ) % 26;
			name = String.fromCharCode( 65 + remainder ) + name;
			index = Math.floor( ( index - 1 ) / 26 );
		}

		return name;
	};

	Chart.slug = function ( value ) {
		return String( value == null ? '' : value )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 );
	};

	Chart.uniqueKey = function ( taken, base ) {
		var key = base || 'o';
		var suffix = 2;

		while ( taken.indexOf( key ) !== -1 ) {
			key = ( base || 'o' ) + '-' + suffix;
			suffix += 1;
		}

		return key;
	};

	/** Keys must be unique across the whole chart, since a key is what a sold seat points at. */
	Chart.allKeys = function ( chart ) {
		var keys = [];

		Chart.eachObject( chart, function ( object ) {
			keys.push( object.key );

			( object.seats || [] ).forEach( function ( seat ) {
				keys.push( seat.key );
			} );
		} );

		return keys;
	};

	/* ------------------------------------------------------------------------- traversal */

	/**
	 * Visit every object, descending into sections.
	 *
	 * The callback receives the object, its container (floor or section) and the floor it lives on,
	 * because almost everything that acts on an object also needs to know where it sits.
	 */
	Chart.eachObject = function ( chart, callback ) {
		( chart.floors || [] ).forEach( function ( floor ) {
			walk( floor.objects || [], floor, floor );
		} );

		function walk( objects, container, floor ) {
			objects.forEach( function ( object ) {
				callback( object, container, floor );

				if ( 'section' === object.type ) {
					walk( object.objects || [], object, floor );
				}
			} );
		}
	};

	Chart.findObject = function ( chart, key ) {
		var found = null;

		Chart.eachObject( chart, function ( object, container, floor ) {
			if ( object.key === key ) {
				found = { object: object, container: container, floor: floor };
			}
		} );

		return found;
	};

	/** Every seat in the chart, with the row or table that owns it. */
	Chart.eachSeat = function ( chart, callback ) {
		Chart.eachObject( chart, function ( object, container, floor ) {
			if ( 'row' !== object.type && 'table' !== object.type ) {
				return;
			}

			( object.seats || [] ).forEach( function ( seat, index ) {
				callback( seat, object, index, container, floor );
			} );
		} );
	};

	/**
	 * Total bookable places: named seats plus the capacity of every area, booth and
	 * whole-table booking.
	 *
	 * This is the number in the chart panel, and the number a plan limit is checked against.
	 */
	Chart.placeCount = function ( chart ) {
		var count = 0;

		Chart.eachObject( chart, function ( object ) {
			if ( 'row' === object.type ) {
				count += ( object.seats || [] ).filter( function ( seat ) {
					return 'empty' !== seat.type;
				} ).length;
			} else if ( 'table' === object.type ) {
				// A table booked as a whole is one place, however many chairs are drawn round it.
				count += 'table' === object.bookAs ? 1 : ( object.seats || [] ).length;
			} else if ( 'area' === object.type || 'booth' === object.type ) {
				count += Number( ( object.capacity && object.capacity.places ) || 0 );
			}
		} );

		return count;
	};

	/* --------------------------------------------------------------------------- categories */

	Chart.addCategory = function ( chart, label, color, accessible ) {
		var taken = ( chart.categories || [] ).map( function ( category ) {
			return category.key;
		} );

		var category = {
			key: Chart.uniqueKey( taken, Chart.slug( label ) || 'category' ),
			label: label,
			color: color || '#2d6cdf',
			accessible: !! accessible,
		};

		chart.categories = chart.categories || [];
		chart.categories.push( category );

		return category;
	};

	Chart.category = function ( chart, key ) {
		var found = null;

		( chart.categories || [] ).forEach( function ( category ) {
			if ( category.key === key ) {
				found = category;
			}
		} );

		return found;
	};

	/**
	 * Removing a category must also clear it from everything that referenced it, or the chart would
	 * keep failing "all objects are categorized" against a category that no longer exists.
	 */
	Chart.removeCategory = function ( chart, key ) {
		chart.categories = ( chart.categories || [] ).filter( function ( category ) {
			return category.key !== key;
		} );

		Chart.eachObject( chart, function ( object ) {
			if ( object.categoryKey === key ) {
				object.categoryKey = null;
			}

			( object.seats || [] ).forEach( function ( seat ) {
				if ( seat.categoryKey === key ) {
					seat.categoryKey = null;
				}
			} );
		} );

		return chart;
	};

	/** A seat's own category, falling back to the row's, then the section's. */
	Chart.effectiveCategory = function ( chart, seat, owner, container ) {
		var key =
			( seat && seat.categoryKey ) ||
			( owner && owner.categoryKey ) ||
			( container && container.categoryKey ) ||
			null;

		return key ? Chart.category( chart, key ) : null;
	};

	/* -------------------------------------------------------------------------------- rows */

	Chart.newRow = function ( chart, options ) {
		var settings = Object.assign(
			{
				x: 200,
				y: 200,
				rotation: 0,
				curve: 0,
				seatSpacing: 4,
				seats: 10,
				label: null,
				labelScheme: 'numeric',
				categoryKey: null,
				layer: 'interactive',
			},
			options || {}
		);

		var taken = Chart.allKeys( chart );
		var label = settings.label == null ? 'A' : String( settings.label );

		var row = {
			type: 'row',
			key: Chart.uniqueKey( taken, 'row-' + ( Chart.slug( label ) || 'a' ) ),
			layer: settings.layer,
			x: settings.x,
			y: settings.y,
			rotation: settings.rotation,
			curve: settings.curve,
			seatSpacing: settings.seatSpacing,
			categoryKey: settings.categoryKey,
			entrance: null,
			labeling: Chart.defaultRowLabeling( label ),
			seatLabeling: { scheme: settings.labelScheme, displayedType: t( 'panel.chart.seat' ), locked: false },
			seats: [],
		};

		for ( var i = 0; i < settings.seats; i++ ) {
			row.seats.push( Chart.newSeat( row, i, settings.labelScheme, settings.seats ) );
		}

		return row;
	};

	Chart.defaultRowLabeling = function ( label ) {
		return {
			enabled: true,
			label: label,
			displayedLabel: null, // null means "show `label`"; set it to override what the buyer sees
			position: 'both',
			displayedType: t( 'panel.chart.row' ),
			locked: false,
		};
	};

	Chart.newSeat = function ( row, index, scheme, total ) {
		var taken = ( row.seats || [] ).map( function ( seat ) {
			return seat.key;
		} );

		var label = Chart.seatLabel( index, total || ( row.seats || [] ).length + 1, scheme );

		return {
			type: 'seat',
			key: Chart.uniqueKey( taken, row.key + '-' + ( Chart.slug( label ) || index ) ),
			label: label,
			categoryKey: null,
			accessible: false,
			// The chair beside a wheelchair space, kept for whoever comes with the person using
			// it. Marked here so the platform can refuse to sell it on its own, which is the thing
			// venues currently do by blocking it and unblocking it by hand.
			companion: false,
			entrance: null,
		};
	};

	Chart.seatLabel = function ( index, total, scheme ) {
		var strategy = Chart.SEAT_LABEL_SCHEMES[ scheme ] || Chart.SEAT_LABEL_SCHEMES.numeric;

		return strategy.of( index, total );
	};

	/**
	 * Change the number of seats in a row, keeping the seats that are already there.
	 *
	 * Shrinking drops from the end rather than rebuilding, so seats that survive keep their keys —
	 * and therefore their sales.
	 */
	Chart.setRowSeatCount = function ( row, count ) {
		count = Math.max( 0, Math.min( 500, Math.round( count ) ) );

		var scheme = ( row.seatLabeling && row.seatLabeling.scheme ) || 'numeric';

		while ( row.seats.length > count ) {
			row.seats.pop();
		}

		while ( row.seats.length < count ) {
			row.seats.push( Chart.newSeat( row, row.seats.length, scheme, count ) );
		}

		return row;
	};

	/**
	 * Where each seat in a row actually sits.
	 *
	 * Straight rows are evenly spaced along a line. A curved row bows by `curve`, read as the
	 * sagitta as a percentage of the chord: the seats are then spread evenly *along the arc*, which
	 * is what keeps neighbours the same distance apart all the way round. Spacing them by equal
	 * angle instead would bunch them at the ends.
	 *
	 * @return {Array} one {x, y, rotation} per seat, in chart coordinates.
	 */
	Chart.rowSeatPositions = function ( row ) {
		var count = ( row.seats || [] ).length;

		if ( 0 === count ) {
			return [];
		}

		var pitch = Chart.SEAT_SIZE + ( Number( row.seatSpacing ) || 0 );
		var chord = ( count - 1 ) * pitch;
		var theta = ( ( Number( row.rotation ) || 0 ) * Math.PI ) / 180;
		var cos = Math.cos( theta );
		var sin = Math.sin( theta );
		var curve = Number( row.curve ) || 0;

		var local = [];

		if ( 0 === curve || 1 === count ) {
			for ( var i = 0; i < count; i++ ) {
				local.push( { x: i * pitch - chord / 2, y: 0, rotation: 0 } );
			}
		} else {
			// Circular arc through both ends with sagitta h; R follows from chord and sagitta.
			var h = ( curve / 100 ) * chord;
			var radius = Math.abs( h ) / 2 + ( chord * chord ) / ( 8 * Math.abs( h ) );
			var sweep = 2 * Math.asin( Math.min( 1, chord / ( 2 * radius ) ) );
			var sign = h < 0 ? -1 : 1;

			for ( var j = 0; j < count; j++ ) {
				var angle = -sweep / 2 + ( sweep * j ) / ( count - 1 );

				local.push( {
					x: radius * Math.sin( angle ),
					// Measured from the chord, so a curve of 0 and a curve approaching 0 agree.
					y: sign * ( radius * Math.cos( angle ) - radius * Math.cos( sweep / 2 ) ),
					// Seats on an arc face the centre of that arc.
					rotation: ( ( -sign * angle * 180 ) / Math.PI ),
				} );
			}
		}

		return local.map( function ( point ) {
			return {
				x: round( row.x + point.x * cos - point.y * sin ),
				y: round( row.y + point.x * sin + point.y * cos ),
				rotation: round( ( Number( row.rotation ) || 0 ) + point.rotation ),
			};
		} );
	};

	function round( value ) {
		return Math.round( value * 100 ) / 100;
	}

	/** Relabel a row's seats, leaving every key untouched. */
	Chart.renumberRow = function ( row, scheme ) {
		scheme = scheme || ( row.seatLabeling && row.seatLabeling.scheme ) || 'numeric';

		row.seatLabeling = row.seatLabeling || {};
		row.seatLabeling.scheme = scheme;

		var total = row.seats.length;

		row.seats.forEach( function ( seat, index ) {
			seat.label = Chart.seatLabel( index, total, scheme );
		} );

		return row;
	};

	/** The label a buyer sees for a row — the override if set, otherwise the row's own label. */
	Chart.displayedRowLabel = function ( row ) {
		var labeling = row.labeling || {};

		return labeling.displayedLabel != null && '' !== labeling.displayedLabel
			? labeling.displayedLabel
			: labeling.label;
	};

	/**
	 * Where a row's label is written, at one or both ends.
	 *
	 * Here rather than in the drawing code because two things need it and they must not drift: the
	 * designer draws the label, and it also has to be *clickable* — it is how a row is selected,
	 * since every point along the seats themselves belongs to a chair. A label drawn in one place
	 * and hit-tested in another is a control that works everywhere except where somebody aims.
	 *
	 * @return {Array<{x: number, y: number}>} empty when the row shows no label at all
	 */
	Chart.rowLabelPositions = function ( row, positions ) {
		var labeling = row.labeling || {};
		var where = labeling.position || 'both';

		if ( false === labeling.enabled || 'none' === where || ! Chart.displayedRowLabel( row ) ) {
			return [];
		}

		positions = positions || Chart.rowSeatPositions( row );

		if ( ! positions.length ) {
			return [];
		}

		// One seat's pitch beyond the end chair, which is where the eye expects the row's name.
		var pitch = Chart.SEAT_SIZE + ( Number( row.seatSpacing ) || 0 );
		var theta = ( ( Number( row.rotation ) || 0 ) * Math.PI ) / 180;
		var first = positions[ 0 ];
		var last = positions[ positions.length - 1 ];
		var out = [];

		if ( 'both' === where || 'start' === where ) {
			out.push( { x: first.x - Math.cos( theta ) * pitch, y: first.y - Math.sin( theta ) * pitch } );
		}

		if ( 'both' === where || 'end' === where ) {
			out.push( { x: last.x + Math.cos( theta ) * pitch, y: last.y + Math.sin( theta ) * pitch } );
		}

		return out;
	};

	/* --------------------------------------------------------------------------- sections */

	/**
	 * A section is a polygon that contains its own rows.
	 *
	 * At chart level it draws as a shape with a schematic of its rows; the designer double-clicks to
	 * go inside and work on the seats. That is what makes a 4,000-seat arena navigable.
	 */
	Chart.newSection = function ( chart, label, polygon, options ) {
		var settings = Object.assign( { categoryKey: null, color: null }, options || {} );
		var taken = Chart.allKeys( chart );

		return {
			type: 'section',
			key: Chart.uniqueKey( taken, 'section-' + ( Chart.slug( label ) || 'a' ) ),
			layer: 'interactive',
			label: label,
			labeling: { label: label, displayedLabel: null, visible: true, fontSize: 16, locked: false },
			polygon: polygon || Chart.rectanglePolygon( 200, 200, 320, 240 ),
			categoryKey: settings.categoryKey,
			color: settings.color,
			entrance: null,
			objects: [],
		};
	};

	Chart.rectanglePolygon = function ( x, y, width, height ) {
		return [
			[ x, y ],
			[ x + width, y ],
			[ x + width, y + height ],
			[ x, y + height ],
		];
	};

	Chart.polygonBounds = function ( polygon ) {
		var xs = polygon.map( function ( point ) { return point[ 0 ]; } );
		var ys = polygon.map( function ( point ) { return point[ 1 ]; } );

		var minX = Math.min.apply( null, xs );
		var minY = Math.min.apply( null, ys );

		return { x: minX, y: minY, width: Math.max.apply( null, xs ) - minX, height: Math.max.apply( null, ys ) - minY };
	};

	Chart.polygonCentroid = function ( polygon ) {
		var bounds = Chart.polygonBounds( polygon );

		return { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 };
	};

	/* ------------------------------------------------------- areas, tables, booths, scenery */

	/**
	 * A capacity object: general admission (any number up to `places`) or fixed occupancy.
	 *
	 * Unlike a row, nobody picks a specific spot — the buyer picks a quantity, so `places` is the
	 * whole inventory model for it.
	 */
	Chart.newArea = function ( chart, label, options ) {
		var settings = Object.assign(
			{
				x: 200, y: 200, width: 320, height: 120, rotation: 0, cornerRadius: 8,
				kind: 'rect', capacityType: 'generalAdmission', places: 100, categoryKey: null,
			},
			options || {}
		);

		return {
			type: 'area',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'area-' + ( Chart.slug( label ) || 'a' ) ),
			layer: 'interactive',
			shape: {
				kind: settings.kind,
				x: settings.x,
				y: settings.y,
				width: settings.width,
				height: settings.height,
				rotation: settings.rotation,
				cornerRadius: settings.cornerRadius,
				points: settings.points || null,
			},
			translucent: false,
			scale: 1,
			categoryKey: settings.categoryKey,
			entrance: null,
			labeling: {
				label: label,
				displayedLabel: null,
				visible: true,
				fontSize: 20,
				positionX: 0,
				positionY: 0,
				locked: false,
			},
			capacity: { type: settings.capacityType, places: settings.places },
		};
	};

	/**
	 * A table with chairs around it.
	 *
	 * `bookAs` decides whether a buyer takes one chair or the whole table — the difference between
	 * a comedy club and a gala dinner, and the reason a table counts as one place or many.
	 */
	Chart.newTable = function ( chart, label, options ) {
		var settings = Object.assign(
			{
				x: 300, y: 300, shape: 'round', width: 120, height: 120, rotation: 0,
				seats: 8, bookAs: 'seat', categoryKey: null, labelScheme: 'numeric',
			},
			options || {}
		);

		var table = {
			type: 'table',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'table-' + ( Chart.slug( label ) || 'a' ) ),
			layer: 'interactive',
			label: label,
			labeling: { label: label, displayedLabel: null, visible: true, fontSize: 14, locked: false },
			shape: settings.shape,
			x: settings.x,
			y: settings.y,
			width: settings.width,
			height: settings.height,
			rotation: settings.rotation,
			bookAs: settings.bookAs,
			categoryKey: settings.categoryKey,
			entrance: null,
			seatLabeling: { scheme: settings.labelScheme, displayedType: t( 'panel.chart.seat' ), locked: false },
			seats: [],
		};

		for ( var i = 0; i < settings.seats; i++ ) {
			table.seats.push( Chart.newSeat( table, i, settings.labelScheme, settings.seats ) );
		}

		return table;
	};

	/** Chairs spaced evenly around a table — round on a circle, rectangular around the perimeter. */
	Chart.tableSeatPositions = function ( table ) {
		var count = ( table.seats || [] ).length;

		if ( 0 === count ) {
			return [];
		}

		var theta = ( ( Number( table.rotation ) || 0 ) * Math.PI ) / 180;
		var cos = Math.cos( theta );
		var sin = Math.sin( theta );
		var margin = Chart.SEAT_SIZE * 0.9;
		var local = [];

		if ( 'round' === table.shape ) {
			var radius = Math.max( table.width, table.height ) / 2 + margin;

			for ( var i = 0; i < count; i++ ) {
				var angle = ( 2 * Math.PI * i ) / count - Math.PI / 2;

				local.push( {
					x: radius * Math.cos( angle ),
					y: radius * Math.sin( angle ),
					rotation: ( angle * 180 ) / Math.PI + 90,
				} );
			}
		} else {
			// Walk the perimeter at constant spacing so chairs do not bunch at the corners.
			var w = table.width / 2 + margin;
			var h = table.height / 2 + margin;
			var perimeter = 2 * ( table.width + table.height );

			for ( var j = 0; j < count; j++ ) {
				var distance = ( perimeter * j ) / count;
				local.push( perimeterPoint( distance, table.width, table.height, w, h ) );
			}
		}

		return local.map( function ( point ) {
			return {
				x: round( table.x + point.x * cos - point.y * sin ),
				y: round( table.y + point.x * sin + point.y * cos ),
				rotation: round( ( Number( table.rotation ) || 0 ) + point.rotation ),
			};
		} );
	};

	function perimeterPoint( distance, width, height, w, h ) {
		if ( distance < width ) {
			return { x: -width / 2 + distance, y: -h, rotation: 0 };
		}

		distance -= width;

		if ( distance < height ) {
			return { x: w, y: -height / 2 + distance, rotation: 90 };
		}

		distance -= height;

		if ( distance < width ) {
			return { x: width / 2 - distance, y: h, rotation: 180 };
		}

		distance -= width;

		return { x: -w, y: height / 2 - distance, rotation: 270 };
	}

	Chart.newBooth = function ( chart, label, options ) {
		var settings = Object.assign(
			{ x: 200, y: 200, width: 140, height: 90, rotation: 0, places: 4, categoryKey: null },
			options || {}
		);

		return {
			type: 'booth',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'booth-' + ( Chart.slug( label ) || 'a' ) ),
			layer: 'interactive',
			label: label,
			labeling: { label: label, displayedLabel: null, visible: true, fontSize: 14, locked: false },
			shape: {
				kind: 'rect',
				x: settings.x, y: settings.y, width: settings.width, height: settings.height,
				rotation: settings.rotation, cornerRadius: 4,
			},
			categoryKey: settings.categoryKey,
			entrance: null,
			// A booth is sold whole, to a fixed number of people.
			capacity: { type: 'fixed', places: settings.places },
		};
	};

	Chart.newText = function ( chart, text, options ) {
		var settings = Object.assign( { x: 200, y: 200, fontSize: 18, color: null, rotation: 0, layer: 'foreground' }, options || {} );

		return {
			type: 'text',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'text-' + ( Chart.slug( text ) || 'a' ) ),
			layer: settings.layer,
			text: text,
			x: settings.x, y: settings.y,
			fontSize: settings.fontSize,
			color: settings.color,
			rotation: settings.rotation,
		};
	};

	Chart.newShape = function ( chart, kind, options ) {
		var settings = Object.assign(
			{ x: 200, y: 200, width: 200, height: 80, rotation: 0, cornerRadius: 4, fill: null, label: null, layer: 'background' },
			options || {}
		);

		return {
			type: 'shape',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'shape-' + kind ),
			layer: settings.layer,
			kind: kind, // stage | wall | aisle | entrance | exit | rect | ellipse | polygon | line
			x: settings.x, y: settings.y,
			width: settings.width, height: settings.height,
			rotation: settings.rotation,
			cornerRadius: settings.cornerRadius,
			points: settings.points || null,
			fill: settings.fill,
			label: settings.label,
		};
	};

	Chart.newImage = function ( chart, href, options ) {
		var settings = Object.assign( { x: 100, y: 100, width: 320, height: 240, rotation: 0, opacity: 1, layer: 'surroundings' }, options || {} );

		return {
			type: 'image',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'image' ),
			// Usually a scanned floor plan being traced over, so it belongs behind everything.
			layer: settings.layer,
			href: href,
			x: settings.x, y: settings.y,
			width: settings.width, height: settings.height,
			rotation: settings.rotation,
			opacity: settings.opacity,
		};
	};

	Chart.newIcon = function ( chart, name, options ) {
		var settings = Object.assign( { x: 200, y: 200, size: 22, rotation: 0, layer: 'foreground' }, options || {} );

		return {
			type: 'icon',
			key: Chart.uniqueKey( Chart.allKeys( chart ), 'icon-' + name ),
			layer: settings.layer,
			name: name, // wheelchair | toilets | bar | food | entrance | exit | stairs | lift
			x: settings.x, y: settings.y,
			size: settings.size,
			rotation: settings.rotation,
		};
	};

	/* ------------------------------------------------------------------------ focal point */

	/**
	 * The point the venue faces — usually the middle of the stage.
	 *
	 * It is what "best available" sorts towards and what a view-from-seat render points at, so a
	 * chart without one is flagged in the validation panel.
	 */
	Chart.setFocalPoint = function ( chart, x, y ) {
		chart.focalPoint = { x: round( x ), y: round( y ) };

		return chart;
	};

	/* ------------------------------------------------------------------------- validation */

	/**
	 * The checklist shown in the chart panel.
	 *
	 * Each check answers a question that only matters once the chart is sold against: a duplicate
	 * label means two tickets read the same, an uncategorised object cannot be priced, and a chart
	 * with no focal point cannot sort seats by how good they are.
	 */
	Chart.validate = function ( chart ) {
		var errors = [];
		var warnings = [];
		var duplicates = Chart.findDuplicateLabels( chart );
		var unlabeled = [];
		var uncategorized = [];
		var typesPerCategory = {};
		var positions = [];

		Chart.eachObject( chart, function ( object, container ) {
			if ( Chart.BOOKABLE_TYPES.indexOf( object.type ) === -1 ) {
				return;
			}

			if ( ! Chart.objectLabel( object ) ) {
				unlabeled.push( object.key );
			}

			// A section carries its rows' category rather than one of its own, so it is only
			// uncategorised when nothing inside it is categorised either.
			var categoryKey = object.categoryKey || ( container && container.categoryKey ) || null;

			if ( ! categoryKey && 'section' !== object.type ) {
				uncategorized.push( object.key );
			}

			if ( categoryKey ) {
				typesPerCategory[ categoryKey ] = typesPerCategory[ categoryKey ] || {};
				typesPerCategory[ categoryKey ][ object.type ] = true;
			}

			if ( 'row' === object.type ) {
				Chart.rowSeatPositions( object ).forEach( function ( point, index ) {
					positions.push( [ point.x, point.y, Chart.seatPath( object, index ) ] );
				} );
			}
		} );

		( chart.floors || [] ).forEach( function ( floor ) {
			Chart.eachObjectOnFloor( floor, function ( object ) {
				if ( 'row' !== object.type ) {
					return;
				}

				Chart.rowSeatPositions( object ).forEach( function ( point ) {
					if (
						point.x < 0 || point.y < 0 ||
						point.x > floor.canvas.width || point.y > floor.canvas.height
					) {
						errors.push( {
							code: 'seat_off_canvas',
							message: t( 'panel.chart.issues.seat_off_canvas', {
								row: Chart.displayedRowLabel( object ),
							} ),
							objectKey: object.key,
						} );
					}
				} );
			} );
		} );

		duplicates.forEach( function ( duplicate ) {
			errors.push( {
				code: 'duplicate_label',
				message: t( 'panel.chart.issues.duplicate_label', { label: duplicate } ),
			} );
		} );

		unlabeled.forEach( function ( key ) {
			errors.push( {
				code: 'object_not_labeled',
				message: t( 'panel.chart.issues.object_not_labeled' ),
				objectKey: key,
			} );
		} );

		uncategorized.forEach( function ( key ) {
			warnings.push( {
				code: 'object_not_categorized',
				message: t( 'panel.chart.issues.object_not_categorized' ),
				objectKey: key,
			} );
		} );

		var mixedCategories = Object.keys( typesPerCategory ).filter( function ( key ) {
			return Object.keys( typesPerCategory[ key ] ).length > 1;
		} );

		mixedCategories.forEach( function ( key ) {
			warnings.push( {
				code: 'category_spans_object_types',
				message: t( 'panel.chart.issues.category_spans_object_types', { key: key } ),
			} );
		} );

		var places = Chart.placeCount( chart );

		if ( 0 === places ) {
			errors.push( { code: 'no_places', message: t( 'panel.chart.issues.no_places' ) } );
		}

		Chart.findOverlaps( positions ).forEach( function ( pair ) {
			warnings.push( {
				code: 'seats_overlap',
				message: t( 'panel.chart.issues.seats_overlap', { first: pair[ 0 ], second: pair[ 1 ] } ),
			} );
		} );

		// The panel's checklist, in the order the designer shows it.
		var checks = [
			{ code: 'no_duplicate_objects', ok: 0 === duplicates.length },
			{ code: 'all_labeled', ok: 0 === unlabeled.length },
			{ code: 'all_categorized', ok: 0 === uncategorized.length },
			{ code: 'one_category_per_type', ok: 0 === mixedCategories.length },
			{ code: 'focal_point', ok: !! chart.focalPoint },
		].map( function ( check ) {
			check.label = t( 'panel.chart.checks.' + check.code );

			return check;
		} );

		return {
			valid: 0 === errors.length,
			places: places,
			checks: checks,
			errors: errors,
			warnings: warnings,
		};
	};

	Chart.eachObjectOnFloor = function ( floor, callback ) {
		( floor.objects || [] ).forEach( function walk( object ) {
			callback( object );

			if ( 'section' === object.type ) {
				( object.objects || [] ).forEach( walk );
			}
		} );
	};

	Chart.objectLabel = function ( object ) {
		if ( 'row' === object.type ) {
			return Chart.displayedRowLabel( object );
		}

		if ( object.labeling ) {
			return object.labeling.displayedLabel || object.labeling.label;
		}

		return object.label || null;
	};

	Chart.seatPath = function ( row, index ) {
		return Chart.displayedRowLabel( row ) + ( row.seats[ index ] ? row.seats[ index ].label : '' );
	};

	/**
	 * Duplicate labels, scoped the way a ticket reads them.
	 *
	 * Two rows both called "A" are only a problem when they are in the same section — "Stalls A" and
	 * "Circle A" are unambiguous and completely normal.
	 */
	Chart.findDuplicateLabels = function ( chart ) {
		var seen = {};
		var duplicates = [];

		Chart.eachObject( chart, function ( object, container ) {
			if ( Chart.BOOKABLE_TYPES.indexOf( object.type ) === -1 ) {
				return;
			}

			var label = Chart.objectLabel( object );

			if ( ! label ) {
				return;
			}

			var scope = ( container && container.key ? container.key : 'floor' ) + '|' + object.type + '|' + label;

			if ( seen[ scope ] && duplicates.indexOf( label ) === -1 ) {
				duplicates.push( label );
			}

			seen[ scope ] = true;

			if ( 'row' === object.type ) {
				var seatSeen = {};

				( object.seats || [] ).forEach( function ( seat ) {
					if ( seatSeen[ seat.label ] ) {
						var path = label + seat.label;

						if ( duplicates.indexOf( path ) === -1 ) {
							duplicates.push( path );
						}
					}

					seatSeen[ seat.label ] = true;
				} );
			}
		} );

		return duplicates;
	};

	/** Grid-bucketed: a pairwise scan would be unusable on a 20,000-seat chart. */
	Chart.findOverlaps = function ( positions ) {
		var buckets = {};
		var overlaps = [];
		var cell = 8;

		for ( var i = 0; i < positions.length; i++ ) {
			var x = positions[ i ][ 0 ];
			var y = positions[ i ][ 1 ];
			var id = positions[ i ][ 2 ];
			var bx = Math.floor( x / cell );
			var by = Math.floor( y / cell );

			for ( var dx = -1; dx <= 1; dx++ ) {
				for ( var dy = -1; dy <= 1; dy++ ) {
					var bucket = buckets[ bx + dx + ':' + ( by + dy ) ] || [];

					for ( var b = 0; b < bucket.length; b++ ) {
						if ( Math.hypot( x - bucket[ b ][ 0 ], y - bucket[ b ][ 1 ] ) < cell ) {
							overlaps.push( [ bucket[ b ][ 2 ], id ] );

							if ( overlaps.length >= 25 ) {
								return overlaps;
							}
						}
					}
				}
			}

			var key = bx + ':' + by;
			buckets[ key ] = buckets[ key ] || [];
			buckets[ key ].push( [ x, y, id ] );
		}

		return overlaps;
	};

	Chart.snap = function ( value, grid ) {
		return grid > 0 ? Math.round( value / grid ) * grid : value;
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = Chart;
	} else {
		global.SeatmapChart = Chart;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
