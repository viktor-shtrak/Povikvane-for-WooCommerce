/**
 * Setup wizard: shows the test message arriving.
 */
( function () {
	'use strict';

	var config = window.povikvaneWizard;
	var box = document.getElementById( 'pvk-test-result' );

	if ( ! config || ! box ) {
		return;
	}

	// Nothing to watch if the result is already known when the page loads.
	if ( '1' === box.getAttribute( 'data-final' ) ) {
		return;
	}

	var id = box.getAttribute( 'data-message-id' );
	var text = document.getElementById( 'pvk-test-status' );
	var pill = document.getElementById( 'pvk-test-pill' );
	var spinner = box.querySelector( '.pvk-spinner' );
	var tries = 0;
	var MAX_TRIES = 40;
	var DELAY = 3000;

	function stop( message ) {
		if ( spinner ) {
			spinner.style.display = 'none';
		}

		if ( text && message ) {
			text.textContent = message;
		}
	}

	function poll() {
		var body = new FormData();

		tries += 1;
		body.append( 'action', 'povikvane_wizard_status' );
		body.append( 'nonce', config.nonce );
		body.append( 'id', id );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					stop( '' );
					return;
				}

				if ( pill ) {
					// The server escaped this markup.
					pill.innerHTML = json.data.pill;
				}

				if ( json.data.final ) {
					stop( json.data.label );
				} else if ( tries < MAX_TRIES ) {
					window.setTimeout( poll, DELAY );
				} else {
					stop( config.i18n.stopped );
				}
			} )
			.catch( function () {
				if ( tries < MAX_TRIES ) {
					window.setTimeout( poll, DELAY );
				} else {
					stop( config.i18n.stopped );
				}
			} );
	}

	window.setTimeout( poll, 1500 );
}() );
