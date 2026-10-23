<?php
/**
 * Applies a delivery webhook.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentEvent;
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

	public const RESULT_UPDATED   = 'updated';
	public const RESULT_IGNORED   = 'ignored';
	public const RESULT_UNKNOWN   = 'unknown_message';
	public const RESULT_INVALID   = 'invalid';
	public const RESULT_DUPLICATE = 'duplicate';
	public const RESULT_TEMPLATE  = 'template_updated';
	public const RESULT_OPTED_OUT = 'opted_out';

	/**
	 * Constructor.
	 *
	 * @param MessageStore    $store    Message log.
	 * @param DeliveryUpdater $updater  Records results.
	 * @param WebhookActivity $activity Remembers arrivals.
	 * @param Clock           $clock    Time source.
	 * @param Consent|null    $consent  Consent rules, to record opt-outs Povikvane reports.
	 * @param callable|null   $on_template Called when a template changed state, to refresh the list.
	 * @param SeenEvents|null $seen     Remembers event ids, so a retry is not handled twice.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly DeliveryUpdater $updater,
		private readonly WebhookActivity $activity,
		private readonly Clock $clock,
		private readonly ?Consent $consent = null,
		private readonly mixed $on_template = null,
		private readonly ?SeenEvents $seen = null
	) {}

	/**
	 * Handle a decoded webhook body.
	 *
	 * @param array<mixed> $body Decoded JSON.
	 * @return string One of the RESULT_* constants.
	 */
	public function handle( array $body ): string {
		$now = $this->clock->now();
		$id  = $body['id'] ?? null;

		// A retried event keeps its id; handling it twice would repeat its side effects.
		if ( null !== $this->seen && is_string( $id ) && $this->seen->has( $id, $now ) ) {
			return self::RESULT_DUPLICATE;
		}

		$result = $this->process( $body );
		$this->activity->record( $now, $result );

		// A message this plugin does not know yet may be known on the retry, so it is not remembered.
		if ( null !== $this->seen && is_string( $id ) && self::RESULT_UNKNOWN !== $result ) {
			$this->seen->remember( $id, $now );
		}

		return $result;
	}

	/**
	 * Do the work.
	 *
	 * @param array<mixed> $body Decoded JSON.
	 */
	private function process( array $body ): string {
		$data = $body['data'] ?? null;

		if ( 'template.status_updated' === ( $body['event'] ?? null ) && is_array( $data ) ) {
			return $this->template_updated();
		}

		if ( 'contact.unsubscribed' === ( $body['event'] ?? null ) && is_array( $data ) ) {
			return $this->contact_unsubscribed( $data );
		}

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

	/**
	 * A template changed state: the list is read again. The event carries no text, so
	 * the list from the API is the only source of truth.
	 */
	private function template_updated(): string {
		if ( is_callable( $this->on_template ) ) {
			( $this->on_template )();
		}

		return self::RESULT_TEMPLATE;
	}

	/**
	 * Povikvane added an opt-out for a customer: promotions to them stop here as well.
	 *
	 * @param array<mixed> $data Event data.
	 */
	private function contact_unsubscribed( array $data ): string {
		$phone = isset( $data['phone'] ) && is_string( $data['phone'] ) ? $data['phone'] : '';

		if ( null === $this->consent || 1 !== preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ) {
			return self::RESULT_INVALID;
		}

		return $this->consent->revoke( $phone, ConsentEvent::SOURCE_PLATFORM ) ? self::RESULT_OPTED_OUT : self::RESULT_IGNORED;
	}
}
