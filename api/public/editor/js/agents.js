/**
 * The shops and bureaux that sell an organiser's tickets, and what is between them.
 *
 * One screen for three questions, because they are asked together and answered together: who sells
 * for us, what have we let each of them sell, and where does their account stand. The list is the
 * agents with their balance beside them — an agency in the red by four thousand is the first thing
 * anybody opening this screen wants to see — and opening one gives the allowances, the ledger and
 * the statement.
 */
( function ( global ) {
	'use strict';

	var Agents = {};

	Agents.render = function ( App ) {
		Agents.App = App;
		App.loading( App.t( 'panel.nav.agents' ) );

		App.request( 'GET', '/sales-agents' )
			.then( function ( response ) {
				Agents.list = response.data || [];
				Agents.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Agents.paint = function () {
		var App = Agents.App;

		App.page( {
			title: App.t( 'panel.nav.agents' ),
			description: App.t( 'panel.agents.subtitle' ),
			actions: '<button class="btn btn--primary" id="agent-add">' +
				esc( App.t( 'panel.agents.add' ) ) + '</button>',
			body: Agents.list.length
				? App.table(
					[
						App.t( 'panel.agents.name' ),
						App.t( 'panel.agents.maySell' ),
						App.t( 'panel.agents.rate' ),
						{ label: App.t( 'panel.agents.sold' ), numeric: true },
						{ label: App.t( 'panel.agents.balance' ), numeric: true },
						'',
					],
					Agents.list.map( function ( agent ) {
						var account = agent.account || {};
						var owing = ( account.balance || 0 ) < 0;

						return '<tr' + ( agent.active ? '' : ' class="is-muted"' ) + '>' +
							'<td class="table__primary">' + esc( agent.name ) +
								'<span class="muted on-own-line">' + esc( agent.code ) +
									( agent.active ? '' : ' · ' + esc( App.t( 'panel.agents.switchedOff' ) ) ) +
								'</span></td>' +
							'<td>' + esc( agent.all_events
								? App.t( 'panel.agents.everything' )
								: App.t( 'panel.agents.someEvents', {
									count: App.number( agent.event_count || 0 ),
								} ) ) + '</td>' +
							'<td class="tnum">' + esc( App.number( agent.commission_percent ) ) + '%</td>' +
							'<td class="tnum">' + esc( App.money( account.sold || 0, account.currency ) ) + '</td>' +
							// The number anybody opening this screen is looking for: red is money
							// the agent owes the house.
							'<td class="tnum' + ( owing ? ' is-danger' : '' ) + '">' +
								esc( App.money( account.balance || 0, account.currency ) ) + '</td>' +
							'<td><button class="btn btn--sm" data-agent="' + esc( agent.id ) + '">' +
								esc( App.t( 'panel.agents.open' ) ) + '</button></td>' +
						'</tr>';
					} ).join( '' )
				)
				: '<p class="muted">' + esc( App.t( 'panel.agents.none' ) ) + '</p>',
		} );

		bind( 'agent-add', function () { Agents.edit( null ); } );

		each( '[data-agent]', function ( button ) {
			button.addEventListener( 'click', function () { Agents.open( button.dataset.agent ); } );
		} );
	};

	/* --------------------------------------------------------------------------- one agent */

	Agents.open = function ( id ) {
		var App = Agents.App;

		App.loading( App.t( 'panel.nav.agents' ) );

		Promise.all( [
			App.request( 'GET', '/sales-agents/' + id ),
			App.request( 'GET', '/events?per_page=100' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( answers ) {
			Agents.one = answers[ 0 ];
			Agents.events = answers[ 1 ].data || [];
			Agents.paintOne();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	Agents.paintOne = function () {
		var App = Agents.App;
		var agent = Agents.one;
		var account = agent.account || {};

		App.page( {
			title: agent.name,
			description: esc( agent.code + ( agent.contact_email ? ' · ' + agent.contact_email : '' ) ),
			actions:
				'<button class="btn" id="agent-back">' + esc( App.t( 'panel.agents.all' ) ) + '</button>' +
				'<button class="btn" id="agent-edit">' + esc( App.t( 'panel.common.edit' ) ) + '</button>' +
				'<button class="btn" id="agent-events">' + esc( App.t( 'panel.agents.chooseEvents' ) ) + '</button>' +
				// Only where they have no way in yet: a second sign-in for the same agent is a
				// second person nobody can tell apart in the audit log.
				( agent.user_id
					? ''
					: '<button class="btn" id="agent-signin">' +
						esc( App.t( 'panel.agents.giveSignIn' ) ) + '</button>' ) +
				'<button class="btn btn--primary" id="agent-credit">' +
					esc( App.t( 'panel.agents.recordMovement' ) ) + '</button>',
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.agents.balance' ), App.money( account.balance || 0, account.currency ),
						( account.balance || 0 ) < 0
							? App.t( 'panel.agents.owesUs' )
							: App.t( 'panel.agents.inHand' ) ) +
					tile( App.t( 'panel.agents.available' ), App.money( account.available || 0, account.currency ),
						App.t( 'panel.agents.limitIs', {
							amount: App.money( account.credit_limit || 0, account.currency ),
						} ) ) +
					tile( App.t( 'panel.agents.sold' ), App.money( account.sold || 0, account.currency ),
						App.t( 'panel.agents.seatsSold', { count: App.number( account.seats || 0 ) } ) ) +
					tile( App.t( 'panel.agents.commission' ),
						App.money( account.commission || 0, account.currency ),
						App.t( 'panel.agents.atRate', { rate: App.number( agent.commission_percent ) } ) ) +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'panel.agents.maySell' ) ) + '</h3>' +
				( agent.all_events
					? '<p class="muted">' + esc( App.t( 'panel.agents.everythingHint' ) ) + '</p>'
					: ( agent.events || [] ).length
						? App.table(
							[ App.t( 'panel.agents.event' ), App.t( 'panel.agents.when' ),
								App.t( 'panel.common.status' ) ],
							agent.events.map( function ( event ) {
								return '<tr><td class="table__primary">' + esc( event.name ) + '</td>' +
									'<td class="nowrap tnum">' + esc( App.date( event.starts_at ) ) + '</td>' +
									'<td>' + esc( App.t( 'panel.eventStatus.' + event.status ) ) + '</td></tr>';
							} ).join( '' )
						)
						: '<p class="muted">' + esc( App.t( 'panel.agents.nothingAllowed' ) ) + '</p>' ) +

				'<h3 class="subhead">' + esc( App.t( 'panel.agents.ledger' ) ) + '</h3>' +
				( ( agent.ledger || [] ).length
					? App.table(
						[
							App.t( 'panel.agents.when' ),
							App.t( 'panel.agents.movement' ),
							App.t( 'panel.agents.reference' ),
							{ label: App.t( 'panel.orders.total' ), numeric: true },
						],
						agent.ledger.map( function ( entry ) {
							return '<tr>' +
								'<td class="nowrap tnum">' + esc( App.date( entry.at ) ) + '</td>' +
								'<td>' + esc( App.t( 'panel.agents.kinds.' + entry.kind ) ) +
									( entry.note
										? '<span class="muted on-own-line">' + esc( entry.note ) + '</span>'
										: '' ) + '</td>' +
								'<td class="muted">' + esc( entry.reference || '—' ) + '</td>' +
								'<td class="tnum">' + esc( App.money( entry.amount, entry.currency ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="muted">' + esc( App.t( 'panel.agents.noMovements' ) ) + '</p>' ),
		} );

		bind( 'agent-back', function () { Agents.render( App ); } );
		bind( 'agent-edit', function () { Agents.edit( agent ); } );
		bind( 'agent-events', function () { Agents.chooseEvents(); } );
		bind( 'agent-credit', function () { Agents.recordMovement(); } );
		bind( 'agent-signin', function () { Agents.giveSignIn(); } );
	};

	/* --------------------------------------------------------------------- the agency's own */

	/**
	 * The same account, read by the agency rather than about them.
	 *
	 * The figures are the organiser's figures, from the same endpoint family and the same
	 * derivation — a reseller arguing about a month with a different set of numbers than the person
	 * they are arguing with is how a settlement takes a fortnight. What is missing is everything
	 * that is not theirs: no list of other agencies, no way to move their own credit, no rate to
	 * edit. They read; the organiser writes.
	 */
	Agents.mine = function ( App ) {
		Agents.App = App;
		App.loading( App.t( 'panel.nav.myagency' ) );

		Promise.all( [
			App.request( 'GET', '/sales-agents/summary' ),
			App.request( 'GET', '/sales-agents/summary/statement' + Agents.period() ),
		] )
			.then( function ( answers ) {
				Agents.one = answers[ 0 ].agent;
				Agents.one.events = answers[ 0 ].events || [];
				Agents.one.ledger = answers[ 0 ].ledger || [];
				Agents.statement = answers[ 1 ];
				Agents.paintMine();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/** The period the screen is showing, as a query string. Empty means everything so far. */
	Agents.period = function () {
		var span = Agents.span || {};
		var parts = [];

		if ( span.from ) { parts.push( 'from=' + encodeURIComponent( span.from ) ); }
		if ( span.to ) { parts.push( 'to=' + encodeURIComponent( span.to ) ); }

		return parts.length ? '?' + parts.join( '&' ) : '';
	};

	Agents.paintMine = function () {
		var App = Agents.App;
		var agent = Agents.one;

		// Somebody who is not an agency at all, opening this by its address. Told rather than
		// refused: there is nothing here for them, and that is not the same as a wall.
		if ( ! agent ) {
			App.page( {
				title: App.t( 'panel.nav.myagency' ),
				body: App.emptyState( 'users', App.t( 'panel.agents.notAnAgent' ),
					esc( App.t( 'panel.agents.notAnAgentBody' ) ) ),
			} );

			return;
		}

		var account = agent.account || {};
		var period = ( Agents.statement || {} ).period || {};
		var span = Agents.span || {};

		App.page( {
			title: agent.name,
			description: esc( agent.code ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.agents.available' ), App.money( account.available || 0, account.currency ),
						App.t( 'panel.agents.limitIs', {
							amount: App.money( account.credit_limit || 0, account.currency ),
						} ) ) +
					tile( App.t( 'panel.agents.balance' ), App.money( account.balance || 0, account.currency ),
						( account.balance || 0 ) < 0
							? App.t( 'panel.agents.owesUs' )
							: App.t( 'panel.agents.inHand' ) ) +
					tile( App.t( 'panel.agents.sold' ), App.money( account.sold || 0, account.currency ),
						App.t( 'panel.agents.seatsSold', { count: App.number( account.seats || 0 ) } ) ) +
					tile( App.t( 'panel.agents.commission' ),
						App.money( account.commission || 0, account.currency ),
						App.t( 'panel.agents.atRate', { rate: App.number( agent.commission_percent ) } ) ) +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'panel.agents.statement' ) ) + '</h3>' +
				'<div class="card spaced">' +
					'<div class="filters">' +
						dated( 'mine-from', App.t( 'panel.agents.from' ), span.from || '' ) +
						dated( 'mine-to', App.t( 'panel.agents.to' ), span.to || '' ) +
						'<button class="btn" id="mine-period">' +
							esc( App.t( 'panel.agents.showPeriod' ) ) + '</button>' +
					'</div>' +
					'<div class="stat-strip">' +
						tile( App.t( 'panel.agents.periodSold' ),
							App.money( period.sold || 0, account.currency ),
							App.t( 'panel.agents.seatsSold', { count: App.number( period.seats || 0 ) } ) ) +
						tile( App.t( 'panel.agents.periodCommission' ),
							App.money( period.commission || 0, account.currency ),
							App.t( 'panel.agents.atRate', { rate: App.number( agent.commission_percent ) } ) ) +
						tile( App.t( 'panel.agents.periodDue' ),
							App.money( period.due || 0, account.currency ),
							App.t( 'panel.agents.dueHint' ) ) +
					'</div>' +
					// The one sentence that stops a statement being read as a bill: the period bounds
					// what was sold, and never what is in hand.
					'<p class="hint">' + esc( App.t( 'panel.agents.periodHint' ) ) + '</p>' +
				'</div>' +

				'<h3 class="subhead">' + esc( App.t( 'panel.agents.maySell' ) ) + '</h3>' +
				( agent.all_events
					? '<p class="muted">' + esc( App.t( 'panel.agents.everythingHint' ) ) + '</p>'
					: ( agent.events || [] ).length
						? App.table(
							[ App.t( 'panel.agents.event' ), App.t( 'panel.agents.when' ),
								App.t( 'panel.common.status' ) ],
							agent.events.map( function ( event ) {
								return '<tr><td class="table__primary">' + esc( event.name ) + '</td>' +
									'<td class="nowrap tnum">' + esc( App.date( event.starts_at ) ) + '</td>' +
									'<td>' + esc( App.t( 'panel.eventStatus.' + event.status ) ) + '</td></tr>';
							} ).join( '' )
						)
						: '<p class="muted">' + esc( App.t( 'panel.agents.nothingAllowed' ) ) + '</p>' ) +

				'<h3 class="subhead">' + esc( App.t( 'panel.agents.ledger' ) ) + '</h3>' +
				( ( agent.ledger || [] ).length
					? App.table(
						[
							App.t( 'panel.agents.when' ),
							App.t( 'panel.agents.movement' ),
							App.t( 'panel.agents.reference' ),
							{ label: App.t( 'panel.orders.total' ), numeric: true },
						],
						agent.ledger.map( function ( entry ) {
							return '<tr>' +
								'<td class="nowrap tnum">' + esc( App.date( entry.at ) ) + '</td>' +
								'<td>' + esc( App.t( 'panel.agents.kinds.' + entry.kind ) ) +
									( entry.note
										? '<span class="muted on-own-line">' + esc( entry.note ) + '</span>'
										: '' ) + '</td>' +
								'<td class="muted">' + esc( entry.reference || '—' ) + '</td>' +
								'<td class="tnum">' + esc( App.money( entry.amount, entry.currency ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="muted">' + esc( App.t( 'panel.agents.noMovements' ) ) + '</p>' ),
		} );

		bind( 'mine-period', function () {
			Agents.span = {
				from: value( 'mine-from' ),
				to: value( 'mine-to' ),
			};

			Agents.mine( App );
		} );
	};

	/* ------------------------------------------------------------------------------ forms */

	Agents.edit = function ( agent ) {
		var App = Agents.App;
		var making = ! agent;

		App.modal( {
			title: making ? App.t( 'panel.agents.add' ) : agent.name,
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					field( 'ag-name', App.t( 'panel.agents.name' ), agent ? agent.name : '', 160, null, true ) +
					field( 'ag-code', App.t( 'panel.agents.code' ), agent ? agent.code : '', 40,
						App.t( 'panel.agents.codeHint' ), true ) +
					field( 'ag-contact', App.t( 'panel.agents.contact' ), agent ? agent.contact_name : '', 120 ) +
					field( 'ag-email', App.t( 'panel.agents.email' ), agent ? agent.contact_email : '', 190 ) +
					field( 'ag-phone', App.t( 'panel.agents.phone' ), agent ? agent.contact_phone : '', 40 ) +
					'<div class="field"><label class="field__label" for="ag-rate">' +
						esc( App.t( 'panel.agents.rate' ) ) + '</label>' +
						'<input class="input" id="ag-rate" type="number" min="0" max="100" step="0.25" value="' +
						esc( agent ? agent.commission_percent : 0 ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.agents.rateHint' ) ) + '</span></div>' +
					'<div class="field"><label class="field__label" for="ag-limit">' +
						esc( App.t( 'panel.agents.creditLimit' ) ) + '</label>' +
						'<input class="input" id="ag-limit" type="number" min="0" step="1" value="' +
						esc( agent ? ( agent.credit_limit / 100 ) : 0 ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.agents.creditLimitHint' ) ) +
						'</span></div>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="ag-active"' +
						( ! agent || agent.active ? ' checked' : '' ) + '>' +
						'<span>' + esc( App.t( 'panel.agents.selling' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var payload = {
					name: value( 'ag-name' ),
					code: value( 'ag-code' ),
					contact_name: value( 'ag-contact' ) || null,
					contact_email: value( 'ag-email' ) || null,
					contact_phone: value( 'ag-phone' ) || null,
					// Typed as a percentage and sent as basis points, which is the unit every rate
					// on this platform is stored in.
					commission_rate: Math.round( ( parseFloat( value( 'ag-rate' ) ) || 0 ) * 100 ),
					credit_limit: Math.round( ( parseFloat( value( 'ag-limit' ) ) || 0 ) * 100 ),
					active: document.getElementById( 'ag-active' ).checked,
				};

				if ( ! payload.name || ! payload.code ) {
					App.toast( App.t( 'panel.agents.needsNameAndCode' ), true );

					return true;
				}

				var call = making
					? App.request( 'POST', '/sales-agents', payload )
					: App.request( 'PATCH', '/sales-agents/' + agent.id, payload );

				return call.then( function ( saved ) {
					App.toast( App.t( 'panel.agents.saved' ) );
					making ? Agents.open( saved.id ) : Agents.open( agent.id );
				} );
			},
		} );
	};

	/** What this agent may sell — the whole list at once, ticked. */
	Agents.chooseEvents = function () {
		var App = Agents.App;
		var agent = Agents.one;
		var granted = {};

		( agent.events || [] ).forEach( function ( event ) { granted[ event.id ] = true; } );

		App.modal( {
			title: App.t( 'panel.agents.chooseEvents' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.agents.chooseEventsHint' ) ) + '</p>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="ag-all"' +
						( agent.all_events ? ' checked' : '' ) + '>' +
						'<span>' + esc( App.t( 'panel.agents.everything' ) ) + '</span></label>' +
					'<div class="perms" id="ag-events">' +
						Agents.events.map( function ( event ) {
							return '<label class="perms__row">' +
								'<input type="checkbox" class="checkbox" data-event="' + esc( event.id ) + '"' +
								( granted[ event.id ] ? ' checked' : '' ) + '>' +
								'<span>' + esc( event.name ) + ' <span class="muted">' +
								esc( App.date( event.starts_at ) ) + '</span></span></label>';
						} ).join( '' ) +
					'</div>' +
				'</div>',
			onSubmit: function () {
				var all = document.getElementById( 'ag-all' ).checked;
				var ids = [];

				each( '#ag-events [data-event]', function ( box ) {
					if ( box.checked ) {
						ids.push( box.dataset.event );
					}
				} );

				return App.request( 'PUT', '/sales-agents/' + agent.id + '/events', {
					all_events: all,
					event_ids: ids,
				} ).then( function () {
					App.toast( App.t( 'panel.agents.saved' ) );
					Agents.open( agent.id );
				} );
			},
		} );

		// Ticking "everything" makes the list beneath it beside the point, and saying so is kinder
		// than leaving somebody to wonder why their choices had no effect.
		var all = document.getElementById( 'ag-all' );
		var list = document.getElementById( 'ag-events' );
		var sync = function () { list.hidden = all.checked; };

		all.addEventListener( 'change', sync );
		sync();
	};

	/** Money in from an agent, money out to them, or an adjustment somebody signs. */
	Agents.recordMovement = function () {
		var App = Agents.App;
		var agent = Agents.one;
		var currency = ( agent.account || {} ).currency || 'EUR';

		App.modal( {
			title: App.t( 'panel.agents.recordMovement' ),
			submitLabel: App.t( 'panel.agents.record' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.agents.movementHint' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="ag-kind">' +
						esc( App.t( 'panel.agents.movement' ) ) + '</label>' +
						'<select class="select" id="ag-kind">' +
							[ 'topup', 'settlement', 'deduction', 'adjustment' ].map( function ( kind ) {
								return '<option value="' + kind + '">' +
									esc( App.t( 'panel.agents.kinds.' + kind ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					'<div class="field"><label class="field__label" for="ag-amount">' +
						esc( App.t( 'panel.orders.total' ) ) + '</label>' +
						'<input class="input" id="ag-amount" type="number" step="0.01" value="0"></div>' +
					'<div class="field"><label class="field__label" for="ag-method">' +
						esc( App.t( 'panel.boxOffice.method' ) ) + '</label>' +
						'<select class="select" id="ag-method">' +
							[ 'transfer', 'cash', 'card' ].map( function ( method ) {
								return '<option value="' + method + '">' +
									esc( App.t( 'panel.boxOffice.methods.' + method ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					field( 'ag-reference', App.t( 'panel.agents.reference' ), '', 190 ) +
					field( 'ag-note', App.t( 'panel.boxOffice.note' ), '', 200 ) +
				'</div>',
			onSubmit: function () {
				var amount = Math.round( ( parseFloat( value( 'ag-amount' ) ) || 0 ) * 100 );

				if ( ! amount ) {
					App.toast( App.t( 'panel.agents.needsAmount' ), true );

					return true;
				}

				return App.request( 'POST', '/sales-agents/' + agent.id + '/credit', {
					kind: document.getElementById( 'ag-kind' ).value,
					amount: amount,
					currency: currency,
					method: document.getElementById( 'ag-method' ).value,
					reference: value( 'ag-reference' ) || null,
					note: value( 'ag-note' ) || null,
				} ).then( function () {
					App.toast( App.t( 'panel.agents.recorded' ) );
					Agents.open( agent.id );
				} );
			},
		} );
	};

	/**
	 * A way in for the agent themselves.
	 *
	 * The password is shown once and never again — the same bargain the platform makes with an API
	 * secret — so the screen has to make that plain while it is on it.
	 */
	Agents.giveSignIn = function () {
		var App = Agents.App;
		var agent = Agents.one;

		App.modal( {
			title: App.t( 'panel.agents.giveSignIn' ),
			submitLabel: App.t( 'panel.agents.giveSignIn' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.agents.signInHint' ) ) + '</p>' +
					field( 'ag-login-email', App.t( 'panel.agents.email' ), agent.contact_email || '', 190 ) +
					field( 'ag-login-name', App.t( 'panel.agents.contact' ), agent.contact_name || agent.name, 120 ) +
				'</div>',
			onSubmit: function () {
				return App.request( 'POST', '/sales-agents/' + agent.id + '/sign-in', {
					email: value( 'ag-login-email' ) || null,
					name: value( 'ag-login-name' ) || null,
				} ).then( function ( made ) {
					Agents.open( agent.id );

					// Shown as a modal of its own rather than a toast: it is the one moment this
					// password exists, and a message that fades after four seconds is not where it
					// belongs.
					App.modal( {
						title: App.t( 'panel.agents.signInMade' ),
						body:
							'<p>' + esc( App.t( 'panel.agents.signInOnce' ) ) + '</p>' +
							'<dl class="kbd-list">' +
								'<dt>' + esc( App.t( 'panel.agents.email' ) ) + '</dt>' +
								'<dd><code>' + esc( made.email ) + '</code></dd>' +
								'<dt>' + esc( App.t( 'panel.agents.password' ) ) + '</dt>' +
								'<dd><code>' + esc( made.password ) + '</code></dd>' +
							'</dl>',
						doneLabel: App.t( 'panel.common.done' ),
					} );
				} );
			},
		} );
	};

	/* ---------------------------------------------------------------------------- helpers */

	/** `must` marks the two the server will not do without, so the form says so before it is sent. */
	function field( id, label, current, max, hint, must ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' + esc( label ) +
			'</label><input class="input" id="' + id + '" maxlength="' + max + '"' +
			( must ? ' required' : '' ) + ' value="' +
			esc( current || '' ) + '">' +
			( hint ? '<span class="field__hint">' + esc( hint ) + '</span>' : '' ) + '</div>';
	}

	function value( id ) {
		return ( document.getElementById( id ).value || '' ).trim();
	}

	/*
	 * A date is the one filter that cannot say what it is on its own: empty, it reads as
	 * "mm/dd/yyyy" and nothing else, so the word goes beside the box rather than only in a label
	 * the eye never reaches.
	 */
	function dated( id, label, current ) {
		return '<label class="filters__dated" for="' + id + '">' +
			'<span>' + esc( label ) + '</span>' +
			'<input class="input" type="date" id="' + id + '" value="' + esc( current ) + '">' +
		'</label>';
	}

	function tile( label, amount, hint ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( amount ) + '</span>' +
			( hint ? '<span class="tile__meta">' + esc( hint ) + '</span>' : '' ) +
		'</div>';
	}

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapAgents = Agents;
}( typeof window !== 'undefined' ? window : globalThis ) );
