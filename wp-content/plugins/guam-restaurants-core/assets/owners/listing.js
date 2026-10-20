/**
 * Counts a restaurant page view and clicks on its website and phone links
 * for the owner's dashboard, with navigator.sendBeacon. Only the restaurant
 * ID and the event names are sent; nothing about the visitor. Automated
 * browsers (navigator.webdriver) send nothing.
 */
( function () {
	'use strict';

	var settings = window.guamRestaurantsListing || {};
	var root = document.querySelector( '[data-gr-listing]' );
	if ( ! settings.endpoint || ! root || navigator.webdriver ) {
		return;
	}
	var restaurant = parseInt( root.getAttribute( 'data-gr-listing' ), 10 );
	if ( ! restaurant ) {
		return;
	}

	function send( events ) {
		var body = JSON.stringify( { restaurant: restaurant, events: events } );
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
	}

	send( [ 'view' ] );

	// The page top and the details section both carry phone and website links.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '[data-gr-listing] [data-gr-listing-click]' );
		if ( link ) {
			send( [ link.getAttribute( 'data-gr-listing-click' ) ] );
		}
	} );
} )();
