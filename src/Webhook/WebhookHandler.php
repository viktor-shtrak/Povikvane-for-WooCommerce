<?php
/**
 * Applies a delivery webhook.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a "message.status_updated" event into a delivery result. The sender is
 * trusted only as far as the secret URL goes, so the event can only move a known
 * message forward; it can never create a message or trigger a send. The same
 * Povikvane webhook also describes messages this plugin did not send, which are
 * simply ignored.
 */
final class WebhookHandler {

	public const RESULT_UPDATED = 'updated';
	public const RESULT_IGNORED = 'ignored';
	public const RESULT_UNKNOWN = 'unknown_message';
	public const RESULT_INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param MessageStore    $store    Message log.
	 * @param DeliveryUpdater $updater  Records results.
	 * @param WebhookActivity $activity Remembers arrivals.
	 * @param Clock           $clock    Time source.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly DeliveryUpdater $updater,
		private readonly WebhookActivity $activity,
		private readonly Clock $clock
	) {}

	/**
	 * Handle a decoded webhook body.
	 *
	 * @param array<mixed> $body Decoded JSON.
	 * @return string One of the RESULT_* constants.
	 */
	public function handle( array $body ): string {
		$result = $this->process( $body );
		$this->activity->record( $this->clock->now(), $result );

		return $result;
	}

	/**
	 * Do the work.
	 *
	 * @param array<mixed> $body Decoded JSON.
	 */
	private function process( array $body ): string {
		$data = $body['data'] ?? null;

		if ( 'message.status_updated' !== ( $body['event'] ?? null ) || ! is_array( $data ) ) {
			return self::RESULT_INVALID;
		}

		$id        = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		$status    = isset( $data['status'] ) && is_string( $data['status'] ) ? $data['status'] : '';
		$reference = isset( $data['reference'] ) && is_string( $data['reference'] ) ? $data['reference'] : '';
		$error     = isset( $data['error'] ) && is_string( $data['error'] ) && '' !== $data['error'] ? $data['error'] : null;

		if ( '' === $id || ! in_array( $status, array( 'delivered', 'failed' ), true ) ) {
			return self::RESULT_INVALID;
		}

		$message = $this->store->find_by_api_message_id( $id );

		// Without a stored ID (an interrupted send) the reference identifies the message.
		if ( null === $message && '' !== $reference ) {
			$message = $this->store->find_by_reference( $reference );

			if ( null !== $message && null === $message->api_message_id ) {
				$message->api_message_id = $id;
			}
		}

		if ( null === $message ) {
			return self::RESULT_UNKNOWN;
		}

		$changed = 'delivered' === $status ? $this->updater->delivered( $message, isset( $data['delivered_via'] ) && is_string( $data['delivered_via'] ) ? $data['delivered_via'] : null ) : $this->updater->failed( $message, $error );

		return $changed ? self::RESULT_UPDATED : self::RESULT_IGNORED;
	}
}
