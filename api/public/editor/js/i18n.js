/**
 * The panel's half of the six languages (ADR-0005).
 *
 * The catalogue is fetched from `/v1/i18n/<locale>` and comes from the same PHP files the server
 * renders sites and emails from. There is no second copy in a `.js` file, because two copies of a
 * translation is two translations, and the one that is wrong is always the one nobody is looking at.
 *
 * `t()` is synchronous, because a render loop that had to await a string would either flash English
 * or not render. So the catalogue is loaded once before the workspace paints, and `t()` reads it.
 * Until then it falls back to the key's last segment, which is ugly and readable — and never
 * happens on a working install, because nothing paints before `load()` resolves.
 */
( function ( global ) {
	'use strict';

	var STORE = { locale: 'seatmap.locale' };

	var I18n = {
		locale: 'en',
		dir: 'ltr',
		icu: 'en-GB',
		messages: {},
		locales: [],
		loaded: false,
	};

	/**
	 * Which language to ask for.
	 *
	 * A choice made here is remembered per browser, and it is the *only* thing that outranks the
	 * server: someone who set the panel to German meant the panel, not one visit to it. When they
	 * have made no choice, the server decides from their account and their headers.
	 */
	I18n.preferred = function () {
		try {
			return window.localStorage.getItem( STORE.locale ) || null;
		} catch ( error ) {
			return null;
		}
	};

	I18n.remember = function ( locale ) {
		try {
			window.localStorage.setItem( STORE.locale, locale );
		} catch ( error ) {
			// A browser with storage switched off still works; the choice just lasts one session.
		}
	};

	/**
	 * Load the catalogue. Resolves either way: a panel with no translations is a bad panel, and a
	 * panel that refuses to open because a catalogue is missing is a broken one.
	 */
	I18n.load = function ( api ) {
		var self = this;
		var preferred = this.preferred();

		return fetch( api + '/i18n', { headers: headers( preferred ) } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( index ) {
				self.locales = index.locales || [];

				// The server's answer, unless this browser has said otherwise.
				var locale = preferred && supported( self.locales, preferred ) ? preferred : index.current;

				return fetch( api + '/i18n/' + locale, { headers: headers( locale ) } )
					.then( function ( response ) { return response.json(); } );
			} )
			.then( function ( catalogue ) {
				self.locale = catalogue.locale;
				self.dir = catalogue.dir;
				self.icu = catalogue.icu;
				self.messages = catalogue.messages || {};
				self.loaded = true;

				self.applyDirection();
			} )
			.catch( function () {
				// Leave the defaults in place and let the panel open in English.
				self.loaded = true;
			} );
	};

	/** Direction and language on the document, once, from the resolved locale. */
	I18n.applyDirection = function () {
		document.documentElement.setAttribute( 'lang', this.locale );
		document.documentElement.setAttribute( 'dir', this.dir );
	};

	I18n.choose = function ( locale ) {
		this.remember( locale );
		window.location.reload();
	};

	/**
	 * Look up `namespace.some.key`, substituting `:name` placeholders.
	 *
	 * A missing key returns its own last segment rather than an empty string: a button labelled
	 * "enable" is usable, and a button labelled nothing is not.
	 */
	I18n.t = function ( key, replace ) {
		var value = key.split( '.' ).reduce( function ( carry, part ) {
			return carry && typeof carry === 'object' ? carry[ part ] : undefined;
		}, this.messages );

		if ( 'string' !== typeof value ) {
			return key.split( '.' ).pop();
		}

		if ( ! replace ) {
			return value;
		}

		return Object.keys( replace ).reduce( function ( carry, name ) {
			return carry.split( ':' + name ).join( String( replace[ name ] ) );
		}, value );
	};

	/**
	 * How many decimal places a currency has, from the browser's own ICU data.
	 *
	 * Two for a euro, none for a rial, three for a dinar. Asked rather than assumed, because
	 * assuming two turns 500,000 rials into 5,000 — the same hundredfold error in the other
	 * direction that the server's minor-unit table exists to prevent.
	 */
	I18n.currencyDecimals = function ( currency ) {
		try {
			return new Intl.NumberFormat( 'en', { style: 'currency', currency: currency } )
				.resolvedOptions().minimumFractionDigits;
		} catch ( error ) {
			return 2;
		}
	};

	/** Money, in the currency charged and the shape this reader reads (ADR-0005 §5). */
	I18n.money = function ( minorUnits, currency, decimals ) {
		if ( null === minorUnits || undefined === minorUnits ) {
			return '';
		}

		var places = 'number' === typeof decimals ? decimals : this.currencyDecimals( currency );
		var value = minorUnits / Math.pow( 10, places );

		try {
			return new Intl.NumberFormat( this.icu, {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: places,
				maximumFractionDigits: places,
			} ).format( value );
		} catch ( error ) {
			return value.toFixed( places ) + ' ' + ( currency || '' );
		}
	};

	/** A plain number in this reader's digits — a seat count, a failure count. */
	I18n.number = function ( value ) {
		try {
			return new Intl.NumberFormat( this.icu ).format( value );
		} catch ( error ) {
			return String( value );
		}
	};

	/**
	 * A date, in this reader's language *and* calendar. The ICU locale carries both, so an Iranian
	 * reader gets ۷ مهر ۱۴۰۵ rather than a date they would have to convert.
	 */
	I18n.date = function ( value, options ) {
		if ( ! value ) {
			return '';
		}

		try {
			return new Intl.DateTimeFormat( this.icu, options || {
				dateStyle: 'medium',
				timeStyle: 'short',
			} ).format( new Date( value ) );
		} catch ( error ) {
			return String( value );
		}
	};

	function headers( locale ) {
		var head = { Accept: 'application/json' };

		if ( locale ) {
			head[ 'X-Seatmap-Locale' ] = locale;
		}

		return head;
	}

	function supported( locales, code ) {
		return locales.some( function ( entry ) { return entry.code === code; } );
	}

	global.SeatmapI18n = I18n;
}( window ) );
