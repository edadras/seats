/**
 * Tenant panel: sign in, manage venues, maps and events, and drive the editor.
 *
 * Plain DOM rather than a framework — the panel is a handful of screens around one genuinely
 * interesting component (the canvas editor), and a build step would add more to maintain than it
 * would remove.
 */
( function () {
	'use strict';

	var Geometry = window.SeatmapGeometry;

	var App = {
		api: null,
		token: null,
		root: null,
		state: { venues: [], maps: [], events: [], map: null, editor: null },
	};

	App.init = function () {
		this.root = document.getElementById( 'app' );
		this.api = this.root.dataset.api;
		this.token = window.sessionStorage.getItem( 'seatmap_token' );

		if ( this.token ) {
			this.showWorkspace();
		} else {
			this.showLogin();
		}
	};

	/* --------------------------------------------------------------------- transport */

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
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
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

	/* ------------------------------------------------------------------------ views */

	App.showLogin = function () {
		this.root.innerHTML =
			'<div class="auth">' +
			'<h1>Seatmap</h1>' +
			'<form id="login">' +
			'<label>Email<input type="email" name="email" required autocomplete="username"></label>' +
			'<label>Password<input type="password" name="password" required autocomplete="current-password"></label>' +
			'<button type="submit">Sign in</button>' +
			'<p class="error" role="alert"></p>' +
			'</form>' +
			'</div>';

		var self = this;
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
			'<header class="topbar">' +
			'<strong>Seatmap</strong>' +
			'<nav><button data-view="events">Events</button><button data-view="maps">Seat maps</button>' +
			'<button data-view="venues">Venues</button><button data-view="connections">Connections</button></nav>' +
			'<button id="signout" class="ghost">Sign out</button>' +
			'</header><main id="view"></main>';

		this.root.querySelectorAll( 'nav button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				self.route( button.dataset.view );
			} );
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
			case 'venues':
				return this.renderVenues();
			case 'maps':
				return this.renderMaps();
			case 'connections':
				return this.renderConnections();
			default:
				return this.renderEvents();
		}
	};

	App.view = function () {
		return document.getElementById( 'view' );
	};

	App.error = function ( error ) {
		this.view().innerHTML = '<p class="error" role="alert">' + this.escape( error.message ) + '</p>';
	};

	App.escape = function ( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value == null ? '' : value );

		return div.innerHTML;
	};

	App.renderVenues = function () {
		var self = this;

		this.request( 'GET', '/venues' )
			.then( function ( response ) {
				self.state.venues = response.data;

				var rows = response.data
					.map( function ( venue ) {
						return (
							'<tr><td>' + self.escape( venue.name ) + '</td><td>' +
							self.escape( venue.city || '' ) + '</td><td>' +
							self.escape( venue.timezone ) + '</td></tr>'
						);
					} )
					.join( '' );

				self.view().innerHTML =
					'<h2>Venues</h2>' +
					'<form id="new-venue" class="inline">' +
					'<input name="name" placeholder="Venue name" required>' +
					'<input name="city" placeholder="City">' +
					'<button type="submit">Add venue</button>' +
					'</form>' +
					'<table><thead><tr><th>Name</th><th>City</th><th>Time zone</th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="3">No venues yet.</td></tr>' ) +
					'</tbody></table>';

				document.getElementById( 'new-venue' ).addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var data = new FormData( event.target );

					self.request( 'POST', '/venues', {
						name: data.get( 'name' ),
						city: data.get( 'city' ) || null,
					} )
						.then( function () {
							self.renderVenues();
						} )
						.catch( function ( error ) {
							self.error( error );
						} );
				} );
			} )
			.catch( function ( error ) {
				self.error( error );
			} );
	};

	App.renderMaps = function () {
		var self = this;

		Promise.all( [ this.request( 'GET', '/seat-maps' ), this.request( 'GET', '/venues' ) ] )
			.then( function ( results ) {
				self.state.maps = results[ 0 ].data;
				self.state.venues = results[ 1 ].data;

				var rows = results[ 0 ].data
					.map( function ( map ) {
						var published = map.published_version
							? 'v' + map.published_version.version + ' · ' + map.published_version.seat_count + ' seats'
							: 'not published';

						return (
							'<tr><td>' + self.escape( map.name ) + '</td><td>' + self.escape( published ) +
							'</td><td><button data-map="' + self.escape( map.id ) + '">Open editor</button></td></tr>'
						);
					} )
					.join( '' );

				var venueOptions = results[ 1 ].data
					.map( function ( venue ) {
						return '<option value="' + self.escape( venue.id ) + '">' + self.escape( venue.name ) + '</option>';
					} )
					.join( '' );

				self.view().innerHTML =
					'<h2>Seat maps</h2>' +
					'<form id="new-map" class="inline">' +
					'<select name="venue_id" required>' + venueOptions + '</select>' +
					'<input name="name" placeholder="Map name" required>' +
					'<button type="submit">Create map</button>' +
					'</form>' +
					'<table><thead><tr><th>Name</th><th>Published</th><th></th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="3">No maps yet.</td></tr>' ) +
					'</tbody></table>';

				document.getElementById( 'new-map' ).addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var data = new FormData( event.target );

					self.request( 'POST', '/seat-maps', {
						venue_id: data.get( 'venue_id' ),
						name: data.get( 'name' ),
					} )
						.then( function () {
							self.renderMaps();
						} )
						.catch( function ( error ) {
							self.error( error );
						} );
				} );

				self.view().querySelectorAll( '[data-map]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.openEditor( button.dataset.map );
					} );
				} );
			} )
			.catch( function ( error ) {
				self.error( error );
			} );
	};

	App.renderEvents = function () {
		var self = this;

		this.request( 'GET', '/events' )
			.then( function ( response ) {
				var rows = response.data
					.map( function ( event ) {
						return (
							'<tr><td>' + self.escape( event.name ) + '</td>' +
							'<td>' + self.escape( new Date( event.starts_at ).toLocaleString() ) + '</td>' +
							'<td>' + self.escape( event.status ) + '</td>' +
							'<td><code>' + self.escape( event.public_id ) + '</code></td>' +
							'<td><button data-stats="' + self.escape( event.id ) + '">Stats</button></td></tr>'
						);
					} )
					.join( '' );

				self.view().innerHTML =
					'<h2>Events</h2>' +
					'<p class="hint">Paste an event’s public ID into your WordPress page as ' +
					'<code>[seatmap_event id="evt_…"]</code>, or into the Seat map block.</p>' +
					'<table><thead><tr><th>Name</th><th>Starts</th><th>Status</th><th>Public ID</th><th></th></tr></thead><tbody>' +
					( rows || '<tr><td colspan="5">No events yet.</td></tr>' ) +
					'</tbody></table><div id="stats"></div>';

				self.view().querySelectorAll( '[data-stats]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.renderStats( button.dataset.stats );
					} );
				} );
			} )
			.catch( function ( error ) {
				self.error( error );
			} );
	};

	App.renderStats = function ( eventId ) {
		var self = this;

		this.request( 'GET', '/events/' + eventId + '/stats' ).then( function ( stats ) {
			document.getElementById( 'stats' ).innerHTML =
				'<h3>Inventory</h3><ul class="stats">' +
				'<li><span>' + stats.seats_total + '</span>seats</li>' +
				'<li><span>' + stats.available + '</span>available</li>' +
				'<li><span>' + stats.held + '</span>held</li>' +
				'<li><span>' + stats.allocated + '</span>sold</li>' +
				'<li><span>' + stats.blocked + '</span>blocked</li>' +
				'<li><span>' + stats.checked_in + '</span>checked in</li>' +
				'</ul><p class="hint">Gross figures come from price snapshots and are indicative. ' +
				'Your shop’s books are the authority.</p>';
		} );
	};

	App.renderConnections = function () {
		var self = this;

		this.request( 'GET', '/api-clients' )
			.then( function ( response ) {
				var rows = response.data
					.map( function ( client ) {
						var keys = client.keys
							.map( function ( key ) {
								return '<code>' + self.escape( key.key_id ) + '</code> …' + self.escape( key.secret_hint || '' );
							} )
							.join( '<br>' );

						return (
							'<tr><td>' + self.escape( client.name ) + '</td><td>' +
							self.escape( client.site_url || '' ) + '</td><td>' + keys + '</td>' +
							'<td><button data-rotate="' + self.escape( client.id ) + '">Rotate key</button></td></tr>'
						);
					} )
					.join( '' );

				self.view().innerHTML =
					'<h2>Connected sites</h2>' +
					'<form id="new-client" class="inline">' +
					'<input name="name" placeholder="Site name" required>' +
					'<input name="site_url" placeholder="https://example.com" type="url">' +
					'<button type="submit">Connect a site</button>' +
					'</form>' +
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
						.then( function ( client ) {
							self.showCredentials( client.credentials );
						} )
						.catch( function ( error ) {
							self.error( error );
						} );
				} );

				self.view().querySelectorAll( '[data-rotate]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						self.request( 'POST', '/api-clients/' + button.dataset.rotate + '/keys', {} )
							.then( function ( credentials ) {
								self.showCredentials( credentials );
							} );
					} );
				} );
			} )
			.catch( function ( error ) {
				self.error( error );
			} );
	};

	/**
	 * Secrets are shown once and never again, so say so plainly rather than letting someone
	 * navigate away and assume they can come back for it.
	 */
	App.showCredentials = function ( credentials ) {
		document.getElementById( 'credentials' ).innerHTML =
			'<div class="credentials"><h3>Copy these into the WordPress plugin now</h3>' +
			'<p><strong>Key ID</strong> <code>' + this.escape( credentials.key_id ) + '</code></p>' +
			'<p><strong>Secret</strong> <code>' + this.escape( credentials.secret ) + '</code></p>' +
			'<p class="warn">This is the only time the secret is shown. If you lose it, rotate the key ' +
			'— the old one keeps working until you revoke it, so your site stays up.</p></div>';
	};

	/* ----------------------------------------------------------------------- editor */

	App.openEditor = function ( mapId ) {
		var self = this;

		this.request( 'GET', '/seat-maps/' + mapId ).then( function ( map ) {
			self.state.map = map;

			var version = map.draft_version || map.published_version;
			var geometry = version ? version.geometry : Geometry.empty();

			self.view().innerHTML =
				'<div class="editor">' +
				'<div class="editor__toolbar">' +
				'<button data-action="add-section">Add section</button>' +
				'<button data-action="add-rows">Straight rows</button>' +
				'<button data-action="add-curved">Curved rows</button>' +
				'<span class="sep"></span>' +
				'<button data-action="stage">Stage</button>' +
				'<button data-action="aisle">Aisle</button>' +
				'<button data-action="entrance">Entrance</button>' +
				'<button data-action="exit">Exit</button>' +
				'<button data-action="text">Text</button>' +
				'<span class="sep"></span>' +
				'<button data-action="align-x">Align vertically</button>' +
				'<button data-action="align-y">Align horizontally</button>' +
				'<button data-action="distribute-x">Distribute</button>' +
				'<button data-action="renumber">Renumber rows</button>' +
				'<span class="sep"></span>' +
				'<button data-action="undo">Undo</button>' +
				'<button data-action="redo">Redo</button>' +
				'<label class="toggle"><input type="checkbox" id="snap" checked> Snap to grid</label>' +
				'<button data-action="fit">Fit</button>' +
				'<span class="spacer"></span>' +
				'<button data-action="save" class="primary">Save draft</button>' +
				'<button data-action="publish" class="primary">Publish</button>' +
				'</div>' +
				'<div class="editor__canvas"><canvas id="editor-canvas"></canvas></div>' +
				'<aside class="editor__side"><h3>Status</h3><div id="editor-status"></div>' +
				'<h3>Validation</h3><div id="editor-validation"></div></aside>' +
				'</div>';

			var editor = new window.SeatmapEditor( document.getElementById( 'editor-canvas' ), {
				geometry: geometry,
				onChange: function () {
					self.refreshEditorStatus();
				},
				onSelectionChange: function () {
					self.refreshEditorStatus();
				},
			} ).init();

			self.state.editor = editor;
			// Exposed for the browser smoke test; harmless in production and useful in the console
			// when diagnosing a map a customer has sent in.
			window.__editor = editor;
			editor.zoomToFit();
			self.refreshEditorStatus();

			window.addEventListener( 'resize', function () {
				editor.resize();
			} );

			document.getElementById( 'snap' ).addEventListener( 'change', function ( event ) {
				editor.snapToGrid = event.target.checked;
				editor.draw();
			} );

			self.view().querySelectorAll( '[data-action]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					self.editorAction( button.dataset.action );
				} );
			} );
		} );
	};

	App.editorAction = function ( action ) {
		var editor = this.state.editor;
		var self = this;

		switch ( action ) {
			case 'add-section':
				var name = window.prompt( 'Section name', 'Stalls' );

				if ( name ) {
					editor.mutate( function ( geometry ) {
						Geometry.addSection( geometry, name );
					} );
				}

				break;

			case 'add-rows':
			case 'add-curved':
				this.addRows( 'add-curved' === action );
				break;

			case 'stage':
			case 'aisle':
			case 'entrance':
			case 'exit':
				editor.mutate( function ( geometry ) {
					Geometry.addShape( geometry, {
						kind: action,
						x: 200,
						y: 'stage' === action ? 60 : 400,
						width: 'stage' === action ? 320 : 60,
						height: 'stage' === action ? 60 : 200,
						label: 'stage' === action ? 'Stage' : action.charAt( 0 ).toUpperCase() + action.slice( 1 ),
					} );
				} );
				break;

			case 'text':
				var text = window.prompt( 'Text' );

				if ( text ) {
					editor.mutate( function ( geometry ) {
						Geometry.addText( geometry, { text: text, x: 200, y: 200 } );
					} );
				}

				break;

			case 'align-x':
				editor.mutate( function ( geometry ) {
					Geometry.align( geometry, editor.selection, 'x' );
				} );
				break;

			case 'align-y':
				editor.mutate( function ( geometry ) {
					Geometry.align( geometry, editor.selection, 'y' );
				} );
				break;

			case 'distribute-x':
				editor.mutate( function ( geometry ) {
					Geometry.distribute( geometry, editor.selection, 'x' );
				} );
				break;

			case 'renumber':
				editor.mutate( function ( geometry ) {
					geometry.sections.forEach( function ( section ) {
						section.rows.forEach( function ( row ) {
							Geometry.renumberRow( row, 'ltr' );
						} );
					} );
				} );
				break;

			case 'undo':
				editor.undo();
				break;

			case 'redo':
				editor.redo();
				break;

			case 'fit':
				editor.zoomToFit();
				break;

			case 'save':
				this.saveDraft();
				break;

			case 'publish':
				this.publish();
				break;
		}
	};

	App.addRows = function ( curved ) {
		var editor = this.state.editor;
		var geometry = editor.geometry;

		if ( ! geometry.sections.length ) {
			window.alert( 'Add a section first — rows belong to a section.' );

			return;
		}

		var sectionName = window.prompt(
			'Which section? ' + geometry.sections.map( function ( s ) { return s.name; } ).join( ', ' ),
			geometry.sections[ 0 ].name
		);

		var section = geometry.sections.filter( function ( s ) {
			return s.name === sectionName;
		} )[ 0 ];

		if ( ! section ) {
			return;
		}

		var rows = parseInt( window.prompt( 'How many rows?', '10' ), 10 );
		var perRow = parseInt( window.prompt( 'Seats per row?', '16' ), 10 );

		if ( ! rows || ! perRow ) {
			return;
		}

		var zone = window.prompt( 'Price zone key (optional)', 'standard' ) || null;

		editor.mutate( function () {
			if ( curved ) {
				Geometry.addCurvedRows( section, { rows: rows, seatsPerRow: perRow, zoneKey: zone } );
			} else {
				Geometry.addStraightRows( section, { rows: rows, seatsPerRow: perRow, zoneKey: zone } );
			}
		} );
	};

	App.refreshEditorStatus = function () {
		var editor = this.state.editor;
		var report = Geometry.validate( editor.geometry );
		var self = this;

		document.getElementById( 'editor-status' ).innerHTML =
			'<ul class="stats"><li><span>' + report.seat_count + '</span>seats</li>' +
			'<li><span>' + editor.geometry.sections.length + '</span>sections</li>' +
			'<li><span>' + editor.selection.length + '</span>selected</li></ul>';

		var issues = report.errors
			.map( function ( issue ) {
				return '<li class="issue issue--error">' + self.escape( issue.message ) + '</li>';
			} )
			.concat(
				report.warnings.map( function ( issue ) {
					return '<li class="issue issue--warning">' + self.escape( issue.message ) + '</li>';
				} )
			)
			.join( '' );

		document.getElementById( 'editor-validation' ).innerHTML = issues
			? '<ul class="issues">' + issues + '</ul>'
			: '<p class="ok">No problems found.</p>';
	};

	App.saveDraft = function () {
		var self = this;

		this.request( 'POST', '/seat-maps/' + this.state.map.id + '/versions', {
			geometry: this.state.editor.geometry,
		} )
			.then( function () {
				self.toast( 'Draft saved.' );
			} )
			.catch( function ( error ) {
				self.toast( error.message, true );
			} );
	};

	/**
	 * Publishing saves first, so what is published is exactly what is on screen — publishing a
	 * stale server-side draft is the kind of surprise that costs a venue a night's sales.
	 */
	App.publish = function () {
		var self = this;

		this.request( 'POST', '/seat-maps/' + this.state.map.id + '/versions', {
			geometry: this.state.editor.geometry,
		} )
			.then( function () {
				return self.request( 'POST', '/seat-maps/' + self.state.map.id + '/publish', {} );
			} )
			.then( function ( version ) {
				self.toast( 'Published version ' + version.version + ' with ' + version.seat_count + ' seats.' );
			} )
			.catch( function ( error ) {
				var detail = '';

				if ( error.details && error.details.errors ) {
					detail =
						' ' +
						error.details.errors
							.map( function ( issue ) {
								return issue.message;
							} )
							.join( ' ' );
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

		window.setTimeout( function () {
			toast.remove();
		}, 6000 );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		App.init();
	} );
} )();
