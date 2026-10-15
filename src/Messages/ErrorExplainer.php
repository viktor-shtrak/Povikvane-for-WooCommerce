<?php
/**
 * Plain-language reasons for failed messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the codes the plugin stores into a sentence a shop owner can act on.
 */
final class ErrorExplainer {

	/**
	 * What went wrong and what to do about it.
	 *
	 * @param Message $message A failed or unconfirmed message.
	 */
	public static function explain( Message $message ): string {
		$code = (string) $message->error_code;

		if ( Status::Unconfirmed === $message->status ) {
			return __( 'Povikvane could not confirm delivery. The message may still have reached the phone, so check before sending it again.', 'povikvane-for-woocommerce' );
		}

		return match ( true ) {
			'delivery_failed' === $code                          => __( 'The message did not reach the phone: it may be switched off, out of coverage, or the number is not active. Resending later may work.', 'povikvane-for-woocommerce' ),
			'http_502' === $code                                 => __( 'The mobile network refused the message and the credits were refunded. Check the phone number.', 'povikvane-for-woocommerce' ),
			in_array( $code, array( 'http_400', 'http_422' ), true ) => __( 'Povikvane did not accept the message. Check the phone number and the text.', 'povikvane-for-woocommerce' ),
			in_array( $code, array( 'http_401', 'http_403' ), true ) => __( 'Povikvane rejected the API key. Check your connection settings.', 'povikvane-for-woocommerce' ),
			'idempotency_mismatch' === $code                     => __( 'A duplicate send was stopped, so nothing was lost. Contact support if this keeps happening.', 'povikvane-for-woocommerce' ),
			default                                              => __( 'The message could not be sent. See the message log detail, or resend it.', 'povikvane-for-woocommerce' ),
		};
	}
}
