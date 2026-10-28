/**
 * "Check price" under an order message: asks Povikvane what the message as written
 * would cost. Nothing is sent and nothing is charged.
 */
( function () {
	'use strict';

	var config = window.povikvanePrice;

	if ( ! config ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target instanceof Element ? event.target.closest( '[data-pvk-price]' ) : null;

		if ( ! button ) {
			return;
		}

		var row = button.closest( '.pvk-trigger' );
		var text = row ? row.querySelector( 'textarea' ) : null;
		var out = button.parentNode.querySelector( '[data-pvk-price-result]' );

		if ( ! text || ! out ) {
			return;
		}

		var body = new FormData();

		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'text', text.value );
		out.textContent = config.working;
		button.disabled = true;

		fetch( config.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				out.textContent = data && data.success && data.data && data.data.message ? data.data.message : config.error;
			} )
			.catch( function () {
				out.textContent = config.error;
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );
}() );
