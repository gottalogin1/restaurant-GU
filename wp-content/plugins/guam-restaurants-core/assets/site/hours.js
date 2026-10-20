/**
 * "Open now" labels and today's row in the hours table, worked out in the
 * browser in Guam time so cached pages are never out of date.
 *
 * Elements carry data-gr-hours="start-end,start-end" in minutes from
 * Monday 00:00 (Site\Open_Hours::compact()).
 */
( function () {
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : function ( text, value ) {
		return text.replace( '%s', value );
	};
	var DAYS = [ 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ];
	var WEEK = 10080;

	function guamNow() {
		var parts = {};
		new Intl.DateTimeFormat( 'en-US', {
			timeZone: 'Pacific/Guam',
			weekday: 'short',
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23',
		} )
			.formatToParts( new Date() )
			.forEach( function ( part ) {
				parts[ part.type ] = part.value;
			} );
		var day = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ].indexOf( parts.weekday );
		return { day: day, minute: day * 1440 + parseInt( parts.hour, 10 ) * 60 + parseInt( parts.minute, 10 ) };
	}

	function intervals( value ) {
		return String( value || '' )
			.split( ',' )
			.filter( Boolean )
			.map( function ( pair ) {
				var bits = pair.split( '-' );
				return [ parseInt( bits[ 0 ], 10 ), parseInt( bits[ 1 ], 10 ) ];
			} );
	}

	function timeLabel( minuteOfWeek ) {
		var minute = minuteOfWeek % 1440;
		return new Date( Date.UTC( 2024, 0, 1, Math.floor( minute / 60 ), minute % 60 ) ).toLocaleTimeString( [], {
			hour: 'numeric',
			minute: '2-digit',
			timeZone: 'UTC',
		} );
	}

	function status( list, now ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ][ 0 ] <= now && now < list[ i ][ 1 ] ) {
				var end = list[ i ][ 1 ];
				// An interval that runs past Sunday midnight continues at Monday 00:00.
				if ( end === WEEK && list[ 0 ][ 0 ] === 0 && list[ 0 ][ 1 ] < WEEK ) {
					end = list[ 0 ][ 1 ];
				}
				if ( list[ i ][ 0 ] === 0 && list[ i ][ 1 ] === WEEK ) {
					return { open: true, text: __( 'Open 24 hours', 'guam-restaurants-core' ) };
				}
				/* translators: %s: closing time. */
				return { open: true, text: sprintf( __( 'Open now, until %s', 'guam-restaurants-core' ), timeLabel( end ) ) };
			}
		}
		return { open: false, text: __( 'Closed now', 'guam-restaurants-core' ) };
	}

	function apply( root ) {
		var now = guamNow();
		( root || document ).querySelectorAll( '[data-gr-hours]' ).forEach( function ( node ) {
			var list = intervals( node.getAttribute( 'data-gr-hours' ) );
			if ( ! list.length ) {
				return;
			}
			var result = status( list, now.minute );
			var target = node.classList.contains( 'gr-card__open' ) ? node : node.querySelector( '.gr-hours__now' );
			if ( target ) {
				target.textContent = result.text;
				target.classList.toggle( 'is-open', result.open );
				target.classList.toggle( 'is-closed', ! result.open );
			}
			var today = node.querySelector( '[data-gr-day="' + DAYS[ now.day ] + '"]' );
			if ( today ) {
				today.classList.add( 'is-today' );
				var th = today.querySelector( 'th' );
				if ( th && ! th.querySelector( '.gr-hours__today' ) ) {
					var tag = document.createElement( 'span' );
					tag.className = 'gr-hours__today';
					tag.textContent = ' ' + __( '(today)', 'guam-restaurants-core' );
					th.appendChild( tag );
				}
			}
		} );
	}

	// "Are these hours right?": Yes only thanks the visitor (nothing is sent);
	// No is a plain link to the correction form.
	function initChecks() {
		document.querySelectorAll( '[data-gr-hours-ask]' ).forEach( function ( ask ) {
			var yes = ask.querySelector( '[data-gr-hours-yes]' );
			var thanks = ask.nextElementSibling;
			if ( ! yes || ! thanks ) {
				return;
			}
			yes.hidden = false;
			yes.addEventListener( 'click', function () {
				ask.hidden = true;
				thanks.hidden = false;
			} );
		} );
	}

	window.guamRestaurantsHours = { apply: apply };
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			apply();
			initChecks();
		} );
	} else {
		apply();
		initChecks();
	}
} )();
