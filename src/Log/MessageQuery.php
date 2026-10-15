<?php
/**
 * Filters for the message log.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

use Povikvane\WooCommerce\Messages\Status;

defined( 'ABSPATH' ) || exit;

/**
 * What the message log screen and the CSV export are filtered by.
 */
final class MessageQuery {

	/**
	 * Constructor.
	 *
	 * @param Status|null $status Only this status.
	 * @param string      $event  Only this event slug.
	 * @param string      $search Order number, reference or message ID.
	 * @param int|null    $from   Created at or after (Unix time).
	 * @param int|null    $to     Created before (Unix time).
	 * @param bool        $needs_attention Only failed and unconfirmed messages.
	 */
	public function __construct(
		public readonly ?Status $status = null,
		public readonly string $event = '',
		public readonly string $search = '',
		public readonly ?int $from = null,
		public readonly ?int $to = null,
		public readonly bool $needs_attention = false
	) {}
}
