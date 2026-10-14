<?php
/**
 * Applies delivery results to messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a message becomes delivered or failed after being accepted. A
 * message only moves forward: a late or repeated report can never undo a result.
 */
final class DeliveryUpdater {

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store    Message log.
	 * @param Notifier     $notifier Order note writer.
	 * @param Clock        $clock    Time source.
	 * @param Logger       $logger   Diagnostic logger.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Notifier $notifier,
		private readonly Clock $clock,
		private readonly Logger $logger = new NullLogger()
	) {}

	/**
	 * The handset received the message.
	 *
	 * @param Message     $message Message.
	 * @param string|null $via     Channel that delivered it, when the service says.
	 * @return bool Whether the message changed.
	 */
	public function delivered( Message $message, ?string $via = null ): bool {
		if ( ! $this->can_finish( $message ) ) {
			return false;
		}

		$message->delivered_via = null !== $via && null !== Channel::tryFrom( $via ) ? $via : $message->delivered_via;

		$message->error_code   = null;
		$message->error_detail = null;
		$this->finish( $message, Status::Delivered );
		$this->notifier->note( $message, Notifier::NOTE_DELIVERED );

		return true;
	}

	/**
	 * The carrier could not deliver the message.
	 *
	 * @param Message     $message Message.
	 * @param string|null $error   Reason from the service, if any.
	 * @return bool Whether the message changed.
	 */
	public function failed( Message $message, ?string $error ): bool {
		if ( ! $this->can_finish( $message ) ) {
			return false;
		}

		$message->error_code   = 'delivery_failed';
		$message->error_detail = $error;
		$this->finish( $message, Status::Failed );
		$this->notifier->note( $message, Notifier::NOTE_DELIVERY_FAILED, array( 'detail' => $error ) );

		return true;
	}

	/**
	 * The service cannot say whether the message was delivered.
	 *
	 * @param Message     $message Message.
	 * @param string      $code    Short reason code.
	 * @param string|null $detail  Reason text.
	 * @return bool Whether the message changed.
	 */
	public function unconfirmed( Message $message, string $code, ?string $detail ): bool {
		if ( Status::Queued !== $message->status ) {
			return false;
		}

		$message->status       = Status::Unconfirmed;
		$message->error_code   = $code;
		$message->error_detail = $detail;
		$message->updated_at   = $this->clock->now();
		$this->store->save( $message );
		$this->notifier->note( $message, Notifier::NOTE_UNCONFIRMED, array( 'message_id' => $message->api_message_id ) );
		$this->logger->log( Logger::INFO, 'Message ' . $message->id . ' -> unconfirmed', array( 'code' => $code ) );

		return true;
	}

	/**
	 * Whether a final result may still be recorded.
	 *
	 * @param Message $message Message.
	 */
	private function can_finish( Message $message ): bool {
		return in_array( $message->status, array( Status::Sending, Status::Queued, Status::Unconfirmed ), true );
	}

	/**
	 * Record a final status.
	 *
	 * @param Message $message Message.
	 * @param Status  $status  Final status.
	 */
	private function finish( Message $message, Status $status ): void {
		$now                      = $this->clock->now();
		$message->status          = $status;
		$message->next_attempt_at = null;
		$message->updated_at      = $now;
		$message->final_at        = $now;
		$this->store->save( $message );
		$this->logger->log( Logger::INFO, 'Message ' . $message->id . ' -> ' . $status->value, array( 'reference' => $message->reference ) );
	}
}
