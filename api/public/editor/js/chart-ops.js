/**
 * Operations over a chart: bounds, hit testing, transforms and migration.
 *
 * Kept apart from the model so `chart.js` stays a description of what a venue *is*, while this file
 * is what the designer's tools *do* to it. Both are pure — no canvas, no DOM — so both are testable
 * without a browser.
 */
( function ( global ) {
	'use strict';

	var Chart = global.SeatmapChart || ( typeof require !== 'undefined' ? require( './chart.js' ) : null );

	var Ops = {};

	/* ---------------------------------------------------------------------------- bounds */

	/**
	 * The axis-aligned box an object occupies, in chart coordinates.
	 *
	 * Rows and tables are measured from their computed seat positions rather than their anchor,
	 * because a rotated or curved row's anchor tells you almost nothing about where it actually is.
	 */
	Ops.bounds = function ( object ) {
		switch ( object.type ) {
			case 'row':
				return pointsBounds( Chart.rowSeatPositions( object ), Chart.SEAT_SIZE / 2 );

			case 'table':
				return expand(
					pointsBounds( Chart.tableSeatPositions( object ), Chart.SEAT_SIZE / 2 ),
					boxBounds( object.x - object.width / 2, object.y - object.height / 2, object.width, object.height )
				);

			case 'section':
				return Chart.polygonBounds( object.polygon );

			case 'area':
			case 'booth':
				return object.shape.points
					? Chart.polygonBounds( object.shape.points )
					: boxBounds( object.shape.x, object.shape.y, object.shape.width, object.shape.height );

			case 'shape':
				return object.points
					? Chart.polygonBounds( object.points )
					: boxBounds( object.x, object.y, object.width, object.height );

			case 'image':
				return boxBounds( object.x, object.y, object.width, object.height );

			case 'text':
				// Approximate: enough for selection and fitting, and cheap without a text metric.
				return boxBounds( object.x, object.y - object.fontSize, object.text.length * object.fontSize * 0.55, object.fontSize * 1.3 );

			case 'icon':
				return boxBounds( object.x - object.size / 2, object.y - object.size / 2, object.size, object.size );

			default:
				return boxBounds( object.x || 0, object.y || 0, 0, 0 );
		}
	};

	function boxBounds( x, y, width, height ) {
		return { x: x, y: y, width: width, height: height };
	}

	function pointsBounds( points, padding ) {
		if ( ! points.length ) {
			return boxBounds( 0, 0, 0, 0 );
		}

		var xs = points.map( function ( p ) { return p.x; } );
		var ys = points.map( function ( p ) { return p.y; } );
		var minX = Math.min.apply( null, xs ) - padding;
		var minY = Math.min.apply( null, ys ) - padding;

		return boxBounds(
			minX, minY,
			Math.max.apply( null, xs ) + padding - minX,
			Math.max.apply( null, ys ) + padding - minY
		);
	}

	function expand( a, b ) {
		var minX = Math.min( a.x, b.x );
		var minY = Math.min( a.y, b.y );

		return boxBounds(
			minX, minY,
			Math.max( a.x + a.width, b.x + b.width ) - minX,
			Math.max( a.y + a.height, b.y + b.height ) - minY
		);
	}

	Ops.boundsOfMany = function ( objects ) {
		if ( ! objects.length ) {
			return null;
		}

		return objects.map( Ops.bounds ).reduce( expand );
	};

	Ops.center = function ( object ) {
		var box = Ops.bounds( object );

		return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
	};

	/* ------------------------------------------------------------------------ hit testing */

	Ops.hitTest = function ( object, point ) {
		if ( 'section' === object.type ) {
			return Ops.pointInPolygon( point, object.polygon );
		}

		if ( ( 'area' === object.type || 'booth' === object.type ) && object.shape.points ) {
			return Ops.pointInPolygon( point, object.shape.points );
		}

		if ( 'shape' === object.type && object.points ) {
			return Ops.pointInPolygon( point, object.points );
		}

		var box = Ops.bounds( object );

		return (
			point.x >= box.x && point.x <= box.x + box.width &&
			point.y >= box.y && point.y <= box.y + box.height
		);
	};

	/** Ray casting. Handles concave section polygons, which the octagonal arena charts are full of. */
	Ops.pointInPolygon = function ( point, polygon ) {
		var inside = false;

		for ( var i = 0, j = polygon.length - 1; i < polygon.length; j = i++ ) {
			var xi = polygon[ i ][ 0 ];
			var yi = polygon[ i ][ 1 ];
			var xj = polygon[ j ][ 0 ];
			var yj = polygon[ j ][ 1 ];

			var intersects =
				yi > point.y !== yj > point.y &&
				point.x < ( ( xj - xi ) * ( point.y - yi ) ) / ( yj - yi ) + xi;

			if ( intersects ) {
				inside = ! inside;
			}
		}

		return inside;
	};

	/** The seat nearest a point, within a tolerance — how clicking a seat actually resolves. */
	Ops.seatAt = function ( container, point, tolerance ) {
		tolerance = tolerance || Chart.SEAT_SIZE * 0.8;

		var best = null;
		var bestDistance = tolerance;

		( container.objects || [] ).forEach( function ( object ) {
			if ( 'row' !== object.type && 'table' !== object.type ) {
				return;
			}

			var positions = 'row' === object.type
				? Chart.rowSeatPositions( object )
				: Chart.tableSeatPositions( object );

			positions.forEach( function ( position, index ) {
				var distance = Math.hypot( position.x - point.x, position.y - point.y );

				if ( distance < bestDistance ) {
					bestDistance = distance;
					best = { object: object, seat: object.seats[ index ], index: index, position: position };
				}
			} );
		} );

		return best;
	};

	/* -------------------------------------------------------------------------- transforms */

	Ops.move = function ( object, dx, dy ) {
		switch ( object.type ) {
			case 'row':
			case 'table':
			case 'icon':
			case 'text':
				object.x = round( object.x + dx );
				object.y = round( object.y + dy );
				break;

			case 'section':
				object.polygon = object.polygon.map( function ( point ) {
					return [ round( point[ 0 ] + dx ), round( point[ 1 ] + dy ) ];
				} );

				// Everything inside a section moves with it, or the seats would be left behind.
				( object.objects || [] ).forEach( function ( child ) {
					Ops.move( child, dx, dy );
				} );
				break;

			case 'area':
			case 'booth':
				if ( object.shape.points ) {
					object.shape.points = object.shape.points.map( function ( point ) {
						return [ round( point[ 0 ] + dx ), round( point[ 1 ] + dy ) ];
					} );
				} else {
					object.shape.x = round( object.shape.x + dx );
					object.shape.y = round( object.shape.y + dy );
				}
				break;

			default:
				if ( object.points ) {
					object.points = object.points.map( function ( point ) {
						return [ round( point[ 0 ] + dx ), round( point[ 1 ] + dy ) ];
					} );
				} else {
					object.x = round( ( object.x || 0 ) + dx );
					object.y = round( ( object.y || 0 ) + dy );
				}
		}

		return object;
	};

	/**
	 * Mirror a selection about its own centre.
	 *
	 * A true reflection: every chair moves to its mirrored position carrying its own key and label,
	 * so seat A1 is still A1 — it has simply changed ends. Renumbering afterwards is a separate,
	 * deliberate act.
	 *
	 * Reflecting a row is done entirely through its rotation and curve rather than by touching the
	 * seat list. With `rotation' = 180 - rotation` the row's local axis already points the other
	 * way, and `curve' = -curve` cancels the sign flip that introduces — which together place every
	 * seat exactly where the reflection demands. Reversing the seat array on top of that would flip
	 * the row a second time and put it back as it was.
	 */
	Ops.mirror = function ( objects, axis ) {
		var box = Ops.boundsOfMany( objects );

		if ( ! box ) {
			return;
		}

		var cx = box.x + box.width / 2;
		var cy = box.y + box.height / 2;

		objects.forEach( function ( object ) {
			reflect( object, axis, cx, cy );
		} );
	};

	function reflect( object, axis, cx, cy ) {
		var flipX = 'horizontal' === axis;

		function reflectPoint( x, y ) {
			return [ flipX ? round( 2 * cx - x ) : round( x ), flipX ? round( y ) : round( 2 * cy - y ) ];
		}

		switch ( object.type ) {
			case 'row':
				var point = reflectPoint( object.x, object.y );
				object.x = point[ 0 ];
				object.y = point[ 1 ];
				object.rotation = round( flipX ? 180 - object.rotation : -object.rotation );
				object.curve = round( -object.curve );
				break;

			case 'table':
			case 'icon':
			case 'text':
				var p = reflectPoint( object.x, object.y );
				object.x = p[ 0 ];
				object.y = p[ 1 ];
				object.rotation = round( flipX ? 180 - ( object.rotation || 0 ) : -( object.rotation || 0 ) );
				break;

			case 'section':
				object.polygon = object.polygon.map( function ( pt ) {
					return reflectPoint( pt[ 0 ], pt[ 1 ] );
				} );

				( object.objects || [] ).forEach( function ( child ) {
					reflect( child, axis, cx, cy );
				} );
				break;

			case 'area':
			case 'booth':
				if ( object.shape.points ) {
					object.shape.points = object.shape.points.map( function ( pt ) {
						return reflectPoint( pt[ 0 ], pt[ 1 ] );
					} );
				} else {
					// Reflect the far edge, since x/y is the top-left rather than the centre.
					var corner = reflectPoint(
						flipX ? object.shape.x + object.shape.width : object.shape.x,
						flipX ? object.shape.y : object.shape.y + object.shape.height
					);
					object.shape.x = corner[ 0 ];
					object.shape.y = corner[ 1 ];
				}
				break;

			default:
				if ( object.points ) {
					object.points = object.points.map( function ( pt ) {
						return reflectPoint( pt[ 0 ], pt[ 1 ] );
					} );
				} else {
					var far = reflectPoint(
						flipX ? ( object.x || 0 ) + ( object.width || 0 ) : object.x || 0,
						flipX ? object.y || 0 : ( object.y || 0 ) + ( object.height || 0 )
					);
					object.x = far[ 0 ];
					object.y = far[ 1 ];
				}
		}
	}

	/** Rotate a selection about its collective centre. */
	Ops.rotate = function ( objects, degrees ) {
		var box = Ops.boundsOfMany( objects );

		if ( ! box ) {
			return;
		}

		var cx = box.x + box.width / 2;
		var cy = box.y + box.height / 2;
		var theta = ( degrees * Math.PI ) / 180;
		var cos = Math.cos( theta );
		var sin = Math.sin( theta );

		function spin( x, y ) {
			var dx = x - cx;
			var dy = y - cy;

			return [ round( cx + dx * cos - dy * sin ), round( cy + dx * sin + dy * cos ) ];
		}

		objects.forEach( function ( object ) {
			if ( 'section' === object.type ) {
				object.polygon = object.polygon.map( function ( pt ) { return spin( pt[ 0 ], pt[ 1 ] ); } );
				Ops.rotate( object.objects || [], degrees );

				return;
			}

			if ( 'row' === object.type || 'table' === object.type || 'icon' === object.type || 'text' === object.type ) {
				var point = spin( object.x, object.y );
				object.x = point[ 0 ];
				object.y = point[ 1 ];
				object.rotation = round( ( object.rotation || 0 ) + degrees );

				return;
			}

			var target = object.shape || object;

			if ( target.points ) {
				target.points = target.points.map( function ( pt ) { return spin( pt[ 0 ], pt[ 1 ] ); } );
			} else {
				var moved = spin( target.x, target.y );
				target.x = moved[ 0 ];
				target.y = moved[ 1 ];
				target.rotation = round( ( target.rotation || 0 ) + degrees );
			}
		} );
	};

	/**
	 * Align to the mean rather than to the first-selected object: tidying a row of sections should
	 * nudge them into line, not jerk them to wherever the first click landed.
	 */
	Ops.align = function ( objects, edge ) {
		if ( objects.length < 2 ) {
			return;
		}

		var centers = objects.map( Ops.center );
		var boxes = objects.map( Ops.bounds );

		var target;

		switch ( edge ) {
			case 'left':
				target = Math.min.apply( null, boxes.map( function ( b ) { return b.x; } ) );
				break;
			case 'right':
				target = Math.max.apply( null, boxes.map( function ( b ) { return b.x + b.width; } ) );
				break;
			case 'top':
				target = Math.min.apply( null, boxes.map( function ( b ) { return b.y; } ) );
				break;
			case 'bottom':
				target = Math.max.apply( null, boxes.map( function ( b ) { return b.y + b.height; } ) );
				break;
			case 'middle':
				target = mean( centers.map( function ( c ) { return c.y; } ) );
				break;
			default: // center
				target = mean( centers.map( function ( c ) { return c.x; } ) );
		}

		objects.forEach( function ( object, index ) {
			var box = boxes[ index ];

			switch ( edge ) {
				case 'left':
					Ops.move( object, target - box.x, 0 );
					break;
				case 'right':
					Ops.move( object, target - ( box.x + box.width ), 0 );
					break;
				case 'top':
					Ops.move( object, 0, target - box.y );
					break;
				case 'bottom':
					Ops.move( object, 0, target - ( box.y + box.height ) );
					break;
				case 'middle':
					Ops.move( object, 0, target - centers[ index ].y );
					break;
				default:
					Ops.move( object, target - centers[ index ].x, 0 );
			}
		} );
	};

	function mean( values ) {
		return values.reduce( function ( total, value ) { return total + value; }, 0 ) / values.length;
	}

	/** Space a selection evenly between its own outermost members. */
	Ops.distribute = function ( objects, axis ) {
		if ( objects.length < 3 ) {
			return;
		}

		var key = 'x' === axis ? 'x' : 'y';

		var sorted = objects.slice().sort( function ( a, b ) {
			return Ops.center( a )[ key ] - Ops.center( b )[ key ];
		} );

		var first = Ops.center( sorted[ 0 ] )[ key ];
		var last = Ops.center( sorted[ sorted.length - 1 ] )[ key ];
		var step = ( last - first ) / ( sorted.length - 1 );

		sorted.forEach( function ( object, index ) {
			var delta = first + index * step - Ops.center( object )[ key ];

			Ops.move( object, 'x' === axis ? delta : 0, 'x' === axis ? 0 : delta );
		} );
	};

	/**
	 * Copy objects, giving every copy — and every seat inside it — a fresh key.
	 *
	 * A pasted row is a new set of chairs, not a second reference to the originals. Sharing keys
	 * would make one sale appear to fill two rows.
	 */
	Ops.duplicate = function ( chart, objects, dx, dy ) {
		var taken = Chart.allKeys( chart );
		var copies = [];

		objects.forEach( function ( object ) {
			var copy = JSON.parse( JSON.stringify( object ) );

			rekey( copy, taken );
			Ops.move( copy, dx, dy );
			copies.push( copy );
		} );

		return copies;
	};

	function rekey( object, taken ) {
		object.key = Chart.uniqueKey( taken, stripSuffix( object.key ) + '-copy' );
		taken.push( object.key );

		( object.seats || [] ).forEach( function ( seat ) {
			seat.key = Chart.uniqueKey( taken, stripSuffix( seat.key ) + '-copy' );
			taken.push( seat.key );
		} );

		( object.objects || [] ).forEach( function ( child ) {
			rekey( child, taken );
		} );
	}

	function stripSuffix( key ) {
		return String( key ).replace( /-copy(-\d+)?$/, '' );
	}

	Ops.remove = function ( container, keys ) {
		container.objects = ( container.objects || [] ).filter( function ( object ) {
			if ( 'section' === object.type ) {
				Ops.remove( object, keys );
			}

			return keys.indexOf( object.key ) === -1;
		} );

		return container;
	};

	/** Move objects between layers, which is what the selection-layer list acts on. */
	Ops.setLayer = function ( objects, layer ) {
		objects.forEach( function ( object ) {
			object.layer = layer;
		} );
	};

	Ops.objectsInLayer = function ( container, layer ) {
		return ( container.objects || [] ).filter( function ( object ) {
			return 'all' === layer || ( object.layer || 'interactive' ) === layer;
		} );
	};

	/* --------------------------------------------------------------------------- migration */

	/**
	 * Bring a v1 geometry up to the current model.
	 *
	 * v1 charts were flat sections of rows with per-seat coordinates and no categories, floors or
	 * capacity objects. Existing published versions are immutable and orders point at them, so they
	 * have to keep opening — converting on read is the only honest way to change the schema.
	 */
	Ops.migrate = function ( geometry ) {
		if ( ! geometry || geometry.version >= 2 ) {
			return geometry;
		}

		var chart = Chart.empty( geometry.name || 'Imported chart' );
		var floor = chart.floors[ 0 ];

		floor.canvas = {
			width: ( geometry.canvas && geometry.canvas.width ) || 1200,
			height: ( geometry.canvas && geometry.canvas.height ) || 900,
			background: ( geometry.canvas && geometry.canvas.background ) || null,
		};

		var zones = {};

		( geometry.sections || [] ).forEach( function ( section ) {
			( section.rows || [] ).forEach( function ( row ) {
				( row.seats || [] ).forEach( function ( seat ) {
					if ( seat.zone_key ) {
						zones[ seat.zone_key ] = true;
					}
				} );
			} );
		} );

		// v1 price zones become categories — the same idea under the name the designer uses.
		Object.keys( zones ).forEach( function ( key ) {
			chart.categories.push( { key: key, label: key, color: '#2d6cdf', accessible: false } );
		} );

		( geometry.sections || [] ).forEach( function ( section ) {
			( section.rows || [] ).forEach( function ( row ) {
				var seats = row.seats || [];

				if ( ! seats.length ) {
					return;
				}

				// v1 stored a coordinate per seat. Recover the anchor, rotation and spacing the new
				// model needs from the first and last seat of the row.
				var first = seats[ 0 ];
				var last = seats[ seats.length - 1 ];
				var dx = last.x - first.x;
				var dy = last.y - first.y;
				var span = Math.hypot( dx, dy );
				var pitch = seats.length > 1 ? span / ( seats.length - 1 ) : Chart.SEAT_SIZE + 4;

				var migrated = {
					type: 'row',
					key: section.key + '-' + row.key,
					layer: 'interactive',
					x: round( ( first.x + last.x ) / 2 ),
					y: round( ( first.y + last.y ) / 2 ),
					rotation: seats.length > 1 ? round( ( Math.atan2( dy, dx ) * 180 ) / Math.PI ) : 0,
					curve: 0,
					seatSpacing: round( Math.max( 0, pitch - Chart.SEAT_SIZE ) ),
					categoryKey: seats[ 0 ].zone_key || null,
					entrance: null,
					labeling: Chart.defaultRowLabeling( row.name || row.key ),
					seatLabeling: { scheme: 'numeric', displayedType: 'Seat', locked: false },
					seats: seats.map( function ( seat ) {
						return {
							type: 'seat',
							// The original composite key is preserved so a published v1 map still
							// resolves to the same seats after conversion.
							key: section.key + '/' + row.key + '/' + seat.key,
							label: String( seat.label ),
							categoryKey: seat.zone_key || null,
							accessible: !! seat.accessible,
							entrance: null,
						};
					} ),
				};

				floor.objects.push( migrated );
			} );
		} );

		( geometry.shapes || [] ).forEach( function ( shape ) {
			floor.objects.push( {
				type: 'shape',
				key: Chart.uniqueKey( Chart.allKeys( chart ), 'shape-' + ( shape.kind || 'rect' ) ),
				layer: 'background',
				kind: shape.kind || 'rect',
				x: shape.x, y: shape.y,
				width: shape.width || 0, height: shape.height || 0,
				rotation: shape.rotation || 0,
				cornerRadius: 4,
				points: shape.points || null,
				fill: shape.fill || null,
				label: shape.label || null,
			} );
		} );

		( geometry.texts || [] ).forEach( function ( text ) {
			floor.objects.push( {
				type: 'text',
				key: Chart.uniqueKey( Chart.allKeys( chart ), 'text' ),
				layer: 'foreground',
				text: text.text,
				x: text.x, y: text.y,
				fontSize: text.size || 14,
				color: text.color || null,
				rotation: text.rotation || 0,
			} );
		} );

		return chart;
	};

	function round( value ) {
		return Math.round( value * 100 ) / 100;
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = Ops;
	} else {
		global.SeatmapChartOps = Ops;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
