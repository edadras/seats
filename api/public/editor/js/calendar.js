/**
 * Typing a date in the calendar the venue actually keeps.
 *
 * Showing a Jalali date is the easy half and `Intl` does it. The half that decides whether the
 * feature is real is *entry*: an organiser in Tehran announcing a concert on ۸ مهر ۱۴۰۵ should not
 * have to convert it to 30 September 2026 in their head before typing it into a box, and a browser's
 * own date control is Gregorian and cannot be asked otherwise.
 *
 * So this upgrades the controls that already exist rather than replacing them everywhere.
 *
 *   **In Gregorian, nothing happens at all.** The native `date` and `datetime-local` controls are
 *   better than anything written here — they know the reader's keyboard, their screen reader and
 *   their phone — so they are left exactly as they are.
 *
 *   **In Jalali, every one of them is upgraded in place.** The original input stays in the DOM and
 *   keeps its name and its value in the format the server already expects; a text box beside it
 *   shows and accepts the same instant written in Jalali. That is why this works on twenty-five
 *   date fields across fourteen screens without any of them being edited — and on the ones written
 *   next year too.
 *
 * The conversion is arithmetic rather than a library: Borkowski's algorithm, which is exact and is
 * about thirty lines. `calendar_smoke` checks it against ICU's own answer for every day across
 * several years, which is a stronger test than any library's changelog.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Dates = { App: null };

	/* ------------------------------------------------------------------ the arithmetic */

	/*
	 * Borkowski's algorithm, in the form the Iranian calendar is actually published in.
	 *
	 * The leap years are not a clean cycle — they come from the list of years at which the pattern
	 * breaks, which is what the official calendar uses and what ICU agrees with. A 33-year
	 * approximation is close and is wrong a few days a century, which for a venue announcing next
	 * season is a wrong date on a poster.
	 *
	 * Everything goes through a Julian day number, so there is one conversion in each direction and
	 * both are reversible. `calendar_smoke` checks every day of several years against ICU's own
	 * answer, in both directions.
	 */
	/*
	 * Truncating division, not flooring, and a remainder that matches it.
	 *
	 * The algorithm is written for a language where integer division rounds toward zero, and
	 * several of its intermediate terms go negative — `div(8 - gm, 6)` for any month after August,
	 * for one. `Math.floor` there is off by one, and the error grows with the date rather than
	 * showing up as a constant offset, which is exactly the kind of wrong that looks right in
	 * February and is two years out by September.
	 */
	function div( a, b ) {
		return Math.trunc( a / b );
	}

	function mod( a, b ) {
		return a - Math.trunc( a / b ) * b;
	}

	/** Julian day number for a Gregorian date. */
	function g2d( gy, gm, gd ) {
		var d = div( ( gy + div( gm - 8, 6 ) + 100100 ) * 1461, 4 ) +
			div( 153 * mod( gm + 9, 12 ) + 2, 5 ) + gd - 34840408;

		return d - div( div( gy + 100100 + div( gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
	}

	/** And back. */
	function d2g( jdn ) {
		var j = 4 * jdn + 139361631;

		j = j + div( div( 4 * jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;

		var i = div( mod( j, 1461 ), 4 ) * 5 + 308;
		var gd = div( mod( i, 153 ), 5 ) + 1;
		var gm = mod( div( i, 153 ), 12 ) + 1;

		return { year: div( j, 1461 ) - 100100 + div( 8 - gm, 6 ), month: gm, day: gd };
	}

	/**
	 * Where a Jalali year starts and whether it is long.
	 *
	 * `BREAKS` is the published list of years at which the leap pattern changes. Nothing about it
	 * can be derived; it is the calendar.
	 */
	var BREAKS = [
		-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
		1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
	];

	function jalaliYear( jy ) {
		var gy = jy + 621;
		var leapJ = -14;
		var jp = BREAKS[ 0 ];
		var jump = 0;
		var jm;
		var i;

		if ( jy < jp || jy >= BREAKS[ BREAKS.length - 1 ] ) {
			return null;
		}

		for ( i = 1; i < BREAKS.length; i++ ) {
			jm = BREAKS[ i ];
			jump = jm - jp;

			if ( jy < jm ) {
				break;
			}

			leapJ = leapJ + div( jump, 33 ) * 8 + div( mod( jump, 33 ), 4 );
			jp = jm;
		}

		var n = jy - jp;

		leapJ = leapJ + div( n, 33 ) * 8 + div( mod( n, 33 ) + 3, 4 );

		if ( 4 === mod( jump, 33 ) && 4 === jump - n ) {
			leapJ += 1;
		}

		var leapG = div( gy, 4 ) - div( ( div( gy, 100 ) + 1 ) * 3, 4 ) - 150;
		var march = 20 + leapJ - leapG;

		if ( jump - n < 6 ) {
			n = n - jump + div( jump + 4, 33 ) * 33;
		}

		var leap = mod( mod( n + 1, 33 ) - 1, 4 );

		return { leap: -1 === leap ? 4 : leap, gy: gy, march: march };
	}

	/** Julian day number for a Jalali date. */
	function j2d( jy, jm, jd ) {
		var about = jalaliYear( jy );

		if ( ! about ) {
			return null;
		}

		return g2d( about.gy, 3, about.march ) + ( jm - 1 ) * 31 - div( jm, 7 ) * ( jm - 7 ) + jd - 1;
	}

	/** Gregorian → Jalali. Months are 1-based in both directions. */
	Dates.toJalali = function ( gy, gm, gd ) {
		var jdn = g2d( gy, gm, gd );
		var jy = d2g( jdn ).year - 621;
		var about = jalaliYear( jy );

		if ( ! about ) {
			return { year: jy, month: 1, day: 1 };
		}

		var k = jdn - g2d( about.gy, 3, about.march );

		if ( k >= 0 ) {
			if ( k <= 185 ) {
				return { year: jy, month: 1 + div( k, 31 ), day: mod( k, 31 ) + 1 };
			}

			k -= 186;
		} else {
			jy -= 1;
			k += 179;

			// The leap flag of the year we started from, not of the one we just stepped back into:
			// it is what decides whether the year before it was 365 days or 366.
			if ( 1 === about.leap ) {
				k += 1;
			}
		}

		return { year: jy, month: 7 + div( k, 30 ), day: mod( k, 30 ) + 1 };
	};

	/** Jalali → Gregorian. */
	Dates.toGregorian = function ( jy, jm, jd ) {
		var jdn = j2d( jy, jm, jd );

		return null === jdn ? { year: jy + 621, month: 1, day: 1 } : d2g( jdn );
	};

	/** How many days a Jalali month has: 31, then 30, and Esfand is 29 or 30. */
	Dates.monthLength = function ( jy, jm ) {
		if ( jm <= 6 ) {
			return 31;
		}

		if ( jm <= 11 ) {
			return 30;
		}

		var about = jalaliYear( jy );

		return about && 1 === about.leap ? 30 : 29;
	};

	/* ------------------------------------------------------------------ the control */

	/** Which calendar the panel is reading in, from the locale string the server composed. */
	Dates.calendar = function () {
		var App = Dates.App;
		var icu = App && App.locale ? App.locale() : '';

		return /-ca-persian/.test( icu ) ? 'persian' : 'gregory';
	};

	/**
	 * Upgrade every date control on the screen. Does nothing at all in Gregorian.
	 *
	 * Called from the same place picture fields are woken, so a screen written tomorrow gets this
	 * without knowing it exists.
	 */
	Dates.wire = function ( App ) {
		Dates.App = App;

		if ( 'persian' !== Dates.calendar() ) {
			return;
		}

		each( 'input[type=date], input[type="datetime-local"]', function ( native ) {
			if ( ! native.dataset.jalali ) {
				Dates.upgrade( native );
			}
		} );
	};

	Dates.upgrade = function ( native ) {
		var App = Dates.App;
		var withTime = 'datetime-local' === native.type;

		native.dataset.jalali = '1';

		var wrap = document.createElement( 'div' );
		wrap.className = 'jalali';

		native.parentNode.insertBefore( wrap, native );
		wrap.appendChild( native );

		/*
		 * The original stays, hidden but focusable.
		 *
		 * It keeps its `name`, its value in the format the server reads, and every listener already
		 * attached to it. Focusable rather than `display:none` because a `required` control the
		 * browser cannot focus makes the whole form refuse to submit, with a message in the console
		 * and nothing on the screen.
		 */
		native.classList.add( 'jalali__native' );

		var box = document.createElement( 'input' );
		box.className = native.className.replace( 'jalali__native', '' ).trim() || 'input';
		box.classList.remove( 'jalali__native' );
		box.type = 'text';
		box.inputMode = 'numeric';
		box.autocomplete = 'off';
		box.placeholder = withTime ? '۱۴۰۵/۰۷/۰۸ ۲۰:۳۰' : '۱۴۰۵/۰۷/۰۸';
		box.setAttribute( 'aria-label', App.t( 'panel.shell.calendar_persian' ) );

		if ( native.required ) {
			// Moved rather than copied: two required controls for one answer is a form that refuses
			// twice for the same reason.
			native.required = false;
			box.required = true;
		}

		var open = document.createElement( 'button' );
		open.type = 'button';
		open.className = 'icon-btn icon-btn--sm jalali__open';
		open.innerHTML = icon( 'calendar', { size: 15 } );
		open.setAttribute( 'aria-label', App.t( 'panel.shell.calendarOpen' ) );

		wrap.appendChild( box );
		wrap.appendChild( open );

		box.value = Dates.write( native.value, withTime );

		box.addEventListener( 'change', function () {
			Dates.take( native, box, withTime );
		} );

		open.addEventListener( 'click', function () {
			Dates.picker( wrap, native, box, withTime );
		} );

		// Something else filled it in — a form reset, a screen repainting around it.
		native.addEventListener( 'change', function () {
			if ( ! native.dataset.fromBox ) {
				box.value = Dates.write( native.value, withTime );
			}
		} );
	};

	/** An ISO-ish local value, written out in Jalali. */
	Dates.write = function ( value, withTime ) {
		var parts = Dates.readNative( value );

		if ( ! parts ) {
			return '';
		}

		var jalali = Dates.toJalali( parts.year, parts.month, parts.day );
		var text = digits( jalali.year, 4 ) + '/' + digits( jalali.month, 2 ) + '/' + digits( jalali.day, 2 );

		return withTime ? text + ' ' + digits( parts.hour, 2 ) + ':' + digits( parts.minute, 2 ) : text;
	};

	/** What the organiser typed, taken as an instant. Anything unreadable is left alone. */
	Dates.take = function ( native, box, withTime ) {
		var typed = latin( box.value ).trim();

		if ( '' === typed ) {
			Dates.put( native, '' );
			box.value = '';

			return;
		}

		var found = typed.match( /^(\d{3,4})\D+(\d{1,2})\D+(\d{1,2})(?:\D+(\d{1,2})\D+(\d{1,2}))?/ );

		if ( ! found ) {
			// Put back what was there. A box that empties itself because somebody mistyped is a box
			// that loses the date they had.
			box.value = Dates.write( native.value, withTime );

			return;
		}

		var jy = Number( found[ 1 ] );
		var jm = Math.min( 12, Math.max( 1, Number( found[ 2 ] ) ) );
		var jd = Math.min( Dates.monthLength( jy, jm ), Math.max( 1, Number( found[ 3 ] ) ) );
		var when = Dates.toGregorian( jy, jm, jd );
		var value = digits( when.year, 4 ) + '-' + digits( when.month, 2 ) + '-' + digits( when.day, 2 );

		if ( withTime ) {
			value += 'T' + digits( Math.min( 23, Number( found[ 4 ] || 0 ) ), 2 ) +
				':' + digits( Math.min( 59, Number( found[ 5 ] || 0 ) ), 2 );
		}

		Dates.put( native, value );
		box.value = Dates.write( value, withTime );
	};

	/**
	 * Write into the original and tell the screen, exactly as a person typing into it would.
	 *
	 * `fromBox` keeps the echo out: the original's own `change` listener would otherwise rewrite the
	 * box in the middle of somebody editing it.
	 */
	Dates.put = function ( native, value ) {
		native.dataset.fromBox = '1';
		native.value = value;
		native.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		delete native.dataset.fromBox;
	};

	/** A month, to pick a day out of. */
	Dates.picker = function ( wrap, native, box, withTime ) {
		var existing = wrap.querySelector( '.jalali__month' );

		if ( existing ) {
			existing.remove();

			return;
		}

		var parts = Dates.readNative( native.value ) || Dates.readNative( Dates.today() );
		var showing = Dates.toJalali( parts.year, parts.month, parts.day );
		var month = document.createElement( 'div' );

		month.className = 'jalali__month';
		wrap.appendChild( month );

		function paint( jy, jm ) {
			var first = Dates.toGregorian( jy, jm, 1 );
			var weekday = new Date( first.year, first.month - 1, first.day ).getDay();
			// Saturday starts the week in this calendar, and JavaScript counts from Sunday.
			var lead = ( weekday + 1 ) % 7;
			var length = Dates.monthLength( jy, jm );
			var chosen = Dates.readNative( native.value );
			var chosenJ = chosen ? Dates.toJalali( chosen.year, chosen.month, chosen.day ) : null;
			var cells = '';

			for ( var blank = 0; blank < lead; blank++ ) {
				cells += '<span></span>';
			}

			for ( var day = 1; day <= length; day++ ) {
				var picked = chosenJ && chosenJ.year === jy && chosenJ.month === jm && chosenJ.day === day;

				cells += '<button type="button" data-day="' + day + '"' +
					( picked ? ' class="is-picked"' : '' ) + '>' + digits( day, 0 ) + '</button>';
			}

			month.innerHTML =
				'<div class="jalali__head">' +
					'<button type="button" data-step="-1" aria-label="' +
						esc( Dates.App.t( 'panel.shell.calendarBack' ) ) + '">‹</button>' +
					'<strong>' + esc( MONTHS[ jm - 1 ] ) + ' ' + digits( jy, 0 ) + '</strong>' +
					'<button type="button" data-step="1" aria-label="' +
						esc( Dates.App.t( 'panel.shell.calendarNext' ) ) + '">›</button>' +
				'</div>' +
				'<div class="jalali__week">' + WEEKDAYS.map( function ( name ) {
					return '<span>' + esc( name ) + '</span>';
				} ).join( '' ) + '</div>' +
				'<div class="jalali__days">' + cells + '</div>';

			each( '[data-step]', function ( button ) {
				button.addEventListener( 'click', function () {
					var next = jm + Number( button.dataset.step );
					var year = jy;

					if ( next < 1 ) { next = 12; year -= 1; }
					if ( next > 12 ) { next = 1; year += 1; }

					paint( year, next );
				} );
			}, month );

			each( '[data-day]', function ( button ) {
				button.addEventListener( 'click', function () {
					var time = withTime
						? ' ' + digits( parts.hour, 2 ) + ':' + digits( parts.minute, 2 )
						: '';

					box.value = digits( jy, 4 ) + '/' + digits( jm, 2 ) + '/' +
						digits( Number( button.dataset.day ), 2 ) + time;

					Dates.take( native, box, withTime );
					month.remove();
				} );
			}, month );
		}

		paint( showing.year, showing.month );

		// One open at a time, and a click anywhere else closes it.
		window.setTimeout( function () {
			document.addEventListener( 'click', function away( event ) {
				if ( ! wrap.contains( event.target ) ) {
					month.remove();
					document.removeEventListener( 'click', away );
				}
			} );
		}, 0 );
	};

	/* ------------------------------------------------------------------ small things */

	var MONTHS = [
		'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
		'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
	];

	var WEEKDAYS = [ 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' ];

	/** `2026-09-30` or `2026-09-30T20:30`, taken apart. */
	Dates.readNative = function ( value ) {
		var found = String( value || '' ).match( /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/ );

		return found ? {
			year: Number( found[ 1 ] ),
			month: Number( found[ 2 ] ),
			day: Number( found[ 3 ] ),
			hour: Number( found[ 4 ] || 0 ),
			minute: Number( found[ 5 ] || 0 ),
		} : null;
	};

	Dates.today = function () {
		var now = new Date();

		return now.getFullYear() + '-' + digits( now.getMonth() + 1, 2 ) + '-' + digits( now.getDate(), 2 );
	};

	/** Persian and Arabic digits, as the numbers they are. */
	function latin( value ) {
		return String( value || '' )
			.replace( /[۰-۹]/g, function ( d ) { return String( '۰۱۲۳۴۵۶۷۸۹'.indexOf( d ) ); } )
			.replace( /[٠-٩]/g, function ( d ) { return String( '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) ); } );
	}

	/** A number, padded and written in the reader's own digits. */
	function digits( value, pad ) {
		var text = String( value );

		while ( text.length < pad ) {
			text = '0' + text;
		}

		try {
			return text.replace( /\d/g, function ( d ) {
				return new Intl.NumberFormat( Dates.App.locale(), { useGrouping: false } ).format( Number( d ) );
			} );
		} catch ( error ) {
			return text;
		}
	}

	function each( selector, visit, root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapCalendar = Dates;
}( typeof window !== 'undefined' ? window : globalThis ) );
