/**
 * The icon set.
 *
 * Hand-authored 24×24 stroke paths on a shared grid: 1.75 stroke, round caps and joins, drawn to
 * the same optical weight so a toolbar of them reads as one family. `currentColor` throughout, so
 * an icon takes the colour of whatever it sits in and needs no variants for themes or states.
 *
 * These replace the emoji that were here before. Emoji are a different weight, colour and metric on
 * every platform, they cannot inherit state, and in a toolbar they are the single loudest signal
 * that something is a prototype.
 */
( function ( global ) {
	'use strict';

	var PATHS = {
		/* --- panel navigation ---------------------------------------------------------- */
		calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		map: '<path d="M4 6.5 9.5 4v13.5L4 20zM9.5 4 15 6.5v13.5L9.5 17.5zM15 6.5 20 4v13.5L15 20z"/>',
		building: '<path d="M4 21V5.5A1.5 1.5 0 0 1 5.5 4h7A1.5 1.5 0 0 1 14 5.5V21M14 21V10h4.5A1.5 1.5 0 0 1 20 11.5V21M3 21h18"/><path d="M7 8h3M7 12h3M7 16h3"/>',
		plug: '<path d="M9 3v6M15 3v6M6 9h12v3a6 6 0 0 1-12 0zM12 18v3"/>',
		// A piece with one tab and one notch: enough to read as "fits into something" at 16px,
		// where a four-lobed jigsaw piece turns into a blob.
		puzzle: '<path d="M10.2 4.2a1.8 1.8 0 0 1 3.6 0V6h3.4a.8.8 0 0 1 .8.8v3.4h1.8a1.8 1.8 0 0 1 0 3.6H18v3.4a.8.8 0 0 1-.8.8h-3.4v-1.8a1.8 1.8 0 0 0-3.6 0V18H6.8a.8.8 0 0 1-.8-.8v-3.4H4.2a1.8 1.8 0 0 1 0-3.6H6V6.8A.8.8 0 0 1 6.8 6h3.4Z"/>',
		logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h11"/>',
		user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
		// Two people, the second half-hidden behind the first: enough to read as "more than one"
		// without drawing a crowd nobody can resolve at 16px.
		users: '<circle cx="9.5" cy="8" r="3.2"/><path d="M3.5 19.5a6 6 0 0 1 12 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6"/><path d="M17.5 14.4a6 6 0 0 1 3 5.1"/>',
		// A clock with its hand turned back: the log, not the schedule.
		history: '<path d="M3.6 12a8.4 8.4 0 1 1 2.5 6"/><path d="M3 14.5 6.1 18l3.4-3"/><path d="M12 7.5V12l3 1.8"/>',
		key: '<circle cx="8" cy="14" r="4"/><path d="M11 11l8-8M17 5l2 2M14.5 7.5l2 2"/>',

		/* --- actions ------------------------------------------------------------------- */
		plus: '<path d="M12 5v14M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		check: '<path d="m5 12.5 4.5 4.5L19 7"/>',
		close: '<path d="M6 6l12 12M18 6L6 18"/>',
		search: '<circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>',
		settings: '<circle cx="12" cy="12" r="3.1"/><path d="M12 2.8h.9l.4 2.2a7.2 7.2 0 0 1 1.9 1.1l2.1-.8.9 1.5-1.7 1.5a7.2 7.2 0 0 1 0 2.2l1.7 1.5-.9 1.5-2.1-.8a7.2 7.2 0 0 1-1.9 1.1l-.4 2.2h-1.8l-.4-2.2a7.2 7.2 0 0 1-1.9-1.1l-2.1.8-.9-1.5 1.7-1.5a7.2 7.2 0 0 1 0-2.2L5.6 6.8l.9-1.5 2.1.8a7.2 7.2 0 0 1 1.9-1.1l.4-2.2Z"/>',
		trash: '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/>',
		copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"/>',
		duplicate: '<rect x="4" y="4" width="11" height="11" rx="2"/><path d="M9 20h9a2 2 0 0 0 2-2V9"/>',
		undo: '<path d="M8 8H5V5"/><path d="M5.5 8.5A7.5 7.5 0 1 1 4.6 14"/>',
		redo: '<path d="M16 8h3V5"/><path d="M18.5 8.5A7.5 7.5 0 1 0 19.4 14"/>',
		eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/>',
		sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2.5 12h2M19.5 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
		moon: '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/>',
		lock: '<rect x="4.5" y="10" width="15" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
		unlock: '<rect x="4.5" y="10" width="15" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/>',
		help: '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.4"/><path d="M12 17.2v.01"/>',
		save: '<path d="M5 5.5A1.5 1.5 0 0 1 6.5 4h9L20 8.5v10A1.5 1.5 0 0 1 18.5 20h-12A1.5 1.5 0 0 1 5 18.5Z"/><path d="M8.5 4v5h6M8.5 20v-5h7v5"/>',
		publish: '<path d="M12 16V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4.5 15v3.5A1.5 1.5 0 0 0 6 20h12a1.5 1.5 0 0 0 1.5-1.5V15"/>',
		back: '<path d="M11 6l-6 6 6 6M5 12h14"/>',

		/* --- designer chrome ------------------------------------------------------------ */
		target: '<circle cx="12" cy="12" r="7.5"/><circle cx="12" cy="12" r="2.5"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3"/>',
		tag: '<path d="M4 11.5V5a1 1 0 0 1 1-1h6.5a2 2 0 0 1 1.4.6l7 7a2 2 0 0 1 0 2.8l-6.1 6.1a2 2 0 0 1-2.8 0l-7-7a2 2 0 0 1-.6-1.4Z"/><circle cx="8" cy="8" r="1.4"/>',
		flipH: '<path d="M12 3v18"/><path d="M9 7.5 4 12l5 4.5zM15 7.5 20 12l-5 4.5z"/>',
		flipV: '<path d="M3 12h18"/><path d="M7.5 9 12 4l4.5 5zM7.5 15 12 20l4.5-5z"/>',
		layers: '<path d="m12 3 8.5 4.5L12 12 3.5 7.5Z"/><path d="m4.5 12 7.5 4 7.5-4M4.5 16.5l7.5 4 7.5-4"/>',
		fit: '<path d="M4 9V5.5A1.5 1.5 0 0 1 5.5 4H9M15 4h3.5A1.5 1.5 0 0 1 20 5.5V9M20 15v3.5a1.5 1.5 0 0 1-1.5 1.5H15M9 20H5.5A1.5 1.5 0 0 1 4 18.5V15"/>',

		/* --- tool palette --------------------------------------------------------------- */
		cursor: '<path d="M6 3.5 18.5 11l-5.2 1.6-2.4 5.1z"/>',
		lasso: '<path d="M12 4.5c4.7 0 8.5 2.5 8.5 5.6 0 2.3-2 4.2-5 5.1"/><path d="M12 4.5C7.3 4.5 3.5 7 3.5 10.1c0 2 1.6 3.8 4 4.8"/><path d="M7.5 15.3c0 1.6-.6 2.4-1.5 2.9"/><circle cx="5.4" cy="19.4" r="1.8"/>',
		wand: '<path d="m5 19 9.5-9.5M13 4.5l.7 1.9 1.9.7-1.9.7-.7 1.9-.7-1.9-1.9-.7 1.9-.7zM19 11l.5 1.4 1.4.5-1.4.5-.5 1.4-.5-1.4-1.4-.5 1.4-.5z"/><path d="m13.5 8.5 2 2"/>',
		row: '<circle cx="4.5" cy="12" r="1.9"/><circle cx="9.8" cy="12" r="1.9"/><circle cx="15.1" cy="12" r="1.9"/><circle cx="20.4" cy="12" r="1.9"/>',
		curvedRow: '<path d="M3 9c3.5 4.5 14.5 4.5 18 0" stroke-dasharray="0.1 4.6" stroke-width="3.6"/><path d="M3 15.5c3.5 4 14.5 4 18 0" opacity=".45"/>',
		section: '<path d="M4 8.5 12 4l8 4.5V16l-8 4.5L4 16Z"/><path d="M8 11h8M8 14.5h8"/>',
		table: '<circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="4.6" r="1.5"/><circle cx="12" cy="19.4" r="1.5"/><circle cx="4.6" cy="12" r="1.5"/><circle cx="19.4" cy="12" r="1.5"/>',
		booth: '<rect x="3.5" y="6" width="17" height="12" rx="1.5"/><path d="M3.5 12h17M12 6v12"/>',
		area: '<rect x="3.5" y="6.5" width="17" height="11" rx="2.5" stroke-dasharray="3 2.6"/><path d="M8.5 12h7" stroke-dasharray="0"/>',
		shape: '<rect x="4.5" y="4.5" width="15" height="15" rx="2"/>',
		line: '<path d="M4.5 19.5 19.5 4.5"/><circle cx="4.5" cy="19.5" r="1.6"/><circle cx="19.5" cy="4.5" r="1.6"/>',
		text: '<path d="M5 6.5V5h14v1.5M12 5v14M9 19h6"/>',
		image: '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="m4.5 17 4.7-4.5a1.5 1.5 0 0 1 2 0L16 17M14 14.5l1.4-1.3a1.5 1.5 0 0 1 2 0l2.1 2"/>',
		accessibility: '<circle cx="12" cy="4.8" r="1.9"/><path d="M8 8.6h8M12 8.6v5h4.5M12 13.6 9.5 20"/><path d="M16.5 13.6 19 20"/>',
		hand: '<path d="M8 11V6.2a1.6 1.6 0 0 1 3.2 0V11m0-1.2a1.6 1.6 0 0 1 3.2 0V11m0-.6a1.6 1.6 0 0 1 3.2 0v4.4a5.5 5.5 0 0 1-5.5 5.5h-1a5 5 0 0 1-3.7-1.6L4 16.4a1.6 1.6 0 0 1 2.3-2.2L8 15.8V11"/>',

		/* --- inspector and misc ---------------------------------------------------------- */
		chevronDown: '<path d="m7 10 5 5 5-5"/>',
		chevronRight: '<path d="m10 7 5 5-5 5"/>',
		alert: '<path d="M12 4.5 21 19.5H3Z"/><path d="M12 10v4M12 17v.01"/>',
		info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.01"/>',
		seat: '<path d="M6.5 10V6.5A2.5 2.5 0 0 1 9 4h6a2.5 2.5 0 0 1 2.5 2.5V10"/><path d="M5 10.5h14v5H5zM6.5 15.5V20M17.5 15.5V20"/>',
		grid: '<path d="M4 9h16M4 15h16M9 4v16M15 4v16"/>',

		/* --- hosted sites ---------------------------------------------------------------- */
		globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.4 5.3 3.4 8.5s-1.1 6.1-3.4 8.5c-2.3-2.4-3.4-5.3-3.4-8.5S9.7 5.9 12 3.5Z"/>',
		file: '<path d="M13.5 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8.5Z"/><path d="M13.5 3.5v5h5"/>',
		external: '<path d="M14 4h6v6M20 4l-8.5 8.5"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		arrowUp: '<path d="M12 19V5M6 11l6-6 6 6"/>',
		arrowDown: '<path d="M12 5v14M6 13l6 6 6-6"/>',
		palette: '<path d="M12 3.5a8.5 8.5 0 0 0 0 17c1.4 0 2-.9 2-1.8 0-.5-.2-.9-.5-1.2-.3-.4-.5-.7-.5-1.2 0-.9.7-1.6 1.6-1.6h1.5a4.4 4.4 0 0 0 4.4-4.4C20.5 6.6 16.7 3.5 12 3.5Z"/><circle cx="7.5" cy="11" r="1.1"/><circle cx="10" cy="7.5" r="1.1"/><circle cx="14.5" cy="7.5" r="1.1"/>',
		list: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
		ticket: '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h13A1.5 1.5 0 0 1 20 8.5v2a2 2 0 0 0 0 3v2A1.5 1.5 0 0 1 18.5 17h-13A1.5 1.5 0 0 1 4 15.5v-2a2 2 0 0 0 0-3Z"/><path d="M13.5 7.4v1.4M13.5 11.3v1.4M13.5 15.2v1.4"/>',
	};

	/**
	 * Venue markers — the symbols that go *on* a chart rather than in the chrome around it.
	 *
	 * Plain path data rather than markup, because these are drawn twice: as SVG in the panel, and
	 * with Path2D on the canvas, where there is no DOM to hand a markup fragment to.
	 */
	var VENUE = {
		wheelchair: 'M13.9 4.8a1.9 1.9 0 1 1-3.8 0 1.9 1.9 0 1 1 3.8 0M8 8.6h8M12 8.6v5h4.5M12 13.6 9.5 20M16.5 13.6 19 20',
		toilets: 'M8.6 5.4a1.4 1.4 0 1 1-2.8 0 1.4 1.4 0 1 1 2.8 0M7.2 8.4v5.8M5.7 9.8h3M6.1 14.2V20M8.3 14.2V20M17.7 5.4a1.4 1.4 0 1 1-2.8 0 1.4 1.4 0 1 1 2.8 0M16.3 8.4 14.1 14.6h4.4L16.3 8.4M15.3 14.6V20M17.3 14.6V20',
		bar: 'M4.5 5h15l-7.5 7.5zM12 12.5V19M8.5 19h7',
		food: 'M8 4v6.5M11 4v6.5M9.5 4v6.5M9.5 10.5V20M16.5 4c-1.4 1.4-2.1 3.1-2.1 5s.7 3.1 2.1 3.5V20',
		entrance: 'M13.5 4H19a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5.5M9.5 8l4 4-4 4M13.5 12H4',
		exit: 'M10.5 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5.5M14.5 8l4 4-4 4M18.5 12H8',
		stairs: 'M3.5 20h4.5v-4h4.5v-4H17V7.5h3.5',
		lift: 'M5 3.5h14a1.5 1.5 0 0 1 1.5 1.5v14a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V5A1.5 1.5 0 0 1 5 3.5ZM9.5 10.5 12 7l2.5 3.5M9.5 13.5 12 17l2.5-3.5',
	};

	// Registered under a prefix so they can be rendered like any other icon as well.
	Object.keys( VENUE ).forEach( function ( name ) {
		PATHS[ 'venue-' + name ] = '<path d="' + VENUE[ name ] + '"/>';
	} );

	/**
	 * Render an icon.
	 *
	 * Decorative by default — the label lives on the button that contains it, so announcing the
	 * icon as well would make a screen reader say everything twice.
	 */
	function icon( name, options ) {
		options = options || {};

		var body = PATHS[ name ];

		if ( ! body ) {
			return '';
		}

		return '<svg class="icon' + ( options.className ? ' ' + options.className : '' ) + '"' +
			' viewBox="0 0 24 24" width="' + ( options.size || 20 ) + '" height="' + ( options.size || 20 ) + '"' +
			' fill="none" stroke="currentColor" stroke-width="' + ( options.strokeWidth || 1.75 ) + '"' +
			' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			body + '</svg>';
	}

	/** The same icon as a detached element, for code that builds DOM rather than markup. */
	function iconNode( name, options ) {
		var wrapper = document.createElement( 'span' );
		wrapper.innerHTML = icon( name, options );

		return wrapper.firstChild;
	}

	icon.node = iconNode;
	icon.has = function ( name ) {
		return Object.prototype.hasOwnProperty.call( PATHS, name );
	};
	icon.names = function () {
		return Object.keys( PATHS );
	};

	/** Raw path data for a venue marker, for drawing it on a canvas. */
	icon.venuePath = function ( name ) {
		return Object.prototype.hasOwnProperty.call( VENUE, name ) ? VENUE[ name ] : null;
	};

	icon.venueNames = function () {
		return Object.keys( VENUE );
	};

	global.SeatmapIcon = icon;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = icon;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
