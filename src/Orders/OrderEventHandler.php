<?php
/**
 * Decides what happens when an order changes status.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Viber\ViberPlan;

defined( 'ABSPATH' ) || exit;

/**
 * For one order event: queue a message, or record why none was sent. At most one
 * decision is ever made per order and status, because the reference is unique.
 */
final class OrderEventHandler {

	public const RESULT_DISABLED  = 'disabled';
	public const RESULT_DUPLICATE = 'duplicate';
	public const RESULT_QUEUED    = 'queued';
	public const RESULT_SKIPPED   = 'skipped';

	/**
	 * Final veto, so other code can stop a message.
	 *
	 * @var callable(OrderFacts): bool
	 */
	private $allow;

	/**
	 * Decides whether a message goes over Viber.
	 *
	 * @var callable|null
	 */
	private $viber_plan;

	/**
	 * Constructor.
	 *
	 * @param MessageStore  $store      Message log.
	 * @param Enqueuer      $enqueuer   Queues or records messages.
	 * @param Normalizer    $normalizer Phone normaliser.
	 * @param StormGuard    $storm      Mass-send guard.
	 * @param SendingState  $state      Pause switch.
	 * @param Notifier      $notifier   Order note writer.
	 * @param Clock         $clock      Time source.
	 * @param string        $site_token Site token used in references.
	 * @param Rules         $rules      Eligibility rules.
	 * @param callable      $allow      Returns false to veto a message.
	 * @param callable|null $viber_plan  Given the trigger, placeholder values and SMS text, returns a ViberPlan or null to send an SMS.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Enqueuer $enqueuer,
		private readonly Normalizer $normalizer,
		private readonly StormGuard $storm,
		private readonly SendingState $state,
		private readonly Notifier $notifier,
		private readonly Clock $clock,
		private readonly string $site_token,
		private readonly Rules $rules,
		callable $allow,
		?callable $viber_plan = null
	) {
		$this->allow      = $allow;
		$this->viber_plan = $viber_plan;
	}

	/**
	 * Handle an order reaching a status.
	 *
	 * @param OrderFacts $facts   What we know about the order.
	 * @param Trigger    $trigger Configuration for the new status.
	 * @return string One of the RESULT_* constants.
	 */
	public function handle( OrderFacts $facts, Trigger $trigger ): string {
		if ( ! $trigger->enabled ) {
			return self::RESULT_DISABLED;
		}

		$reference = Reference::for_event( $this->site_token, $facts->order_id, $facts->status );
		if ( null !== $this->store->find_by_reference( $reference ) ) {
			return self::RESULT_DUPLICATE;
		}

		$message            = new Message();
		$message->order_id  = $facts->order_id;
		$message->event     = $facts->status;
		$message->reference = $reference;

		$reason = $this->skip_reason( $facts, $message );

		if ( null === $reason ) {
			$message->body = Placeholders::render( $trigger->template, $facts->values );

			if ( '' === $message->body ) {
				$reason = SkipReason::EMPTY_MESSAGE;
			} elseif ( ! ( $this->allow )( $facts ) ) {
				$reason = SkipReason::VETOED;
			}
		}

		if ( null !== $reason ) {
			$message->body = '';
			$this->enqueuer->record_skipped( $message, $reason );
			$this->notifier->note( $message, Notifier::NOTE_SKIPPED, array( 'reason' => $reason ) );

			return self::RESULT_SKIPPED;
		}

		$plan = null !== $this->viber_plan ? ( $this->viber_plan )( $trigger, $facts->values, $message->body ) : null;

		if ( $plan instanceof ViberPlan ) {
			$message->channel = 'viber';
			$message->viber   = $plan->payload();
			$message->body    = $plan->text;
		}

		$this->storm->check();
		$this->enqueuer->enqueue( $message, max( 0, $trigger->delay_minutes ) * MINUTE_IN_SECONDS );

		return self::RESULT_QUEUED;
	}

	/**
	 * First reason the order must not get a message, setting the recipient when it can.
	 *
	 * @param OrderFacts $facts   Order facts.
	 * @param Message    $message Message being prepared.
	 * @return string|null A SkipReason constant, or null if the order is eligible.
	 */
	private function skip_reason( OrderFacts $facts, Message $message ): ?string {
		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			return SkipReason::SITE_UNCONFIRMED;
		}

		if ( $this->rules->max_age_days > 0 && $this->clock->now() - $facts->created_at > $this->rules->max_age_days * DAY_IN_SECONDS ) {
			return SkipReason::TOO_OLD;
		}

		if ( $this->rules->skip_admin_created && 'admin' === $facts->created_via ) {
			return SkipReason::ADMIN_CREATED;
		}

		if ( '' === trim( $facts->phone ) ) {
			return SkipReason::NO_PHONE;
		}

		$number = $this->normalizer->normalize( $facts->phone, $facts->calling_code );
		if ( ! $number->is_valid() ) {
			return SkipReason::INVALID_PHONE;
		}

		$message->recipient = (string) $number->e164;

		if ( $this->rules->mobile_only && false === $this->normalizer->is_mobile( $message->recipient ) ) {
			return SkipReason::NOT_MOBILE;
		}

		return null;
	}
}
