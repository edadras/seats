/**
 * Reports: pick a dataset, drag what to group by and what to count, look at the answer.
 *
 * There is no query box here and there will not be one (ADR-0006). Every field on this screen came
 * from the source's own declaration, which is also what the server validates against — so the
 * builder cannot offer a field the runner would refuse, and neither can accept one nobody
 * declared. Dragging changes which declared field is in which shelf; it can never invent one.
 *
 * Two things about the dragging are deliberate:
 *
 *   - Every drag has a button that does the same thing. A field chip adds on click, a shelf pill
 *     can be moved with its two arrows and removed with its cross. Somebody working from the
 *     keyboard, or on a phone, builds the same report by pressing rather than dragging.
 *   - Nothing is dropped into a shelf that the shelf did not declare: a measure dragged onto
 *     "group by" is refused by the drop handler, not by the server afterwards.
 *
 * The chart is drawn here rather than fetched: a report is at most a thousand rows, and a charting
 * library is 200KB of somebody else's opinions about tooltips.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var VIEWS = [ 'table', 'bar', 'line', 'stat' ];
	var WIDTHS = [ 'third', 'half', 'full' ];
	var LIMITS = [ 25, 100, 500, 1000 ];

	var Reports = {
		App: null,
		sources: [],
		saved: [],
		pages: [],
		events: [],
		maxRows: 1000,

		// The report being built.
		draft: null,
		result: null,
		view: 'list',

		// The page being arranged, and the timer that writes it back.
		page: null,
		saveTimer: null,
		saveState: '',
	};

	/* ---------------------------------------------------------------------------- the list */

	Reports.render = function ( App ) {
		Reports.App = App;
		Reports.view = 'list';

		App.loading( App.t( 'reports.title' ) );

		Promise.all( [
			App.request( 'GET', '/reports/sources' ),
			App.request( 'GET', '/reports' ),
			App.request( 'GET', '/report-pages' ),
			// Schedules, quietly: a listing that failed would be an error on a screen whose real
			// business is the reports above it.
			App.request( 'GET', '/report-schedules' ).catch( function () { return { data: [] }; } ),
			// The events an event filter offers. Fetched rather than typed: an id in a box is a
			// way to point a report at something that is not yours and be told off for it.
			App.request( 'GET', '/events?per_page=100' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( results ) {
			Reports.sources = results[ 0 ].data || [];
			Reports.maxRows = results[ 0 ].max_rows || 1000;
			Reports.saved = results[ 1 ].data || [];
			Reports.pages = results[ 2 ].data || [];
			Reports.schedules = results[ 3 ].data || [];
			Reports.events = results[ 4 ].data || [];
			Reports.paintList();
		} ).catch( function ( error ) { App.error( error ); } );
	};

	/**
	 * The reports that go out on their own.
	 *
	 * Under the saved ones rather than on a screen of their own: a schedule is a property of a
	 * report, and an organiser deciding whether Monday's figures are arriving is looking at the
	 * report it came from. Hidden entirely when there are none — an empty table of a thing most
	 * accounts never use is furniture.
	 */
	Reports.schedulesMarkup = function ( App ) {
		var rows = Reports.schedules || [];

		if ( ! rows.length ) {
			return '';
		}

		return '<h3 class="subhead">' + esc( App.t( 'reports.schedules' ) ) + '</h3>' +
			App.table(
				[
					App.t( 'reports.name' ),
					App.t( 'reports.when' ),
					App.t( 'reports.recipients' ),
					App.t( 'reports.nextOne' ),
					'',
				],
				rows.map( function ( row ) {
					return '<tr' + ( row.paused ? ' class="is-muted"' : '' ) + '>' +
						'<td class="table__primary">' + esc( row.name || row.report || '—' ) +
							( row.paused
								? ' <span class="badge">' + esc( App.t( 'reports.paused' ) ) + '</span>'
								: '' ) +
							// What went wrong last time, on the row it went wrong on. A schedule
							// that quietly stopped arriving is the failure nobody notices.
							( row.last_error
								? '<span class="muted on-own-line">' + esc( row.last_error ) + '</span>'
								: '' ) + '</td>' +
						'<td>' + esc( Reports.cadenceLabel( App, row ) ) + '</td>' +
						'<td class="muted">' + esc( ( row.recipients || [] ).join( ', ' ) ) + '</td>' +
						'<td class="muted nowrap tnum">' + esc( row.next_run_at
							? App.date( row.next_run_at )
							: '—' ) + '</td>' +
						'<td class="table__actions">' +
							'<button class="btn btn--sm" data-schedule-send="' + esc( row.id ) + '">' +
								esc( App.t( 'reports.sendNow' ) ) + '</button>' +
							'<button class="btn btn--sm" data-schedule-edit="' + esc( row.id ) + '">' +
								esc( App.t( 'reports.edit' ) ) + '</button>' +
							'<button class="btn btn--sm" data-schedule-delete="' + esc( row.id ) + '">' +
								esc( App.t( 'reports.unschedule' ) ) + '</button>' +
						'</td>' +
					'</tr>';
				} ).join( '' )
			);
	};

	/** "Every Monday at 08:00", in as many words as it takes to be unambiguous. */
	Reports.cadenceLabel = function ( App, row ) {
		var hour = String( row.hour === null || row.hour === undefined ? 8 : row.hour );
		var at = App.t( 'reports.atHour', { hour: ( '0' + hour ).slice( -2 ) } );

		if ( 'weekly' === row.cadence ) {
			return App.t( 'reports.everyWeekday', {
				day: App.t( 'reports.weekdays.' + ( row.weekday || 1 ) ),
			} ) + ' ' + at;
		}

		if ( 'monthly' === row.cadence ) {
			return App.t( 'reports.everyMonth', { day: App.number( row.day_of_month || 1 ) } ) + ' ' + at;
		}

		return App.t( 'reports.everyDay' ) + ' ' + at;
	};

	/**
	 * Put a report on a timer, or change one that is already on it.
	 *
	 * The recipients are typed as addresses rather than picked from the team, and that is the
	 * point: the people who want Monday's figures are often a marketing agency, a board member or
	 * an auditor, none of whom have an account here. Whoever sets it is vouching for them, which is
	 * why the audit log records who did it.
	 */
	Reports.scheduleReport = function ( reportId, existing ) {
		var App = Reports.App;
		var row = existing || {};
		var cadence = row.cadence || 'weekly';

		App.modal( {
			title: App.t( existing ? 'reports.scheduleEditTitle' : 'reports.scheduleTitle' ),
			submitLabel: App.t( 'reports.save' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'reports.scheduleBody' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="sch-name">' +
						esc( App.t( 'reports.scheduleName' ) ) + '</label>' +
						'<input class="input" id="sch-name" maxlength="120" value="' +
							esc( row.name || '' ) + '"></div>' +
					'<div class="field-duo">' +
						'<div class="field"><label class="field__label" for="sch-cadence">' +
							esc( App.t( 'reports.when' ) ) + '</label>' +
							'<select class="select" id="sch-cadence">' +
								[ 'daily', 'weekly', 'monthly' ].map( function ( key ) {
									return '<option value="' + key + '"' +
										( key === cadence ? ' selected' : '' ) + '>' +
										esc( App.t( 'reports.cadences.' + key ) ) + '</option>';
								} ).join( '' ) +
							'</select></div>' +
						'<div class="field"><label class="field__label" for="sch-hour">' +
							esc( App.t( 'reports.hour' ) ) + '</label>' +
							'<select class="select" id="sch-hour">' +
								Array.apply( null, { length: 24 } ).map( function ( ignored, hour ) {
									return '<option value="' + hour + '"' +
										( hour === ( row.hour === undefined ? 8 : row.hour ) ? ' selected' : '' ) +
										'>' + ( '0' + hour ).slice( -2 ) + ':00</option>';
								} ).join( '' ) +
							'</select></div>' +
					'</div>' +
					'<div class="field-duo">' +
						'<div class="field" id="sch-weekday-field"><label class="field__label" for="sch-weekday">' +
							esc( App.t( 'reports.weekday' ) ) + '</label>' +
							'<select class="select" id="sch-weekday">' +
								[ 1, 2, 3, 4, 5, 6, 7 ].map( function ( day ) {
									return '<option value="' + day + '"' +
										( day === ( row.weekday || 1 ) ? ' selected' : '' ) + '>' +
										esc( App.t( 'reports.weekdays.' + day ) ) + '</option>';
								} ).join( '' ) +
							'</select></div>' +
						'<div class="field" id="sch-day-field"><label class="field__label" for="sch-day">' +
							esc( App.t( 'reports.dayOfMonth' ) ) + '</label>' +
							'<input class="input" id="sch-day" type="number" min="1" max="28" value="' +
								esc( row.day_of_month || 1 ) + '">' +
							// 28 rather than 31: a report set for the 31st would skip February and
							// half the year besides, and "it never arrived" is the worst failure a
							// scheduled report has.
							'<span class="field__hint">' + esc( App.t( 'reports.dayOfMonthHint' ) ) + '</span></div>' +
					'</div>' +
					'<div class="field"><label class="field__label" for="sch-to">' +
						esc( App.t( 'reports.recipients' ) ) + '</label>' +
						'<textarea class="input" id="sch-to" rows="3">' +
							esc( ( row.recipients || [] ).join( '\n' ) ) + '</textarea>' +
						'<span class="field__hint">' + esc( App.t( 'reports.recipientsHint' ) ) + '</span></div>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="sch-link"' +
						( false === row.include_link ? '' : ' checked' ) + '>' +
						'<span>' + esc( App.t( 'reports.includeLink' ) ) +
							'<span class="muted on-own-line">' +
							esc( App.t( 'reports.includeLinkHint' ) ) + '</span></span></label>' +
					'<label class="perms__row"><input type="checkbox" class="checkbox" id="sch-paused"' +
						( row.paused ? ' checked' : '' ) + '>' +
						'<span>' + esc( App.t( 'reports.pauseIt' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var payload = {
					cadence: value( 'sch-cadence' ),
					hour: Number( value( 'sch-hour' ) ),
					weekday: Number( value( 'sch-weekday' ) ),
					day_of_month: Number( value( 'sch-day' ) ),
					name: value( 'sch-name' ) || null,
					recipients: value( 'sch-to' ).split( /[\n,;]+/ ).map( function ( one ) {
						return one.trim();
					} ).filter( Boolean ),
					include_link: checked( 'sch-link' ),
					paused: checked( 'sch-paused' ),
				};

				var request = existing
					? App.request( 'PATCH', '/report-schedules/' + existing.id, payload )
					: App.request( 'POST', '/report-schedules', Object.assign( payload, {
						report_id: reportId,
						// The clock the venue works in, taken from the browser rather than asked
						// for: nobody sets up a Monday report thinking about UTC.
						timezone: App.timezone(),
					} ) );

				return request.then( function () {
					App.toast( App.t( 'reports.scheduleSaved' ) );
					Reports.render( App );
				} );
			},
		} );

		// Only the field the chosen cadence actually uses. A weekday box on a daily report is a
		// question with no answer.
		var showRelevant = function () {
			var chosen = value( 'sch-cadence' );

			document.getElementById( 'sch-weekday-field' ).hidden = 'weekly' !== chosen;
			document.getElementById( 'sch-day-field' ).hidden = 'monthly' !== chosen;
		};

		document.getElementById( 'sch-cadence' ).addEventListener( 'change', showRelevant );
		showRelevant();
	};

	Reports.paintList = function () {
		var App = Reports.App;

		App.page( {
			title: App.t( 'reports.title' ),
			description: App.t( 'reports.subtitle' ),
			actions:
				'<button class="btn" id="report-new-page">' + icon( 'plus', { size: 15 } ) +
					esc( App.t( 'reports.newPage' ) ) + '</button>' +
				'<button class="btn btn--primary" id="report-new">' + icon( 'plus', { size: 15 } ) +
					esc( App.t( 'reports.newReport' ) ) + '</button>',
			body:
				'<h3 class="subhead">' + esc( App.t( 'reports.saved' ) ) + '</h3>' +
				( Reports.saved.length
					? App.table(
						[ App.t( 'reports.name' ), App.t( 'reports.source' ), '' ],
						Reports.saved.map( function ( report ) {
							return '<tr><td class="table__primary">' + esc( report.name ) + '</td>' +
								'<td>' + esc( Reports.sourceName( report.source ) ) + '</td>' +
								'<td class="table__actions">' +
									'<button class="btn btn--sm" data-open="' + esc( report.id ) + '">' +
										esc( App.t( 'reports.open' ) ) + '</button>' +
									'<button class="btn btn--sm" data-schedule="' + esc( report.id ) + '">' +
										esc( App.t( 'reports.schedule' ) ) + '</button>' +
									'<button class="btn btn--sm" data-delete="' + esc( report.id ) + '">' +
										esc( App.t( 'reports.delete' ) ) + '</button>' +
								'</td></tr>';
						} ).join( '' )
					)
					: App.emptyState( 'chart', App.t( 'reports.noReports' ), App.t( 'reports.noReportsHint' ) ) ) +

				Reports.schedulesMarkup( App ) +

				'<h3 class="subhead">' + esc( App.t( 'reports.pages' ) ) + '</h3>' +
				( Reports.pages.length
					? '<div class="look-grid">' + Reports.pages.map( function ( page ) {
						return '<button class="block-card" data-page="' + esc( page.id ) + '">' +
							'<span class="block-card__name">' + esc( page.name ) + '</span>' +
							'<span class="block-card__meta">' +
								esc( App.t( 'reports.widgetCount', {
									count: App.number( ( page.widgets || [] ).length ),
								} ) ) + '</span>' +
						'</button>';
					} ).join( '' ) + '</div>'
					: App.emptyState( 'chart', App.t( 'reports.noPages' ), App.t( 'reports.noPagesHint' ) ) ),
		} );

		each( '[data-open]', function ( button ) {
			button.addEventListener( 'click', function () { Reports.openSaved( button.dataset.open ); } );
		} );

		each( '[data-schedule]', function ( button ) {
			button.addEventListener( 'click', function () { Reports.scheduleReport( button.dataset.schedule ); } );
		} );

		each( '[data-schedule-send]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.request( 'POST', '/report-schedules/' + button.dataset.scheduleSend + '/send' )
					.then( function ( body ) {
						App.toast( App.t( 'reports.sentNow', { count: App.number( body.sent ) } ) );
						Reports.render( App );
					} )
					.catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );

		each( '[data-schedule-edit]', function ( button ) {
			button.addEventListener( 'click', function () {
				Reports.scheduleReport( null, Reports.schedules.filter( function ( row ) {
					return row.id === button.dataset.scheduleEdit;
				} )[ 0 ] );
			} );
		} );

		each( '[data-schedule-delete]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.modal( {
					title: App.t( 'reports.unscheduleTitle' ),
					submitLabel: App.t( 'reports.unschedule' ),
					body: '<p>' + esc( App.t( 'reports.unscheduleBody' ) ) + '</p>',
					onSubmit: function () {
						return App.request( 'DELETE', '/report-schedules/' + button.dataset.scheduleDelete )
							.then( function () {
								App.toast( App.t( 'reports.unscheduled' ) );
								Reports.render( App );
							} );
					},
				} );
			} );
		} );

		each( '[data-delete]', function ( button ) {
			button.addEventListener( 'click', function () {
				App.modal( {
					title: App.t( 'reports.deleteTitle' ),
					submitLabel: App.t( 'reports.delete' ),
					body: '<p>' + esc( App.t( 'reports.deleteBody' ) ) + '</p>',
					onSubmit: function () {
						return App.request( 'DELETE', '/reports/' + button.dataset.delete )
							.then( function () {
								App.toast( App.t( 'reports.deleted' ) );
								Reports.render( App );
							} );
					},
				} );
			} );
		} );

		each( '[data-page]', function ( button ) {
			button.addEventListener( 'click', function () { Reports.openPage( button.dataset.page ); } );
		} );

		document.getElementById( 'report-new' ).addEventListener( 'click', function () {
			Reports.startDraft();
		} );

		document.getElementById( 'report-new-page' ).addEventListener( 'click', function () {
			Reports.newPage();
		} );
	};

	Reports.sourceName = function ( key ) {
		var found = Reports.sources.filter( function ( source ) { return source.key === key; } )[ 0 ];

		return found ? found.name : key;
	};

	Reports.source = function ( key ) {
		return Reports.sources.filter( function ( source ) { return source.key === key; } )[ 0 ] || null;
	};

	/* ------------------------------------------------------------------------- the builder */

	Reports.startDraft = function ( report ) {
		if ( ! Reports.sources.length ) {
			Reports.App.toast( Reports.App.t( 'errors.forbidden_permission' ), true );

			return;
		}

		var definition = report ? ( report.definition || {} ) : {};

		Reports.draft = {
			id: report ? report.id : null,
			name: report ? report.name : '',
			source: report ? report.source : Reports.sources[ 0 ].key,
			dimensions: ( definition.dimensions || [] ).slice(),
			measures: ( definition.measures || [] ).slice(),
			filters: JSON.parse( JSON.stringify( definition.filters || {} ) ),
			sort: definition.sort ? { key: definition.sort.key, direction: definition.sort.direction } : null,
			limit: definition.limit || 100,
			// How this report was last looked at. The runner ignores it; a page widget decides for
			// itself. It is here so that opening a saved report shows what its author saw.
			view: VIEWS.indexOf( definition.view ) === -1 ? 'table' : definition.view,
		};

		Reports.result = null;
		Reports.view = 'builder';
		Reports.paintBuilder();
		Reports.run();
	};

	Reports.openSaved = function ( id ) {
		var App = Reports.App;

		App.request( 'GET', '/reports/' + id ).then( function ( report ) {
			Reports.startDraft( report );
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Reports.paintBuilder = function () {
		var App = Reports.App;
		var draft = Reports.draft;
		var source = Reports.source( draft.source );

		App.page( {
			title: draft.name || App.t( 'reports.newReport' ),
			description: source ? esc( source.description ) : '',
			actions:
				'<button class="btn" id="report-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'reports.back' ) ) + '</button>' +
				( draft.id
					? '<button class="btn" id="report-export">' + icon( 'download', { size: 15 } ) +
						esc( App.t( 'reports.export' ) ) + '</button>'
					: '' ) +
				'<button class="btn btn--primary" id="report-save">' +
					esc( App.t( 'reports.save' ) ) + '</button>',
			body:
				'<div class="builder">' +
					'<aside class="builder__palette">' +
						'<div class="field">' +
							'<label class="field__label" for="report-source">' +
								esc( App.t( 'reports.source' ) ) + '</label>' +
							'<select class="select" id="report-source">' +
								Reports.sources.map( function ( entry ) {
									return '<option value="' + esc( entry.key ) + '"' +
										( entry.key === draft.source ? ' selected' : '' ) + '>' +
										esc( entry.name ) + '</option>';
								} ).join( '' ) +
							'</select>' +
						'</div>' +
						'<p class="builder__hint">' + esc( App.t( 'reports.dragHint' ) ) + '</p>' +
						Reports.paletteGroup( 'dimension', source ? source.dimensions : [] ) +
						Reports.paletteGroup( 'measure', source ? source.measures : [] ) +
						Reports.paletteGroup( 'filter', source ? source.filters : [] ) +
					'</aside>' +
					'<div class="builder__main">' +
						Reports.shelf( 'dimension', App.t( 'reports.groupBy' ), draft.dimensions ) +
						Reports.shelf( 'measure', App.t( 'reports.measure' ), draft.measures ) +
						Reports.filterShelf( source ) +
						'<div class="builder__bar">' +
							Reports.viewSwitch() +
							'<div class="builder__spacer"></div>' +
							'<label class="builder__limit" for="report-limit">' +
								esc( App.t( 'reports.rowLimit' ) ) +
								'<select class="select select--sm" id="report-limit">' +
									LIMITS.filter( function ( limit ) {
										return limit <= Reports.maxRows;
									} ).map( function ( limit ) {
										return '<option value="' + limit + '"' +
											( limit === draft.limit ? ' selected' : '' ) + '>' +
											esc( App.number( limit ) ) + '</option>';
									} ).join( '' ) +
								'</select>' +
							'</label>' +
						'</div>' +
						'<div class="builder__preview" id="report-result">' +
							'<p class="muted">' + esc( App.t( 'reports.running' ) ) + '</p>' +
						'</div>' +
					'</div>' +
				'</div>',
		} );

		Reports.bindBuilder();

		/*
		 * Repainting the builder empties the preview, and not every change to it is a change to the
		 * answer — dropping a filter in before choosing a value asks nothing new. So the last
		 * answer is put back, and only the things that really change the question call `run()`.
		 */
		if ( Reports.result ) {
			Reports.paintResult();
		}
	};

	/** One group of draggable fields. Each chip is also a button, so pressing it adds the field. */
	Reports.paletteGroup = function ( kind, fields ) {
		var App = Reports.App;
		var titles = { dimension: 'groupBy', measure: 'measure', filter: 'filters' };
		var chosen = Reports.chosen( kind );

		if ( ! fields.length ) {
			return '';
		}

		return '<section class="builder__group">' +
			'<h3 class="subhead">' + esc( App.t( 'reports.' + titles[ kind ] ) ) + '</h3>' +
			'<ul class="field-chips">' +
				fields.map( function ( field ) {
					var used = chosen.indexOf( field.key ) !== -1;

					return '<li><button type="button" class="field-chip' + ( used ? ' is-used' : '' ) + '" ' +
						'draggable="' + ( used ? 'false' : 'true' ) + '" ' +
						'data-add="' + esc( kind ) + '" data-key="' + esc( field.key ) + '"' +
						( used ? ' disabled' : '' ) + ' ' +
						'title="' + esc( App.t( 'reports.addField', { field: field.label } ) ) + '">' +
						icon( used ? 'check' : 'plus', { size: 13 } ) +
						'<span>' + esc( field.label ) + '</span>' +
					'</button></li>';
				} ).join( '' ) +
			'</ul>' +
		'</section>';
	};

	/** What is already in a shelf, whichever shelf it is. */
	Reports.chosen = function ( kind ) {
		if ( 'dimension' === kind ) {
			return Reports.draft.dimensions;
		}

		if ( 'measure' === kind ) {
			return Reports.draft.measures;
		}

		return Object.keys( Reports.draft.filters );
	};

	Reports.fieldLabel = function ( kind, key ) {
		var source = Reports.source( Reports.draft.source );
		var list = source ? source[ { dimension: 'dimensions', measure: 'measures', filter: 'filters' }[ kind ] ] : [];
		var found = ( list || [] ).filter( function ( field ) { return field.key === key; } )[ 0 ];

		return found ? found.label : key;
	};

	Reports.shelf = function ( kind, title, keys ) {
		var App = Reports.App;

		return '<section class="shelf" data-shelf="' + esc( kind ) + '">' +
			'<h3 class="shelf__title">' + esc( title ) + '</h3>' +
			'<ol class="shelf__items">' +
				keys.map( function ( key, index ) {
					return Reports.pill( kind, key, index, keys.length );
				} ).join( '' ) +
			'</ol>' +
			( keys.length
				? ''
				: '<p class="shelf__empty">' + esc( App.t( 'reports.dropHere' ) ) + '</p>' ) +
		'</section>';
	};

	Reports.pill = function ( kind, key, index, total ) {
		var App = Reports.App;
		var label = Reports.fieldLabel( kind, key );

		return '<li class="pill" draggable="true" data-kind="' + esc( kind ) + '" ' +
			'data-key="' + esc( key ) + '" data-index="' + index + '">' +
			'<span class="pill__grip" aria-hidden="true">' + icon( 'layers', { size: 13 } ) + '</span>' +
			'<span class="pill__label">' + esc( label ) + '</span>' +
			'<span class="pill__buttons">' +
				'<button type="button" class="icon-btn icon-btn--sm" data-move="-1" ' +
					'data-kind="' + esc( kind ) + '" data-index="' + index + '"' +
					( 0 === index ? ' disabled' : '' ) + ' aria-label="' +
					esc( App.t( 'reports.moveEarlier', { field: label } ) ) + '">' +
					icon( 'arrowUp', { size: 13 } ) + '</button>' +
				'<button type="button" class="icon-btn icon-btn--sm" data-move="1" ' +
					'data-kind="' + esc( kind ) + '" data-index="' + index + '"' +
					( index === total - 1 ? ' disabled' : '' ) + ' aria-label="' +
					esc( App.t( 'reports.moveLater', { field: label } ) ) + '">' +
					icon( 'arrowDown', { size: 13 } ) + '</button>' +
				'<button type="button" class="icon-btn icon-btn--sm" data-drop-field="' +
					esc( kind ) + '" data-key="' + esc( key ) + '" aria-label="' +
					esc( App.t( 'reports.removeField', { field: label } ) ) + '">' +
					icon( 'close', { size: 13 } ) + '</button>' +
			'</span>' +
		'</li>';
	};

	/** The filter shelf holds controls rather than pills: a filter without a value filters nothing. */
	Reports.filterShelf = function ( source ) {
		var App = Reports.App;
		var keys = Object.keys( Reports.draft.filters );

		return '<section class="shelf shelf--filters" data-shelf="filter">' +
			'<h3 class="shelf__title">' + esc( App.t( 'reports.filters' ) ) + '</h3>' +
			( keys.length
				? '<div class="shelf__fields">' + keys.map( function ( key ) {
					var declared = ( ( source && source.filters ) || [] ).filter( function ( filter ) {
						return filter.key === key;
					} )[ 0 ];

					return declared ? Reports.filterField( declared ) : '';
				} ).join( '' ) + '</div>'
				: '<p class="shelf__empty">' + esc( App.t( 'reports.dropFilter' ) ) + '</p>' ) +
		'</section>';
	};

	Reports.filterField = function ( filter ) {
		var App = Reports.App;
		var value = Reports.draft.filters[ filter.key ];
		var head = '<div class="field field--filter">' +
			'<span class="field__row field__row--between">' +
				'<label class="field__label" for="f-' + esc( filter.key ) + '">' +
					esc( filter.label ) + '</label>' +
				'<button type="button" class="icon-btn icon-btn--sm" data-drop-field="filter" ' +
					'data-key="' + esc( filter.key ) + '" aria-label="' +
					esc( App.t( 'reports.removeField', { field: filter.label } ) ) + '">' +
					icon( 'close', { size: 13 } ) + '</button>' +
			'</span>';

		if ( 'date_range' === filter.type ) {
			value = ( value && 'object' === typeof value ) ? value : {};

			return head +
				'<span class="field__row">' +
					'<input class="input" type="date" id="f-' + esc( filter.key ) + '" ' +
						'data-range="' + esc( filter.key ) + '" data-edge="from" ' +
						'value="' + esc( value.from || '' ) + '" aria-label="' +
						esc( App.t( 'reports.from' ) ) + '">' +
					'<input class="input" type="date" data-range="' + esc( filter.key ) + '" ' +
						'data-edge="to" value="' + esc( value.to || '' ) + '" aria-label="' +
						esc( App.t( 'reports.to' ) ) + '">' +
				'</span>' +
			'</div>';
		}

		if ( 'enum' === filter.type ) {
			return head +
				'<select class="select" id="f-' + esc( filter.key ) + '" data-filter="' +
					esc( filter.key ) + '">' +
					'<option value="">' + esc( App.t( 'reports.anyValue' ) ) + '</option>' +
					( filter.options || [] ).map( function ( option ) {
						return '<option value="' + esc( option ) + '"' +
							( value === option ? ' selected' : '' ) + '>' + esc( option ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>';
		}

		// An event filter is a select of this account's events, not a box to type an id into.
		return head +
			'<select class="select" id="f-' + esc( filter.key ) + '" data-filter="' +
				esc( filter.key ) + '">' +
				'<option value="">' + esc( App.t( 'reports.allEvents' ) ) + '</option>' +
				( Reports.events || [] ).map( function ( event ) {
					return '<option value="' + esc( event.id ) + '"' +
						( value === event.id ? ' selected' : '' ) + '>' + esc( event.name ) + '</option>';
				} ).join( '' ) +
			'</select>' +
		'</div>';
	};

	Reports.viewSwitch = function () {
		var App = Reports.App;

		return '<div class="segmented" role="group" aria-label="' +
			esc( App.t( 'reports.widgetType' ) ) + '">' +
			VIEWS.map( function ( view ) {
				return '<button type="button" class="' +
					( view === Reports.draft.view ? 'is-on' : '' ) + '" data-view="' + view + '"' +
					( view === Reports.draft.view ? ' aria-pressed="true"' : ' aria-pressed="false"' ) + '>' +
					esc( App.t( 'reports.types.' + view ) ) + '</button>';
			} ).join( '' ) +
		'</div>';
	};

	/* ------------------------------------------------------------------- builder behaviour */

	Reports.bindBuilder = function () {
		var App = Reports.App;

		document.getElementById( 'report-source' ).addEventListener( 'change', function () {
			// A new dataset means new fields; keeping the old ones would offer a report that the
			// server is about to refuse.
			Reports.draft.source = this.value;
			Reports.draft.dimensions = [];
			Reports.draft.measures = [];
			Reports.draft.filters = {};
			Reports.draft.sort = null;
			Reports.paintBuilder();
			Reports.run();
		} );

		document.getElementById( 'report-limit' ).addEventListener( 'change', function () {
			Reports.draft.limit = Number( this.value );
			Reports.run();
		} );

		each( '[data-add]', function ( chip ) {
			chip.addEventListener( 'click', function () {
				Reports.add( chip.dataset.add, chip.dataset.key );
			} );

			chip.addEventListener( 'dragstart', function ( event ) {
				event.dataTransfer.effectAllowed = 'copy';
				event.dataTransfer.setData( 'text/plain', 'add:' + chip.dataset.add + ':' + chip.dataset.key );
			} );
		} );

		each( '[data-drop-field]', function ( button ) {
			button.addEventListener( 'click', function () {
				Reports.removeField( button.dataset.dropField, button.dataset.key );
			} );
		} );

		each( '[data-move]', function ( button ) {
			button.addEventListener( 'click', function () {
				Reports.move(
					button.dataset.kind,
					Number( button.dataset.index ),
					Number( button.dataset.index ) + Number( button.dataset.move )
				);
			} );
		} );

		each( '.segmented [data-view]', function ( button ) {
			button.addEventListener( 'click', function () {
				Reports.draft.view = button.dataset.view;
				Reports.paintBuilder();
				Reports.paintResult();
			} );
		} );

		Reports.bindDragging();

		each( '[data-filter]', function ( control ) {
			control.addEventListener( 'change', function () {
				Reports.draft.filters[ control.dataset.filter ] = control.value;
				Reports.run();
			} );
		} );

		each( '[data-range]', function ( control ) {
			control.addEventListener( 'change', function () {
				var key = control.dataset.range;
				var range = Reports.draft.filters[ key ];

				range = ( range && 'object' === typeof range ) ? range : {};
				range[ control.dataset.edge ] = control.value;
				Reports.draft.filters[ key ] = range;
				Reports.run();
			} );
		} );

		document.getElementById( 'report-back' )
			.addEventListener( 'click', function () { Reports.render( App ); } );

		document.getElementById( 'report-save' )
			.addEventListener( 'click', function () { Reports.save(); } );

		var exporter = document.getElementById( 'report-export' );

		if ( exporter ) {
			exporter.addEventListener( 'click', function () { Reports.download(); } );
		}
	};

	/**
	 * Dropping.
	 *
	 * A shelf accepts its own kind and nothing else, and a pill dropped on another pill lands in
	 * that place rather than at the end — which is what somebody dragging a column between two
	 * others means by it.
	 */
	Reports.bindDragging = function () {
		each( '.pill', function ( pill ) {
			pill.addEventListener( 'dragstart', function ( event ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain',
					'move:' + pill.dataset.kind + ':' + pill.dataset.index );
				pill.classList.add( 'is-dragging' );
			} );

			pill.addEventListener( 'dragend', function () { pill.classList.remove( 'is-dragging' ); } );

			pill.addEventListener( 'dragover', function ( event ) {
				event.preventDefault();
				pill.classList.add( 'is-target' );
			} );

			pill.addEventListener( 'dragleave', function () { pill.classList.remove( 'is-target' ); } );

			pill.addEventListener( 'drop', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				pill.classList.remove( 'is-target' );
				Reports.handleDrop( event.dataTransfer.getData( 'text/plain' ),
					pill.dataset.kind, Number( pill.dataset.index ) );
			} );
		} );

		each( '.shelf', function ( shelf ) {
			shelf.addEventListener( 'dragover', function ( event ) {
				event.preventDefault();
				shelf.classList.add( 'is-target' );
			} );

			shelf.addEventListener( 'dragleave', function () { shelf.classList.remove( 'is-target' ); } );

			shelf.addEventListener( 'drop', function ( event ) {
				event.preventDefault();
				shelf.classList.remove( 'is-target' );
				Reports.handleDrop( event.dataTransfer.getData( 'text/plain' ), shelf.dataset.shelf, null );
			} );
		} );
	};

	Reports.handleDrop = function ( payload, shelf, index ) {
		var parts = String( payload || '' ).split( ':' );

		if ( 3 !== parts.length || parts[ 1 ] !== shelf ) {
			// A measure dropped on "group by" is a mistake, not an instruction. Refusing it here
			// is kinder than a validation error a second later.
			return;
		}

		if ( 'add' === parts[ 0 ] ) {
			Reports.add( shelf, parts[ 2 ], index );

			return;
		}

		if ( 'move' === parts[ 0 ] ) {
			Reports.move( shelf, Number( parts[ 2 ] ), null === index ? -1 : index );
		}
	};

	Reports.add = function ( kind, key, at ) {
		if ( 'filter' === kind ) {
			if ( ! ( key in Reports.draft.filters ) ) {
				Reports.draft.filters[ key ] = '';
				Reports.paintBuilder();
			}

			return;
		}

		var list = Reports.chosen( kind );

		if ( list.indexOf( key ) !== -1 ) {
			return;
		}

		if ( null === at || undefined === at ) {
			list.push( key );
		} else {
			list.splice( at, 0, key );
		}

		Reports.paintBuilder();
		Reports.run();
	};

	Reports.removeField = function ( kind, key ) {
		if ( 'filter' === kind ) {
			delete Reports.draft.filters[ key ];
		} else {
			var list = Reports.chosen( kind );
			var at = list.indexOf( key );

			if ( -1 === at ) {
				return;
			}

			list.splice( at, 1 );

			// A sort pointing at a column that is no longer there would be refused by the runner.
			if ( Reports.draft.sort && Reports.draft.sort.key === key ) {
				Reports.draft.sort = null;
			}
		}

		Reports.paintBuilder();
		Reports.run();
	};

	Reports.move = function ( kind, from, to ) {
		var list = Reports.chosen( kind );

		if ( 'filter' === kind || from < 0 || from >= list.length ) {
			return;
		}

		var target = ( null === to || to < 0 || to > list.length - 1 ) ? list.length - 1 : to;

		if ( target === from ) {
			return;
		}

		list.splice( target, 0, list.splice( from, 1 )[ 0 ] );
		Reports.paintBuilder();
		Reports.run();
	};

	Reports.definition = function () {
		var definition = {
			dimensions: Reports.draft.dimensions,
			measures: Reports.draft.measures,
			filters: Reports.filtersForRun(),
			limit: Reports.draft.limit,
			view: Reports.draft.view,
		};

		if ( Reports.draft.sort ) {
			definition.sort = Reports.draft.sort;
		}

		return definition;
	};

	/** A filter that has been dropped in but not filled in yet is not a filter. */
	Reports.filtersForRun = function () {
		var filters = {};

		Object.keys( Reports.draft.filters ).forEach( function ( key ) {
			var value = Reports.draft.filters[ key ];

			if ( value && 'object' === typeof value ) {
				if ( value.from || value.to ) {
					filters[ key ] = value;
				}

				return;
			}

			if ( value ) {
				filters[ key ] = value;
			}
		} );

		return filters;
	};

	Reports.run = function () {
		var App = Reports.App;
		var host = document.getElementById( 'report-result' );

		if ( ! host ) {
			return;
		}

		if ( ! Reports.draft.measures.length ) {
			Reports.result = null;
			host.innerHTML = '<p class="muted">' + esc( App.t( 'reports.needMeasure' ) ) + '</p>';

			return;
		}

		host.innerHTML = '<p class="muted">' + esc( App.t( 'reports.running' ) ) + '</p>';

		App.request( 'POST', '/reports/run', {
			source: Reports.draft.source,
			definition: Reports.definition(),
		} ).then( function ( result ) {
			Reports.result = result;
			Reports.paintResult();
		} ).catch( function ( error ) {
			Reports.result = null;
			host.innerHTML = '<p class="muted">' + esc( error.message ) + '</p>';
		} );
	};

	Reports.paintResult = function () {
		var host = document.getElementById( 'report-result' );

		if ( ! host || ! Reports.result ) {
			return;
		}

		host.innerHTML = Reports.resultMarkup( Reports.result, Reports.draft.view, true );

		each( '#report-result [data-sort]', function ( button ) {
			button.addEventListener( 'click', function () {
				var key = button.dataset.sort;
				var current = Reports.draft.sort;

				Reports.draft.sort = {
					key: key,
					direction: current && current.key === key && 'desc' === current.direction
						? 'asc'
						: 'desc',
				};

				Reports.run();
			} );
		} );
	};

	/* -------------------------------------------------------------------------- rendering */

	/**
	 * The answer.
	 *
	 * `sortable` is only true in the builder: a column heading on a report page is a heading, not
	 * a control, because the page's own definition is what decides its order.
	 */
	Reports.resultMarkup = function ( result, type, sortable ) {
		var App = Reports.App;

		if ( ! result.rows || ! result.rows.length ) {
			return '<p class="muted">' + esc( App.t( 'reports.noRows' ) ) + '</p>';
		}

		if ( 'stat' === type ) {
			return Reports.stat( result );
		}

		var chart = ( 'bar' === type || 'line' === type ) ? Reports.chart( result, type ) : '';

		return chart + App.table(
			result.columns.map( function ( column ) {
				return sortable
					? { html: Reports.sortHeading( result, column ), numeric: 'measure' === column.kind }
					: { label: column.label, numeric: 'measure' === column.kind };
			} ),
			result.rows.map( function ( row ) {
				return '<tr>' + result.columns.map( function ( column ) {
					return '<td' + ( 'measure' === column.kind ? ' class="tnum"' : '' ) + '>' +
						esc( Reports.cell( row[ column.alias ], column ) ) + '</td>';
				} ).join( '' ) + '</tr>';
			} ).join( '' )
		) + ( result.truncated
			? '<p class="muted">' + esc( App.t( 'reports.truncated', {
				count: App.number( result.limit ),
			} ) ) + '</p>'
			: '' );
	};

	/**
	 * A column heading that sorts.
	 *
	 * Handed to the table helper as `html`, which is the one field it does not escape — so
	 * everything variable in here is escaped on the way in, and the arrow is our own icon rather
	 * than anything the server sent.
	 */
	Reports.sortHeading = function ( result, column ) {
		var App = Reports.App;
		var active = result.sort && result.sort.alias === column.alias;

		return '<button type="button" class="th-sort' + ( active ? ' is-active' : '' ) + '" ' +
			'data-sort="' + esc( column.key ) + '" aria-label="' +
			esc( App.t( 'reports.sortBy', { field: column.label } ) ) + '">' +
			'<span>' + esc( column.label ) + '</span>' +
			( active
				? '<span class="th-sort__arrow" aria-hidden="true">' +
					icon( 'asc' === result.sort.direction ? 'arrowUp' : 'arrowDown', { size: 12 } ) +
				'</span>'
				: '' ) +
		'</button>';
	};

	/** One number, big. What a stat widget is for. */
	Reports.stat = function ( result ) {
		var measure = result.columns.filter( function ( column ) {
			return 'measure' === column.kind;
		} )[ 0 ];

		if ( ! measure ) {
			return '';
		}

		var total = result.rows.reduce( function ( sum, row ) {
			return sum + ( Number( row[ measure.alias ] ) || 0 );
		}, 0 );

		return '<div class="stat stat--block"><span class="stat__value tnum">' +
			esc( Reports.cell( total, measure ) ) + '</span>' +
			'<span class="stat__label">' + esc( measure.label ) + '</span></div>';
	};

	Reports.cell = function ( value, column ) {
		var App = Reports.App;

		if ( null === value || undefined === value ) {
			return '—';
		}

		if ( 'measure' === column.kind ) {
			// Money is in the event's currency; a report that mixes currencies groups by currency,
			// which is why the column is offered. Without one, the platform's own is the honest
			// default and the number is still right in its own terms.
			return 'money' === column.format
				? App.money( Math.round( value ), Reports.currencyOf( column ) )
				: App.number( value );
		}

		if ( 'date' === column.type || 'datetime' === column.type ) {
			return App.date( value );
		}

		return String( value );
	};

	/**
	 * Which currency to write a money column in.
	 *
	 * If the report groups by currency, each row says; otherwise the account's own is used and the
	 * report is honest about being one currency's worth of arithmetic.
	 */
	Reports.currencyOf = function () {
		return ( Reports.result && Reports.result.currency ) || 'EUR';
	};

	/**
	 * A chart, drawn here.
	 *
	 * A thousand rows is not a reason to load a charting library; it is a reason to write forty
	 * lines of SVG that do exactly what this screen needs and nothing else.
	 */
	Reports.chart = function ( result, type ) {
		var dimension = result.columns.filter( function ( c ) { return 'dimension' === c.kind; } )[ 0 ];
		var measures = result.columns.filter( function ( c ) { return 'measure' === c.kind; } );

		// The column the report is in order of, when that is a measure — somebody who sorted by
		// revenue meant revenue, and a chart of the first column instead is a chart of the wrong
		// question. Otherwise the first, which is what a report with no sort is ordered by anyway.
		var measure = measures.filter( function ( c ) {
			return result.sort && result.sort.alias === c.alias;
		} )[ 0 ] || measures[ 0 ];

		if ( ! dimension || ! measure ) {
			return '';
		}

		var rows = result.rows.slice( 0, 24 );
		var values = rows.map( function ( row ) { return Number( row[ measure.alias ] ) || 0; } );
		var top = Math.max.apply( null, values.concat( [ 1 ] ) );

		// The canvas grows with the data instead of stretching to fill: two bars stretched across
		// a wide panel read as a bar chart of something enormous.
		var slot = 64;
		var plot = 150;
		var labels = 34;
		var width = Math.max( rows.length * slot, 240 );
		var height = plot + labels;

		function x( index ) {
			return index * slot;
		}

		var body = rows.map( function ( row, index ) {
			var value = values[ index ];
			var barHeight = Math.max( ( value / top ) * ( plot - 12 ), 2 );
			var label = Reports.cell( row[ dimension.alias ], dimension );
			var title = label + ' — ' + Reports.cell( row[ measure.alias ], measure );
			var mark;

			if ( 'line' === type ) {
				mark = '<circle class="spark__dot" cx="' + ( x( index ) + slot / 2 ).toFixed( 1 ) +
					'" cy="' + ( plot - barHeight ).toFixed( 1 ) + '" r="3.5"></circle>';
			} else {
				mark = '<rect class="spark__bar" x="' + ( x( index ) + slot * 0.18 ).toFixed( 1 ) +
					'" y="' + ( plot - barHeight ).toFixed( 1 ) +
					'" width="' + ( slot * 0.64 ).toFixed( 1 ) +
					'" height="' + barHeight.toFixed( 1 ) + '" rx="2"></rect>';
			}

			return '<g><title>' + esc( title ) + '</title>' + mark +
				'<text class="spark__label" x="' + ( x( index ) + slot / 2 ).toFixed( 1 ) +
					'" y="' + ( plot + 14 ) + '">' + esc( trim( label, 10 ) ) + '</text>' +
				'<text class="spark__value" x="' + ( x( index ) + slot / 2 ).toFixed( 1 ) +
					'" y="' + ( plot + 28 ) + '">' +
					esc( trim( Reports.cell( row[ measure.alias ], measure ), 10 ) ) + '</text>' +
			'</g>';
		} ).join( '' );

		var line = 'line' === type
			? '<polyline class="spark__line" points="' + rows.map( function ( row, index ) {
				return ( x( index ) + slot / 2 ).toFixed( 1 ) + ',' +
					( plot - Math.max( ( values[ index ] / top ) * ( plot - 12 ), 2 ) ).toFixed( 1 );
			} ).join( ' ' ) + '"></polyline>'
			: '';

		return '<svg class="spark" viewBox="0 0 ' + width + ' ' + height + '" ' +
			'role="img" aria-label="' + esc( measure.label ) + '">' +
			'<line class="spark__axis" x1="0" y1="' + plot + '" x2="' + width + '" y2="' + plot + '"></line>' +
			line + body + '</svg>';
	};

	/* ---------------------------------------------------------------------------- saving */

	Reports.save = function () {
		var App = Reports.App;
		var draft = Reports.draft;

		if ( ! draft.measures.length ) {
			App.toast( App.t( 'reports.needMeasure' ), true );

			return;
		}

		if ( draft.id ) {
			App.request( 'PATCH', '/reports/' + draft.id, { definition: Reports.definition() } )
				.then( function () {
					App.toast( App.t( 'reports.savedToast' ) );
				} ).catch( function ( error ) { App.toast( error.message, true ); } );

			return;
		}

		App.modal( {
			title: App.t( 'reports.save' ),
			submitLabel: App.t( 'reports.save' ),
			body:
				'<div class="field"><label class="field__label" for="r-name">' +
					esc( App.t( 'reports.name' ) ) + '</label>' +
				'<input class="input" id="r-name" name="name" required maxlength="120" ' +
					'value="' + esc( draft.name ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'reports.nameHint' ) ) + '</span></div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/reports', {
					name: data.get( 'name' ),
					source: draft.source,
					definition: Reports.definition(),
				} ).then( function ( saved ) {
					App.toast( App.t( 'reports.savedToast' ) );
					Reports.draft.id = saved.id;
					Reports.draft.name = saved.name;
					Reports.paintBuilder();
					Reports.run();
				} );
			},
		} );
	};

	/**
	 * Download the CSV.
	 *
	 * Fetched with the panel's own credentials and handed to the browser as a blob, because an
	 * anchor cannot carry an Authorization header and a token in a query string is a token in
	 * somebody's server log.
	 */
	Reports.download = function () {
		var App = Reports.App;

		App.request( 'GET', '/reports/' + Reports.draft.id + '/export', null, { raw: true } )
			.then( function ( blob ) {
				var url = global.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = ( Reports.draft.name || 'report' ) + '.csv';
				document.body.appendChild( link );
				link.click();
				link.remove();
				global.URL.revokeObjectURL( url );
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ----------------------------------------------------------------------------- pages */

	Reports.newPage = function () {
		var App = Reports.App;

		App.modal( {
			title: App.t( 'reports.newPage' ),
			submitLabel: App.t( 'reports.newPage' ),
			body:
				'<div class="field"><label class="field__label" for="p-name">' +
					esc( App.t( 'reports.pageName' ) ) + '</label>' +
				'<input class="input" id="p-name" name="name" required maxlength="120"></div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/report-pages', { name: data.get( 'name' ), widgets: [] } )
					.then( function ( page ) {
						App.toast( App.t( 'reports.pageSaved' ) );
						Reports.openPage( page.id );
					} );
			},
		} );
	};

	Reports.openPage = function ( id ) {
		var App = Reports.App;

		App.loading( App.t( 'reports.pages' ) );

		Promise.all( [
			App.request( 'GET', '/report-pages/' + id ),
			Reports.saved.length ? Promise.resolve( { data: Reports.saved } )
				: App.request( 'GET', '/reports' ),
		] ).then( function ( results ) {
			Reports.page = results[ 0 ];
			Reports.saved = results[ 1 ].data || [];
			Reports.view = 'page';
			Reports.saveState = '';
			Reports.paintPage();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * A page, arranged by hand.
	 *
	 * The left column is what can go on it — the saved reports, and a note for the organiser's own
	 * words. The right is the page itself: cards that can be dragged into any order, widened to a
	 * third, a half or the whole row, and retitled in place. Every change writes itself back a
	 * moment later, because a layout with a Save button is a layout somebody loses.
	 */
	Reports.paintPage = function () {
		var App = Reports.App;
		var page = Reports.page;

		App.page( {
			title: page.name,
			description: esc( App.t( 'reports.pageHint' ) ),
			actions:
				'<button class="btn" id="page-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'reports.back' ) ) + '</button>' +
				'<span class="save-state" id="page-state">' + esc( Reports.saveState ) + '</span>' +
				'<button class="btn btn--danger" id="page-delete">' +
					esc( App.t( 'reports.delete' ) ) + '</button>',
			body:
				'<div class="builder">' +
					'<aside class="builder__palette">' +
						'<p class="builder__hint">' + esc( App.t( 'reports.pageDragHint' ) ) + '</p>' +
						'<section class="builder__group">' +
							'<h3 class="subhead">' + esc( App.t( 'reports.saved' ) ) + '</h3>' +
							( Reports.saved.length
								? '<ul class="field-chips">' + Reports.saved.map( function ( report ) {
									return '<li><button type="button" class="field-chip" draggable="true" ' +
										'data-widget-add="' + esc( report.id ) + '">' +
										icon( 'plus', { size: 13 } ) + '<span>' + esc( report.name ) +
										'</span></button></li>';
								} ).join( '' ) + '</ul>'
								: '<p class="muted">' + esc( App.t( 'reports.noReportsHint' ) ) + '</p>' ) +
						'</section>' +
						'<section class="builder__group">' +
							'<h3 class="subhead">' + esc( App.t( 'reports.blocks' ) ) + '</h3>' +
							'<ul class="field-chips"><li>' +
								'<button type="button" class="field-chip" draggable="true" data-widget-add="note">' +
									icon( 'text', { size: 13 } ) + '<span>' +
									esc( App.t( 'reports.note' ) ) + '</span>' +
								'</button>' +
							'</li></ul>' +
						'</section>' +
					'</aside>' +
					'<div class="builder__main">' +
						'<div class="widgets" id="page-canvas">' +
							( page.widgets.length
								? page.widgets.map( function ( widget, index ) {
									return Reports.widgetMarkup( widget, index );
								} ).join( '' )
								: '<p class="canvas__empty">' + esc( App.t( 'reports.dropWidget' ) ) + '</p>' ) +
						'</div>' +
					'</div>' +
				'</div>',
		} );

		Reports.bindPage();
	};

	Reports.widgetMarkup = function ( widget, index ) {
		var App = Reports.App;

		var head = '<header class="widget__head" draggable="true" data-widget="' + index + '">' +
			'<span class="pill__grip" aria-hidden="true">' + icon( 'layers', { size: 13 } ) + '</span>' +
			'<input class="widget__title" data-title="' + index + '" ' +
				'value="' + esc( widget.title || '' ) + '" maxlength="120" ' +
				'placeholder="' + esc( App.t( 'reports.untitled' ) ) + '" aria-label="' +
				esc( App.t( 'reports.widgetTitle' ) ) + '">' +
			'<span class="widget__tools">' +
				( 'note' === widget.type
					? ''
					: '<select class="select select--sm" data-type="' + index + '" aria-label="' +
						esc( App.t( 'reports.widgetType' ) ) + '">' +
						VIEWS.map( function ( view ) {
							return '<option value="' + view + '"' +
								( view === widget.type ? ' selected' : '' ) + '>' +
								esc( App.t( 'reports.types.' + view ) ) + '</option>';
						} ).join( '' ) +
					'</select>' ) +
				'<select class="select select--sm" data-width="' + index + '" aria-label="' +
					esc( App.t( 'reports.width' ) ) + '">' +
					WIDTHS.map( function ( width ) {
						return '<option value="' + width + '"' +
							( width === ( widget.width || 'full' ) ? ' selected' : '' ) + '>' +
							esc( App.t( 'reports.widths.' + width ) ) + '</option>';
					} ).join( '' ) +
				'</select>' +
				'<button type="button" class="icon-btn icon-btn--sm" data-remove="' + index +
					'" aria-label="' + esc( App.t( 'reports.remove' ) ) + '">' +
					icon( 'close', { size: 13 } ) + '</button>' +
			'</span>' +
		'</header>';

		var body;

		if ( 'note' === widget.type ) {
			body = '<textarea class="input widget__note" data-note="' + index + '" rows="4" ' +
				'maxlength="2000" placeholder="' + esc( App.t( 'reports.notePlaceholder' ) ) + '">' +
				esc( widget.text || '' ) + '</textarea>';
		} else if ( widget.error ) {
			body = '<p class="muted">' + esc( App.t( 'reports.widgetError' ) ) + '</p>';
		} else {
			body = Reports.resultMarkup( widget, widget.type, false );
		}

		return '<section class="widget widget--' + esc( widget.width || 'full' ) + '" ' +
			'data-card="' + index + '">' + head + body + '</section>';
	};

	Reports.bindPage = function () {
		var App = Reports.App;

		document.getElementById( 'page-back' )
			.addEventListener( 'click', function () { Reports.render( App ); } );

		document.getElementById( 'page-delete' ).addEventListener( 'click', function () {
			App.modal( {
				title: App.t( 'reports.deletePageTitle' ),
				submitLabel: App.t( 'reports.delete' ),
				body: '<p>' + esc( App.t( 'reports.deletePageBody' ) ) + '</p>',
				onSubmit: function () {
					return App.request( 'DELETE', '/report-pages/' + Reports.page.id ).then( function () {
						App.toast( App.t( 'reports.pageDeleted' ) );
						Reports.render( App );
					} );
				},
			} );
		} );

		each( '[data-widget-add]', function ( chip ) {
			chip.addEventListener( 'click', function () {
				Reports.addWidget( chip.dataset.widgetAdd, null );
			} );

			chip.addEventListener( 'dragstart', function ( event ) {
				event.dataTransfer.effectAllowed = 'copy';
				event.dataTransfer.setData( 'text/plain', 'widget:' + chip.dataset.widgetAdd );
			} );
		} );

		each( '[data-remove]', function ( button ) {
			button.addEventListener( 'click', function () {
				Reports.page.widgets.splice( Number( button.dataset.remove ), 1 );
				Reports.paintPage();
				Reports.queueSave();
			} );
		} );

		each( '[data-title]', function ( input ) {
			input.addEventListener( 'input', function () {
				Reports.page.widgets[ Number( input.dataset.title ) ].title = input.value;
				Reports.queueSave();
			} );
		} );

		each( '[data-note]', function ( area ) {
			area.addEventListener( 'input', function () {
				Reports.page.widgets[ Number( area.dataset.note ) ].text = area.value;
				Reports.queueSave();
			} );
		} );

		each( '[data-type]', function ( select ) {
			select.addEventListener( 'change', function () {
				var widget = Reports.page.widgets[ Number( select.dataset.type ) ];

				// The rows are already here; only the shape of them changes. Asking the server
				// again for the same numbers would be a round trip to redraw a chart.
				widget.type = select.value;
				Reports.paintPage();
				Reports.queueSave();
			} );
		} );

		each( '[data-width]', function ( select ) {
			select.addEventListener( 'change', function () {
				Reports.page.widgets[ Number( select.dataset.width ) ].width = select.value;
				Reports.paintPage();
				Reports.queueSave();
			} );
		} );

		Reports.bindCanvasDragging();
	};

	Reports.bindCanvasDragging = function () {
		var canvas = document.getElementById( 'page-canvas' );

		if ( ! canvas ) {
			return;
		}

		each( '.widget__head[data-widget]', function ( head ) {
			head.addEventListener( 'dragstart', function ( event ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', 'card:' + head.dataset.widget );
				head.closest( '.widget' ).classList.add( 'is-dragging' );
			} );

			head.addEventListener( 'dragend', function () {
				each( '.widget', function ( card ) { card.classList.remove( 'is-dragging' ); } );
			} );
		} );

		each( '.widget[data-card]', function ( card ) {
			card.addEventListener( 'dragover', function ( event ) {
				event.preventDefault();
				card.classList.add( 'is-target' );
			} );

			card.addEventListener( 'dragleave', function () { card.classList.remove( 'is-target' ); } );

			card.addEventListener( 'drop', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				card.classList.remove( 'is-target' );
				Reports.handleCanvasDrop( event.dataTransfer.getData( 'text/plain' ),
					Number( card.dataset.card ) );
			} );
		} );

		canvas.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			canvas.classList.add( 'is-target' );
		} );

		canvas.addEventListener( 'dragleave', function () { canvas.classList.remove( 'is-target' ); } );

		canvas.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			canvas.classList.remove( 'is-target' );
			Reports.handleCanvasDrop( event.dataTransfer.getData( 'text/plain' ), null );
		} );
	};

	Reports.handleCanvasDrop = function ( payload, at ) {
		var parts = String( payload || '' ).split( ':' );

		if ( 2 !== parts.length ) {
			return;
		}

		if ( 'widget' === parts[ 0 ] ) {
			Reports.addWidget( parts[ 1 ], at );

			return;
		}

		if ( 'card' === parts[ 0 ] ) {
			var from = Number( parts[ 1 ] );
			var to = null === at ? Reports.page.widgets.length - 1 : at;

			if ( from === to ) {
				return;
			}

			Reports.page.widgets.splice( to, 0, Reports.page.widgets.splice( from, 1 )[ 0 ] );
			Reports.paintPage();
			Reports.queueSave();
		}
	};

	/**
	 * Put something new on the page.
	 *
	 * A note is drawn straight away — there is nothing to run. A report has to come back from the
	 * server with its rows in it, so the page is written and then read again.
	 */
	Reports.addWidget = function ( what, at ) {
		var widget = 'note' === what
			? { type: 'note', title: '', text: '', width: 'full' }
			: { type: 'table', report_id: what, title: '', width: 'half' };

		if ( null === at || undefined === at ) {
			Reports.page.widgets.push( widget );
		} else {
			Reports.page.widgets.splice( at, 0, widget );
		}

		Reports.paintPage();

		if ( 'note' === what ) {
			Reports.queueSave();

			return;
		}

		Reports.saveWidgets();
	};

	Reports.queueSave = function () {
		global.clearTimeout( Reports.saveTimer );
		Reports.setSaveState( Reports.App.t( 'reports.saving' ) );
		Reports.saveTimer = global.setTimeout( function () { Reports.saveWidgets( true ); }, 700 );
	};

	Reports.setSaveState = function ( text ) {
		var host = document.getElementById( 'page-state' );

		Reports.saveState = text;

		if ( host ) {
			host.textContent = text;
		}
	};

	/** The page as it is stored, rather than as it is rendered. */
	Reports.widgetDefinitions = function () {
		return Reports.page.widgets.map( function ( widget ) {
			return 'note' === widget.type
				? { type: 'note', title: widget.title, text: widget.text || '', width: widget.width || 'full' }
				: {
					type: widget.type,
					report_id: widget.report_id,
					title: widget.title,
					width: widget.width || 'full',
				};
		} );
	};

	/**
	 * Write the arrangement back.
	 *
	 * `quietly` means the page keeps what it already has on screen. It is the right thing for a
	 * reorder or a retitle, where the rows have not changed and re-fetching them would make the
	 * screen flicker; a new report widget arrives without rows, so that one reads the page back.
	 */
	Reports.saveWidgets = function ( quietly ) {
		var App = Reports.App;

		global.clearTimeout( Reports.saveTimer );

		return App.request( 'PATCH', '/report-pages/' + Reports.page.id, {
			widgets: Reports.widgetDefinitions(),
		} ).then( function () {
			if ( quietly ) {
				Reports.setSaveState( App.t( 'reports.allSaved' ) );

				return;
			}

			return App.request( 'GET', '/report-pages/' + Reports.page.id ).then( function ( page ) {
				Reports.page = page;
				Reports.saveState = App.t( 'reports.allSaved' );
				Reports.paintPage();
			} );
		} ).catch( function ( error ) {
			Reports.setSaveState( '' );
			App.toast( error.message, true );
		} );
	};

	/* --------------------------------------------------------------------------- helpers */

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

	function checked( id ) {
		var element = document.getElementById( id );

		return !! ( element && element.checked );
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	/** A chart label is a label, not a paragraph. */
	function trim( value, length ) {
		var text = String( value );

		return text.length > length ? text.slice( 0, length - 1 ) + '…' : text;
	}

	function esc( value ) {
		return String( null === value || undefined === value ? '' : value )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	global.SeatmapReports = Reports;
}( window ) );
