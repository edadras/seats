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

	var SEAT_RADIUS = 9;
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
		this.geometry = config.geometry || { canvas: { width: 1000, height: 800 }, sections: [] };
		this.seats = [];
		this.seatsById = {};
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
		this.fetchAvailability();
		this.startPolling();
	};

	/** Flatten the nested geometry once; everything else indexes into this. */
	SeatmapWidget.prototype.flattenSeats = function () {
		var self = this;

		( this.geometry.sections || [] ).forEach( function ( section ) {
			( section.rows || [] ).forEach( function ( row ) {
				( row.seats || [] ).forEach( function ( seat ) {
					var record = {
						key: section.key + '/' + row.key + '/' + seat.key,
						// Published inside the immutable geometry, so geometry and availability are
						// joined on a stable id rather than on array position.
						id: seat.seat_id || null,
						section: section.name,
						sectionKey: section.key,
						row: row.name,
						label: seat.label,
						x: seat.x,
						y: seat.y,
						shape: seat.shape || 'circle',
						zoneKey: seat.zone_key || null,
						accessible: !! seat.accessible,
						state: 'available',
						amount: null,
					};

					self.seats.push( record );

					if ( record.id ) {
						self.seatsById[ record.id ] = record;
					}
				} );
			} );
		} );
	};

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
				if ( ! data || ! data.seats ) {
					return;
				}

				// An unchanged cursor comes back with an empty list; leave the map alone.
				if ( data.seats.length ) {
					self.applyAvailability( data.seats );
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

	SeatmapWidget.prototype.startPolling = function () {
		var self = this;

		this.pollTimer = window.setInterval( function () {
			if ( ! document.hidden ) {
				self.fetchAvailability();
			}
		}, POLL_INTERVAL );
	};

	SeatmapWidget.prototype.resize = function () {
		var width = this.container.clientWidth || 800;
		var geometryWidth = this.geometry.canvas.width || 1000;
		var geometryHeight = this.geometry.canvas.height || 800;
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

		this.paintShapes( ctx );
		this.paintSeats( ctx, scale );
		this.paintTexts( ctx );

		ctx.restore();
	};

	SeatmapWidget.prototype.paintShapes = function ( ctx ) {
		var self = this;

		( this.geometry.shapes || [] ).forEach( function ( shape ) {
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

		this.seats.forEach( function ( seat ) {
			ctx.beginPath();
			ctx.fillStyle = self.seatColour( seat );
			ctx.strokeStyle = 'selected' === seat.state ? '#12263f' : 'rgba(0,0,0,0.2)';
			ctx.lineWidth = 'selected' === seat.state ? 2.5 / scale : 1 / scale;

			if ( 'square' === seat.shape ) {
				ctx.rect( seat.x - SEAT_RADIUS, seat.y - SEAT_RADIUS, SEAT_RADIUS * 2, SEAT_RADIUS * 2 );
			} else {
				ctx.arc( seat.x, seat.y, SEAT_RADIUS, 0, Math.PI * 2 );
			}

			ctx.fill();
			ctx.stroke();
		} );
	};

	SeatmapWidget.prototype.paintTexts = function ( ctx ) {
		( this.geometry.texts || [] ).forEach( function ( text ) {
			ctx.save();
			ctx.fillStyle = text.color || '#3a3f4b';
			ctx.font = '500 ' + ( text.size || 14 ) + 'px system-ui, sans-serif';
			ctx.fillText( text.text, text.x, text.y );
			ctx.restore();
		} );
	};

	SeatmapWidget.prototype.seatColour = function ( seat ) {
		if ( 'selected' === seat.state ) {
			return '#12263f';
		}

		if ( 'available' !== seat.state ) {
			return '#d3d6dc';
		}

		var zones = this.config.event.zones || [];

		for ( var i = 0; i < zones.length; i++ ) {
			if ( zones[ i ].key === seat.zoneKey ) {
				return zones[ i ].color || '#2d6cdf';
			}
		}

		return '#2d6cdf';
	};

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

	SeatmapWidget.prototype.renderSeatList = function () {
		if ( ! this.seatListEl ) {
			return;
		}

		var self = this;
		var active = document.activeElement;
		var activeKey = active && active.dataset ? active.dataset.seatKey : null;

		this.seatListEl.innerHTML = '';

		( this.geometry.sections || [] ).forEach( function ( section ) {
			var group = document.createElement( 'div' );
			group.className = 'seatmap-widget__section';

			var title = document.createElement( 'h4' );
			title.textContent = section.name;
			group.appendChild( title );

			( section.rows || [] ).forEach( function ( row ) {
				var rowEl = document.createElement( 'div' );
				rowEl.className = 'seatmap-widget__row';

				var rowLabel = document.createElement( 'span' );
				rowLabel.className = 'seatmap-widget__row-label';
				rowLabel.textContent = row.name;
				rowEl.appendChild( rowLabel );

				( row.seats || [] ).forEach( function ( geometrySeat ) {
					var seat = self.findSeat( section.key, row.key, geometrySeat.key );

					if ( ! seat ) {
						return;
					}

					rowEl.appendChild( self.buildSeatButton( seat ) );
				} );

				group.appendChild( rowEl );
			} );

			self.seatListEl.appendChild( group );
		} );

		if ( activeKey ) {
			var restored = this.seatListEl.querySelector( '[data-seat-key="' + activeKey + '"]' );

			// Repainting the list must not throw the keyboard user back to the top of the page.
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
				.replace( '%1$s', seat.section )
				.replace( '%2$s', seat.row )
				.replace( '%3$s', seat.label )
				.replace( '%4$s', null === seat.amount ? '' : this.formatMoney( seat.amount ) )
		);

		if ( seat.accessible ) {
			button.classList.add( 'is-accessible' );
		}

		button.addEventListener( 'click', function () {
			self.toggleSeat( seat );
		} );

		return button;
	};

	SeatmapWidget.prototype.findSeat = function ( sectionKey, rowKey, seatKey ) {
		var composite = sectionKey + '/' + rowKey + '/' + seatKey;

		for ( var i = 0; i < this.seats.length; i++ ) {
			if ( this.seats[ i ].key === composite ) {
				return this.seats[ i ];
			}
		}

		return null;
	};

	SeatmapWidget.prototype.renderSelection = function () {
		var self = this;

		this.selectionEl.innerHTML = '';

		if ( ! this.selected.length ) {
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
			item.textContent = seat.section + ' · ' + seat.row + ' · ' + seat.label + ' — ' + self.formatMoney( seat.amount );
			this.selectionEl.appendChild( item );
		}, this );

		this.totalEl.textContent = this.i18n.total + ': ' + this.formatMoney( total );
		this.submitEl.disabled = false;
	};

	SeatmapWidget.prototype.reserve = function () {
		if ( this.busy || ! this.selected.length ) {
			return;
		}

		this.busy = true;
		this.submitEl.disabled = true;
		this.submitEl.textContent = this.i18n.working;

		var self = this;
		var seatIds = this.selected.map( function ( seat ) {
			return seat.id;
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
		var self = this;

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
