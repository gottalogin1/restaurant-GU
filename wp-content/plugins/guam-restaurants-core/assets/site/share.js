/**
 * Share button on restaurant pages: the device's share sheet where there is
 * one, else the link is copied. The button stays hidden when neither works.
 */
( function () {
	function init() {
		document.querySelectorAll( '[data-gr-share]' ).forEach( function ( button ) {
			if ( ! navigator.share && ! ( navigator.clipboard && window.isSecureContext ) ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				var url = button.getAttribute( 'data-gr-share-url' );
				if ( navigator.share ) {
					navigator.share( { title: button.getAttribute( 'data-gr-share-title' ), url: url } ).catch( function () {} );
					return;
				}
				navigator.clipboard.writeText( url ).then( function () {
					var label = button.textContent;
					button.textContent = button.getAttribute( 'data-gr-copied' );
					window.setTimeout( function () {
						button.textContent = label;
					}, 2000 );
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
