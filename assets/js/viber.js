/**
 * The Viber part of the Messages tab:
 *  - the shop-wide Viber switch shows or hides everything that only matters for Viber;
 *  - editing a message that is approved for Viber offers to send the new wording for approval;
 *  - the buttons that send messages to Povikvane for approval.
 */
( function () {
	'use strict';

	var card = document.querySelector( '[data-pvk-viber-card]' );
	var toggle = document.querySelector( '[data-pvk-viber-switch]' );

	if ( ! card ) {
		return;
	}

	var fallback = document.querySelector( '[data-pvk-fallback-switch]' );
	var root = document.querySelector( '.pvk' );
	var config = {};

	try {
		config = JSON.parse( card.getAttribute( 'data-pvk-requests' ) || '{}' );
	} catch ( e ) {
		config = {};
	}

	function rows() {
		return Array.prototype.slice.call( document.querySelectorAll( '.pvk-trigger[data-pvk-status]' ) );
	}

	function area( row ) {
		return row.querySelector( 'textarea[data-povikvane-template]' );
	}

	function show( visible ) {
		document.querySelectorAll( '[data-pvk-viber-only]' ).forEach( function ( block ) {
			block.hidden = ! visible;
		} );
	}

	/**
	 * With Viber on and no SMS fallback, an SMS length estimate means nothing for the
	 * messages that go over Viber, so it is hidden.
	 */
	function estimates() {
		if ( root ) {
			root.classList.toggle( 'pvk-hide-estimates', !! ( toggle && toggle.checked && fallback && ! fallback.checked ) );
		}
	}

	if ( toggle ) {
		toggle.addEventListener( 'change', function () {
			show( toggle.checked );
			estimates();
		} );
	}

	if ( fallback ) {
		fallback.addEventListener( 'change', estimates );
	}

	estimates();

	// Offer approval for an edited message that was approved as it was.
	rows().forEach( function ( row ) {
		var text = area( row );
		var note = row.querySelector( '[data-pvk-changed]' );

		if ( ! text || ! note ) {
			return;
		}

		function sync() {
			var edited = text.value.replace( /\s+/g, ' ' ).trim() !== row.getAttribute( 'data-pvk-initial' ).replace( /\s+/g, ' ' ).trim();

			note.hidden = ! ( edited && 'ready' === row.getAttribute( 'data-pvk-match' ) );
		}

		text.addEventListener( 'input', sync );
		sync();
	} );


	// Fill a message with the wording of an approved template, or with the plugin's own suggestion.
	document.addEventListener( 'change', function ( event ) {
		var select = event.target instanceof Element ? event.target.closest( 'select[data-pvk-pick]' ) : null;

		if ( ! select || ! select.value ) {
			return;
		}

		var row = select.closest( '.pvk-trigger[data-pvk-status]' );
		var text = row ? area( row ) : null;
		var option = select.options[ select.selectedIndex ];

		if ( text && option ) {
			var edited = text.value.replace( /\s+/g, ' ' ).trim() !== row.getAttribute( 'data-pvk-initial' ).replace( /\s+/g, ' ' ).trim();

			if ( ! edited || window.confirm( select.getAttribute( 'data-confirm' ) ) ) {
				var badge = row.querySelector( '[data-pvk-badge]' );

				// The text is now the chosen one, so the row counts as that wording stands.
				row.setAttribute( 'data-pvk-initial', option.getAttribute( 'data-text' ) );
				row.setAttribute( 'data-pvk-match', option.getAttribute( 'data-match' ) );
				text.value = option.getAttribute( 'data-text' );

				if ( badge ) {
					// The server escaped this markup.
					badge.innerHTML = option.getAttribute( 'data-badge' );
				}

				text.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}

		// Back to the heading, so the list is ready for another choice.
		select.selectedIndex = 0;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	var result = document.querySelector( '[data-pvk-request-result]' );

	function say( message ) {
		if ( result ) {
			result.textContent = message;
		}
	}

	/**
	 * In the setup wizard, keep the switches and wording on the page: sending messages for
	 * approval must not leave the choices unsaved.
	 *
	 * @return {Promise<boolean>} Whether the choices were saved.
	 */
	function saveChoices() {
		var form = document.getElementById( 'pvk-wizard-messages' );

		if ( ! form || ! config.save_action ) {
			return Promise.resolve( false );
		}

		var body = new FormData( form );

		body.set( 'action', config.save_action );

		return fetch( config.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				return !! ( data && data.success );
			} )
			.catch( function () {
				return false;
			} );
	}

	/**
	 * Send some statuses for approval and show what happened under each message.
	 *
	 * @param {string[]} slugs  Order statuses.
	 * @param {string}   prompt Confirmation text.
	 */
	function send( slugs, prompt ) {
		var body = new FormData();
		var count = 0;

		slugs.forEach( function ( slug ) {
			var row = document.querySelector( '.pvk-trigger[data-pvk-status="' + slug + '"]' );
			var text = row ? area( row ) : null;

			if ( text && text.value.trim() ) {
				body.append( 'texts[' + slug + ']', text.value );
				count++;
			}
		} );

		if ( ! count ) {
			say( config.nothing );
			return;
		}

		if ( ! window.confirm( prompt ) ) {
			return;
		}

		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		say( config.working );

		Promise.all( [
			saveChoices(),
			fetch( config.url, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
				return response.json();
			} )
		] )
			.then( function ( both ) {
				var kept = both[ 0 ];
				var data = both[ 1 ];
				var results = data && data.success ? data.data.results : {};
				var sent = 0;
				var problems = [];

				Object.keys( results ).forEach( function ( slug ) {
					var row = document.querySelector( '.pvk-trigger[data-pvk-status="' + slug + '"]' );
					var badge = row ? row.querySelector( '[data-pvk-badge]' ) : null;
					var item = results[ slug ];

					if ( 'sent' === item.result ) {
						sent++;

						if ( badge ) {
							badge.textContent = config.sent;
						}
					} else if ( 'blocked' === item.result || 'failed' === item.result ) {
						problems.push( ( row ? row.querySelector( '.pvk-switch__text' ).textContent.trim() : slug ) + ': ' + item.reason );
					}
				} );

				say( ( sent ? config.sent + ': ' + sent + '. ' : '' ) + problems.join( ' ' ) + ( kept ? ' ' + config.saved : '' ) );

				if ( ! sent && ! problems.length ) {
					say( config.nothing + ( kept ? ' ' + config.saved : '' ) );
				}
			} )
			.catch( function () {
				say( config.error );
			} );
	}

	var all = document.querySelector( '[data-pvk-request-all]' );

	if ( all ) {
		all.addEventListener( 'click', function () {
			var slugs = rows().filter( function ( row ) {
				var on = row.querySelector( 'input[type="checkbox"]' );

				return on && on.checked && 'none' === row.getAttribute( 'data-pvk-match' );
			} ).map( function ( row ) {
				return row.getAttribute( 'data-pvk-status' );
			} );

			send( slugs, config.confirm );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target instanceof Element ? event.target.closest( '[data-pvk-request-one]' ) : null;

		if ( button ) {
			send( [ button.getAttribute( 'data-pvk-request-one' ) ], config.confirm_one );
		}
	} );
}() );
