/**
 * Rotating sponsored slots (Ads\Slot): the ads in a slot with
 * data-gr-rotate take turns in one spot, round-robin.
 *
 * The ad shown first comes from the clock (which turn of ROTATE_MS we are
 * in), so every visitor at one moment sees the same sponsor, every
 * sponsor gets the same share of time, and pages from a full page cache
 * still rotate. Nothing is stored in the browser.
 *
 * Pause and Next buttons are added (WCAG 2.2.2). Rotation waits while the
 * pointer or keyboard focus is in the slot or the tab is hidden, and does
 * not start on its own when the visitor prefers reduced motion.
 */
( function () {
	'use strict';

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : function ( text ) {
		return text;
	};
	var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function setUp( slot ) {
		var ads = Array.prototype.slice.call( slot.querySelectorAll( '.gr-ads__list > .gr-ad' ) );
		var every = parseInt( slot.getAttribute( 'data-gr-rotate' ), 10 ) || 8000;
		if ( ads.length < 2 ) {
			return;
		}

		var current = Math.floor( Date.now() / every ) % ads.length;
		var paused = reduced;
		var hovering = false;
		var timer = 0;

		var position = document.createElement( 'span' );
		position.className = 'gr-ads__position';

		var pause = document.createElement( 'button' );
		pause.type = 'button';
		pause.className = 'gr-ads__button';

		var next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'gr-ads__button';
		next.textContent = __( 'Next sponsor', 'guam-restaurants-core' );

		var controls = document.createElement( 'div' );
		controls.className = 'gr-ads__controls';
		controls.appendChild( position );
		controls.appendChild( pause );
		controls.appendChild( next );
		slot.appendChild( controls );

		function show( index, announce ) {
			current = ( index + ads.length ) % ads.length;
			ads.forEach( function ( ad, i ) {
				ad.hidden = i !== current;
			} );
			position.textContent = sprintf( __( 'Sponsor %1$d of %2$d', 'guam-restaurants-core' ), current + 1, ads.length );
			// Announce only changes the visitor asked for.
			slot.setAttribute( 'aria-live', announce ? 'polite' : 'off' );
			if ( window.guamRestaurantsAds && window.guamRestaurantsAds.watch ) {
				window.guamRestaurantsAds.watch( slot );
			}
		}

		function label() {
			pause.textContent = paused ? __( 'Play', 'guam-restaurants-core' ) : __( 'Pause', 'guam-restaurants-core' );
			pause.setAttribute( 'aria-pressed', paused ? 'true' : 'false' );
		}

		function schedule() {
			window.clearTimeout( timer );
			if ( paused ) {
				return;
			}
			// Line up with the clock so turns change together on every page.
			timer = window.setTimeout( tick, every - ( Date.now() % every ) );
		}

		function tick() {
			if ( ! hovering && 'hidden' !== document.visibilityState ) {
				show( current + 1, false );
			}
			schedule();
		}

		pause.addEventListener( 'click', function () {
			paused = ! paused;
			label();
			schedule();
		} );
		next.addEventListener( 'click', function () {
			show( current + 1, true );
			schedule();
		} );
		slot.addEventListener( 'mouseenter', function () {
			hovering = true;
		} );
		slot.addEventListener( 'mouseleave', function () {
			hovering = false;
		} );
		slot.addEventListener( 'focusin', function () {
			hovering = true;
		} );
		slot.addEventListener( 'focusout', function ( event ) {
			hovering = slot.contains( event.relatedTarget );
		} );

		label();
		show( current, false );
		schedule();
	}

	function init() {
		document.querySelectorAll( '[data-gr-rotate]' ).forEach( setUp );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
