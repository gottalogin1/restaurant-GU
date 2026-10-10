/**
 * Correction form: loads a fresh nonce, the choices, our response times and
 * any pre-filled restaurant (?restaurant=ID) from the REST API, builds the
 * form, and sends the request. Owners can also ask to change or remove a
 * listing. Text from the server is inserted as plain text. No X-WP-Nonce
 * header is sent, so every request runs as a logged-out visitor and the
 * form nonce matches.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n ? i18n.sprintf : function ( text, value ) {
		return text.replace( '%d', value );
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

	function field( id, labelText, input, hint, required ) {
		var labelNode = el( 'label', { for: id }, [
			document.createTextNode( labelText ),
			required ? el( 'span', { className: 'gr-lead-form__required', text: ' ' + __( '(required)', 'guam-restaurants-core' ) } ) : null,
		] );
		return {
			label: labelNode,
			node: el( 'p', { className: 'gr-lead-form__field' }, [ labelNode, hint ? el( 'small', { id: id + '-hint', text: hint } ) : null, input ] ),
		};
	}

	function fillSelect( node, choices, placeholder ) {
		node.textContent = '';
		node.appendChild( el( 'option', { value: '', text: placeholder } ) );
		choices.forEach( function ( choice ) {
			node.appendChild( el( 'option', { value: choice.id, text: choice.label } ) );
		} );
	}

	function placeLabel( restaurant ) {
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
			required: true,
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-describedby': id + '-hint',
			value: initial ? placeLabel( initial ) : '',
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
			input.value = placeLabel( restaurant );
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
				list.appendChild( el( 'li', { className: 'gr-lead-picker__empty', text: __( 'No restaurant with that name.', 'guam-restaurants-core' ) } ) );
			}
			rows.forEach( function ( restaurant, index ) {
				var option = el( 'li', { id: listId + '-' + index, role: 'option', 'aria-selected': 'false', text: placeLabel( restaurant ) } );
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
			if ( ! hidden.value ) {
				input.value = '';
			}
		} );

		return { input: el( 'div', { className: 'gr-lead-picker' }, [ input, list, hidden ] ), hidden: hidden };
	}

	function build( container, config ) {
		var started = Date.now();
		var prefix = 'gr-correction-' + ++uid;
		var status = el( 'div', { className: 'gr-lead-form__message', role: 'status', tabindex: '-1' } );
		var days = config.response_days || {};

		var picker = restaurantPicker( prefix + '-restaurant', container.getAttribute( 'data-search' ), config.restaurant );

		var requestBox = el( 'fieldset', { className: 'gr-lead-form__field gr-correction-form__requests' }, [
			el( 'legend', { text: __( 'What would you like to do?', 'guam-restaurants-core' ) } ),
		] );
		( config.requests || [] ).forEach( function ( choice, index ) {
			var id = prefix + '-request-' + index;
			requestBox.appendChild(
				el( 'p', {}, [
					el( 'input', { type: 'radio', id: id, name: 'request', value: choice.id, checked: index === 0, required: true } ),
					el( 'label', { for: id, text: ' ' + choice.label } ),
				] )
			);
		} );
		var promise = el( 'p', { className: 'gr-lead-form__note', id: prefix + '-promise' } );

		var topic = el( 'select', { id: prefix + '-topic', name: 'topic', required: true } );
		var topicField = field( prefix + '-topic', '', topic, '', true );
		var details = el( 'textarea', { id: prefix + '-details', name: 'details', rows: '6', minlength: '10', maxlength: '2000', required: true } );
		var detailsField = field( prefix + '-details', '', details, '', true );
		var evidence = el( 'input', { type: 'text', id: prefix + '-evidence', name: 'evidence', maxlength: '500', 'aria-describedby': prefix + '-evidence-hint' } );
		var relationship = el( 'select', { id: prefix + '-relationship', name: 'relationship', required: true } );
		fillSelect( relationship, config.relationships || [], __( 'Choose one', 'guam-restaurants-core' ) );
		var name = el( 'input', { type: 'text', id: prefix + '-name', name: 'name', maxlength: '100', autocomplete: 'name' } );
		var nameField = field( prefix + '-name', __( 'Your name', 'guam-restaurants-core' ), name, '', false );
		var email = el( 'input', { type: 'email', id: prefix + '-email', name: 'email', maxlength: '254', required: true, autocomplete: 'email' } );
		var trap = el( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' } );
		var button = el( 'button', { type: 'submit', className: 'wp-element-button', text: __( 'Send request', 'guam-restaurants-core' ) } );

		var notes = [ document.createTextNode( __( 'We use your email address only to reply to this request. Nothing you send here is published.', 'guam-restaurants-core' ) + ' ' ) ];
		if ( config.privacy ) {
			notes.push( el( 'a', { href: config.privacy, text: __( 'Privacy policy', 'guam-restaurants-core' ) } ) );
			notes.push( document.createTextNode( '. ' ) );
		}
		if ( config.policy_url ) {
			notes.push( el( 'a', { href: config.policy_url, text: __( 'How we handle corrections', 'guam-restaurants-core' ) } ) );
			notes.push( document.createTextNode( '.' ) );
		}

		function currentRequest() {
			var checked = requestBox.querySelector( 'input[name="request"]:checked' );
			return checked ? checked.value : 'correction';
		}

		function setLabel( fieldParts, text, required ) {
			fieldParts.label.textContent = text;
			if ( required ) {
				fieldParts.label.appendChild( el( 'span', { className: 'gr-lead-form__required', text: ' ' + __( '(required)', 'guam-restaurants-core' ) } ) );
			}
		}

		function update() {
			var request = currentRequest();
			var owner = request !== 'correction';
			if ( request === 'removal' ) {
				setLabel( topicField, __( 'Reason for removal', 'guam-restaurants-core' ), true );
				fillSelect( topic, config.reasons || [], __( 'Choose a reason', 'guam-restaurants-core' ) );
				setLabel( detailsField, __( 'Tell us more', 'guam-restaurants-core' ), true );
			} else {
				setLabel( topicField, __( 'What needs changing?', 'guam-restaurants-core' ), true );
				fillSelect( topic, config.topics || [], __( 'Choose one', 'guam-restaurants-core' ) );
				setLabel( detailsField, owner ? __( 'What should the listing say?', 'guam-restaurants-core' ) : __( 'What is wrong, and what is correct?', 'guam-restaurants-core' ), true );
			}
			setLabel( nameField, __( 'Your name', 'guam-restaurants-core' ), owner );
			name.required = owner;
			promise.textContent = sprintf(
				/* translators: %d: number of days. */
				__( 'We reply within %d days.', 'guam-restaurants-core' ),
				days[ request ] || 14
			);
			if ( request === 'removal' ) {
				promise.textContent +=
					' ' + __( 'A closed restaurant is marked closed rather than removed. Removing a listing never adds any note to the site.', 'guam-restaurants-core' );
			}
		}
		requestBox.addEventListener( 'change', update );

		var form = el( 'form', { className: 'gr-lead-form__form', novalidate: true }, [
			field( prefix + '-restaurant', __( 'Restaurant', 'guam-restaurants-core' ), picker.input, __( 'Type at least two letters of the restaurant name and pick it from the list.', 'guam-restaurants-core' ), true ).node,
			requestBox,
			promise,
			topicField.node,
			detailsField.node,
			field( prefix + '-evidence', __( 'Where can we confirm this?', 'guam-restaurants-core' ), evidence, __( 'For example, a link to the restaurant\'s website or social media page, or "sign on the door".', 'guam-restaurants-core' ), false ).node,
			field( prefix + '-relationship', __( 'Your connection to the restaurant', 'guam-restaurants-core' ), relationship, '', true ).node,
			nameField.node,
			field( prefix + '-email', __( 'Email', 'guam-restaurants-core' ), email, '', true ).node,
			el( 'p', { className: 'gr-lead-form__trap', 'aria-hidden': 'true' }, [ el( 'label', { text: 'Website' }, [ trap ] ) ] ),
			el( 'p', { className: 'gr-lead-form__note' }, notes ),
			el( 'p', {}, [ button ] ),
			status,
		] );
		update();

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
			if ( ! picker.hidden.value ) {
				say( __( 'Choose the restaurant from the list.', 'guam-restaurants-core' ), null, 'error' );
				return;
			}
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
					restaurant_id: parseInt( picker.hidden.value, 10 ) || 0,
					request: currentRequest(),
					topic: topic.value,
					details: details.value,
					evidence: evidence.value,
					relationship: relationship.value,
					name: name.value,
					email: email.value,
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
					say( ( result.body && result.body.message ) || __( 'Your request could not be sent. Please try again.', 'guam-restaurants-core' ), errors, 'error' );
					button.disabled = false;
				} )
				.catch( function () {
					say( __( 'Your request could not be sent. Please check your connection and try again.', 'guam-restaurants-core' ), null, 'error' );
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
		document.querySelectorAll( '[data-gr-correction-form]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
