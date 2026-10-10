/**
 * Counts one view of a restaurant page per browser tab session with
 * navigator.sendBeacon. Only the restaurant ID is sent; nothing about the
 * visitor. Automated browsers (navigator.webdriver) send nothing.
 */
( function () {
	'use strict';

	var settings = window.guamRestaurantsViews || {};
	if ( ! settings.endpoint || navigator.webdriver ) {
		return;
	}

	var id = parseInt( settings.restaurant, 10 ) || 0;
	if ( ! id ) {
		return;
	}

	var key = 'gr-viewed-' + id;
	try {
		if ( window.sessionStorage.getItem( key ) ) {
			return;
		}
		window.sessionStorage.setItem( key, '1' );
	} catch ( e ) {
		// Storage blocked: count the view anyway.
	}

	var body = JSON.stringify( { restaurant: id } );
	if ( navigator.sendBeacon ) {
		navigator.sendBeacon( settings.endpoint, new Blob( [ body ], { type: 'application/json' } ) );
	} else if ( window.fetch ) {
		window.fetch( settings.endpoint, {
			method: 'POST',
			body: body,
			headers: { 'Content-Type': 'application/json' },
			keepalive: true,
			credentials: 'omit',
		} );
	}
} )();
