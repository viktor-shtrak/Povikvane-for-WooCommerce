/**
 * Settings screen helpers: copy buttons and a fresh "Credits" figure.
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

				function done() {
					button.textContent = button.getAttribute( 'data-copied' );
					window.setTimeout( function () {
						button.textContent = label;
					}, 1500 );
				}

				field.focus();
				field.select();
				field.setSelectionRange( 0, field.value.length );

				// The clipboard API only exists on secure (https) pages; the older command works everywhere.
				function fallback() {
					try {
						if ( document.execCommand( 'copy' ) ) {
							done();
						}
					} catch ( e ) {
						// The text stays selected, so the shortcut still works.
					}
				}

				if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( field.value ).then( done, fallback );
				} else {
					fallback();
				}
			} );
		} );
	}

	/**
	 * Refresh a stale credit balance in the background.
	 */
	function initBalance() {
		var config = window.povikvaneStatus;
		var amount = document.getElementById( 'povikvane-balance' );
		var hint = document.getElementById( 'povikvane-balance-hint' );

		if ( ! config || ! amount || '1' !== amount.getAttribute( 'data-refresh' ) ) {
			return;
		}

		var age = parseInt( amount.getAttribute( 'data-age' ), 10 );
		var estimated = '1' === amount.getAttribute( 'data-estimated' );

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
				if ( json && json.success && json.data ) {
					amount.textContent = json.data.amount;

					if ( hint ) {
						hint.textContent = json.data.hint;
					}
				}
			} )
			.catch( function () {
				// Keep the figure that is already on the page.
			} );
	}

	initCopyButtons();
	initBalance();
}() );
