<?php
/**
 * Record of webhook arrivals.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers when a webhook last reached the site, so the settings page can show
 * whether the Povikvane webhook is wired up correctly.
 */
interface WebhookActivity {

	/**
	 * Record an authenticated webhook request.
	 *
	 * @param int    $time   Unix time.
	 * @param string $result A WebhookHandler result code.
	 */
	public function record( int $time, string $result ): void;

	/**
	 * The most recent request, if any.
	 *
	 * @return array{time:int,result:string}|null
	 */
	public function last(): ?array;
}
