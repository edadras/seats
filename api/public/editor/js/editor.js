/**
 * The seat map editor: rendering, hit testing and the tools.
 *
 * Three deliberate choices shape this file.
 *
 * **Full repaint, no diffing.** Every change redraws the canvas. At the sizes involved a repaint is
 * well under a frame, and a diffing layer would be a large amount of state to keep correct in
 * exchange for nothing anyone can see.
 *
 * **Snapshot history.** Undo stores whole-chart JSON rather than inverse commands. A chart is small,
 * and writing an inverse for each of the editor's many small mutations is a large surface to get
 * subtly wrong.
 *
 * **Sections are places you go into.** At chart level a section draws as its outline with a
 * schematic of its rows; you enter it to work on seats. That is what makes a 4,000-seat arena
 * navigable instead of a wall of dots.
 */
( function ( global ) {
	'use strict';

	var Chart = global.SeatmapChart;
	var Ops = global.SeatmapChartOps;

	var SEAT_R = Chart.SEAT_SIZE / 2;

	/** The catalogue, read per call — see the note on the same helper in chart.js. */
	function t( key, replace ) {
		return global.SeatmapI18n.t( key, replace );
	}

	function History( limit ) {
		this.limit = limit || 60;
		this.past = [];
		this.future = [];
	}

	History.prototype.push = function ( chart ) {
		this.past.push( JSON.stringify( chart ) );

		if ( this.past.length > this.limit ) {
			this.past.shift();
		}

		// A new edit invalidates the redo branch — redoing into work the user has since edited
		// away from would silently discard it.
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

	History.prototype.canUndo = function () { return this.past.length > 0; };
	History.prototype.canRedo = function () { return this.future.length > 0; };

	function Editor( canvas, options ) {
		options = options || {};

		this.canvas = canvas;
		this.ctx = canvas.getContext( '2d' );
		this.chart = options.chart || Chart.empty();
		this.history = new History();

		this.floorKey = this.chart.floors[ 0 ].key;
		/** When set, the designer is inside this section and only its contents are editable. */
		this.sectionKey = null;

		this.selection = [];       // object keys
		this.seatSelection = [];   // "objectKey/seatKey" for individual chairs
		this.layer = 'all';
		this.tool = 'select';

		this.view = { scale: 1, x: 60, y: 60 };
		this.grid = 10;
		this.snapToGrid = true;
		this.showLabels = true;
		this.locked = false;
		this.clipboard = null;

		this.marquee = null;
		this.lasso = null;
		this.drag = null;
		this.draft = null;

		this.onChange = options.onChange || function () {};
		this.onSelectionChange = options.onSelectionChange || function () {};
		this.onContextChange = options.onContextChange || function () {};
		this.onStatus = options.onStatus || function () {};
	}

	Editor.prototype.init = function () {
		this.bindPointer();
		this.bindKeyboard();
		this.resize();

		return this;
	};

	/* ------------------------------------------------------------------------- context */

	Editor.prototype.floor = function () {
		var self = this;
		var found = this.chart.floors[ 0 ];

		this.chart.floors.forEach( function ( floor ) {
			if ( floor.key === self.floorKey ) {
				found = floor;
			}
		} );

		return found;
	};

	/** The container edits apply to: a section when inside one, otherwise the floor. */
	Editor.prototype.container = function () {
		if ( ! this.sectionKey ) {
			return this.floor();
		}

		var found = Chart.findObject( this.chart, this.sectionKey );

		return found ? found.object : this.floor();
	};

	Editor.prototype.enterSection = function ( key ) {
		this.sectionKey = key;
		this.selection = [];
		this.seatSelection = [];
		this.zoomToFit();
		this.onContextChange();
		this.onSelectionChange();
	};

	Editor.prototype.exitSection = function () {
		this.sectionKey = null;
		this.selection = [];
		this.seatSelection = [];
		this.zoomToFit();
		this.onContextChange();
		this.onSelectionChange();
	};

	Editor.prototype.setFloor = function ( key ) {
		this.floorKey = key;
		this.sectionKey = null;
		this.selection = [];
		this.seatSelection = [];
		this.zoomToFit();
		this.onContextChange();
	};

	Editor.prototype.selectedObjects = function () {
		var self = this;
		var objects = [];

		Chart.eachObject( this.chart, function ( object ) {
			if ( self.selection.indexOf( object.key ) !== -1 ) {
				objects.push( object );
			}
		} );

		return objects;
	};

	Editor.prototype.selectedSeats = function () {
		var self = this;
		var seats = [];

		Chart.eachSeat( this.chart, function ( seat, owner ) {
			if ( self.seatSelection.indexOf( owner.key + '/' + seat.key ) !== -1 ) {
				seats.push( { seat: seat, owner: owner } );
			}
		} );

		return seats;
	};

	/* ------------------------------------------------------------------------ mutation */

	Editor.prototype.mutate = function ( callback ) {
		if ( this.locked ) {
			this.onStatus( t( 'panel.hints.readOnly' ) );

			return;
		}

		this.history.push( this.chart );
		callback( this.chart );
		this.draw();
		this.onChange( this.chart );
	};

	Editor.prototype.undo = function () {
		var previous = this.history.undo( this.chart );

		if ( previous ) {
			this.applyRestored( previous );
		}
	};

	Editor.prototype.redo = function () {
		var next = this.history.redo( this.chart );

		if ( next ) {
			this.applyRestored( next );
		}
	};

	Editor.prototype.applyRestored = function ( chart ) {
		this.chart = chart;
		this.selection = [];
		this.seatSelection = [];

		// The section or floor may not exist in the restored state — fall back rather than
		// leaving the editor pointing at nothing.
		if ( this.sectionKey && ! Chart.findObject( this.chart, this.sectionKey ) ) {
			this.sectionKey = null;
		}

		if ( ! this.floor() ) {
			this.floorKey = this.chart.floors[ 0 ].key;
		}

		this.onSelectionChange();
		this.onContextChange();
		this.draw();
		this.onChange( this.chart );
	};

	/* ------------------------------------------------------------------------ rendering */

	Editor.prototype.resize = function () {
		var host = this.canvas.parentNode.getBoundingClientRect();
		var dpr = window.devicePixelRatio || 1;
		var height = Math.max( 380, host.height );

		this.canvas.width = host.width * dpr;
		this.canvas.height = height * dpr;
		this.canvas.style.width = host.width + 'px';
		this.canvas.style.height = height + 'px';
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

	/**
	 * The canvas palette.
	 *
	 * A canvas has no cascade, so the two themes are stated here rather than read from CSS. They
	 * track the tokens in design.css by hand — the alternative, reading a dozen custom properties
	 * on every repaint, costs a style flush per frame while something is being dragged.
	 */
	var PALETTES = {
		light: {
			grid: 'rgba(27,32,48,0.06)',
			bounds: '#c7cddb',
			ink: '#1b2030',
			inkFill: 'rgba(27,32,48,0.08)',
			halo: 'rgba(27,32,48,0.22)',
			seatFillOn: '#1b2030',
			seatTextOn: '#ffffff',
			seatEdge: 'rgba(27,32,48,0.22)',
			seatText: 'rgba(27,32,48,0.72)',
			emptySeat: 'rgba(27,32,48,0.25)',
			rowLabel: 'rgba(27,32,48,0.55)',
			dimmed: '#9aa3b7',
			neutralSeat: '#c9ced6',
			text: '#3d4457',
			iconInk: '#4a5160',
			shapeEdge: 'rgba(27,32,48,0.2)',
			shapeLabel: '#ffffff',
			focal: '#d1495b',
			accessible: '#1c4f8f',
			// How far a category colour moves to make a label legible against its own fill.
			labelShift: -0.45,
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
			grid: 'rgba(233,236,243,0.06)',
			bounds: '#3b4256',
			ink: '#e9ecf3',
			inkFill: 'rgba(233,236,243,0.08)',
			halo: 'rgba(233,236,243,0.20)',
			seatFillOn: '#f1f3f7',
			seatTextOn: '#12151f',
			seatEdge: 'rgba(9,11,16,0.45)',
			seatText: 'rgba(9,11,16,0.78)',
			emptySeat: 'rgba(233,236,243,0.28)',
			rowLabel: 'rgba(233,236,243,0.6)',
			dimmed: '#6f7891',
			neutralSeat: '#5b6478',
			text: '#c3cad9',
			iconInk: '#a8b0c3',
			shapeEdge: 'rgba(233,236,243,0.22)',
			// A shape label sits on the shape's own fill, which is dark in this theme — not on
			// the canvas — so it lightens rather than darkening.
			shapeLabel: '#e9ecf3',
			focal: '#f0736a',
			accessible: '#9dc4f5',
			labelShift: 0.4,
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

	/** The palette for whatever theme the document is in, looked up once per repaint. */
	Editor.prototype.colors = function () {
		return 'dark' === document.documentElement.getAttribute( 'data-theme' )
			? PALETTES.dark
			: PALETTES.light;
	};

	Editor.prototype.draw = function () {
		var ctx = this.ctx;
		var floor = this.floor();

		ctx.setTransform( this.dpr, 0, 0, this.dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.canvas.width, this.canvas.height );
		ctx.save();
		ctx.translate( this.view.x, this.view.y );
		ctx.scale( this.view.scale, this.view.scale );

		this.drawGrid( ctx, floor );
		this.drawCanvasBounds( ctx, floor );

		var self = this;
		var inside = this.sectionKey;

		// Paint back to front by layer so scenery never covers seats.
		Chart.LAYERS.forEach( function ( layer ) {
			( floor.objects || [] ).forEach( function ( object ) {
				if ( ( object.layer || 'interactive' ) !== layer ) {
					return;
				}

				// Everything outside the section being edited stays visible but recedes, so the
				// designer keeps their bearings without being able to click the wrong thing.
				var dimmed = inside && object.key !== inside;

				self.drawObject( ctx, object, dimmed );
			} );
		} );

		if ( inside ) {
			var section = this.container();

			if ( section && section.objects ) {
				Chart.LAYERS.forEach( function ( layer ) {
					section.objects.forEach( function ( object ) {
						if ( ( object.layer || 'interactive' ) === layer ) {
							self.drawObject( ctx, object, false );
						}
					} );
				} );
			}
		}

		this.drawFocalPoint( ctx );
		this.drawDraft( ctx );
		this.drawMarquee( ctx );
		this.drawLasso( ctx );

		ctx.restore();
	};

	Editor.prototype.drawGrid = function ( ctx, floor ) {
		if ( ! this.snapToGrid || this.view.scale < 0.35 ) {
			return; // At small scales the grid is noise rather than guidance.
		}

		ctx.save();
		ctx.strokeStyle = this.colors().grid;
		ctx.lineWidth = 1 / this.view.scale;
		ctx.beginPath();

		for ( var x = 0; x <= floor.canvas.width; x += this.grid * 5 ) {
			ctx.moveTo( x, 0 );
			ctx.lineTo( x, floor.canvas.height );
		}

		for ( var y = 0; y <= floor.canvas.height; y += this.grid * 5 ) {
			ctx.moveTo( 0, y );
			ctx.lineTo( floor.canvas.width, y );
		}

		ctx.stroke();
		ctx.restore();
	};

	Editor.prototype.drawCanvasBounds = function ( ctx, floor ) {
		ctx.save();
		ctx.strokeStyle = this.colors().bounds;
		ctx.lineWidth = 1 / this.view.scale;
		ctx.setLineDash( [ 6 / this.view.scale, 4 / this.view.scale ] );
		ctx.strokeRect( 0, 0, floor.canvas.width, floor.canvas.height );
		ctx.restore();
	};

	Editor.prototype.drawObject = function ( ctx, object, dimmed ) {
		ctx.save();
		ctx.globalAlpha = dimmed ? 0.25 : 1;

		switch ( object.type ) {
			case 'section': this.drawSection( ctx, object, dimmed ); break;
			case 'row': this.drawRow( ctx, object ); break;
			case 'area': this.drawArea( ctx, object ); break;
			case 'table': this.drawTable( ctx, object ); break;
			case 'booth': this.drawBooth( ctx, object ); break;
			case 'shape': this.drawShape( ctx, object ); break;
			case 'text': this.drawText( ctx, object ); break;
			case 'icon': this.drawIcon( ctx, object ); break;
			case 'image': this.drawImage( ctx, object ); break;
		}

		ctx.restore();
	};

	Editor.prototype.categoryColor = function ( key, fallback ) {
		var category = key ? Chart.category( this.chart, key ) : null;

		return category ? category.color : fallback || '#9aa1ad';
	};

	/**
	 * A section at chart level: its outline, filled in its category colour, with a light schematic
	 * of the rows inside so the shape reads as seating rather than as a blank polygon.
	 */
	Editor.prototype.drawSection = function ( ctx, section, dimmed ) {
		var selected = this.selection.indexOf( section.key ) !== -1;
		var color = section.color || this.categoryColor( section.categoryKey, '#2d6cdf' );

		ctx.beginPath();
		section.polygon.forEach( function ( point, index ) {
			index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
		} );
		ctx.closePath();

		ctx.fillStyle = withAlpha( color, 0.18 );
		ctx.fill();
		ctx.strokeStyle = selected ? this.colors().ink : color;
		ctx.lineWidth = ( selected ? 3 : 1.5 ) / this.view.scale;
		ctx.stroke();

		if ( this.sectionKey !== section.key ) {
			this.drawSectionSchematic( ctx, section, color );
		}

		var labeling = section.labeling || {};

		if ( false !== labeling.visible && Chart.objectLabel( section ) ) {
			var center = Chart.polygonCentroid( section.polygon );

			ctx.save();
			ctx.fillStyle = dimmed ? this.colors().dimmed : shade( color, this.colors().labelShift );
			ctx.font = '600 ' + ( labeling.fontSize || 16 ) + 'px system-ui, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( Chart.objectLabel( section ), center.x, center.y );
			ctx.restore();
		}
	};

	/** Each row drawn as a single stroke — the "lines inside the polygon" look of a zoomed-out chart. */
	Editor.prototype.drawSectionSchematic = function ( ctx, section, color ) {
		ctx.save();
		ctx.strokeStyle = withAlpha( color, 0.55 );
		ctx.lineWidth = Math.max( 1.5, 3 / this.view.scale );
		ctx.lineCap = 'round';

		( section.objects || [] ).forEach( function ( object ) {
			if ( 'row' !== object.type ) {
				return;
			}

			var positions = Chart.rowSeatPositions( object );

			if ( positions.length < 2 ) {
				return;
			}

			ctx.beginPath();
			positions.forEach( function ( point, index ) {
				index === 0 ? ctx.moveTo( point.x, point.y ) : ctx.lineTo( point.x, point.y );
			} );
			ctx.stroke();
		} );

		ctx.restore();
	};

	Editor.prototype.drawRow = function ( ctx, row ) {
		var self = this;
		var colors = this.colors();
		var positions = Chart.rowSeatPositions( row );
		var rowSelected = this.selection.indexOf( row.key ) !== -1;

		if ( rowSelected ) {
			// A translucent capsule behind the row, so a selected row reads as one object rather
			// than as a handful of separately highlighted circles.
			this.drawRowHalo( ctx, positions );
		}

		positions.forEach( function ( point, index ) {
			var seat = row.seats[ index ];

			if ( ! seat ) {
				return;
			}

			var seatKey = row.key + '/' + seat.key;
			var selected = rowSelected || self.seatSelection.indexOf( seatKey ) !== -1;
			var category = seat.categoryKey || row.categoryKey;

			if ( 'empty' === seat.type ) {
				ctx.save();
				ctx.strokeStyle = colors.emptySeat;
				ctx.setLineDash( [ 2, 2 ] );
				ctx.lineWidth = 1 / self.view.scale;
				ctx.beginPath();
				ctx.arc( point.x, point.y, SEAT_R, 0, Math.PI * 2 );
				ctx.stroke();
				ctx.restore();

				return;
			}

			ctx.beginPath();
			ctx.arc( point.x, point.y, SEAT_R, 0, Math.PI * 2 );
			ctx.fillStyle = selected
				? colors.seatFillOn
				: withAlpha( self.categoryColor( category, colors.neutralSeat ), 0.85 );
			ctx.fill();
			ctx.strokeStyle = selected ? colors.seatFillOn : colors.seatEdge;
			ctx.lineWidth = ( selected ? 2 : 1 ) / self.view.scale;
			ctx.stroke();

			if ( seat.accessible ) {
				self.drawWheelchair( ctx, point.x, point.y, selected );
			} else if ( self.showLabels && self.view.scale > 1 ) {
				ctx.save();
				ctx.fillStyle = selected ? colors.seatTextOn : colors.seatText;
				ctx.font = '9px system-ui, sans-serif';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText( seat.label, point.x, point.y );
				ctx.restore();
			}
		} );

		if ( this.showLabels && positions.length ) {
			this.drawRowLabels( ctx, row, positions );
		}
	};

	Editor.prototype.drawRowHalo = function ( ctx, positions ) {
		ctx.save();
		ctx.strokeStyle = this.colors().halo;
		ctx.lineWidth = ( Chart.SEAT_SIZE + 8 );
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.beginPath();
		positions.forEach( function ( point, index ) {
			index === 0 ? ctx.moveTo( point.x, point.y ) : ctx.lineTo( point.x, point.y );
		} );

		if ( positions.length === 1 ) {
			ctx.arc( positions[ 0 ].x, positions[ 0 ].y, 1, 0, Math.PI * 2 );
		}

		ctx.stroke();
		ctx.restore();
	};

	/** Row labels at the ends the designer asked for — both, one, or neither. */
	Editor.prototype.drawRowLabels = function ( ctx, row, positions ) {
		var labeling = row.labeling || {};

		if ( false === labeling.enabled ) {
			return;
		}

		var text = Chart.displayedRowLabel( row );

		if ( ! text ) {
			return;
		}

		var position = labeling.position || 'both';

		if ( 'none' === position ) {
			return;
		}

		var pitch = Chart.SEAT_SIZE + ( Number( row.seatSpacing ) || 0 );
		var first = positions[ 0 ];
		var last = positions[ positions.length - 1 ];
		var theta = ( ( Number( row.rotation ) || 0 ) * Math.PI ) / 180;

		ctx.save();
		ctx.fillStyle = this.colors().rowLabel;
		ctx.font = '600 10px system-ui, sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';

		if ( 'both' === position || 'start' === position ) {
			ctx.fillText( text, first.x - Math.cos( theta ) * pitch, first.y - Math.sin( theta ) * pitch );
		}

		if ( 'both' === position || 'end' === position ) {
			ctx.fillText( text, last.x + Math.cos( theta ) * pitch, last.y + Math.sin( theta ) * pitch );
		}

		ctx.restore();
	};

	Editor.prototype.drawWheelchair = function ( ctx, x, y, selected ) {
		ctx.save();
		ctx.strokeStyle = selected ? this.colors().seatTextOn : this.colors().accessible;
		ctx.lineWidth = 1.4;
		ctx.beginPath();
		ctx.arc( x, y - 3.2, 1.5, 0, Math.PI * 2 );   // head
		ctx.moveTo( x - 1, y - 1.5 );
		ctx.lineTo( x - 1, y + 1.5 );                  // back
		ctx.moveTo( x - 1, y + 1.5 );
		ctx.lineTo( x + 2.5, y + 1.5 );                // legs
		ctx.stroke();
		ctx.beginPath();
		ctx.arc( x - 0.5, y + 2.6, 2.6, 0, Math.PI * 2 ); // wheel
		ctx.stroke();
		ctx.restore();
	};

	Editor.prototype.drawArea = function ( ctx, area ) {
		var selected = this.selection.indexOf( area.key ) !== -1;
		var color = this.categoryColor( area.categoryKey, '#e0526a' );
		var shape = area.shape;

		ctx.save();
		turn( ctx, shape, Ops.bounds( area ) );
		ctx.globalAlpha *= area.translucent ? 0.45 : 1;
		ctx.fillStyle = withAlpha( color, 0.28 );
		ctx.strokeStyle = selected ? this.colors().ink : color;
		ctx.lineWidth = ( selected ? 3 : 1.5 ) / this.view.scale;

		this.tracePath( ctx, shape );
		ctx.fill();
		ctx.stroke();
		ctx.restore();

		var labeling = area.labeling || {};

		if ( false !== labeling.visible && Chart.objectLabel( area ) ) {
			var box = Ops.bounds( area );

			ctx.save();
			ctx.fillStyle = shade( color, this.colors().labelShift );
			ctx.font = '600 ' + ( labeling.fontSize || 20 ) + 'px system-ui, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(
				Chart.objectLabel( area ),
				box.x + box.width / 2 + ( ( labeling.positionX || 0 ) / 100 ) * box.width,
				box.y + box.height / 2 + ( ( labeling.positionY || 0 ) / 100 ) * box.height
			);
			ctx.restore();
		}
	};

	Editor.prototype.drawBooth = function ( ctx, booth ) {
		this.drawArea( ctx, booth );
	};

	Editor.prototype.drawTable = function ( ctx, table ) {
		var self = this;
		var colors = this.colors();
		var selected = this.selection.indexOf( table.key ) !== -1;
		var color = this.categoryColor( table.categoryKey, '#8a6f4b' );

		ctx.save();
		ctx.translate( table.x, table.y );
		ctx.rotate( ( ( table.rotation || 0 ) * Math.PI ) / 180 );
		ctx.fillStyle = withAlpha( color, 0.3 );
		ctx.strokeStyle = selected ? colors.ink : color;
		ctx.lineWidth = ( selected ? 3 : 1.5 ) / this.view.scale;
		ctx.beginPath();

		if ( 'round' === table.shape ) {
			ctx.ellipse( 0, 0, table.width / 2, table.height / 2, 0, 0, Math.PI * 2 );
		} else {
			ctx.rect( -table.width / 2, -table.height / 2, table.width, table.height );
		}

		ctx.fill();
		ctx.stroke();

		if ( ( table.labeling || {} ).visible !== false && Chart.objectLabel( table ) ) {
			ctx.rotate( -( ( table.rotation || 0 ) * Math.PI ) / 180 );
			ctx.fillStyle = shade( color, this.colors().labelShift );
			ctx.font = '600 ' + ( ( table.labeling || {} ).fontSize || 14 ) + 'px system-ui, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( Chart.objectLabel( table ), 0, 0 );
		}

		ctx.restore();

		Chart.tableSeatPositions( table ).forEach( function ( point, index ) {
			var seat = table.seats[ index ];
			var seatSelected = selected || self.seatSelection.indexOf( table.key + '/' + seat.key ) !== -1;

			ctx.beginPath();
			ctx.arc( point.x, point.y, SEAT_R, 0, Math.PI * 2 );
			ctx.fillStyle = seatSelected
				? colors.seatFillOn
				: withAlpha( self.categoryColor( seat.categoryKey || table.categoryKey, colors.neutralSeat ), 0.85 );
			ctx.fill();
			ctx.strokeStyle = colors.seatEdge;
			ctx.lineWidth = 1 / self.view.scale;
			ctx.stroke();
		} );
	};

	Editor.prototype.drawShape = function ( ctx, shape ) {
		var colors = this.colors();
		var selected = this.selection.indexOf( shape.key ) !== -1;

		ctx.save();
		ctx.fillStyle = shape.fill || shapeColour( shape.kind, colors );
		ctx.strokeStyle = selected ? colors.ink : colors.shapeEdge;
		ctx.lineWidth = ( selected ? 3 : 1 ) / this.view.scale;

		turn( ctx, shape, Ops.bounds( shape ) );

		if ( 'line' === shape.kind && shape.points ) {
			ctx.beginPath();
			shape.points.forEach( function ( point, index ) {
				index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
			} );
			ctx.strokeStyle = shape.fill || colors.shapes.wall;
			// Its own weight, still measured on screen rather than on the plan: a floor drawing
			// says which walls are thick, and a hairline at 15% zoom says nothing at all.
			ctx.lineWidth = ( shape.strokeWidth || 3 ) / this.view.scale;
			ctx.stroke();
			ctx.restore();

			return;
		}

		this.tracePath( ctx, shape );
		ctx.fill();

		if ( selected ) {
			ctx.stroke();
		}

		if ( shape.label ) {
			ctx.fillStyle = colors.shapeLabel;
			ctx.font = '600 15px system-ui, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( shape.label, shape.x + shape.width / 2, shape.y + shape.height / 2 );
		}

		ctx.restore();
	};

	/**
	 * Turn the canvas about an object's own middle.
	 *
	 * The inspector has offered a rotation on shapes and areas since the designer was built, and
	 * nothing drew it — a stage set at 30 degrees stayed square on both the designer's canvas and
	 * the buyer's. The rotation was being stored, published and ignored.
	 */
	function turn( ctx, object, box ) {
		var angle = ( ( ( object && object.rotation ) || 0 ) * Math.PI ) / 180;

		if ( ! angle ) {
			return;
		}

		var cx = box.x + box.width / 2;
		var cy = box.y + box.height / 2;

		ctx.translate( cx, cy );
		ctx.rotate( angle );
		ctx.translate( -cx, -cy );
	}

	Editor.prototype.tracePath = function ( ctx, shape ) {
		ctx.beginPath();

		if ( shape.points ) {
			shape.points.forEach( function ( point, index ) {
				index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
			} );
			ctx.closePath();

			return;
		}

		if ( 'ellipse' === shape.kind ) {
			ctx.ellipse(
				shape.x + shape.width / 2, shape.y + shape.height / 2,
				shape.width / 2, shape.height / 2, 0, 0, Math.PI * 2
			);

			return;
		}

		var radius = Math.min( shape.cornerRadius || 0, shape.width / 2, shape.height / 2 );

		if ( radius > 0 && ctx.roundRect ) {
			ctx.roundRect( shape.x, shape.y, shape.width, shape.height, radius );
		} else {
			ctx.rect( shape.x, shape.y, shape.width, shape.height );
		}
	};

	Editor.prototype.drawText = function ( ctx, text ) {
		var selected = this.selection.indexOf( text.key ) !== -1;

		ctx.save();
		ctx.translate( text.x, text.y );
		ctx.rotate( ( ( text.rotation || 0 ) * Math.PI ) / 180 );
		ctx.fillStyle = text.color || this.colors().text;
		ctx.font = '500 ' + ( text.fontSize || 16 ) + 'px system-ui, sans-serif';
		ctx.textBaseline = 'alphabetic';
		ctx.fillText( text.text, 0, 0 );

		if ( selected ) {
			var width = ctx.measureText( text.text ).width;
			ctx.strokeStyle = this.colors().ink;
			ctx.lineWidth = 1.5 / this.view.scale;
			ctx.strokeRect( -2, -( text.fontSize || 16 ), width + 4, ( text.fontSize || 16 ) * 1.3 );
		}

		ctx.restore();
	};

	/**
	 * A venue marker, drawn from the same vector paths the panel uses.
	 *
	 * These were emoji, which meant a different weight, colour and size on every platform, and a
	 * chart that looked different to the venue than it did to the buyer.
	 */
	Editor.prototype.drawIcon = function ( ctx, marker ) {
		var colors = this.colors();
		var selected = this.selection.indexOf( marker.key ) !== -1;
		var size = marker.size || 20;
		var data = global.SeatmapIcon && global.SeatmapIcon.venuePath( marker.name );

		ctx.save();
		ctx.strokeStyle = selected ? colors.ink : colors.iconInk;
		ctx.fillStyle = ctx.strokeStyle;

		if ( data && global.Path2D ) {
			// The paths are drawn on a 24-unit grid, so scale to the marker and keep the stroke
			// weight constant in that space rather than in chart units.
			ctx.translate( marker.x - size / 2, marker.y - size / 2 );
			ctx.scale( size / 24, size / 24 );
			ctx.lineWidth = 1.75;
			ctx.lineCap = 'round';
			ctx.lineJoin = 'round';
			ctx.stroke( new global.Path2D( data ) );
		} else {
			// An unknown marker still has to be visible, or it becomes impossible to select and
			// delete.
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.font = '600 ' + Math.round( size * 0.7 ) + 'px system-ui, sans-serif';
			ctx.fillText( String( marker.name || '?' ).charAt( 0 ).toUpperCase(), marker.x, marker.y );
		}

		ctx.restore();
	};

	Editor.prototype.drawImage = function ( ctx, object ) {
		var cached = this.imageCache && this.imageCache[ object.key ];

		if ( ! cached ) {
			this.imageCache = this.imageCache || {};
			var image = new window.Image();
			var self = this;

			image.onload = function () {
				self.draw();
			};

			image.src = object.href;
			this.imageCache[ object.key ] = image;

			return;
		}

		if ( ! cached.complete ) {
			return;
		}

		ctx.save();
		ctx.globalAlpha *= object.opacity == null ? 1 : object.opacity;
		ctx.drawImage( cached, object.x, object.y, object.width, object.height );
		ctx.restore();
	};

	/** The focal point: a crosshair, drawn last so it is never hidden behind seating. */
	Editor.prototype.drawFocalPoint = function ( ctx ) {
		if ( ! this.chart.focalPoint ) {
			return;
		}

		var point = this.chart.focalPoint;
		var size = 12 / this.view.scale;

		ctx.save();
		ctx.strokeStyle = this.colors().focal;
		ctx.lineWidth = 2 / this.view.scale;
		ctx.beginPath();
		ctx.moveTo( point.x - size, point.y );
		ctx.lineTo( point.x + size, point.y );
		ctx.moveTo( point.x, point.y - size );
		ctx.lineTo( point.x, point.y + size );
		ctx.stroke();
		ctx.beginPath();
		ctx.arc( point.x, point.y, size * 0.55, 0, Math.PI * 2 );
		ctx.stroke();
		ctx.restore();
	};

	Editor.prototype.drawMarquee = function ( ctx ) {
		if ( ! this.marquee ) {
			return;
		}

		var box = normalise( this.marquee );

		ctx.save();
		ctx.fillStyle = this.colors().inkFill;
		ctx.strokeStyle = this.colors().ink;
		ctx.lineWidth = 1 / this.view.scale;
		ctx.fillRect( box.x, box.y, box.width, box.height );
		ctx.strokeRect( box.x, box.y, box.width, box.height );
		ctx.restore();
	};

	Editor.prototype.drawLasso = function ( ctx ) {
		if ( ! this.lasso || this.lasso.length < 2 ) {
			return;
		}

		ctx.save();
		ctx.fillStyle = this.colors().inkFill;
		ctx.strokeStyle = this.colors().ink;
		ctx.lineWidth = 1 / this.view.scale;
		ctx.beginPath();
		this.lasso.forEach( function ( point, index ) {
			index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
		} );
		ctx.closePath();
		ctx.fill();
		ctx.stroke();
		ctx.restore();
	};

	/** The object being drawn right now, before it is committed to the chart. */
	Editor.prototype.drawDraft = function ( ctx ) {
		if ( ! this.draft ) {
			return;
		}

		ctx.save();
		ctx.strokeStyle = this.colors().ink;
		ctx.setLineDash( [ 5 / this.view.scale, 4 / this.view.scale ] );
		ctx.lineWidth = 1.5 / this.view.scale;

		if ( 'polygon' === this.draft.kind ) {
			ctx.beginPath();
			this.draft.points.forEach( function ( point, index ) {
				index === 0 ? ctx.moveTo( point[ 0 ], point[ 1 ] ) : ctx.lineTo( point[ 0 ], point[ 1 ] );
			} );
			ctx.stroke();
		} else {
			var box = normalise( this.draft );
			ctx.strokeRect( box.x, box.y, box.width, box.height );
		}

		ctx.restore();
	};

	/* ----------------------------------------------------------------------- hit testing */

	Editor.prototype.objectAt = function ( point ) {
		var container = this.container();
		var candidates = [];

		( container.objects || [] ).forEach( function ( object ) {
			if ( Ops.hitTest( object, point ) ) {
				candidates.push( object );
			}
		} );

		// Last drawn is topmost, so the last match is the one under the cursor.
		return candidates.length ? candidates[ candidates.length - 1 ] : null;
	};

	Editor.prototype.seatAt = function ( point ) {
		return Ops.seatAt( this.container(), point );
	};

	/* ------------------------------------------------------------------------ interaction */

	Editor.prototype.setTool = function ( tool ) {
		this.tool = tool;
		this.draft = null;
		this.onStatus( t( 'panel.hints.' + ( HINTED[ tool ] ? tool : 'select' ) ) );
		this.canvas.style.cursor = 'pan' === tool ? 'grab' : 'crosshair';

		if ( 'select' === tool ) {
			this.canvas.style.cursor = 'default';
		}
	};

	/*
	 * Tools that have a sentence of their own under `panel.hints`. Anything not in here falls back
	 * to the select hint, which is the honest answer for a tool that does not draw.
	 */
	var HINTED = {
		select: true, sameType: true, lasso: true, pan: true, row: true, curvedRow: true,
		section: true, area: true, table: true, booth: true, shape: true, line: true,
		text: true, image: true, icon: true, focalPoint: true,
	};

	Editor.prototype.bindPointer = function () {
		var self = this;

		/*
		 * Suspended while the canvas is showing the room rather than the plan.
		 *
		 * The 3D view takes the same canvas, and the same drag that walks around a room would
		 * otherwise also be dragging a row across the floor of it. Left as a flag on the editor
		 * rather than as unbound listeners, because the room is switched on and off all afternoon
		 * and listeners that are removed and re-added drift out of step with the ones that are not.
		 */
		this.canvas.addEventListener( 'pointerdown', function ( event ) {
			if ( self.suspended ) {
				return;
			}

			self.canvas.setPointerCapture( event.pointerId );
			self.onPointerDown( event, self.toWorld( event.clientX, event.clientY ) );
		} );

		this.canvas.addEventListener( 'pointermove', function ( event ) {
			if ( self.suspended ) {
				return;
			}

			self.onPointerMove( event, self.toWorld( event.clientX, event.clientY ) );
		} );

		this.canvas.addEventListener( 'pointerup', function ( event ) {
			if ( self.suspended ) {
				return;
			}

			self.onPointerUp( event, self.toWorld( event.clientX, event.clientY ) );
		} );

		this.canvas.addEventListener( 'dblclick', function ( event ) {
			if ( self.suspended ) {
				return;
			}

			var point = self.toWorld( event.clientX, event.clientY );
			var object = self.objectAt( point );

			// Double-click is how you go into a section, and how you come back out of one.
			if ( object && 'section' === object.type && ! self.sectionKey ) {
				self.enterSection( object.key );
			} else if ( self.sectionKey && ! object ) {
				self.exitSection();
			}
		} );

		this.canvas.addEventListener(
			'wheel',
			function ( event ) {
				if ( self.suspended ) {
					return;
				}

				event.preventDefault();

				var before = self.toWorld( event.clientX, event.clientY );
				self.view.scale = Math.min( 12, Math.max( 0.1, self.view.scale * ( event.deltaY < 0 ? 1.1 : 0.9 ) ) );

				// Keep the point under the cursor pinned, which is what makes wheel-zoom feel like
				// a map rather than a slider.
				var after = self.toWorld( event.clientX, event.clientY );
				self.view.x += ( after.x - before.x ) * self.view.scale;
				self.view.y += ( after.y - before.y ) * self.view.scale;

				self.draw();
			},
			{ passive: false }
		);
	};

	Editor.prototype.onPointerDown = function ( event, point ) {
		if ( 'pan' === this.tool || 1 === event.button || this.spaceHeld ) {
			this.drag = { mode: 'pan', lastX: event.clientX, lastY: event.clientY };

			return;
		}

		if ( 'focalPoint' === this.tool ) {
			var self = this;
			this.mutate( function ( chart ) {
				Chart.setFocalPoint( chart, point.x, point.y );
			} );
			this.setTool( 'select' );

			return;
		}

		if ( DRAW_TOOLS.indexOf( this.tool ) !== -1 ) {
			this.startDraft( point );

			return;
		}

		if ( 'lasso' === this.tool ) {
			this.lasso = [ [ point.x, point.y ] ];

			return;
		}

		this.startSelection( event, point );
	};

	var DRAW_TOOLS = [ 'row', 'curvedRow', 'section', 'area', 'booth', 'shape', 'line', 'table', 'text', 'icon', 'image' ];

	Editor.prototype.startSelection = function ( event, point ) {
		var seatHit = this.seatAt( point );
		var objectHit = this.objectAt( point );

		if ( 'sameType' === this.tool && objectHit ) {
			this.selectSameType( objectHit.type, event.shiftKey );

			return;
		}

		// Inside a section, clicking lands on individual chairs; at chart level it lands on whole
		// objects, because that is the level the designer is working at.
		if ( seatHit && this.sectionKey ) {
			var seatKey = seatHit.object.key + '/' + seatHit.seat.key;

			if ( event.shiftKey ) {
				this.toggleSeat( seatKey );
			} else if ( this.seatSelection.indexOf( seatKey ) === -1 ) {
				this.seatSelection = [ seatKey ];
				this.selection = [];
			}

			this.drag = { mode: 'move', lastX: point.x, lastY: point.y, moved: false };
			this.onSelectionChange();
			this.draw();

			return;
		}

		if ( objectHit ) {
			if ( event.shiftKey ) {
				this.toggleObject( objectHit.key );
			} else if ( this.selection.indexOf( objectHit.key ) === -1 ) {
				this.selection = [ objectHit.key ];
				this.seatSelection = [];
			}

			this.drag = { mode: 'move', lastX: point.x, lastY: point.y, moved: false };
			this.onSelectionChange();
			this.draw();

			return;
		}

		if ( ! event.shiftKey ) {
			this.selection = [];
			this.seatSelection = [];
			this.onSelectionChange();
		}

		this.marquee = { x1: point.x, y1: point.y, x2: point.x, y2: point.y };
		this.draw();
	};

	Editor.prototype.onPointerMove = function ( event, point ) {
		if ( this.drag && 'pan' === this.drag.mode ) {
			this.view.x += event.clientX - this.drag.lastX;
			this.view.y += event.clientY - this.drag.lastY;
			this.drag.lastX = event.clientX;
			this.drag.lastY = event.clientY;
			this.draw();

			return;
		}

		if ( this.drag && 'move' === this.drag.mode ) {
			if ( ! this.drag.moved ) {
				// One history entry per drag, not one per pointermove — otherwise reversing a
				// single drag would take thirty undos.
				this.history.push( this.chart );
				this.drag.moved = true;
			}

			this.moveSelection( point.x - this.drag.lastX, point.y - this.drag.lastY );
			this.drag.lastX = point.x;
			this.drag.lastY = point.y;
			this.draw();

			return;
		}

		if ( this.draft && 'polygon' !== this.draft.kind ) {
			this.draft.x2 = point.x;
			this.draft.y2 = point.y;
			this.draw();

			return;
		}

		if ( this.lasso ) {
			this.lasso.push( [ point.x, point.y ] );
			this.draw();

			return;
		}

		if ( this.marquee ) {
			this.marquee.x2 = point.x;
			this.marquee.y2 = point.y;
			this.draw();
		}
	};

	Editor.prototype.onPointerUp = function ( event, point ) {
		if ( this.drag && 'move' === this.drag.mode && this.drag.moved ) {
			if ( this.snapToGrid ) {
				this.snapSelection();
			}

			this.onChange( this.chart );
		}

		if ( this.draft && 'polygon' !== this.draft.kind ) {
			this.commitDraft();
		}

		if ( this.lasso ) {
			this.selectInPolygon( this.lasso );
			this.lasso = null;
		}

		if ( this.marquee ) {
			var box = normalise( this.marquee );

			this.selectInPolygon( [
				[ box.x, box.y ],
				[ box.x + box.width, box.y ],
				[ box.x + box.width, box.y + box.height ],
				[ box.x, box.y + box.height ],
			] );

			this.marquee = null;
		}

		this.drag = null;
		this.draw();
	};

	Editor.prototype.moveSelection = function ( dx, dy ) {
		var objects = this.selectedObjects();

		if ( objects.length ) {
			objects.forEach( function ( object ) {
				Ops.move( object, dx, dy );
			} );

			return;
		}

		// Dragging chairs moves the row they belong to: a seat has no position of its own, since
		// where it sits follows from the row's anchor, rotation and spacing.
		var rows = {};

		this.selectedSeats().forEach( function ( entry ) {
			rows[ entry.owner.key ] = entry.owner;
		} );

		Object.keys( rows ).forEach( function ( key ) {
			Ops.move( rows[ key ], dx, dy );
		} );
	};

	Editor.prototype.snapSelection = function () {
		var self = this;

		this.selectedObjects().forEach( function ( object ) {
			if ( 'row' === object.type || 'table' === object.type || 'text' === object.type || 'icon' === object.type ) {
				object.x = Chart.snap( object.x, self.grid );
				object.y = Chart.snap( object.y, self.grid );
			}
		} );
	};

	Editor.prototype.toggleObject = function ( key ) {
		var index = this.selection.indexOf( key );

		index === -1 ? this.selection.push( key ) : this.selection.splice( index, 1 );
		this.onSelectionChange();
	};

	Editor.prototype.toggleSeat = function ( key ) {
		var index = this.seatSelection.indexOf( key );

		index === -1 ? this.seatSelection.push( key ) : this.seatSelection.splice( index, 1 );
		this.onSelectionChange();
	};

	/** Select every object of one kind — the "select same type" tool. */
	Editor.prototype.selectSameType = function ( type, additive ) {
		var container = this.container();
		var keys = ( container.objects || [] )
			.filter( function ( object ) { return object.type === type; } )
			.map( function ( object ) { return object.key; } );

		this.selection = additive ? this.selection.concat( keys ) : keys;
		this.seatSelection = [];
		this.onSelectionChange();
		this.draw();
	};

	Editor.prototype.selectInPolygon = function ( polygon ) {
		var self = this;
		var container = this.container();

		if ( this.sectionKey ) {
			// Inside a section the natural unit of selection is the chair.
			( container.objects || [] ).forEach( function ( object ) {
				if ( 'row' !== object.type && 'table' !== object.type ) {
					return;
				}

				var positions = 'row' === object.type
					? Chart.rowSeatPositions( object )
					: Chart.tableSeatPositions( object );

				positions.forEach( function ( position, index ) {
					if ( ! Ops.pointInPolygon( position, polygon ) ) {
						return;
					}

					var key = object.key + '/' + object.seats[ index ].key;

					if ( self.seatSelection.indexOf( key ) === -1 ) {
						self.seatSelection.push( key );
					}
				} );
			} );

			this.onSelectionChange();

			return;
		}

		( container.objects || [] ).forEach( function ( object ) {
			if ( 'all' !== self.layer && ( object.layer || 'interactive' ) !== self.layer ) {
				return;
			}

			var center = Ops.center( object );

			if ( Ops.pointInPolygon( center, polygon ) && self.selection.indexOf( object.key ) === -1 ) {
				self.selection.push( object.key );
			}
		} );

		this.onSelectionChange();
	};

	Editor.prototype.selectAll = function () {
		var container = this.container();
		var self = this;

		this.selection = ( container.objects || [] )
			.filter( function ( object ) {
				return 'all' === self.layer || ( object.layer || 'interactive' ) === self.layer;
			} )
			.map( function ( object ) { return object.key; } );

		this.seatSelection = [];
		this.onSelectionChange();
		this.draw();
	};

	Editor.prototype.clearSelection = function () {
		this.selection = [];
		this.seatSelection = [];
		this.onSelectionChange();
		this.draw();
	};

	/* ------------------------------------------------------------------------- drawing tools */

	Editor.prototype.startDraft = function ( point ) {
		if ( 'section' === this.tool || 'line' === this.tool ) {
			if ( this.draft && 'polygon' === this.draft.kind ) {
				this.draft.points.push( [ point.x, point.y ] );
			} else {
				this.draft = { kind: 'polygon', points: [ [ point.x, point.y ] ] };
			}

			this.draw();

			return;
		}

		if ( 'table' === this.tool || 'text' === this.tool || 'icon' === this.tool ) {
			this.commitPointTool( point );

			return;
		}

		this.draft = { kind: 'box', x1: point.x, y1: point.y, x2: point.x, y2: point.y };
	};

	Editor.prototype.commitPointTool = function ( point ) {
		var self = this;
		var container = this.container();

		this.mutate( function ( chart ) {
			var object;

			if ( 'table' === self.tool ) {
				object = Chart.newTable( chart, 'T' + ( countOfType( container, 'table' ) + 1 ), {
					x: Chart.snap( point.x, self.grid ),
					y: Chart.snap( point.y, self.grid ),
				} );
			} else if ( 'text' === self.tool ) {
				var text = window.prompt( 'Text', 'Label' );

				if ( ! text ) {
					return;
				}

				object = Chart.newText( chart, text, { x: point.x, y: point.y } );
			} else {
				object = Chart.newIcon( chart, self.iconName || 'wheelchair', { x: point.x, y: point.y } );
			}

			container.objects.push( object );
			self.selection = [ object.key ];
		} );

		this.setTool( 'select' );
		this.onSelectionChange();
	};

	/** Close a polygon being drawn point by point. */
	Editor.prototype.finishPolygon = function () {
		if ( ! this.draft || 'polygon' !== this.draft.kind || this.draft.points.length < 2 ) {
			this.draft = null;
			this.draw();

			return;
		}

		var self = this;
		var points = this.draft.points;
		var container = this.container();

		this.mutate( function ( chart ) {
			var object;

			if ( 'section' === self.tool ) {
				if ( points.length < 3 ) {
					return;
				}

				var label = window.prompt(
					t( 'panel.prompt.sectionName' ),
					t( 'panel.prompt.sectionDefault', { number: countOfType( container, 'section' ) + 1 } )
				);

				if ( ! label ) {
					return;
				}

				object = Chart.newSection( chart, label, points );
			} else {
				object = Chart.newShape( chart, 'line', { points: points, layer: 'background' } );
			}

			container.objects.push( object );
			self.selection = [ object.key ];
		} );

		this.draft = null;
		this.setTool( 'select' );
		this.onSelectionChange();
	};

	Editor.prototype.commitDraft = function () {
		var box = normalise( this.draft );
		// Keep the raw drag before discarding it: normalising loses the direction, and a row needs
		// to know which way it was drawn to set its rotation.
		var stroke = this.draft;
		var self = this;
		var container = this.container();

		this.draft = null;

		if ( box.width < 8 && box.height < 8 ) {
			this.draw();

			return; // A stray click, not a drawn object.
		}

		this.mutate( function ( chart ) {
			var object;

			switch ( self.tool ) {
				case 'row':
				case 'curvedRow':
					object = self.rowFromStroke( chart, stroke );
					break;

				case 'area':
					object = Chart.newArea( chart, 'Area ' + ( countOfType( container, 'area' ) + 1 ), {
						x: box.x, y: box.y, width: box.width, height: box.height,
						places: Math.max( 1, Math.round( ( box.width * box.height ) / 900 ) ),
					} );
					break;

				case 'booth':
					object = Chart.newBooth( chart, 'Booth ' + ( countOfType( container, 'booth' ) + 1 ), {
						x: box.x, y: box.y, width: box.width, height: box.height,
					} );
					break;

				case 'image':
					var href = window.prompt( 'Image URL' );

					if ( ! href ) {
						return;
					}

					object = Chart.newImage( chart, href, {
						x: box.x, y: box.y, width: box.width, height: box.height,
					} );
					break;

				default:
					object = Chart.newShape( chart, self.shapeKind || 'rect', {
						x: box.x, y: box.y, width: box.width, height: box.height,
						label: self.shapeKind === 'stage' ? 'Stage' : null,
					} );
			}

			if ( object ) {
				container.objects.push( object );
				self.selection = [ object.key ];
			}
		} );

		this.setTool( 'select' );
		this.onSelectionChange();
	};

	/**
	 * Turn a drag into a row.
	 *
	 * The stroke gives the row's direction and length, and the seat count follows from how many fit
	 * at the current spacing — so drawing a row feels like drawing a line, not filling in a form.
	 */
	Editor.prototype.rowFromStroke = function ( chart, stroke ) {
		var dx = stroke.x2 - stroke.x1;
		var dy = stroke.y2 - stroke.y1;
		var length = Math.hypot( dx, dy );
		var spacing = 4;
		var count = Math.max( 1, Math.round( length / ( Chart.SEAT_SIZE + spacing ) ) + 1 );
		var container = this.container();

		return Chart.newRow( chart, {
			x: ( stroke.x1 + stroke.x2 ) / 2,
			y: ( stroke.y1 + stroke.y2 ) / 2,
			rotation: ( Math.atan2( dy, dx ) * 180 ) / Math.PI,
			curve: 'curvedRow' === this.tool ? 15 : 0,
			seatSpacing: spacing,
			seats: count,
			label: Chart.indexToLetters( countOfType( container, 'row' ) ),
		} );
	};

	function countOfType( container, type ) {
		return ( container.objects || [] ).filter( function ( object ) {
			return object.type === type;
		} ).length;
	}

	/* ------------------------------------------------------------------------- keyboard */

	Editor.prototype.bindKeyboard = function () {
		var self = this;

		window.addEventListener( 'keydown', function ( event ) {
			// Never hijack typing in the property panel.
			if ( /^(INPUT|TEXTAREA|SELECT)$/.test( event.target.tagName ) ) {
				return;
			}

			if ( ' ' === event.key ) {
				self.spaceHeld = true;
				self.canvas.style.cursor = 'grab';
			}

			var meta = event.ctrlKey || event.metaKey;
			var key = event.key.toLowerCase();

			if ( meta && 'z' === key ) {
				event.preventDefault();
				event.shiftKey ? self.redo() : self.undo();

				return;
			}

			if ( meta && 'y' === key ) {
				event.preventDefault();
				self.redo();

				return;
			}

			if ( meta && 'a' === key ) {
				event.preventDefault();
				self.selectAll();

				return;
			}

			if ( meta && 'd' === key ) {
				event.preventDefault();
				// Ctrl+D deselects, matching the hint in the status bar.
				self.clearSelection();

				return;
			}

			if ( meta && 'c' === key ) {
				self.copy();

				return;
			}

			if ( meta && 'v' === key ) {
				event.preventDefault();
				self.paste();

				return;
			}

			if ( 'Enter' === event.key && self.draft ) {
				event.preventDefault();
				self.finishPolygon();

				return;
			}

			if ( 'Delete' === event.key || 'Backspace' === event.key ) {
				event.preventDefault();
				self.deleteSelection();

				return;
			}

			if ( 'Escape' === event.key ) {
				if ( self.draft ) {
					self.draft = null;
					self.draw();
				} else if ( self.selection.length || self.seatSelection.length ) {
					self.clearSelection();
				} else if ( self.sectionKey ) {
					self.exitSection();
				}

				return;
			}

			var nudges = {
				ArrowLeft: [ -1, 0 ], ArrowRight: [ 1, 0 ], ArrowUp: [ 0, -1 ], ArrowDown: [ 0, 1 ],
			};

			if ( nudges[ event.key ] && ( self.selection.length || self.seatSelection.length ) ) {
				event.preventDefault();

				// Shift nudges a whole grid cell; a bare arrow moves one unit for fine work.
				var step = event.shiftKey ? self.grid : 1;

				self.mutate( function () {
					self.moveSelection( nudges[ event.key ][ 0 ] * step, nudges[ event.key ][ 1 ] * step );
				} );
			}
		} );

		window.addEventListener( 'keyup', function ( event ) {
			if ( ' ' === event.key ) {
				self.spaceHeld = false;
				self.canvas.style.cursor = 'select' === self.tool ? 'default' : 'crosshair';
			}
		} );
	};

	Editor.prototype.copy = function () {
		this.clipboard = JSON.parse( JSON.stringify( this.selectedObjects() ) );
	};

	Editor.prototype.paste = function () {
		if ( ! this.clipboard || ! this.clipboard.length ) {
			return;
		}

		var self = this;
		var container = this.container();

		this.mutate( function ( chart ) {
			// Offset by a couple of grid cells so the copy is visibly distinct from its source.
			var copies = Ops.duplicate( chart, self.clipboard, self.grid * 2, self.grid * 2 );

			copies.forEach( function ( copy ) {
				container.objects.push( copy );
			} );

			self.selection = copies.map( function ( copy ) { return copy.key; } );
			self.seatSelection = [];
		} );

		this.onSelectionChange();
	};

	Editor.prototype.duplicateSelection = function () {
		this.copy();
		this.paste();
	};

	Editor.prototype.deleteSelection = function () {
		var self = this;

		if ( this.selection.length ) {
			this.mutate( function () {
				Ops.remove( self.container(), self.selection );
				self.selection = [];
			} );

			this.onSelectionChange();

			return;
		}

		if ( this.seatSelection.length ) {
			this.mutate( function () {
				self.selectedSeats().forEach( function ( entry ) {
					entry.owner.seats = entry.owner.seats.filter( function ( seat ) {
						return seat.key !== entry.seat.key;
					} );
				} );

				self.seatSelection = [];
			} );

			this.onSelectionChange();
		}
	};

	Editor.prototype.mirrorSelection = function ( axis ) {
		var self = this;

		this.mutate( function () {
			Ops.mirror( self.selectedObjects(), axis );
		} );
	};

	Editor.prototype.alignSelection = function ( edge ) {
		var self = this;

		this.mutate( function () {
			Ops.align( self.selectedObjects(), edge );
		} );
	};

	Editor.prototype.distributeSelection = function ( axis ) {
		var self = this;

		this.mutate( function () {
			Ops.distribute( self.selectedObjects(), axis );
		} );
	};

	/* ------------------------------------------------------------------------------ view */

	Editor.prototype.zoomBy = function ( factor ) {
		this.view.scale = Math.min( 12, Math.max( 0.1, this.view.scale * factor ) );
		this.draw();
	};

	Editor.prototype.zoomToFit = function () {
		var rect = this.canvas.getBoundingClientRect();
		var target = this.sectionKey ? Ops.bounds( this.container() ) : null;

		var box = target || {
			x: 0, y: 0,
			width: this.floor().canvas.width,
			height: this.floor().canvas.height,
		};

		var padding = 60;
		var scale = Math.min(
			( rect.width - padding * 2 ) / Math.max( 1, box.width ),
			( rect.height - padding * 2 ) / Math.max( 1, box.height )
		);

		this.view.scale = Math.min( 12, Math.max( 0.1, scale ) );
		this.view.x = padding - box.x * this.view.scale + ( rect.width - padding * 2 - box.width * this.view.scale ) / 2;
		this.view.y = padding - box.y * this.view.scale + ( rect.height - padding * 2 - box.height * this.view.scale ) / 2;

		this.draw();
	};

	/* ----------------------------------------------------------------------------- helpers */

	function normalise( box ) {
		return {
			x: Math.min( box.x1, box.x2 ),
			y: Math.min( box.y1, box.y2 ),
			width: Math.abs( box.x2 - box.x1 ),
			height: Math.abs( box.y2 - box.y1 ),
		};
	}

	function shapeColour( kind, colors ) {
		return colors.shapes[ kind ] || colors.shapes.fallback;
	}

	function withAlpha( color, alpha ) {
		var rgb = toRgb( color );

		return 'rgba(' + rgb.join( ',' ) + ',' + alpha + ')';
	}

	function shade( color, amount ) {
		var rgb = toRgb( color ).map( function ( channel ) {
			return Math.max( 0, Math.min( 255, Math.round( channel + 255 * amount ) ) );
		} );

		return 'rgb(' + rgb.join( ',' ) + ')';
	}

	function toRgb( color ) {
		var hex = String( color || '#2d6cdf' ).replace( '#', '' );

		if ( 3 === hex.length ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}

		var value = parseInt( hex, 16 );

		if ( isNaN( value ) ) {
			return [ 45, 108, 223 ];
		}

		return [ ( value >> 16 ) & 255, ( value >> 8 ) & 255, value & 255 ];
	}

	global.SeatmapEditor = Editor;
	global.SeatmapHistory = History;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { Editor: Editor, History: History };
	}
} )( typeof window !== 'undefined' ? window : globalThis );
