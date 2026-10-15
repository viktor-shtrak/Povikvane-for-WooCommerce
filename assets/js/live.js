/**
 * Keeps message statuses current: while a message is still on its way, ask the
 * server for its status every few seconds and update the label in place.
 */
( function () {
	'use strict';

	var config = window.povikvaneLive;
	var INTERVAL = 6000;
	var GIVE_UP_AFTER = 10 * 60 * 1000;

	if ( ! config ) {
		return;
	}

	var started = Date.now();

	/**
	 * Labels whose message is not settled yet.
	 *
	 * @return {Element[]} Elements.
	 */
	function waiting() {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-pvk-live][data-pvk-settled="0"]' ) );
	}

	function poll() {
		var items = waiting();

		if ( 0 === items.length || Date.now() - started > GIVE_UP_AFTER ) {
			return;
		}

		if ( document.hidden ) {
			window.setTimeout( poll, INTERVAL );
			return;
		}

		var body = new FormData();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );

		items.forEach( function ( item ) {
			body.append( 'ids[]', item.getAttribute( 'data-pvk-live' ) );
		} );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data ) {
					return;
				}

				json.data.messages.forEach( function ( message ) {
					items.forEach( function ( item ) {
						if ( item.getAttribute( 'data-pvk-live' ) === String( message.id ) ) {
							item.innerHTML = message.html;
							item.setAttribute( 'data-pvk-settled', message.settled ? '1' : '0' );
						}
					} );
				} );
			} )
			.catch( function () {
				// Try again on the next round.
			} )
			.then( function () {
				window.setTimeout( poll, INTERVAL );
			} );
	}

	window.setTimeout( poll, 2000 );
}() );
