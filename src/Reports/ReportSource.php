<?php
/**
 * Where reports read their numbers from.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Reports;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by the message log repository and by a fake in tests.
 */
interface ReportSource {

	/**
	 * Messages created since a time, counted by status, channel and delivering channel.
	 *
	 * @param int $since Unix time.
	 * @return array<int,array{status:string,channel:string,delivered_via:?string,count:int,credits:float}>
	 */
	public function summary( int $since ): array;

	/**
	 * The latest failed or unconfirmed messages.
	 *
	 * @param int $limit Maximum rows.
	 * @return Message[]
	 */
	public function recent_problems( int $limit ): array;
}
