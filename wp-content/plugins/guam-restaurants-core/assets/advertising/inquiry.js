/**
 * Advertiser inquiry form: loads a fresh nonce, the choices and any
 * pre-filled restaurant (?restaurant=ID) from the REST API, builds the form,
 * offers a searchable restaurant picker, and sends the inquiry. Text from
 * the server is inserted as plain text. No X-WP-Nonce header is sent, so
 * every request runs as a logged-out visitor and the form nonce matches.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
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

	function getJson( url ) {
		return fetch( url, { credentials: 'omit', headers: { Accept: 'application/json' } } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	function withParam( url, key, value ) {
		return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + encodeURIComponent( key ) + '=' + encodeURIComponent( value );
	}

	function field( id, label, input, hint, required ) {
		return el( 'p', { className: 'gr-lead-form__field' }, [
			el( 'label', { for: id }, [
				document.createTextNode( label ),
				required ? el( 'span', { className: 'gr-lead-form__required', text: ' ' + __( '(required)', 'guam-restaurants-core' ) } ) : null,
			] ),
			hint ? el( 'small', { id: id + '-hint', text: hint } ) : null,
			input,
		] );
	}

	function select( id, name, choices, placeholder ) {
		var node = el( 'select', { id: id, name: name, required: true }, [ el( 'option', { value: '', text: placeholder } ) ] );
		choices.forEach( function ( choice ) {
			node.appendChild( el( 'option', { value: choice.id, text: choice.label } ) );
		} );
		return node;
	}

	function label( restaurant ) {
		return restaurant.place ? restaurant.name + ' (' + restaurant.place + ')' : restaurant.name;
	}

	// Combobox (WAI-ARIA 1.2 pattern): type to search, arrows to move, Enter to pick.
	function restaurantPicker( id, searchUrl, initial ) {
		var hidden = el( 'input', { type: 'hidden', name: 'restaurant_id', value: initial ? String( initial.id ) : '' } );
		var listId = id + '-list';
		var input = el( 'input', {
			type: 'text',
			id: id,
			role: 'combobox',
			autocomplete: 'off',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-describedby': id + '-hint',
			value: initial ? label( initial ) : '',
		} );
		var list = el( 'ul', { id: listId, role: 'listbox', className: 'gr-lead-picker__list', hidden: true } );
		var results = [];
		var active = -1;
		var timer = null;
		var request = 0;

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function choose( restaurant ) {
			hidden.value = String( restaurant.id );
			input.value = label( restaurant );
			close();
		}

		function highlight( index ) {
			var items = list.querySelectorAll( '[role="option"]' );
			active = index;
			items.forEach( function ( item, i ) {
				item.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
			if ( items[ index ] ) {
				input.setAttribute( 'aria-activedescendant', items[ index ].id );
			}
		}

		function show( rows ) {
			results = rows;
			list.textContent = '';
			if ( ! rows.length ) {
				list.appendChild( el( 'li', { className: 'gr-lead-picker__empty', text: __( 'No restaurant with that name. You can leave this empty.', 'guam-restaurants-core' ) } ) );
			}
			rows.forEach( function ( restaurant, index ) {
				var option = el( 'li', { id: listId + '-' + index, role: 'option', 'aria-selected': 'false', text: label( restaurant ) } );
				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( restaurant );
				} );
				list.appendChild( option );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			active = -1;
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			hidden.value = '';
			clearTimeout( timer );
			if ( term.length < 2 ) {
				close();
				return;
			}
			timer = setTimeout( function () {
				var mine = ++request;
				getJson( withParam( searchUrl, 'search', term ) )
					.then( function ( rows ) {
						if ( mine === request ) {
							show( Array.isArray( rows ) ? rows : [] );
						}
					} )
					.catch( close );
			}, 250 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}
			if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
				event.preventDefault();
				if ( results.length ) {
					highlight( ( active + ( event.key === 'ArrowDown' ? 1 : -1 ) + results.length ) % results.length );
				}
			} else if ( event.key === 'Enter' && active >= 0 ) {
				event.preventDefault();
				choose( results[ active ] );
			} else if ( event.key === 'Escape' ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			close();
			// Free text that was never picked is not a restaurant.
			if ( ! hidden.value ) {
				input.value = '';
			}
		} );

		return { input: el( 'div', { className: 'gr-lead-picker' }, [ input, list, hidden ] ), hidden: hidden };
	}

	function build( container, config ) {
		var started = Date.now();
		var prefix = 'gr-lead-' + ++uid;
		var status = el( 'div', { className: 'gr-lead-form__message', role: 'status', tabindex: '-1' } );

		var name = el( 'input', { type: 'text', id: prefix + '-name', name: 'name', maxlength: '100', required: true, autocomplete: 'name' } );
		var business = el( 'input', { type: 'text', id: prefix + '-business', name: 'business', maxlength: '150', required: true, autocomplete: 'organization' } );
		var picker = restaurantPicker( prefix + '-restaurant', container.getAttribute( 'data-search' ), config.restaurant );
		var email = el( 'input', { type: 'email', id: prefix + '-email', name: 'email', maxlength: '254', required: true, autocomplete: 'email' } );
		var phone = el( 'input', { type: 'tel', id: prefix + '-phone', name: 'phone', maxlength: '30', autocomplete: 'tel' } );
		var pkg = select( prefix + '-package', 'package', config.packages || [], __( 'Choose a package', 'guam-restaurants-core' ) );
		var budget = select( prefix + '-budget', 'budget', config.budgets || [], __( 'Choose a range', 'guam-restaurants-core' ) );
		var message = el( 'textarea', { id: prefix + '-message', name: 'message', rows: '6', maxlength: '3000' } );
		var trap = el( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' } );
		var button = el( 'button', { type: 'submit', className: 'wp-element-button', text: __( 'Send inquiry', 'guam-restaurants-core' ) } );

		var notes = [ document.createTextNode( __( 'We use these details only to reply to your inquiry.', 'guam-restaurants-core' ) + ' ' ) ];
		if ( config.privacy ) {
			notes.push( el( 'a', { href: config.privacy, text: __( 'Privacy policy', 'guam-restaurants-core' ) } ) );
			notes.push( document.createTextNode( '. ' ) );
		}
		if ( config.policy_url ) {
			notes.push( document.createTextNode( __( 'Advertising never affects ratings, awards, rankings or reviews:', 'guam-restaurants-core' ) + ' ' ) );
			notes.push( el( 'a', { href: config.policy_url, text: __( 'editorial policy', 'guam-restaurants-core' ) } ) );
			notes.push( document.createTextNode( '.' ) );
		}

		var form = el( 'form', { className: 'gr-lead-form__form', novalidate: true }, [
			field( prefix + '-name', __( 'Your name', 'guam-restaurants-core' ), name, '', true ),
			field( prefix + '-business', __( 'Business name', 'guam-restaurants-core' ), business, '', true ),
			field( prefix + '-restaurant', __( 'Restaurant', 'guam-restaurants-core' ), picker.input, __( 'Type at least two letters of a listed restaurant and pick it from the list. Leave empty if your business is not listed.', 'guam-restaurants-core' ), false ),
			field( prefix + '-email', __( 'Email', 'guam-restaurants-core' ), email, '', true ),
			field( prefix + '-phone', __( 'Phone', 'guam-restaurants-core' ), phone, '', false ),
			field( prefix + '-package', __( 'Package you are interested in', 'guam-restaurants-core' ), pkg, '', true ),
			field( prefix + '-budget', __( 'Monthly budget', 'guam-restaurants-core' ), budget, '', true ),
			field( prefix + '-message', __( 'Message', 'guam-restaurants-core' ), message, '', false ),
			el( 'p', { className: 'gr-lead-form__trap', 'aria-hidden': 'true' }, [ el( 'label', { text: 'Website' }, [ trap ] ) ] ),
			el( 'p', { className: 'gr-lead-form__note' }, notes ),
			el( 'p', {}, [ button ] ),
			status,
		] );

		function say( text, errors, kind ) {
			status.textContent = '';
			status.className = 'gr-lead-form__message' + ( kind ? ' is-' + kind : '' );
			status.appendChild( el( 'p', { text: text } ) );
			if ( errors && errors.length ) {
				var list = el( 'ul' );
				errors.forEach( function ( error ) {
					list.appendChild( el( 'li', { text: error } ) );
				} );
				status.appendChild( list );
			}
			status.focus();
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}
			button.disabled = true;
			fetch( container.getAttribute( 'data-submit' ), {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify( {
					nonce: config.nonce,
					name: name.value,
					business: business.value,
					restaurant_id: parseInt( picker.hidden.value, 10 ) || 0,
					email: email.value,
					phone: phone.value,
					package: pkg.value,
					budget: budget.value,
					message: message.value,
					website: trap.value,
					elapsed_ms: Date.now() - started,
				} ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok ) {
						form.replaceWith( status );
						say( result.body.message, null, 'success' );
						return;
					}
					var errors = [];
					( ( result.body && result.body.additional_errors ) || [] ).forEach( function ( extra ) {
						errors.push( extra.message );
					} );
					say( ( result.body && result.body.message ) || __( 'Your inquiry could not be sent. Please try again.', 'guam-restaurants-core' ), errors, 'error' );
					button.disabled = false;
				} )
				.catch( function () {
					say( __( 'Your inquiry could not be sent. Please check your connection and try again.', 'guam-restaurants-core' ), null, 'error' );
					button.disabled = false;
				} );
		} );

		container.textContent = '';
		container.appendChild( form );
	}

	function init( container ) {
		var url = container.getAttribute( 'data-form' );
		var restaurant = parseInt( new URLSearchParams( window.location.search ).get( 'restaurant' ), 10 );
		if ( restaurant > 0 ) {
			url = withParam( url, 'restaurant', restaurant );
		}
		getJson( url )
			.then( function ( config ) {
				build( container, config );
			} )
			.catch( function () {
				container.textContent = '';
				container.appendChild( el( 'p', { text: __( 'The form could not be loaded. Please reload the page.', 'guam-restaurants-core' ) } ) );
			} );
	}

	function start() {
		document.querySelectorAll( '[data-gr-lead-form]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
