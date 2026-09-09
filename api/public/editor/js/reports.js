/**
 * Reports: pick a dataset, say what to group by and what to count, look at the answer.
 *
 * There is no query box here and there will not be one (ADR-0006). Every field on this screen came
 * from the source's own declaration, which is also what the server validates against — so the
 * builder cannot offer a field the runner would refuse, and neither can accept one nobody
 * declared.
 *
 * The chart is drawn here rather than fetched: a report is at most a thousand rows, and a
 * charting library is 200KB of somebody else's opinions about tooltips.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Reports = {
		App: null,
		sources: [],
		saved: [],
		pages: [],
		maxRows: 1000,

		// The report being built.
		draft: null,
		result: null,
		view: 'list',
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
			// The events an event filter offers. Fetched rather than typed: an id in a box is a
			// way to point a report at something that is not yours and be told off for it.
			App.request( 'GET', '/events?per_page=100' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( results ) {
			Reports.sources = results[ 0 ].data || [];
			Reports.maxRows = results[ 0 ].max_rows || 1000;
			Reports.saved = results[ 1 ].data || [];
			Reports.pages = results[ 2 ].data || [];
			Reports.events = results[ 3 ].data || [];
			Reports.paintList();
		} ).catch( function ( error ) { App.error( error ); } );
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
									'<button class="btn btn--sm" data-delete="' + esc( report.id ) + '">' +
										esc( App.t( 'reports.delete' ) ) + '</button>' +
								'</td></tr>';
						} ).join( '' )
					)
					: App.emptyState( 'chart', App.t( 'reports.noReports' ), App.t( 'reports.noReportsHint' ) ) ) +

				'<h3 class="subhead">' + esc( App.t( 'reports.pages' ) ) + '</h3>' +
				( Reports.pages.length
					? '<div class="look-grid">' + Reports.pages.map( function ( page ) {
						return '<button class="block-card" data-page="' + esc( page.id ) + '">' +
							'<span class="block-card__name">' + esc( page.name ) + '</span>' +
							'<span class="block-card__meta">' +
								esc( App.t( 'reports.rows', {
									count: App.number( ( page.widgets || [] ).length ),
								} ) ) + '</span>' +
						'</button>';
					} ).join( '' ) + '</div>'
					: App.emptyState( 'chart', App.t( 'reports.noPages' ), App.t( 'reports.noPagesHint' ) ) ),
		} );

		each( '[data-open]', function ( button ) {
			button.addEventListener( 'click', function () { Reports.openSaved( button.dataset.open ); } );
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

		Reports.draft = report
			? {
				id: report.id,
				name: report.name,
				source: report.source,
				dimensions: ( report.definition.dimensions || [] ).slice(),
				measures: ( report.definition.measures || [] ).slice(),
				filters: JSON.parse( JSON.stringify( report.definition.filters || {} ) ),
			}
			: {
				id: null,
				name: '',
				source: Reports.sources[ 0 ].key,
				dimensions: [],
				measures: [],
				filters: {},
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
			description: source ? source.description : '',
			actions:
				'<button class="btn" id="report-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'reports.back' ) ) + '</button>' +
				( draft.id
					? '<a class="btn" id="report-export" href="#">' + icon( 'download', { size: 15 } ) +
						esc( App.t( 'reports.export' ) ) + '</a>'
					: '' ) +
				'<button class="btn btn--primary" id="report-save">' +
					esc( App.t( 'reports.save' ) ) + '</button>',
			body:
				'<div class="theme-editor">' +
					'<div class="theme-editor__controls">' +
						'<section class="theme-group">' +
							'<h3 class="subhead">' + esc( App.t( 'reports.source' ) ) + '</h3>' +
							'<select class="select" id="report-source">' +
								Reports.sources.map( function ( entry ) {
									return '<option value="' + esc( entry.key ) + '"' +
										( entry.key === draft.source ? ' selected' : '' ) + '>' +
										esc( entry.name ) + '</option>';
								} ).join( '' ) +
							'</select>' +
						'</section>' +
						Reports.pickerMarkup( 'groupBy', 'dimension', source ? source.dimensions : [] ) +
						Reports.pickerMarkup( 'measure', 'measure', source ? source.measures : [] ) +
						Reports.filtersMarkup( source ) +
					'</div>' +
					'<div class="theme-editor__preview" id="report-result">' +
						'<p class="muted">' + esc( App.t( 'reports.running' ) ) + '</p>' +
					'</div>' +
				'</div>',
		} );

		Reports.bindBuilder();
	};

	Reports.pickerMarkup = function ( titleKey, kind, fields ) {
		var App = Reports.App;
		var chosen = 'dimension' === kind ? Reports.draft.dimensions : Reports.draft.measures;

		return '<section class="theme-group">' +
			'<h3 class="subhead">' + esc( App.t( 'reports.' + titleKey ) ) + '</h3>' +
			'<div class="perms">' +
				fields.map( function ( field ) {
					return '<label class="perms__row">' +
						'<input type="checkbox" class="checkbox" data-field="' + esc( kind ) + '" ' +
							'value="' + esc( field.key ) + '"' +
							( chosen.indexOf( field.key ) !== -1 ? ' checked' : '' ) + '>' +
						'<span>' + esc( field.label ) + '</span>' +
					'</label>';
				} ).join( '' ) +
			'</div>' +
		'</section>';
	};

	Reports.filtersMarkup = function ( source ) {
		var App = Reports.App;

		if ( ! source || ! source.filters.length ) {
			return '';
		}

		return '<section class="theme-group">' +
			'<h3 class="subhead">' + esc( App.t( 'reports.filters' ) ) + '</h3>' +
			'<div class="theme-group__fields">' +
				source.filters.map( function ( filter ) {
					return Reports.filterField( filter );
				} ).join( '' ) +
			'</div>' +
		'</section>';
	};

	Reports.filterField = function ( filter ) {
		var App = Reports.App;
		var value = Reports.draft.filters[ filter.key ];

		if ( 'date_range' === filter.type ) {
			value = value || {};

			return '<div class="field">' +
				'<label class="field__label">' + esc( filter.label ) + '</label>' +
				'<span class="field__row">' +
					'<input class="input" type="date" data-range="' + esc( filter.key ) + '" ' +
						'data-edge="from" value="' + esc( value.from || '' ) + '" ' +
						'aria-label="' + esc( App.t( 'reports.from' ) ) + '">' +
					'<input class="input" type="date" data-range="' + esc( filter.key ) + '" ' +
						'data-edge="to" value="' + esc( value.to || '' ) + '" ' +
						'aria-label="' + esc( App.t( 'reports.to' ) ) + '">' +
				'</span>' +
			'</div>';
		}

		if ( 'enum' === filter.type ) {
			return '<div class="field">' +
				'<label class="field__label" for="f-' + esc( filter.key ) + '">' +
					esc( filter.label ) + '</label>' +
				'<select class="select" id="f-' + esc( filter.key ) + '" ' +
					'data-filter="' + esc( filter.key ) + '">' +
					'<option value="">' + esc( App.t( 'reports.anyValue' ) ) + '</option>' +
					( filter.options || [] ).map( function ( option ) {
						return '<option value="' + esc( option ) + '"' +
							( value === option ? ' selected' : '' ) + '>' + esc( option ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>';
		}

		// An event filter is a select of this account's events, not a box to type an id into.
		return '<div class="field">' +
			'<label class="field__label" for="f-' + esc( filter.key ) + '">' +
				esc( filter.label ) + '</label>' +
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

	Reports.bindBuilder = function () {
		var App = Reports.App;

		document.getElementById( 'report-source' ).addEventListener( 'change', function () {
			// A new dataset means new fields; keeping the old ones would offer a report that the
			// server is about to refuse.
			Reports.draft.source = this.value;
			Reports.draft.dimensions = [];
			Reports.draft.measures = [];
			Reports.draft.filters = {};
			Reports.paintBuilder();
			Reports.run();
		} );

		each( '[data-field]', function ( box ) {
			box.addEventListener( 'change', function () {
				var list = 'dimension' === box.dataset.field
					? Reports.draft.dimensions
					: Reports.draft.measures;
				var at = list.indexOf( box.value );

				if ( box.checked && at === -1 ) {
					list.push( box.value );
				} else if ( ! box.checked && at !== -1 ) {
					list.splice( at, 1 );
				}

				Reports.run();
			} );
		} );

		each( '[data-filter]', function ( control ) {
			control.addEventListener( 'change', function () {
				if ( control.value ) {
					Reports.draft.filters[ control.dataset.filter ] = control.value;
				} else {
					delete Reports.draft.filters[ control.dataset.filter ];
				}

				Reports.run();
			} );
		} );

		each( '[data-range]', function ( control ) {
			control.addEventListener( 'change', function () {
				var key = control.dataset.range;
				var range = Reports.draft.filters[ key ] || {};

				range[ control.dataset.edge ] = control.value;

				if ( ! range.from && ! range.to ) {
					delete Reports.draft.filters[ key ];
				} else {
					Reports.draft.filters[ key ] = range;
				}

				Reports.run();
			} );
		} );

		document.getElementById( 'report-back' )
			.addEventListener( 'click', function () { Reports.render( App ); } );

		document.getElementById( 'report-save' )
			.addEventListener( 'click', function () { Reports.save(); } );

		var exporter = document.getElementById( 'report-export' );

		if ( exporter ) {
			exporter.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				Reports.download();
			} );
		}
	};

	Reports.definition = function () {
		return {
			dimensions: Reports.draft.dimensions,
			measures: Reports.draft.measures,
			filters: Reports.draft.filters,
		};
	};

	Reports.run = function () {
		var App = Reports.App;
		var host = document.getElementById( 'report-result' );

		if ( ! Reports.draft.measures.length ) {
			host.innerHTML = '<p class="muted">' + esc( App.t( 'reports.needMeasure' ) ) + '</p>';

			return;
		}

		host.innerHTML = '<p class="muted">' + esc( App.t( 'reports.running' ) ) + '</p>';

		App.request( 'POST', '/reports/run', {
			source: Reports.draft.source,
			definition: Reports.definition(),
		} ).then( function ( result ) {
			Reports.result = result;
			host.innerHTML = Reports.resultMarkup( result );
		} ).catch( function ( error ) {
			host.innerHTML = '<p class="muted">' + esc( error.message ) + '</p>';
		} );
	};

	/* -------------------------------------------------------------------------- rendering */

	Reports.resultMarkup = function ( result, type ) {
		var App = Reports.App;

		if ( ! result.rows.length ) {
			return '<p class="muted">' + esc( App.t( 'reports.noRows' ) ) + '</p>';
		}

		var chart = '';

		if ( 'bar' === type || 'line' === type ) {
			chart = Reports.chart( result, type );
		} else if ( 'stat' === type ) {
			return Reports.stat( result );
		}

		return chart + App.table(
			result.columns.map( function ( column ) {
				return { label: column.label, numeric: 'measure' === column.kind };
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
		var measure = result.columns.filter( function ( c ) { return 'measure' === c.kind; } )[ 0 ];

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

		App.request( 'GET', '/report-pages/' + id ).then( function ( page ) {
			Reports.page = page;
			Reports.paintPage();
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Reports.paintPage = function () {
		var App = Reports.App;
		var page = Reports.page;

		App.page( {
			title: page.name,
			actions:
				'<button class="btn" id="page-back">' + icon( 'back', { size: 15 } ) +
					esc( App.t( 'reports.back' ) ) + '</button>' +
				'<button class="btn" id="page-add">' + icon( 'plus', { size: 15 } ) +
					esc( App.t( 'reports.addWidget' ) ) + '</button>' +
				'<button class="btn btn--danger" id="page-delete">' +
					esc( App.t( 'reports.delete' ) ) + '</button>',
			body: page.widgets.length
				? '<div class="widgets">' + page.widgets.map( function ( widget, index ) {
					return '<section class="widget widget--' + esc( widget.width || 'full' ) + '">' +
						'<div class="widget__head">' +
							'<h3>' + esc( widget.title || '' ) + '</h3>' +
							'<button class="btn btn--sm" data-remove="' + index + '">' +
								esc( App.t( 'reports.remove' ) ) + '</button>' +
						'</div>' +
						( widget.error
							? '<p class="muted">' + esc( App.t( 'reports.widgetError' ) ) + '</p>'
							: Reports.resultMarkup( widget, widget.type ) ) +
					'</section>';
				} ).join( '' ) + '</div>'
				: App.emptyState( 'chart', App.t( 'reports.noPages' ), App.t( 'reports.addWidget' ) ),
		} );

		document.getElementById( 'page-back' )
			.addEventListener( 'click', function () { Reports.render( App ); } );

		document.getElementById( 'page-add' )
			.addEventListener( 'click', function () { Reports.addWidget(); } );

		document.getElementById( 'page-delete' ).addEventListener( 'click', function () {
			App.modal( {
				title: App.t( 'reports.deletePageTitle' ),
				submitLabel: App.t( 'reports.delete' ),
				body: '<p>' + esc( App.t( 'reports.deletePageBody' ) ) + '</p>',
				onSubmit: function () {
					return App.request( 'DELETE', '/report-pages/' + page.id ).then( function () {
						App.toast( App.t( 'reports.pageDeleted' ) );
						Reports.render( App );
					} );
				},
			} );
		} );

		each( '[data-remove]', function ( button ) {
			button.addEventListener( 'click', function () {
				var widgets = Reports.widgetDefinitions();

				widgets.splice( Number( button.dataset.remove ), 1 );
				Reports.saveWidgets( widgets );
			} );
		} );
	};

	/** The page as it is stored, rather than as it is rendered. */
	Reports.widgetDefinitions = function () {
		return Reports.page.widgets.map( function ( widget ) {
			return {
				type: widget.type,
				report_id: widget.report_id,
				title: widget.title,
				width: widget.width || 'full',
			};
		} );
	};

	Reports.addWidget = function () {
		var App = Reports.App;

		if ( ! Reports.saved.length ) {
			App.toast( App.t( 'reports.noReportsHint' ), true );

			return;
		}

		App.modal( {
			title: App.t( 'reports.addWidget' ),
			submitLabel: App.t( 'reports.addWidget' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="w-report">' +
					esc( App.t( 'reports.saved' ) ) + '</label>' +
				'<select class="select" id="w-report" name="report_id">' +
					Reports.saved.map( function ( report ) {
						return '<option value="' + esc( report.id ) + '">' + esc( report.name ) +
							'</option>';
					} ).join( '' ) +
				'</select></div>' +
				'<div class="field"><label class="field__label" for="w-type">' +
					esc( App.t( 'reports.widgetType' ) ) + '</label>' +
				'<select class="select" id="w-type" name="type">' +
					[ 'table', 'bar', 'line', 'stat' ].map( function ( type ) {
						return '<option value="' + type + '">' +
							esc( App.t( 'reports.types.' + type ) ) + '</option>';
					} ).join( '' ) +
				'</select></div>' +
				'</div>',
			onSubmit: function ( data ) {
				var widgets = Reports.widgetDefinitions();

				widgets.push( {
					type: data.get( 'type' ),
					report_id: data.get( 'report_id' ),
					width: 'full',
				} );

				return Reports.saveWidgets( widgets );
			},
		} );
	};

	Reports.saveWidgets = function ( widgets ) {
		var App = Reports.App;

		return App.request( 'PATCH', '/report-pages/' + Reports.page.id, { widgets: widgets } )
			.then( function () {
				App.toast( App.t( 'reports.pageSaved' ) );
				Reports.openPage( Reports.page.id );
			} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* --------------------------------------------------------------------------- helpers */

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
