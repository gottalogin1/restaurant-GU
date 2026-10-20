/**
 * Light / dark / system switches: the header block's buttons
 * ([data-gr-scheme]) and the admin bar's Theme menu (links to
 * #gr-scheme-<choice>). head.js has already applied the saved choice.
 */
( function () {
	var scheme = window.guamColorScheme;
	if ( ! scheme ) {
		return;
	}
	var prefix = '#gr-scheme-';

	function picked( el ) {
		var value = el.getAttribute( 'data-gr-scheme' );
		if ( ! value && el.hash && 0 === el.hash.indexOf( prefix ) ) {
			value = el.hash.slice( prefix.length );
		}
		return 'light' === value || 'dark' === value || 'system' === value ? value : '';
	}

	function sync() {
		var current = scheme.choice();
		document.querySelectorAll( '[data-gr-scheme]' ).forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', picked( button ) === current ? 'true' : 'false' );
		} );
		document.querySelectorAll( '.gr-scheme' ).forEach( function ( group ) {
			group.hidden = false;
		} );
		document.querySelectorAll( 'a[href^="' + prefix + '"]' ).forEach( function ( link ) {
			var on = picked( link ) === current;
			link.parentNode.classList.toggle( 'is-current', on );
			if ( on ) {
				document.querySelectorAll( '.gr-scheme-current' ).forEach( function ( label ) {
					label.textContent = link.textContent;
				} );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-gr-scheme], a[href^="' + prefix + '"]' );
		var value = el ? picked( el ) : '';
		if ( ! value ) {
			return;
		}
		event.preventDefault();
		scheme.save( value );
		sync();
	} );

	// Follow the device while the choice is "system", and other tabs' choices.
	if ( window.matchMedia ) {
		var query = window.matchMedia( '(prefers-color-scheme: dark)' );
		var follow = function () {
			scheme.apply( scheme.choice() );
		};
		if ( query.addEventListener ) {
			query.addEventListener( 'change', follow );
		} else if ( query.addListener ) {
			query.addListener( follow );
		}
	}
	window.addEventListener( 'storage', function ( event ) {
		if ( scheme.key === event.key ) {
			scheme.apply( scheme.choice() );
			sync();
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync );
	} else {
		sync();
	}
} )();
