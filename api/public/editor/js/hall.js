/**
 * The designer's third dimension.
 *
 * The plan says where the chairs are. It cannot say what anybody in them will see, and that is the
 * question a venue is asked all day: is the balcony too high, does row F see over row E, is the
 * side of the stage visible from the end of a row. Four numbers answer it — the height of the
 * stage, the rake of each block, the height each block starts at, and how deep its platform is —
 * and this is where an organiser types them and immediately sees the room they describe.
 *
 * The projection itself is `shared/hall-3d`, the same file the buyer's picker uses, so the room set
 * up here is the room shown there rather than a second implementation that agrees for a while.
 */
( function ( global ) {
	'use strict';

	/** Resolved when it is used, not when this file loads: script order is not this file's business. */
	function chartApi() {
		return global.SeatmapChart;
	}

	function Hall( editor ) {
		this.editor = editor;
		this.canvas = editor.canvas;
		this.scene = null;
		this.camera = null;
		this.dragging = false;
		this.last = null;
	}

	/** The chart's 3D settings, created on first use so an untouched chart carries nothing. */
	Hall.settingsOf = function ( chart ) {
		return global.SeatmapHall3D.settings( chart );
	};

	/**
	 * Every block of the chart as the 3D engine wants it.
	 *
	 * A section is a block, and its outline is the polygon the designer drew — the same polygon the
	 * buyer's picker uses, so the floor of a room is the shape somebody actually drew rather than a
	 * rectangle around it. Rows outside any section are one block of their own, because a chart is
	 * allowed not to have sections and a room still has a floor.
	 */
	Hall.prototype.model = function () {
		var chart = this.editor.chart;
		var floorKey = this.editor.floorKey;
		var seats = [];
		var blocks = [];
		var loose = [];

		chartApi().eachObject( chart, function ( object, container, floor ) {
			if ( floor.key !== floorKey ) {
				return;
			}

			if ( 'section' === object.type ) {
				blocks.push( {
					key: object.key,
					name: ( object.labeling && ( object.labeling.displayedLabel || object.labeling.label ) ) ||
						object.label || object.key,
					outline: ( object.polygon || [] ).map( function ( point ) {
						return [ point[ 0 ], point[ 1 ] ];
					} ),
					colour: object.color || null,
				} );
			}

			if ( 'row' !== object.type && 'table' !== object.type ) {
				return;
			}

			var positions = 'row' === object.type
				? chartApi().rowSeatPositions( object )
				: chartApi().tableSeatPositions( object );

			( object.seats || [] ).forEach( function ( seat, index ) {
				if ( 'empty' === seat.type || ! positions[ index ] ) {
					return;
				}

				var key = container && 'section' === container.type ? container.key : '';

				seats.push( {
					x: positions[ index ].x,
					y: positions[ index ].y,
					blockKey: key,
					record: {
						seat: seat,
						owner: object,
						categoryKey: seat.categoryKey || object.categoryKey || null,
					},
				} );

				if ( ! key ) {
					loose.push( [ positions[ index ].x, positions[ index ].y ] );
				}
			} );
		} );

		if ( loose.length > 2 ) {
			blocks.push( {
				key: '',
				name: '',
				outline: hullOf( loose, chartApi().SEAT_SIZE * 1.6 ),
				colour: null,
			} );
		}

		return { seats: seats, blocks: blocks };
	};

	/** The stage, where the chart has one drawn. */
	Hall.prototype.stageShape = function () {
		var chart = this.editor.chart;
		var floorKey = this.editor.floorKey;
		var found = null;

		chartApi().eachObject( chart, function ( object, container, floor ) {
			if ( found || floor.key !== floorKey || 'shape' !== object.type ) {
				return;
			}

			var label = String( object.label || '' ).toLowerCase();

			if ( 'stage' !== object.kind && ! /stage|scene|bühne|escenario|palco|صحنه|المسرح/.test( label ) ) {
				return;
			}

			found = footprint( object );
		} );

		return found;
	};

	/** Rebuild the room from the chart as it now is. Cheap enough to do on every edit. */
	Hall.prototype.build = function () {
		var model = this.model();
		var keep = this.camera;

		this.scene = global.SeatmapHall3D.build( {
			settings: Hall.settingsOf( this.editor.chart ),
			focal: this.editor.chart.focalPoint || null,
			bounds: boundsOf( model.seats ),
			stageShape: this.stageShape(),
			seats: model.seats,
			blocks: model.blocks,
		} );

		// The camera survives an edit: raising the balcony by ten and being thrown back to the
		// opening view is how somebody loses the thing they were looking at.
		this.camera = keep || global.SeatmapHall3D.camera( this.scene, {} );

		return this;
	};

	Hall.prototype.reset = function () {
		this.camera = this.scene ? global.SeatmapHall3D.camera( this.scene, {} ) : null;
	};

	Hall.prototype.draw = function () {
		if ( ! this.scene ) {
			this.build();
		}

		var editor = this.editor;
		var ctx = editor.ctx;
		var colors = editor.colors();
		var width = this.canvas.clientWidth || 800;
		var height = this.canvas.clientHeight || 600;

		ctx.setTransform( editor.dpr, 0, 0, editor.dpr, 0, 0 );

		global.SeatmapHall3D.paint( ctx, this.scene, this.camera, {
			width: width,
			height: height,
			palette: {
				// The designer's own paper, so the room sits on the same surface the plan does
				// and the dark theme is dark here too.
				paper: window.getComputedStyle( this.canvas ).backgroundColor || '#ffffff',
				text: colors.ink,
				floor: colors.inkFill,
				floorEdge: colors.halo,
				skirt: colors.inkFill,
				stage: colors.shapes.stage,
				stageSide: colors.dimmed,
				stageEdge: colors.shapeEdge,
			},
			seatColour: function ( entry ) {
				// The category's own colour, exactly as the plan paints it: a room where the
				// premium block is a different colour from the plan is a room nobody trusts.
				return editor.categoryColor( entry.record.categoryKey, colors.neutralSeat );
			},
		} );
	};

	/* ----------------------------------------------------------------------------- the mouse */

	Hall.prototype.bind = function () {
		var self = this;

		this.onDown = function ( event ) {
			self.dragging = true;
			self.last = { x: event.clientX, y: event.clientY };
			self.canvas.setPointerCapture( event.pointerId );
		};

		this.onMove = function ( event ) {
			if ( ! self.dragging ) {
				return;
			}

			var dx = event.clientX - self.last.x;
			var dy = event.clientY - self.last.y;

			self.last = { x: event.clientX, y: event.clientY };

			// Drag to walk around the room; hold shift to slide across it. The same two gestures
			// the buyer's view uses, because they are the same room.
			if ( event.shiftKey ) {
				var reach = self.camera.distance / 600;

				self.camera.pan( -dx * reach, dy * reach );
			} else {
				self.camera.orbit( dx * 0.008, -dy * 0.006 );
			}

			self.draw();
		};

		this.onUp = function () { self.dragging = false; };

		this.onWheel = function ( event ) {
			event.preventDefault();
			self.camera.zoom( event.deltaY < 0 ? 0.9 : 1.1 );
			self.draw();
		};

		this.canvas.addEventListener( 'pointerdown', this.onDown );
		this.canvas.addEventListener( 'pointermove', this.onMove );
		this.canvas.addEventListener( 'pointerup', this.onUp );
		this.canvas.addEventListener( 'wheel', this.onWheel, { passive: false } );

		return this;
	};

	Hall.prototype.unbind = function () {
		this.canvas.removeEventListener( 'pointerdown', this.onDown );
		this.canvas.removeEventListener( 'pointermove', this.onMove );
		this.canvas.removeEventListener( 'pointerup', this.onUp );
		this.canvas.removeEventListener( 'wheel', this.onWheel );
	};

	/* -------------------------------------------------------------------------------- helpers */

	function footprint( object ) {
		var width = object.width || 0;
		var height = object.height || 0;
		var points = object.points && object.points.length && Array.isArray( object.points[ 0 ] )
			? object.points.map( function ( point ) { return [ point[ 0 ], point[ 1 ] ]; } )
			: null;

		if ( ! points || points.length < 3 ) {
			if ( width <= 0 || height <= 0 ) {
				return null;
			}

			points = [
				[ object.x, object.y ],
				[ object.x + width, object.y ],
				[ object.x + width, object.y + height ],
				[ object.x, object.y + height ],
			];
		}

		var angle = ( ( object.rotation || 0 ) * Math.PI ) / 180;

		if ( ! angle ) {
			return points;
		}

		var cx = object.x + width / 2;
		var cy = object.y + height / 2;
		var cos = Math.cos( angle );
		var sin = Math.sin( angle );

		return points.map( function ( point ) {
			var dx = point[ 0 ] - cx;
			var dy = point[ 1 ] - cy;

			return [ cx + dx * cos - dy * sin, cy + dx * sin + dy * cos ];
		} );
	}

	function boundsOf( seats ) {
		if ( ! seats.length ) {
			return { x: 0, y: 0, width: 800, height: 600 };
		}

		var minX = Infinity;
		var minY = Infinity;
		var maxX = -Infinity;
		var maxY = -Infinity;

		seats.forEach( function ( seat ) {
			minX = Math.min( minX, seat.x );
			minY = Math.min( minY, seat.y );
			maxX = Math.max( maxX, seat.x );
			maxY = Math.max( maxY, seat.y );
		} );

		return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
	}

	/** A convex hull with a margin, for rows that belong to no section. */
	function hullOf( points, pad ) {
		if ( points.length < 3 ) {
			return points;
		}

		var sorted = points.slice().sort( function ( a, b ) {
			return a[ 0 ] === b[ 0 ] ? a[ 1 ] - b[ 1 ] : a[ 0 ] - b[ 0 ];
		} );

		var cross = function ( o, a, b ) {
			return ( a[ 0 ] - o[ 0 ] ) * ( b[ 1 ] - o[ 1 ] ) - ( a[ 1 ] - o[ 1 ] ) * ( b[ 0 ] - o[ 0 ] );
		};

		var lower = [];
		var upper = [];
		var i;

		for ( i = 0; i < sorted.length; i++ ) {
			while ( lower.length > 1 && cross( lower[ lower.length - 2 ], lower[ lower.length - 1 ], sorted[ i ] ) <= 0 ) {
				lower.pop();
			}

			lower.push( sorted[ i ] );
		}

		for ( i = sorted.length - 1; i >= 0; i-- ) {
			while ( upper.length > 1 && cross( upper[ upper.length - 2 ], upper[ upper.length - 1 ], sorted[ i ] ) <= 0 ) {
				upper.pop();
			}

			upper.push( sorted[ i ] );
		}

		var hull = lower.slice( 0, -1 ).concat( upper.slice( 0, -1 ) );
		var cx = 0;
		var cy = 0;

		hull.forEach( function ( point ) { cx += point[ 0 ]; cy += point[ 1 ]; } );
		cx /= hull.length;
		cy /= hull.length;

		return hull.map( function ( point ) {
			var dx = point[ 0 ] - cx;
			var dy = point[ 1 ] - cy;
			var length = Math.sqrt( dx * dx + dy * dy ) || 1;

			return [ point[ 0 ] + ( dx / length ) * pad, point[ 1 ] + ( dy / length ) * pad ];
		} );
	}

	global.SeatmapHall = Hall;
}( typeof window !== 'undefined' ? window : globalThis ) );
