/**
 * Editor side of the highlights block: settings and a placeholder.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	function toggle( props, name, label ) {
		return el( components.ToggleControl, {
			label: label,
			checked: props.attributes[ name ],
			onChange: function ( value ) {
				var change = {};
				change[ name ] = value;
				props.setAttributes( change );
			},
		} );
	}

	blocks.registerBlockType( 'guam-restaurants/highlights', {
		edit: function ( props ) {
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Highlights', 'guam-restaurants-core' ) },
						el( components.TextControl, {
							label: __( 'Restaurant ID (0 = this restaurant)', 'guam-restaurants-core' ),
							type: 'number',
							value: props.attributes.restaurantId,
							onChange: function ( value ) {
								props.setAttributes( { restaurantId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						toggle( props, 'showReviews', __( 'Show user reviews', 'guam-restaurants-core' ) ),
						toggle( props, 'showForm', __( 'Show the review form', 'guam-restaurants-core' ) ),
						toggle( props, 'showTrend', __( 'Show the monthly trend chart', 'guam-restaurants-core' ) )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps( { className: 'gr-highlights gr-highlights--placeholder' } ),
					__( 'Approved owner quotes, editor\'s notes and user reviews appear here on the live page. Do not place this block in a section that shows Yelp ratings.', 'guam-restaurants-core' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
