/**
 * Tickets on paper, from the panel.
 *
 * Two destinations, because a box office has two kinds of printer and only one of them is on the
 * counter. A roll printer takes the bytes: the server builds them, the browser hands them to a
 * local print agent as a file, and the ticket is out before the drawer has closed. Everything else
 * — a laser printer in the office, a roll printer whose driver is installed, an event whose name
 * is in Persian — goes through the browser's own print dialogue, which can lay out any script in
 * the world where a printer's code page cannot.
 *
 * Both are drawn from the same list of lines the API returns, so the two cannot disagree about
 * which seat somebody has.
 */
( function ( global ) {
	'use strict';

	var Receipts = {};
	var WIDTH_KEY = 'seatmap.receipt.width';
	var DESTINATION_KEY = 'seatmap.receipt.destination';

	/* --------------------------------------------------------------------- what was chosen */

	function remembered( key, fallback, allowed ) {
		try {
			var stored = global.localStorage.getItem( key );

			return -1 === allowed.indexOf( stored ) ? fallback : stored;
		} catch ( error ) {
			// A browser with storage switched off still prints; it just asks every time.
			return fallback;
		}
	}

	function remember( key, value ) {
		try {
			global.localStorage.setItem( key, value );
		} catch ( error ) {
			/* Nothing to do: the choice is a convenience, not the ticket. */
		}
	}

	/* ------------------------------------------------------------------------- the button */

	/**
	 * Ask where it is going, then send it there.
	 *
	 * `path` is the receipts endpoint — a whole booking or one seat — and `name` is what a
	 * downloaded file should be called.
	 */
	Receipts.ask = function ( App, path, name ) {
		var width = remembered( WIDTH_KEY, '80', [ '58', '80' ] );
		var destination = remembered( DESTINATION_KEY, 'browser', [ 'browser', 'escpos' ] );

		App.modal( {
			title: App.t( 'panel.printing.title' ),
			submitLabel: App.t( 'panel.printing.print' ),
			body:
				'<div class="stack">' +
					'<p class="muted">' + esc( App.t( 'panel.printing.reissues' ) ) + '</p>' +
					'<div class="field"><label class="field__label" for="print-destination">' +
						esc( App.t( 'panel.printing.destination' ) ) + '</label>' +
						'<select class="input" id="print-destination">' +
							option( 'browser', App.t( 'panel.printing.browser' ), destination ) +
							option( 'escpos', App.t( 'panel.printing.roll' ), destination ) +
						'</select>' +
						'<p class="field__hint">' + esc( App.t( 'panel.printing.rollHint' ) ) + '</p></div>' +
					'<div class="field"><label class="field__label" for="print-width">' +
						esc( App.t( 'panel.printing.width' ) ) + '</label>' +
						'<select class="input" id="print-width">' +
							option( '80', App.t( 'panel.printing.mm80' ), width ) +
							option( '58', App.t( 'panel.printing.mm58' ), width ) +
						'</select></div>' +
				'</div>',
			onSubmit: function () {
				var chosenWidth = document.getElementById( 'print-width' ).value;
				var chosenDestination = document.getElementById( 'print-destination' ).value;

				remember( WIDTH_KEY, chosenWidth );
				remember( DESTINATION_KEY, chosenDestination );

				return 'escpos' === chosenDestination
					? Receipts.toRoll( App, path, chosenWidth, name )
					: Receipts.toBrowser( App, path, chosenWidth );
			},
		} );
	};

	/**
	 * Print it where it went last time, without asking.
	 *
	 * What a counter wants: the ticket comes out as the sale completes, and the operator chose the
	 * destination once, days ago. Everywhere else asks, because everywhere else is somebody
	 * printing one ticket for one reason.
	 */
	Receipts.send = function ( App, path, name ) {
		var width = remembered( WIDTH_KEY, '80', [ '58', '80' ] );

		return ( 'escpos' === remembered( DESTINATION_KEY, 'browser', [ 'browser', 'escpos' ] )
			? Receipts.toRoll( App, path, width, name )
			: Receipts.toBrowser( App, path, width ) )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/**
	 * The bytes, as a file.
	 *
	 * Handed over as a blob rather than opened as a link, because an anchor carries no
	 * Authorization header and a token in a query string is a token in somebody's server log.
	 */
	Receipts.toRoll = function ( App, path, width, name ) {
		return App.request( 'GET', path + query( width, 'escpos' ), null, { raw: true } )
			.then( function ( blob ) {
				var url = global.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = ( String( name || 'tickets' ).replace( /[^A-Za-z0-9._-]/g, '' ) || 'tickets' ) + '.bin';
				document.body.appendChild( link );
				link.click();
				link.remove();
				global.URL.revokeObjectURL( url );
				App.toast( App.t( 'panel.printing.sent' ) );
			} );
	};

	Receipts.toBrowser = function ( App, path, width ) {
		return App.request( 'GET', path + query( width, 'json' ) ).then( function ( response ) {
			Receipts.paint( App, response.data || [], parseInt( width, 10 ) || 80 );
		} );
	};

	/* ---------------------------------------------------------------------- the print view */

	/**
	 * The tickets, in a frame of their own, at the width of the roll.
	 *
	 * A frame rather than a new window: a pop-up blocker eats the window, and the operator finds
	 * out only when the queue asks where their ticket is. The frame is removed once the dialogue
	 * closes — kept until then, because a frame taken out of the page mid-print prints nothing.
	 */
	Receipts.paint = function ( App, receipts, width ) {
		var frame = document.createElement( 'iframe' );

		frame.setAttribute( 'aria-hidden', 'true' );
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';

		/*
		 * Printed on load, and loaded from `srcdoc` rather than written into the frame.
		 *
		 * The QR codes are images, and a page printed before they decode is a strip of paper nobody
		 * can be let in on. `srcdoc` fires load once they have; a document written with `write()` may
		 * have finished before the listener is attached, which is the same bug arriving silently.
		 */
		frame.addEventListener( 'load', function () {
			frame.contentWindow.focus();
			frame.contentWindow.print();

			// Kept in the page until the dialogue is done with it: a frame removed mid-print prints
			// nothing, and the operator finds out from the person at the window.
			global.setTimeout( function () { frame.remove(); }, 60000 );
		} );

		frame.srcdoc = Receipts.markup( App, receipts, width );
		document.body.appendChild( frame );
	};

	Receipts.markup = function ( App, receipts, width ) {
		var direction = document.documentElement.getAttribute( 'dir' ) || 'ltr';
		var language = document.documentElement.getAttribute( 'lang' ) || 'en';

		return '<!doctype html><html lang="' + esc( language ) + '" dir="' + esc( direction ) + '">' +
			'<head><meta charset="utf-8"><title>' + esc( App.t( 'panel.printing.title' ) ) + '</title>' +
			'<style>' + Receipts.styles( width ) + '</style></head><body>' +
			receipts.map( function ( receipt, index ) {
				return '<article class="ticket' + ( index === receipts.length - 1 ? ' ticket--last' : '' ) + '">' +
					( receipt.lines || [] ).map( Receipts.line ).join( '' ) +
				'</article>';
			} ).join( '' ) +
			'</body></html>';
	};

	Receipts.line = function ( line ) {
		switch ( line.kind ) {
			case 'title':
				return '<h1>' + esc( line.text ) + '</h1>';
			case 'seat':
				return '<p class="seat">' + esc( line.text ) + '</p>';
			case 'centre':
				return '<p class="centre">' + esc( line.text ) + '</p>';
			case 'small':
				return '<p class="small">' + esc( line.text ) + '</p>';
			case 'rule':
				return '<hr>';
			case 'pair':
				return '<p class="pair"><span>' + esc( line.left ) + '</span>' +
					'<span>' + esc( line.right ) + '</span></p>';
			case 'qr':
				// No alt text: the characters are printed underneath it anyway, and a reader
				// announcing forty random characters helps nobody.
				return line.image
					? '<p class="qr"><img src="' + esc( line.image ) + '" alt=""></p>'
					: '';
			default:
				return '<p class="centre">' + esc( line.text || '' ) + '</p>';
		}
	};

	/**
	 * Millimetres, not pixels.
	 *
	 * A roll is a physical width and the printer's driver is told it in `@page`; everything inside
	 * is sized against that, so the same markup prints correctly on both widths anybody buys.
	 */
	Receipts.styles = function ( width ) {
		var paper = ( 58 === width ? 58 : 80 ) + 'mm';
		var body = 58 === width ? 3 : 3.4;

		return '@page { size: ' + paper + ' auto; margin: 3mm; }' +
			'* { box-sizing: border-box; }' +
			'body { margin: 0; width: ' + paper + '; font-family: -apple-system, "Segoe UI", ' +
				'"Noto Sans", "Vazirmatn", system-ui, sans-serif; font-size: ' + body + 'mm; ' +
				'line-height: 1.4; color: #000; background: #fff; }' +
			'.ticket { padding: 2mm 0 4mm; page-break-after: always; break-after: page; }' +
			'.ticket--last { page-break-after: auto; break-after: auto; }' +
			'h1 { font-size: ' + ( body * 1.5 ) + 'mm; margin: 0 0 1mm; text-align: center; }' +
			'.seat { font-size: ' + ( body * 2 ) + 'mm; font-weight: 700; text-align: center; ' +
				'margin: 2mm 0; }' +
			'.centre { text-align: center; margin: 0.6mm 0; }' +
			'.small { text-align: center; margin: 1mm 0 0; font-size: ' + ( body * 0.8 ) + 'mm; ' +
				'letter-spacing: 0.2mm; }' +
			'.pair { display: flex; justify-content: space-between; gap: 3mm; margin: 0.6mm 0; }' +
			'hr { border: 0; border-top: 0.3mm dashed #000; margin: 2mm 0; }' +
			'.qr { text-align: center; margin: 2mm 0 0; }' +
			'.qr img { width: ' + ( 58 === width ? 32 : 40 ) + 'mm; height: auto; }';
	};

	/* ------------------------------------------------------------------------------ helpers */

	function query( width, format ) {
		return '?width=' + encodeURIComponent( width ) + '&format=' + format;
	}

	function option( value, label, chosen ) {
		return '<option value="' + esc( value ) + '"' + ( value === chosen ? ' selected' : '' ) +
			'>' + esc( label ) + '</option>';
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapReceipts = Receipts;
}( typeof window !== 'undefined' ? window : globalThis ) );
