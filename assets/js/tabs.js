/**
 * Tabs, unsaved-changes warning and live switch state for the settings screen.
 *
 * Without JavaScript every panel is simply shown one after another, so nothing
 * is lost; with it, one panel is shown at a time. All panels live in one form,
 * so saving never discards settings from a tab you are not looking at.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.pvk[data-pvk-tabs]' );

	if ( ! root ) {
		return;
	}

	var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-pvk-tab]' ) );
	var panels = Array.prototype.slice.call( root.querySelectorAll( '.pvk-panel' ) );
	var form = root.querySelector( 'form[data-pvk-form]' );
	var savebar = root.querySelector( '.pvk-savebar' );
	var dirty = false;
	var submitting = false;

	/**
	 * Show one tab.
	 *
	 * @param {string}  id    Tab ID.
	 * @param {boolean} focus Whether to move focus to the tab.
	 */
	function activate( id, focus ) {
		var found = false;

		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-pvk-tab' ) === id;

			found = found || on;
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			tab.tabIndex = on ? 0 : -1;

			if ( on && focus ) {
				tab.focus();
			}
		} );

		if ( ! found ) {
			return;
		}

		panels.forEach( function ( panel ) {
			var on = panel.id === 'pvk-panel-' + id;

			panel.classList.toggle( 'is-active', on );

			// The Overview has nothing to save, so the save bar is hidden there.
			if ( on && savebar ) {
				savebar.hidden = '1' === panel.getAttribute( 'data-pvk-nosave' );
			}
		} );

		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + id );
		}
	}

	root.classList.add( 'pvk-js' );

	var initial = ( window.location.hash || '' ).replace( '#', '' );

	if ( ! tabs.some( function ( tab ) { return tab.getAttribute( 'data-pvk-tab' ) === initial; } ) ) {
		initial = root.getAttribute( 'data-pvk-initial' ) || ( tabs[ 0 ] && tabs[ 0 ].getAttribute( 'data-pvk-tab' ) );
	}

	activate( initial, false );

	tabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function () {
			activate( tab.getAttribute( 'data-pvk-tab' ), false );
		} );

		tab.addEventListener( 'keydown', function ( event ) {
			var next = null;

			if ( 'ArrowRight' === event.key ) {
				next = tabs[ ( index + 1 ) % tabs.length ];
			} else if ( 'ArrowLeft' === event.key ) {
				next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
			} else if ( 'Home' === event.key ) {
				next = tabs[ 0 ];
			} else if ( 'End' === event.key ) {
				next = tabs[ tabs.length - 1 ];
			}

			if ( next ) {
				event.preventDefault();
				activate( next.getAttribute( 'data-pvk-tab' ), true );
			}
		} );
	} );

	// Links elsewhere on the page that open a tab: <a href="#messages" data-pvk-goto="messages">.
	root.addEventListener( 'click', function ( event ) {
		var link = event.target instanceof Element ? event.target.closest( '[data-pvk-goto]' ) : null;

		if ( link ) {
			event.preventDefault();
			activate( link.getAttribute( 'data-pvk-goto' ), true );
			window.scrollTo( 0, 0 );
		}
	} );

	// A status row looks switched off until its switch is on.
	root.addEventListener( 'change', function ( event ) {
		var input = event.target;
		var row = input instanceof Element ? input.closest( '.pvk-trigger' ) : null;

		if ( row && 'checkbox' === input.type && input.name.indexOf( '[enabled]' ) !== -1 ) {
			row.classList.toggle( 'is-on', input.checked );
		}
	} );

	if ( ! form ) {
		return;
	}

	function markDirty( event ) {
		// Boxes that belong to another form (for example the test-message box) are not settings.
		if ( dirty || ( event && event.target && event.target.form && event.target.form !== form ) ) {
			return;
		}

		dirty = true;

		if ( savebar ) {
			var status = savebar.querySelector( '.pvk-savebar__status' );

			savebar.classList.add( 'is-dirty' );

			if ( status ) {
				status.textContent = status.getAttribute( 'data-dirty' ) || status.textContent;
			}
		}
	}

	form.addEventListener( 'input', markDirty );
	form.addEventListener( 'change', markDirty );

	form.addEventListener( 'submit', function () {
		var active = tabs.filter( function ( tab ) {
			return 'true' === tab.getAttribute( 'aria-selected' );
		} )[ 0 ];
		var referer = form.querySelector( 'input[name="_wp_http_referer"]' );

		submitting = true;

		// WordPress returns to this address after saving, so remember the tab.
		if ( referer && active ) {
			try {
				var url = new URL( referer.value, window.location.origin );

				url.searchParams.set( 'tab', active.getAttribute( 'data-pvk-tab' ) );
				referer.value = url.pathname + url.search;
			} catch ( error ) {
				// Keep the address as it was.
			}
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty && ! submitting ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
}() );
