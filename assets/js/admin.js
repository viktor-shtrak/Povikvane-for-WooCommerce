/**
 * Template editor: live estimate of SMS parts and credits.
 */
( function () {
	'use strict';

	var config = window.povikvaneAdmin;
	var segments = window.povikvaneSegments;

	if ( ! config || ! segments ) {
		return;
	}

	var PLACEHOLDER = /\{([A-Za-z_]+)\}/g;

	/**
	 * Fill a template with sample values, like the PHP renderer does.
	 *
	 * @param {string} template Template text.
	 * @param {Object} values   Sample values.
	 * @return {string} Rendered text.
	 */
	function render( template, values ) {
		return template
			.replace( PLACEHOLDER, function ( match, name ) {
				var value = values[ name.toLowerCase() ];
				return undefined === value ? '' : value;
			} )
			.replace( /[ \t]{2,}/g, ' ' )
			.trim();
	}

	/**
	 * Placeholder names that are not supported.
	 *
	 * @param {string} template Template text.
	 * @return {string[]} Unknown names.
	 */
	function unknownNames( template ) {
		var found = [];
		var match;

		PLACEHOLDER.lastIndex = 0;
		while ( null !== ( match = PLACEHOLDER.exec( template ) ) ) {
			if ( -1 === config.names.indexOf( match[ 1 ].toLowerCase() ) && -1 === found.indexOf( match[ 1 ] ) ) {
				found.push( match[ 1 ] );
			}
		}

		return found;
	}

	/**
	 * One line of text describing an analysis.
	 *
	 * @param {{encoding: string, units: number, segments: number}} info Analysis.
	 * @return {string} Description.
	 */
	function describe( info ) {
		var label = 'ucs2' === info.encoding ? config.i18n.ucs2 : config.i18n.gsm7;
		var parts = 1 === info.segments ? config.i18n.segment : config.i18n.segments;

		return label + ': ' + info.units + ' ' + config.i18n.characters + ', ' + info.segments + ' ' + parts + ' (' + info.segments + ' ' + config.i18n.credits + ')';
	}

	/**
	 * Update the estimate under a textarea.
	 *
	 * @param {HTMLTextAreaElement} textarea Template field.
	 */
	function update( textarea ) {
		var output = textarea.parentNode.querySelector( '.povikvane-estimate' );
		var latin = render( textarea.value, config.samples.latin );
		var cyrillic = render( textarea.value, config.samples.cyrillic );
		var lines = [];
		var unknown = unknownNames( textarea.value );
		var base;

		if ( ! output ) {
			return;
		}

		if ( '' === latin ) {
			output.textContent = config.i18n.empty;
			return;
		}

		base = segments.analyse( latin );
		lines.push( describe( base ) );

		if ( 'gsm7' === base.encoding ) {
			lines.push( config.i18n.cyrillic + ' ' + describe( segments.analyse( cyrillic ) ) );
		}

		if ( unknown.length ) {
			lines.push( config.i18n.unknown + ' {' + unknown.join( '}, {' ) + '}' );
		}

		output.textContent = lines.join( ' · ' );
	}

	document.querySelectorAll( 'textarea[data-povikvane-template]' ).forEach( function ( textarea ) {
		update( textarea );
		textarea.addEventListener( 'input', function () {
			update( textarea );
		} );
	} );
}() );
