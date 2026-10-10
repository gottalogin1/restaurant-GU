/**
 * Filter form enhancement: applies filters as they change, asks the REST
 * endpoint for the results and keeps the address bar in step, so every
 * state has a shareable URL. Without this script the form still works as
 * a plain GET form.
 *
 * The map page uses the same form (data-gr-filters="map"); there this
 * script only keeps the URL in step and tells map.js to reload points.
 */
( function () {
	var settings = window.guamRestaurantsDirectory || {};
	var i18n = window.wp && window.wp.i18n;
	var MULTI = [ 'village', 'mall', 'cuisine', 'price' ];

	function params( form ) {
		var out = new URLSearchParams();
		var q = form.querySelector( '[name="q"]' );
		if ( q && q.value.trim() ) {
			out.set( 'q', q.value.trim() );
		}
		MULTI.forEach( function ( name ) {
			var values = [];
			form.querySelectorAll( 'input[name="' + name + '[]"]:checked' ).forEach( function ( box ) {
				values.push( box.value );
			} );
			if ( values.length ) {
				out.set( name, values.join( ',' ) );
			}
		} );
		[ 'award', 'open' ].forEach( function ( name ) {
			var box = form.querySelector( 'input[name="' + name + '"]' );
			if ( box && box.checked ) {
				out.set( name, box.value );
			}
		} );
		var sort = form.querySelector( '[name="sort"]' );
		if ( sort && sort.value ) {
			var fallback = out.has( 'q' ) ? 'relevance' : 'name';
			var offersRelevance = !! sort.querySelector( '[value="relevance"]' );
			if ( sort.value !== fallback && ! ( sort.value === 'name' && ! offersRelevance ) ) {
				out.set( 'sort', sort.value );
			}
		}
		return out;
	}

	function shareable( form, query ) {
		var text = query.toString().replace( /%2C/g, ',' );
		return form.getAttribute( 'action' ).split( '?' )[ 0 ] + ( text ? '?' + text : '' );
	}

	function updateCounts( form ) {
		form.querySelectorAll( '[data-gr-facet]' ).forEach( function ( facet ) {
			var count = facet.querySelectorAll( 'input:checked' ).length;
			var label = facet.querySelector( '.gr-facet__count' );
			if ( label ) {
				/* translators: %d: number of selected options. */
				label.textContent = count ? ( i18n ? i18n.sprintf( i18n.__( ' (%d selected)', 'guam-restaurants-core' ), count ) : ' (' + count + ')' ) : '';
			}
		} );
	}

	function initList( form ) {
		var results = document.getElementById( 'gr-directory-results' );
		if ( ! results || ! settings.endpoint ) {
			return;
		}
		var controller = null;

		function load( query, page, push, focus ) {
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();
			var request = new URLSearchParams( query );
			if ( page > 1 ) {
				request.set( 'page', String( page ) );
			}
			var context = form.getAttribute( 'data-gr-context' );
			if ( context ) {
				request.set( 'context', context );
			}
			results.setAttribute( 'aria-busy', 'true' );
			fetch( settings.endpoint + ( settings.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + request.toString(), {
				signal: controller.signal,
				headers: { Accept: 'application/json' },
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}
					return response.json();
				} )
				.then( function ( data ) {
					results.innerHTML = data.html;
					results.removeAttribute( 'aria-busy' );
					if ( push ) {
						window.history.pushState( { grDirectory: true }, '', data.url );
					}
					if ( window.guamRestaurantsHours ) {
						window.guamRestaurantsHours.apply( results );
					}
					if ( focus ) {
						results.focus( { preventScroll: true } );
						results.scrollIntoView( { block: 'start' } );
					}
				} )
				.catch( function ( error ) {
					if ( error.name !== 'AbortError' ) {
						window.location.href = shareable( form, query );
					}
				} );
		}

		var timer = null;
		function changed( delay ) {
			updateCounts( form );
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				load( params( form ), 1, true, false );
			}, delay );
		}

		form.addEventListener( 'change', function ( event ) {
			if ( event.target.name !== 'q' ) {
				changed( 0 );
			}
		} );
		form.addEventListener( 'input', function ( event ) {
			if ( event.target.name === 'q' ) {
				changed( 400 );
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			window.clearTimeout( timer );
			load( params( form ), 1, true, true );
		} );
		results.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.gr-pagination a' );
			if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey ) {
				return;
			}
			var match = link.getAttribute( 'href' ).match( /\/page\/(\d+)\/?|[?&]paged=(\d+)/ );
			event.preventDefault();
			load( params( form ), match ? parseInt( match[ 1 ] || match[ 2 ], 10 ) : 1, true, true );
		} );
		window.addEventListener( 'popstate', function () {
			window.location.reload();
		} );
	}

	function initMap( form ) {
		function changed() {
			updateCounts( form );
			var query = params( form );
			window.history.replaceState( { grMap: true }, '', shareable( form, query ) );
			document.dispatchEvent( new CustomEvent( 'gr:map-filters', { detail: { query: query.toString() } } ) );
		}
		form.addEventListener( 'change', function ( event ) {
			if ( event.target.name !== 'q' ) {
				changed();
			}
		} );
		var timer = null;
		form.addEventListener( 'input', function ( event ) {
			if ( event.target.name === 'q' ) {
				window.clearTimeout( timer );
				timer = window.setTimeout( changed, 400 );
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			changed();
		} );
	}

	function init() {
		document.querySelectorAll( 'form[data-gr-filters]' ).forEach( function ( form ) {
			if ( form.getAttribute( 'data-gr-filters' ) === 'map' ) {
				initMap( form );
			} else {
				initList( form );
			}
		} );
	}

	window.guamRestaurantsFilters = { params: params };
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
