/**
 * Hides a "Needs attention" item when its X is pressed and tells the server, so it
 * stays away for a while.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.pvk-attn__dismiss' ) : null;

		if ( ! button ) {
			return;
		}

		var item = button.closest( '.pvk-attn' );
		var list = item.parentNode;
		var card = list.closest( '.pvk-card' );
		var body = new FormData();

		body.append( 'action', button.getAttribute( 'data-action' ) );
		body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
		body.append( 'key', button.getAttribute( 'data-key' ) );

		item.remove();

		if ( card && ! list.children.length ) {
			card.remove();
		}

		if ( window.ajaxurl ) {
			fetch( window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {
				// Already hidden on this page; it may simply come back.
			} );
		}
	} );
}() );
