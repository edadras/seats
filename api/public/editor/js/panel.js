/**
 * Tenant panel and designer shell.
 *
 * Plain DOM rather than a framework: the panel is a handful of screens around one genuinely
 * interesting component, and a build step would add more to maintain than it removes.
 */
( function () {
	'use strict';

	var Chart = window.SeatmapChart;
	var Ops = window.SeatmapChartOps;

	var App = {
		api: null,
		token: null,
		root: null,
		map: null,
		editor: null,
		inspector: null,
		readOnly: false,
	};

	App.init = function () {
		this.root = document.getElementById( 'app' );
		this.api = this.root.dataset.api;
		this.token = window.sessionStorage.getItem( 'seatmap_token' );

		this.token ? this.showWorkspace() : this.showLogin();
	};

	/* ------------------------------------------------------------------------- transport */

	App.request = function ( method, path, body ) {
		var headers = { Accept: 'application/json' };

		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		if ( this.token ) {
			headers.Authorization = 'Bearer ' + this.token;
		}

		return fetch( this.api + path, {
			method: method,
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( ( data.error && data.error.message ) || 'Request failed' );
					error.code = data.error && data.error.code;
					error.details = data.error && data.error.details;

					throw error;
				}

				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------------ auth shell */

	App.showLogin = function () {
		var self = this;

		this.root.innerHTML =
			'<div class="auth"><h1>Seatmap</h1><form id="login">' +
			'<label>Email<input type="email" name="email" required autocomplete="username"></label>' +
			'<label>Password<input type="password" name="password" required autocomplete="current-password"></label>' +
			'<button type="submit">Sign in</button><p class="error" role="alert"></p>' +
			'</form></div>';

		var form = document.getElementById( 'login' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( form );

			self.request( 'POST', '/auth/login', {
				email: data.get( 'email' ),
				password: data.get( 'password' ),
				device_name: 'panel',
			} )
				.then( function ( response ) {
					self.token = response.token;
					// sessionStorage, not localStorage: the token dies with the tab rather than
					// lingering on a shared machine.
					window.sessionStorage.setItem( 'seatmap_token', response.token );
					self.showWorkspace();
				} )
				.catch( function ( error ) {
					form.querySelector( '.error' ).textContent = error.message;
				} );
		} );
	};

	App.showWorkspace = function () {
		var self = this;

		this.root.innerHTML =
			'<header class="topbar"><strong>Seatmap</strong>' +
			'<nav><button data-view="events">Events</button><button data-view="maps">Seat maps</button>' +
			'<button data-view="venues">Venues</button><button data-view="connections">Connections</button></nav>' +
			'<button id="signout" class="ghost">Sign out</button></header><main id="view"></main>';

		this.root.querySelectorAll( 'nav button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { self.route( button.dataset.view ); } );
		} );

		document.getElementById( 'signout' ).addEventListener( 'click', function () {
			window.sessionStorage.removeItem( 'seatmap_token' );
			self.token = null;
			self.showLogin();
		} );

		this.route( 'events' );
	};

	App.route = function ( view ) {
		switch ( view ) {
			case 'venues': return this.renderVenues();
			case 'maps': return this.renderMaps();
			case 'connections': return this.renderConnections();
			default: return this.renderEvents();
		}
	};

	App.view = function () { return document.getElementById( 'view' ); };

	App.escape = function ( value ) {
		var node = document.createElement( 'div' );
		node.textContent = String( value == null ? '' : value );

		return node.innerHTML;
	};

	App.error = function ( error ) {
		this.view().innerHTML = '<p class="error" role="alert">' + this.escape( error.message ) + '</p>';
	};

	/* --------------------------------------------------------------------------- listings */

	App.renderVenues = function () {
		var self = this;

		this.request( 'GET', '/venues' )
			.then( function ( response ) {
				var rows = response.data.map( function ( venue ) {
					return '<tr><td>' + self.escape( venue.name ) + '</td><td>' +
						self.escape( venue.city || '' ) + '</td><td>' + self.escape( venue.timezone ) + '</td></tr>';
				} ).join( '' );

				self.view().innerHTML =
					'<h2>Venues</h2><form id="new-venue" class="inline">' +
					'<input name="name" placeholder="Venue name" required>' +
					'<input name="city" placeholder="City"><button type="submit">Add venue</button></form>' +
					'<table><thead><tr><th>Name</th><th>City</th><th>Time zone</th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="3">No venues yet.</td></tr>' ) + '</tbody></table>';

				document.getElementById( 'new-venue' ).addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var data = new FormData( event.target );

					self.request( 'POST', '/venues', { name: data.get( 'name' ), city: data.get( 'city' ) || null } )
						.then( function () { self.renderVenues(); } )
						.catch( function ( error ) { self.error( error ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.renderMaps = function () {
		var self = this;

		Promise.all( [ this.request( 'GET', '/seat-maps' ), this.request( 'GET', '/venues' ) ] )
			.then( function ( results ) {
				var rows = results[ 0 ].data.map( function ( map ) {
					var published = map.published_version
						? 'v' + map.published_version.version + ' · ' + map.published_version.seat_count + ' places'
						: 'not published';

					return '<tr><td>' + self.escape( map.name ) + '</td><td>' + self.escape( published ) +
						'</td><td><button data-map="' + self.escape( map.id ) + '">Open designer</button></td></tr>';
				} ).join( '' );

				var venueOptions = results[ 1 ].data.map( function ( venue ) {
					return '<option value="' + self.escape( venue.id ) + '">' + self.escape( venue.name ) + '</option>';
				} ).join( '' );

				self.view().innerHTML =
					'<h2>Seat maps</h2><form id="new-map" class="inline">' +
					'<select name="venue_id" required>' + venueOptions + '</select>' +
					'<input name="name" placeholder="Map name" required><button type="submit">Create map</button></form>' +
					'<table><thead><tr><th>Name</th><th>Published</th><th></th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="3">No maps yet.</td></tr>' ) + '</tbody></table>';

				document.getElementById( 'new-map' ).addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var data = new FormData( event.target );

					self.request( 'POST', '/seat-maps', { venue_id: data.get( 'venue_id' ), name: data.get( 'name' ) } )
						.then( function () { self.renderMaps(); } )
						.catch( function ( error ) { self.error( error ); } );
				} );

				self.view().querySelectorAll( '[data-map]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.openDesigner( button.dataset.map ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.renderEvents = function () {
		var self = this;

		this.request( 'GET', '/events' )
			.then( function ( response ) {
				var rows = response.data.map( function ( event ) {
					return '<tr><td>' + self.escape( event.name ) + '</td><td>' +
						self.escape( new Date( event.starts_at ).toLocaleString() ) + '</td><td>' +
						self.escape( event.status ) + '</td><td><code>' + self.escape( event.public_id ) +
						'</code></td><td><button data-stats="' + self.escape( event.id ) + '">Stats</button></td></tr>';
				} ).join( '' );

				self.view().innerHTML =
					'<h2>Events</h2><p class="hint">Paste an event’s public ID into WordPress as ' +
					'<code>[seatmap_event id="evt_…"]</code>, or into the Seat map block.</p>' +
					'<table><thead><tr><th>Name</th><th>Starts</th><th>Status</th><th>Public ID</th><th></th></tr></thead>' +
					'<tbody>' + ( rows || '<tr><td colspan="5">No events yet.</td></tr>' ) + '</tbody></table><div id="stats"></div>';

				self.view().querySelectorAll( '[data-stats]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { self.renderStats( button.dataset.stats ); } );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.renderStats = function ( eventId ) {
		this.request( 'GET', '/events/' + eventId + '/stats' ).then( function ( stats ) {
			document.getElementById( 'stats' ).innerHTML =
				'<h3>Inventory</h3><ul class="stats">' +
				'<li><span>' + stats.seats_total + '</span>places</li>' +
				'<li><span>' + stats.available + '</span>available</li>' +
				'<li><span>' + stats.held + '</span>held</li>' +
				'<li><span>' + stats.allocated + '</span>sold</li>' +
				'<li><span>' + stats.blocked + '</span>blocked</li>' +
				'<li><span>' + stats.checked_in + '</span>checked in</li></ul>' +
				'<p class="hint">Gross figures come from price snapshots and are indicative. ' +
				'Your shop’s books are the authority.</p>';
		} );
	};

	App.renderConnections = function () {
		var self = this;

		this.request( 'GET', '/api-clients' )
			.then( function ( response ) {
				var rows = response.data.map( function ( client ) {
					var keys = client.keys.map( function ( key ) {
						return '<code>' + self.escape( key.key_id ) + '</code> …' + self.escape( key.secret_hint || '' );
					} ).join( '<br>' );

					return '<tr><td>' + self.escape( client.name ) + '</td><td>' + self.escape( client.site_url || '' ) +
						'</td><td>' + keys + '</td><td><button data-rotate="' + self.escape( client.id ) +
						'">Rotate key</button></td></tr>';
				} ).join( '' );

				self.view().innerHTML =
					'<h2>Connected sites</h2><form id="new-client" class="inline">' +
					'<input name="name" placeholder="Site name" required>' +
					'<input name="site_url" placeholder="https://example.com" type="url">' +
					'<button type="submit">Connect a site</button></form>' +
					'<table><thead><tr><th>Name</th><th>URL</th><th>Keys</th><th></th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="4">No sites connected yet.</td></tr>' ) +
					'</tbody></table><div id="credentials"></div>';

				document.getElementById( 'new-client' ).addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var data = new FormData( event.target );

					self.request( 'POST', '/api-clients', {
						name: data.get( 'name' ),
						site_url: data.get( 'site_url' ) || null,
					} )
						.then( function ( client ) { self.showCredentials( client.credentials ); } )
						.catch( function ( error ) { self.error( error ); } );
				} );

				self.view().querySelectorAll( '[data-rotate]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.request( 'POST', '/api-clients/' + button.dataset.rotate + '/keys', {} )
							.then( function ( credentials ) { self.showCredentials( credentials ); } );
					} );
				} );
			} )
			.catch( function ( error ) { self.error( error ); } );
	};

	App.showCredentials = function ( credentials ) {
		document.getElementById( 'credentials' ).innerHTML =
			'<div class="credentials"><h3>Copy these into the WordPress plugin now</h3>' +
			'<p><strong>Key ID</strong> <code>' + this.escape( credentials.key_id ) + '</code></p>' +
			'<p><strong>Secret</strong> <code>' + this.escape( credentials.secret ) + '</code></p>' +
			'<p class="warn">This is the only time the secret is shown. If you lose it, rotate the key — ' +
			'the old one keeps working until you revoke it, so your site stays up.</p></div>';
	};

	/* --------------------------------------------------------------------------- designer */

	App.openDesigner = function ( mapId ) {
		var self = this;

		this.request( 'GET', '/seat-maps/' + mapId ).then( function ( map ) {
			self.map = map;

			var version = map.draft_version || map.published_version;
			// A published v1 map opens through the converter, so charts drawn before the model
			// changed still load rather than showing an empty canvas.
			var chart = version ? Ops.migrate( version.geometry ) : Chart.empty( map.name );

			// Editing the published version directly is not possible — publishing forks a draft —
			// so make that state visible rather than letting someone edit and wonder why.
			self.readOnly = ! map.draft_version && !! map.published_version;

			self.view().innerHTML = self.designerMarkup( map );
			self.mountDesigner( chart );
		} );
	};

	App.designerMarkup = function ( map ) {
		return '' +
		'<div class="designer">' +
			'<div class="designer__bar">' +
				'<button class="dz-icon" id="dz-close" title="Back to seat maps">✕</button>' +
				'<span class="dz-name">' + this.escape( map.name ) + '</span>' +
				'<span class="dz-badge" id="dz-readonly" hidden>◯ Read only</span>' +
				'<button class="dz-icon" id="dz-preview" title="Preview as a buyer sees it">👁</button>' +
				'<button class="dz-icon" id="dz-theme" title="Light or dark">☀</button>' +
				'<span class="dz-sep"></span>' +
				'<button class="dz-icon" id="dz-undo" title="Undo (Ctrl+Z)">↶</button>' +
				'<button class="dz-icon" id="dz-redo" title="Redo (Ctrl+Shift+Z)">↷</button>' +
				'<span class="dz-sep"></span>' +
				'<button class="dz-icon" id="dz-focal" title="Set the focal point">☀</button>' +
				'<button class="dz-icon" id="dz-labels" title="Show or hide labels">🏷</button>' +
				'<span class="dz-sep"></span>' +
				'<button class="dz-icon" id="dz-lock" title="Lock the chart against edits">🔓</button>' +
				'<button class="dz-icon" id="dz-mirror-h" title="Mirror horizontally">⇋</button>' +
				'<button class="dz-icon" id="dz-mirror-v" title="Mirror vertically">⇵</button>' +
				'<span class="dz-sep"></span>' +
				'<button class="dz-icon" id="dz-add-floor" title="Add a floor">⊞</button>' +
				'<button class="dz-icon" id="dz-duplicate" title="Duplicate (Ctrl+D on a selection)">⧉</button>' +
				'<button class="dz-icon" id="dz-copy" title="Copy (Ctrl+C)">⧉</button>' +
				'<button class="dz-icon" id="dz-delete" title="Delete (Del)">🗑</button>' +
				'<span class="dz-spacer"></span>' +
				'<button class="dz-icon" id="dz-help" title="Keyboard shortcuts">?</button>' +
				'<button class="dz-primary" id="dz-save">Save draft</button>' +
				'<button class="dz-primary" id="dz-publish">Publish</button>' +
			'</div>' +
			'<div class="designer__body">' +
				'<div class="designer__tools" id="dz-tools"></div>' +
				'<div class="designer__stage">' +
					'<div class="dz-layers" id="dz-layers"></div>' +
					'<button class="dz-exit" id="dz-exit" hidden>↩ Exit section</button>' +
					'<div class="dz-canvas"><canvas id="dz-canvas"></canvas></div>' +
					'<div class="dz-zoom">' +
						'<button id="dz-zoom-out" aria-label="Zoom out">−</button>' +
						'<button id="dz-zoom-fit" aria-label="Fit to view">◎</button>' +
						'<button id="dz-zoom-in" aria-label="Zoom in">+</button>' +
					'</div>' +
					'<div class="dz-floors" id="dz-floors"></div>' +
				'</div>' +
				'<aside class="designer__inspector" id="dz-inspector"></aside>' +
			'</div>' +
			'<div class="designer__status"><span id="dz-status"></span><span id="dz-selection"></span></div>' +
		'</div>';
	};

	/**
	 * The tool palette.
	 *
	 * Grouped the way the work goes: pick things, then place seating, then place everything that is
	 * not seating, then move the view.
	 */
	App.TOOLS = [
		{ key: 'select', icon: '⌖', label: 'Select' },
		{ key: 'lasso', icon: '◌', label: 'Lasso select' },
		{ key: 'sameType', icon: '⁘', label: 'Select same type' },
		{ separator: true },
		{ key: 'row', icon: '⋯', label: 'Straight row' },
		{ key: 'curvedRow', icon: '⌒', label: 'Curved row' },
		{ key: 'section', icon: '⬠', label: 'Section' },
		{ key: 'table', icon: '◍', label: 'Table' },
		{ key: 'booth', icon: '▤', label: 'Booth' },
		{ key: 'area', icon: '▭', label: 'General admission area' },
		{ separator: true },
		{ key: 'shape', icon: '◻', label: 'Shape' },
		{ key: 'line', icon: '╱', label: 'Line' },
		{ key: 'text', icon: 'A', label: 'Text' },
		{ key: 'image', icon: '🖼', label: 'Image' },
		{ key: 'icon', icon: '♿', label: 'Icon' },
		{ separator: true },
		{ key: 'pan', icon: '✋', label: 'Pan' },
	];

	App.mountDesigner = function ( chart ) {
		var self = this;

		var editor = new window.SeatmapEditor( document.getElementById( 'dz-canvas' ), {
			chart: chart,
			onChange: function () { self.refreshDesigner(); },
			onSelectionChange: function () { self.refreshInspector(); self.refreshStatus(); },
			onContextChange: function () { self.refreshDesigner(); },
			onStatus: function ( message ) { document.getElementById( 'dz-status' ).textContent = message; },
		} ).init();

		this.editor = editor;
		editor.locked = this.readOnly;

		this.inspector = new window.SeatmapInspector(
			document.getElementById( 'dz-inspector' ),
			editor,
			{ onManageCategories: function () { self.manageCategories(); } }
		);

		// Exposed for the browser smoke test, and genuinely useful in the console when diagnosing
		// a chart a customer has sent in.
		window.__editor = editor;

		this.bindDesigner();
		this.renderTools();
		editor.zoomToFit();
		this.refreshDesigner();
		editor.setTool( 'select' );

		window.addEventListener( 'resize', function () { editor.resize(); } );
	};

	App.renderTools = function () {
		var self = this;
		var host = document.getElementById( 'dz-tools' );

		host.innerHTML = '';

		this.TOOLS.forEach( function ( tool ) {
			if ( tool.separator ) {
				host.appendChild( node( 'div', 'dz-tool-sep' ) );

				return;
			}

			var button = node( 'button', 'dz-tool', tool.icon );
			button.title = tool.label;
			button.dataset.tool = tool.key;
			button.setAttribute( 'aria-label', tool.label );

			button.addEventListener( 'click', function () {
				self.editor.setTool( tool.key );
				self.renderTools();
			} );

			if ( self.editor.tool === tool.key ) {
				button.classList.add( 'is-active' );
			}

			host.appendChild( button );
		} );
	};

	App.bindDesigner = function () {
		var self = this;
		var editor = this.editor;

		function on( id, handler ) {
			var element = document.getElementById( id );

			if ( element ) {
				element.addEventListener( 'click', handler );
			}
		}

		on( 'dz-close', function () { self.renderMaps(); } );
		on( 'dz-undo', function () { editor.undo(); } );
		on( 'dz-redo', function () { editor.redo(); } );
		on( 'dz-zoom-in', function () { editor.zoomBy( 1.25 ); } );
		on( 'dz-zoom-out', function () { editor.zoomBy( 0.8 ); } );
		on( 'dz-zoom-fit', function () { editor.zoomToFit(); } );
		on( 'dz-exit', function () { editor.exitSection(); } );
		on( 'dz-mirror-h', function () { editor.mirrorSelection( 'horizontal' ); } );
		on( 'dz-mirror-v', function () { editor.mirrorSelection( 'vertical' ); } );
		on( 'dz-duplicate', function () { editor.duplicateSelection(); } );
		on( 'dz-copy', function () { editor.copy(); self.toast( 'Copied.' ); } );
		on( 'dz-delete', function () { editor.deleteSelection(); } );
		on( 'dz-focal', function () { editor.setTool( 'focalPoint' ); self.renderTools(); } );
		on( 'dz-save', function () { self.saveDraft(); } );
		on( 'dz-publish', function () { self.publish(); } );
		on( 'dz-add-floor', function () { self.addFloor(); } );
		on( 'dz-help', function () { self.showShortcuts(); } );

		on( 'dz-labels', function () {
			editor.showLabels = ! editor.showLabels;
			document.getElementById( 'dz-labels' ).classList.toggle( 'is-on', editor.showLabels );
			editor.draw();
		} );

		on( 'dz-lock', function () {
			editor.locked = ! editor.locked;
			self.refreshDesigner();
			self.toast( editor.locked ? 'Chart locked.' : 'Chart unlocked.' );
		} );

		on( 'dz-preview', function () {
			// Preview hides the designer's own furniture — grid, focal crosshair, selection — so
			// what is left is what a buyer would see.
			document.querySelector( '.designer' ).classList.toggle( 'is-preview' );
			editor.snapToGrid = ! document.querySelector( '.designer' ).classList.contains( 'is-preview' );
			editor.clearSelection();
			editor.draw();
		} );

		on( 'dz-theme', function () {
			document.body.classList.toggle( 'theme-dark' );
			editor.draw();
		} );
	};

	App.refreshDesigner = function () {
		this.refreshInspector();
		this.refreshStatus();
		this.refreshLayers();
		this.refreshFloors();

		var exit = document.getElementById( 'dz-exit' );

		if ( exit ) {
			exit.hidden = ! this.editor.sectionKey;
		}

		var badge = document.getElementById( 'dz-readonly' );

		if ( badge ) {
			badge.hidden = ! this.editor.locked;
		}

		var lock = document.getElementById( 'dz-lock' );

		if ( lock ) {
			lock.textContent = this.editor.locked ? '🔒' : '🔓';
		}
	};

	App.refreshInspector = function () {
		if ( this.inspector ) {
			this.inspector.render();
		}
	};

	App.refreshStatus = function () {
		var editor = this.editor;
		var target = document.getElementById( 'dz-selection' );

		if ( ! target ) {
			return;
		}

		if ( editor.seatSelection.length ) {
			target.textContent = editor.seatSelection.length + ' seat' + ( 1 === editor.seatSelection.length ? '' : 's' ) + ' selected';

			return;
		}

		if ( ! editor.selection.length ) {
			target.textContent = '';

			return;
		}

		var children = 0;

		editor.selectedObjects().forEach( function ( object ) {
			children += ( object.seats || object.objects || [] ).length;
		} );

		target.textContent =
			editor.selection.length + ' object' + ( 1 === editor.selection.length ? '' : 's' ) + ' selected' +
			( children ? ' (' + children + ' children)' : '' );
	};

	/**
	 * The selection-layer list.
	 *
	 * Restricting selection to one layer is what makes a busy chart workable: you can drag the
	 * scenery around without disturbing a single seat, and vice versa.
	 */
	App.refreshLayers = function () {
		var self = this;
		var host = document.getElementById( 'dz-layers' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';
		host.appendChild( node( 'h4', 'dz-layers__title', 'SELECTION LAYER' ) );

		var floor = this.editor.floor();
		var counts = { all: 0 };

		Chart.LAYERS.forEach( function ( layer ) { counts[ layer ] = 0; } );

		( floor.objects || [] ).forEach( function ( object ) {
			counts.all += 1;
			counts[ object.layer || 'interactive' ] += 1;
		} );

		[ 'all' ].concat( Chart.LAYERS.slice().reverse() ).forEach( function ( layer ) {
			var button = node( 'button', 'dz-layer', Chart.LAYER_LABELS[ layer ] );

			// A layer with nothing on it is shown but greyed, so the list stays a stable map of the
			// chart rather than appearing and disappearing as objects are added.
			if ( 'all' !== layer && ! counts[ layer ] ) {
				button.classList.add( 'is-empty' );
			}

			if ( self.editor.layer === layer ) {
				button.classList.add( 'is-active' );
				button.appendChild( node( 'span', 'dz-layer__tick', '✓' ) );
			}

			button.addEventListener( 'click', function () {
				self.editor.layer = layer;
				self.editor.clearSelection();
				self.refreshLayers();
			} );

			host.appendChild( button );
		} );
	};

	App.refreshFloors = function () {
		var self = this;
		var host = document.getElementById( 'dz-floors' );

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';

		var gear = node( 'button', 'dz-floor dz-floor--gear', '⚙' );
		gear.title = 'Rename or remove this floor';
		gear.addEventListener( 'click', function () { self.editFloor(); } );
		host.appendChild( gear );

		this.editor.chart.floors.slice().reverse().forEach( function ( floor ) {
			var button = node( 'button', 'dz-floor', floor.key );
			button.title = floor.name;

			if ( self.editor.floorKey === floor.key ) {
				button.classList.add( 'is-active' );
			}

			button.addEventListener( 'click', function () { self.editor.setFloor( floor.key ); } );
			host.appendChild( button );
		} );
	};

	App.addFloor = function () {
		var self = this;
		var name = window.prompt( 'Floor name', 'Level ' + ( this.editor.chart.floors.length + 1 ) );

		if ( ! name ) {
			return;
		}

		this.editor.mutate( function ( chart ) {
			var key = String( chart.floors.length + 1 );

			while ( chart.floors.some( function ( floor ) { return floor.key === key; } ) ) {
				key += '\''; // A removed-then-re-added floor must not collide with a surviving one.
			}

			chart.floors.push( Chart.newFloor( key, name ) );
			self.editor.floorKey = key;
		} );

		this.refreshDesigner();
	};

	App.editFloor = function () {
		var self = this;
		var floor = this.editor.floor();
		var name = window.prompt( 'Floor name (clear it to remove this floor)', floor.name );

		if ( null === name ) {
			return;
		}

		this.editor.mutate( function ( chart ) {
			if ( '' === name.trim() ) {
				if ( chart.floors.length < 2 ) {
					self.toast( 'A chart needs at least one floor.', true );

					return;
				}

				chart.floors = chart.floors.filter( function ( entry ) { return entry.key !== floor.key; } );
				self.editor.floorKey = chart.floors[ 0 ].key;

				return;
			}

			floor.name = name;
		} );

		this.refreshDesigner();
	};

	/* ------------------------------------------------------------------------- categories */

	App.manageCategories = function () {
		var self = this;
		var chart = this.editor.chart;
		var host = document.createElement( 'div' );

		host.className = 'dz-modal';
		host.innerHTML = '<div class="dz-modal__body"><h3>Categories</h3><div id="dz-cats"></div>' +
			'<form id="dz-cat-form" class="inline"><input name="label" placeholder="Category name" required>' +
			'<input name="color" type="color" value="#2d6cdf"><label class="dz-check">' +
			'<input name="accessible" type="checkbox"> Accessible</label>' +
			'<button type="submit">Add</button></form>' +
			'<p class="hint">A category is a price tier. Every bookable object needs one before the ' +
			'chart can be priced for an event.</p>' +
			'<button class="dz-primary" id="dz-cat-close">Done</button></div>';

		document.body.appendChild( host );

		function paint() {
			var list = host.querySelector( '#dz-cats' );
			list.innerHTML = '';

			( chart.categories || [] ).forEach( function ( category ) {
				var line = node( 'div', 'dz-cat' );
				var dot = node( 'span', 'insp-dot' );
				dot.style.background = category.color;
				line.appendChild( dot );
				line.appendChild( node( 'span', 'dz-cat__label', category.label + ( category.accessible ? ' ♿' : '' ) ) );

				var remove = node( 'button', 'insp-link', 'Remove' );
				remove.addEventListener( 'click', function () {
					self.editor.mutate( function () { Chart.removeCategory( chart, category.key ); } );
					paint();
				} );

				line.appendChild( remove );
				list.appendChild( line );
			} );
		}

		paint();

		host.querySelector( '#dz-cat-form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = new FormData( event.target );

			self.editor.mutate( function () {
				Chart.addCategory( chart, data.get( 'label' ), data.get( 'color' ), !! data.get( 'accessible' ) );
			} );

			event.target.reset();
			paint();
		} );

		host.querySelector( '#dz-cat-close' ).addEventListener( 'click', function () {
			host.remove();
			self.refreshDesigner();
		} );
	};

	App.showShortcuts = function () {
		var host = document.createElement( 'div' );

		host.className = 'dz-modal';
		host.innerHTML = '<div class="dz-modal__body"><h3>Shortcuts</h3><dl class="dz-keys">' +
			'<dt>Ctrl/⌘ + Z</dt><dd>Undo</dd>' +
			'<dt>Ctrl/⌘ + Shift + Z</dt><dd>Redo</dd>' +
			'<dt>Ctrl/⌘ + A</dt><dd>Select everything in the current layer</dd>' +
			'<dt>Ctrl/⌘ + D</dt><dd>Deselect</dd>' +
			'<dt>Ctrl/⌘ + C / V</dt><dd>Copy and paste</dd>' +
			'<dt>Shift + Click</dt><dd>Add to or remove from the selection</dd>' +
			'<dt>Arrows</dt><dd>Nudge by one unit — hold Shift for a grid step</dd>' +
			'<dt>Space + drag</dt><dd>Pan</dd>' +
			'<dt>Enter</dt><dd>Close the section or line being drawn</dd>' +
			'<dt>Double-click</dt><dd>Go into a section, or back out of it</dd>' +
			'<dt>Delete</dt><dd>Remove the selection</dd>' +
			'</dl><button class="dz-primary" id="dz-keys-close">Close</button></div>';

		document.body.appendChild( host );
		host.querySelector( '#dz-keys-close' ).addEventListener( 'click', function () { host.remove(); } );
	};

	/* ------------------------------------------------------------------------ persistence */

	App.saveDraft = function () {
		var self = this;

		return this.request( 'POST', '/seat-maps/' + this.map.id + '/versions', { geometry: this.editor.chart } )
			.then( function ( version ) {
				// Saving a draft forks one from the published version, so the chart stops being
				// read-only the moment there is something to edit.
				self.readOnly = false;
				self.editor.locked = false;
				self.map.draft_version = version;
				self.refreshDesigner();
				self.toast( 'Draft saved.' );

				return version;
			} )
			.catch( function ( error ) {
				self.toast( error.message, true );

				throw error;
			} );
	};

	/**
	 * Publishing saves first, so what goes live is exactly what is on screen. Publishing a stale
	 * server-side draft is the kind of surprise that costs a venue a night's sales.
	 */
	App.publish = function () {
		var self = this;

		this.saveDraft()
			.then( function () {
				return self.request( 'POST', '/seat-maps/' + self.map.id + '/publish', {} );
			} )
			.then( function ( version ) {
				self.toast( 'Published version ' + version.version + ' with ' + version.seat_count + ' places.' );
			} )
			.catch( function ( error ) {
				if ( ! error || ! error.message ) {
					return;
				}

				var detail = '';

				if ( error.details && error.details.errors ) {
					detail = ' ' + error.details.errors.map( function ( issue ) { return issue.message; } ).join( ' ' );
				}

				self.toast( error.message + detail, true );
			} );
	};

	App.toast = function ( message, isError ) {
		var existing = document.querySelector( '.toast' );

		if ( existing ) {
			existing.remove();
		}

		var toast = document.createElement( 'div' );
		toast.className = 'toast' + ( isError ? ' toast--error' : '' );
		toast.setAttribute( 'role', 'status' );
		toast.textContent = message;
		document.body.appendChild( toast );

		window.setTimeout( function () { toast.remove(); }, 6000 );
	};

	function node( tag, className, text ) {
		var element = document.createElement( tag );

		if ( className ) {
			element.className = className;
		}

		if ( text != null ) {
			element.textContent = text;
		}

		return element;
	}

	document.addEventListener( 'DOMContentLoaded', function () { App.init(); } );
} )();
