/**
 * Manual SMS box on the order screen. Sends through admin-ajax because the box
 * lives inside the order edit form, where a nested form is not allowed.
 */
( function () {
	'use strict';

	var config = window.povikvaneOrderBox;
	var box = document.getElementById( 'povikvane-manual' );

	if ( ! config || ! box ) {
		return;
	}

	var text = document.getElementById( 'povikvane-manual-text' );
	var button = document.getElementById( 'povikvane-manual-send' );
	var result = document.getElementById( 'povikvane-manual-result' );

	button.addEventListener( 'click', function () {
		var body = new FormData();

		body.append( 'action', 'povikvane_send_manual' );
		body.append( 'nonce', config.nonce );
		body.append( 'order_id', box.getAttribute( 'data-order-id' ) );
		body.append( 'text', text.value );

		button.disabled = true;
		result.textContent = config.i18n.sending;

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				result.textContent = json && json.data && json.data.message ? json.data.message : config.i18n.failed;

				if ( json && json.success ) {
					text.value = '';
				}
			} )
			.catch( function () {
				result.textContent = config.i18n.failed;
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );
}() );
