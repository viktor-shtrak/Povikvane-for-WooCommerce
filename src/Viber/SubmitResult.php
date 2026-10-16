<?php
/**
 * Outcome of sending one template for review.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Accepted for review, or refused with a reason the shop owner can read.
 */
final class SubmitResult {

	/**
	 * Constructor.
	 *
	 * @param bool   $accepted    Whether Povikvane took the template for review.
	 * @param string $template_id ID Povikvane gave it, when accepted.
	 * @param string $reason      Plain-language reason, when refused.
	 */
	public function __construct(
		public readonly bool $accepted,
		public readonly string $template_id = '',
		public readonly string $reason = ''
	) {}
}
