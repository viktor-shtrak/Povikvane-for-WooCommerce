<?php
/**
 * Delivery channel.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * The channels the Povikvane API can send through.
 */
enum Channel: string {
	case Sms   = 'sms';
	case Viber = 'viber';

	/**
	 * Parse a stored or submitted value; anything unknown is SMS.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function from_value( mixed $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Sms ) : self::Sms;
	}
}
