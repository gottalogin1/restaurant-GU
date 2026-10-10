/**
 * Maps with MapLibre GL JS. The library (about 1 MB plus its worker) is loaded only when
 * a map is about to be seen: on restaurant pages when the map scrolls into
 * view, on the map page straight away.
 *
 * Tiles and their attribution come from the style URL (OpenFreeMap by
 * default, see Site\Maps); MapLibre shows the attribution on the map.
 */
( function () {
	var settings = window.guamRestaurantsMap || {};
	var directory = window.guamRestaurantsDirectory || {};
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var loading = null;

	// MapLibre 6 ships only as an ES module; it starts its worker from the
	// file next to it (maplibre-gl-worker.mjs). The server must send .mjs
	// files as JavaScript (docs/deployment.md).
	function loadLibrary() {
		if ( loading ) {
			return loading;
		}
		var css = document.createElement( 'link' );
		css.rel = 'stylesheet';
		css.href = settings.library.css;
		document.head.appendChild( css );
		loading = import( settings.library.js );
		return loading;
	}

	function failed( container, message ) {
		var note = document.createElement( 'p' );
		note.className = 'gr-map__error';
		note.textContent = message;
		container.innerHTML = '';
		container.appendChild( note );
	}

	function baseMap( maplibregl, container, options ) {
		var map = new maplibregl.Map(
			Object.assign(
				{
					container: container,
					style: settings.style,
					cooperativeGestures: true,
					attributionControl: { compact: true },
				},
				options
			)
		);
		map.addControl( new maplibregl.NavigationControl( { showCompass: false } ), 'top-right' );
		map.on( 'error', function ( event ) {
			if ( ! map.isStyleLoaded() && event && event.error && /style|fetch|load/i.test( String( event.error.message ) ) ) {
				failed( container, __( 'The map could not load right now.', 'guam-restaurants-core' ) );
			}
		} );
		return map;
	}

	function initPoint( container ) {
		var point = container.getAttribute( 'data-gr-map-point' ).split( ',' ).map( Number );
		loadLibrary()
			.then( function ( maplibregl ) {
				var map = baseMap( maplibregl, container, { center: point, zoom: 15.5 } );
				new maplibregl.Marker( { color: '#0b6e8a' } ).setLngLat( point ).addTo( map );
			} )
			.catch( function () {
				failed( container, __( 'The map could not load right now.', 'guam-restaurants-core' ) );
			} );
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = String( text || '' );
		return div.innerHTML;
	}

	function initExplorer( container ) {
		var status = document.querySelector( '[data-gr-map-status]' );
		var list = document.querySelector( '[data-gr-map-list]' );
		var listBase = list ? list.getAttribute( 'href' ).split( '?' )[ 0 ] : '';
		var query = window.location.search.replace( /^\?/, '' );
		var map = null;

		function say( text ) {
			if ( status ) {
				status.textContent = text;
			}
		}

		function fetchPoints() {
			var url = directory.map + ( directory.map.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
			return fetch( url, { headers: { Accept: 'application/json' } } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} );
		}

		function show( data ) {
			var count = data.features.length;
			say(
				i18n
					? i18n.sprintf( i18n._n( '%d restaurant on the map.', '%d restaurants on the map.', count, 'guam-restaurants-core' ), count )
					: count + ' restaurants on the map.'
			);
			if ( list ) {
				list.setAttribute( 'href', listBase + ( query ? '?' + query : '' ) );
			}
			if ( map && map.getSource( 'restaurants' ) ) {
				map.getSource( 'restaurants' ).setData( data );
			}
		}

		Promise.all( [ loadLibrary(), fetchPoints() ] )
			.then( function ( results ) {
				var maplibregl = results[ 0 ];
				var data = results[ 1 ];
				map = baseMap( maplibregl, container, { center: settings.center, zoom: settings.zoom } );
				map.on( 'load', function () {
					map.addSource( 'restaurants', { type: 'geojson', data: data, cluster: true, clusterRadius: 45, clusterMaxZoom: 15 } );
					map.addLayer( {
						id: 'clusters',
						type: 'circle',
						source: 'restaurants',
						filter: [ 'has', 'point_count' ],
						paint: {
							'circle-color': '#0b6e8a',
							'circle-opacity': 0.9,
							'circle-stroke-color': '#ffffff',
							'circle-stroke-width': 2,
							'circle-radius': [ 'step', [ 'get', 'point_count' ], 14, 10, 18, 50, 24, 200, 30 ],
						},
					} );
					map.addLayer( {
						id: 'cluster-count',
						type: 'symbol',
						source: 'restaurants',
						filter: [ 'has', 'point_count' ],
						layout: { 'text-field': [ 'get', 'point_count_abbreviated' ], 'text-size': 13, 'text-font': [ 'Noto Sans Bold' ] },
						paint: { 'text-color': '#ffffff' },
					} );
					map.addLayer( {
						id: 'points',
						type: 'circle',
						source: 'restaurants',
						filter: [ '!', [ 'has', 'point_count' ] ],
						paint: { 'circle-color': '#b5462f', 'circle-radius': 7, 'circle-stroke-color': '#ffffff', 'circle-stroke-width': 2 },
					} );
					map.on( 'click', 'clusters', function ( event ) {
						var feature = map.queryRenderedFeatures( event.point, { layers: [ 'clusters' ] } )[ 0 ];
						map.getSource( 'restaurants' )
							.getClusterExpansionZoom( feature.properties.cluster_id )
							.then( function ( zoom ) {
								map.easeTo( { center: feature.geometry.coordinates, zoom: zoom } );
							} );
					} );
					map.on( 'click', 'points', function ( event ) {
						var feature = event.features[ 0 ];
						var p = feature.properties;
						var meta = [ p.cuisine, p.place, p.price ].filter( Boolean ).map( escapeHtml ).join( ' · ' );
						new maplibregl.Popup( { offset: 10 } )
							.setLngLat( feature.geometry.coordinates )
							.setHTML( '<p class="gr-popup__name"><a href="' + escapeHtml( p.url ) + '">' + escapeHtml( p.name ) + '</a></p>' + ( meta ? '<p class="gr-popup__meta">' + meta + '</p>' : '' ) )
							.addTo( map );
					} );
					[ 'clusters', 'points' ].forEach( function ( layer ) {
						map.on( 'mouseenter', layer, function () {
							map.getCanvas().style.cursor = 'pointer';
						} );
						map.on( 'mouseleave', layer, function () {
							map.getCanvas().style.cursor = '';
						} );
					} );
				} );
				show( data );
			} )
			.catch( function () {
				say( __( 'The map could not load right now. The list view shows the same restaurants.', 'guam-restaurants-core' ) );
			} );

		document.addEventListener( 'gr:map-filters', function ( event ) {
			query = event.detail.query;
			say( __( 'Updating the map…', 'guam-restaurants-core' ) );
			fetchPoints()
				.then( show )
				.catch( function () {
					say( __( 'The map could not be updated.', 'guam-restaurants-core' ) );
				} );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-gr-map-explorer]' ).forEach( initExplorer );
		var points = document.querySelectorAll( '[data-gr-map-point]' );
		if ( ! points.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			points.forEach( initPoint );
			return;
		}
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						observer.unobserve( entry.target );
						initPoint( entry.target );
					}
				} );
			},
			{ rootMargin: '0px', threshold: 0.25 }
		);
		points.forEach( function ( node ) {
			observer.observe( node );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
