<?php
/**
 * Polls the service for delivery results.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Webhooks are best effort, so every accepted message is also checked on a
 * back-off schedule until a result is known. "Not delivered" with the detail
 * "unknown" means unconfirmed, never failed, and is never resent.
 */
final class Reconciler {

	/**
	 * Constructor.
	 *
	 * @param MessageStore    $store    Message log.
	 * @param Gateway         $gateway  API gateway.
	 * @param DeliveryUpdater $updater  Records results.
	 * @param SendingState    $state    Pause switch.
	 * @param Clock           $clock    Time source.
	 * @param Logger          $logger   Diagnostic logger.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Gateway $gateway,
		private readonly DeliveryUpdater $updater,
		private readonly SendingState $state,
		private readonly Clock $clock,
		private readonly Logger $logger = new NullLogger()
	) {}

	/**
	 * Check every message that is due.
	 *
	 * @param int $limit Maximum messages per run.
	 * @return int Messages checked.
	 */
	public function run( int $limit = 50 ): int {
		$checked = 0;

		foreach ( $this->store->due_for_check( $this->clock->now(), $limit ) as $message ) {
			$result = $this->gateway->message_status( (string) $message->api_message_id );

			if ( Outcome::RateLimited === $result->outcome ) {
				$this->logger->log( Logger::WARNING, 'Delivery checks paused: rate limited', array( 'retry_after' => $result->retry_after ) );
				break;
			}

			if ( Outcome::AuthFailure === $result->outcome ) {
				$this->state->pause( SendingState::REASON_AUTH );
				break;
			}

			++$checked;

			if ( Outcome::Success === $result->outcome && $this->apply( $message, $result ) ) {
				continue;
			}

			$this->still_unresolved( $message );
		}

		return $checked;
	}

	/**
	 * Apply a status response.
	 *
	 * @param Message   $message Message.
	 * @param ApiResult $result  Successful status response.
	 * @return bool True when the message is finished and needs no further checks.
	 */
	private function apply( Message $message, ApiResult $result ): bool {
		$attributes = $result->attributes();
		$status     = (string) ( $attributes['status'] ?? '' );

		if ( 'delivered_to_handset' === $status ) {
			$this->updater->delivered( $message );
			return true;
		}

		if ( 'not_delivered_to_handset' !== $status ) {
			return false;
		}

		if ( 'unknown' === ( $attributes['status_detail'] ?? null ) ) {
			$this->updater->unconfirmed( $message, 'unknown', 'The service could not confirm delivery.' );
			return false;
		}

		$this->updater->failed( $message, null );

		return true;
	}

	/**
	 * Count a check that produced no final answer and schedule the next one.
	 *
	 * @param Message $message Message.
	 */
	private function still_unresolved( Message $message ): void {
		$now = $this->clock->now();
		++$message->checks;
		$message->updated_at = $now;

		$delay = CheckSchedule::delay_after( $message->checks );

		if ( null === $delay ) {
			$message->next_attempt_at = null;
			$this->store->save( $message );
			$this->updater->unconfirmed( $message, 'no_report', 'No delivery report was received.' );
			return;
		}

		$message->next_attempt_at = $now + $delay;
		$this->store->save( $message );
	}
}
