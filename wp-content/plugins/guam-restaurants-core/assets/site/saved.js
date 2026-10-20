/**
 * Hearts and saved lists (Saved\View).
 *
 * Without an account the list is kept in this browser (localStorage) and
 * never sent anywhere except to draw the Saved page. Signed in, the list
 * lives on the account (REST /saved), and anything saved in this browser
 * before signing in is added to it once, then cleared from the browser.
 *
 * Saving never needs an account, but the first and fifth save open a
 * prompt to sign in so the list follows the visitor to other devices.
 */
( function () {
	var settings = window.guamRestaurantsSaved || {};
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n ? i18n.sprintf : function ( text, value ) {
		return text.replace( '%d', value );
	};
	var _n = i18n ? i18n._n : function ( one, many, count ) {
		return count === 1 ? one : many;
	};
	var KEY = 'gr-saved';
	var PROMPTED = 'gr-saved-prompted';
	var ids = [];
	var ready = false;

	function readLocal( key ) {
		try {
			var value = JSON.parse( window.localStorage.getItem( key ) || '[]' );
			return Array.isArray( value ) ? value : [];
		} catch ( error ) {
			return [];
		}
	}

	function writeLocal( key, value ) {
		try {
			if ( value.length ) {
				window.localStorage.setItem( key, JSON.stringify( value ) );
			} else {
				window.localStorage.removeItem( key );
			}
			return true;
		} catch ( error ) {
			return false;
		}
	}

	function clean( list ) {
		var out = [];
		list.forEach( function ( id ) {
			id = parseInt( id, 10 );
			if ( id > 0 && out.indexOf( id ) === -1 ) {
				out.push( id );
			}
		} );
		return out.slice( -( settings.max || 500 ) );
	}

	function request( method, body ) {
		return fetch( settings.endpoint, {
			method: method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( response.status );
			}
			return response.json();
		} );
	}

	var live = null;
	function say( text ) {
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.className = 'gr-saved-toast';
			live.setAttribute( 'role', 'status' );
			document.body.appendChild( live );
		}
		live.textContent = text;
		live.classList.add( 'is-shown' );
		window.clearTimeout( say.timer );
		say.timer = window.setTimeout( function () {
			live.classList.remove( 'is-shown' );
		}, 3500 );
	}

	// Only real changes, so the MutationObserver below does not loop.
	function setText( node, text ) {
		if ( node.textContent !== text ) {
			node.textContent = text;
		}
	}

	function sync() {
		document.querySelectorAll( '[data-gr-save]' ).forEach( function ( button ) {
			var on = ids.indexOf( parseInt( button.getAttribute( 'data-gr-save' ), 10 ) ) !== -1;
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			if ( button.hasAttribute( 'data-gr-label-on' ) ) {
				button.setAttribute( 'aria-label', button.getAttribute( on ? 'data-gr-label-on' : 'data-gr-label-off' ) );
			}
			var text = button.querySelector( '.gr-save__text' );
			if ( text ) {
				setText( text, button.getAttribute( on ? 'data-gr-text-on' : 'data-gr-text-off' ) );
			}
			button.hidden = false;
		} );
		document.querySelectorAll( '[data-gr-saved-count]' ).forEach( function ( count ) {
			setText( count, ids.length ? String( ids.length ) : '' );
			count.hidden = ! ids.length;
		} );
		document.querySelectorAll( '[data-gr-saved-share]' ).forEach( function ( button ) {
			var can = navigator.share || ( navigator.clipboard && window.isSecureContext );
			button.hidden = ! can || ! ids.length;
			var note = document.querySelector( '[data-gr-saved-share-note]' );
			if ( note ) {
				note.hidden = button.hidden;
			}
		} );
	}

	function prompted() {
		return readLocal( PROMPTED );
	}

	function maybePrompt() {
		if ( settings.signedIn ) {
			return;
		}
		var at = ( settings.promptAt || [] ).filter( function ( count ) {
			return ids.length >= count && prompted().indexOf( count ) === -1;
		} );
		if ( at.length ) {
			writeLocal( PROMPTED, prompted().concat( at ) );
			openJoin();
		}
	}

	function openJoin() {
		var dialog = document.querySelector( '[data-gr-join-dialog]' );
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return false;
		}
		if ( ! dialog.open ) {
			dialog.showModal();
		}
		return true;
	}

	function change( add, remove ) {
		var before = ids.slice();
		ids = clean( ids.filter( function ( id ) {
			return remove.indexOf( id ) === -1;
		} ).concat( add ) );
		sync();
		document.dispatchEvent( new CustomEvent( 'gr:saved-change', { detail: { ids: ids.slice() } } ) );
		if ( settings.signedIn ) {
			return request( 'POST', { add: add, remove: remove } )
				.then( function ( data ) {
					ids = clean( data.ids || [] );
					sync();
				} )
				.catch( function () {
					ids = before;
					sync();
					say( __( 'That did not save. Check your connection and try again.', 'guam-restaurants-core' ) );
					throw new Error( 'save failed' );
				} );
		}
		if ( ! writeLocal( KEY, ids ) ) {
			ids = before;
			sync();
			say( __( 'This browser is not letting us save. Sign in to keep a list on your account.', 'guam-restaurants-core' ) );
			openJoin();
			return Promise.reject( new Error( 'storage blocked' ) );
		}
		return Promise.resolve();
	}

	function toggle( button ) {
		var id = parseInt( button.getAttribute( 'data-gr-save' ), 10 );
		if ( ! id ) {
			return;
		}
		if ( ids.indexOf( id ) !== -1 ) {
			change( [], [ id ] ).then( function () {
				say( __( 'Removed from your saved list.', 'guam-restaurants-core' ) );
			}, function () {} );
			return;
		}
		change( [ id ], [] ).then( function () {
			say( __( 'Saved to your list.', 'guam-restaurants-core' ) );
			maybePrompt();
		}, function () {} );
	}

	function saveAll( button ) {
		var add = clean( button.getAttribute( 'data-gr-save-all' ).split( ',' ) );
		change( add, [] ).then( function () {
			button.textContent = button.getAttribute( 'data-gr-done' );
			button.disabled = true;
			/* translators: %d: number of restaurants. */
			say( sprintf( _n( '%d restaurant added to your list.', '%d restaurants added to your list.', add.length, 'guam-restaurants-core' ), add.length ) );
			maybePrompt();
		}, function () {} );
	}

	function shareList( button ) {
		var url = new URL( settings.page, window.location.href );
		url.searchParams.set( settings.listParam, ids.slice( -( settings.shareMax || 100 ) ).join( ',' ) );
		var link = url.toString().replace( /%2C/g, ',' );
		if ( navigator.share ) {
			navigator.share( { title: button.getAttribute( 'data-gr-share-title' ), url: link } ).catch( function () {} );
			return;
		}
		navigator.clipboard.writeText( link ).then( function () {
			var label = button.textContent;
			button.textContent = button.getAttribute( 'data-gr-copied' );
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		} );
	}

	// The Saved page list for a list kept in the browser, or after a merge.
	function drawList() {
		var results = document.querySelector( '[data-gr-saved-mine]' );
		if ( ! results ) {
			return Promise.resolve();
		}
		if ( ! ids.length ) {
			results.removeAttribute( 'data-gr-saved-local' );
		}
		var url = new URL( settings.cards, window.location.href );
		url.searchParams.set( 'ids', ids.join( ',' ) );
		results.setAttribute( 'aria-busy', 'true' );
		return fetch( url.toString(), { headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				results.innerHTML = data.html;
				results.removeAttribute( 'aria-busy' );
				sync();
				document.dispatchEvent( new CustomEvent( 'gr:directory-results' ) );
			} )
			.catch( function () {
				results.removeAttribute( 'aria-busy' );
				results.textContent = __( 'Your saved restaurants could not load right now. Try again in a moment.', 'guam-restaurants-core' );
			} );
	}

	function load() {
		var local = clean( readLocal( KEY ) );
		if ( ! settings.signedIn ) {
			ids = local;
			return Promise.resolve( false );
		}
		var call = local.length ? request( 'POST', { add: local } ) : request( 'GET' );
		return call.then( function ( data ) {
			ids = clean( data.ids || [] );
			if ( local.length ) {
				// Moved to the account; a shared computer should not keep them.
				writeLocal( KEY, [] );
				writeLocal( PROMPTED, [] );
				/* translators: %d: number of restaurants. */
				say( sprintf( _n( 'We added %d place you saved in this browser to your account.', 'We added the %d places you saved in this browser to your account.', local.length, 'guam-restaurants-core' ), local.length ) );
			}
			return local.length > 0;
		} );
	}

	function init() {
		var params = new URLSearchParams( window.location.search );
		if ( params.get( 'account' ) === 'deleted' ) {
			writeLocal( KEY, [] );
			say( __( 'Your account and saved list were deleted.', 'guam-restaurants-core' ) );
		}

		document.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( '[data-gr-save], [data-gr-save-all], [data-gr-saved-share], [data-gr-join-open], [data-gr-join-close]' );
			if ( ! target ) {
				return;
			}
			if ( target.hasAttribute( 'data-gr-join-open' ) ) {
				if ( openJoin() ) {
					event.preventDefault();
				}
				return;
			}
			if ( target.hasAttribute( 'data-gr-join-close' ) ) {
				target.closest( 'dialog' ).close();
				return;
			}
			if ( ! ready ) {
				return;
			}
			event.preventDefault();
			if ( target.hasAttribute( 'data-gr-save-all' ) ) {
				saveAll( target );
			} else if ( target.hasAttribute( 'data-gr-saved-share' ) ) {
				shareList( target );
			} else {
				toggle( target );
			}
		} );

		// Closes the prompt when the backdrop is clicked.
		var dialog = document.querySelector( '[data-gr-join-dialog]' );
		if ( dialog ) {
			dialog.addEventListener( 'click', function ( event ) {
				if ( event.target === dialog ) {
					dialog.close();
				}
			} );
		}

		// Cards added later (filtered results, picks) get their hearts too.
		if ( 'MutationObserver' in window ) {
			var queued = false;
			new MutationObserver( function () {
				if ( ready && ! queued ) {
					queued = true;
					window.requestAnimationFrame( function () {
						queued = false;
						sync();
					} );
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		}

		load()
			.then( function ( changed ) {
				ready = true;
				sync();
				var results = document.querySelector( '[data-gr-saved-mine]' );
				if ( results && ( results.hasAttribute( 'data-gr-saved-local' ) || changed ) ) {
					return drawList();
				}
			} )
			.catch( function () {
				ready = true;
				sync();
				say( __( 'Your saved list could not load right now.', 'guam-restaurants-core' ) );
			} );
		document.querySelectorAll( '[data-gr-save-all]' ).forEach( function ( button ) {
			button.hidden = false;
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
