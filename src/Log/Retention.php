<?php
/**
 * Data retention.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

use Povikvane\WooCommerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Message text is personal data, so it is erased after a while. Whole rows
 * (order, status, credits) are kept longer for billing questions, then deleted.
 * Messages that are still waiting to be sent are never touched.
 */
final class Retention {

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store     Message log.
	 * @param Clock        $clock     Time source.
	 * @param int          $body_days Erase message text after this many days (0 = never).
	 * @param int          $row_days  Delete rows after this many days (0 = never).
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Clock $clock,
		private readonly int $body_days,
		private readonly int $row_days
	) {}

	/**
	 * Apply the retention rules.
	 *
	 * @return array{bodies:int,rows:int} How many messages were erased and deleted.
	 */
	public function run(): array {
		$now    = $this->clock->now();
		$result = array(
			'bodies' => 0,
			'rows'   => 0,
		);

		if ( $this->body_days > 0 ) {
			$result['bodies'] = $this->store->purge_bodies( $now - $this->body_days * 86400 );
		}

		if ( $this->row_days > 0 ) {
			$result['rows'] = $this->store->delete_rows( $now - $this->row_days * 86400 );
		}

		return $result;
	}
}
