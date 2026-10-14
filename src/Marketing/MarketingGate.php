<?php
/**
 * Last-moment checks for promotional messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\SendGate;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Consent is checked when a promotional message is about to go out, not when it
 * was scheduled, so a withdrawal in between is honoured immediately.
 */
final class MarketingGate implements SendGate {

	/**
	 * Constructor.
	 *
	 * @param bool         $enabled Whether promotional messages are switched on.
	 * @param Consent      $consent Consent rules.
	 * @param FrequencyCap $cap     Frequency limit.
	 * @param Clock        $clock   Time source.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly Consent $consent,
		private readonly FrequencyCap $cap,
		private readonly Clock $clock
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message about to be sent.
	 */
	public function blocks( Message $message ): ?string {
		if ( Kind::Marketing !== $message->kind ) {
			return null;
		}

		if ( ! $this->enabled ) {
			return SkipReason::MARKETING_OFF;
		}

		if ( ! $this->consent->has_consent( $message->recipient ) ) {
			return SkipReason::NO_CONSENT;
		}

		return $this->cap->reached( $message->recipient, $this->clock->now() ) ? SkipReason::FREQUENCY_CAP : null;
	}
}
