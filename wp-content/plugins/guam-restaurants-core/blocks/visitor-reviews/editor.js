/**
 * Editor side of the visitor reviews block: settings and a placeholder.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'guam-restaurants/visitor-reviews', {
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
						{ title: __( 'Visitor reviews', 'guam-restaurants-core' ) },
						el( components.TextControl, {
							label: __( 'Restaurant ID (0 = this restaurant)', 'guam-restaurants-core' ),
							type: 'number',
							value: attributes.restaurantId,
							onChange: function ( value ) {
								props.setAttributes( { restaurantId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the review form', 'guam-restaurants-core' ),
							checked: attributes.showForm,
							onChange: function ( value ) {
								props.setAttributes( { showForm: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the monthly trend chart', 'guam-restaurants-core' ),
							checked: attributes.showTrend,
							onChange: function ( value ) {
								props.setAttributes( { showTrend: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps( { className: 'gr-visitor-reviews gr-visitor-reviews--placeholder' } ),
					__( 'Visitor reviews load here on the live page. Do not place this block in a section that shows Yelp ratings.', 'guam-restaurants-core' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
