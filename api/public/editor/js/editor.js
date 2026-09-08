/**
 * Canvas seat map editor.
 *
 * Splits into three concerns on purpose:
 *   - SeatmapGeometry (geometry.js) decides what a map is;
 *   - History records geometry snapshots for undo/redo;
 *   - Editor draws, hit-tests and translates gestures into geometry operations.
 *
 * Rendering is redrawn wholesale on every change rather than diffed. At the sizes involved
 * (a few thousand seats) a full repaint is well under a frame, and a diffing layer would be a
 * large amount of state to keep correct for no visible gain.
 */
( function ( global ) {
	'use strict';

	var Geometry = global.SeatmapGeometry;

	var SEAT_RADIUS = 9;

	/**
	 * Undo/redo over whole-geometry snapshots.
	 *
	 * Snapshots, not commands: the editor mutates geometry in many small ways and an inverse for
	 * every one of them is a large surface to get subtly wrong. A map's JSON is small enough that
	 * keeping 50 copies costs less than the bugs would.
	 */
	function History( limit ) {
		this.limit = limit || 50;
		this.past = [];
		this.future = [];
	}

	History.prototype.push = function ( geometry ) {
		this.past.push( JSON.stringify( geometry ) );

		if ( this.past.length > this.limit ) {
			this.past.shift();
		}

		// Any new edit invalidates a redo branch.
		this.future = [];
	};

	History.prototype.undo = function ( current ) {
		if ( ! this.past.length ) {
			return null;
		}

		this.future.push( JSON.stringify( current ) );

		return JSON.parse( this.past.pop() );
	};

	History.prototype.redo = function ( current ) {
		if ( ! this.future.length ) {
			return null;
		}

		this.past.push( JSON.stringify( current ) );

		return JSON.parse( this.future.pop() );
	};

	History.prototype.canUndo = function () {
		return this.past.length > 0;
	};

	History.prototype.canRedo = function () {
		return this.future.length > 0;
	};

	function Editor( canvas, options ) {
		this.canvas = canvas;
		this.ctx = canvas.getContext( '2d' );
		this.options = options || {};
		this.geometry = this.options.geometry || Geometry.empty();
		this.history = new History();
		this.selection = [];
		this.view = { scale: 1, x: 40, y: 40 };
		this.grid = 10;
		this.snapToGrid = true;
		this.clipboard = [];
		this.zones = this.options.zones || [];
		this.onChange = this.options.onChange || function () {};
		// Selection is not geometry, but the panel shows a count of it, so it needs its own signal
		// — otherwise selecting seats silently leaves the sidebar showing a stale number.
		this.onSelectionChange = this.options.onSelectionChange || function () {};
		this.marquee = null;
		this.drag = null;
	}

	Editor.prototype.init = function () {
		this.bindPointer();
		this.bindKeyboard();
		this.resize();
		this.draw();

		return this;
	};

	Editor.prototype.setGeometry = function ( geometry, recordHistory ) {
		if ( recordHistory !== false ) {
			this.history.push( this.geometry );
		}

		this.geometry = geometry;
		this.selection = [];
		this.onSelectionChange( this.selection );
		this.draw();
		this.onChange( this.geometry );
	};

	/** Wrap a mutation so it is undoable and repaints exactly once. */
	Editor.prototype.mutate = function ( callback ) {
		this.history.push( this.geometry );
		callback( this.geometry );
		this.draw();
		this.onChange( this.geometry );
	};

	Editor.prototype.undo = function () {
		var previous = this.history.undo( this.geometry );

		if ( previous ) {
			this.geometry = previous;
			this.selection = [];
			this.onSelectionChange( this.selection );
			this.draw();
			this.onChange( this.geometry );
		}
	};

	Editor.prototype.redo = function () {
		var next = this.history.redo( this.geometry );

		if ( next ) {
			this.geometry = next;
			this.selection = [];
			this.onSelectionChange( this.selection );
			this.draw();
			this.onChange( this.geometry );
		}
	};

	Editor.prototype.resize = function () {
		var rect = this.canvas.parentNode.getBoundingClientRect();
		var dpr = window.devicePixelRatio || 1;

		this.canvas.width = rect.width * dpr;
		this.canvas.height = Math.max( 400, rect.height ) * dpr;
		this.canvas.style.width = rect.width + 'px';
		this.canvas.style.height = Math.max( 400, rect.height ) + 'px';
		this.dpr = dpr;

		this.draw();
	};

	Editor.prototype.toWorld = function ( clientX, clientY ) {
		var rect = this.canvas.getBoundingClientRect();

		return {
			x: ( clientX - rect.left - this.view.x ) / this.view.scale,
			y: ( clientY - rect.top - this.view.y ) / this.view.scale,
		};
	};

	Editor.prototype.draw = function () {
		var ctx = this.ctx;

		ctx.setTransform( this.dpr, 0, 0, this.dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.canvas.width, this.canvas.height );

		ctx.save();
		ctx.translate( this.view.x, this.view.y );
		ctx.scale( this.view.scale, this.view.scale );

		this.drawGrid( ctx );
		this.drawCanvasBounds( ctx );
		this.drawShapes( ctx );
		this.drawSeats( ctx );
		this.drawTexts( ctx );
		this.drawMarquee( ctx );

		ctx.restore();
	};

	Editor.prototype.drawGrid = function ( ctx ) {
		if ( ! this.snapToGrid || this.view.scale < 0.4 ) {
			return; // At small scales the grid is noise, not guidance.
		}

		var width = this.geometry.canvas.width;
		var height = this.geometry.canvas.height;

		ctx.save();
		ctx.strokeStyle = 'rgba(0,0,0,0.06)';
		ctx.lineWidth = 1 / this.view.scale;
		ctx.beginPath();

		for ( var x = 0; x <= width; x += this.grid * 5 ) {
			ctx.moveTo( x, 0 );
			ctx.lineTo( x, height );
		}

		for ( var y = 0; y <= height; y += this.grid * 5 ) {
			ctx.moveTo( 0, y );
			ctx.lineTo( width, y );
		}

		ctx.stroke();
		ctx.restore();
	};

	Editor.prototype.drawCanvasBounds = function ( ctx ) {
		ctx.save();
		ctx.strokeStyle = '#b6bcc7';
		ctx.lineWidth = 1 / this.view.scale;
		ctx.setLineDash( [ 6 / this.view.scale, 4 / this.view.scale ] );
		ctx.strokeRect( 0, 0, this.geometry.canvas.width, this.geometry.canvas.height );
		ctx.restore();
	};

	Editor.prototype.drawShapes = function ( ctx ) {
		var self = this;

		( this.geometry.shapes || [] ).forEach( function ( shape, index ) {
			ctx.save();
			ctx.fillStyle = shape.fill || self.shapeColour( shape.kind );
			ctx.fillRect( shape.x, shape.y, shape.width || 0, shape.height || 0 );

			if ( self.selection.indexOf( 'shape:' + index ) !== -1 ) {
				ctx.strokeStyle = '#12263f';
				ctx.lineWidth = 2 / self.view.scale;
				ctx.strokeRect( shape.x, shape.y, shape.width || 0, shape.height || 0 );
			}

			if ( shape.label ) {
				ctx.fillStyle = '#ffffff';
				ctx.font = '600 15px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText(
					shape.label,
					shape.x + ( shape.width || 0 ) / 2,
					shape.y + ( shape.height || 0 ) / 2
				);
			}

			ctx.restore();
		} );
	};

	Editor.prototype.shapeColour = function ( kind ) {
		switch ( kind ) {
			case 'stage':
				return '#3a3f4b';
			case 'entrance':
				return '#3f9c6d';
			case 'exit':
				return '#b3543a';
			case 'aisle':
				return '#e8eaee';
			case 'wall':
				return '#8a8f99';
			default:
				return '#c8ccd4';
		}
	};

	Editor.prototype.drawSeats = function ( ctx ) {
		var self = this;

		Geometry.eachSeat( this.geometry, function ( seat, row, section ) {
			var key = section.key + '/' + row.key + '/' + seat.key;
			var selected = self.selection.indexOf( key ) !== -1;

			ctx.beginPath();
			ctx.fillStyle = selected ? '#12263f' : self.zoneColour( seat.zone_key, section.color );
			ctx.strokeStyle = selected ? '#12263f' : 'rgba(0,0,0,0.25)';
			ctx.lineWidth = ( selected ? 2.5 : 1 ) / self.view.scale;

			if ( 'square' === seat.shape ) {
				ctx.rect( seat.x - SEAT_RADIUS, seat.y - SEAT_RADIUS, SEAT_RADIUS * 2, SEAT_RADIUS * 2 );
			} else {
				ctx.arc( seat.x, seat.y, SEAT_RADIUS, 0, Math.PI * 2 );
			}

			ctx.fill();
			ctx.stroke();

			// Labels only when there is room for them; otherwise they overlap into mush.
			if ( self.view.scale > 1.1 ) {
				ctx.save();
				ctx.fillStyle = selected ? '#ffffff' : 'rgba(0,0,0,0.65)';
				ctx.font = ( 9 ) + 'px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText( seat.label, seat.x, seat.y );
				ctx.restore();
			}
		} );
	};

	Editor.prototype.zoneColour = function ( zoneKey, fallback ) {
		for ( var i = 0; i < this.zones.length; i++ ) {
			if ( this.zones[ i ].key === zoneKey ) {
				return this.zones[ i ].color || '#2d6cdf';
			}
		}

		return fallback || '#2d6cdf';
	};

	Editor.prototype.drawTexts = function ( ctx ) {
		( this.geometry.texts || [] ).forEach( function ( text ) {
			ctx.save();
			ctx.fillStyle = text.color || '#3a3f4b';
			ctx.font = '500 ' + ( text.size || 14 ) + 'px system-ui, sans-serif';
			ctx.fillText( text.text, text.x, text.y );
			ctx.restore();
		} );
	};

	Editor.prototype.drawMarquee = function ( ctx ) {
		if ( ! this.marquee ) {
			return;
		}

		ctx.save();
		ctx.strokeStyle = '#12263f';
		ctx.fillStyle = 'rgba(18,38,63,0.08)';
		ctx.lineWidth = 1 / this.view.scale;

		var x = Math.min( this.marquee.x1, this.marquee.x2 );
		var y = Math.min( this.marquee.y1, this.marquee.y2 );
		var w = Math.abs( this.marquee.x2 - this.marquee.x1 );
		var h = Math.abs( this.marquee.y2 - this.marquee.y1 );

		ctx.fillRect( x, y, w, h );
		ctx.strokeRect( x, y, w, h );
		ctx.restore();
	};

	Editor.prototype.seatAt = function ( point ) {
		var found = null;
		var best = SEAT_RADIUS * 1.4;

		Geometry.eachSeat( this.geometry, function ( seat, row, section ) {
			var distance = Math.hypot( seat.x - point.x, seat.y - point.y );

			if ( distance < best ) {
				best = distance;
				found = { key: section.key + '/' + row.key + '/' + seat.key, seat: seat };
			}
		} );

		return found;
	};

	Editor.prototype.bindPointer = function () {
		var self = this;

		this.canvas.addEventListener( 'pointerdown', function ( event ) {
			self.canvas.setPointerCapture( event.pointerId );
			var point = self.toWorld( event.clientX, event.clientY );

			// Space or middle button pans; everything else is selection or drag.
			if ( 1 === event.button || self.spaceHeld ) {
				self.drag = { mode: 'pan', lastX: event.clientX, lastY: event.clientY };

				return;
			}

			var hit = self.seatAt( point );

			if ( hit ) {
				if ( event.shiftKey ) {
					self.toggleSelection( hit.key );
				} else if ( self.selection.indexOf( hit.key ) === -1 ) {
					self.selection = [ hit.key ];
					self.onSelectionChange( self.selection );
				}

				self.drag = {
					mode: 'move',
					startX: point.x,
					startY: point.y,
					lastX: point.x,
					lastY: point.y,
					moved: false,
				};
				self.draw();

				return;
			}

			if ( ! event.shiftKey ) {
				self.selection = [];
				self.onSelectionChange( self.selection );
			}

			self.marquee = { x1: point.x, y1: point.y, x2: point.x, y2: point.y };
			self.draw();
		} );

		this.canvas.addEventListener( 'pointermove', function ( event ) {
			var point = self.toWorld( event.clientX, event.clientY );

			if ( self.drag && 'pan' === self.drag.mode ) {
				self.view.x += event.clientX - self.drag.lastX;
				self.view.y += event.clientY - self.drag.lastY;
				self.drag.lastX = event.clientX;
				self.drag.lastY = event.clientY;
				self.draw();

				return;
			}

			if ( self.drag && 'move' === self.drag.mode ) {
				if ( ! self.drag.moved ) {
					// Record history once per drag, not once per pointermove — otherwise a single
					// drag would need thirty undos to reverse.
					self.history.push( self.geometry );
					self.drag.moved = true;
				}

				Geometry.moveSeats(
					self.geometry,
					self.selection,
					point.x - self.drag.lastX,
					point.y - self.drag.lastY
				);

				self.drag.lastX = point.x;
				self.drag.lastY = point.y;
				self.draw();

				return;
			}

			if ( self.marquee ) {
				self.marquee.x2 = point.x;
				self.marquee.y2 = point.y;
				self.draw();
			}
		} );

		this.canvas.addEventListener( 'pointerup', function () {
			if ( self.drag && 'move' === self.drag.mode && self.drag.moved ) {
				if ( self.snapToGrid ) {
					self.snapSelection();
				}

				self.onChange( self.geometry );
			}

			if ( self.marquee ) {
				self.selectWithin( self.marquee );
				self.marquee = null;
			}

			self.drag = null;
			self.draw();
		} );

		this.canvas.addEventListener(
			'wheel',
			function ( event ) {
				event.preventDefault();

				var factor = event.deltaY < 0 ? 1.1 : 0.9;
				var before = self.toWorld( event.clientX, event.clientY );

				self.view.scale = Math.min( 8, Math.max( 0.2, self.view.scale * factor ) );

				// Keep the point under the cursor fixed while zooming, which is what makes
				// wheel-zoom feel like a map rather than a slider.
				var after = self.toWorld( event.clientX, event.clientY );
				self.view.x += ( after.x - before.x ) * self.view.scale;
				self.view.y += ( after.y - before.y ) * self.view.scale;

				self.draw();
			},
			{ passive: false }
		);
	};

	Editor.prototype.snapSelection = function () {
		var self = this;

		Geometry.eachSeat( this.geometry, function ( seat, row, section ) {
			if ( self.selection.indexOf( section.key + '/' + row.key + '/' + seat.key ) !== -1 ) {
				seat.x = Geometry.snap( seat.x, self.grid );
				seat.y = Geometry.snap( seat.y, self.grid );
			}
		} );

		this.draw();
	};

	Editor.prototype.toggleSelection = function ( key ) {
		var index = this.selection.indexOf( key );

		if ( index === -1 ) {
			this.selection.push( key );
		} else {
			this.selection.splice( index, 1 );
		}

		this.onSelectionChange( this.selection );
	};

	Editor.prototype.selectWithin = function ( box ) {
		var minX = Math.min( box.x1, box.x2 );
		var maxX = Math.max( box.x1, box.x2 );
		var minY = Math.min( box.y1, box.y2 );
		var maxY = Math.max( box.y1, box.y2 );
		var self = this;

		Geometry.eachSeat( this.geometry, function ( seat, row, section ) {
			if ( seat.x >= minX && seat.x <= maxX && seat.y >= minY && seat.y <= maxY ) {
				var key = section.key + '/' + row.key + '/' + seat.key;

				if ( self.selection.indexOf( key ) === -1 ) {
					self.selection.push( key );
				}
			}
		} );

		this.onSelectionChange( this.selection );
	};

	Editor.prototype.selectAll = function () {
		var keys = [];

		Geometry.eachSeat( this.geometry, function ( seat, row, section ) {
			keys.push( section.key + '/' + row.key + '/' + seat.key );
		} );

		this.selection = keys;
		this.onSelectionChange( this.selection );
		this.draw();
	};

	Editor.prototype.bindKeyboard = function () {
		var self = this;

		window.addEventListener( 'keydown', function ( event ) {
			// Never hijack typing in a form field.
			if ( /^(INPUT|TEXTAREA|SELECT)$/.test( event.target.tagName ) ) {
				return;
			}

			if ( ' ' === event.key ) {
				self.spaceHeld = true;
				self.canvas.style.cursor = 'grab';
			}

			var meta = event.ctrlKey || event.metaKey;

			if ( meta && 'z' === event.key.toLowerCase() ) {
				event.preventDefault();
				event.shiftKey ? self.redo() : self.undo();

				return;
			}

			if ( meta && 'y' === event.key.toLowerCase() ) {
				event.preventDefault();
				self.redo();

				return;
			}

			if ( meta && 'a' === event.key.toLowerCase() ) {
				event.preventDefault();
				self.selectAll();

				return;
			}

			if ( meta && 'c' === event.key.toLowerCase() ) {
				self.clipboard = self.selection.slice();

				return;
			}

			if ( meta && 'v' === event.key.toLowerCase() ) {
				event.preventDefault();
				self.paste();

				return;
			}

			if ( meta && 'd' === event.key.toLowerCase() ) {
				event.preventDefault();
				self.clipboard = self.selection.slice();
				self.paste();

				return;
			}

			if ( 'Delete' === event.key || 'Backspace' === event.key ) {
				event.preventDefault();
				self.deleteSelection();

				return;
			}

			if ( 'Escape' === event.key ) {
				self.selection = [];
				self.onSelectionChange( self.selection );
				self.draw();

				return;
			}

			var nudges = {
				ArrowLeft: [ -1, 0 ],
				ArrowRight: [ 1, 0 ],
				ArrowUp: [ 0, -1 ],
				ArrowDown: [ 0, 1 ],
			};

			if ( nudges[ event.key ] && self.selection.length ) {
				event.preventDefault();

				// Shift nudges by a grid cell; a bare arrow moves one unit for fine work.
				var step = event.shiftKey ? self.grid : 1;

				self.mutate( function ( geometry ) {
					Geometry.moveSeats(
						geometry,
						self.selection,
						nudges[ event.key ][ 0 ] * step,
						nudges[ event.key ][ 1 ] * step
					);
				} );
			}
		} );

		window.addEventListener( 'keyup', function ( event ) {
			if ( ' ' === event.key ) {
				self.spaceHeld = false;
				self.canvas.style.cursor = 'default';
			}
		} );
	};

	Editor.prototype.paste = function () {
		if ( ! this.clipboard.length ) {
			return;
		}

		var self = this;

		this.mutate( function ( geometry ) {
			// Offset by a grid cell so the copy is visibly distinct from its source.
			self.selection = Geometry.duplicateSeats( geometry, self.clipboard, self.grid * 2, self.grid * 2 );
		} );
	};

	Editor.prototype.deleteSelection = function () {
		if ( ! this.selection.length ) {
			return;
		}

		var self = this;

		this.mutate( function ( geometry ) {
			Geometry.deleteSeats( geometry, self.selection );
			self.selection = [];
		} );
	};

	Editor.prototype.zoomToFit = function () {
		var rect = this.canvas.getBoundingClientRect();
		var scaleX = rect.width / ( this.geometry.canvas.width + 80 );
		var scaleY = rect.height / ( this.geometry.canvas.height + 80 );

		this.view.scale = Math.max( 0.2, Math.min( scaleX, scaleY ) );
		this.view.x = 40;
		this.view.y = 40;
		this.draw();
	};

	global.SeatmapEditor = Editor;
	global.SeatmapHistory = History;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { Editor: Editor, History: History };
	}
} )( typeof window !== 'undefined' ? window : globalThis );
