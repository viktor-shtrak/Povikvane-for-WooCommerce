/**
 * Keeps the "Credits available" figure fresh while the settings page is open.
 */
( function () {
	'use strict';

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
}() );
