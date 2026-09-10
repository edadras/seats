/**
 * One show, however many nights and however many towns.
 *
 * A run in one building was always a list of dates. A tour is a list of places, and the questions
 * an organiser asks about it are different: which towns is it in, which of them is selling, and
 * where does it go next. So the table is the dates with their halls beside them, the strip at the
 * top is the run added up, and the button that matters puts the same show somewhere else.
 */
( function ( global ) {
	'use strict';

	var Productions = {};

	Productions.render = function ( App ) {
		Productions.App = App;
		App.loading( App.t( 'panel.nav.productions' ) );

		App.request( 'GET', '/productions' )
			.then( function ( response ) {
				Productions.list = response.data || [];
				Productions.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Productions.paint = function () {
		var App = Productions.App;

		App.page( {
			title: App.t( 'panel.nav.productions' ),
			description: App.t( 'panel.productions.subtitle' ),
			actions: '<button class="btn btn--primary" id="production-add">' +
				esc( App.t( 'panel.productions.add' ) ) + '</button>',
			body: Productions.list.length
				? App.table(
					[
						App.t( 'panel.productions.name' ),
						App.t( 'panel.productions.dates' ),
						App.t( 'panel.productions.towns' ),
						App.t( 'panel.productions.next' ),
						{ label: App.t( 'panel.productions.sold' ), numeric: true },
						'',
					],
					Productions.list.map( function ( row ) {
						return '<tr>' +
							'<td class="table__primary">' + esc( row.name ) +
								( row.category
									? '<span class="muted on-own-line">' + esc( row.category ) + '</span>'
									: '' ) + '</td>' +
							'<td>' + esc( App.number( row.totals.dates ) ) + '</td>' +
							'<td>' + esc( App.number( row.cities || 0 ) ) + '</td>' +
							'<td class="nowrap">' + ( row.next_date
								? esc( App.date( row.next_date ) ) +
									( row.next_city
										? '<span class="muted on-own-line">' + esc( row.next_city ) + '</span>'
										: '' )
								: '<span class="muted">—</span>' ) + '</td>' +
							'<td class="tnum">' + esc( App.number( row.totals.sold ) ) + ' / ' +
								esc( App.number( row.totals.capacity ) ) + '</td>' +
							'<td><button class="btn btn--sm" data-production="' + esc( row.id ) + '">' +
								esc( App.t( 'panel.productions.open' ) ) + '</button></td>' +
						'</tr>';
					} ).join( '' )
				)
				: '<p class="muted">' + esc( App.t( 'panel.productions.none' ) ) + '</p>',
		} );

		bind( 'production-add', function () { Productions.create(); } );

		each( '[data-production]', function ( button ) {
			button.addEventListener( 'click', function () { Productions.open( button.dataset.production ); } );
		} );
	};

	/* ------------------------------------------------------------------------- one production */

	Productions.open = function ( id ) {
		var App = Productions.App;

		App.loading( App.t( 'panel.nav.productions' ) );

		Promise.all( [
			App.request( 'GET', '/productions/' + id ),
			App.request( 'GET', '/venues?per_page=100' ).catch( function () { return { data: [] }; } ),
			App.request( 'GET', '/seat-maps?per_page=100' ).catch( function () { return { data: [] }; } ),
			// A tour has to start somewhere, and what somebody has in front of them when they
			// start one is the show they already put on at home.
			App.request( 'GET', '/events?per_page=100' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( answers ) {
			Productions.one = answers[ 0 ];
			Productions.venues = answers[ 1 ].data || [];
			Productions.events = answers[ 3 ].data || [];
			// Only charts anybody could sell in: an unpublished one has no seats yet, and offering
			// it would be offering a date that cannot go on sale.
			Productions.maps = ( answers[ 2 ].data || [] ).filter( function ( map ) {
				return !! map.published_version;
			} );
			Productions.paintOne();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	Productions.paintOne = function () {
		var App = Productions.App;
		var run = Productions.one;
		var money = run.totals.revenue !== null && run.totals.revenue !== undefined;
		var currency = ( run.dates[ 0 ] || {} ).currency || 'EUR';

		App.page( {
			title: run.name,
			description: esc( run.description || App.t( 'panel.productions.noDescription' ) ),
			actions:
				'<button class="btn" id="production-back">' +
					esc( App.t( 'panel.productions.all' ) ) + '</button>' +
				'<button class="btn" id="production-edit">' +
					esc( App.t( 'panel.common.edit' ) ) + '</button>' +
				'<button class="btn btn--primary" id="production-date">' +
					esc( App.t( 'panel.productions.addDate' ) ) + '</button>',
			body:
				'<div class="stat-strip">' +
					tile( App.t( 'panel.productions.dates' ), App.number( run.totals.dates ),
						App.t( 'panel.productions.inTowns', { count: App.number( run.cities || 0 ) } ) ) +
					tile( App.t( 'panel.productions.sold' ), App.number( run.totals.sold ),
						App.t( 'panel.productions.ofCapacity', {
							count: App.number( run.totals.capacity ),
						} ) ) +
					tile( App.t( 'panel.productions.available' ), App.number( run.totals.available ), '' ) +
					// Withheld rather than shown as nought: a figure somebody may not see should
					// not be guessable from a screen.
					( money
						? tile( App.t( 'panel.productions.takings' ),
							App.money( run.totals.revenue, currency ), '' )
						: '' ) +
				'</div>' +

				( run.dates.length
					? App.table(
						[
							App.t( 'panel.productions.when' ),
							App.t( 'panel.productions.where' ),
							App.t( 'panel.common.status' ),
							{ label: App.t( 'panel.productions.sold' ), numeric: true },
						].concat( money
							? [ { label: App.t( 'panel.productions.takings' ), numeric: true } ]
							: [] ),
						run.dates.map( function ( date ) {
							return '<tr>' +
								'<td class="nowrap tnum">' + esc( App.date( date.starts_at ) ) + '</td>' +
								'<td class="table__primary">' + esc( date.venue || '—' ) +
									( date.city
										? '<span class="muted on-own-line">' + esc( date.city ) + '</span>'
										: '' ) + '</td>' +
								'<td>' + esc( App.t( 'panel.eventStatus.' + date.status ) ) + '</td>' +
								'<td class="tnum">' + esc( App.number( date.sold ) ) + ' / ' +
									esc( App.number( date.capacity ) ) + '</td>' +
								( money
									? '<td class="tnum">' +
										esc( App.money( date.revenue || 0, date.currency ) ) + '</td>'
									: '' ) +
							'</tr>';
						} ).join( '' )
					)
					: '<p class="muted">' + esc( App.t( 'panel.productions.noDates' ) ) + '</p>' ),
		} );

		bind( 'production-back', function () { Productions.render( App ); } );
		bind( 'production-edit', function () { Productions.edit(); } );
		bind( 'production-date', function () { Productions.addDate(); } );
	};

	/* ------------------------------------------------------------------------------ the forms */

	Productions.create = function () {
		var App = Productions.App;

		App.modal( {
			title: App.t( 'panel.productions.add' ),
			submitLabel: App.t( 'panel.common.save' ),
			body: fields( App, {} ),
			onSubmit: function () {
				return App.request( 'POST', '/productions', values() ).then( function () {
					App.toast( App.t( 'panel.productions.added' ) );
					Productions.render( App );
				} );
			},
		} );
	};

	Productions.edit = function () {
		var App = Productions.App;
		var run = Productions.one;

		App.modal( {
			title: run.name,
			submitLabel: App.t( 'panel.common.save' ),
			body: fields( App, run ),
			onSubmit: function () {
				return App.request( 'PATCH', '/productions/' + run.id, values() ).then( function () {
					App.toast( App.t( 'panel.productions.saved' ) );
					Productions.open( run.id );
				} );
			},
		} );
	};

	/**
	 * The same show, somewhere else.
	 *
	 * The chart list follows the building, because a chart belongs to one: offering every chart in
	 * the account would be offering to sell Glasgow's stalls in Leeds.
	 */
	Productions.addDate = function () {
		var App = Productions.App;
		var run = Productions.one;

		App.modal( {
			title: App.t( 'panel.productions.addDate' ),
			submitLabel: App.t( 'panel.productions.addDate' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.productions.addDateBody' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="date-venue">' +
						esc( App.t( 'panel.productions.venue' ) ) + '</label>' +
						'<select class="select" id="date-venue">' +
							Productions.venues.map( function ( venue ) {
								return '<option value="' + esc( venue.id ) + '">' + esc( venue.name ) +
									( venue.city ? ' — ' + esc( venue.city ) : '' ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					'<div class="field"><label class="field__label" for="date-map">' +
						esc( App.t( 'panel.productions.map' ) ) + '</label>' +
						'<select class="select" id="date-map"></select></div>' +
					'<div class="field"><label class="field__label" for="date-when">' +
						esc( App.t( 'panel.productions.when' ) ) + '</label>' +
						'<input class="input" id="date-when" type="datetime-local" required></div>' +
					// Which night this one is a copy of. Left alone it is the run's most recent
					// date — the one whose prices somebody last thought about.
					'<div class="field"><label class="field__label" for="date-from">' +
						esc( App.t( 'panel.productions.copyFrom' ) ) + '</label>' +
						'<select class="select" id="date-from">' +
							( run.dates.length
								? '<option value="">' +
									esc( App.t( 'panel.productions.copyLatest' ) ) + '</option>'
								: '' ) +
							Productions.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '">' + esc( event.name ) +
									' — ' + esc( App.date( event.starts_at ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
				'</div>',
			onSubmit: function () {
				var when = document.getElementById( 'date-when' ).value;
				var map = document.getElementById( 'date-map' ).value;

				if ( ! when || ! map ) {
					App.toast( App.t( 'panel.productions.needsWhenAndWhere' ), true );

					return true;
				}

				return App.request( 'POST', '/productions/' + run.id + '/dates', {
					venue_id: document.getElementById( 'date-venue' ).value,
					seat_map_id: map,
					starts_at: when,
					from_event_id: document.getElementById( 'date-from' ).value || null,
				} ).then( function () {
					App.toast( App.t( 'panel.productions.dateAdded' ) );
					Productions.open( run.id );
				} );
			},
		} );

		var venue = document.getElementById( 'date-venue' );

		var charts = function () {
			var host = document.getElementById( 'date-map' );
			var mine = Productions.maps.filter( function ( map ) {
				return map.venue_id === venue.value;
			} );

			host.innerHTML = mine.length
				? mine.map( function ( map ) {
					return '<option value="' + esc( map.id ) + '">' + esc( map.name ) + '</option>';
				} ).join( '' )
				: '<option value="">' + esc( App.t( 'panel.productions.noMap' ) ) + '</option>';
		};

		venue.addEventListener( 'change', charts );
		charts();
	};

	/* ------------------------------------------------------------------------------ helpers */

	function fields( App, run ) {
		return '<div class="stack">' +
			'<div class="field"><label class="field__label" for="run-name">' +
				esc( App.t( 'panel.productions.name' ) ) + '</label>' +
				'<input class="input" id="run-name" maxlength="200" required value="' +
				esc( run.name || '' ) + '"></div>' +
			'<div class="field"><label class="field__label" for="run-category">' +
				esc( App.t( 'panel.productions.category' ) ) + '</label>' +
				'<input class="input" id="run-category" maxlength="60" value="' +
				esc( run.category || '' ) + '"></div>' +
			'<div class="field"><label class="field__label" for="run-image">' +
				esc( App.t( 'panel.productions.image' ) ) + '</label>' +
				'<input class="input" id="run-image" maxlength="500" value="' +
				esc( run.image_url || '' ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.productions.imageHint' ) ) +
				'</span></div>' +
			'<div class="field"><label class="field__label" for="run-description">' +
				esc( App.t( 'panel.productions.description' ) ) + '</label>' +
				'<textarea class="input" id="run-description" rows="4" maxlength="4000">' +
				esc( run.description || '' ) + '</textarea>' +
				'<span class="field__hint">' + esc( App.t( 'panel.productions.descriptionHint' ) ) +
				'</span></div>' +
		'</div>';
	}

	function values() {
		return {
			name: document.getElementById( 'run-name' ).value.trim(),
			category: document.getElementById( 'run-category' ).value.trim() || null,
			image_url: document.getElementById( 'run-image' ).value.trim() || null,
			description: document.getElementById( 'run-description' ).value.trim() || null,
		};
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

	global.SeatmapProductions = Productions;
}( typeof window !== 'undefined' ? window : globalThis ) );
