/**
 * SMS encoding and segment counting.
 *
 * Mirrors Povikvane\WooCommerce\Messages\SegmentCounter (PHP). Both are tested
 * against tests/fixtures/segment-cases.json, so they cannot drift apart.
 */
( function ( root, factory ) {
	'use strict';

	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.povikvaneSegments = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// GSM 03.38 basic character set (without the escape code).
	var GSM_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

	// GSM 03.38 extension table: each of these costs two units.
	var GSM_EXTENDED = '^{}\\[~]|€\f';

	/**
	 * Analyse a message body.
	 *
	 * @param {string} text Message text.
	 * @return {{encoding: string, units: number, segments: number}} Result.
	 */
	function analyse( text ) {
		var chars = Array.from( text );
		var units = 0;
		var i;

		if ( 0 === chars.length ) {
			return { encoding: 'gsm7', units: 0, segments: 0 };
		}

		for ( i = 0; i < chars.length; i++ ) {
			if ( -1 !== GSM_BASIC.indexOf( chars[ i ] ) ) {
				units += 1;
			} else if ( -1 !== GSM_EXTENDED.indexOf( chars[ i ] ) ) {
				units += 2;
			} else {
				// Any other character switches the whole message to UCS-2 (UTF-16 code units).
				return {
					encoding: 'ucs2',
					units: text.length,
					segments: text.length <= 70 ? 1 : Math.ceil( text.length / 67 )
				};
			}
		}

		return {
			encoding: 'gsm7',
			units: units,
			segments: units <= 160 ? 1 : Math.ceil( units / 153 )
		};
	}

	return { analyse: analyse };
} ) );
