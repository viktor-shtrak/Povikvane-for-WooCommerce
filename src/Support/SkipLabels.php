<?php
/**
 * Readable skip reasons.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

use Povikvane\WooCommerce\Messages\SkipReason;

defined( 'ABSPATH' ) || exit;

/**
 * Translated text for SkipReason codes, used in order notes and the message list.
 */
final class SkipLabels {

	/**
	 * Text for a reason.
	 *
	 * @param string $reason A SkipReason constant.
	 */
	public static function text( string $reason ): string {
		return match ( $reason ) {
			SkipReason::NO_PHONE         => __( 'the order has no phone number', 'povikvane-sms-for-woocommerce' ),
			SkipReason::INVALID_PHONE    => __( 'the phone number is not valid', 'povikvane-sms-for-woocommerce' ),
			SkipReason::NOT_MOBILE       => __( 'the number is not a mobile number', 'povikvane-sms-for-woocommerce' ),
			SkipReason::TOO_OLD          => __( 'the order is too old for automatic messages', 'povikvane-sms-for-woocommerce' ),
			SkipReason::ADMIN_CREATED    => __( 'the order was created in the admin', 'povikvane-sms-for-woocommerce' ),
			SkipReason::SITE_UNCONFIRMED => __( 'the site address changed and sending is not confirmed', 'povikvane-sms-for-woocommerce' ),
			SkipReason::VETOED           => __( 'stopped by a site rule', 'povikvane-sms-for-woocommerce' ),
			SkipReason::EMPTY_MESSAGE    => __( 'the message text was empty', 'povikvane-sms-for-woocommerce' ),
			SkipReason::STATUS_CHANGED   => __( 'the order status changed before sending', 'povikvane-sms-for-woocommerce' ),
			SkipReason::ORDER_MISSING    => __( 'the order no longer exists', 'povikvane-sms-for-woocommerce' ),
			SkipReason::DISCARDED        => __( 'discarded by an administrator', 'povikvane-sms-for-woocommerce' ),
			SkipReason::NO_CONSENT       => __( 'the customer has not agreed to promotional messages', 'povikvane-sms-for-woocommerce' ),
			SkipReason::FREQUENCY_CAP    => __( 'the customer already received a promotional message recently', 'povikvane-sms-for-woocommerce' ),
			SkipReason::MARKETING_OFF    => __( 'promotional messages are switched off', 'povikvane-sms-for-woocommerce' ),
			default                      => $reason,
		};
	}
}
