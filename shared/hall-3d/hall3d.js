/**
 * The hall in three dimensions.
 *
 * A seating plan is a drawing of the floor, and a floor is not what somebody is buying. What they
 * want to know is what they will see: how far above the stage the balcony is, whether the row in
 * front will be in the way, whether the seat at the end of the row is looking at the stage or at
 * the side of it. None of that is in a plan, and all of it is in the same plan plus four numbers —
 * the height of the stage, the rake of each block, the height each block starts at, and how deep
 * its platform is.
 *
 * So this is not a second chart. It is the chart, lifted:
 *
 *   z = base + rake% × (how far this chair is from the stage, minus how far the nearest chair in
 *                       its own block is)
 *
 * Distance is measured from the chart's focal point rather than along an axis, because that is the
 * one direction a hall is actually built around and it works the same for a fan, a horseshoe and
 * twenty straight rows. Everything else — the platform under a block, the skirt under a balcony,
 * the box of the stage — falls out of the same number.
 *
 * Dependency-free and shared by the designer and the buyer's picker, so the room an organiser sets
 * up is the room a buyer is shown, down to the last centimetre of rake.
 */
( function ( global ) {
	'use strict';

	var Hall3D = {};

	/**
	 * What a hall looks like before anybody has said anything about it.
	 *
	 * Heights and depths are in the chart's own units — the same ones a seat is 18 of across —
	 * because a chart carries no scale and inventing one would put a number on a screen that is
	 * wrong in every venue that did not happen to use it. Rake is a percentage, which is how a
	 * theatre's own drawings express it and is the same number at any scale.
	 */
	Hall3D.DEFAULTS = {
		enabled: false,
		rake: 6,
		stage: { height: 24, depth: 120, width: 0, riser: true },
		sections: {},
	};

	var SEAT_W = 15;
	var SEAT_D = 13;
	var SEAT_BACK = 11;

	/** The 3D settings of a chart, with every gap filled in. Never mutates what it is given. */
	Hall3D.settings = function ( chart ) {
		var raw = ( chart && chart.view3d ) || {};
		var stage = raw.stage || {};

		return {
			enabled: !! raw.enabled,
			rake: number( raw.rake, Hall3D.DEFAULTS.rake ),
			stage: {
				height: number( stage.height, Hall3D.DEFAULTS.stage.height ),
				depth: number( stage.depth, Hall3D.DEFAULTS.stage.depth ),
				width: number( stage.width, Hall3D.DEFAULTS.stage.width ),
				riser: undefined === stage.riser ? true : !! stage.riser,
			},
			sections: raw.sections ? clone( raw.sections ) : {},
		};
	};

	/** One block's settings, falling back to the hall's. */
	Hall3D.forSection = function ( settings, key ) {
		var own = ( settings.sections || {} )[ key ] || {};

		return {
			base: number( own.base, 0 ),
			rake: number( own.rake, settings.rake ),
			depth: number( own.depth, 0 ),
			skirt: undefined === own.skirt ? number( own.base, 0 ) > 0 : !! own.skirt,
		};
	};

	/* ------------------------------------------------------------------------------- the room */

	/**
	 * Lift a plan into a room.
	 *
	 * `input` is what both callers already have: the chairs with their plan coordinates, the blocks
	 * with the outline the designer drew, where the stage is, and the 3D settings. What comes back
	 * is a list of things to draw with a height on every corner.
	 */
	Hall3D.build = function ( input ) {
		var settings = input.settings || Hall3D.settings( null );
		var focal = input.focal || centreOf( input.bounds );
		var seats = input.seats || [];
		var blocks = input.blocks || [];

		// How far the nearest chair of each block is from the stage. A block's own rake starts at
		// its own front row, not at the stage: a balcony forty metres back does not begin forty
		// metres in the air.
		var nearest = {};

		seats.forEach( function ( seat ) {
			var key = seat.blockKey || seat.sectionKey || '';
			var d = distance( seat.x, seat.y, focal.x, focal.y );

			if ( undefined === nearest[ key ] || d < nearest[ key ] ) {
				nearest[ key ] = d;
			}
		} );

		var platforms = blocks.map( function ( block ) {
			var key = block.key || block.sectionKey || '';
			var rules = Hall3D.forSection( settings, key );
			var start = undefined === nearest[ key ] ? 0 : nearest[ key ];
			var outline = ( block.outline || [] ).map( function ( point ) {
				return [ point[ 0 ], point[ 1 ], Hall3D.heightAt(
					point[ 0 ], point[ 1 ], focal, start, rules
				) ];
			} );

			return {
				kind: 'platform',
				key: key,
				name: block.name || '',
				colour: block.colour || null,
				base: rules.base,
				skirt: rules.skirt,
				points: outline,
			};
		} ).filter( function ( platform ) { return platform.points.length > 2; } );

		var chairs = seats.map( function ( seat ) {
			var key = seat.blockKey || seat.sectionKey || '';
			var rules = Hall3D.forSection( settings, key );
			var start = undefined === nearest[ key ] ? 0 : nearest[ key ];

			return {
				kind: 'seat',
				seat: seat,
				x: seat.x,
				y: seat.y,
				z: Hall3D.heightAt( seat.x, seat.y, focal, start, rules ),
				// Chairs face the stage, so a row on the left of a fan is turned towards it and a
				// buyer can see which way they will be sitting.
				angle: Math.atan2( focal.y - seat.y, focal.x - seat.x ),
			};
		} );

		return {
			settings: settings,
			focal: focal,
			bounds: input.bounds || boundsOf( seats ),
			platforms: platforms,
			seats: chairs,
			stage: Hall3D.stage( input, settings, focal ),
		};
	};

	/** The height of one point of the floor. */
	Hall3D.heightAt = function ( x, y, focal, start, rules ) {
		var run = Math.max( 0, distance( x, y, focal.x, focal.y ) - start );

		return rules.base + ( rules.rake / 100 ) * run;
	};

	/**
	 * The stage.
	 *
	 * Taken from the chart where the chart says so — a shape somebody drew and labelled — because a
	 * guessed rectangle in a hall that has a thrust or an orchestra pit is a lie about the room.
	 * Where the chart says nothing, it is a plain platform in front of the nearest chairs, which is
	 * true of almost every hall and is at least the right size and in the right place.
	 */
	Hall3D.stage = function ( input, settings, focal ) {
		var height = settings.stage.height;

		if ( height <= 0 ) {
			return null;
		}

		if ( input.stageShape && input.stageShape.length > 2 ) {
			return { kind: 'stage', height: height, points: input.stageShape.slice() };
		}

		var bounds = input.bounds || boundsOf( input.seats || [] );
		var width = settings.stage.width > 0
			? settings.stage.width
			: Math.max( 120, bounds.width * 0.45 );
		var depth = settings.stage.depth;

		// Turned to face the room: the stage of a fan-shaped hall is square to the seats, not to
		// the page.
		var towards = Math.atan2( centreOf( bounds ).y - focal.y, centreOf( bounds ).x - focal.x );

		return {
			kind: 'stage',
			height: height,
			points: rectangleAt( focal, towards, width, depth ),
		};
	};

	/* --------------------------------------------------------------------------- the camera */

	/**
	 * Where somebody is standing to look at it.
	 *
	 * An orbit rather than a free camera: the thing being looked at is a room, the question is
	 * always "from where in it", and a camera that can be flown into a wall is a camera every user
	 * has to be rescued from.
	 */
	function Camera( scene, options ) {
		var bounds = scene.bounds;
		var opts = options || {};

		this.target = {
			x: bounds.x + bounds.width / 2,
			y: bounds.y + bounds.height / 2,
			z: 0,
		};

		this.span = Math.max( bounds.width, bounds.height, 200 );
		// Close enough that the room fills the frame: a hall drawn small in the middle of an empty
		// canvas is a diagram of a hall.
		this.distance = this.span * 1.1;

		/*
		 * Standing at the back of the hall, looking at the stage.
		 *
		 * Which way that is cannot be assumed: a chart may be drawn with the stage at the top, at
		 * the bottom or off to one side, and a room that opened looking at the back of the last row
		 * would be answering a question nobody asked. So the opening view is taken from the chart
		 * itself — behind the seats, along the line from them to the stage.
		 */
		this.yaw = undefined === opts.yaw ? behind( scene ) : opts.yaw;
		// High enough to see the rake, low enough that it is a room and not a plan.
		this.pitch = undefined === opts.pitch ? 0.55 : opts.pitch;
		this.focal = 1.15;
	}

	Camera.prototype.clamp = function () {
		this.pitch = Math.max( 0.08, Math.min( 1.45, this.pitch ) );
		this.distance = Math.max( this.span * 0.35, Math.min( this.span * 4, this.distance ) );
	};

	Camera.prototype.orbit = function ( dx, dy ) {
		this.yaw += dx;
		this.pitch += dy;
		this.clamp();
	};

	Camera.prototype.zoom = function ( factor ) {
		this.distance *= factor;
		this.clamp();
	};

	/** Slide the room under the camera, in the plane the camera is looking along. */
	Camera.prototype.pan = function ( dx, dy ) {
		var cos = Math.cos( this.yaw );
		var sin = Math.sin( this.yaw );

		this.target.x += dx * cos + dy * sin;
		this.target.y += -dx * sin + dy * cos;
	};

	/**
	 * A point of the room, on the screen.
	 *
	 * Returns null for anything behind the camera rather than the wrapped-around nonsense a
	 * division by a negative depth produces.
	 */
	Camera.prototype.project = function ( x, y, z, width, height ) {
		var dx = x - this.target.x;
		var dy = y - this.target.y;
		var dz = ( z || 0 ) - this.target.z;

		var cos = Math.cos( this.yaw );
		var sin = Math.sin( this.yaw );

		var rx = dx * cos - dy * sin;
		var ry = dx * sin + dy * cos;

		var cp = Math.cos( this.pitch );
		var sp = Math.sin( this.pitch );

		// The camera looks along +ry with its own up axis tilted by the pitch.
		var depth = this.distance + ry * cp - dz * sp;

		if ( depth < 1 ) {
			return null;
		}

		var up = ry * sp + dz * cp;
		var k = ( height * this.focal ) / depth;

		return {
			x: width / 2 + rx * k,
			y: height / 2 - up * k,
			depth: depth,
			scale: k,
		};
	};

	Hall3D.camera = function ( scene, options ) {
		return new Camera( scene, options );
	};

	/**
	 * The angle that puts the camera behind the audience.
	 *
	 * The camera sits along -(sin yaw, cos yaw) from what it is aimed at, so pointing it from the
	 * seats towards the stage means solving that for the direction the stage lies in.
	 */
	function behind( scene ) {
		var bounds = scene.bounds;
		var middle = { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 };
		var focal = scene.focal || middle;
		var dx = middle.x - focal.x;
		var dy = middle.y - focal.y;
		var length = Math.sqrt( dx * dx + dy * dy );

		if ( length < 1 ) {
			return 0;
		}

		return Math.atan2( -dx / length, -dy / length );
	}

	/* ---------------------------------------------------------------------------- the drawing */

	/**
	 * Draw the room.
	 *
	 * Painter's algorithm: everything is projected, sorted by how far it is from the camera and
	 * drawn far-to-near. A depth buffer would be better and is not available on a 2D canvas; with a
	 * room made of flat plates and small chairs, sorting by centroid is right almost everywhere and
	 * wrong only where two things overlap in a way an audience never sees.
	 *
	 * `options.seatColour( seat )` decides a chair's colour, so the designer can paint by category
	 * and the picker by whether it is free — the same room, answering each caller's own question.
	 */
	Hall3D.paint = function ( ctx, scene, camera, options ) {
		var opts = options || {};
		var width = opts.width || ctx.canvas.width;
		var height = opts.height || ctx.canvas.height;
		var palette = opts.palette || {};
		var pieces = [];
		var i;

		ctx.save();
		ctx.clearRect( 0, 0, width, height );

		if ( palette.paper ) {
			ctx.fillStyle = palette.paper;
			ctx.fillRect( 0, 0, width, height );
		}

		for ( i = 0; i < scene.platforms.length; i++ ) {
			collectPlatform( pieces, scene.platforms[ i ], camera, width, height, palette );
		}

		if ( scene.stage ) {
			collectStage( pieces, scene.stage, camera, width, height, palette );
		}

		for ( i = 0; i < scene.seats.length; i++ ) {
			collectSeat( pieces, scene.seats[ i ], camera, width, height, palette, opts );
		}

		pieces.sort( function ( a, b ) { return b.depth - a.depth; } );

		for ( i = 0; i < pieces.length; i++ ) {
			drawPiece( ctx, pieces[ i ] );
		}

		if ( opts.labels !== false ) {
			drawLabels( ctx, scene, camera, width, height, palette );
		}

		ctx.restore();

		return pieces.length;
	};

	function collectPlatform( pieces, platform, camera, width, height, palette ) {
		var top = project( platform.points, camera, width, height );

		if ( ! top ) {
			return;
		}

		// The wall under a raised block, so a balcony reads as a balcony rather than as a plate
		// floating in the air.
		if ( platform.skirt ) {
			for ( var i = 0; i < platform.points.length; i++ ) {
				var a = platform.points[ i ];
				var b = platform.points[ ( i + 1 ) % platform.points.length ];
				var quad = project( [
					[ a[ 0 ], a[ 1 ], a[ 2 ] ],
					[ b[ 0 ], b[ 1 ], b[ 2 ] ],
					[ b[ 0 ], b[ 1 ], 0 ],
					[ a[ 0 ], a[ 1 ], 0 ],
				], camera, width, height );

				if ( quad ) {
					pieces.push( {
						points: quad.points,
						depth: quad.depth + 0.5,
						fill: palette.skirt || 'rgba(20, 24, 40, 0.30)',
						stroke: null,
					} );
				}
			}
		}

		pieces.push( {
			points: top.points,
			depth: top.depth,
			fill: platform.colour || palette.floor || 'rgba(120, 130, 160, 0.22)',
			alpha: platform.colour ? 0.30 : 1,
			stroke: palette.floorEdge || 'rgba(90, 100, 130, 0.55)',
			lineWidth: 1,
		} );
	}

	function collectStage( pieces, stage, camera, width, height, palette ) {
		var points = stage.points;
		var i;

		for ( i = 0; i < points.length; i++ ) {
			var a = points[ i ];
			var b = points[ ( i + 1 ) % points.length ];
			var side = project( [
				[ a[ 0 ], a[ 1 ], stage.height ],
				[ b[ 0 ], b[ 1 ], stage.height ],
				[ b[ 0 ], b[ 1 ], 0 ],
				[ a[ 0 ], a[ 1 ], 0 ],
			], camera, width, height );

			if ( side ) {
				pieces.push( {
					points: side.points,
					depth: side.depth,
					fill: palette.stageSide || 'rgba(40, 44, 66, 0.85)',
					stroke: null,
				} );
			}
		}

		var top = project( points.map( function ( point ) {
			return [ point[ 0 ], point[ 1 ], stage.height ];
		} ), camera, width, height );

		if ( top ) {
			pieces.push( {
				points: top.points,
				depth: top.depth,
				fill: palette.stage || '#2f3450',
				stroke: palette.stageEdge || 'rgba(255, 255, 255, 0.25)',
				lineWidth: 1,
			} );
		}
	}

	function collectSeat( pieces, chair, camera, width, height, palette, opts ) {
		var middle = camera.project( chair.x, chair.y, chair.z + 4, width, height );

		if ( ! middle ) {
			return;
		}

		// Off screen, or too small to be anything but noise on a hall of four thousand.
		if ( middle.x < -60 || middle.y < -60 || middle.x > width + 60 || middle.y > height + 60 ) {
			return;
		}

		var colour = opts.seatColour ? opts.seatColour( chair.seat ) : ( palette.seat || '#5b6bd6' );
		var cos = Math.cos( chair.angle );
		var sin = Math.sin( chair.angle );
		var half = SEAT_W / 2;

		// The pad, turned to face the stage.
		var pad = project( [
			corner( chair, cos, sin, -half, -SEAT_D / 2, 4 ),
			corner( chair, cos, sin, half, -SEAT_D / 2, 4 ),
			corner( chair, cos, sin, half, SEAT_D / 2, 4 ),
			corner( chair, cos, sin, -half, SEAT_D / 2, 4 ),
		], camera, width, height );

		if ( ! pad ) {
			return;
		}

		pieces.push( {
			points: pad.points,
			depth: pad.depth,
			fill: colour,
			stroke: null,
		} );

		// A back, once a chair is big enough on screen for one to mean anything.
		if ( middle.scale * SEAT_W > 7 ) {
			var back = project( [
				corner( chair, cos, sin, -half, SEAT_D / 2, 4 ),
				corner( chair, cos, sin, half, SEAT_D / 2, 4 ),
				corner( chair, cos, sin, half, SEAT_D / 2, 4 + SEAT_BACK ),
				corner( chair, cos, sin, -half, SEAT_D / 2, 4 + SEAT_BACK ),
			], camera, width, height );

			if ( back ) {
				pieces.push( {
					points: back.points,
					depth: back.depth - 0.25,
					fill: shade( colour, 0.82 ),
					stroke: null,
				} );
			}
		}

		chair.screen = middle;
	}

	/** A chair's corner: `across` is along the row, `into` is towards the back of the chair. */
	function corner( chair, cos, sin, across, into, lift ) {
		return [
			chair.x + across * -sin + into * -cos,
			chair.y + across * cos + into * -sin,
			chair.z + lift,
		];
	}

	function drawPiece( ctx, piece ) {
		var points = piece.points;

		ctx.beginPath();
		ctx.moveTo( points[ 0 ].x, points[ 0 ].y );

		for ( var i = 1; i < points.length; i++ ) {
			ctx.lineTo( points[ i ].x, points[ i ].y );
		}

		ctx.closePath();

		if ( piece.fill ) {
			ctx.globalAlpha = undefined === piece.alpha ? 1 : piece.alpha;
			ctx.fillStyle = piece.fill;
			ctx.fill();
			ctx.globalAlpha = 1;
		}

		if ( piece.stroke ) {
			ctx.strokeStyle = piece.stroke;
			ctx.lineWidth = piece.lineWidth || 1;
			ctx.stroke();
		}
	}

	/** The name of each block, over the middle of its own platform. */
	function drawLabels( ctx, scene, camera, width, height, palette ) {
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';

		scene.platforms.forEach( function ( platform ) {
			if ( ! platform.name ) {
				return;
			}

			var middle = centroid3( platform.points );
			var at = camera.project( middle[ 0 ], middle[ 1 ], middle[ 2 ] + 12, width, height );

			if ( ! at ) {
				return;
			}

			var size = Math.max( 10, Math.min( 20, at.scale * 26 ) );

			ctx.font = '600 ' + size.toFixed( 1 ) + 'px system-ui, sans-serif';
			ctx.lineWidth = size / 4;
			ctx.strokeStyle = palette.paper || '#ffffff';
			ctx.strokeText( platform.name, at.x, at.y );
			ctx.fillStyle = palette.text || '#1a1d2b';
			ctx.fillText( platform.name, at.x, at.y );
		} );
	}

	/**
	 * The chair under the pointer.
	 *
	 * Answered from the last frame's projected positions rather than by casting a ray: the frame
	 * has already worked out where every chair is, and the nearest one to the pointer within a few
	 * pixels is the one somebody is aiming at. Ties go to the chair nearest the camera, which is
	 * the one they can see.
	 */
	Hall3D.seatAt = function ( scene, point, radius ) {
		var reach = radius || 12;
		var best = null;
		var bestScore = Infinity;

		for ( var i = 0; i < scene.seats.length; i++ ) {
			var chair = scene.seats[ i ];

			if ( ! chair.screen ) {
				continue;
			}

			var dx = chair.screen.x - point.x;
			var dy = chair.screen.y - point.y;
			var d = Math.sqrt( dx * dx + dy * dy );

			if ( d > reach ) {
				continue;
			}

			var score = d + chair.screen.depth / 1000;

			if ( score < bestScore ) {
				bestScore = score;
				best = chair.seat;
			}
		}

		return best;
	};

	/* ------------------------------------------------------------------------------- helpers */

	function project( points, camera, width, height ) {
		var out = [];
		var depth = 0;

		for ( var i = 0; i < points.length; i++ ) {
			var at = camera.project( points[ i ][ 0 ], points[ i ][ 1 ], points[ i ][ 2 ] || 0, width, height );

			if ( ! at ) {
				return null;
			}

			out.push( at );
			depth += at.depth;
		}

		return { points: out, depth: depth / out.length };
	}

	function rectangleAt( focal, angle, width, depth ) {
		var cos = Math.cos( angle );
		var sin = Math.sin( angle );
		var half = width / 2;

		// Centred a little behind the focal point, away from the seats, so the front of the stage
		// is where the chart says the audience is looking.
		var cx = focal.x - cos * depth * 0.15;
		var cy = focal.y - sin * depth * 0.15;

		return [
			[ cx + -half * -sin + -depth / 2 * cos, cy + -half * cos + -depth / 2 * sin ],
			[ cx + half * -sin + -depth / 2 * cos, cy + half * cos + -depth / 2 * sin ],
			[ cx + half * -sin + depth / 2 * cos, cy + half * cos + depth / 2 * sin ],
			[ cx + -half * -sin + depth / 2 * cos, cy + -half * cos + depth / 2 * sin ],
		];
	}

	function distance( x1, y1, x2, y2 ) {
		var dx = x1 - x2;
		var dy = y1 - y2;

		return Math.sqrt( dx * dx + dy * dy );
	}

	function centreOf( bounds ) {
		var box = bounds || { x: 0, y: 0, width: 0, height: 0 };

		return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
	}

	function boundsOf( points ) {
		if ( ! points.length ) {
			return { x: 0, y: 0, width: 100, height: 100 };
		}

		var minX = Infinity;
		var minY = Infinity;
		var maxX = -Infinity;
		var maxY = -Infinity;

		points.forEach( function ( point ) {
			minX = Math.min( minX, point.x );
			minY = Math.min( minY, point.y );
			maxX = Math.max( maxX, point.x );
			maxY = Math.max( maxY, point.y );
		} );

		return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
	}

	function centroid3( points ) {
		var x = 0;
		var y = 0;
		var z = 0;

		points.forEach( function ( point ) {
			x += point[ 0 ];
			y += point[ 1 ];
			z += point[ 2 ] || 0;
		} );

		return [ x / points.length, y / points.length, z / points.length ];
	}

	/** A darker version of a colour, for the face of a chair that is turned away from the light. */
	function shade( colour, factor ) {
		var match = /^#?([0-9a-f]{6})$/i.exec( String( colour ).trim() );

		if ( ! match ) {
			return colour;
		}

		var value = parseInt( match[ 1 ], 16 );
		var r = Math.round( ( ( value >> 16 ) & 255 ) * factor );
		var g = Math.round( ( ( value >> 8 ) & 255 ) * factor );
		var b = Math.round( ( value & 255 ) * factor );

		return 'rgb(' + r + ',' + g + ',' + b + ')';
	}

	function number( value, fallback ) {
		var parsed = parseFloat( value );

		return isFinite( parsed ) ? parsed : fallback;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	Hall3D.shade = shade;

	global.SeatmapHall3D = Hall3D;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = Hall3D;
	}
}( typeof window !== 'undefined' ? window : globalThis ) );
