/**
 * Asks for confirmation before anything marked with data-povikvane-confirm.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = event.target instanceof Element ? event.target.closest( '[data-povikvane-confirm]' ) : null;

		if ( target && ! window.confirm( target.getAttribute( 'data-povikvane-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
