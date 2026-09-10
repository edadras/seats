/**
 * The people who sell tickets on an organiser's behalf, and what they are owed.
 *
 * One screen for two questions that look like one. The table is the people: a name, a link they can
 * put in a post, and a percentage. Underneath it is everything else that arrived on a link nobody
 * is being paid for — the newsletter, the listing site, the poster with a code on it — because that
 * is the second thing every organiser asks and a promoter table alone cannot answer it.
 */
( function ( global ) {
	'use strict';

	var Promoters = {};

	function esc( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	Promoters.render = function ( App ) {
		App.loading( App.t( 'panel.promoters.title' ) );

		Promise.all( [
			App.request( 'GET', '/promoters' ),
			// A person who may add a promoter may not be a person who may see the takings, so a
			// refusal here is a screen without figures rather than a screen that fails to open.
			App.request( 'GET', '/promoters/performance' ).catch( function () {
				return { data: [], campaigns: [], forbidden: true };
			} ),
			App.request( 'GET', '/sites' ).catch( function () { return { data: [] }; } ),
		] ).then( function ( answers ) {
			Promoters.list = answers[ 0 ].data || [];
			Promoters.figures = answers[ 1 ].data || [];
			Promoters.campaigns = answers[ 1 ].campaigns || [];
			Promoters.blind = !! answers[ 1 ].forbidden;
			// Whichever address their own audience already knows. A promoter's link has to be on
			// the organiser's own site, not on ours.
			Promoters.host = ( ( answers[ 2 ].data || [] )[ 0 ] || {} ).url || '';
			Promoters.paint( App );
		} ).catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Promoters.paint = function ( App ) {
		var byId = {};

		Promoters.figures.forEach( function ( row ) { byId[ row.promoter_id ] = row; } );

		App.page( {
			title: App.t( 'panel.promoters.title' ),
			description: App.t( 'panel.promoters.subtitle' ),
			actions: '<button class="btn btn--primary" id="promoter-add">' +
				esc( App.t( 'panel.promoters.add' ) ) + '</button>',
			body:
				( Promoters.list.length
					? App.table(
						[
							App.t( 'panel.promoters.name' ), App.t( 'panel.promoters.link' ),
							App.t( 'panel.promoters.rate' ), App.t( 'panel.promoters.sold' ),
							App.t( 'panel.promoters.owed' ), '',
						],
						Promoters.list.map( function ( promoter, index ) {
							return Promoters.row( App, promoter, index, byId[ promoter.id ] );
						} ).join( '' )
					)
					: App.emptyState( 'users', App.t( 'panel.promoters.emptyTitle' ),
						App.t( 'panel.promoters.emptyHint' ) ) ) +

				'<h3 class="subhead">' + esc( App.t( 'panel.promoters.campaigns' ) ) + '</h3>' +
				'<p class="hint">' + esc( App.t( 'panel.promoters.campaignsHint' ) ) + '</p>' +
				( Promoters.campaigns.length
					? App.table(
						[ App.t( 'panel.promoters.source' ), App.t( 'panel.promoters.campaign' ),
							App.t( 'panel.promoters.orders' ) ],
						Promoters.campaigns.map( function ( row ) {
							return '<tr><td>' + esc( row.source ) + '</td><td>' +
								esc( row.campaign || '—' ) + '</td><td class="tnum">' +
								esc( row.orders ) + '</td></tr>';
						} ).join( '' )
					)
					: App.emptyState( 'chart', App.t( 'panel.promoters.noCampaigns' ),
						App.t( 'panel.promoters.noCampaignsHint' ) ) ),
		} );

		Promoters.wire( App );
	};

	Promoters.row = function ( App, promoter, index, figures ) {
		var link = Promoters.host
			? Promoters.host + '?' + promoter.link_query
			: '?' + promoter.link_query;

		return '<tr' + ( promoter.active ? '' : ' class="is-muted"' ) + '>' +
			'<td><strong dir="auto">' + esc( promoter.name ) + '</strong>' +
			( promoter.contact_email ? '<br><span class="hint">' + esc( promoter.contact_email ) + '</span>' : '' ) +
			'</td>' +
			'<td><code class="code" data-link="' + esc( link ) + '">' + esc( link ) + '</code> ' +
			'<button class="btn btn--quiet" data-copy="' + index + '">' +
			esc( App.t( 'panel.promoters.copy' ) ) + '</button></td>' +
			'<td class="tnum">' + esc( promoter.commission_percent ) + '%</td>' +
			'<td class="tnum">' + esc( figures ? figures.tickets : ( Promoters.blind ? '—' : 0 ) ) + '</td>' +
			'<td class="tnum">' + esc( figures
				? App.money( figures.commission, figures.currency )
				: ( Promoters.blind ? '—' : App.money( 0 ) ) ) + '</td>' +
			'<td class="row row--end">' +
			'<button class="btn btn--quiet" data-edit="' + index + '">' +
			esc( App.t( 'panel.promoters.edit' ) ) + '</button>' +
			'<button class="btn btn--quiet" data-drop="' + index + '">' +
			esc( App.t( 'panel.promoters.remove' ) ) + '</button></td></tr>';
	};

	Promoters.wire = function ( App ) {
		document.getElementById( 'promoter-add' ).addEventListener( 'click', function () {
			Promoters.form( App, null );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-edit]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				Promoters.form( App, Promoters.list[ Number( button.dataset.edit ) ] );
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-copy]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var code = button.parentNode.querySelector( '[data-link]' );

				// A link is useless if it cannot leave this screen, and a promoter is on the
				// telephone while the organiser reads it out.
				if ( global.navigator.clipboard ) {
					global.navigator.clipboard.writeText( code.dataset.link ).then( function () {
						App.toast( App.t( 'panel.promoters.copied' ) );
					} );

					return;
				}

				global.getSelection().selectAllChildren( code );
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-drop]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var promoter = Promoters.list[ Number( button.dataset.drop ) ];

				App.modal( {
					title: App.t( 'panel.promoters.removeTitle', { name: promoter.name } ),
					// Said rather than assumed: somebody who has sold tickets is switched off, and
					// what they sold stays on the bookings either way.
					body: '<p>' + esc( App.t( 'panel.promoters.removeBody' ) ) + '</p>',
					submitLabel: App.t( 'panel.promoters.remove' ),
					danger: true,
					onSubmit: function () {
						return App.request( 'DELETE', '/promoters/' + promoter.id )
							.then( function () { Promoters.render( App ); } );
					},
				} );
			} );
		} );
	};

	Promoters.form = function ( App, promoter ) {
		App.modal( {
			title: promoter ? App.t( 'panel.promoters.edit' ) : App.t( 'panel.promoters.add' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="field"><label class="field__label" for="pr-name">' +
				esc( App.t( 'panel.promoters.name' ) ) + '</label>' +
				'<input class="input" id="pr-name" name="name" maxlength="160" required value="' +
				esc( promoter ? promoter.name : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="pr-code">' +
				esc( App.t( 'panel.promoters.code' ) ) + '</label>' +
				'<input class="input input--code" id="pr-code" name="code" maxlength="40" required ' +
				'pattern="[A-Za-z0-9-]+" value="' + esc( promoter ? promoter.code : '' ) + '">' +
				'<span class="field__hint">' + esc( App.t( 'panel.promoters.codeHint' ) ) + '</span></div>' +
				'<div class="field"><label class="field__label" for="pr-email">' +
				esc( App.t( 'panel.promoters.email' ) ) + '</label>' +
				'<input class="input" id="pr-email" name="contact_email" type="email" maxlength="190" value="' +
				esc( promoter && promoter.contact_email ? promoter.contact_email : '' ) + '"></div>' +
				'<div class="field"><label class="field__label" for="pr-rate">' +
				esc( App.t( 'panel.promoters.rate' ) ) + '</label>' +
				// Per cent on the screen, basis points on the wire: nobody types 750 for 7.5%.
				'<input class="input tnum" id="pr-rate" name="commission_percent" type="number" ' +
				'min="0" max="100" step="0.01" value="' +
				esc( promoter ? promoter.commission_percent : 0 ) + '"></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="pr-active" ' +
				'name="active"' + ( ! promoter || promoter.active ? ' checked' : '' ) + '>' +
				'<span>' + esc( App.t( 'panel.promoters.active' ) ) + '</span></label>' +
				'<div class="field"><label class="field__label" for="pr-note">' +
				esc( App.t( 'panel.promoters.note' ) ) + '</label>' +
				'<textarea class="input" id="pr-note" name="note" rows="2" maxlength="2000">' +
				esc( promoter && promoter.note ? promoter.note : '' ) + '</textarea></div>',
			onSubmit: function ( data ) {
				var payload = {
					name: data.get( 'name' ),
					code: data.get( 'code' ),
					contact_email: data.get( 'contact_email' ) || null,
					commission_rate: Math.round( Number( data.get( 'commission_percent' ) || 0 ) * 100 ),
					active: null !== data.get( 'active' ),
					note: data.get( 'note' ) || null,
				};

				return App.request(
					promoter ? 'PATCH' : 'POST',
					promoter ? '/promoters/' + promoter.id : '/promoters',
					payload
				).then( function () { Promoters.render( App ); } );
			},
		} );
	};

	global.SeatmapPromoters = Promoters;
}( window ) );
