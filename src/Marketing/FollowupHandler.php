<?php
/**
 * Schedules the review request after a completed order.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\Rules;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * When an order is completed, queues a promotional message for N days later. The
 * message is only created for a customer who agreed, and every message carries an
 * opt-out link. Consent and the order status are checked again at send time.
 */
final class FollowupHandler {

	public const EVENT = 'followup_review';

	public const RESULT_DISABLED  = 'disabled';
	public const RESULT_DUPLICATE = 'duplicate';
	public const RESULT_QUEUED    = 'queued';
	public const RESULT_SKIPPED   = 'skipped';

	private const MAX_DAYS = 60;

	/**
	 * Constructor.
	 *
	 * @param MessageStore   $store      Message log.
	 * @param Enqueuer       $enqueuer   Queues or records messages.
	 * @param Normalizer     $normalizer Phone normaliser.
	 * @param Consent        $consent    Consent rules.
	 * @param OptOutToken    $token      Opt-out link tokens.
	 * @param StormGuard     $storm      Mass-send guard.
	 * @param SendingState   $state      Pause switch.
	 * @param Notifier       $notifier   Order note writer.
	 * @param Clock          $clock      Time source.
	 * @param string         $site_token Site token used in references.
	 * @param Rules          $rules      Eligibility rules shared with order messages.
	 * @param FollowupConfig $config     Follow-up settings.
	 * @param string         $site_url   Shop address, for the opt-out link.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Enqueuer $enqueuer,
		private readonly Normalizer $normalizer,
		private readonly Consent $consent,
		private readonly OptOutToken $token,
		private readonly StormGuard $storm,
		private readonly SendingState $state,
		private readonly Notifier $notifier,
		private readonly Clock $clock,
		private readonly string $site_token,
		private readonly Rules $rules,
		private readonly FollowupConfig $config,
		private readonly string $site_url
	) {}

	/**
	 * Handle an order that was just completed.
	 *
	 * @param OrderFacts $facts Order facts.
	 * @return string One of the RESULT_* constants.
	 */
	public function handle( OrderFacts $facts ): string {
		if ( ! $this->config->enabled ) {
			return self::RESULT_DISABLED;
		}

		$reference = Reference::for_event( $this->site_token, $facts->order_id, self::EVENT );
		if ( null !== $this->store->find_by_reference( $reference ) ) {
			return self::RESULT_DUPLICATE;
		}

		$message            = new Message();
		$message->order_id  = $facts->order_id;
		$message->event     = self::EVENT;
		$message->kind      = Kind::Marketing;
		$message->reference = $reference;

		$reason = $this->skip_reason( $facts, $message );

		if ( null !== $reason ) {
			$this->enqueuer->record_skipped( $message, $reason );
			$this->notifier->note( $message, Notifier::NOTE_SKIPPED, array( 'reason' => $reason ) );

			return self::RESULT_SKIPPED;
		}

		$message->body = $this->body( $facts, $message->recipient );

		$this->storm->check();
		$this->enqueuer->enqueue( $message, max( 1, min( self::MAX_DAYS, $this->config->days ) ) * DAY_IN_SECONDS );

		return self::RESULT_QUEUED;
	}

	/**
	 * First reason no promotional message may be created, setting the recipient when it can.
	 *
	 * @param OrderFacts $facts   Order facts.
	 * @param Message    $message Message being prepared.
	 */
	private function skip_reason( OrderFacts $facts, Message $message ): ?string {
		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			return SkipReason::SITE_UNCONFIRMED;
		}

		if ( $this->rules->max_age_days > 0 && $this->clock->now() - $facts->created_at > $this->rules->max_age_days * DAY_IN_SECONDS ) {
			return SkipReason::TOO_OLD;
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

		return $this->consent->has_consent( $message->recipient ) ? null : SkipReason::NO_CONSENT;
	}

	/**
	 * Render the message, making sure it always carries the opt-out link.
	 *
	 * @param OrderFacts $facts     Order facts.
	 * @param string     $recipient Number in E.164.
	 */
	private function body( OrderFacts $facts, string $recipient ): string {
		$template = $this->config->template;

		if ( false === stripos( $template, '{optout_link}' ) ) {
			$template = rtrim( $template ) . ' ' . $this->config->optout_label . ' {optout_link}';
		}

		$values                = $facts->values;
		$values['optout_link'] = rtrim( $this->site_url, '/' ) . '/?po=' . $this->token->create( $this->consent->hash( $recipient ) );

		return Placeholders::render( $template, $values );
	}
}
