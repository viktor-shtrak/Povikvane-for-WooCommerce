<?php
/**
 * What a Viber template variable cannot be filled with.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Facts about the values a Viber template can be filled with.
 */
final class VariableMapper {

	/**
	 * Placeholders whose value is a link. Viber values cannot contain links, so a
	 * message that needs one is sent as an SMS.
	 */
	public const LINK_PLACEHOLDERS = array( 'tracking_url', 'shop_url', 'optout_link' );
}
