/**
 * How fast one night is selling, and what happens to the people who look at it.
 *
 * "Two hundred sold" is not an answer. Two hundred out of a thousand people who looked is a
 * pricing problem; two hundred out of two hundred and twelve is a marketing one, and the remedies
 * are opposite. So this screen leads with a rate and a funnel, and the total is the small print.
 *
 * The chart is drawn as SVG by hand rather than pulled from a library: it is bars and one line,
 * the panel ships no charting dependency, and a dependency that renders one screen is a dependency
 * that has to be kept current for the life of the product.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Pace = {
		App: null,
		eventId: null,
		eventName: '',
		days: 30,
		data: null,
	};

	Pace.open = function ( App, eventId, eventName ) {
		Pace.App = App;
		Pace.eventId = eventId;
		Pace.eventName = eventName || '';

		App.loading( App.t( 'panel.pace.title' ) );
		Pace.load();
	};

	Pace.load = function () {
		var App = Pace.App;

		App.request( 'GET', '/events/' + Pace.eventId + '/pace?days=' + Pace.days )
			.then( function ( data ) {
				Pace.data = data;
				Pace.paint();
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Pace.paint = function () {
		var App = Pace.App;
		var data = Pace.data;

		App.page( {
			title: Pace.eventName || App.t( 'panel.pace.title' ),
			description: esc( App.t( 'panel.pace.description' ) ),
			actions:
				'<div class="row">' +
					'<select class="select select--sm" id="pace-days" aria-label="' +
						esc( App.t( 'panel.pace.window' ) ) + '">' +
						[ 7, 30, 90 ].map( function ( days ) {
							return '<option value="' + days + '"' +
								( days === Pace.days ? ' selected' : '' ) + '>' +
								esc( App.t( 'panel.pace.lastDays', { count: App.number( days ) } ) ) +
							'</option>';
						} ).join( '' ) +
					'</select>' +
					'<button class="btn" id="pace-back">' + icon( 'back', { size: 15 } ) +
						esc( App.t( 'panel.pace.back' ) ) + '</button>' +
				'</div>',
			body:
				Pace.tiles() +
				'<p class="hint" id="pace-sentence">' + esc( Pace.sentence() ) + '</p>' +
				'<h2 class="subhead">' + esc( App.t( 'panel.pace.curveTitle' ) ) + '</h2>' +
				Pace.chart() +
				'<h2 class="subhead">' + esc( App.t( 'panel.pace.funnelTitle' ) ) + '</h2>' +
				'<p class="hint">' + esc( App.t( 'panel.pace.funnelHint' ) ) + '</p>' +
				Pace.funnel(),
		} );

		document.getElementById( 'pace-back' ).addEventListener( 'click', function () {
			App.renderEvents();
		} );

		document.getElementById( 'pace-days' ).addEventListener( 'change', function () {
			Pace.days = Number( this.value );
			Pace.load();
		} );
	};

	/* ------------------------------------------------------------------------------- tiles */

	Pace.tiles = function () {
		var App = Pace.App;
		var pace = Pace.data.pace;

		function tile( label, value, note ) {
			return '<div class="stat stat--block">' +
				'<span class="stat__value tnum">' + esc( value ) + '</span>' +
				'<span class="stat__label">' + esc( label ) + '</span>' +
				( note ? '<span class="stat__note">' + esc( note ) + '</span>' : '' ) +
			'</div>';
		}

		return '<div class="stat-grid">' +
			tile(
				App.t( 'panel.pace.sold' ),
				App.number( pace.sold ) + ' / ' + App.number( pace.capacity ),
				App.t( 'panel.pace.soldShare', { percent: App.number( Math.round( pace.sold_share * 100 ) ) } )
			) +
			tile( App.t( 'panel.pace.daily' ), App.number( pace.daily ),
				App.t( 'panel.pace.overDays', { count: App.number( pace.days_counted ) } ) ) +
			tile(
				App.t( 'panel.pace.toDoors' ),
				null === pace.days_to_doors ? '—' : App.number( pace.days_to_doors ),
				App.t( 'panel.pace.daysWord' )
			) +
			tile(
				App.t( 'panel.pace.projected' ),
				null === pace.projected_sold ? '—' : App.number( pace.projected_sold ),
				App.t( 'panel.pace.ofCapacity', { count: App.number( pace.capacity ) } )
			) +
		'</div>';
	};

	/**
	 * The one sentence somebody reads.
	 *
	 * Deliberately hedged — "at this rate" — because it is arithmetic and not a forecast: a
	 * straight line is exactly wrong for a run that sells out in its final three days, and every
	 * organiser knows that about their own audience. Saying so is the difference between a number
	 * they can use and one they learn to distrust.
	 */
	Pace.sentence = function () {
		var App = Pace.App;
		var pace = Pace.data.pace;

		if ( pace.sold_out ) {
			return App.t( 'panel.pace.saysSoldOut' );
		}

		if ( ! pace.daily ) {
			return App.t( 'panel.pace.saysNothingLately' );
		}

		if ( pace.sells_out_on ) {
			return App.t( 'panel.pace.saysSellsOut', { when: Pace.dayName( pace.sells_out_on ) } );
		}

		return App.t( 'panel.pace.saysShortOf', {
			count: App.number( pace.projected_sold ),
			capacity: App.number( pace.capacity ),
		} );
	};

	/* ------------------------------------------------------------------------------- chart */

	/**
	 * Bars for seats sold, a line for people looking.
	 *
	 * Two series on one picture because the interesting days are the ones where they disagree: a
	 * spike in looking with no bar under it is the day an email went out and the price put people
	 * off. Each series is scaled to its own maximum, and the legend says so — a shared axis would
	 * flatten the seats to nothing the moment a thousand people looked at once.
	 */
	Pace.chart = function () {
		var App = Pace.App;
		var curve = Pace.data.curve;
		var width = 720;
		var height = 180;
		var pad = 4;
		var step = curve.length ? ( width - pad * 2 ) / curve.length : 0;
		var mostSold = Math.max.apply( null, curve.map( function ( d ) { return d.places; } ).concat( [ 1 ] ) );
		var mostSeen = Math.max.apply( null, curve.map( function ( d ) { return d.views; } ).concat( [ 1 ] ) );

		var bars = curve.map( function ( day, i ) {
			var tall = Math.round( ( height - 20 ) * ( day.places / mostSold ) );

			return '<rect class="chart__bar" x="' + ( pad + i * step + step * 0.15 ) + '" y="' +
				( height - 16 - tall ) + '" width="' + ( step * 0.7 ) + '" height="' + Math.max( 0, tall ) + '">' +
				'<title>' + esc( Pace.dayName( day.day ) + ' — ' +
					App.t( 'panel.pace.dayTip', {
						sold: App.number( day.places ),
						views: App.number( day.views ),
					} ) ) + '</title>' +
			'</rect>';
		} ).join( '' );

		var line = curve.map( function ( day, i ) {
			var y = height - 16 - Math.round( ( height - 20 ) * ( day.views / mostSeen ) );

			return ( i ? 'L' : 'M' ) + ( pad + i * step + step / 2 ) + ' ' + y;
		} ).join( ' ' );

		return '<div class="chart">' +
			'<svg class="chart__svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" ' +
				'aria-label="' + esc( App.t( 'panel.pace.chartAlt', {
					from: Pace.dayName( Pace.data.from ),
					to: Pace.dayName( Pace.data.to ),
				} ) ) + '">' +
				bars +
				( curve.length > 1 ? '<path class="chart__line" d="' + line + '"></path>' : '' ) +
			'</svg>' +
			'<div class="chart__legend">' +
				'<span class="chart__key chart__key--bar">' + esc( App.t( 'panel.pace.legendSold', {
					count: App.number( mostSold ),
				} ) ) + '</span>' +
				'<span class="chart__key chart__key--line">' + esc( App.t( 'panel.pace.legendViews', {
					count: App.number( mostSeen ),
				} ) ) + '</span>' +
			'</div>' +
		'</div>';
	};

	/* ------------------------------------------------------------------------------ funnel */

	Pace.funnel = function () {
		var App = Pace.App;
		var funnel = Pace.data.funnel;
		var widest = Math.max( funnel.looked, funnel.baskets, funnel.checkouts, funnel.bought, 1 );

		var steps = [
			[ 'looked', funnel.looked, null ],
			[ 'baskets', funnel.baskets, funnel.basket_rate ],
			[ 'checkouts', funnel.checkouts, funnel.checkout_rate ],
			[ 'bought', funnel.bought, funnel.buy_rate ],
		];

		return '<div class="funnel">' +
			steps.map( function ( step ) {
				return '<div class="funnel__step">' +
					'<span class="funnel__label">' + esc( App.t( 'panel.pace.step.' + step[ 0 ] ) ) + '</span>' +
					'<span class="funnel__bar"><span class="funnel__fill" style="inline-size:' +
						Math.max( 2, Math.round( 100 * step[ 1 ] / widest ) ) + '%"></span></span>' +
					'<span class="funnel__count tnum">' + esc( App.number( step[ 1 ] ) ) + '</span>' +
					// Null, not nought: a step with nothing above it has no rate at all, and a
					// bold 0% under "looked" would read as a failure rather than as no question.
					'<span class="funnel__rate tnum">' + esc( null === step[ 2 ] || undefined === step[ 2 ]
						? '—'
						: App.number( Math.round( step[ 2 ] * 100 ) ) + '%' ) + '</span>' +
				'</div>';
			} ).join( '' ) +
			( undefined === funnel.amount
				? ''
				: '<p class="hint">' + esc( App.t( 'panel.pace.took', {
					amount: App.money( funnel.amount, Pace.data.currency ),
				} ) ) + '</p>' ) +
		'</div>';
	};

	/**
	 * A day, written as a day.
	 *
	 * The panel's ordinary date formatting carries a clock with it, and "12 Aug, 00:00" on a bucket
	 * that is a whole day is a midnight nothing happened at.
	 */
	Pace.dayName = function ( value ) {
		return Pace.App.date( value, { dateStyle: 'medium' } );
	};

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	global.SeatmapPace = Pace;
}( window ) );
