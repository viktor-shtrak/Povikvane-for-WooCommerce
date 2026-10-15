/**
 * Remembers a dismissed Povikvane notice: the X button of a notice marked
 * "povikvane-dismissible" tells the server, so the notice stays away for a while.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.povikvane-dismissible .notice-dismiss' ) : null;

		if ( ! button || ! window.ajaxurl ) {
			return;
		}

		var notice = button.closest( '.povikvane-dismissible' );
		var body = new FormData();

		body.append( 'action', notice.getAttribute( 'data-action' ) );
		body.append( 'nonce', notice.getAttribute( 'data-nonce' ) );

		fetch( window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {
			// The notice is already hidden on this page; it may simply come back.
		} );
	} );
}() );
