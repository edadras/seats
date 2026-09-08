/**
 * Geometry model for the seat map editor.
 *
 * Deliberately separate from rendering and from the DOM: it is the piece that decides what a map
 * *is*, so it must be testable on its own and must never depend on a canvas existing.
 *
 * Two rules it enforces everywhere:
 *   - keys are stable. A seat's (section, row, seat) key triple is what carries its identity — and
 *     therefore its sales history — across a republish, so keys are generated once and never
 *     rewritten when something is renamed or moved.
 *   - the geometry holds no availability. Sold and held are computed per event by the server.
 */
( function ( global ) {
	'use strict';

	var Geometry = {};

	Geometry.empty = function () {
		return {
			canvas: { width: 1200, height: 800, background: null },
			sections: [],
			shapes: [],
			texts: [],
		};
	};

	/** Row names run A…Z, then AA, AB — the convention every venue already uses. */
	Geometry.indexToLetters = function ( index ) {
		var name = '';

		index += 1;

		while ( index > 0 ) {
			var remainder = ( index - 1 ) % 26;
			name = String.fromCharCode( 65 + remainder ) + name;
			index = Math.floor( ( index - 1 ) / 26 );
		}

		return name;
	};

	Geometry.uniqueKey = function ( existing, base ) {
		var key = base;
		var suffix = 2;

		while ( existing.indexOf( key ) !== -1 ) {
			key = base + '-' + suffix;
			suffix += 1;
		}

		return key;
	};

	Geometry.addSection = function ( geometry, name, color ) {
		var keys = geometry.sections.map( function ( section ) {
			return section.key;
		} );

		var section = {
			key: Geometry.uniqueKey( keys, Geometry.slug( name ) || 'section' ),
			name: name,
			color: color || '#2d6cdf',
			rows: [],
		};

		geometry.sections.push( section );

		return section;
	};

	Geometry.slug = function ( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 );
	};

	/**
	 * A straight block of rows.
	 *
	 * @param {object} options rows, seatsPerRow, x, y, rowGap, seatGap, startRowIndex, zoneKey,
	 *                         numbering ('ltr' | 'rtl' | 'odd-even'), skip (list of seat numbers).
	 */
	Geometry.addStraightRows = function ( section, options ) {
		var settings = Object.assign(
			{
				rows: 5,
				seatsPerRow: 10,
				x: 200,
				y: 200,
				rowGap: 34,
				seatGap: 30,
				zoneKey: null,
				numbering: 'ltr',
				shape: 'circle',
			},
			options || {}
		);

		var created = [];

		for ( var r = 0; r < settings.rows; r++ ) {
			var rowName = Geometry.indexToLetters( section.rows.length );
			var row = Geometry.createRow( section, rowName );

			for ( var s = 0; s < settings.seatsPerRow; s++ ) {
				var label = Geometry.seatLabel( s, settings.seatsPerRow, settings.numbering );

				Geometry.createSeat( row, {
					label: label,
					x: settings.x + s * settings.seatGap,
					y: settings.y + r * settings.rowGap,
					shape: settings.shape,
					zone_key: settings.zoneKey,
				} );
			}

			created.push( row );
		}

		return created;
	};

	/**
	 * A curved block, the shape most auditoriums actually are.
	 *
	 * Each row sits on an arc of increasing radius so the spacing between neighbours stays even as
	 * rows get further from the stage — laying curved rows out with a fixed angular step instead
	 * would spread the back rows apart.
	 */
	Geometry.addCurvedRows = function ( section, options ) {
		var settings = Object.assign(
			{
				rows: 5,
				seatsPerRow: 12,
				centerX: 600,
				centerY: 700,
				innerRadius: 220,
				rowGap: 34,
				seatGap: 30,
				zoneKey: null,
				numbering: 'ltr',
				shape: 'circle',
			},
			options || {}
		);

		var created = [];

		for ( var r = 0; r < settings.rows; r++ ) {
			var radius = settings.innerRadius + r * settings.rowGap;
			var step = settings.seatGap / radius; // constant arc length, not constant angle
			var start = -( ( settings.seatsPerRow - 1 ) / 2 ) * step;

			var rowName = Geometry.indexToLetters( section.rows.length );
			var row = Geometry.createRow( section, rowName );

			for ( var s = 0; s < settings.seatsPerRow; s++ ) {
				var angle = start + s * step;
				var label = Geometry.seatLabel( s, settings.seatsPerRow, settings.numbering );

				Geometry.createSeat( row, {
					label: label,
					x: settings.centerX + Math.sin( angle ) * radius,
					y: settings.centerY - Math.cos( angle ) * radius,
					// Seats face the stage, so each is rotated to match its position on the arc.
					rotation: ( angle * 180 ) / Math.PI,
					shape: settings.shape,
					zone_key: settings.zoneKey,
				} );
			}

			created.push( row );
		}

		return created;
	};

	Geometry.seatLabel = function ( index, total, numbering ) {
		switch ( numbering ) {
			case 'rtl':
				return String( total - index );
			case 'odd-even':
				// Continental numbering: odds one way from the centre, evens the other.
				return String( index * 2 + 1 );
			default:
				return String( index + 1 );
		}
	};

	Geometry.createRow = function ( section, name ) {
		var keys = section.rows.map( function ( row ) {
			return row.key;
		} );

		var row = {
			key: Geometry.uniqueKey( keys, Geometry.slug( name ) || 'row' ),
			name: name,
			seats: [],
		};

		section.rows.push( row );

		return row;
	};

	Geometry.createSeat = function ( row, attributes ) {
		var keys = row.seats.map( function ( seat ) {
			return seat.key;
		} );

		var seat = {
			key: Geometry.uniqueKey( keys, Geometry.slug( attributes.label ) || 's' ),
			label: String( attributes.label ),
			x: Math.round( attributes.x * 100 ) / 100,
			y: Math.round( attributes.y * 100 ) / 100,
			rotation: attributes.rotation || 0,
			shape: attributes.shape || 'circle',
			zone_key: attributes.zone_key || null,
			accessible: !! attributes.accessible,
		};

		row.seats.push( seat );

		return seat;
	};

	/**
	 * Renumber a row left to right by position.
	 *
	 * Labels change; keys do not. That distinction is the whole point — a seat that has been sold
	 * keeps its identity even when the organiser decides row A should count from the other side.
	 */
	Geometry.renumberRow = function ( row, numbering ) {
		var ordered = row.seats.slice().sort( function ( a, b ) {
			return a.x - b.x;
		} );

		ordered.forEach( function ( seat, index ) {
			seat.label = Geometry.seatLabel( index, ordered.length, numbering || 'ltr' );
		} );

		return row;
	};

	/** Move a set of seats, identified by key triple. */
	Geometry.moveSeats = function ( geometry, keys, dx, dy ) {
		Geometry.eachSeat( geometry, function ( seat, row, section ) {
			if ( keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) !== -1 ) {
				seat.x = Math.round( ( seat.x + dx ) * 100 ) / 100;
				seat.y = Math.round( ( seat.y + dy ) * 100 ) / 100;
			}
		} );

		return geometry;
	};

	Geometry.deleteSeats = function ( geometry, keys ) {
		geometry.sections.forEach( function ( section ) {
			section.rows.forEach( function ( row ) {
				row.seats = row.seats.filter( function ( seat ) {
					return keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) === -1;
				} );
			} );

			section.rows = section.rows.filter( function ( row ) {
				return row.seats.length > 0;
			} );
		} );

		return geometry;
	};

	/**
	 * Duplicate seats, offset by a delta.
	 *
	 * Copies land in the same row as their source and take fresh keys — a pasted seat is a new
	 * chair, not a second reference to an existing one.
	 */
	Geometry.duplicateSeats = function ( geometry, keys, dx, dy ) {
		var created = [];

		geometry.sections.forEach( function ( section ) {
			section.rows.forEach( function ( row ) {
				row.seats.slice().forEach( function ( seat ) {
					if ( keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) === -1 ) {
						return;
					}

					var copy = Geometry.createSeat( row, {
						label: seat.label,
						x: seat.x + dx,
						y: seat.y + dy,
						rotation: seat.rotation,
						shape: seat.shape,
						zone_key: seat.zone_key,
						accessible: seat.accessible,
					} );

					created.push( section.key + '/' + row.key + '/' + copy.key );
				} );
			} );
		} );

		return created;
	};

	Geometry.assignZone = function ( geometry, keys, zoneKey ) {
		Geometry.eachSeat( geometry, function ( seat, row, section ) {
			if ( keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) !== -1 ) {
				seat.zone_key = zoneKey;
			}
		} );

		return geometry;
	};

	Geometry.eachSeat = function ( geometry, callback ) {
		( geometry.sections || [] ).forEach( function ( section ) {
			( section.rows || [] ).forEach( function ( row ) {
				( row.seats || [] ).forEach( function ( seat ) {
					callback( seat, row, section );
				} );
			} );
		} );
	};

	Geometry.seatCount = function ( geometry ) {
		var count = 0;

		Geometry.eachSeat( geometry, function () {
			count += 1;
		} );

		return count;
	};

	Geometry.snap = function ( value, grid ) {
		return grid > 0 ? Math.round( value / grid ) * grid : value;
	};

	/**
	 * Align a selection.
	 *
	 * Aligning to the mean rather than to the first-selected seat: dragging a row into place then
	 * aligning should tidy it, not jerk it to wherever the click happened to start.
	 */
	Geometry.align = function ( geometry, keys, axis ) {
		var selected = [];

		Geometry.eachSeat( geometry, function ( seat, row, section ) {
			if ( keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) !== -1 ) {
				selected.push( seat );
			}
		} );

		if ( selected.length < 2 ) {
			return geometry;
		}

		var mean =
			selected.reduce( function ( total, seat ) {
				return total + seat[ axis ];
			}, 0 ) / selected.length;

		selected.forEach( function ( seat ) {
			seat[ axis ] = Math.round( mean * 100 ) / 100;
		} );

		return geometry;
	};

	/** Space a selection evenly between its own extremes. */
	Geometry.distribute = function ( geometry, keys, axis ) {
		var selected = [];

		Geometry.eachSeat( geometry, function ( seat, row, section ) {
			if ( keys.indexOf( section.key + '/' + row.key + '/' + seat.key ) !== -1 ) {
				selected.push( seat );
			}
		} );

		if ( selected.length < 3 ) {
			return geometry;
		}

		selected.sort( function ( a, b ) {
			return a[ axis ] - b[ axis ];
		} );

		var first = selected[ 0 ][ axis ];
		var last = selected[ selected.length - 1 ][ axis ];
		var step = ( last - first ) / ( selected.length - 1 );

		selected.forEach( function ( seat, index ) {
			seat[ axis ] = Math.round( ( first + index * step ) * 100 ) / 100;
		} );

		return geometry;
	};

	Geometry.addShape = function ( geometry, shape ) {
		geometry.shapes = geometry.shapes || [];
		geometry.shapes.push(
			Object.assign( { kind: 'rect', x: 100, y: 100, width: 200, height: 60, rotation: 0 }, shape )
		);

		return geometry;
	};

	Geometry.addText = function ( geometry, text ) {
		geometry.texts = geometry.texts || [];
		geometry.texts.push( Object.assign( { text: 'Label', x: 100, y: 100, size: 16 }, text ) );

		return geometry;
	};

	/**
	 * Client-side validation, mirroring the server's rules.
	 *
	 * The server validates again and is the authority; this exists so the organiser sees a problem
	 * while they are still looking at the thing that caused it.
	 */
	Geometry.validate = function ( geometry ) {
		var errors = [];
		var warnings = [];
		var sectionKeys = {};
		var positions = [];

		if ( ! geometry.sections || ! geometry.sections.length ) {
			errors.push( { code: 'no_sections', message: 'Add at least one section.' } );
		}

		( geometry.sections || [] ).forEach( function ( section ) {
			if ( sectionKeys[ section.key ] ) {
				errors.push( { code: 'duplicate_section_key', message: 'Two sections share the key ' + section.key + '.' } );
			}

			sectionKeys[ section.key ] = true;

			var rowKeys = {};

			( section.rows || [] ).forEach( function ( row ) {
				if ( rowKeys[ row.key ] ) {
					errors.push( { code: 'duplicate_row_key', message: 'Row key ' + row.key + ' is repeated in ' + section.name + '.' } );
				}

				rowKeys[ row.key ] = true;

				var seatKeys = {};
				var labels = {};

				( row.seats || [] ).forEach( function ( seat ) {
					if ( seatKeys[ seat.key ] ) {
						errors.push( { code: 'duplicate_seat_key', message: 'Seat key ' + seat.key + ' is repeated in row ' + row.name + '.' } );
					}

					seatKeys[ seat.key ] = true;

					if ( labels[ seat.label ] ) {
						warnings.push( {
							code: 'duplicate_seat_label',
							message: 'Row ' + row.name + ' has two seats labelled ' + seat.label + '.',
						} );
					}

					labels[ seat.label ] = true;

					if (
						seat.x < 0 ||
						seat.y < 0 ||
						seat.x > geometry.canvas.width ||
						seat.y > geometry.canvas.height
					) {
						errors.push( {
							code: 'seat_off_canvas',
							message: 'Seat ' + row.name + seat.label + ' is outside the canvas.',
						} );
					}

					positions.push( [ seat.x, seat.y, row.name + seat.label ] );
				} );
			} );
		} );

		if ( ! positions.length && ! errors.length ) {
			errors.push( { code: 'no_seats', message: 'A map needs at least one seat before it can be published.' } );
		}

		Geometry.findOverlaps( positions ).forEach( function ( pair ) {
			warnings.push( {
				code: 'seats_overlap',
				message: 'Seats ' + pair[ 0 ] + ' and ' + pair[ 1 ] + ' are almost on top of each other.',
			} );
		} );

		return {
			valid: errors.length === 0,
			seat_count: positions.length,
			errors: errors,
			warnings: warnings,
		};
	};

	/** Grid-bucketed, so a 20,000-seat map does not turn into 200 million comparisons. */
	Geometry.findOverlaps = function ( positions ) {
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

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = Geometry;
	} else {
		global.SeatmapGeometry = Geometry;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
