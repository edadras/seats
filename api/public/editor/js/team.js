/**
 * Who is in this account and what they may do — and, on its own screen, what everyone has done.
 *
 * The permission checkboxes are grouped the way the jobs are, not the way the code is: "Box office"
 * holds finding a booking, refunding it and freeing a seat, because that is one person's afternoon.
 * The label on each box says what somebody can *do*, since whoever is ticking it is deciding
 * whether a volunteer should see the takings.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Team = { roles: [], permissions: [], groups: [], editing: null };
	var Audit = { filters: { action: '', actor_id: '' }, facets: { actions: [], actors: [] } };

	/* ------------------------------------------------------------------------------- team */

	Team.render = function ( App ) {
		App.loading( App.t( 'team.title' ) );

		Promise.all( [ App.request( 'GET', '/team' ), App.request( 'GET', '/roles' ) ] )
			.then( function ( responses ) {
				var team = responses[ 0 ];
				var roles = responses[ 1 ];

				Team.roles = roles.data;
				Team.permissions = roles.permissions;
				Team.groups = roles.groups;

				Team.paint( App, team );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Team.paint = function ( App, team ) {
		App.page( {
			title: App.t( 'team.title' ),
			description: App.t( 'team.subtitle' ),
			actions: '<button class="btn btn--primary" id="team-invite">' +
				icon( 'plus', { size: 15 } ) + esc( App.t( 'team.invite' ) ) + '</button>',
			body:
				'<h3 class="section-heading">' + esc( App.t( 'team.members' ) ) + '</h3>' +
				Team.members( App, team.data ) +

				( team.invitations.length
					? '<h3 class="section-heading">' + esc( App.t( 'team.invitations' ) ) + '</h3>' +
						Team.invitations( App, team.invitations )
					: '' ) +

				'<h3 class="section-heading">' + esc( App.t( 'team.rolesTitle' ) ) + '</h3>' +
				Team.roleCards( App ),
		} );

		Team.bind( App, team );
	};

	Team.members = function ( App, members ) {
		return App.table(
			[ App.t( 'team.members' ), App.t( 'team.rolesTitle' ), App.t( 'team.lastSeenColumn' ), '' ],
			members.map( function ( member ) {
				return '<tr' + ( member.suspended ? ' class="is-muted"' : '' ) + '>' +
					'<td class="table__primary">' + esc( member.name || member.email ) +
						'<span class="muted on-own-line">' + esc( member.email ) + '</span></td>' +
					'<td>' + esc( member.role_name ) + '</td>' +
					'<td class="muted">' +
						( member.suspended
							? '<span class="badge badge--warn">' + esc( App.t( 'team.suspended' ) ) + '</span>'
							: esc( member.last_seen_at
								? App.t( 'team.lastSeen', { when: App.date( member.last_seen_at ) } )
								: App.t( 'team.neverSeen' ) ) ) +
					'</td>' +
					'<td class="table__actions">' +
						// One row per person, so the name of the control is the person it is about.
						'<select class="select select--sm" data-member-role="' + esc( member.id ) + '"' +
							' aria-label="' + esc( App.t( 'team.roleOf', { name: member.name } ) ) + '">' +
						Team.roles.map( function ( role ) {
							return '<option value="' + esc( role.key ) + '"' +
								( role.key === member.role ? ' selected' : '' ) + '>' +
								esc( role.name ) + '</option>';
						} ).join( '' ) +
						'</select> ' +
						'<button class="btn btn--sm" data-member-suspend="' + esc( member.id ) + '" ' +
							'data-suspended="' + ( member.suspended ? '1' : '0' ) + '">' +
							esc( App.t( member.suspended ? 'team.restore' : 'team.suspend' ) ) +
						'</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Team.invitations = function ( App, invitations ) {
		return App.table(
			[ App.t( 'site.email' ), App.t( 'team.rolesTitle' ), '', '' ],
			invitations.map( function ( invitation ) {
				return '<tr><td class="table__primary">' + esc( invitation.email ) + '</td>' +
					'<td>' + esc( invitation.role ) + '</td>' +
					'<td><span class="badge badge--' + ( invitation.expired ? 'warn' : 'neutral' ) + '">' +
						esc( App.t( invitation.expired ? 'team.expired' : 'team.pending' ) ) + '</span></td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm btn--danger" data-revoke="' + esc( invitation.id ) + '">' +
							esc( App.t( 'team.revoke' ) ) + '</button>' +
					'</td></tr>';
			} ).join( '' )
		);
	};

	Team.roleCards = function ( App ) {
		return '<div class="roles">' + Team.roles.map( function ( role ) {
			return '<article class="role">' +
				'<header class="role__head">' +
					'<h4 class="role__name">' + esc( role.name ) + '</h4>' +
					'<span class="badge badge--soft">' +
						esc( App.t( role.built_in ? 'team.builtIn' : 'team.custom' ) ) + '</span>' +
				'</header>' +
				( role.description ? '<p class="role__about">' + esc( role.description ) + '</p>' : '' ) +
				'<p class="role__count muted">' +
					esc( App.t( 'team.permissionCount', { count: App.number( role.permissions.length ) } ) ) +
				'</p>' +
				( role.built_in ? '' :
					'<div class="row row--end">' +
						'<button class="btn btn--sm" data-edit-role="' + esc( role.id ) + '">' +
							esc( App.t( 'modules.configure' ) ) + '</button>' +
					'</div>' ) +
			'</article>';
		} ).join( '' ) +
		'<button class="role role--new" id="team-new-role">' + icon( 'plus', { size: 18 } ) +
			'<span>' + esc( App.t( 'team.newRole' ) ) + '</span></button>' +
		'</div>';
	};

	Team.bind = function ( App, team ) {
		on( '[data-member-role]', 'change', function ( control ) {
			Team.save( App, '/team/members/' + control.dataset.memberRole, { role: control.value } );
		} );

		on( '[data-member-suspend]', 'click', function ( button ) {
			Team.save( App, '/team/members/' + button.dataset.memberSuspend, {
				suspended: '1' !== button.dataset.suspended,
			} );
		} );

		on( '[data-revoke]', 'click', function ( button ) {
			App.request( 'DELETE', '/team/invitations/' + button.dataset.revoke )
				.then( function () { Team.render( App ); } )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		on( '[data-edit-role]', 'click', function ( button ) {
			Team.editRole( App, Team.roles.filter( function ( role ) {
				return role.id === button.dataset.editRole;
			} )[ 0 ] );
		} );

		var invite = document.getElementById( 'team-invite' );
		if ( invite ) { invite.addEventListener( 'click', function () { Team.invite( App ); } ); }

		var newRole = document.getElementById( 'team-new-role' );
		if ( newRole ) { newRole.addEventListener( 'click', function () { Team.editRole( App, null ); } ); }
	};

	Team.save = function ( App, path, payload ) {
		App.request( 'PATCH', path, payload )
			.then( function () { Team.render( App ); } )
			.catch( function ( error ) {
				App.toast( error.message, true );
				// Repaint, so a control that did not take does not go on showing the value the
				// server refused.
				Team.render( App );
			} );
	};

	Team.invite = function ( App ) {
		App.modal( {
			title: App.t( 'team.invite' ),
			body:
				'<div class="field"><label class="field__label" for="inv-email">' +
					esc( App.t( 'site.email' ) ) + '</label>' +
					'<input class="input" id="inv-email" type="email" required></div>' +
				'<div class="field"><label class="field__label" for="inv-role">' +
					esc( App.t( 'team.rolesTitle' ) ) + '</label>' +
					'<select class="select" id="inv-role">' +
					Team.roles.filter( function ( role ) { return 'owner' !== role.key; } )
						.map( function ( role ) {
							return '<option value="' + esc( role.key ) + '">' + esc( role.name ) + '</option>';
						} ).join( '' ) +
					'</select></div>',
			submitLabel: App.t( 'team.invite' ),
			onSubmit: function () {
				return App.request( 'POST', '/team/invitations', {
					email: document.getElementById( 'inv-email' ).value,
					role: document.getElementById( 'inv-role' ).value,
				} ).then( function ( invitation ) {
					// The token is shown once, here, and never again — so it goes on the clipboard
					// rather than being left on a screen for somebody to lose.
					var link = window.location.origin + '/invite/' + invitation.token;

					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( link ).catch( function () {} );
					}

					App.toast( App.t( 'team.linkCopied' ) );
					Team.render( App );
				} );
			},
		} );
	};

	Team.editRole = function ( App, role ) {
		var held = role ? role.permissions : [];

		App.modal( {
			title: role ? role.name : App.t( 'team.newRole' ),
			body:
				'<div class="field"><label class="field__label" for="role-name">' +
					esc( App.t( 'team.roleName' ) ) + '</label>' +
					'<input class="input" id="role-name" value="' + esc( role ? role.name : '' ) + '"></div>' +
				( role ? '' :
					'<div class="field"><label class="field__label" for="role-key">' +
						esc( App.t( 'team.roleKey' ) ) + '</label>' +
						'<input class="input" id="role-key" placeholder="usher">' +
						'<p class="field__hint">' + esc( App.t( 'team.roleKeyHint' ) ) + '</p></div>' ) +
				Team.groups.map( function ( group ) {
					var inGroup = Team.permissions.filter( function ( permission ) {
						return permission.group === group.key;
					} );

					return '<fieldset class="perms">' +
						'<legend class="perms__legend">' + esc( group.label ) + '</legend>' +
						inGroup.map( function ( permission ) {
							return '<label class="perms__row">' +
								'<input type="checkbox" value="' + esc( permission.key ) + '"' +
								( held.indexOf( permission.key ) > -1 ? ' checked' : '' ) + '>' +
								'<span>' + esc( permission.label ) + '</span></label>';
						} ).join( '' ) +
					'</fieldset>';
				} ).join( '' ),
			submitLabel: App.t( role ? 'team.saveRole' : 'team.createRole' ),
			onSubmit: function () {
				var permissions = Array.prototype.slice
					.call( document.querySelectorAll( '.perms input:checked' ) )
					.map( function ( input ) { return input.value; } );

				var payload = {
					name: document.getElementById( 'role-name' ).value,
					permissions: permissions,
				};

				var request = role
					? App.request( 'PATCH', '/roles/' + role.id, payload )
					: App.request( 'POST', '/roles', Object.assign(
						{ key: document.getElementById( 'role-key' ).value }, payload ) );

				return request.then( function () { Team.render( App ); } );
			},
		} );
	};

	/* --------------------------------------------------------------------------- activity */

	Audit.render = function ( App ) {
		App.loading( App.t( 'team.auditTitle' ) );

		var query = Object.keys( Audit.filters )
			.filter( function ( key ) { return Audit.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Audit.filters[ key ] ); } )
			.join( '&' );

		Promise.all( [
			App.request( 'GET', '/audit' + ( query ? '?' + query : '' ) ),
			App.request( 'GET', '/audit/facets' ),
		] ).then( function ( responses ) {
			Audit.facets = responses[ 1 ];
			Audit.paint( App, responses[ 0 ] );
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Audit.paint = function ( App, page ) {
		App.page( {
			title: App.t( 'team.auditTitle' ),
			description: App.t( 'team.auditSubtitle' ),
			body:
				'<div class="filters">' +
					select( 'audit-action', App.t( 'team.anyAction' ),
						Audit.facets.actions.map( function ( action ) { return [ action, action ]; } ),
						Audit.filters.action ) +
					select( 'audit-actor', App.t( 'team.anyone' ),
						Audit.facets.actors.map( function ( actor ) { return [ actor.id, actor.name ]; } ),
						Audit.filters.actor_id ) +
				'</div>' +
				( page.data.length
					? App.table(
						[ App.t( 'team.whenColumn' ), App.t( 'team.actionColumn' ),
						  App.t( 'team.whoColumn' ), App.t( 'team.changedColumn' ) ],
						page.data.map( function ( entry ) { return Audit.row( App, entry ); } ).join( '' )
					)
					: App.emptyState( 'info', App.t( 'team.auditTitle' ), App.t( 'team.noActivity' ) ) ),
		} );

		[ [ 'audit-action', 'action' ], [ 'audit-actor', 'actor_id' ] ].forEach( function ( pair ) {
			var control = document.getElementById( pair[ 0 ] );

			if ( control ) {
				control.addEventListener( 'change', function () {
					Audit.filters[ pair[ 1 ] ] = control.value;
					Audit.render( App );
				} );
			}
		} );
	};

	Audit.row = function ( App, entry ) {
		var actor = entry.actor.name || App.t( 'team.' + ( {
			api_key: 'apiKey', device: 'device', system: 'system',
		}[ entry.actor.type ] || 'system' ) );

		var changes = entry.changes
			? Object.keys( entry.changes ).map( function ( field ) {
				var change = entry.changes[ field ];

				// Field, then what it was, then what it is. The "was" is the half that makes a
				// log worth opening.
				return '<span class="change"><b>' + esc( field ) + '</b> ' +
					esc( App.t( 'team.changedFrom', { from: describe( change.from ) } ) ) + ' → ' +
					esc( App.t( 'team.changedTo', { to: describe( change.to ) } ) ) + '</span>';
			} ).join( '' )
			: '';

		return '<tr>' +
			'<td class="muted nowrap">' + esc( App.date( entry.created_at ) ) + '</td>' +
			'<td class="table__primary">' + esc( entry.action ) +
				( entry.subject.label
					? '<span class="muted on-own-line">' + esc( entry.subject.label ) + '</span>'
					: '' ) + '</td>' +
			'<td>' + esc( actor ) + '</td>' +
			'<td class="changes">' + changes + '</td>' +
		'</tr>';
	};

	/* --------------------------------------------------------------------------- helpers */

	function describe( value ) {
		if ( null === value || undefined === value || '' === value ) {
			return '—';
		}

		if ( 'object' === typeof value ) {
			return JSON.stringify( value ).slice( 0, 80 );
		}

		return String( value ).slice( 0, 80 );
	}

	/*
	 * A filter is a control like any other and needs a name.
	 *
	 * These sit in a row above a table with nothing beside them, so the name is carried on the
	 * control rather than printed twice: the blank option — "Any action", "Anyone" — is what the
	 * filter is *for*, and is already translated.
	 */
	function select( id, blank, options, current ) {
		return '<select class="select" id="' + id + '" aria-label="' + esc( blank ) + '">' +
			'<option value="">' + esc( blank ) + '</option>' +
			options.map( function ( option ) {
				return '<option value="' + esc( option[ 0 ] ) + '"' +
					( option[ 0 ] === current ? ' selected' : '' ) + '>' +
					esc( option[ 1 ] ) + '</option>';
			} ).join( '' ) +
		'</select>';
	}

	function on( selector, event, handler ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), function ( element ) {
			element.addEventListener( event, function () { handler( element ); } );
		} );
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapTeam = Team;
	global.SeatmapAudit = Audit;
}( window ) );
