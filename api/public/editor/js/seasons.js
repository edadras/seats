/**
 * Season tickets.
 *
 * The screen is short because the offer is: a run, a name, and what buying the whole thing saves.
 * There is no price on a pass and there is no seat on it — every night is still priced by the
 * event it belongs to, and the subscriber picks their seat in the ordinary picker. A pass that
 * carried its own price would be a second pricing system, and the first thing anybody would do
 * with it is forget to keep the two in step.
 *
 * The number worth watching here is "nights on sale": a pass for a run whose nights have all been
 * and gone sells nothing, and says so, rather than sitting there looking active.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Seasons = {
		App: null,
		series: [],
		filters: { series_id: '', status: '', q: '' },
		timer: null,
		pass: null,
	};

	Seasons.render = function ( App ) {
		Seasons.App = App;
		App.loading( App.t( 'panel.seasons.title' ) );

		App.request( 'GET', '/season-passes/series' )
			.catch( function () { return { data: [] }; } )
			.then( function ( response ) {
				Seasons.series = response.data || [];
				Seasons.paint();
				Seasons.load();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Seasons.paint = function () {
		var App = Seasons.App;

		App.page( {
			title: App.t( 'panel.seasons.title' ),
			description: esc( App.t( 'panel.seasons.description' ) ),
			actions: Seasons.series.length
				? /*
				   * Renewals hang off a *run*, not off a pass: what is being offered is next
				   * season's chairs, and which pass prices them is a choice made when the round is
				   * opened. So the button is here, beside the run filter that says which run.
				   */
				  '<button class="btn" id="s-renewals">' +
					icon( 'users', { size: 15 } ) + esc( App.t( 'panel.renewals.title' ) ) + '</button>' +
				  '<button class="btn btn--primary" id="s-new">' +
					icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.seasons.create' ) ) + '</button>'
				: '',
			body:
				'<div class="filters">' +
					'<input class="input grow" id="s-search" type="search" ' +
						'placeholder="' + esc( App.t( 'panel.seasons.search' ) ) + '" ' +
						'aria-label="' + esc( App.t( 'panel.seasons.search' ) ) + '" ' +
						'value="' + esc( Seasons.filters.q ) + '">' +
					'<select class="select" id="s-series" aria-label="' +
						esc( App.t( 'panel.seasons.run' ) ) + '">' +
						'<option value="">' + esc( App.t( 'panel.seasons.everyRun' ) ) + '</option>' +
						Seasons.series.map( function ( run ) {
							return '<option value="' + esc( run.id ) + '"' +
								( run.id === Seasons.filters.series_id ? ' selected' : '' ) + '>' +
								esc( run.name ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<select class="select" id="s-status" aria-label="' +
						esc( App.t( 'panel.common.status' ) ) + '">' +
						[ '', 'active', 'paused' ].map( function ( status ) {
							return '<option value="' + status + '"' +
								( status === Seasons.filters.status ? ' selected' : '' ) + '>' +
								esc( status
									? App.t( 'panel.seasons.status.' + status )
									: App.t( 'panel.seasons.anyStatus' ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<div id="s-results" class="spaced"></div>',
		} );

		var search = document.getElementById( 's-search' );

		search.addEventListener( 'input', function () {
			Seasons.filters.q = search.value;

			global.clearTimeout( Seasons.timer );
			Seasons.timer = global.setTimeout( function () { Seasons.load(); }, 300 );
		} );

		[ [ 's-series', 'series_id' ], [ 's-status', 'status' ] ].forEach( function ( pair ) {
			document.getElementById( pair[ 0 ] ).addEventListener( 'change', function () {
				Seasons.filters[ pair[ 1 ] ] = this.value;
				Seasons.load();
			} );
		} );

		bind( 's-new', function () { Seasons.form( null ); } );
		bind( 's-renewals', function () { Seasons.renewals(); } );
	};

	/* ------------------------------------------------------------------- renewals */

	/**
	 * Next season, offered to last season's subscribers before anybody else.
	 *
	 * One run at a time, because that is what a round is. The run comes from the filter above: an
	 * organiser looking at "every run" is not looking at a renewal, they are looking at a list.
	 */
	Seasons.renewals = function () {
		var App = Seasons.App;
		var seriesId = Seasons.filters.series_id;

		if ( ! seriesId ) {
			App.toast( App.t( 'panel.renewals.chooseRun' ), true );

			return;
		}

		App.request( 'GET', '/series/' + seriesId + '/renewals' )
			.then( function ( response ) {
				var rounds = response.data || [];

				if ( ! rounds.length ) {
					Seasons.openRound( seriesId );

					return;
				}

				Seasons.showRound( rounds[ 0 ] );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/** Open one: which run they came from, how it is priced, and the date it closes. */
	Seasons.openRound = function ( seriesId ) {
		var App = Seasons.App;

		App.request( 'GET', '/season-passes?series_id=' + encodeURIComponent( seriesId ) )
			.then( function ( response ) {
				var passes = response.data || [];

				if ( ! passes.length ) {
					App.toast( App.t( 'panel.renewals.needsPass' ), true );

					return;
				}

				App.modal( {
					title: App.t( 'panel.renewals.open' ),
					submitLabel: App.t( 'panel.renewals.openIt' ),
					body:
						'<p class="hint">' + esc( App.t( 'panel.renewals.description' ) ) + '</p>' +
						'<div class="field"><label class="field__label" for="r-name">' +
						esc( App.t( 'panel.renewals.name' ) ) + '</label>' +
						'<input class="input" id="r-name" value="' +
						esc( App.t( 'panel.renewals.defaultName' ) ) + '"></div>' +
						'<div class="field"><label class="field__label" for="r-from">' +
						esc( App.t( 'panel.renewals.lastRun' ) ) + '</label>' +
						'<select class="select" id="r-from">' +
						Seasons.series.filter( function ( run ) { return run.id !== seriesId; } )
							.map( function ( run ) {
								return '<option value="' + esc( run.id ) + '">' +
									esc( run.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
						'<span class="field__hint">' +
						esc( App.t( 'panel.renewals.lastRunHint' ) ) + '</span></div>' +
						'<div class="field"><label class="field__label" for="r-pass">' +
						esc( App.t( 'panel.renewals.pass' ) ) + '</label>' +
						'<select class="select" id="r-pass">' +
						passes.map( function ( pass ) {
							return '<option value="' + esc( pass.id ) + '">' +
								esc( pass.name ) + '</option>';
						} ).join( '' ) +
						'</select></div>' +
						'<div class="field"><label class="field__label" for="r-deadline">' +
						esc( App.t( 'panel.renewals.deadline' ) ) + '</label>' +
						'<input class="input" id="r-deadline" type="datetime-local">' +
						'<span class="field__hint">' +
						esc( App.t( 'panel.renewals.deadlineHint' ) ) + '</span></div>',
					onSubmit: function () {
						var deadline = document.getElementById( 'r-deadline' ).value;

						if ( ! deadline ) {
							return Promise.reject( new Error( App.t( 'panel.renewals.needsDeadline' ) ) );
						}

						return App.request( 'POST', '/series/' + seriesId + '/renewals', {
							from_series_id: document.getElementById( 'r-from' ).value,
							season_pass_id: document.getElementById( 'r-pass' ).value,
							name: document.getElementById( 'r-name' ).value,
							deadline: new Date( deadline ).toISOString(),
						} ).then( function ( made ) {
							App.toast( App.t( 'panel.renewals.opened', {
								count: App.number( made.offered ),
							} ) );
							Seasons.showRound( made.data );
						} );
					},
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/** Who was offered what, and whether they have answered. */
	Seasons.showRound = function ( round ) {
		var App = Seasons.App;

		function draw() {
			var host = document.getElementById( 'r-list' );

			if ( ! host ) {
				return;
			}

			host.innerHTML = round.data.length
				? App.table(
					[
						App.t( 'panel.renewals.subscriber' ),
						App.t( 'panel.renewals.seats' ),
						App.t( 'panel.common.status' ),
					],
					round.data.map( function ( offer ) {
						return '<tr>' +
							'<td class="table__primary">' + esc( offer.name || offer.email ) +
								'<span class="muted on-own-line">' + esc( offer.email ) + '</span></td>' +
							'<td>' + esc( offer.seats.join( ' · ' ) ) + '</td>' +
							'<td>' + esc( App.t( 'panel.renewals.states.' + offer.state ) ) + '</td>' +
						'</tr>';
					} ).join( '' )
				)
				: '<p class="hint">' + esc( App.t( 'panel.renewals.nobody' ) ) + '</p>';
		}

		App.modal( {
			title: round.name,
			cancelLabel: null,
			doneLabel: App.t( 'panel.common.close' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.renewals.states.offered' ), App.number( round.counts.offered ) ) +
					tile( App.t( 'panel.renewals.states.accepted' ), App.number( round.counts.accepted ) ) +
					tile( App.t( 'panel.renewals.states.declined' ), App.number( round.counts.declined ) ) +
					tile( App.t( 'panel.renewals.states.lapsed' ), App.number( round.counts.lapsed ) ) +
				'</div>' +
				'<p class="hint">' + esc( App.t( round.live
					? 'panel.renewals.until'
					: 'panel.renewals.finished', {
					date: App.date( round.deadline, { dateStyle: 'long', timeStyle: 'short' } ),
				} ) ) + '</p>' +
				'<div class="filters">' +
					'<button type="button" class="btn" id="r-invite">' +
						esc( App.t( 'panel.renewals.invite' ) ) + '</button>' +
					( 'open' === round.state
						? '<button type="button" class="btn btn--danger" id="r-close">' +
							esc( App.t( 'panel.renewals.close' ) ) + '</button>'
						: '' ) +
				'</div>' +
				'<div id="r-list" class="spaced"></div>',
		} );

		draw();

		bind( 'r-invite', function () {
			App.request( 'POST', '/renewals/' + round.id + '/invite' )
				.then( function ( response ) {
					App.toast( App.t( 'panel.renewals.invited', {
						count: App.number( response.sent ),
					} ) );
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );

		bind( 'r-close', function () {
			App.request( 'POST', '/renewals/' + round.id + '/close' )
				.then( function ( response ) {
					App.toast( App.t( 'panel.renewals.closed' ) );
					round = response.data;
					draw();
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	Seasons.load = function () {
		var App = Seasons.App;
		var host = document.getElementById( 's-results' );

		if ( ! host ) {
			return;
		}

		var query = Object.keys( Seasons.filters )
			.filter( function ( key ) { return Seasons.filters[ key ]; } )
			.map( function ( key ) { return key + '=' + encodeURIComponent( Seasons.filters[ key ] ); } )
			.join( '&' );

		App.request( 'GET', '/season-passes' + ( query ? '?' + query : '' ) )
			.then( function ( response ) {
				host.innerHTML = Seasons.listMarkup( response );

				each( '[data-open-pass]', function ( button ) {
					button.addEventListener( 'click', function () { Seasons.open( button.dataset.openPass ); } );
				} );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Seasons.listMarkup = function ( response ) {
		var App = Seasons.App;

		if ( ! response.data.length ) {
			// A run of one night cannot carry a season ticket, so an account with no runs is told
			// what to do about it rather than shown a button that would refuse them.
			return App.emptyState(
				Seasons.filters.q ? 'search' : 'calendar',
				App.t( Seasons.filters.q
					? 'panel.seasons.noMatchTitle'
					: ( Seasons.series.length ? 'panel.seasons.noneTitle' : 'panel.seasons.noRunsTitle' ) ),
				esc( App.t( Seasons.filters.q
					? 'panel.seasons.noMatchBody'
					: ( Seasons.series.length ? 'panel.seasons.noneBody' : 'panel.seasons.noRunsBody' ) ) ),
				/*
				 * Three situations and three answers: a search found nothing, there are runs to
				 * sell a season against, or there are none — and the last one is the reason this
				 * screen used to be a dead end, since a run is made on an event and this said so
				 * in a sentence without offering the way there.
				 */
				Seasons.filters.q
					? { waiting: true }
					: ( Seasons.series.length
						? { does: 's-new', label: App.t( 'panel.seasons.create' ) }
						: App.goesTo( 'events' ) )
			);
		}

		return App.table(
			[
				App.t( 'panel.seasons.pass' ),
				App.t( 'panel.seasons.run' ),
				App.t( 'panel.seasons.saving' ),
				{ label: App.t( 'panel.seasons.nightsOnSale' ), numeric: true },
				{ label: App.t( 'panel.seasons.sold' ), numeric: true },
				App.t( 'panel.common.status' ),
				'',
			],
			response.data.map( function ( pass ) {
				return '<tr>' +
					'<td class="table__primary">' + esc( pass.name ) +
						'<span class="muted on-own-line">' +
							esc( App.t( 'panel.seasons.kinds.' + pass.kind, {
								count: App.number( pass.nights || 0 ),
							} ) ) + '</span></td>' +
					'<td>' + esc( pass.series_name || '—' ) + '</td>' +
					'<td>' + esc( Seasons.saving( pass ) ) + '</td>' +
					'<td class="tnum">' + esc( App.number( pass.nights_on_sale ) ) + '</td>' +
					'<td class="tnum">' + esc( App.number( pass.sold ) ) + '</td>' +
					'<td>' + Seasons.badge( pass ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-open-pass="' + esc( pass.id ) + '">' +
							esc( App.t( 'panel.seasons.open' ) ) + '</button>' +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Seasons.saving = function ( pass ) {
		var App = Seasons.App;

		return 'percent' === pass.discount_kind
			? App.t( 'panel.seasons.percentOff', { percent: App.number( pass.discount_value ) } )
			: App.t( 'panel.seasons.amountOff', {
				amount: App.money( pass.discount_value, pass.currency ),
			} );
	};

	/**
	 * Sellable is the honest answer, not the stored status.
	 *
	 * A pass marked active for a run whose nights have all been sells nothing, and telling an
	 * organiser it is "active" is how they wonder for a week why nobody is subscribing.
	 */
	Seasons.badge = function ( pass ) {
		var App = Seasons.App;

		if ( pass.live ) {
			return '<span class="badge badge--ok">' + esc( App.t( 'panel.seasons.onSale' ) ) + '</span>';
		}

		var why = 'paused' === pass.status
			? 'paused'
			: ( pass.nights_on_sale > 1 ? 'notNow' : 'noNights' );

		return '<span class="badge badge--neutral">' +
			esc( App.t( 'panel.seasons.' + why ) ) + '</span>';
	};

	/* ----------------------------------------------------------------------- one pass */

	Seasons.open = function ( id ) {
		var App = Seasons.App;

		App.loading( App.t( 'panel.seasons.title' ) );

		App.request( 'GET', '/season-passes/' + id )
			.then( function ( pass ) {
				Seasons.pass = pass;
				Seasons.paintPass();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Seasons.paintPass = function () {
		var App = Seasons.App;
		var pass = Seasons.pass;
		var taken = pass.bookings.reduce( function ( sum, booking ) {
			return sum + ( 'cancelled' === booking.status ? 0 : booking.total );
		}, 0 );

		App.page( {
			title: pass.name,
			description: esc( pass.description || App.t( 'panel.seasons.noDescription' ) ),
			actions:
				'<button class="btn" id="s-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'panel.seasons.back' ) ) + '</button>' +
				'<button class="btn" id="s-toggle">' +
					esc( App.t( 'active' === pass.status
						? 'panel.seasons.pause'
						: 'panel.seasons.resume' ) ) + '</button>' +
				'<button class="btn" id="s-edit">' + icon( 'edit', { size: 15 } ) +
					esc( App.t( 'panel.seasons.edit' ) ) + '</button>' +
				( pass.sold
					? ''
					: '<button class="btn btn--danger" id="s-delete">' +
						esc( App.t( 'panel.seasons.delete' ) ) + '</button>' ),
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.seasons.run' ), pass.series_name || '—' ) +
					tile( App.t( 'panel.seasons.nightsOnSale' ), App.number( pass.nights_on_sale ),
						App.t( 'panel.seasons.kinds.' + pass.kind, {
							count: App.number( pass.nights || 0 ),
						} ) ) +
					tile( App.t( 'panel.seasons.saving' ), Seasons.saving( pass ) ) +
					tile( App.t( 'panel.seasons.taken' ), App.money( taken, pass.currency ),
						App.t( 'panel.seasons.soldCount', { count: App.number( pass.sold ) } ) ) +
				'</div>' +
				'<h3 class="subhead">' + esc( App.t( 'panel.seasons.subscribers' ) ) + '</h3>' +
				( pass.bookings.length
					? App.table(
						[
							App.t( 'panel.orders.reference' ),
							App.t( 'panel.orders.buyer' ),
							{ label: App.t( 'panel.seasons.seats' ), numeric: true },
							{ label: App.t( 'panel.seasons.nights' ), numeric: true },
							{ label: App.t( 'panel.seasons.total' ), numeric: true },
							App.t( 'panel.common.status' ),
						],
						pass.bookings.map( function ( booking ) {
							return '<tr>' +
								'<td class="table__primary"><code>' + esc( booking.reference ) + '</code></td>' +
								'<td>' + esc( booking.buyer || '—' ) + '</td>' +
								'<td class="tnum">' + esc( App.number( booking.seats ) ) + '</td>' +
								'<td class="tnum">' + esc( App.number( booking.nights ) ) + '</td>' +
								'<td class="tnum">' + esc( App.money( booking.total, pass.currency ) ) +
									'<span class="muted on-own-line">−' +
									esc( App.money( booking.discount, pass.currency ) ) + '</span></td>' +
								'<td>' + esc( App.t( 'panel.seasons.bookingStatus.' + booking.status ) ) + '</td>' +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="hint">' + esc( App.t( 'panel.seasons.noSubscribers' ) ) + '</p>' ),
		} );

		bind( 's-back', function () { Seasons.render( App ); } );
		bind( 's-edit', function () { Seasons.form( pass ); } );
		bind( 's-toggle', function () { Seasons.toggle(); } );
		bind( 's-delete', function () { Seasons.remove(); } );
	};

	Seasons.toggle = function () {
		var App = Seasons.App;
		var pass = Seasons.pass;
		var next = 'active' === pass.status ? 'paused' : 'active';

		App.request( 'PATCH', '/season-passes/' + pass.id, { status: next } )
			.then( function () {
				App.toast( App.t( 'panel.seasons.' + ( 'paused' === next ? 'pausedNow' : 'resumedNow' ) ) );
				Seasons.open( pass.id );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Seasons.remove = function () {
		var App = Seasons.App;

		App.modal( {
			title: App.t( 'panel.seasons.deleteTitle' ),
			submitLabel: App.t( 'panel.seasons.delete' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.seasons.deleteBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/season-passes/' + Seasons.pass.id )
					.then( function () {
						App.toast( App.t( 'panel.seasons.deleted' ) );
						Seasons.render( App );
					} );
			},
		} );
	};

	/* ----------------------------------------------------------------------- the form */

	Seasons.form = function ( pass ) {
		var App = Seasons.App;
		var editing = !! pass;

		App.modal( {
			title: App.t( editing ? 'panel.seasons.editTitle' : 'panel.seasons.createTitle' ),
			submitLabel: App.t( editing ? 'panel.common.save' : 'panel.seasons.create' ),
			body:
				'<div class="stack">' +
				( editing
					? '<p class="hint">' + esc( App.t( 'panel.seasons.runIsFixed', {
						name: pass.series_name || '',
					} ) ) + '</p>'
					: '<div class="field">' +
						'<label class="field__label" for="s-run">' +
							esc( App.t( 'panel.seasons.run' ) ) + '</label>' +
						'<select class="select" id="s-run">' +
							Seasons.series.map( function ( run ) {
								return '<option value="' + esc( run.id ) + '">' + esc( run.name ) +
									' · ' + esc( App.t( 'panel.seasons.nightsIn', {
										count: App.number( run.nights ),
									} ) ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>' ) +

				'<div class="field">' +
					'<label class="field__label" for="s-name">' +
						esc( App.t( 'panel.seasons.name' ) ) + '</label>' +
					'<input class="input" id="s-name" maxlength="160" required value="' +
						esc( ( pass && pass.name ) || '' ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'panel.seasons.nameHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="s-description">' +
						esc( App.t( 'panel.seasons.blurb' ) ) + '</label>' +
					'<input class="input" id="s-description" maxlength="400" value="' +
						esc( ( pass && pass.description ) || '' ) + '">' +
				'</div>' +

				'<div class="field">' +
					'<label class="field__label" for="s-kind">' +
						esc( App.t( 'panel.seasons.whatItCovers' ) ) + '</label>' +
					'<select class="select" id="s-kind">' +
						[ 'all', 'choose' ].map( function ( kind ) {
							return '<option value="' + kind + '"' +
								( pass && kind === pass.kind ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.seasons.kindNames.' + kind ) ) + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<span class="field__hint" id="s-kind-hint"></span>' +
				'</div>' +

				'<div class="field" id="s-nights-field" hidden>' +
					'<label class="field__label" for="s-nights">' +
						esc( App.t( 'panel.seasons.howManyNights' ) ) + '</label>' +
					'<input class="input" id="s-nights" type="number" min="2" value="' +
						esc( ( pass && pass.nights ) || 2 ) + '">' +
					'<span class="field__hint">' + esc( App.t( 'panel.seasons.howManyNightsHint' ) ) + '</span>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="s-discount-kind">' +
							esc( App.t( 'panel.seasons.saving' ) ) + '</label>' +
						'<select class="select" id="s-discount-kind">' +
							[ 'percent', 'fixed' ].map( function ( kind ) {
								return '<option value="' + kind + '"' +
									( pass && kind === pass.discount_kind ? ' selected' : '' ) + '>' +
									esc( App.t( 'panel.seasons.savingKinds.' + kind ) ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="s-discount-value">' +
							esc( App.t( 'panel.seasons.savingValue' ) ) + '</label>' +
						'<input class="input" id="s-discount-value" type="number" min="0" ' +
							'inputmode="decimal" value="' + esc( Seasons.typedValue( pass ) ) + '">' +
						'<span class="field__hint" id="s-value-hint"></span>' +
					'</div>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="s-currency">' +
							esc( App.t( 'panel.seasons.currency' ) ) + '</label>' +
						'<input class="input" id="s-currency" maxlength="3" value="' +
							esc( ( pass && pass.currency ) || 'EUR' ) + '">' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="s-max-seats">' +
							esc( App.t( 'panel.seasons.maxSeats' ) ) + '</label>' +
						'<input class="input" id="s-max-seats" type="number" min="1" value="' +
							esc( ( pass && pass.max_seats ) || 6 ) + '">' +
						'<span class="field__hint">' + esc( App.t( 'panel.seasons.maxSeatsHint' ) ) + '</span>' +
					'</div>' +
				'</div>' +

				'<div class="field-duo">' +
					'<div class="field">' +
						'<label class="field__label" for="s-on-sale">' +
							esc( App.t( 'panel.seasons.onSaleFrom' ) ) + '</label>' +
						'<input class="input" id="s-on-sale" type="datetime-local" value="' +
							esc( localDateTime( pass && pass.on_sale_at ) ) + '">' +
					'</div>' +
					'<div class="field">' +
						'<label class="field__label" for="s-off-sale">' +
							esc( App.t( 'panel.seasons.offSaleAt' ) ) + '</label>' +
						'<input class="input" id="s-off-sale" type="datetime-local" value="' +
							esc( localDateTime( pass && pass.off_sale_at ) ) + '">' +
					'</div>' +
				'</div>' +
				'</div>',
			onSubmit: function () {
				var kind = value( 's-kind' );
				var currency = ( value( 's-currency' ) || 'EUR' ).toUpperCase();
				var savingKind = value( 's-discount-kind' );
				var typed = parseFloat( value( 's-discount-value' ) ) || 0;

				var payload = {
					name: value( 's-name' ),
					description: value( 's-description' ) || null,
					kind: kind,
					nights: 'choose' === kind ? parseInt( value( 's-nights' ), 10 ) || 2 : null,
					discount_kind: savingKind,
					// A percentage is a percentage; an amount is typed in the currency's own units
					// and sent in minor ones, through the same conversion the pricing screen uses.
					discount_value: 'percent' === savingKind
						? Math.round( typed )
						: minorUnits( typed, currency ),
					currency: currency,
					max_seats: parseInt( value( 's-max-seats' ), 10 ) || 6,
					on_sale_at: isoOrNull( value( 's-on-sale' ) ),
					off_sale_at: isoOrNull( value( 's-off-sale' ) ),
				};

				if ( ! editing ) {
					payload.series_id = value( 's-run' );
				}

				var request = editing
					? App.request( 'PATCH', '/season-passes/' + pass.id, payload )
					: App.request( 'POST', '/season-passes', payload );

				return request.then( function ( saved ) {
					App.toast( App.t( editing ? 'panel.seasons.saved' : 'panel.seasons.created' ) );
					Seasons.open( saved.id );
				} );
			},
		} );

		var kind = document.getElementById( 's-kind' );
		var savingKind = document.getElementById( 's-discount-kind' );
		var currency = document.getElementById( 's-currency' );

		function explain() {
			document.getElementById( 's-kind-hint' ).textContent =
				App.t( 'panel.seasons.kindHints.' + kind.value );
			document.getElementById( 's-nights-field' ).hidden = 'choose' !== kind.value;
		}

		function restep() {
			var box = document.getElementById( 's-discount-value' );
			var percent = 'percent' === savingKind.value;

			box.step = percent
				? '1'
				: global.SeatmapPricing.step( ( currency.value || 'EUR' ).toUpperCase() );
			box.max = percent ? '100' : '';
			document.getElementById( 's-value-hint' ).textContent =
				App.t( 'panel.seasons.savingHints.' + savingKind.value );
		}

		kind.addEventListener( 'change', explain );
		savingKind.addEventListener( 'change', restep );
		currency.addEventListener( 'input', restep );
		explain();
		restep();
	};

	/** What the saving box should show: a percentage as itself, an amount in its own units. */
	Seasons.typedValue = function ( pass ) {
		if ( ! pass ) {
			return '';
		}

		if ( 'percent' === pass.discount_kind ) {
			return pass.discount_value;
		}

		var decimals = global.SeatmapI18n.currencyDecimals( pass.currency );

		return decimals
			? ( pass.discount_value / Math.pow( 10, decimals ) ).toFixed( decimals )
			: String( pass.discount_value );
	};

	/* ----------------------------------------------------------------------- helpers */

	function minorUnits( typed, currency ) {
		var decimals = global.SeatmapI18n.currencyDecimals( currency );

		return Math.round( ( parseFloat( typed ) || 0 ) * Math.pow( 10, decimals ) );
	}

	function localDateTime( iso ) {
		if ( ! iso ) {
			return '';
		}

		var when = new Date( iso );
		var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };

		return when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() ) +
			'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );
	}

	function isoOrNull( local ) {
		return local ? new Date( local ).toISOString() : null;
	}

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

	function tile( label, value, hint ) {
		return '<div class="tile tile--static">' +
			'<span class="tile__label">' + esc( label ) + '</span>' +
			'<span class="tile__value tnum">' + esc( value ) + '</span>' +
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

	global.SeatmapSeasons = Seasons;
}( typeof window !== 'undefined' ? window : globalThis ) );
