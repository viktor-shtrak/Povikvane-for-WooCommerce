/**
 * Settings page helpers: copy buttons and a fresh "Credits available" figure.
 */
( function () {
	'use strict';

	/**
	 * Copy buttons: copy the value of the field named in data-povikvane-copy.
	 */
	function initCopyButtons() {
		document.querySelectorAll( '[data-povikvane-copy]' ).forEach( function ( button ) {
			var field = document.querySelector( button.getAttribute( 'data-povikvane-copy' ) );

			if ( ! field ) {
				return;
			}

			field.addEventListener( 'focus', function () {
				field.select();
			} );

			button.addEventListener( 'click', function () {
				var label = button.textContent;

				field.select();

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( field.value ).then( function () {
						button.textContent = button.getAttribute( 'data-copied' );
						window.setTimeout( function () {
							button.textContent = label;
						}, 1500 );
					} );
				}
			} );
		} );
	}

	/**
	 * Refresh a stale credit balance in the background.
	 */
	function initBalance() {
		var config = window.povikvaneStatus;
		var target = document.getElementById( 'povikvane-balance' );

		if ( ! config || ! target || '1' !== target.getAttribute( 'data-refresh' ) ) {
			return;
		}

		var age = parseInt( target.getAttribute( 'data-age' ), 10 );
		var estimated = '1' === target.getAttribute( 'data-estimated' );

		// Fresh enough: nothing to do.
		if ( ! estimated && age >= 0 && age < config.maxAge ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'povikvane_refresh_balance' );
		body.append( 'nonce', config.nonce );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.text ) {
					target.textContent = json.data.text;
				}
			} )
			.catch( function () {
				// Keep the figure that is already on the page.
			} );
	}

	initCopyButtons();
	initBalance();
}() );
