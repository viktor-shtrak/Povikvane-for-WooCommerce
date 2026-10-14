<?php
/**
 * Last-moment check before a message is sent.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a message be dropped if circumstances changed while it waited, for
 * example the order moved on to another status.
 */
interface SendGate {

	/**
	 * Whether the message must not be sent.
	 *
	 * @param Message $message Message about to be sent.
	 * @return string|null A SkipReason constant, or null to go ahead.
	 */
	public function blocks( Message $message ): ?string;
}
