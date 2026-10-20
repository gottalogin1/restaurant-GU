/**
 * Visitor reviews: fills placeholders from the REST endpoint after the page
 * loads (so page caches never hold a stale list), draws the monthly trend
 * of visitor ratings, lists approved reviews newest first, and sends new
 * reviews for moderation. Review text is inserted as plain text.
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
	var _n = i18n ? i18n._n : function ( single, plural, number ) {
		return 1 === number ? single : plural;
	};
	var SVG = 'http://www.w3.org/2000/svg';
	var uid = 0;

	function el( tag, props, children ) {
		var node = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			var value = props[ key ];
			if ( value === undefined || value === null || value === false ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'className' ) {
				node.className = value;
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function svg( tag, attrs ) {
		var node = document.createElementNS( SVG, tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	function stars( rating ) {
		return el( 'span', {
			className: 'gr-vr-stars',
			role: 'img',
			'aria-label': sprintf( __( 'Rated %1$s out of %2$s', 'guam-restaurants-core' ), String( rating ), '5' ),
			style: '--gr-vr-fill:' + Math.max( 0, Math.min( 100, ( rating / 5 ) * 100 ) ) + '%',
		} );
	}

	function monthIndex( month ) {
		var parts = month.split( '-' );
		return parseInt( parts[ 0 ], 10 ) * 12 + parseInt( parts[ 1 ], 10 ) - 1;
	}

	function monthLabel( index, long ) {
		var date = new Date( Date.UTC( Math.floor( index / 12 ), index % 12, 1 ) );
		return date.toLocaleDateString( undefined, { month: long ? 'long' : 'short', year: 'numeric', timeZone: 'UTC' } );
	}

	// Single-series line: monthly average of approved visitor ratings, fixed 1-5 scale.
	// Drawn at the container's width so axis text stays at its CSS size on phones.
	function trendChart( trend, available ) {
		var width = Math.max( 280, Math.min( 720, Math.round( available ) || 640 ) );
		var height = 220;
		var pad = { top: 16, right: 40, bottom: 28, left: 28 };
		var first = monthIndex( trend[ 0 ].month );
		var last = monthIndex( trend[ trend.length - 1 ].month );
		var span = Math.max( 1, last - first );
		var x = function ( index ) {
			return pad.left + ( ( index - first ) / span ) * ( width - pad.left - pad.right );
		};
		var y = function ( value ) {
			return pad.top + ( ( 5 - value ) / 4 ) * ( height - pad.top - pad.bottom );
		};

		var titleId = 'gr-vr-trend-' + ( ++uid );
		var chart = svg( 'svg', { viewBox: '0 0 ' + width + ' ' + height, class: 'gr-vr-chart__svg', role: 'img', 'aria-labelledby': titleId } );
		var title = svg( 'title', { id: titleId } );
		title.textContent = __( 'Monthly average visitor rating, 1 to 5 stars', 'guam-restaurants-core' );
		chart.appendChild( title );

		[ 1, 2, 3, 4, 5 ].forEach( function ( value ) {
			chart.appendChild( svg( 'line', { x1: pad.left, x2: width - pad.right, y1: y( value ), y2: y( value ), class: 'gr-vr-chart__grid' } ) );
			var label = svg( 'text', { x: pad.left - 8, y: y( value ) + 4, class: 'gr-vr-chart__axis', 'text-anchor': 'end' } );
			label.textContent = String( value );
			chart.appendChild( label );
		} );

		[ first, last ].filter( function ( value, index, list ) {
			return list.indexOf( value ) === index;
		} ).forEach( function ( index, position ) {
			var label = svg( 'text', { x: x( index ), y: height - 8, class: 'gr-vr-chart__axis', 'text-anchor': position === 0 && first !== last ? 'start' : 'end' } );
			label.textContent = monthLabel( index, false );
			chart.appendChild( label );
		} );

		// A month without reviews breaks the line instead of being drawn through.
		var path = '';
		var previous = null;
		trend.forEach( function ( point ) {
			var index = monthIndex( point.month );
			path += ( previous !== null && index === previous + 1 ? 'L' : 'M' ) + x( index ).toFixed( 1 ) + ',' + y( point.average ).toFixed( 1 );
			previous = index;
		} );
		chart.appendChild( svg( 'path', { d: path, class: 'gr-vr-chart__line' } ) );

		var tooltip = el( 'div', { className: 'gr-vr-chart__tooltip', role: 'status', hidden: true } );
		function show( point, cx, cy ) {
			tooltip.textContent = sprintf(
				_n( '%1$s: %2$s average from %3$s review', '%1$s: %2$s average from %3$s reviews', point.count, 'guam-restaurants-core' ),
				monthLabel( monthIndex( point.month ), true ),
				point.average.toFixed( 1 ),
				String( point.count )
			);
			tooltip.style.left = ( cx / width ) * 100 + '%';
			tooltip.style.top = ( cy / height ) * 100 + '%';
			tooltip.hidden = false;
		}
		function hide() {
			tooltip.hidden = true;
		}

		trend.forEach( function ( point, i ) {
			var cx = x( monthIndex( point.month ) );
			var cy = y( point.average );
			var dot = svg( 'circle', { cx: cx, cy: cy, r: 4, class: 'gr-vr-chart__dot', tabindex: '0', 'aria-label': sprintf( __( '%1$s: %2$s', 'guam-restaurants-core' ), monthLabel( monthIndex( point.month ), true ), point.average.toFixed( 1 ) ) } );
			// Hit target wider than the dot.
			var hit = svg( 'rect', { x: cx - 14, y: pad.top, width: 28, height: height - pad.top - pad.bottom, class: 'gr-vr-chart__hit' } );
			[ hit, dot ].forEach( function ( node ) {
				node.addEventListener( 'mouseenter', function () {
					show( point, cx, cy );
				} );
				node.addEventListener( 'mouseleave', hide );
			} );
			dot.addEventListener( 'focus', function () {
				show( point, cx, cy );
			} );
			dot.addEventListener( 'blur', hide );
			chart.appendChild( hit );
			chart.appendChild( dot );

			if ( i === trend.length - 1 ) {
				var end = svg( 'text', { x: cx + 8, y: cy + 4, class: 'gr-vr-chart__value' } );
				end.textContent = point.average.toFixed( 1 );
				chart.appendChild( end );
			}
		} );

		var rows = trend.map( function ( point ) {
			return el( 'tr', {}, [
				el( 'td', { text: monthLabel( monthIndex( point.month ), true ) } ),
				el( 'td', { text: point.average.toFixed( 1 ) } ),
				el( 'td', { text: String( point.count ) } ),
			] );
		} );
		var table = el( 'details', { className: 'gr-vr-chart__table' }, [
			el( 'summary', { text: __( 'Show as a table', 'guam-restaurants-core' ) } ),
			el( 'table', {}, [
				el( 'thead', {}, [ el( 'tr', {}, [
					el( 'th', { scope: 'col', text: __( 'Month', 'guam-restaurants-core' ) } ),
					el( 'th', { scope: 'col', text: __( 'Average rating', 'guam-restaurants-core' ) } ),
					el( 'th', { scope: 'col', text: __( 'Reviews', 'guam-restaurants-core' ) } ),
				] ) ] ),
				el( 'tbody', {}, rows ),
			] ),
		] );

		var plot = el( 'div', { className: 'gr-vr-chart__plot' }, [ tooltip ] );
		plot.insertBefore( chart, tooltip );

		return el( 'figure', { className: 'gr-vr-chart' }, [
			el( 'figcaption', { className: 'gr-vr-chart__caption', text: __( 'Visitor rating by month (average of approved reviews written that month)', 'guam-restaurants-core' ) } ),
			plot,
			table,
		] );
	}

	function reviewItem( review, data ) {
		var badge = data.badge || {};
		var children = [
			el( 'div', { className: 'gr-vr-review__header' }, [
				stars( review.rating ),
				el( 'strong', { className: 'gr-vr-review__author', text: review.author } ),
				review.email_verified ? el( 'span', { className: 'gr-vr-badge', title: badge.explanation, text: badge.label } ) : null,
				el( 'time', { className: 'gr-vr-review__date', datetime: review.date, text: review.date } ),
			] ),
		];
		if ( review.disclosure ) {
			children.push( el( 'p', { className: 'gr-vr-review__disclosure', text: sprintf( __( 'The reviewer says they are connected to this restaurant: %s', 'guam-restaurants-core' ), review.disclosure ) } ) );
		}
		children.push( el( 'p', { className: 'gr-vr-review__text', text: review.text } ) );
		if ( review.dishes && review.dishes.length ) {
			children.push( el( 'p', { className: 'gr-vr-review__dishes', text: sprintf( __( 'Dishes tried: %s', 'guam-restaurants-core' ), review.dishes.join( ', ' ) ) } ) );
		}
		if ( review.response ) {
			children.push( el( 'div', { className: 'gr-vr-response' }, [
				el( 'p', { className: 'gr-vr-response__label' }, [
					el( 'strong', { text: ( data.labels && data.labels.owner_response ) || __( 'Response from the owner', 'guam-restaurants-core' ) } ),
					el( 'span', { text: ' · ' + review.response.author + ' · ' } ),
					el( 'time', { datetime: review.response.date, text: review.response.date } ),
				] ),
				el( 'p', { className: 'gr-vr-response__text', text: review.response.text } ),
			] ) );
		}
		return el( 'li', { className: 'gr-vr-review' }, children );
	}

	function rulesList( rules, policy ) {
		return el( 'details', { className: 'gr-vr-rules' }, [
			el( 'summary', { text: __( 'Our review rules', 'guam-restaurants-core' ) } ),
			el( 'p', { text: __( 'We publish positive and critical reviews alike. An administrator checks each review first and rejects it only if its content breaks one of these rules, which apply the same way whatever the rating. Owners can reply but cannot remove reviews, we never remove a review because a restaurant or advertiser asks, and we never pay for reviews.', 'guam-restaurants-core' ) } ),
			el( 'ul', {}, rules.map( function ( rule ) {
				return el( 'li', { text: rule.label } );
			} ) ),
			policy ? el( 'p', {}, [ el( 'a', { href: policy, text: __( 'Read our editorial policy', 'guam-restaurants-core' ) } ) ] ) : null,
		] );
	}

	function field( label, input, hint ) {
		var id = 'gr-vr-field-' + ( ++uid );
		input.id = id;
		return el( 'p', { className: 'gr-vr-form__field' }, [
			el( 'label', { for: id, text: label } ),
			input,
			hint ? el( 'small', { text: hint } ) : null,
		] );
	}

	function reviewForm( endpoint, container ) {
		var started = Date.now();
		var nonce = container.getAttribute( 'data-nonce' );
		var userName = container.getAttribute( 'data-user' ) || '';
		var message = el( 'p', { className: 'gr-vr-form__message', role: 'status' } );

		var ratingGroup = el( 'fieldset', { className: 'gr-vr-form__rating' }, [ el( 'legend', { text: __( 'Your rating', 'guam-restaurants-core' ) } ) ] );
		[ 1, 2, 3, 4, 5 ].forEach( function ( value ) {
			ratingGroup.appendChild( el( 'label', {}, [
				el( 'input', { type: 'radio', name: 'rating', value: String( value ), required: true } ),
				el( 'span', { text: sprintf( _n( '%s star', '%s stars', value, 'guam-restaurants-core' ), String( value ) ) } ),
			] ) );
		} );

		var body = el( 'textarea', { name: 'body', rows: '5', minlength: '20', maxlength: '2000', required: true } );
		var dishes = el( 'input', { type: 'text', name: 'dishes', maxlength: '190', autocomplete: 'off' } );
		var name = el( 'input', { type: 'text', name: 'author_name', maxlength: '60', required: ! nonce, autocomplete: 'nickname', value: userName } );
		var email = el( 'input', { type: 'email', name: 'email', maxlength: '200', required: true, autocomplete: 'email' } );
		var verify = el( 'input', { type: 'checkbox', name: 'verify_email', value: '1' } );
		var identity = nonce
			? el( 'p', { className: 'gr-vr-form__field gr-vr-form__check' }, [
				el( 'label', {}, [ verify, el( 'span', { text: __( 'Email me a link to confirm my account email and show the "Verified email" badge. It shows only that I confirmed an email address.', 'guam-restaurants-core' ) } ) ] ),
			] )
			: field( __( 'Email address', 'guam-restaurants-core' ), email, __( 'We email you a link to confirm your review. We do not show or keep your address. Or log in to skip this step.', 'guam-restaurants-core' ) );
		var connected = el( 'input', { type: 'checkbox', name: 'connected', value: '1' } );
		var connection = el( 'input', { type: 'text', name: 'connection', maxlength: '200' } );
		var connectionField = field( __( 'How are you connected?', 'guam-restaurants-core' ), connection, __( 'For example: I work there, or the owner is my cousin. This is shown with your review.', 'guam-restaurants-core' ) );
		connectionField.hidden = true;
		connected.addEventListener( 'change', function () {
			connectionField.hidden = ! connected.checked;
			connection.required = connected.checked;
		} );

		// Hidden from people; bots tend to fill it.
		var trap = el( 'p', { className: 'gr-vr-form__trap', 'aria-hidden': 'true' }, [
			el( 'label', { text: 'Website' }, [ el( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' } ) ] ),
		] );

		var submit = el( 'button', { type: 'submit', className: 'gr-vr-form__submit', text: __( 'Send review', 'guam-restaurants-core' ) } );
		var form = el( 'form', { className: 'gr-vr-form', novalidate: false }, [
			el( 'h3', { text: __( 'Write a review', 'guam-restaurants-core' ) } ),
			ratingGroup,
			field( __( 'Your review', 'guam-restaurants-core' ), body, __( 'At least 20 characters. Shown as you write it.', 'guam-restaurants-core' ) ),
			field( __( 'Dishes you tried (optional)', 'guam-restaurants-core' ), dishes, __( 'Up to 3, separated by commas, for example: Chicken kelaguen, Red rice. They help others find this restaurant\'s popular dishes.', 'guam-restaurants-core' ) ),
			field( __( 'Display name', 'guam-restaurants-core' ), name, __( 'Shown with your review. Please do not use your full name.', 'guam-restaurants-core' ) ),
			identity,
			el( 'p', { className: 'gr-vr-form__field gr-vr-form__check' }, [
				el( 'label', {}, [ connected, el( 'span', { text: __( 'I own, work for, or am related to someone at this restaurant, or I work for a competitor.', 'guam-restaurants-core' ) } ) ] ),
			] ),
			connectionField,
			trap,
			submit,
			message,
		] );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var checked = form.querySelector( 'input[name="rating"]:checked' );
			submit.disabled = true;
			message.textContent = __( 'Sending…', 'guam-restaurants-core' );
			window.fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: Object.assign( { 'Content-Type': 'application/json', Accept: 'application/json' }, nonce ? { 'X-WP-Nonce': nonce } : {} ),
				body: JSON.stringify( {
					rating: checked ? parseInt( checked.value, 10 ) : 0,
					body: body.value,
					author_name: name.value,
					email: nonce ? '' : email.value,
					verify_email: nonce ? verify.checked : false,
					connected: connected.checked,
					connection: connection.value,
					dishes: dishes.value,
					website: form.querySelector( 'input[name="website"]' ).value,
					elapsed_ms: Date.now() - started,
				} ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok ) {
						form.reset();
						name.value = userName;
						connectionField.hidden = true;
						message.textContent = result.data.message;
					} else {
						var errors = ( result.data && result.data.additional_errors ) || [];
						var texts = [ result.data && result.data.message ].concat( errors.map( function ( error ) {
							return error.message;
						} ) ).filter( Boolean );
						message.textContent = texts.join( ' ' ) || __( 'Your review could not be sent. Please try again.', 'guam-restaurants-core' );
						submit.disabled = false;
					}
				} )
				.catch( function () {
					message.textContent = __( 'Your review could not be sent. Please try again.', 'guam-restaurants-core' );
					submit.disabled = false;
				} );
		} );

		return form;
	}

	function load( endpoint, page ) {
		var url = endpoint + ( endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + 'page=' + page;
		return window.fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	function fill( container ) {
		var endpoint = container.getAttribute( 'data-gr-visitor-reviews' );
		var status = container.querySelector( '.gr-visitor-reviews__status' );

		// Back from an email confirmation link.
		var confirmed = /[?&]gr_review=(confirmed|expired)/.exec( window.location.search );
		if ( confirmed ) {
			container.insertBefore( el( 'p', {
				className: 'gr-vr-notice',
				role: 'status',
				text: confirmed[ 1 ] === 'confirmed'
					? __( 'Thanks, your email is confirmed. Your review will appear after an administrator checks it against our rules.', 'guam-restaurants-core' )
					: __( 'That confirmation link has expired or was already used. Please send your review again.', 'guam-restaurants-core' ),
			} ), status );
		}

		load( endpoint, 1 ).then( function ( data ) {
			status.remove();
			var summary = data.summary || {};

			if ( summary.count > 0 ) {
				container.appendChild( el( 'p', { className: 'gr-vr-summary' }, [
					stars( summary.average ),
					el( 'strong', { text: sprintf( __( '%s out of 5', 'guam-restaurants-core' ), summary.average.toFixed( 1 ) ) } ),
					el( 'span', { text: sprintf( _n( 'Average of %s approved visitor review on this site. Reviews from other sites are not included.', 'Average of %s approved visitor reviews on this site. Reviews from other sites are not included.', summary.count, 'guam-restaurants-core' ), String( summary.count ) ) } ),
				] ) );
			} else {
				container.appendChild( el( 'p', { className: 'gr-vr-summary', text: __( 'No visitor reviews yet.', 'guam-restaurants-core' ) } ) );
			}

			if ( container.getAttribute( 'data-trend' ) === '1' && data.trend && data.trend.length >= 2 ) {
				container.appendChild( trendChart( data.trend, container.clientWidth ) );
			}

			var item = function ( review ) {
				return reviewItem( review, data );
			};
			var list = el( 'ol', { className: 'gr-vr-list' }, ( data.reviews || [] ).map( item ) );
			if ( data.reviews && data.reviews.length ) {
				container.appendChild( el( 'p', { className: 'gr-vr-order', text: __( 'Newest first. All approved reviews are shown, positive and critical.', 'guam-restaurants-core' ) } ) );
				if ( data.badge && data.reviews.some( function ( review ) {
					return review.email_verified;
				} ) ) {
					container.appendChild( el( 'p', { className: 'gr-vr-order', text: data.badge.label + ': ' + data.badge.explanation } ) );
				}
				container.appendChild( list );
			}

			var page = 1;
			if ( data.pages > 1 ) {
				var more = el( 'button', { type: 'button', className: 'gr-vr-more', text: __( 'Show more reviews', 'guam-restaurants-core' ) } );
				more.addEventListener( 'click', function () {
					more.disabled = true;
					load( endpoint, ++page ).then( function ( next ) {
						next.reviews.forEach( function ( review ) {
							list.appendChild( item( review ) );
						} );
						more.disabled = false;
						if ( page >= next.pages ) {
							more.remove();
						}
					} );
				} );
				container.appendChild( more );
			}

			container.appendChild( rulesList( data.rules || [], container.getAttribute( 'data-policy' ) ) );
			if ( container.getAttribute( 'data-form' ) === '1' ) {
				container.appendChild( reviewForm( endpoint, container ) );
			}
		} ).catch( function () {
			status.textContent = __( 'Reviews could not be loaded.', 'guam-restaurants-core' );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-gr-visitor-reviews]' ), fill );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
