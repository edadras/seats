/**
 * The books, against the bank.
 *
 * Every other figure on the settlement screen is worked out from the orders in this database. That
 * is the right way round — a booking's arithmetic is frozen when it is paid and never recomputed —
 * but it means those figures agree with themselves and with nothing else. This section is the one
 * place on the platform where the numbers meet a number that did not come from here.
 *
 * The import is a column mapper rather than a fixed format, and that is not laziness: every card
 * processor names its columns differently and always will, so the honest design is to read the
 * headings out of whatever file the organiser exported and ask which is which. Anything else means
 * a venue editing a CSV in a spreadsheet before they can use their own ticketing system.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	/** What we need from a statement, and what a gateway is likely to call it. */
	var FIELDS = [ 'reference', 'kind', 'amount', 'fee', 'occurred_on', 'description' ];

	var GUESSES = {
		reference: [ 'reference', 'payment_intent', 'charge', 'source', 'id', 'transaction' ],
		kind: [ 'kind', 'type', 'reporting_category' ],
		amount: [ 'amount', 'gross', 'value' ],
		fee: [ 'fee', 'fees', 'commission' ],
		occurred_on: [ 'created', 'date', 'available_on', 'occurred' ],
		description: [ 'description', 'note', 'memo' ],
	};

	var Bank = { App: null, payouts: [], parsed: null };

	Bank.load = function ( App ) {
		Bank.App = App;

		return App.request( 'GET', '/settlement/gateway-payouts' )
			.then( function ( answer ) {
				Bank.payouts = answer.data || [];

				return answer;
			} )
			// The settlement table above is a separate question and should still render when this
			// one fails or is refused.
			.catch( function () { Bank.payouts = []; return { data: [] }; } );
	};

	Bank.markup = function ( App ) {
		var rows = Bank.payouts.map( function ( payout ) {
			return '<tr><td class="table__primary">' + esc( payout.reference ) +
				'<span class="muted on-own-line">' + esc( payout.gateway ) + '</span></td>' +
				'<td class="muted">' + esc( App.date( payout.paid_on ) ) + '</td>' +
				'<td class="tnum">' + esc( App.money( payout.net, payout.currency ) ) + '</td>' +
				'<td class="tnum">' + esc( App.number( payout.lines ) ) + '</td>' +
				'<td>' + ( payout.unexplained
					? '<span class="badge badge--warn">' + esc( App.t( 'panel.bank.unexplained', {
						count: App.number( payout.unexplained ),
					} ) ) + '</span>'
					: '<span class="badge badge--ok">' + esc( App.t( 'panel.bank.allExplained' ) ) + '</span>'
				) + '</td>' +
				'<td class="table__actions">' +
				'<button class="btn btn--sm" data-bank-open="' + esc( payout.id ) + '">' +
				icon( 'search', { size: 14 } ) + esc( App.t( 'panel.bank.open' ) ) + '</button>' +
				'<button class="btn btn--sm" data-bank-forget="' + esc( payout.id ) + '">' +
				icon( 'trash', { size: 14 } ) + esc( App.t( 'panel.common.remove' ) ) + '</button>' +
				'</td></tr>';
		} ).join( '' );

		return '<section class="card card--pad spaced" id="bank">' +
			'<div class="row row--between">' +
			'<div><h2 class="card__title">' + esc( App.t( 'panel.bank.title' ) ) + '</h2>' +
			'<p class="hint">' + esc( App.t( 'panel.bank.description' ) ) + '</p></div>' +
			'<button class="btn btn--primary" id="bank-import">' + icon( 'download', { size: 15 } ) +
			esc( App.t( 'panel.bank.import' ) ) + '</button>' +
			'</div>' +
			App.table(
				[
					App.t( 'panel.bank.statement' ),
					App.t( 'panel.bank.paidOn' ),
					App.t( 'panel.bank.net' ),
					App.t( 'panel.bank.lines' ),
					App.t( 'panel.common.status' ),
					'',
				],
				rows,
				App.emptyState( 'wallet', App.t( 'panel.bank.emptyTitle' ),
					esc( App.t( 'panel.bank.emptyBody' ) ) )
			) +
			'</section>';
	};

	Bank.bind = function ( App, reload ) {
		Bank.App = App;
		Bank.reload = reload;

		var add = document.getElementById( 'bank-import' );

		if ( add ) {
			add.addEventListener( 'click', function () { Bank.importer(); } );
		}

		each( '[data-bank-open]', function ( element ) {
			element.addEventListener( 'click', function () { Bank.open( element.dataset.bankOpen ); } );
		} );

		each( '[data-bank-forget]', function ( element ) {
			element.addEventListener( 'click', function () { Bank.forget( element.dataset.bankForget ); } );
		} );
	};

	/* ------------------------------------------------------------------------------ import */

	/**
	 * Step one: the statement's own totals, and the file.
	 *
	 * The totals are typed rather than summed from the file on purpose. They are what the gateway
	 * asserts and the bank shows, and a statement whose lines do not add up to them is itself a
	 * finding — which it could never be if this worked them out from the lines.
	 */
	Bank.importer = function () {
		var App = Bank.App;

		App.modal( {
			title: App.t( 'panel.bank.import' ),
			submitLabel: App.t( 'panel.bank.next' ),
			body:
				'<div class="stack">' +
				'<p class="hint">' + esc( App.t( 'panel.bank.importHint' ) ) + '</p>' +
				'<div class="field-duo">' +
				text( App, 'bk-gateway', 'panel.bank.gateway', 'stripe' ) +
				text( App, 'bk-reference', 'panel.bank.statement', '' ) +
				'</div>' +
				'<div class="field-duo">' +
				text( App, 'bk-currency', 'panel.bank.currency', 'EUR' ) +
				'<div class="field"><label class="field__label" for="bk-paid">' +
				esc( App.t( 'panel.bank.paidOn' ) ) + '</label>' +
				'<input class="input" id="bk-paid" name="paid_on" type="date" required></div>' +
				'</div>' +
				'<div class="field-duo">' +
				money( App, 'bk-gross', 'panel.bank.gross' ) +
				money( App, 'bk-fees', 'panel.bank.fees' ) +
				'</div>' +
				money( App, 'bk-net', 'panel.bank.net' ) +
				'<div class="field"><label class="field__label" for="bk-file">' +
				esc( App.t( 'panel.bank.file' ) ) + '</label>' +
				'<input class="input" id="bk-file" type="file" accept=".csv,text/csv">' +
				'<span class="field__hint">' + esc( App.t( 'panel.bank.fileHint' ) ) + '</span></div>' +
				'</div>',
			onSubmit: function ( data, panel ) {
				var file = panel.querySelector( '#bk-file' ).files[ 0 ];

				var head = {
					gateway: data.get( 'gateway' ),
					reference: data.get( 'reference' ),
					currency: ( data.get( 'currency' ) || '' ).toUpperCase(),
					paid_on: data.get( 'paid_on' ),
					gross: minor( data.get( 'gross' ) ),
					fees: minor( data.get( 'fees' ) ),
					net: minor( data.get( 'net' ) ),
				};

				if ( ! file ) {
					// A statement with no lines is still worth recording: the totals alone answer
					// "did this month's money arrive", which is most of the question.
					return Bank.send( head, [] );
				}

				return file.text().then( function ( csv ) {
					Bank.parsed = parse( csv );

					if ( ! Bank.parsed.rows.length ) {
						App.toast( App.t( 'panel.bank.noRows' ), true );

						return true;
					}

					Bank.mapper( head );
				} );
			},
		} );
	};

	/** Step two: which column is which. */
	Bank.mapper = function ( head ) {
		var App = Bank.App;
		var columns = Bank.parsed.columns;

		App.modal( {
			title: App.t( 'panel.bank.columns' ),
			submitLabel: App.t( 'panel.bank.record' ),
			body:
				'<div class="stack">' +
				'<p class="hint">' + esc( App.t( 'panel.bank.columnsHint', {
					count: App.number( Bank.parsed.rows.length ),
				} ) ) + '</p>' +
				FIELDS.map( function ( field ) {
					return '<div class="field"><label class="field__label" for="bk-col-' + field + '">' +
						esc( App.t( 'panel.bank.field.' + field ) ) + '</label>' +
						'<select class="select" id="bk-col-' + field + '" name="' + field + '">' +
						'<option value="">' + esc( App.t( 'panel.bank.ignore' ) ) + '</option>' +
						columns.map( function ( column ) {
							return '<option value="' + esc( column ) + '"' +
								( guess( field, columns ) === column ? ' selected' : '' ) + '>' +
								esc( column ) + '</option>';
						} ).join( '' ) +
						'</select></div>';
				} ).join( '' ) +
				'</div>',
			onSubmit: function ( data ) {
				var picked = {};

				FIELDS.forEach( function ( field ) { picked[ field ] = data.get( field ); } );

				return Bank.send( head, Bank.parsed.rows.map( function ( row ) {
					return {
						reference: picked.reference ? row[ picked.reference ] : '',
						kind: kindOf( picked.kind ? row[ picked.kind ] : '', picked.amount ? row[ picked.amount ] : '' ),
						amount: minor( picked.amount ? row[ picked.amount ] : '0' ),
						fee: minor( picked.fee ? row[ picked.fee ] : '0' ),
						occurred_on: picked.occurred_on ? ( row[ picked.occurred_on ] || null ) : null,
						description: picked.description ? row[ picked.description ] : null,
					};
				} ) );
			},
		} );
	};

	Bank.send = function ( head, lines ) {
		var App = Bank.App;

		return App.request( 'POST', '/settlement/gateway-payouts', Object.assign( {}, head, { lines: lines } ) )
			.then( function ( answer ) {
				App.toast( App.t( 'panel.bank.recorded' ) );
				Bank.reload();
				Bank.show( answer.data );
			} );
	};

	/* -------------------------------------------------------------------------- the answer */

	Bank.open = function ( id ) {
		Bank.App.request( 'GET', '/settlement/gateway-payouts/' + id )
			.then( function ( answer ) { Bank.show( answer.data ); } )
			.catch( function ( error ) { Bank.App.toast( error.message, true ); } );
	};

	/**
	 * One statement, in the four sentences there are to say about it.
	 *
	 * Ordered by what somebody does about it: the things that need explaining first, and the long
	 * list of everything that was fine at the bottom, collapsed.
	 */
	Bank.show = function ( answer ) {
		var App = Bank.App;
		var currency = answer.payout.currency;
		var sums = answer.arithmetic;

		var problems = '';

		if ( ! sums.lines_match_gross ) {
			problems += notice( App.t( 'panel.bank.linesOff', {
				lines: App.money( sums.lines_total, currency ),
				gross: App.money( answer.payout.gross, currency ),
			} ) );
		}

		if ( ! sums.line_fees_match ) {
			problems += notice( App.t( 'panel.bank.feesOff', {
				lines: App.money( sums.line_fees_total, currency ),
				fees: App.money( answer.payout.fees, currency ),
			} ) );
		}

		if ( ! sums.net_matches ) {
			problems += notice( App.t( 'panel.bank.netOff', {
				expected: App.money( sums.net_expected, currency ),
				net: App.money( answer.payout.net, currency ),
			} ) );
		}

		App.modal( {
			title: App.t( 'panel.bank.reconciliation' ),
			cancelLabel: null,
			doneLabel: App.t( 'panel.common.done' ),
			body:
				'<p class="hint">' + esc( App.t( 'panel.bank.of', {
					reference: answer.payout.reference,
					net: App.money( answer.payout.net, currency ),
					when: App.date( answer.payout.paid_on ),
				} ) ) + '</p>' +
				problems +
				bucket( App, 'differs', answer.differs, currency, true ) +
				bucket( App, 'unknown', answer.unknown, currency, true ) +
				bucket( App, 'missing', answer.missing, currency, true ) +
				bucket( App, 'matched', answer.matched, currency, false ),
		} );
	};

	Bank.forget = function ( id ) {
		var App = Bank.App;

		App.modal( {
			title: App.t( 'panel.bank.forget' ),
			submitLabel: App.t( 'panel.common.remove' ),
			danger: true,
			body: '<p>' + esc( App.t( 'panel.bank.forgetBody' ) ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/settlement/gateway-payouts/' + id )
					.then( function () {
						App.toast( App.t( 'panel.bank.forgotten' ) );
						Bank.reload();
					} );
			},
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function bucket( App, key, rows, currency, open ) {
		if ( ! rows.length ) {
			return '';
		}

		return '<details class="spaced"' + ( open ? ' open' : '' ) + '>' +
			'<summary><strong>' + esc( App.t( 'panel.bank.bucket.' + key, {
				count: App.number( rows.length ),
			} ) ) + '</strong></summary>' +
			'<p class="hint">' + esc( App.t( 'panel.bank.bucketHint.' + key ) ) + '</p>' +
			'<ul class="statement-lines">' + rows.map( function ( row ) {
				return '<li><span class="tnum">' + esc( row.reference || '—' ) + '</span> · ' +
					esc( App.money( row.amount, currency ) ) +
					( undefined !== row.expected
						? ' · ' + esc( App.t( 'panel.bank.weSaid', {
							amount: App.money( row.expected, currency ),
						} ) )
						: '' ) +
					( row.order_id ? ' · ' + esc( row.order_id ) : '' ) +
					( row.description ? ' · ' + esc( row.description ) : '' ) + '</li>';
			} ).join( '' ) + '</ul>' +
			'</details>';
	}

	function notice( text ) {
		return '<p class="notice notice--warn">' + esc( text ) + '</p>';
	}

	/**
	 * A CSV, read the way a spreadsheet writes one.
	 *
	 * Quoted fields with commas and doubled quotes inside them, because a description column is
	 * exactly where a comma turns up and a naive split would shift every column after it by one.
	 */
	function parse( csv ) {
		var rows = [];
		var row = [];
		var field = '';
		var quoted = false;
		var i = 0;

		csv = csv.replace( /^﻿/, '' ).replace( /\r\n?/g, '\n' );

		for ( ; i < csv.length; i++ ) {
			var c = csv[ i ];

			if ( quoted ) {
				if ( '"' === c && '"' === csv[ i + 1 ] ) {
					field += '"';
					i++;
				} else if ( '"' === c ) {
					quoted = false;
				} else {
					field += c;
				}

				continue;
			}

			if ( '"' === c ) {
				quoted = true;
			} else if ( ',' === c ) {
				row.push( field );
				field = '';
			} else if ( '\n' === c ) {
				row.push( field );
				rows.push( row );
				row = [];
				field = '';
			} else {
				field += c;
			}
		}

		if ( field.length || row.length ) {
			row.push( field );
			rows.push( row );
		}

		var columns = ( rows.shift() || [] ).map( function ( name ) { return name.trim(); } );

		return {
			columns: columns,
			rows: rows
				.filter( function ( line ) { return line.some( function ( cell ) { return cell.trim(); } ); } )
				.map( function ( line ) {
					var out = {};

					columns.forEach( function ( name, index ) { out[ name ] = ( line[ index ] || '' ).trim(); } );

					return out;
				} ),
		};
	}

	/** The column a gateway most likely means by this field. */
	function guess( field, columns ) {
		var wanted = GUESSES[ field ] || [];

		for ( var i = 0; i < wanted.length; i++ ) {
			for ( var j = 0; j < columns.length; j++ ) {
				if ( columns[ j ].toLowerCase().indexOf( wanted[ i ] ) > -1 ) {
					return columns[ j ];
				}
			}
		}

		return '';
	}

	/**
	 * What kind of line this is.
	 *
	 * Read from the column where there is one, and from the sign where there is not: a negative
	 * amount on a statement is money going back, and a gateway that does not label its rows still
	 * tells you that much.
	 */
	function kindOf( said, amount ) {
		var word = String( said || '' ).toLowerCase();

		if ( word.indexOf( 'refund' ) > -1 ) { return 'refund'; }
		if ( word.indexOf( 'fee' ) > -1 ) { return 'fee'; }
		if ( word.indexOf( 'charge' ) > -1 || word.indexOf( 'payment' ) > -1 ) { return 'payment'; }

		return String( amount || '' ).trim().indexOf( '-' ) === 0 ? 'refund' : 'payment';
	}

	/** Money, as somebody types it or as a gateway writes it, in minor units. */
	function minor( written ) {
		var text = String( written === null || written === undefined ? '' : written )
			.replace( /[^0-9.,\-]/g, '' )
			.replace( /,/g, '.' );

		var number = parseFloat( text );

		return isNaN( number ) ? 0 : Math.round( number * 100 );
	}

	function text( App, id, key, placeholder ) {
		var name = id.replace( 'bk-', '' );

		return '<div class="field"><label class="field__label" for="' + id + '">' +
			esc( App.t( key ) ) + '</label>' +
			'<input class="input" id="' + id + '" name="' + name + '" required maxlength="160" ' +
			'placeholder="' + esc( placeholder ) + '"></div>';
	}

	function money( App, id, key ) {
		var name = id.replace( 'bk-', '' );

		return '<div class="field"><label class="field__label" for="' + id + '">' +
			esc( App.t( key ) ) + '</label>' +
			'<input class="input" id="' + id + '" name="' + name + '" required inputmode="decimal" ' +
			'placeholder="0.00"></div>';
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapBank = Bank;
}( typeof window !== 'undefined' ? window : globalThis ) );
