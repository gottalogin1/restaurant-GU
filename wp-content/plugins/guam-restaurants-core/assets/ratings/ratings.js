/**
 * Fills ratings placeholders from the REST endpoint after the page loads,
 * so full-page caches never hold ratings. Each source is shown in its own
 * section with its logo or attribution and a link to the source; review
 * text is inserted as plain text, exactly as the API returned it.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return format.replace( /%(\d\$)?[sd]/g, function () {
			return args.shift();
		} );
	};

	function el( tag, props, children ) {
		var node = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			var value = props[ key ];
			if ( value === undefined || value === null || value === '' ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'className' ) {
				node.className = value;
			} else if ( key === 'style' ) {
				Object.keys( value ).forEach( function ( prop ) {
					node.style.setProperty( prop, value[ prop ] );
				} );
			} else {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function isHttp( url ) {
		return typeof url === 'string' && /^https?:\/\//i.test( url );
	}

	// Links are used exactly as the API returned them (Yelp requires its tracking parameters intact).
	function link( url, className, children ) {
		if ( ! isHttp( url ) ) {
			return el( 'span', { className: className }, children );
		}
		return el( 'a', { className: className, href: url, target: '_blank', rel: 'noopener nofollow' }, children );
	}

	function stars( rating, scale ) {
		var percent = Math.max( 0, Math.min( 100, ( rating / scale ) * 100 ) );
		return el( 'span', {
			className: 'gr-stars',
			role: 'img',
			'aria-label': sprintf( __( 'Rated %1$s out of %2$s', 'guam-restaurants-core' ), rating.toFixed( 1 ), String( scale ) ),
			style: { '--gr-stars-fill': percent + '%' },
		} );
	}

	function ratingVisual( data ) {
		if ( data.source === 'yelp' ) {
			// Yelp's own star image; never redrawn or shown as a bare number.
			return el( 'img', {
				className: 'gr-ratings__stars-image',
				src: data.stars_image,
				alt: sprintf( __( '%s star rating', 'guam-restaurants-core' ), String( data.rating ) ),
			} );
		}
		if ( data.rating_scale === 10 ) {
			return el( 'span', { className: 'gr-ratings__score', text: sprintf( __( '%s/10', 'guam-restaurants-core' ), data.rating.toFixed( 1 ) ) } );
		}
		return el( 'span', { className: 'gr-ratings__stars' }, [
			stars( data.rating, data.rating_scale ),
			el( 'span', { className: 'gr-ratings__number', text: data.rating.toFixed( 1 ), 'aria-hidden': 'true' } ),
		] );
	}

	function countLabel( data ) {
		var format = data.source === 'foursquare' ? __( '%s ratings', 'guam-restaurants-core' ) : __( '%s reviews', 'guam-restaurants-core' );
		return sprintf( format, Number( data.review_count ).toLocaleString() );
	}

	function brand( data ) {
		var attribution = data.attribution || {};
		var mark = isHttp( attribution.logo_url )
			? el( 'img', { className: 'gr-ratings__logo', src: attribution.logo_url, alt: attribution.text || data.label } )
			: el( 'span', { className: 'gr-ratings__attribution', text: attribution.text || data.label } );
		return link( data.url, 'gr-ratings__brand', [ mark ] );
	}

	function providers( data ) {
		var list = ( data.attribution && data.attribution.providers ) || [];
		if ( ! list.length ) {
			return null;
		}
		var items = list.map( function ( provider ) {
			return link( provider.url, 'gr-ratings__provider', [ el( 'span', { text: provider.name } ) ] );
		} );
		return el( 'p', { className: 'gr-ratings__providers' }, [ el( 'span', { text: data.label + ' · ' } ) ].concat( items ) );
	}

	function review( data, item ) {
		var author = item.author || sprintf( __( '%s user', 'guam-restaurants-core' ), data.label );
		var header = [];
		if ( isHttp( item.author_photo ) ) {
			header.push( el( 'img', { className: 'gr-review__avatar', src: item.author_photo, alt: '', width: '32', height: '32', loading: 'lazy' } ) );
		}
		header.push( link( item.author_url, 'gr-review__author', [ el( 'span', { text: author } ) ] ) );
		if ( isHttp( item.stars_image ) ) {
			header.push( el( 'img', { className: 'gr-review__stars-image', src: item.stars_image, alt: sprintf( __( '%s star rating', 'guam-restaurants-core' ), String( item.rating ) ) } ) );
		} else if ( typeof item.rating === 'number' ) {
			header.push( stars( item.rating, 5 ) );
		}
		if ( item.time ) {
			header.push( el( 'span', { className: 'gr-review__time', text: item.time } ) );
		}

		var footer = [ link( item.url, 'gr-review__source', [ el( 'span', { text: sprintf( __( 'Read on %s', 'guam-restaurants-core' ), data.label ) } ) ] ) ];
		if ( isHttp( item.flag_url ) ) {
			footer.push( link( item.flag_url, 'gr-review__flag', [ el( 'span', { text: __( 'Report review', 'guam-restaurants-core' ) } ) ] ) );
		}

		return el( 'li', { className: 'gr-review' }, [
			el( 'div', { className: 'gr-review__header' }, header ),
			el( 'p', { className: 'gr-review__text', text: item.text } ),
			el( 'div', { className: 'gr-review__footer' }, footer ),
		] );
	}

	// Google's live phone, website and hours, in Google's own section and never stored.
	function details( data ) {
		var info = data.details || {};
		var rows = [];
		if ( info.phone ) {
			rows.push( el( 'div', { className: 'gr-ratings__detail' }, [
				el( 'dt', { text: __( 'Phone', 'guam-restaurants-core' ) } ),
				el( 'dd', {}, [ el( 'a', { href: 'tel:' + info.phone.replace( /[^0-9+]/g, '' ), text: info.phone } ) ] ),
			] ) );
		}
		if ( isHttp( info.website ) ) {
			rows.push( el( 'div', { className: 'gr-ratings__detail' }, [
				el( 'dt', { text: __( 'Website', 'guam-restaurants-core' ) } ),
				el( 'dd', {}, [ link( info.website, 'gr-ratings__website', [ el( 'span', { text: info.website.replace( /^https?:\/\/(www\.)?/i, '' ).replace( /\/$/, '' ) } ) ] ) ] ),
			] ) );
		}
		if ( info.hours && info.hours.length ) {
			rows.push( el( 'div', { className: 'gr-ratings__detail' }, [
				el( 'dt', { text: __( 'Hours', 'guam-restaurants-core' ) } ),
				el( 'dd', {}, [ el( 'ul', { className: 'gr-ratings__hours' }, info.hours.map( function ( line ) {
					return el( 'li', { text: line } );
				} ) ) ] ),
			] ) );
		}
		if ( ! rows.length ) {
			return null;
		}
		return el( 'div', { className: 'gr-ratings__details' }, [
			el( 'p', { className: 'gr-ratings__details-title', text: sprintf( __( 'Contact and hours from %s', 'guam-restaurants-core' ), data.label ) } ),
			el( 'dl', {}, rows ),
		] );
	}

	function section( data ) {
		var children = [
			brand( data ),
			link( data.url, 'gr-ratings__summary', [
				ratingVisual( data ),
				el( 'span', { className: 'gr-ratings__count', text: countLabel( data ) } ),
			] ),
			providers( data ),
			data.source === 'google' ? details( data ) : null,
		];
		if ( data.reviews && data.reviews.length ) {
			children.push( el( 'ul', { className: 'gr-ratings__reviews' }, data.reviews.map( function ( item ) {
				return review( data, item );
			} ) ) );
		}
		if ( data.reviews_notice ) {
			children.push( el( 'p', { className: 'gr-ratings__notice', text: data.reviews_notice } ) );
		}
		return el( 'section', { className: 'gr-ratings__source gr-ratings__source--' + data.source }, children );
	}

	function valid( data ) {
		return data && typeof data.rating === 'number' && isHttp( data.url ) && ( data.source !== 'yelp' || isHttp( data.stars_image ) );
	}

	function fill( container ) {
		var endpoint = container.getAttribute( 'data-gr-ratings' );
		if ( ! endpoint ) {
			return;
		}
		window.fetch( endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( body ) {
				var ratings = ( body.ratings || [] ).filter( valid );
				container.textContent = '';
				if ( ! ratings.length ) {
					container.hidden = true;
					return;
				}
				container.appendChild( el( 'div', { className: 'gr-ratings__grid' }, ratings.map( section ) ) );
			} )
			.catch( function () {
				container.hidden = true;
			} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-gr-ratings]' ), fill );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
