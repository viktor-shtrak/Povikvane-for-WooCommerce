<?php
/**
 * Plain-language name of the channel a message used.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * "SMS", "Viber", or "Viber, delivered by SMS" when the fallback was used.
 */
final class ChannelLabel {

	/**
	 * Label for a message.
	 *
	 * @param Message $message Logged message.
	 */
	public static function text( Message $message ): string {
		if ( 'viber' !== $message->channel ) {
			return __( 'SMS', 'povikvane-for-woocommerce' );
		}

		return 'sms' === $message->delivered_via
			? __( 'Viber, delivered by SMS', 'povikvane-for-woocommerce' )
			: __( 'Viber', 'povikvane-for-woocommerce' );
	}
}
