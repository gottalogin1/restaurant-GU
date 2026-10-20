/**
 * Editor side of the public site blocks. Blocks that depend on the page
 * being viewed (a restaurant, a village, the URL's filters) show a
 * placeholder; the others preview with ServerSideRender.
 */
( function ( blocks, element, blockEditor, components, i18n, ServerSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;

	var contextual = [
		'directory',
		'term-intro',
		'restaurant-details',
		'restaurant-map',
		'change-history',
		'listing-actions',
		'listing-context',
		'map-explorer',
		'sponsored-slot',
		'advertiser-inquiry',
		'claim-form',
		'owner-content',
		'correction-form',
		'related-links',
		'restaurant-hero',
	];

	function number( props, name, label ) {
		return el( components.TextControl, {
			label: label,
			type: 'number',
			value: props.attributes[ name ],
			onChange: function ( value ) {
				var attrs = {};
				attrs[ name ] = parseInt( value, 10 ) || 0;
				props.setAttributes( attrs );
			},
		} );
	}

	function select( props, name, label, options ) {
		return el( components.SelectControl, {
			label: label,
			value: props.attributes[ name ],
			options: options,
			onChange: function ( value ) {
				var attrs = {};
				attrs[ name ] = value;
				props.setAttributes( attrs );
			},
		} );
	}

	function controls( name, props ) {
		switch ( name ) {
			case 'term-links':
				return [
					el( components.TextControl, {
						label: __( 'Heading (hidden with the list when empty)', 'guam-restaurants-core' ),
						value: props.attributes.heading,
						onChange: function ( value ) {
							props.setAttributes( { heading: value } );
						},
					} ),
					select( props, 'taxonomy', __( 'List', 'guam-restaurants-core' ), [
						{ label: __( 'Villages', 'guam-restaurants-core' ), value: 'gr_village' },
						{ label: __( 'Malls', 'guam-restaurants-core' ), value: 'gr_mall' },
						{ label: __( 'Cuisines', 'guam-restaurants-core' ), value: 'gr_cuisine' },
						{ label: __( 'Price levels', 'guam-restaurants-core' ), value: 'gr_price_level' },
					] ),
					el( components.ToggleControl, {
						label: __( 'Show counts', 'guam-restaurants-core' ),
						checked: props.attributes.showCounts,
						onChange: function ( value ) {
							props.setAttributes( { showCounts: value } );
						},
					} ),
					number( props, 'limit', __( 'Most listed only (0 = all)', 'guam-restaurants-core' ) ),
					select( props, 'style', __( 'Style', 'guam-restaurants-core' ), [
						{ label: __( 'Chips', 'guam-restaurants-core' ), value: 'chips' },
						{ label: __( 'Tiles', 'guam-restaurants-core' ), value: 'tiles' },
					] ),
				];
			case 'restaurant-row':
				return [
					el( components.TextControl, {
						label: __( 'Heading', 'guam-restaurants-core' ),
						value: props.attributes.heading,
						onChange: function ( value ) {
							props.setAttributes( { heading: value } );
						},
					} ),
					select( props, 'source', __( 'Show', 'guam-restaurants-core' ), [
						{ label: __( 'Restaurants matching a filter', 'guam-restaurants-core' ), value: 'filters' },
						{ label: __( 'Newest listings', 'guam-restaurants-core' ), value: 'new' },
					] ),
					el( components.TextControl, {
						label: __( 'Filter, as in the list page address (for example award=winner or village=dededo&price=p1)', 'guam-restaurants-core' ),
						value: props.attributes.query,
						onChange: function ( value ) {
							props.setAttributes( { query: value } );
						},
					} ),
					number( props, 'limit', __( 'Most shown', 'guam-restaurants-core' ) ),
				];
			case 'recent-changes':
				return [
					select( props, 'kind', __( 'Show', 'guam-restaurants-core' ), [
						{ label: __( 'Openings', 'guam-restaurants-core' ), value: 'openings' },
						{ label: __( 'Reopenings', 'guam-restaurants-core' ), value: 'reopenings' },
						{ label: __( 'Closures', 'guam-restaurants-core' ), value: 'closures' },
					] ),
					number( props, 'days', __( 'Days back', 'guam-restaurants-core' ) ),
					number( props, 'limit', __( 'Most shown', 'guam-restaurants-core' ) ),
					select( props, 'layout', __( 'Layout', 'guam-restaurants-core' ), [
						{ label: __( 'Strip', 'guam-restaurants-core' ), value: 'strip' },
						{ label: __( 'List', 'guam-restaurants-core' ), value: 'list' },
					] ),
				];
			case 'awards-feature':
				return [ number( props, 'limit', __( 'Most shown', 'guam-restaurants-core' ) ) ];
			case 'sponsored-slot':
				return [
					select( props, 'product', __( 'Ad product', 'guam-restaurants-core' ), [
						{ label: __( 'Banner (rotating)', 'guam-restaurants-core' ), value: 'banner' },
						{ label: __( 'Sponsored listings (rotating)', 'guam-restaurants-core' ), value: 'featured_listing' },
						{ label: __( 'Category sponsor (one per page)', 'guam-restaurants-core' ), value: 'category_sponsor' },
						{ label: __( 'Homepage leaderboard (one per page)', 'guam-restaurants-core' ), value: 'leaderboard' },
					] ),
					number( props, 'count', __( 'Most ads shown (exclusive products show one)', 'guam-restaurants-core' ) ),
				];
			default:
				return null;
		}
	}

	Object.keys( blocks.getBlockTypes ? {} : {} ); // Keeps older editors happy.

	var names = [
		'directory',
		'search-form',
		'term-links',
		'recent-changes',
		'awards-feature',
		'sponsored-slot',
		'term-intro',
		'restaurant-details',
		'restaurant-map',
		'change-history',
		'listing-actions',
		'listing-context',
		'map-explorer',
		'data-attributions',
		'policy-links',
		'advertiser-inquiry',
		'claim-form',
		'owner-content',
		'correction-form',
		'related-links',
		'restaurant-row',
		'restaurant-hero',
		'random-picker',
	];

	names.forEach( function ( name ) {
		var full = 'guam-restaurants/' + name;
		blocks.registerBlockType( full, {
			edit: function ( props ) {
				var type = blocks.getBlockType( full );
				var inspector = controls( name, props );
				var body =
					contextual.indexOf( name ) !== -1
						? el( components.Placeholder, { label: type.title, instructions: type.description } )
						: el( ServerSideRender, { block: full, attributes: props.attributes } );
				return el(
					'div',
					blockEditor.useBlockProps(),
					inspector
						? el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: type.title }, inspector ) )
						: null,
					body
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender );
