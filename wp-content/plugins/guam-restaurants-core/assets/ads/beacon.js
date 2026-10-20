/**
 * Counts ad impressions and clicks with navigator.sendBeacon.
 *
 * An impression is sent once per ad shown, when at least half of the ad
 * is on screen; a click when the ad's link is followed. Ads added to the
 * page later are counted once the adding script calls
 * window.guamRestaurantsAds.watch( element ). Nothing
 * about the visitor is sent: only the ad ID, the event type and the page
 * type printed in the slot. Automated browsers (navigator.webdriver) send
 * nothing.
 */
( function () {
	'use strict';

	var settings = window.guamRestaurantsAds || {};
	if ( ! settings.endpoint || navigator.webdriver ) {
		return;
	}

	var queue = {};
	var timer = 0;

	function send( page, events ) {
		var body = JSON.stringify( { page: page, events: events } );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( settings.endpoint, new Blob( [ body ], { type: 'application/json' } ) );
			return;
		}
		if ( window.fetch ) {
			window.fetch( settings.endpoint, {
				method: 'POST',
				body: body,
				headers: { 'Content-Type': 'application/json' },
				keepalive: true,
				credentials: 'omit',
			} );
		}
	}

	function flush() {
		timer = 0;
		Object.keys( queue ).forEach( function ( page ) {
			var events = queue[ page ];
			while ( events.length ) {
				send( page, events.splice( 0, 12 ) );
			}
		} );
		queue = {};
	}

	function track( el, type, now ) {
		var page = el.getAttribute( 'data-gr-ad-page' ) || 'other';
		var ad = parseInt( el.getAttribute( 'data-gr-ad' ), 10 );
		if ( ! ad ) {
			return;
		}
		( queue[ page ] = queue[ page ] || [] ).push( { ad: ad, type: type } );
		if ( now ) {
			flush();
		} else if ( ! timer ) {
			timer = window.setTimeout( flush, 1000 );
		}
	}

	var observer =
		'IntersectionObserver' in window
			? new IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								observer.unobserve( entry.target );
								track( entry.target, 'impression', false );
							}
						} );
					},
					{ threshold: 0.5 }
			  )
			: null;

	// Watches the ads inside root; each ad element is watched once.
	function watch( root ) {
		( root || document ).querySelectorAll( '[data-gr-ad]' ).forEach( function ( el ) {
			if ( el.hasAttribute( 'data-gr-ad-watched' ) ) {
				return;
			}
			el.setAttribute( 'data-gr-ad-watched', '' );
			if ( observer ) {
				observer.observe( el );
			} else {
				track( el, 'impression', false );
			}
			el.addEventListener( 'click', function ( event ) {
				if ( event.target.closest && event.target.closest( 'a[href]' ) ) {
					track( el, 'click', true );
				}
			} );
		} );
	}

	// Scripts that add ads later (the random place picker) call this.
	settings.watch = watch;
	watch( document );

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		}
	} );
} )();
