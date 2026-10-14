<?php
/**
 * Result of analysing a message body.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Encoding, length in encoding units and number of SMS segments.
 */
final class SegmentInfo {

	/**
	 * Constructor.
	 *
	 * @param string $encoding    "gsm7" or "ucs2".
	 * @param int    $units       Length in encoding units (extension characters count 2; UCS-2 counts UTF-16 units).
	 * @param int    $segments    Number of SMS segments (credits for SMS); 0 for an empty body.
	 * @param int    $per_segment Capacity of one segment in the multi-part case.
	 * @param int    $single      Capacity of a single, unsplit message.
	 */
	public function __construct(
		public readonly string $encoding,
		public readonly int $units,
		public readonly int $segments,
		public readonly int $per_segment,
		public readonly int $single
	) {}
}
