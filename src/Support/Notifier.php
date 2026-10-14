<?php
/**
 * Human-facing notes about message events.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Writes order notes. Receives a code and parameters so translation stays out of
 * the dispatcher.
 */
interface Notifier {

	public const NOTE_QUEUED          = 'queued';
	public const NOTE_FAILED          = 'failed';
	public const NOTE_UNCONFIRMED     = 'unconfirmed';
	public const NOTE_HELD_CREDITS    = 'held_credits';
	public const NOTE_EXPIRED         = 'expired';
	public const NOTE_SKIPPED         = 'skipped';
	public const NOTE_DELIVERED       = 'delivered';
	public const NOTE_DELIVERY_FAILED = 'delivery_failed';

	/**
	 * Add a note for a message event.
	 *
	 * @param Message             $message Message the note is about.
	 * @param string              $code    One of the NOTE_* constants.
	 * @param array<string,mixed> $params  Values for the note text.
	 */
	public function note( Message $message, string $code, array $params = array() ): void;
}
