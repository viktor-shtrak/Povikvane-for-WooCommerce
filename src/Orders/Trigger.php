<?php
/**
 * Configuration of one order-status trigger.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a status sends a message, with which text, after what delay.
 */
final class Trigger {

	/**
	 * Constructor.
	 *
	 * @param string                             $status        Status slug without the "wc-" prefix.
	 * @param bool                               $enabled       Whether the status sends a message.
	 * @param string                             $template      Message template.
	 * @param int                                $delay_minutes Minutes to wait before sending (0 = immediately).
	 * @param string                             $channel       Channel chosen for this status: "sms" or "viber".
	 * @param string                             $viber_template Viber template ID, when the channel is Viber.
	 * @param array<string,array<string,string>> $viber_map What fills each template variable: template ID => variable => placeholder.
	 */
	public function __construct(
		public readonly string $status,
		public readonly bool $enabled,
		public readonly string $template,
		public readonly int $delay_minutes = 0,
		public readonly string $channel = 'sms',
		public readonly string $viber_template = '',
		public readonly array $viber_map = array()
	) {}
}
