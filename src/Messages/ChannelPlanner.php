<?php
/**
 * Decides which channel a message goes out on.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Viber is only used when it was asked for and the account can actually do it.
 * Otherwise the message goes by SMS, so an order update is never lost because a
 * channel is missing. When a message goes by Viber, the API owns the SMS fallback.
 */
final class ChannelPlanner {

	public const FALLBACK = 'sms';

	/**
	 * Channel for a message.
	 *
	 * @param Channel $requested   Channel chosen for the order status.
	 * @param bool    $viber_ready Whether the account can send Viber with an SMS fallback.
	 */
	public static function plan( Channel $requested, bool $viber_ready ): Channel {
		return Channel::Viber === $requested && $viber_ready ? Channel::Viber : Channel::Sms;
	}

	/**
	 * The "fallback" value to send with a message on a channel.
	 *
	 * @param string $channel Channel value.
	 * @return string|null "sms" for Viber messages, null otherwise.
	 */
	public static function fallback_for( string $channel ): ?string {
		return Channel::Viber->value === $channel ? self::FALLBACK : null;
	}
}
