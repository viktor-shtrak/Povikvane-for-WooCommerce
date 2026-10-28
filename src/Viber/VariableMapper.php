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
	 * Placeholders whose value is a link that changes from message to message. Viber values
	 * cannot contain links, so a message that needs one is sent as an SMS. The shop address is
	 * not here: it never changes, so it is written into the template as plain text instead.
	 */
	public const LINK_PLACEHOLDERS = array( 'tracking_url', 'optout_link' );
}
