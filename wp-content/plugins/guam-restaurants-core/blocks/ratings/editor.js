/**
 * Editor side of the ratings block. The editor shows a placeholder, not
 * live ratings, so editing a page never spends API calls.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'guam-restaurants/ratings', {
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
						{ title: __( 'Ratings', 'guam-restaurants-core' ) },
						el( components.TextControl, {
							label: __( 'Restaurant ID (0 = this restaurant)', 'guam-restaurants-core' ),
							type: 'number',
							value: attributes.restaurantId,
							onChange: function ( value ) {
								props.setAttributes( { restaurantId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Sources', 'guam-restaurants-core' ),
							help: __( 'Yelp ratings may not appear next to other ratings or visitor content, so Yelp has its own option. Put it in a separate page section.', 'guam-restaurants-core' ),
							value: attributes.sources,
							options: [
								{ label: __( 'Google and Foursquare', 'guam-restaurants-core' ), value: '' },
								{ label: __( 'Google only', 'guam-restaurants-core' ), value: 'google' },
								{ label: __( 'Foursquare only', 'guam-restaurants-core' ), value: 'foursquare' },
								{ label: __( 'Yelp only', 'guam-restaurants-core' ), value: 'yelp' },
							],
							onChange: function ( value ) {
								props.setAttributes( { sources: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show review text', 'guam-restaurants-core' ),
							checked: attributes.showReviews,
							onChange: function ( value ) {
								props.setAttributes( { showReviews: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps( { className: 'gr-ratings gr-ratings--placeholder' } ),
					__( 'Restaurant ratings load here on the live page.', 'guam-restaurants-core' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
