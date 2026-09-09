/**
 * The questions an event asks at checkout.
 *
 * Dietary requirements for a dinner, a car registration for a venue with a barrier, the name of
 * every guest for an event where tickets are not transferable. Every organiser has two or three and
 * every one of them is different, so this holds the shape of a question rather than a list of the
 * questions somebody guessed people would ask.
 *
 * Scope is the decision that matters and it is put first: asked once of whoever is booking, or
 * asked of every seat. Getting that wrong is the difference between one dietary note and four.
 */
( function ( global ) {
	'use strict';

	var icon = global.SeatmapIcon;

	var Questions = {
		App: null,
		events: [],
		eventId: '',
		list: [],
	};

	Questions.render = function ( App ) {
		Questions.App = App;
		App.loading( App.t( 'panel.questions.title' ) );

		App.request( 'GET', '/events?per_page=100' )
			.then( function ( response ) {
				Questions.events = response.data || [];
				Questions.eventId = Questions.eventId || ( Questions.events[ 0 ] || {} ).id || '';
				Questions.paint();

				if ( Questions.eventId ) {
					Questions.load();
				}
			} )
			.catch( function ( error ) { App.error( error ); } );
	};

	Questions.paint = function () {
		var App = Questions.App;

		App.page( {
			title: App.t( 'panel.questions.title' ),
			description: esc( App.t( 'panel.questions.description' ) ),
			actions: Questions.eventId
				? '<button class="btn btn--primary" id="q-add">' + icon( 'plus', { size: 15 } ) +
					esc( App.t( 'panel.questions.add' ) ) + '</button>'
				: '',
			body:
				( Questions.events.length
					? '<div class="filters">' +
						'<select class="select grow" id="q-event" aria-label="' +
							esc( App.t( 'panel.questions.event' ) ) + '">' +
							Questions.events.map( function ( event ) {
								return '<option value="' + esc( event.id ) + '"' +
									( event.id === Questions.eventId ? ' selected' : '' ) + '>' +
									esc( event.name ) + '</option>';
							} ).join( '' ) +
						'</select>' +
					'</div>'
					: App.emptyState( 'calendar', App.t( 'panel.questions.noEventsTitle' ),
						esc( App.t( 'panel.questions.noEventsBody' ) ) ) ) +
				'<div id="q-rows" class="spaced"></div>',
		} );

		var picker = document.getElementById( 'q-event' );

		if ( picker ) {
			picker.addEventListener( 'change', function () {
				Questions.eventId = picker.value;
				Questions.load();
			} );
		}

		bind( 'q-add', function () { Questions.form( null ); } );
	};

	Questions.load = function () {
		var App = Questions.App;
		var host = document.getElementById( 'q-rows' );

		if ( ! host || ! Questions.eventId ) {
			return;
		}

		App.request( 'GET', '/events/' + Questions.eventId + '/questions' )
			.then( function ( response ) {
				Questions.list = response.data || [];
				host.innerHTML = Questions.rowsMarkup( App );
				Questions.bindRows();
			} )
			.catch( function ( error ) { App.toast( error.message, true ); } );
	};

	Questions.rowsMarkup = function ( App ) {
		if ( ! Questions.list.length ) {
			return App.emptyState( 'file', App.t( 'panel.questions.emptyTitle' ),
				esc( App.t( 'panel.questions.emptyBody' ) ) );
		}

		return App.table(
			[
				App.t( 'panel.questions.question' ),
				App.t( 'panel.questions.askedOf' ),
				App.t( 'panel.questions.kind' ),
				{ label: App.t( 'panel.questions.answered' ), numeric: true },
				'',
			],
			Questions.list.map( function ( question, index ) {
				return '<tr' + ( 'hidden' === question.status ? ' class="is-muted"' : '' ) + '>' +
					'<td class="table__primary">' + esc( question.label ) +
						( question.required
							? ' <span class="badge badge--neutral">' +
								esc( App.t( 'panel.questions.requiredBadge' ) ) + '</span>'
							: '' ) +
						( question.help
							? '<span class="muted on-own-line">' + esc( question.help ) + '</span>'
							: '' ) + '</td>' +
					'<td>' + esc( App.t( 'panel.questions.scopes.' + question.scope ) ) + '</td>' +
					'<td class="muted">' + esc( App.t( 'panel.questions.kinds.' + question.kind ) ) + '</td>' +
					'<td class="tnum">' + esc( App.number( question.answered || 0 ) ) + '</td>' +
					'<td class="table__actions">' +
						'<button class="btn btn--sm" data-q-edit="' + index + '">' +
							esc( App.t( 'panel.questions.edit' ) ) + '</button>' +
						( question.answered
							? ''
							: '<button class="btn btn--sm" data-q-drop="' + index + '">' +
								esc( App.t( 'panel.questions.remove' ) ) + '</button>' ) +
					'</td>' +
				'</tr>';
			} ).join( '' )
		);
	};

	Questions.bindRows = function () {
		var App = Questions.App;

		each( '[data-q-edit]', function ( button ) {
			button.addEventListener( 'click', function () {
				Questions.form( Number( button.dataset.qEdit ) );
			} );
		} );

		each( '[data-q-drop]', function ( button ) {
			button.addEventListener( 'click', function () {
				Questions.list.splice( Number( button.dataset.qDrop ), 1 );
				Questions.save().catch( function ( error ) { App.toast( error.message, true ); } );
			} );
		} );
	};

	Questions.form = function ( index ) {
		var App = Questions.App;
		var question = null === index ? {
			label: '', help: '', kind: 'text', options: [], required: false,
			scope: 'order', status: 'active',
		} : Questions.list[ index ];

		App.modal( {
			title: App.t( null === index ? 'panel.questions.add' : 'panel.questions.edit' ),
			submitLabel: App.t( 'panel.common.save' ),
			body:
				'<div class="stack">' +
				'<div class="field"><label class="field__label" for="q-label">' +
					esc( App.t( 'panel.questions.question' ) ) + '</label>' +
					'<input class="input" id="q-label" maxlength="160" required value="' +
						esc( question.label ) + '"></div>' +
				'<div class="field"><label class="field__label" for="q-help">' +
					esc( App.t( 'panel.questions.help' ) ) + '</label>' +
					'<input class="input" id="q-help" maxlength="240" value="' +
						esc( question.help || '' ) + '"></div>' +
				'<div class="field-duo">' +
					'<div class="field"><label class="field__label" for="q-scope">' +
						esc( App.t( 'panel.questions.askedOf' ) ) + '</label>' +
						'<select class="select" id="q-scope">' +
							[ 'order', 'ticket' ].map( function ( scope ) {
								return '<option value="' + scope + '"' +
									( scope === question.scope ? ' selected' : '' ) + '>' +
									esc( App.t( 'panel.questions.scopes.' + scope ) ) + '</option>';
							} ).join( '' ) +
						'</select>' +
						'<span class="field__hint">' + esc( App.t( 'panel.questions.scopeHint' ) ) +
						'</span></div>' +
					'<div class="field"><label class="field__label" for="q-kind">' +
						esc( App.t( 'panel.questions.kind' ) ) + '</label>' +
						'<select class="select" id="q-kind">' +
							[ 'text', 'choice', 'checkbox' ].map( function ( kind ) {
								return '<option value="' + kind + '"' +
									( kind === question.kind ? ' selected' : '' ) + '>' +
									esc( App.t( 'panel.questions.kinds.' + kind ) ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
				'</div>' +
				'<div class="field" id="q-options-field">' +
					'<label class="field__label" for="q-options">' +
						esc( App.t( 'panel.questions.options' ) ) + '</label>' +
					'<textarea class="input" id="q-options" rows="4">' +
						esc( ( question.options || [] ).join( '\n' ) ) + '</textarea>' +
					'<span class="field__hint">' + esc( App.t( 'panel.questions.optionsHint' ) ) +
					'</span></div>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="q-required"' +
					( question.required ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'panel.questions.requiredLabel' ) ) + '</span></label>' +
				'<label class="perms__row"><input type="checkbox" class="checkbox" id="q-hidden"' +
					( 'hidden' === question.status ? ' checked' : '' ) + '>' +
					'<span>' + esc( App.t( 'panel.questions.hiddenLabel' ) ) + '</span></label>' +
				'</div>',
			onSubmit: function () {
				var label = document.getElementById( 'q-label' ).value.trim();

				if ( ! label ) {
					App.toast( App.t( 'panel.questions.needLabel' ), true );

					return true;
				}

				var next = {
					id: question.id || null,
					label: label,
					help: document.getElementById( 'q-help' ).value.trim() || null,
					kind: document.getElementById( 'q-kind' ).value,
					options: document.getElementById( 'q-options' ).value
						.split( '\n' )
						.map( function ( line ) { return line.trim(); } )
						.filter( Boolean ),
					required: document.getElementById( 'q-required' ).checked,
					scope: document.getElementById( 'q-scope' ).value,
					status: document.getElementById( 'q-hidden' ).checked ? 'hidden' : 'active',
					answered: question.answered || 0,
				};

				if ( null === index ) {
					Questions.list.push( next );
				} else {
					Questions.list[ index ] = next;
				}

				return Questions.save();
			},
		} );

		var kind = document.getElementById( 'q-kind' );

		function shape() {
			// Choices belong to a choice question and nowhere else.
			document.getElementById( 'q-options-field' ).hidden = 'choice' !== kind.value;
		}

		kind.addEventListener( 'change', shape );
		shape();
	};

	/** The whole list, every time: what a checkout asks is one decision about the event. */
	Questions.save = function () {
		var App = Questions.App;

		return App.request( 'PUT', '/events/' + Questions.eventId + '/questions', {
			questions: Questions.list.map( function ( question ) {
				return {
					id: question.id || null,
					label: question.label,
					help: question.help,
					kind: question.kind,
					options: question.options || [],
					required: !! question.required,
					scope: question.scope,
					status: question.status || 'active',
				};
			} ),
		} ).then( function ( response ) {
			Questions.list = response.data || [];
			App.toast( App.t( 'panel.questions.saved' ) );
			Questions.paint();
			Questions.load();
		} );
	};

	/* ------------------------------------------------------------------------------ helpers */

	function bind( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	function each( selector, visit ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), visit );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );

		element.textContent = String( value === null || value === undefined ? '' : value );

		return element.innerHTML;
	}

	global.SeatmapQuestions = Questions;
}( typeof window !== 'undefined' ? window : globalThis ) );
