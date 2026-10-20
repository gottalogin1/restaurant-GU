/**
 * Photo upload form (Photos\Photo_Gallery): checks the file size before
 * sending, turns the rating off for people connected to the restaurant, and
 * sends the form to the REST endpoint. Messages are inserted as plain text.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};

	function messages( body ) {
		var list = [];
		if ( body && body.message ) {
			list.push( body.message );
		}
		( ( body && body.additional_errors ) || [] ).forEach( function ( error ) {
			if ( error && error.message ) {
				list.push( error.message );
			}
		} );
		return list.length ? list : [ __( 'Your photo could not be sent. Please try again.', 'guam-restaurants-core' ) ];
	}

	function show( status, lines, isError ) {
		status.textContent = '';
		status.classList.toggle( 'is-error', !! isError );
		lines.forEach( function ( line ) {
			var p = document.createElement( 'span' );
			p.textContent = line;
			status.appendChild( p );
		} );
	}

	function init( form ) {
		var opened = Date.now();
		var status = form.querySelector( '.gr-photos__status' );
		var button = form.querySelector( 'button[type="submit"]' );
		var file = form.querySelector( 'input[type="file"]' );
		var connected = form.querySelector( '[data-gr-photo-connected]' );
		var rating = form.querySelector( '[data-gr-photo-rating]' );

		if ( connected && rating ) {
			connected.addEventListener( 'change', function () {
				rating.disabled = connected.checked;
				if ( connected.checked ) {
					rating.querySelector( 'input[value="0"]' ).checked = true;
				}
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var max = parseInt( file.getAttribute( 'data-max-bytes' ), 10 ) || 0;
			if ( ! form.reportValidity() ) {
				return;
			}
			if ( max && file.files[ 0 ] && file.files[ 0 ].size > max ) {
				show( status, [ __( 'That photo is too large. Check the size limit under the Photo field.', 'guam-restaurants-core' ) ], true );
				return;
			}
			var data = new FormData( form );
			data.set( 'elapsed_ms', String( Date.now() - opened ) );
			data.set( 'agree', form.querySelector( 'input[name="agree"]' ).checked ? '1' : '0' );
			data.set( 'connected', connected && connected.checked ? '1' : '0' );
			var headers = {};
			if ( form.getAttribute( 'data-nonce' ) ) {
				headers[ 'X-WP-Nonce' ] = form.getAttribute( 'data-nonce' );
			}
			button.disabled = true;
			show( status, [ __( 'Uploading…', 'guam-restaurants-core' ) ], false );
			fetch( form.getAttribute( 'data-url' ), {
				method: 'POST',
				body: data,
				headers: headers,
				credentials: 'same-origin',
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					}, function () {
						return { ok: false, body: { message: 413 === response.status ? __( 'That photo is too large for the server.', 'guam-restaurants-core' ) : '' } };
					} );
				} )
				.then( function ( result ) {
					show( status, messages( result.body ), ! result.ok );
					if ( result.ok ) {
						form.reset();
						opened = Date.now();
					}
				} )
				.catch( function () {
					show( status, [ __( 'Your photo could not be sent. Check your connection and try again.', 'guam-restaurants-core' ) ], true );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	document.querySelectorAll( '[data-gr-photo-form]' ).forEach( init );
} )();
