<?php
/**
 * Guard against accidental mass messaging.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Safety;

use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Pauses sending when too many messages are created in a short window, for
 * example after a bulk status change or an order import. The messages are kept,
 * so the shop owner can release or discard them.
 */
final class StormGuard {

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store          Message log.
	 * @param SendingState $state          Pause switch.
	 * @param Clock        $clock          Time source.
	 * @param int          $threshold      Messages in the window that trip the guard (0 = off).
	 * @param int          $window_seconds Length of the window.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly SendingState $state,
		private readonly Clock $clock,
		private readonly int $threshold = 50,
		private readonly int $window_seconds = 600
	) {}

	/**
	 * Call before creating a message. Pauses sending once the window is full.
	 */
	public function check(): void {
		if ( $this->threshold <= 0 || null !== $this->state->paused_reason() ) {
			return;
		}

		if ( $this->store->count_created_since( $this->clock->now() - $this->window_seconds ) >= $this->threshold ) {
			$this->state->pause( SendingState::REASON_STORM );
		}
	}
}
