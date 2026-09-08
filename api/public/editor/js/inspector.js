/**
 * The right-hand property panel.
 *
 * One module because the panel is a single idea: given what is selected, show the fields that
 * apply to it. Splitting it per object type would scatter that decision across a dozen files.
 *
 * Every field writes straight back into the chart and asks the editor to redraw, so typing 17 into
 * Number of seats rearranges the row as you type — which is only possible because seat positions
 * are computed rather than stored.
 */
( function ( global ) {
	'use strict';

	var Chart = global.SeatmapChart;

	function Inspector( root, editor, options ) {
		this.root = root;
		this.editor = editor;
		this.options = options || {};
		this.onManageCategories = this.options.onManageCategories || function () {};
	}

	Inspector.prototype.render = function () {
		var objects = this.editor.selectedObjects();
		var seats = this.editor.selectedSeats();

		this.root.innerHTML = '';

		if ( seats.length && ! objects.length ) {
			this.renderSeats( seats );

			return;
		}

		if ( ! objects.length ) {
			this.editor.sectionKey ? this.renderSection() : this.renderChart();

			return;
		}

		if ( objects.length > 1 ) {
			this.renderMultiple( objects );

			return;
		}

		var object = objects[ 0 ];

		switch ( object.type ) {
			case 'row': this.renderRow( object ); break;
			case 'section': this.renderSectionObject( object ); break;
			case 'area': this.renderArea( object, 'AREA' ); break;
			case 'booth': this.renderArea( object, 'BOOTH' ); break;
			case 'table': this.renderTable( object ); break;
			case 'text': this.renderText( object ); break;
			case 'shape': this.renderShape( object ); break;
			case 'image': this.renderImage( object ); break;
			case 'icon': this.renderIcon( object ); break;
			default: this.renderChart();
		}
	};

	/* --------------------------------------------------------------------- chart level */

	/** With nothing selected the panel is about the chart: categories, places, and the checklist. */
	Inspector.prototype.renderChart = function () {
		var chart = this.editor.chart;
		var report = Chart.validate( chart );
		var self = this;

		this.title( chart.name );

		var categories = this.section();
		var head = el( 'div', 'insp-row insp-row--head' );
		head.appendChild( el( 'span', 'insp-count', String( ( chart.categories || [] ).length ) + ' categories' ) );

		var manage = el( 'button', 'insp-link', '⚙ Manage' );
		manage.addEventListener( 'click', function () { self.onManageCategories(); } );
		head.appendChild( manage );
		categories.appendChild( head );

		( chart.categories || [] ).forEach( function ( category ) {
			var line = el( 'div', 'insp-category' );
			var dot = el( 'span', 'insp-dot' );
			dot.style.background = category.color;
			line.appendChild( dot );
			line.appendChild( el( 'span', '', category.label ) );

			if ( category.accessible ) {
				line.appendChild( el( 'span', 'insp-flag', '♿' ) );
			}

			categories.appendChild( line );
		} );

		var places = this.section();
		var placesHead = el( 'div', 'insp-row insp-row--head' );
		placesHead.appendChild( el( 'strong', 'insp-places', report.places.toLocaleString() + ' places' ) );
		places.appendChild( placesHead );

		// The checklist from the designer, each line answering a question that only bites once the
		// chart is being sold against.
		report.checks.forEach( function ( check ) {
			var line = el( 'div', 'insp-check ' + ( check.ok ? 'is-ok' : 'is-bad' ) );
			line.appendChild( el( 'span', 'insp-check__mark', check.ok ? '✓' : '✕' ) );
			line.appendChild( el( 'span', '', check.label ) );
			places.appendChild( line );
		} );

		if ( report.errors.length ) {
			var issues = this.section( 'Problems' );

			report.errors.slice( 0, 12 ).forEach( function ( issue ) {
				issues.appendChild( el( 'div', 'insp-issue insp-issue--error', issue.message ) );
			} );
		}

		if ( report.warnings.length ) {
			var warnings = this.section( 'Worth a look' );

			report.warnings.slice( 0, 12 ).forEach( function ( issue ) {
				warnings.appendChild( el( 'div', 'insp-issue insp-issue--warning', issue.message ) );
			} );
		}
	};

	/** Inside a section the panel narrows to that section's own contents. */
	Inspector.prototype.renderSection = function () {
		var section = this.editor.container();
		var chart = { version: 2, categories: this.editor.chart.categories, floors: [ { key: 's', canvas: this.editor.floor().canvas, objects: section.objects || [] } ] };
		var report = Chart.validate( chart );

		this.title( ( Chart.objectLabel( section ) || 'Section' ) + ' section' );

		var body = this.section();
		body.appendChild( el( 'strong', 'insp-places', report.places.toLocaleString() + ' places' ) );

		report.checks.slice( 0, 3 ).forEach( function ( check ) {
			var line = el( 'div', 'insp-check ' + ( check.ok ? 'is-ok' : 'is-bad' ) );
			line.appendChild( el( 'span', 'insp-check__mark', check.ok ? '✓' : '✕' ) );
			line.appendChild( el( 'span', '', check.label ) );
			body.appendChild( line );
		} );
	};

	/* -------------------------------------------------------------------------- objects */

	Inspector.prototype.renderRow = function ( row ) {
		var self = this;

		this.title( 'ROW' );
		this.categoryField( row );

		var geometry = this.section( 'Row' );

		this.number( geometry, 'Number of seats', row.seats.length, 0, 500, 1, function ( value ) {
			self.change( function () { Chart.setRowSeatCount( row, value ); } );
		} );

		this.number( geometry, 'Rotation', row.rotation, -360, 360, 1, function ( value ) {
			self.change( function () { row.rotation = value; } );
		}, '°' );

		// Curve is the sagitta as a percentage of the row's length, so the number means the same
		// thing whether the row has six seats or sixty.
		this.number( geometry, 'Curve', row.curve, -60, 60, 1, function ( value ) {
			self.change( function () { row.curve = value; } );
		} );

		this.number( geometry, 'Seat spacing', row.seatSpacing, 0, 60, 1, function ( value ) {
			self.change( function () { row.seatSpacing = value; } );
		}, 'pt' );

		var sectionLabeling = this.section( 'Section labeling' );
		var parent = this.editor.container();

		this.text( sectionLabeling, 'Section label', parent && parent.labeling ? parent.labeling.label : '', function ( value ) {
			if ( parent && parent.labeling ) {
				self.change( function () { parent.labeling.label = value; } );
			}
		}, ! parent || ! parent.labeling );

		this.rowLabelingFields( row );
		this.seatLabelingFields( row );

		var misc = this.section( 'Miscellaneous' );

		this.text( misc, 'Entrance', row.entrance || '', function ( value ) {
			self.change( function () { row.entrance = value || null; } );
		} );
	};

	/**
	 * Row labeling, with the lock the designer shows.
	 *
	 * A locked row takes its label from an automatic scheme; unlocking is a deliberate act, so that
	 * renaming one row does not quietly opt the whole section out of automatic numbering.
	 */
	Inspector.prototype.rowLabelingFields = function ( row ) {
		var self = this;
		var labeling = row.labeling || ( row.labeling = Chart.defaultRowLabeling( 'A' ) );
		var body = this.section( 'Row labeling', labeling.locked ? 'Unlock' : null, function () {
			self.change( function () { labeling.locked = false; } );
		} );

		this.checkbox( body, 'Enabled', labeling.enabled, function ( value ) {
			self.change( function () { labeling.enabled = value; } );
		}, labeling.locked );

		this.text( body, 'Label', labeling.label, function ( value ) {
			self.change( function () { labeling.label = value; } );
		}, labeling.locked );

		this.text( body, 'Displayed label', labeling.displayedLabel == null ? '' : labeling.displayedLabel, function ( value ) {
			// Empty means "show the label itself" rather than "show nothing".
			self.change( function () { labeling.displayedLabel = value === '' ? null : value; } );
		}, labeling.locked, labeling.label );

		this.rowLabelPosition( body, labeling );

		this.text( body, 'Displayed type', labeling.displayedType, function ( value ) {
			self.change( function () { labeling.displayedType = value; } );
		}, labeling.locked );
	};

	/**
	 * The end-labels control: two buttons showing the label, and a track between them for where it
	 * appears. It reads as a picture of the row rather than as a dropdown of words.
	 */
	Inspector.prototype.rowLabelPosition = function ( body, labeling ) {
		var self = this;
		var line = el( 'div', 'insp-field' );
		line.appendChild( el( 'label', '', 'Position' ) );

		var control = el( 'div', 'insp-position' );
		var start = el( 'button', 'insp-position__end', labeling.label || 'A' );
		var end = el( 'button', 'insp-position__end', labeling.label || 'A' );
		var track = el( 'div', 'insp-position__track' );

		function paint() {
			start.classList.toggle( 'is-on', 'both' === labeling.position || 'start' === labeling.position );
			end.classList.toggle( 'is-on', 'both' === labeling.position || 'end' === labeling.position );
		}

		function toggle( which ) {
			var hasStart = 'both' === labeling.position || 'start' === labeling.position;
			var hasEnd = 'both' === labeling.position || 'end' === labeling.position;

			if ( 'start' === which ) {
				hasStart = ! hasStart;
			} else {
				hasEnd = ! hasEnd;
			}

			labeling.position = hasStart && hasEnd ? 'both' : hasStart ? 'start' : hasEnd ? 'end' : 'none';

			self.change( function () {} );
			paint();
		}

		start.addEventListener( 'click', function () { toggle( 'start' ); } );
		end.addEventListener( 'click', function () { toggle( 'end' ); } );

		for ( var i = 0; i < 5; i++ ) {
			track.appendChild( el( 'span', 'insp-position__dot' ) );
		}

		control.appendChild( start );
		control.appendChild( track );
		control.appendChild( end );
		line.appendChild( control );
		body.appendChild( line );

		paint();
	};

	Inspector.prototype.seatLabelingFields = function ( owner ) {
		var self = this;
		var labeling = owner.seatLabeling || ( owner.seatLabeling = { scheme: 'numeric', displayedType: 'Seat', locked: false } );

		var body = this.section( 'Seat labeling', labeling.locked ? 'Unlock' : 'Clear', function () {
			self.change( function () {
				if ( labeling.locked ) {
					labeling.locked = false;
				} else {
					Chart.renumberRow( owner, 'numeric' );
				}
			} );
		} );

		var options = Object.keys( Chart.SEAT_LABEL_SCHEMES ).map( function ( key ) {
			return { value: key, label: Chart.SEAT_LABEL_SCHEMES[ key ].label };
		} );

		this.select( body, 'Labels', labeling.scheme, options, function ( value ) {
			self.change( function () { Chart.renumberRow( owner, value ); } );
		}, labeling.locked );

		this.text( body, 'Displayed type', labeling.displayedType, function ( value ) {
			self.change( function () { labeling.displayedType = value; } );
		}, labeling.locked );
	};

	Inspector.prototype.renderSeats = function ( seats ) {
		var self = this;

		this.title( 1 === seats.length ? 'SEAT' : seats.length + ' SEATS' );

		var first = seats[ 0 ].seat;

		this.categoryField( first, function ( key ) {
			seats.forEach( function ( entry ) { entry.seat.categoryKey = key; } );
		} );

		var body = this.section( 'Seat' );

		if ( 1 === seats.length ) {
			this.text( body, 'Label', first.label, function ( value ) {
				self.change( function () { first.label = value; } );
			} );
		}

		this.checkbox( body, 'Accessible', first.accessible, function ( value ) {
			self.change( function () {
				seats.forEach( function ( entry ) { entry.seat.accessible = value; } );
			} );
		} );

		// An "empty" seat holds a gap in the row — a pillar, a camera position, a wheelchair bay —
		// without shifting every seat after it.
		this.checkbox( body, 'Empty placeholder', 'empty' === first.type, function ( value ) {
			self.change( function () {
				seats.forEach( function ( entry ) { entry.seat.type = value ? 'empty' : 'seat'; } );
			} );
		} );

		var misc = this.section( 'Miscellaneous' );

		this.text( misc, 'Entrance', first.entrance || '', function ( value ) {
			self.change( function () {
				seats.forEach( function ( entry ) { entry.seat.entrance = value || null; } );
			} );
		} );
	};

	Inspector.prototype.renderSectionObject = function ( section ) {
		var self = this;

		this.title( 'SECTION' );
		this.categoryField( section );

		var body = this.section( 'Section' );

		this.text( body, 'Label', section.labeling.label, function ( value ) {
			self.change( function () {
				section.labeling.label = value;
				section.label = value;
			} );
		} );

		this.checkbox( body, 'Label visible', section.labeling.visible, function ( value ) {
			self.change( function () { section.labeling.visible = value; } );
		} );

		this.number( body, 'Font size', section.labeling.fontSize, 6, 120, 1, function ( value ) {
			self.change( function () { section.labeling.fontSize = value; } );
		}, 'pt' );

		var contents = this.section( 'Contents' );
		var counts = {};

		( section.objects || [] ).forEach( function ( object ) {
			counts[ object.type ] = ( counts[ object.type ] || 0 ) + 1;
		} );

		var seatCount = 0;
		( section.objects || [] ).forEach( function ( object ) {
			if ( 'row' === object.type ) {
				seatCount += object.seats.length;
			}
		} );

		contents.appendChild( el( 'div', 'insp-static', seatCount + ' seats in ' + ( counts.row || 0 ) + ' rows' ) );

		var open = el( 'button', 'insp-button', 'Edit seats in this section' );
		open.addEventListener( 'click', function () { self.editor.enterSection( section.key ); } );
		contents.appendChild( open );

		var misc = this.section( 'Miscellaneous' );

		this.text( misc, 'Entrance', section.entrance || '', function ( value ) {
			self.change( function () { section.entrance = value || null; } );
		} );
	};

	Inspector.prototype.renderArea = function ( area, title ) {
		var self = this;

		this.title( title );
		this.categoryField( area );

		var shape = this.section( 'Shape' );

		this.number( shape, 'Width', area.shape.width, 10, 20000, 1, function ( value ) {
			self.change( function () { area.shape.width = value; } );
		}, 'pt' );

		this.number( shape, 'Height', area.shape.height, 10, 20000, 1, function ( value ) {
			self.change( function () { area.shape.height = value; } );
		}, 'pt' );

		this.number( shape, 'Rotation', area.shape.rotation, -360, 360, 1, function ( value ) {
			self.change( function () { area.shape.rotation = value; } );
		}, '°' );

		this.number( shape, 'Corner radius', area.shape.cornerRadius, 0, 400, 1, function ( value ) {
			self.change( function () { area.shape.cornerRadius = value; } );
		}, 'pt' );

		this.checkbox( shape, 'Translucent', area.translucent, function ( value ) {
			self.change( function () { area.translucent = value; } );
		} );

		var transform = this.section( 'Transform' );

		this.slider( transform, 'Scale', area.scale == null ? 1 : area.scale, 0.2, 3, 0.05, function ( value ) {
			self.change( function () { area.scale = value; } );
		} );

		var labeling = area.labeling;
		var labelBody = this.section( 'Area labeling', labeling.locked ? 'Unlock' : null, function () {
			self.change( function () { labeling.locked = false; } );
		} );

		this.text( labelBody, 'Label', labeling.label, function ( value ) {
			self.change( function () { labeling.label = value; } );
		}, labeling.locked );

		this.text( labelBody, 'Displayed label', labeling.displayedLabel == null ? '' : labeling.displayedLabel, function ( value ) {
			self.change( function () { labeling.displayedLabel = value === '' ? null : value; } );
		}, labeling.locked, labeling.label );

		this.checkbox( labelBody, 'Visible', labeling.visible, function ( value ) {
			self.change( function () { labeling.visible = value; } );
		} );

		this.number( labelBody, 'Font size', labeling.fontSize, 6, 200, 1, function ( value ) {
			self.change( function () { labeling.fontSize = value; } );
		}, 'pt' );

		this.number( labelBody, 'Position X', labeling.positionX || 0, -50, 50, 1, function ( value ) {
			self.change( function () { labeling.positionX = value; } );
		}, '%' );

		this.number( labelBody, 'Position Y', labeling.positionY || 0, -50, 50, 1, function ( value ) {
			self.change( function () { labeling.positionY = value; } );
		}, '%' );

		this.capacityFields( area );
	};

	/**
	 * Capacity: how many people this object holds, and whether they choose a spot.
	 *
	 * General admission means nobody picks a seat — the buyer picks a quantity — so `places` is the
	 * entire inventory model for the object.
	 */
	Inspector.prototype.capacityFields = function ( object ) {
		var self = this;
		var capacity = object.capacity || ( object.capacity = { type: 'generalAdmission', places: 1 } );
		var body = this.section( 'Capacity' );

		this.select( body, 'Type', capacity.type, [
			{ value: 'generalAdmission', label: 'General Admission' },
			{ value: 'fixed', label: 'Fixed occupancy' },
		], function ( value ) {
			self.change( function () { capacity.type = value; } );
		} );

		body.appendChild( el(
			'p',
			'insp-hint',
			'generalAdmission' === capacity.type
				? 'Multiple users can select places in a general admission area.'
				: 'The whole object is sold once, to this many people.'
		) );

		this.number( body, 'Places', capacity.places, 1, 100000, 1, function ( value ) {
			self.change( function () { capacity.places = value; } );
		} );
	};

	Inspector.prototype.renderTable = function ( table ) {
		var self = this;

		this.title( 'TABLE' );
		this.categoryField( table );

		var body = this.section( 'Table' );

		this.text( body, 'Label', table.labeling.label, function ( value ) {
			self.change( function () {
				table.labeling.label = value;
				table.label = value;
			} );
		} );

		this.select( body, 'Shape', table.shape, [
			{ value: 'round', label: 'Round' },
			{ value: 'rectangular', label: 'Rectangular' },
		], function ( value ) {
			self.change( function () { table.shape = value; } );
		} );

		this.number( body, 'Number of seats', table.seats.length, 0, 40, 1, function ( value ) {
			self.change( function () { Chart.setRowSeatCount( table, value ); } );
		} );

		this.number( body, 'Width', table.width, 20, 1000, 1, function ( value ) {
			self.change( function () { table.width = value; } );
		}, 'pt' );

		this.number( body, 'Height', table.height, 20, 1000, 1, function ( value ) {
			self.change( function () { table.height = value; } );
		}, 'pt' );

		this.number( body, 'Rotation', table.rotation, -360, 360, 1, function ( value ) {
			self.change( function () { table.rotation = value; } );
		}, '°' );

		var booking = this.section( 'Booking' );

		this.select( booking, 'Book as', table.bookAs, [
			{ value: 'seat', label: 'Individual seats' },
			{ value: 'table', label: 'The whole table' },
		], function ( value ) {
			self.change( function () { table.bookAs = value; } );
		} );

		booking.appendChild( el(
			'p',
			'insp-hint',
			'table' === table.bookAs
				? 'One booking takes the table and every chair at it.'
				: 'Each chair is sold separately, like any other seat.'
		) );

		this.seatLabelingFields( table );
	};

	Inspector.prototype.renderText = function ( text ) {
		var self = this;

		this.title( 'TEXT' );

		var body = this.section( 'Text' );

		this.text( body, 'Content', text.text, function ( value ) {
			self.change( function () { text.text = value; } );
		} );

		this.number( body, 'Font size', text.fontSize, 6, 200, 1, function ( value ) {
			self.change( function () { text.fontSize = value; } );
		}, 'pt' );

		this.number( body, 'Rotation', text.rotation || 0, -360, 360, 1, function ( value ) {
			self.change( function () { text.rotation = value; } );
		}, '°' );

		this.color( body, 'Colour', text.color || '#3a3f4b', function ( value ) {
			self.change( function () { text.color = value; } );
		} );

		this.layerField( text );
	};

	Inspector.prototype.renderShape = function ( shape ) {
		var self = this;

		this.title( 'SHAPE' );

		var body = this.section( 'Shape' );

		this.select( body, 'Kind', shape.kind, [
			{ value: 'rect', label: 'Rectangle' },
			{ value: 'ellipse', label: 'Ellipse' },
			{ value: 'stage', label: 'Stage' },
			{ value: 'aisle', label: 'Aisle' },
			{ value: 'wall', label: 'Wall' },
			{ value: 'entrance', label: 'Entrance' },
			{ value: 'exit', label: 'Exit' },
		], function ( value ) {
			self.change( function () { shape.kind = value; } );
		} );

		this.text( body, 'Label', shape.label || '', function ( value ) {
			self.change( function () { shape.label = value || null; } );
		} );

		if ( ! shape.points ) {
			this.number( body, 'Width', shape.width, 1, 20000, 1, function ( value ) {
				self.change( function () { shape.width = value; } );
			}, 'pt' );

			this.number( body, 'Height', shape.height, 1, 20000, 1, function ( value ) {
				self.change( function () { shape.height = value; } );
			}, 'pt' );

			this.number( body, 'Corner radius', shape.cornerRadius || 0, 0, 400, 1, function ( value ) {
				self.change( function () { shape.cornerRadius = value; } );
			}, 'pt' );
		}

		this.number( body, 'Rotation', shape.rotation || 0, -360, 360, 1, function ( value ) {
			self.change( function () { shape.rotation = value; } );
		}, '°' );

		this.color( body, 'Fill', shape.fill || '#c8ccd4', function ( value ) {
			self.change( function () { shape.fill = value; } );
		} );

		this.layerField( shape );
	};

	Inspector.prototype.renderImage = function ( image ) {
		var self = this;

		this.title( 'IMAGE' );

		var body = this.section( 'Image' );

		this.number( body, 'Width', image.width, 10, 20000, 1, function ( value ) {
			self.change( function () { image.width = value; } );
		}, 'pt' );

		this.number( body, 'Height', image.height, 10, 20000, 1, function ( value ) {
			self.change( function () { image.height = value; } );
		}, 'pt' );

		this.slider( body, 'Opacity', image.opacity == null ? 1 : image.opacity, 0.1, 1, 0.05, function ( value ) {
			self.change( function () { image.opacity = value; } );
		} );

		body.appendChild( el( 'p', 'insp-hint', 'Trace over a scanned floor plan, then delete or hide the image.' ) );

		this.layerField( image );
	};

	Inspector.prototype.renderIcon = function ( icon ) {
		var self = this;

		this.title( 'ICON' );

		var body = this.section( 'Icon' );

		this.select( body, 'Symbol', icon.name, [
			{ value: 'wheelchair', label: 'Wheelchair' },
			{ value: 'toilets', label: 'Toilets' },
			{ value: 'bar', label: 'Bar' },
			{ value: 'food', label: 'Food' },
			{ value: 'entrance', label: 'Entrance' },
			{ value: 'exit', label: 'Exit' },
			{ value: 'stairs', label: 'Stairs' },
			{ value: 'lift', label: 'Lift' },
		], function ( value ) {
			self.change( function () { icon.name = value; } );
		} );

		this.number( body, 'Size', icon.size, 8, 120, 1, function ( value ) {
			self.change( function () { icon.size = value; } );
		}, 'pt' );

		this.layerField( icon );
	};

	Inspector.prototype.renderMultiple = function ( objects ) {
		var self = this;
		var types = {};

		objects.forEach( function ( object ) {
			types[ object.type ] = ( types[ object.type ] || 0 ) + 1;
		} );

		this.title( objects.length + ' OBJECTS' );

		var body = this.section( 'Selection' );

		Object.keys( types ).forEach( function ( type ) {
			body.appendChild( el( 'div', 'insp-static', types[ type ] + ' × ' + type ) );
		} );

		// Category is the one property worth setting across a mixed selection — it is how a whole
		// tier gets priced in one go.
		this.categoryField( { categoryKey: objects[ 0 ].categoryKey }, function ( key ) {
			objects.forEach( function ( object ) { object.categoryKey = key; } );
		} );

		var arrange = this.section( 'Arrange' );

		[
			[ 'Align left', function () { self.editor.alignSelection( 'left' ); } ],
			[ 'Align centre', function () { self.editor.alignSelection( 'center' ); } ],
			[ 'Align right', function () { self.editor.alignSelection( 'right' ); } ],
			[ 'Align top', function () { self.editor.alignSelection( 'top' ); } ],
			[ 'Align middle', function () { self.editor.alignSelection( 'middle' ); } ],
			[ 'Align bottom', function () { self.editor.alignSelection( 'bottom' ); } ],
			[ 'Distribute across', function () { self.editor.distributeSelection( 'x' ); } ],
			[ 'Distribute down', function () { self.editor.distributeSelection( 'y' ); } ],
		].forEach( function ( entry ) {
			var button = el( 'button', 'insp-button insp-button--small', entry[ 0 ] );
			button.addEventListener( 'click', entry[ 1 ] );
			arrange.appendChild( button );
		} );
	};

	/* --------------------------------------------------------------------- shared fields */

	Inspector.prototype.categoryField = function ( object, apply ) {
		var self = this;
		var body = this.section( 'Category', '⚙ Manage', function () { self.onManageCategories(); } );

		var options = [ { value: '', label: 'No category assigned' } ].concat(
			( this.editor.chart.categories || [] ).map( function ( category ) {
				return { value: category.key, label: category.label, color: category.color };
			} )
		);

		this.select( body, null, object.categoryKey || '', options, function ( value ) {
			self.change( function () {
				if ( apply ) {
					apply( value || null );
				} else {
					object.categoryKey = value || null;
				}
			} );
		} );
	};

	Inspector.prototype.layerField = function ( object ) {
		var self = this;
		var body = this.section( 'Layer' );

		this.select( body, null, object.layer || 'interactive', Chart.LAYERS.map( function ( layer ) {
			return { value: layer, label: Chart.LAYER_LABELS[ layer ] };
		} ), function ( value ) {
			self.change( function () { object.layer = value; } );
		} );
	};

	/* ------------------------------------------------------------------------- primitives */

	Inspector.prototype.title = function ( text ) {
		this.root.appendChild( el( 'h2', 'insp-title', text ) );
	};

	Inspector.prototype.section = function ( heading, actionLabel, onAction ) {
		var wrap = el( 'section', 'insp-section' );

		if ( heading ) {
			var head = el( 'div', 'insp-section__head' );
			head.appendChild( el( 'h3', '', heading ) );

			if ( actionLabel ) {
				var button = el( 'button', 'insp-link', actionLabel );
				button.addEventListener( 'click', onAction );
				head.appendChild( button );
			}

			wrap.appendChild( head );
		}

		this.root.appendChild( wrap );

		return wrap;
	};

	/**
	 * A stepper. The arrows are what make a fiddly value like rotation adjustable without typing,
	 * and holding one is how you sweep a row round to where it looks right.
	 */
	Inspector.prototype.number = function ( body, label, value, min, max, step, onChange, suffix ) {
		var field = el( 'div', 'insp-field' );
		field.appendChild( el( 'label', '', label ) );

		var control = el( 'div', 'insp-stepper' );
		var down = el( 'button', 'insp-stepper__arrow', '‹' );
		var input = document.createElement( 'input' );
		var up = el( 'button', 'insp-stepper__arrow', '›' );

		input.type = 'number';
		input.value = round( value );
		input.min = min;
		input.max = max;
		input.step = step;

		function commit( next ) {
			var clamped = Math.max( min, Math.min( max, next ) );

			input.value = round( clamped );
			onChange( clamped );
		}

		input.addEventListener( 'input', function () {
			var parsed = parseFloat( input.value );

			if ( ! isNaN( parsed ) ) {
				onChange( Math.max( min, Math.min( max, parsed ) ) );
			}
		} );

		down.addEventListener( 'click', function () { commit( ( parseFloat( input.value ) || 0 ) - step ); } );
		up.addEventListener( 'click', function () { commit( ( parseFloat( input.value ) || 0 ) + step ); } );

		control.appendChild( down );
		control.appendChild( input );

		if ( suffix ) {
			control.appendChild( el( 'span', 'insp-suffix', suffix ) );
		}

		control.appendChild( up );
		field.appendChild( control );
		body.appendChild( field );
	};

	Inspector.prototype.text = function ( body, label, value, onChange, disabled, placeholder ) {
		var field = el( 'div', 'insp-field' );

		if ( label ) {
			field.appendChild( el( 'label', '', label ) );
		}

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.value = value == null ? '' : value;
		input.disabled = !! disabled;

		if ( placeholder ) {
			input.placeholder = placeholder;
		}

		input.addEventListener( 'input', function () { onChange( input.value ); } );
		field.appendChild( input );
		body.appendChild( field );
	};

	Inspector.prototype.checkbox = function ( body, label, value, onChange, disabled ) {
		var field = el( 'div', 'insp-field insp-field--check' );
		field.appendChild( el( 'label', '', label ) );

		var input = document.createElement( 'input' );
		input.type = 'checkbox';
		input.checked = !! value;
		input.disabled = !! disabled;
		input.addEventListener( 'change', function () { onChange( input.checked ); } );

		field.appendChild( input );
		body.appendChild( field );
	};

	Inspector.prototype.select = function ( body, label, value, options, onChange, disabled ) {
		var field = el( 'div', 'insp-field' );

		if ( label ) {
			field.appendChild( el( 'label', '', label ) );
		}

		var input = document.createElement( 'select' );
		input.disabled = !! disabled;

		options.forEach( function ( option ) {
			var node = document.createElement( 'option' );
			node.value = option.value;
			node.textContent = option.label;
			node.selected = option.value === value;
			input.appendChild( node );
		} );

		input.addEventListener( 'change', function () { onChange( input.value ); } );
		field.appendChild( input );
		body.appendChild( field );
	};

	Inspector.prototype.slider = function ( body, label, value, min, max, step, onChange ) {
		var field = el( 'div', 'insp-field' );
		field.appendChild( el( 'label', '', label ) );

		var input = document.createElement( 'input' );
		input.type = 'range';
		input.min = min;
		input.max = max;
		input.step = step;
		input.value = value;
		input.addEventListener( 'input', function () { onChange( parseFloat( input.value ) ); } );

		field.appendChild( input );
		body.appendChild( field );
	};

	Inspector.prototype.color = function ( body, label, value, onChange ) {
		var field = el( 'div', 'insp-field' );
		field.appendChild( el( 'label', '', label ) );

		var input = document.createElement( 'input' );
		input.type = 'color';
		input.value = value;
		input.addEventListener( 'input', function () { onChange( input.value ); } );

		field.appendChild( input );
		body.appendChild( field );
	};

	/**
	 * Apply an edit and repaint, without rebuilding the panel.
	 *
	 * Re-rendering on every keystroke would take focus out of the field being typed in, which makes
	 * a number input unusable.
	 */
	Inspector.prototype.change = function ( callback ) {
		this.editor.mutate( callback );
	};

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text != null ) {
			node.textContent = text;
		}

		return node;
	}

	function round( value ) {
		return Math.round( Number( value ) * 100 ) / 100;
	}

	global.SeatmapInspector = Inspector;
} )( typeof window !== 'undefined' ? window : globalThis );
