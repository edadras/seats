/**
 * The website screens: an organiser's own event site, edited entirely from this panel.
 *
 * Kept out of panel.js because it is a second application — a page editor, a theme picker, a menu
 * builder and a domain flow — and folding it into the file that also holds the shell would make
 * both harder to follow.
 *
 * Everything here writes to /v1/sites and re-reads the answer. The panel never assumes a write
 * succeeded in the shape it sent: block sanitising happens on the server, so what comes back is
 * what the site will actually render.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Sites = {};

	/* ------------------------------------------------------------------------- the listing */

	Sites.renderList = function ( App ) {
		App.loading( App.t( 'panel.sites.title' ) );

		App.request( 'GET', '/sites' )
			.then( function ( response ) {
				var rows = response.data.map( function ( site ) {
					var primary = ( site.domains || [] ).filter( function ( d ) { return d.is_primary; } )[ 0 ];
					var address = primary
						? ( primary.verified
							? '<a href="' + esc( site.url ) + '" target="_blank" rel="noreferrer noopener">' +
								esc( primary.hostname ) + '</a>'
							: '<span class="muted">' + esc( primary.hostname ) + '</span> ' +
								badge( App.t( 'panel.sites.notVerified' ), 'warn' ) )
						: '<span class="muted">' + esc( App.t( 'panel.sites.noAddress' ) ) + '</span>';

					return '<tr><td class="table__primary">' + esc( site.name ) + '</td>' +
						'<td>' + address + '</td>' +
						'<td>' + esc( titleCase( site.theme_key ) ) + '</td>' +
						'<td>' + Sites.statusBadge( App, site.status ) + '</td>' +
						'<td class="table__actions"><button class="btn btn--sm" data-site="' + esc( site.id ) + '">' +
						icon( 'settings', { size: 14 } ) + esc( App.t( 'panel.common.edit' ) ) +
						'</button></td></tr>';
				} ).join( '' );

				App.page( {
					title: App.t( 'panel.sites.title' ),
					description: esc( App.t( 'panel.sites.description' ) ),
					actions: '<button class="btn btn--primary" id="add-site">' +
						icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.sites.new' ) ) + '</button>',
					body: App.table(
						[
							App.t( 'panel.common.name' ),
							App.t( 'panel.common.address' ),
							App.t( 'panel.sites.theme' ),
							App.t( 'panel.common.status' ),
							'',
						],
						rows,
						App.emptyState( 'globe', App.t( 'panel.sites.emptyTitle' ),
							esc( App.t( 'panel.sites.emptyBody' ) ) )
					),
				} );

				document.getElementById( 'add-site' ).addEventListener( 'click', function () {
					Sites.create( App );
				} );

				App.main().querySelectorAll( '[data-site]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () { Sites.open( App, button.dataset.site ); } );
				} );
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	/** The live/draft badge, in one place: the listing and the editor bar both show it. */
	Sites.statusBadge = function ( App, status ) {
		return badge(
			App.t( 'live' === status ? 'panel.sites.statusLive' : 'panel.sites.statusDraft' ),
			'live' === status ? 'ok' : 'neutral'
		);
	};

	Sites.create = function ( App ) {
		App.modal( {
			title: App.t( 'panel.sites.new' ),
			submitLabel: App.t( 'panel.sites.create' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="s-name">' +
				esc( App.t( 'panel.common.name' ) ) + '</label>' +
				'<input class="input" id="s-name" name="name" required maxlength="120" ' +
				'placeholder="' + esc( App.t( 'panel.sites.namePlaceholder' ) ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.sites.nameHint' ) ) + '</span></div>' +
				'<div class="field"><label class="field__label" for="s-tz">' +
				esc( App.t( 'panel.common.timezone' ) ) + '</label>' +
				'<input class="input" id="s-tz" name="timezone" value="' + esc( App.timezone() ) + '"></div>' +
				'<div class="field"><label class="field__label" for="s-cur">' +
				esc( App.t( 'pricing.currency' ) ) + '</label>' +
				'<input class="input" id="s-cur" name="currency" maxlength="3" value="EUR"></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/sites', {
					name: data.get( 'name' ),
					timezone: data.get( 'timezone' ) || undefined,
					currency: ( data.get( 'currency' ) || 'EUR' ).toUpperCase(),
				} ).then( function ( site ) {
					App.toast( App.t( 'panel.sites.created' ) );
					Sites.open( App, site.id );
				} );
			},
		} );
	};

	/* -------------------------------------------------------------------------- the editor */

	Sites.open = function ( App, siteId ) {
		Promise.all( [
			App.request( 'GET', '/sites/' + siteId ),
			App.request( 'GET', '/site-themes' ),
			App.request( 'GET', '/events' ),
		] )
			.then( function ( results ) {
				Sites.state = {
					site: results[ 0 ],
					meta: results[ 1 ],
					events: results[ 2 ].data,
					tab: 'pages',
					pageId: ( results[ 0 ].pages || [] )[ 0 ] ? results[ 0 ].pages[ 0 ].id : null,
				};

				Sites.paint( App );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Sites.paint = function ( App ) {
		var state = Sites.state;
		var site = state.site;

		App.root.innerHTML =
			'<div class="designer">' +
				'<div class="designer__bar">' +
					'<div class="designer__title">' +
						'<button class="icon-btn" id="site-back" data-tip="' +
							esc( App.t( 'panel.sites.back' ) ) + '" ' +
							'aria-label="' + esc( App.t( 'panel.sites.back' ) ) + '">' +
							icon( 'back' ) + '</button>' +
						'<span class="designer__name">' + esc( site.name ) + '</span>' +
						Sites.statusBadge( App, site.status ) +
					'</div>' +
					'<span class="designer__spacer"></span>' +
					( site.url
						? '<a class="btn" href="' + esc( site.url ) + '" target="_blank" rel="noreferrer noopener">' +
							icon( 'external', { size: 15 } ) + esc( App.t( 'panel.sites.visit' ) ) + '</a>'
						: '' ) +
					( 'live' === site.status
						? '<button class="btn" id="site-offline">' +
							esc( App.t( 'panel.sites.takeOffline' ) ) + '</button>'
						: '<button class="btn btn--primary" id="site-live">' +
							icon( 'publish', { size: 15 } ) + esc( App.t( 'panel.sites.goLive' ) ) +
							'</button>' ) +
				'</div>' +
				'<div class="designer__body site-body">' +
					'<aside class="site-nav" id="site-nav"></aside>' +
					'<div class="site-main" id="site-main"></div>' +
				'</div>' +
			'</div>';

		document.getElementById( 'site-back' ).addEventListener( 'click', function () {
			App.showWorkspace();
			App.route( 'sites' );
		} );

		var live = document.getElementById( 'site-live' );
		var offline = document.getElementById( 'site-offline' );

		if ( live ) {
			live.addEventListener( 'click', function () { Sites.setStatus( App, 'live' ); } );
		}

		if ( offline ) {
			offline.addEventListener( 'click', function () { Sites.setStatus( App, 'draft' ); } );
		}

		Sites.paintNav( App );
		Sites.paintMain( App );
	};

	Sites.setStatus = function ( App, status ) {
		App.request( 'PATCH', '/sites/' + Sites.state.site.id, { status: status } )
			.then( function ( site ) {
				Sites.state.site = site;
				Sites.paint( App );
				App.toast( App.t( 'live' === status ? 'panel.sites.isLive' : 'panel.sites.isOffline' ) );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Sites.paintNav = function ( App ) {
		var state = Sites.state;
		var host = document.getElementById( 'site-nav' );

		host.innerHTML = '';

		var pages = node( 'div', 'site-nav__group' );
		pages.appendChild( node( 'div', 'overline site-nav__title', App.t( 'panel.sites.pages' ) ) );

		( state.site.pages || [] ).forEach( function ( page ) {
			var button = node( 'button', 'nav-item' );
			button.innerHTML = icon( 'file', { size: 15 } ) + '<span>' + esc( page.title ) + '</span>' +
				( page.has_unpublished_changes ? '<span class="dot dot--warn"></span>' : '' );

			if ( 'pages' === state.tab && page.id === state.pageId ) {
				button.classList.add( 'is-active' );
			}

			button.addEventListener( 'click', function () {
				state.tab = 'pages';
				state.pageId = page.id;
				Sites.paintNav( App );
				Sites.paintMain( App );
			} );

			pages.appendChild( button );
		} );

		var add = node( 'button', 'link-btn site-nav__add' );
		add.innerHTML = icon( 'plus', { size: 14 } ) + esc( App.t( 'panel.sites.newPage' ) );
		add.addEventListener( 'click', function () { Sites.newPage( App ); } );
		pages.appendChild( add );

		host.appendChild( pages );

		var settings = node( 'div', 'site-nav__group' );
		settings.appendChild(
			node( 'div', 'overline site-nav__title', App.t( 'panel.sites.settingsGroup' ) )
		);

		[
			[ 'design', 'palette', 'design' ],
			[ 'menus', 'list', 'menus' ],
			[ 'domains', 'globe', 'addressNav' ],
			[ 'signin', 'user', 'signinNav' ],
			[ 'invoicing', 'file', 'invoicingNav' ],
		].forEach( function ( entry ) {
			var button = node( 'button', 'nav-item' );
			button.innerHTML = icon( entry[ 1 ], { size: 15 } ) +
				'<span>' + esc( App.t( 'panel.sites.' + entry[ 2 ] ) ) + '</span>';

			if ( state.tab === entry[ 0 ] ) {
				button.classList.add( 'is-active' );
			}

			button.addEventListener( 'click', function () {
				state.tab = entry[ 0 ];
				Sites.paintNav( App );
				Sites.paintMain( App );
			} );

			settings.appendChild( button );
		} );

		host.appendChild( settings );
	};

	Sites.paintMain = function ( App ) {
		switch ( Sites.state.tab ) {
			case 'design': return Sites.paintDesign( App );
			case 'menus': return Sites.paintMenus( App );
			case 'domains': return Sites.paintDomains( App );
			case 'signin': return Sites.paintSignIn( App );
			case 'invoicing': return Sites.paintInvoicing( App );
			default: return Sites.paintPage( App );
		}
	};


	/* --------------------------------------------------------------------------- the page */

	Sites.currentPage = function () {
		var state = Sites.state;

		return ( state.site.pages || [] ).filter( function ( p ) { return p.id === state.pageId; } )[ 0 ] || null;
	};

	Sites.paintPage = function ( App ) {
		var page = Sites.currentPage();
		var host = document.getElementById( 'site-main' );

		if ( ! page ) {
			host.innerHTML = '<div class="site-pane">' +
				'<p class="muted">' + esc( App.t( 'panel.sites.pickPage' ) ) + '</p></div>';

			return;
		}

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline">' +
					'<div class="page-head__text">' +
						'<h1>' + esc( page.title ) + '</h1>' +
						'<p class="page-head__desc"><code>' + esc( page.path ) + '</code>' +
						( page.has_unpublished_changes
							? ' · ' + badge( App.t( 'panel.sites.unpublishedChanges' ), 'warn' )
							: ' · ' + ( page.published_at
								? badge( App.t( 'panel.sites.published' ), 'ok' )
								: badge( App.t( 'panel.sites.draft' ), 'neutral' ) ) ) +
						'</p>' +
					'</div>' +
					'<div class="page-head__actions">' +
						'<button class="btn" id="page-settings">' + icon( 'settings', { size: 15 } ) +
							esc( App.t( 'panel.common.settings' ) ) + '</button>' +
						( 'home' === page.kind || 'event' === page.kind ? ''
							: '<button class="btn btn--danger" id="page-delete">' + icon( 'trash', { size: 15 } ) + '</button>' ) +
						'<button class="btn btn--primary" id="page-publish">' +
							icon( 'publish', { size: 15 } ) + esc( App.t( 'panel.sites.publishPage' ) ) +
							'</button>' +
					'</div>' +
				'</div>' +
				'<div class="blocks" id="blocks"></div>' +
				'<div class="blocks__add" id="block-add"></div>' +
			'</div>';

		document.getElementById( 'page-publish' ).addEventListener( 'click', function () {
			Sites.publishPage( App );
		} );

		document.getElementById( 'page-settings' ).addEventListener( 'click', function () {
			Sites.pageSettings( App );
		} );

		var remove = document.getElementById( 'page-delete' );

		if ( remove ) {
			remove.addEventListener( 'click', function () { Sites.deletePage( App ); } );
		}

		Sites.paintBlocks( App );
	};

	Sites.paintBlocks = function ( App ) {
		var page = Sites.currentPage();
		var host = document.getElementById( 'blocks' );

		host.innerHTML = '';

		if ( ! ( page.blocks || [] ).length ) {
			host.appendChild( node( 'p', 'muted', App.t( 'panel.sites.emptyPage' ) ) );
		}

		( page.blocks || [] ).forEach( function ( block, index ) {
			host.appendChild( Sites.blockCard( App, block, index ) );
		} );

		var adder = document.getElementById( 'block-add' );
		adder.innerHTML = '<span class="overline">' + esc( App.t( 'panel.sites.addBlock' ) ) + '</span>';

		( Sites.state.meta.blocks || [] ).forEach( function ( kind ) {
			var button = node( 'button', 'btn btn--sm' );
			button.innerHTML = icon( kind.icon, { size: 14 } ) + esc( kind.name );
			button.addEventListener( 'click', function () { Sites.addBlock( App, kind.type ); } );
			adder.appendChild( button );
		} );
	};

	/**
	 * One block, with its own fields.
	 *
	 * Edited in place rather than in a modal: a page is a sequence, and being able to see the
	 * block above while writing the one below is most of what makes it feel like a page at all.
	 */
	Sites.blockCard = function ( App, block, index ) {
		var page = Sites.currentPage();
		var card = node( 'div', 'block' );

		var head = node( 'div', 'block__head' );
		head.innerHTML = '<span class="block__type">' + esc( Sites.blockName( block.type ) ) + '</span>';

		var tools = node( 'div', 'block__tools' );

		[
			[ 'arrowUp', 'panel.sites.moveUp', index > 0, function () { Sites.moveBlock( App, index, -1 ); } ],
			[ 'arrowDown', 'panel.sites.moveDown', index < page.blocks.length - 1,
				function () { Sites.moveBlock( App, index, 1 ); } ],
			[ 'trash', 'panel.common.remove', true, function () { Sites.removeBlock( App, index ); } ],
		].forEach( function ( entry ) {
			var button = node( 'button', 'icon-btn icon-btn--sm' );
			var label = App.t( entry[ 1 ] );

			button.innerHTML = icon( entry[ 0 ], { size: 15 } );
			button.setAttribute( 'data-tip', label );
			button.setAttribute( 'aria-label', label );
			button.disabled = ! entry[ 2 ];
			button.addEventListener( 'click', entry[ 3 ] );
			tools.appendChild( button );
		} );

		head.appendChild( tools );
		card.appendChild( head );

		var body = node( 'div', 'block__body' );
		Sites.blockFields( App, block, body );
		card.appendChild( body );

		return card;
	};

	Sites.blockName = function ( type ) {
		var found = ( Sites.state.meta.blocks || [] ).filter( function ( b ) { return b.type === type; } )[ 0 ];

		return found ? found.name : titleCase( type );
	};

	Sites.blockFields = function ( App, block, host ) {
		function field( label, control, wide ) {
			var wrap = node( 'div', 'insp-field' + ( wide ? ' insp-field--wide' : '' ) );

			if ( label ) {
				wrap.appendChild( node( 'label', '', label ) );
			}

			wrap.appendChild( control );
			host.appendChild( wrap );
		}

		function text( label, key, options ) {
			options = options || {};

			var input = document.createElement( options.multiline ? 'textarea' : 'input' );
			input.className = options.multiline ? 'textarea' : 'input';
			input.value = block[ key ] == null ? '' : block[ key ];

			if ( options.multiline ) {
				input.rows = options.rows || 4;
			}

			if ( options.placeholder ) {
				input.placeholder = options.placeholder;
			}

			input.addEventListener( 'input', function () {
				block[ key ] = input.value;
				Sites.savePage( App );
			} );

			field( label, input, options.wide || options.multiline );
		}

		function select( label, key, options ) {
			var input = document.createElement( 'select' );
			input.className = 'select';

			options.forEach( function ( option ) {
				var node = document.createElement( 'option' );
				node.value = option[ 0 ];
				node.textContent = option[ 1 ];
				node.selected = String( block[ key ] ) === String( option[ 0 ] );
				input.appendChild( node );
			} );

			input.addEventListener( 'change', function () {
				block[ key ] = input.value;
				Sites.savePage( App );
			} );

			field( label, input );
		}

		function label( key ) {
			return App.t( 'panel.sites.' + key );
		}

		switch ( block.type ) {
			case 'hero':
				text( label( 'blockTitle' ), 'title', { wide: true } );
				text( label( 'heroSubtitle' ), 'subtitle', { wide: true } );
				text( label( 'imageUrl' ), 'url', { wide: true, placeholder: 'https://…' } );
				select( label( 'align' ), 'align', [
					[ 'start', label( 'alignLeft' ) ], [ 'center', label( 'alignCentre' ) ],
				] );
				select( label( 'heroHeight' ), 'height', [
					[ 'short', label( 'heightShort' ) ],
					[ 'tall', label( 'heightTall' ) ],
					[ 'full', label( 'heightFull' ) ],
				] );
				break;

			case 'heading':
				text( label( 'blockText' ), 'text', { wide: true } );
				select( label( 'blockSize' ), 'level', [
					[ 2, label( 'sizeLarge' ) ], [ 3, label( 'sizeMedium' ) ], [ 4, label( 'sizeSmall' ) ],
				] );
				select( label( 'align' ), 'align', [
					[ 'start', label( 'alignLeft' ) ], [ 'center', label( 'alignCentre' ) ],
				] );
				break;

			case 'richText':
				text( null, 'text', {
					multiline: true, rows: 6, placeholder: label( 'richTextPlaceholder' ),
				} );
				break;

			case 'image':
				text( label( 'imageUrl' ), 'url', { wide: true, placeholder: 'https://…' } );
				text( label( 'imageAlt' ), 'alt', {
					wide: true, placeholder: label( 'imageAltPlaceholder' ),
				} );
				text( label( 'caption' ), 'caption', { wide: true } );
				select( label( 'imageWidth' ), 'width', [
					[ 'content', label( 'widthContent' ) ],
					[ 'wide', label( 'widthWide' ) ],
					[ 'full', label( 'widthFull' ) ],
				] );
				break;

			case 'buttons':
				Sites.buttonsEditor( App, block, host );
				break;

			case 'eventList':
				text( label( 'blockTitle' ), 'title', { wide: true } );
				select( label( 'layout' ), 'layout', [
					[ 'cards', label( 'layoutCards' ) ],
					[ 'list', label( 'layoutList' ) ],
					[ 'spotlight', label( 'layoutSpotlight' ) ],
				] );
				text( label( 'howMany' ), 'limit' );
				break;

			case 'eventDetail':
				select( label( 'whichEvent' ), 'event_public_id',
					[ [ '', label( 'anyEvent' ) ] ].concat(
						Sites.state.events.map( function ( event ) {
							return [ event.public_id, event.name ];
						} )
					) );
				host.appendChild( node( 'p', 'hint', label( 'eventDetailHint' ) ) );
				break;

			case 'faq':
				text( label( 'blockTitle' ), 'title', { wide: true } );
				Sites.faqEditor( App, block, host );
				break;

			case 'venueMap':
				text( label( 'blockTitle' ), 'title', { wide: true } );
				text( label( 'venueAddress' ), 'address', { multiline: true, rows: 3 } );
				text( label( 'directions' ), 'directions', { multiline: true, rows: 4 } );
				break;

			case 'html':
				text( null, 'html', { multiline: true, rows: 6 } );
				host.appendChild( node( 'p', 'hint', label( 'htmlHint' ) ) );
				break;

			default:
				host.appendChild( node( 'p', 'hint', label( 'nothingToSet' ) ) );
		}
	};

	Sites.buttonsEditor = function ( App, block, host ) {
		block.items = block.items || [];

		block.items.forEach( function ( item, index ) {
			var row = node( 'div', 'row row--wrap' );

			var label = document.createElement( 'input' );
			label.className = 'input grow';
			label.placeholder = App.t( 'panel.sites.buttonLabel' );
			label.value = item.label || '';
			label.addEventListener( 'input', function () {
				item.label = label.value;
				Sites.savePage( App );
			} );

			var href = document.createElement( 'input' );
			href.className = 'input grow';
			href.placeholder = App.t( 'panel.sites.buttonHref' );
			href.value = item.href || '';
			href.addEventListener( 'input', function () {
				item.href = href.value;
				Sites.savePage( App );
			} );

			var remove = node( 'button', 'icon-btn icon-btn--sm' );
			remove.innerHTML = icon( 'trash', { size: 14 } );
			remove.setAttribute( 'aria-label', App.t( 'panel.sites.removeButton' ) );
			remove.addEventListener( 'click', function () {
				block.items.splice( index, 1 );
				Sites.savePage( App, true );
			} );

			row.appendChild( label );
			row.appendChild( href );
			row.appendChild( remove );
			host.appendChild( row );
		} );

		if ( block.items.length < 4 ) {
			var add = node( 'button', 'link-btn' );
			add.innerHTML = icon( 'plus', { size: 14 } ) + esc( App.t( 'panel.sites.addButton' ) );
			add.addEventListener( 'click', function () {
				block.items.push( { label: App.t( 'panel.sites.bookNow' ), href: '/', style: 'primary' } );
				Sites.savePage( App, true );
			} );
			host.appendChild( add );
		}
	};

	Sites.faqEditor = function ( App, block, host ) {
		block.items = block.items || [];

		block.items.forEach( function ( item, index ) {
			var wrap = node( 'div', 'faq-edit' );

			var question = document.createElement( 'input' );
			question.className = 'input';
			question.placeholder = App.t( 'panel.sites.question' );
			question.value = item.question || '';
			question.addEventListener( 'input', function () {
				item.question = question.value;
				Sites.savePage( App );
			} );

			var answer = document.createElement( 'textarea' );
			answer.className = 'textarea';
			answer.rows = 3;
			answer.placeholder = App.t( 'panel.sites.answer' );
			answer.value = item.answer || '';
			answer.addEventListener( 'input', function () {
				item.answer = answer.value;
				Sites.savePage( App );
			} );

			var remove = node( 'button', 'link-btn' );
			remove.innerHTML = icon( 'trash', { size: 14 } ) + esc( App.t( 'panel.common.remove' ) );
			remove.addEventListener( 'click', function () {
				block.items.splice( index, 1 );
				Sites.savePage( App, true );
			} );

			wrap.appendChild( question );
			wrap.appendChild( answer );
			wrap.appendChild( remove );
			host.appendChild( wrap );
		} );

		var add = node( 'button', 'link-btn' );
		add.innerHTML = icon( 'plus', { size: 14 } ) + esc( App.t( 'panel.sites.addQuestion' ) );
		add.addEventListener( 'click', function () {
			block.items.push( { question: '', answer: '' } );
			Sites.savePage( App, true );
		} );
		host.appendChild( add );
	};

	Sites.addBlock = function ( App, type ) {
		var page = Sites.currentPage();

		page.blocks = ( page.blocks || [] ).concat( [ { type: type } ] );
		Sites.savePage( App, true );
	};

	Sites.moveBlock = function ( App, index, delta ) {
		var page = Sites.currentPage();
		var moved = page.blocks.splice( index, 1 )[ 0 ];

		page.blocks.splice( index + delta, 0, moved );
		Sites.savePage( App, true );
	};

	Sites.removeBlock = function ( App, index ) {
		var page = Sites.currentPage();

		page.blocks.splice( index, 1 );
		Sites.savePage( App, true );
	};

	/**
	 * Save the draft, debounced.
	 *
	 * Typing into a text block should not be one request per keystroke, and an editor with a Save
	 * button is an editor people lose work in. `repaint` is for structural changes — adding or
	 * moving a block — where the panel has to redraw from the server's answer anyway.
	 */
	Sites.savePage = function ( App, repaint ) {
		var page = Sites.currentPage();

		window.clearTimeout( Sites._saveTimer );

		Sites._saveTimer = window.setTimeout( function () {
			App.request( 'PATCH', '/sites/' + Sites.state.site.id + '/pages/' + page.id, {
				blocks: page.blocks || [],
			} )
				.then( function ( saved ) {
					Sites.replacePage( saved );

					if ( repaint ) {
						Sites.paintPage( App );
						Sites.paintNav( App );
					} else {
						Sites.markDirty( App );
					}
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		}, repaint ? 0 : 500 );
	};

	Sites.replacePage = function ( saved ) {
		Sites.state.site.pages = ( Sites.state.site.pages || [] ).map( function ( page ) {
			return page.id === saved.id ? saved : page;
		} );
	};

	/** Only the badge changes while typing; redrawing the whole page would take focus away. */
	Sites.markDirty = function ( App ) {
		var page = Sites.currentPage();
		var desc = document.querySelector( '.page-head--inline .page-head__desc' );

		if ( desc && page && page.has_unpublished_changes ) {
			desc.innerHTML = '<code>' + esc( page.path ) + '</code> · ' +
				badge( App.t( 'panel.sites.unpublishedChanges' ), 'warn' );
		}
	};

	Sites.publishPage = function ( App ) {
		var page = Sites.currentPage();

		App.request( 'POST', '/sites/' + Sites.state.site.id + '/pages/' + page.id + '/publish', {} )
			.then( function ( saved ) {
				Sites.replacePage( saved );
				Sites.paintPage( App );
				Sites.paintNav( App );
				App.toast( App.t( 'panel.sites.pagePublished' ) );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Sites.newPage = function ( App ) {
		App.modal( {
			title: App.t( 'panel.sites.newPageTitle' ),
			submitLabel: App.t( 'panel.sites.newPageSubmit' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="p-title">' +
				esc( App.t( 'panel.common.title' ) ) + '</label>' +
				'<input class="input" id="p-title" name="title" required maxlength="160"></div>' +
				'<div class="field"><label class="field__label" for="p-slug">' +
				esc( App.t( 'panel.sites.slug' ) ) + '</label>' +
				'<input class="input" id="p-slug" name="slug" required maxlength="80" ' +
				'placeholder="' + esc( App.t( 'panel.sites.slugPlaceholder' ) ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.sites.slugHint' ) ) + '</span></div>' +
				'</div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/sites/' + Sites.state.site.id + '/pages', {
					title: data.get( 'title' ),
					slug: String( data.get( 'slug' ) || '' ).toLowerCase(),
				} ).then( function ( page ) {
					Sites.state.site.pages = ( Sites.state.site.pages || [] ).concat( [ page ] );
					Sites.state.pageId = page.id;
					Sites.state.tab = 'pages';
					Sites.paintNav( App );
					Sites.paintPage( App );
				} );
			},
		} );
	};

	Sites.pageSettings = function ( App ) {
		var page = Sites.currentPage();

		App.modal( {
			title: App.t( 'panel.sites.pageSettings' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="ps-title">' +
				esc( App.t( 'panel.common.title' ) ) + '</label>' +
				'<input class="input" id="ps-title" name="title" required value="' + esc( page.title ) + '"></div>' +
				( 'home' === page.kind ? ''
					: '<div class="field"><label class="field__label" for="ps-slug">' +
						esc( App.t( 'panel.sites.slug' ) ) + '</label>' +
						'<input class="input" id="ps-slug" name="slug" value="' + esc( page.slug ) + '"></div>' ) +
				'<div class="field"><label class="field__label" for="ps-seo">' +
				esc( App.t( 'panel.sites.seoTitle' ) ) + '</label>' +
				'<input class="input" id="ps-seo" name="seo_title" maxlength="160" value="' +
				esc( page.seo_title || '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="ps-desc">' +
				esc( App.t( 'panel.sites.seoDescription' ) ) + '</label>' +
				'<textarea class="textarea" id="ps-desc" name="seo_description" rows="3" maxlength="320">' +
				esc( page.seo_description || '' ) + '</textarea></div>' +
				'</div>',
			onSubmit: function ( data ) {
				var body = {
					title: data.get( 'title' ),
					seo_title: data.get( 'seo_title' ) || null,
					seo_description: data.get( 'seo_description' ) || null,
				};

				if ( 'home' !== page.kind ) {
					body.slug = String( data.get( 'slug' ) || '' ).toLowerCase();
				}

				return App.request( 'PATCH', '/sites/' + Sites.state.site.id + '/pages/' + page.id, body )
					.then( function ( saved ) {
						Sites.replacePage( saved );
						Sites.paintNav( App );
						Sites.paintPage( App );
					} );
			},
		} );
	};

	Sites.deletePage = function ( App ) {
		var page = Sites.currentPage();

		App.modal( {
			title: App.t( 'panel.sites.deletePageTitle' ),
			submitLabel: App.t( 'panel.common.delete' ),
			body: '<p>' + App.t( 'panel.sites.deletePageBody', { path: esc( page.path ) } ) + '</p>',
			onSubmit: function () {
				return App.request( 'DELETE', '/sites/' + Sites.state.site.id + '/pages/' + page.id )
					.then( function () {
						Sites.state.site.pages = Sites.state.site.pages.filter( function ( p ) {
							return p.id !== page.id;
						} );
						Sites.state.pageId = ( Sites.state.site.pages[ 0 ] || {} ).id || null;
						Sites.paintNav( App );
						Sites.paintPage( App );
						App.toast( App.t( 'panel.sites.pageDeleted' ) );
					} );
			},
		} );
	};


	/* ------------------------------------------------------------------------- the design */

	Sites.paintDesign = function ( App ) {
		var state = Sites.state;
		var site = state.site;
		var brand = site.brand || {};
		var host = document.getElementById( 'site-main' );

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline"><div class="page-head__text">' +
					'<h1>' + esc( App.t( 'panel.sites.designTitle' ) ) + '</h1>' +
					'<p class="page-head__desc">' + esc( App.t( 'panel.sites.designDescription' ) ) + '</p>' +
				'</div></div>' +
				'<div class="themes" id="themes"></div>' +
				'<section class="insp-section"><div class="insp-section__head"><h3>' +
					esc( App.t( 'panel.sites.brand' ) ) + '</h3></div>' +
					'<div id="brand"></div>' +
				'</section>' +
			'</div>';

		var themes = document.getElementById( 'themes' );

		/*
		 * Ours and the account's own, on one screen. The swatch is painted from the theme's own
		 * tokens rather than from a class per theme, so a theme somebody wrote this morning shows
		 * its real colours without a stylesheet being edited.
		 */
		function themeCard( theme, active, onPick ) {
			var tokens = theme.tokens || {};
			var card = node( 'button', 'theme-card' + ( active ? ' is-active' : '' ) );

			card.innerHTML =
				'<span class="theme-card__swatch" style="background:linear-gradient(150deg,' +
					esc( tokens.surface || '#ffffff' ) + ' 45%,' + esc( tokens.accent || '#4a4fdc' ) +
					' 45%)"></span>' +
				'<span class="theme-card__body"><strong>' + esc( theme.name ) + '</strong>' +
				'<span class="muted">' + esc( theme.description || '' ) + '</span></span>';

			card.addEventListener( 'click', onPick );
			themes.appendChild( card );
		}

		( state.meta.themes || [] ).forEach( function ( theme ) {
			themeCard( theme, ! site.site_theme_id && theme.key === site.theme_key, function () {
				// Choosing one of ours also takes the site out of whatever custom theme it wore.
				Sites.saveSite( App, { theme_key: theme.key, site_theme_id: null } );
			} );
		} );

		( state.meta.custom || [] ).forEach( function ( theme ) {
			themeCard(
				{ name: theme.name, tokens: theme.tokens, description: App.t( 'themes.basedOn', {
					theme: theme.base_key,
				} ) },
				site.site_theme_id === theme.id,
				function () { Sites.saveSite( App, { site_theme_id: theme.id } ); }
			);
		} );

		var fields = document.getElementById( 'brand' );

		function field( label, control, wide ) {
			var wrap = node( 'div', 'insp-field' + ( wide ? ' insp-field--wide' : '' ) );
			wrap.appendChild( node( 'label', '', label ) );
			wrap.appendChild( control );
			fields.appendChild( wrap );
		}

		var accent = document.createElement( 'input' );
		accent.type = 'color';
		accent.className = 'swatch';
		accent.value = brand.accent || ( ( state.meta.themes || [] ).filter( function ( t ) {
			return t.key === site.theme_key;
		} )[ 0 ] || { tokens: {} } ).tokens.accent || '#4a4fdc';
		accent.addEventListener( 'change', function () {
			Sites.saveBrand( App, { accent: accent.value } );
		} );
		field( App.t( 'panel.sites.accent' ), accent );

		[
			[ 'headingFont', 'heading_font' ],
			[ 'bodyFont', 'body_font' ],
		].forEach( function ( entry ) {
			var select = document.createElement( 'select' );
			select.className = 'select';

			( state.meta.fonts || [] ).forEach( function ( key ) {
				var option = document.createElement( 'option' );
				option.value = key;
				option.textContent = App.t( 'themes.options.' + key );
				option.selected = brand[ entry[ 1 ] ] === key;
				select.appendChild( option );
			} );

			select.addEventListener( 'change', function () {
				var patch = {};
				patch[ entry[ 1 ] ] = select.value;
				Sites.saveBrand( App, patch );
			} );

			field( App.t( 'panel.sites.' + entry[ 0 ] ), select );
		} );

		var radius = document.createElement( 'select' );
		radius.className = 'select';

		( state.meta.radii || [] ).forEach( function ( key ) {
			var option = document.createElement( 'option' );
			option.value = key;
			option.textContent = App.t( 'themes.options.' + key );
			option.selected = brand.radius === key;
			radius.appendChild( option );
		} );

		radius.addEventListener( 'change', function () { Sites.saveBrand( App, { radius: radius.value } ); } );
		field( App.t( 'panel.sites.corners' ), radius );

		var logo = document.createElement( 'input' );
		logo.className = 'input';
		logo.placeholder = 'https://…';
		logo.value = brand.logo_url || '';
		logo.addEventListener( 'change', function () { Sites.saveBrand( App, { logo_url: logo.value } ); } );
		field( App.t( 'panel.sites.logoUrl' ), logo, true );

		var tagline = document.createElement( 'input' );
		tagline.className = 'input';
		tagline.value = brand.tagline || '';
		tagline.addEventListener( 'change', function () { Sites.saveBrand( App, { tagline: tagline.value } ); } );
		field( App.t( 'panel.sites.tagline' ), tagline, true );
	};

	Sites.saveBrand = function ( App, patch ) {
		var brand = Object.assign( {}, Sites.state.site.brand || {}, patch );

		Sites.saveSite( App, { brand: brand } );
	};

	Sites.saveSite = function ( App, patch ) {
		App.request( 'PATCH', '/sites/' + Sites.state.site.id, patch )
			.then( function ( site ) {
				Sites.state.site = site;
				Sites.paint( App );
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* -------------------------------------------------------------------------- the menus */

	Sites.paintMenus = function ( App ) {
		var host = document.getElementById( 'site-main' );

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline"><div class="page-head__text">' +
					'<h1>' + esc( App.t( 'panel.sites.menusTitle' ) ) + '</h1>' +
					'<p class="page-head__desc">' + esc( App.t( 'panel.sites.menusDescription' ) ) + '</p>' +
				'</div></div>' +
				'<div id="menus"></div>' +
			'</div>';

		var menus = document.getElementById( 'menus' );

		( Sites.state.site.menus || [] ).forEach( function ( menu ) {
			menus.appendChild( Sites.menuEditor( App, menu ) );
		} );
	};

	Sites.menuEditor = function ( App, menu ) {
		var section = node( 'section', 'insp-section' );
		var head = node( 'div', 'insp-section__head' );
		// The two menus every site is provisioned with are named here rather than read back from
		// the row, so a site created in one language does not keep those names in another.
		head.appendChild( node( 'h3', '', App.has( 'panel.sites.menu' + titleCase( menu.key ) )
			? App.t( 'panel.sites.menu' + titleCase( menu.key ) )
			: menu.name ) );
		section.appendChild( head );

		menu.items = menu.items || [];

		menu.items.forEach( function ( item, index ) {
			var row = node( 'div', 'menu-row' );

			var label = document.createElement( 'input' );
			label.className = 'input';
			label.placeholder = App.t( 'panel.sites.menuLabel' );
			label.value = item.label || '';
			label.addEventListener( 'input', function () { item.label = label.value; } );
			label.addEventListener( 'change', function () { Sites.saveMenu( App, menu ); } );

			var target = document.createElement( 'select' );
			target.className = 'select';

			var options = [ [ 'page:', App.t( 'panel.sites.chooseTarget' ) ] ]
				.concat( ( Sites.state.site.pages || [] ).map( function ( page ) {
					return [ 'page:' + page.id, App.t( 'panel.sites.targetPage', { title: page.title } ) ];
				} ) )
				.concat( Sites.state.events.map( function ( event ) {
					return [ 'event:' + event.id, App.t( 'panel.sites.targetEvent', { name: event.name } ) ];
				} ) )
				.concat( [ [ 'url:', App.t( 'panel.sites.targetUrl' ) ] ] );

			var current = 'url' === item.target_type
				? 'url:'
				: item.target_type + ':' + ( item.site_page_id || item.event_id || '' );

			options.forEach( function ( option ) {
				var node = document.createElement( 'option' );
				node.value = option[ 0 ];
				node.textContent = option[ 1 ];
				node.selected = option[ 0 ] === current;
				target.appendChild( node );
			} );

			var url = document.createElement( 'input' );
			url.className = 'input';
			url.placeholder = 'https://…';
			url.value = item.url || '';
			url.hidden = 'url' !== item.target_type;
			url.addEventListener( 'change', function () {
				item.url = url.value;
				Sites.saveMenu( App, menu );
			} );

			target.addEventListener( 'change', function () {
				var parts = target.value.split( ':' );

				item.target_type = parts[ 0 ];
				item.site_page_id = 'page' === parts[ 0 ] ? parts[ 1 ] : null;
				item.event_id = 'event' === parts[ 0 ] ? parts[ 1 ] : null;
				url.hidden = 'url' !== parts[ 0 ];

				if ( 'url' !== parts[ 0 ] ) {
					item.url = null;
					Sites.saveMenu( App, menu );
				}
			} );

			var remove = node( 'button', 'icon-btn icon-btn--sm' );
			remove.innerHTML = icon( 'trash', { size: 15 } );
			remove.setAttribute( 'aria-label', App.t( 'panel.sites.removeLink' ) );
			remove.addEventListener( 'click', function () {
				menu.items.splice( index, 1 );
				Sites.saveMenu( App, menu, true );
			} );

			row.appendChild( label );
			row.appendChild( target );
			row.appendChild( url );
			row.appendChild( remove );
			section.appendChild( row );
		} );

		var add = node( 'button', 'link-btn' );
		add.innerHTML = icon( 'plus', { size: 14 } ) + esc( App.t( 'panel.sites.addLink' ) );
		add.addEventListener( 'click', function () {
			var first = ( Sites.state.site.pages || [] )[ 0 ];

			menu.items.push( {
				label: first ? first.title : App.t( 'panel.sites.linkDefault' ),
				target_type: 'page',
				site_page_id: first ? first.id : null,
			} );

			Sites.saveMenu( App, menu, true );
		} );
		section.appendChild( add );

		return section;
	};

	Sites.saveMenu = function ( App, menu, repaint ) {
		var items = ( menu.items || [] ).filter( function ( item ) {
			return item.label && ( item.site_page_id || item.event_id || item.url );
		} );

		App.request( 'PUT', '/sites/' + Sites.state.site.id + '/menus/' + menu.key, { items: items } )
			.then( function ( saved ) {
				Sites.state.site.menus = ( Sites.state.site.menus || [] ).map( function ( m ) {
					return m.key === saved.key ? saved : m;
				} );

				if ( repaint ) {
					Sites.paintMenus( App );
				}
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	/* ------------------------------------------------------------------------ the address */

	Sites.paintDomains = function ( App ) {
		var host = document.getElementById( 'site-main' );
		var domains = Sites.state.site.domains || [];

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline">' +
					'<div class="page-head__text">' +
						'<h1>' + esc( App.t( 'panel.sites.addressTitle' ) ) + '</h1>' +
						'<p class="page-head__desc">' +
						esc( App.t( 'panel.sites.addressDescription' ) ) + '</p>' +
					'</div>' +
					'<div class="page-head__actions">' +
						'<button class="btn btn--primary" id="domain-add">' +
						icon( 'plus', { size: 15 } ) + esc( App.t( 'panel.sites.addAddress' ) ) + '</button>' +
					'</div>' +
				'</div>' +
				'<div id="domains"></div>' +
			'</div>';

		document.getElementById( 'domain-add' ).addEventListener( 'click', function () {
			Sites.addDomain( App );
		} );

		var list = document.getElementById( 'domains' );

		if ( ! domains.length ) {
			list.innerHTML = App.emptyState( 'globe', App.t( 'panel.sites.noDomainTitle' ),
				esc( App.t( 'panel.sites.noDomainBody' ) ) );

			return;
		}

		domains.forEach( function ( domain ) {
			list.appendChild( Sites.domainCard( App, domain ) );
		} );
	};

	/**
	 * Whether buyers may sign in, and what they get if they do.
	 *
	 * The switch is only a switch where the platform has Google credentials to offer. Where it has
	 * none, this says so plainly rather than showing a control that would fail: an organiser who
	 * turns something on and finds a Google error page has been lied to by the panel.
	 */
	Sites.paintSignIn = function ( App ) {
		var site = Sites.state.site;
		var host = document.getElementById( 'site-main' );
		var available = false !== site.signin_available;

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline">' +
					'<div class="page-head__text">' +
						'<h1>' + esc( App.t( 'panel.sites.signinTitle' ) ) + '</h1>' +
						'<p class="page-head__desc">' + esc( App.t( 'panel.sites.signinDescription' ) ) + '</p>' +
					'</div>' +
				'</div>' +
				'<div class="card card--pad">' +
					( available
						? '<label class="switch switch--row">' +
							'<input type="checkbox" id="signin-toggle"' +
								( site.google_signin ? ' checked' : '' ) + '>' +
							'<span class="switch__track"><span class="switch__thumb"></span></span>' +
							'<span>' + esc( App.t( 'panel.sites.signinToggle' ) ) + '</span>' +
						'</label>'
						: '<p class="muted">' + esc( App.t( 'panel.sites.signinUnavailable' ) ) + '</p>' ) +
					'<p class="hint spaced">' + esc( App.t( 'panel.sites.signinHint' ) ) + '</p>' +
					( site.url
						? '<p class="hint"><code>' + esc( site.url.replace( /\/$/, '' ) + '/account' ) +
							'</code></p>'
						: '' ) +
				'</div>' +
			'</div>';

		var toggle = document.getElementById( 'signin-toggle' );

		if ( toggle ) {
			toggle.addEventListener( 'change', function () {
				App.request( 'PATCH', '/sites/' + site.id, { google_signin: toggle.checked } )
					.then( function ( updated ) {
						site.google_signin = updated.google_signin;
						App.toast( App.t( updated.google_signin
							? 'panel.sites.signinOn'
							: 'panel.sites.signinOff' ) );
					} )
					.catch( function ( error ) {
						toggle.checked = ! toggle.checked;
						App.toast( error.message, true );
					} );
			} );
		}
	};

	/**
	 * Who issues the invoices, and under what tax number.
	 *
	 * The switch alone is not enough: an invoice with no issuing entity and no address is not a
	 * document anybody's accounts department will accept, so the site offers the button only when
	 * both are filled in — and this screen says so rather than letting an organiser discover it
	 * from a buyer's complaint.
	 */
	Sites.paintInvoicing = function ( App ) {
		var site = Sites.state.site;
		var host = document.getElementById( 'site-main' );

		host.innerHTML =
			'<div class="site-pane">' +
				'<div class="page-head page-head--inline">' +
					'<div class="page-head__text">' +
						'<h1>' + esc( App.t( 'panel.sites.invoicingTitle' ) ) + '</h1>' +
						'<p class="page-head__desc">' +
							esc( App.t( 'panel.sites.invoicingDescription' ) ) + '</p>' +
					'</div>' +
				'</div>' +
				'<div class="card card--pad">' +
					'<label class="switch switch--row">' +
						'<input type="checkbox" id="inv-enabled"' +
							( site.invoices_enabled ? ' checked' : '' ) + '>' +
						'<span class="switch__track"><span class="switch__thumb"></span></span>' +
						'<span>' + esc( App.t( 'panel.sites.invoicingToggle' ) ) + '</span>' +
					'</label>' +
					'<div class="stack spaced">' +
						field( App, 'inv-legal', 'panel.sites.legalName', site.legal_name,
							'panel.sites.legalNameHint' ) +
						field( App, 'inv-tax', 'panel.sites.taxNumber', site.tax_number ) +
						'<div class="field"><label class="field__label" for="inv-address">' +
							esc( App.t( 'panel.sites.billingAddress' ) ) + '</label>' +
							'<textarea class="input" id="inv-address" rows="4" maxlength="600">' +
								esc( site.billing_address || '' ) + '</textarea></div>' +
						field( App, 'inv-footer', 'panel.sites.invoiceFooter', site.invoice_footer,
							'panel.sites.invoiceFooterHint' ) +
						field( App, 'inv-prefix', 'panel.sites.invoicePrefix', site.invoice_prefix,
							'panel.sites.invoicePrefixHint' ) +
					'</div>' +
					'<p class="spaced">' +
						'<button class="btn btn--primary" id="inv-save">' +
							esc( App.t( 'panel.common.save' ) ) + '</button>' +
					'</p>' +
				'</div>' +
			'</div>';

		document.getElementById( 'inv-save' ).addEventListener( 'click', function () {
			var payload = {
				invoices_enabled: document.getElementById( 'inv-enabled' ).checked,
				legal_name: value( 'inv-legal' ) || null,
				tax_number: value( 'inv-tax' ) || null,
				billing_address: value( 'inv-address' ) || null,
				invoice_footer: value( 'inv-footer' ) || null,
				invoice_prefix: value( 'inv-prefix' ).toUpperCase() || null,
			};

			App.request( 'PATCH', '/sites/' + site.id, payload )
				.then( function ( updated ) {
					Object.keys( payload ).forEach( function ( key ) { site[ key ] = updated[ key ]; } );

					App.toast( App.t( updated.invoices_enabled && updated.billing_address
						? 'panel.sites.invoicingSaved'
						: 'panel.sites.invoicingIncomplete' ) );
				} )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );
	};

	function field( App, id, key, value, hintKey ) {
		return '<div class="field"><label class="field__label" for="' + id + '">' +
			esc( App.t( key ) ) + '</label>' +
			'<input class="input" id="' + id + '" value="' + esc( value || '' ) + '">' +
			( hintKey ? '<span class="field__hint">' + esc( App.t( hintKey ) ) + '</span>' : '' ) +
		'</div>';
	}

	function value( id ) {
		var element = document.getElementById( id );

		return element ? String( element.value ).trim() : '';
	}

	Sites.domainCard = function ( App, domain ) {
		var card = node( 'div', 'domain' );

		var head = node( 'div', 'domain__head' );
		head.innerHTML = '<strong>' + esc( domain.hostname ) + '</strong>' +
			( domain.is_primary ? ' ' + badge( App.t( 'panel.sites.main' ), 'accent' ) : '' ) +
			' ' + ( domain.verified
				? badge( App.t( 'panel.sites.verified' ), 'ok' )
				: badge( App.t( 'panel.sites.waitingForDns' ), 'warn' ) );

		var tools = node( 'div', 'domain__tools' );

		if ( ! domain.verified ) {
			var check = node( 'button', 'btn btn--sm' );
			check.innerHTML = icon( 'check', { size: 14 } ) + esc( App.t( 'panel.sites.checkNow' ) );
			check.addEventListener( 'click', function () {
				App.request( 'POST', '/sites/' + Sites.state.site.id + '/domains/' + domain.id + '/verify', {} )
					.then( function ( updated ) {
						Sites.replaceDomain( updated );
						Sites.paintDomains( App );
						App.toast( App.t( updated.verified
							? 'panel.sites.verifiedToast'
							: 'panel.sites.notYet' ) );
					} )
					.catch( function ( error ) { App.toast( error.message, true ); } );
			} );
			tools.appendChild( check );
		}

		if ( domain.verified && ! domain.is_primary ) {
			var promote = node( 'button', 'btn btn--sm' );
			promote.textContent = App.t( 'panel.sites.makeMain' );
			promote.addEventListener( 'click', function () {
				App.request( 'POST', '/sites/' + Sites.state.site.id + '/domains/' + domain.id + '/primary', {} )
					.then( function () { Sites.open( App, Sites.state.site.id ); } )
					.catch( function ( error ) { App.toast( error.message, true ); } );
			} );
			tools.appendChild( promote );
		}

		var remove = node( 'button', 'icon-btn icon-btn--sm' );
		remove.innerHTML = icon( 'trash', { size: 15 } );
		remove.setAttribute( 'aria-label',
			App.t( 'panel.sites.removeDomain', { hostname: domain.hostname } ) );
		remove.addEventListener( 'click', function () {
			App.request( 'DELETE', '/sites/' + Sites.state.site.id + '/domains/' + domain.id )
				.then( function () { Sites.open( App, Sites.state.site.id ); } )
				.catch( function ( error ) { App.toast( error.message, true ); } );
		} );
		tools.appendChild( remove );

		head.appendChild( tools );
		card.appendChild( head );

		if ( ! domain.verified ) {
			var record = node( 'div', 'domain__record' );
			record.innerHTML =
				'<p class="hint">' + esc( App.t( 'panel.sites.recordHint' ) ) + '</p>' +
				'<dl class="record">' +
				'<dt>' + esc( App.t( 'panel.sites.recordType' ) ) + '</dt>' +
				'<dd><code>' + esc( domain.record.type ) + '</code></dd>' +
				'<dt>' + esc( App.t( 'panel.sites.recordName' ) ) + '</dt>' +
				'<dd><code>' + esc( domain.record.name ) + '</code></dd>' +
				'<dt>' + esc( App.t( 'panel.sites.recordValue' ) ) + '</dt>' +
				'<dd><code>' + esc( domain.record.value ) + '</code></dd>' +
				'</dl>' +
				( domain.last_error ? '<p class="issue issue--warning">' + esc( domain.last_error ) + '</p>' : '' );

			card.appendChild( record );
		}

		return card;
	};

	Sites.replaceDomain = function ( updated ) {
		Sites.state.site.domains = ( Sites.state.site.domains || [] ).map( function ( domain ) {
			return domain.id === updated.id ? updated : domain;
		} );
	};

	Sites.addDomain = function ( App ) {
		App.modal( {
			title: App.t( 'panel.sites.addAddress' ),
			submitLabel: App.t( 'panel.common.add' ),
			body:
				'<div class="field"><label class="field__label" for="d-host">' +
				esc( App.t( 'panel.sites.domain' ) ) + '</label>' +
				'<input class="input" id="d-host" name="hostname" required placeholder="' +
				esc( App.t( 'panel.sites.domainPlaceholder' ) ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.sites.domainHint' ) ) + '</span></div>',
			onSubmit: function ( data ) {
				return App.request( 'POST', '/sites/' + Sites.state.site.id + '/domains', {
					hostname: data.get( 'hostname' ),
				} ).then( function () {
					Sites.open( App, Sites.state.site.id );
				} );
			},
		} );
	};

	global.SeatmapSites = Sites;

	/* -------------------------------------------------------------------------- helpers */

	function node( tag, className, text ) {
		var element = document.createElement( tag );

		if ( className ) {
			element.className = className;
		}

		if ( text != null ) {
			element.textContent = text;
		}

		return element;
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value == null ? '' : value );

		return element.innerHTML;
	}

	function badge( text, tone ) {
		return '<span class="badge badge--' + ( tone || 'neutral' ) + '">' + esc( text ) + '</span>';
	}

	function titleCase( value ) {
		var text = String( value || '' ).replace( /[_-]+/g, ' ' );

		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	Sites.node = node;
	Sites.esc = esc;
	Sites.badge = badge;
	Sites.titleCase = titleCase;
} )( typeof window !== 'undefined' ? window : globalThis );
