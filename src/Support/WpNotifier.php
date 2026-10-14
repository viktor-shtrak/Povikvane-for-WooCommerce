<?php
/**
 * Order notes for message events.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Adds private order notes (never customer notes) describing what happened.
 */
final class WpNotifier implements Notifier {

	/**
	 * {@inheritDoc}
	 *
	 * @param Message             $message Message the note is about.
	 * @param string              $code    One of the NOTE_* constants.
	 * @param array<string,mixed> $params  Values for the note text.
	 */
	public function note( Message $message, string $code, array $params = array() ): void {
		if ( $message->order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $message->order_id );
		if ( ! $order ) {
			return;
		}

		$text = $this->text( $message, $code, $params );
		if ( '' !== $text ) {
			$order->add_order_note( $text, 0, false );
		}
	}

	/**
	 * Translated note text.
	 *
	 * @param Message             $message Message.
	 * @param string              $code    Note code.
	 * @param array<string,mixed> $params  Parameters.
	 */
	private function text( Message $message, string $code, array $params ): string {
		switch ( $code ) {
			case Notifier::NOTE_QUEUED:
				$credits = $params['credits'] ?? null;

				return null === $credits
					? sprintf(
						/* translators: %s: recipient phone number. */
						__( 'Povikvane: SMS to %s accepted for delivery.', 'povikvane-for-woocommerce' ),
						$message->recipient
					)
					: sprintf(
						/* translators: 1: recipient phone number, 2: credits charged. */
						__( 'Povikvane: SMS to %1$s accepted for delivery (%2$s credits).', 'povikvane-for-woocommerce' ),
						$message->recipient,
						(string) $credits
					);

			case Notifier::NOTE_FAILED:
				return sprintf(
					/* translators: 1: recipient phone number, 2: reason. */
					__( 'Povikvane: SMS to %1$s was not sent: %2$s', 'povikvane-for-woocommerce' ),
					$message->recipient,
					(string) ( $params['detail'] ?? __( 'unknown error', 'povikvane-for-woocommerce' ) )
				);

			case Notifier::NOTE_UNCONFIRMED:
				return sprintf(
					/* translators: %s: recipient phone number. */
					__( 'Povikvane: delivery of the SMS to %s could not be confirmed. Check the message log before sending it again.', 'povikvane-for-woocommerce' ),
					$message->recipient
				);

			case Notifier::NOTE_HELD_CREDITS:
				return sprintf(
					/* translators: %s: recipient phone number. */
					__( 'Povikvane: SMS to %s is on hold because the account has no credits.', 'povikvane-for-woocommerce' ),
					$message->recipient
				);

			case Notifier::NOTE_DELIVERED:
				return sprintf(
					/* translators: %s: recipient phone number. */
					__( 'Povikvane: SMS to %s was delivered.', 'povikvane-for-woocommerce' ),
					$message->recipient
				);

			case Notifier::NOTE_DELIVERY_FAILED:
				$detail = (string) ( $params['detail'] ?? '' );

				return '' === $detail
					? sprintf(
						/* translators: %s: recipient phone number. */
						__( 'Povikvane: SMS to %s could not be delivered.', 'povikvane-for-woocommerce' ),
						$message->recipient
					)
					: sprintf(
						/* translators: 1: recipient phone number, 2: reason. */
						__( 'Povikvane: SMS to %1$s could not be delivered: %2$s', 'povikvane-for-woocommerce' ),
						$message->recipient,
						$detail
					);

			case Notifier::NOTE_SKIPPED:
				return sprintf(
					/* translators: %s: reason the SMS was not sent. */
					__( 'Povikvane: no SMS was sent because %s.', 'povikvane-for-woocommerce' ),
					SkipLabels::text( (string) ( $params['reason'] ?? '' ) )
				);

			case Notifier::NOTE_EXPIRED:
				return sprintf(
					/* translators: %s: recipient phone number. */
					__( 'Povikvane: SMS to %s was not sent because it waited too long.', 'povikvane-for-woocommerce' ),
					$message->recipient
				);
		}

		return '';
	}
}
