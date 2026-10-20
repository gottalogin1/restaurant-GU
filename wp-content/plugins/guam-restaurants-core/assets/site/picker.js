/**
 * Random place picker enhancement: asks the REST endpoint for a pick and
 * shows it without reloading the page. "Pick again" sends the places
 * already shown so they are not repeated; changing a choice starts a new
 * round. The address bar keeps the choices (not the pick), so the link can
 * be shared. Nothing is stored in the browser. Without this script the form
 * still works as a plain GET form.
 */
( function () {
	var settings = window.guamRestaurantsPicker || {};
	var i18n = window.wp && window.wp.i18n;
	var MULTI = [ 'cuisine', 'price' ];

	function __( text ) {
		return i18n ? i18n.__( text, 'guam-restaurants-core' ) : text;
	}

	function params( form ) {
		var out = new URLSearchParams();
		[ 'village', 'mall' ].forEach( function ( name ) {
			var select = form.querySelector( 'select[name="' + name + '"]' );
			if ( select && select.value ) {
				out.set( name, select.value );
			}
		} );
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
		return out;
	}

	function updateCount( form ) {
		var facet = form.querySelector( '[data-gr-facet]' );
		if ( ! facet || ! i18n ) {
			return;
		}
		var count = facet.querySelectorAll( 'input:checked' ).length;
		var label = facet.querySelector( '.gr-facet__count' );
		if ( label ) {
			/* translators: %d: number of selected options. */
			label.textContent = count ? i18n.sprintf( i18n._n( ' (%d selected)', ' (%d selected)', count, 'guam-restaurants-core' ), count ) : '';
		}
	}

	function init( form ) {
		var root = form.closest( '.gr-picker' );
		var result = root && root.querySelector( '[data-gr-picker-result]' );
		var button = form.querySelector( '.gr-picker__go' );
		if ( ! result || ! settings.endpoint || ! window.fetch ) {
			return;
		}
		var page = form.getAttribute( 'action' ).split( '?' )[ 0 ];
		var shown = result.querySelector( '[data-gr-not]' );
		var seen = shown ? shown.getAttribute( 'data-gr-not' ) : '';
		var controller = null;

		function pick( again ) {
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();
			var choices = params( form );
			var request = new URLSearchParams( choices );
			if ( again && seen ) {
				request.set( 'not', seen );
			}
			request.set( 'page_url', page );
			result.setAttribute( 'aria-busy', 'true' );
			root.classList.add( 'is-picking' );
			if ( button ) {
				button.disabled = true;
				button.textContent = __( 'Picking…' );
			}
			function done() {
				result.removeAttribute( 'aria-busy' );
				root.classList.remove( 'is-picking' );
				if ( button ) {
					button.disabled = false;
					button.textContent = __( 'Pick a place for me' );
				}
			}
			fetch( settings.endpoint + ( settings.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + request.toString(), {
				signal: controller.signal,
				headers: { Accept: 'application/json' },
				cache: 'no-store',
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}
					return response.json();
				} )
				.then( function ( data ) {
					done();
					seen = data.not || '';
					result.innerHTML = data.html;
					if ( data.url ) {
						window.history.replaceState( null, '', data.url );
					}
					if ( window.guamRestaurantsHours ) {
						window.guamRestaurantsHours.apply( result );
					}
					var next = again ? result.querySelector( '.gr-picker__again' ) : null;
					( next || result ).focus( { preventScroll: true } );
					result.scrollIntoView( { block: 'nearest' } );
				} )
				.catch( function ( error ) {
					if ( error.name === 'AbortError' ) {
						return;
					}
					done();
					// Fall back to the plain form.
					choices.set( 'pick', '1' );
					if ( again && seen ) {
						choices.set( 'not', seen );
					}
					window.location.href = page + '?' + choices.toString().replace( /%2C/g, ',' );
				} );
		}

		form.addEventListener( 'change', function () {
			// New choices start a new round.
			seen = '';
			updateCount( form );
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			pick( false );
		} );
		result.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.gr-picker__again' );
			if ( link ) {
				event.preventDefault();
				pick( true );
			}
		} );
	}

	function start() {
		document.querySelectorAll( 'form[data-gr-picker]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
