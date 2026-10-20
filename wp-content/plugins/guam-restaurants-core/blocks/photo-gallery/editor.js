/**
 * Editor side of the restaurant photos block: settings and a placeholder.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'guam-restaurants/photo-gallery', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Restaurant photos', 'guam-restaurants-core' ) },
						el( components.TextControl, {
							label: __( 'Restaurant ID (0 = this restaurant)', 'guam-restaurants-core' ),
							type: 'number',
							value: attributes.restaurantId,
							onChange: function ( value ) {
								props.setAttributes( { restaurantId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the upload form', 'guam-restaurants-core' ),
							checked: attributes.showForm,
							onChange: function ( value ) {
								props.setAttributes( { showForm: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps( { className: 'gr-photos gr-photos--placeholder' } ),
					__( 'Approved photos and the upload form show here on the live page.', 'guam-restaurants-core' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
