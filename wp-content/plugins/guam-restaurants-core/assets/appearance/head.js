/**
 * Light or dark, decided before the page is drawn (printed inline in <head>).
 *
 * The choice is "light", "dark" or "system" (the default). "system" follows
 * the device's setting; when the browser cannot report one it falls back to
 * dark, as the GU Headlines site does. The choice is kept in localStorage,
 * so the public site and wp-admin share it.
 *
 * Sets <html data-theme="light|dark" data-theme-choice="light|dark|system">
 * and exposes window.guamColorScheme for color-scheme.js.
 */
( function () {
	var root = document.documentElement;
	var key = 'gr-color-scheme';
	var fallback = 'dark';

	function device() {
		if ( ! window.matchMedia ) {
			return null;
		}
		if ( window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) {
			return 'dark';
		}
		if ( window.matchMedia( '(prefers-color-scheme: light)' ).matches ) {
			return 'light';
		}
		return null;
	}

	function choice() {
		try {
			var saved = window.localStorage.getItem( key );
			return 'light' === saved || 'dark' === saved ? saved : 'system';
		} catch ( e ) {
			return 'system';
		}
	}

	function apply( value ) {
		root.setAttribute( 'data-theme-choice', value );
		root.setAttribute( 'data-theme', 'system' === value ? device() || fallback : value );
	}

	function save( value ) {
		try {
			if ( 'system' === value ) {
				window.localStorage.removeItem( key );
			} else {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {}
		apply( value );
	}

	window.guamColorScheme = { key: key, choice: choice, apply: apply, save: save };
	apply( choice() );
} )();
