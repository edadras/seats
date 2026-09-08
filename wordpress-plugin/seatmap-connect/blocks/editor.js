/**
 * Gutenberg block: pick which Seatmap event a page shows.
 *
 * Deliberately minimal — the seat picker itself is rendered server-side, so the editor only needs
 * to capture the event id rather than reproduce the whole widget in the canvas.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	const el = element.createElement;
	const { InspectorControls, useBlockProps } = blockEditor;
	const { PanelBody, TextControl, Placeholder } = components;
	const { __ } = i18n;

	blocks.registerBlockType( 'seatmap/event', {
		edit: function ( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			const field = el( TextControl, {
				label: __( 'Event public ID', 'seatmap-connect' ),
				help: __( 'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.', 'seatmap-connect' ),
				value: attributes.eventPublicId,
				onChange: function ( value ) {
					setAttributes( { eventPublicId: value.trim() } );
				},
			} );

			return el(
				'div',
				blockProps,
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Seat map', 'seatmap-connect' ) }, field ) ),
				el(
					Placeholder,
					{
						icon: 'tickets-alt',
						label: __( 'Seat map', 'seatmap-connect' ),
						instructions: attributes.eventPublicId
							? __( 'Customers will pick their seats here.', 'seatmap-connect' )
							: __( 'Enter the event ID to show its seating plan.', 'seatmap-connect' ),
					},
					field
				)
			);
		},

		// Rendered by PHP so prices and availability are never baked into saved post content.
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
