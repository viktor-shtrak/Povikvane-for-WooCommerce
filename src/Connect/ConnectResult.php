<?php
/**
 * How a connection attempt ended.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Carries no credentials, only what can be shown.
 */
final class ConnectResult {

	public const CONNECTED = 'connected';
	public const DECLINED  = 'declined';
	public const INVALID   = 'invalid';
	public const FAILED    = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string $status    One of the constants above.
	 * @param string $site_name Shop name Povikvane connected.
	 * @param string $origin    Where the flow was started from ("settings" or "wizard").
	 * @param bool   $webhook   Whether Povikvane also registered this site's webhook address.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $site_name = '',
		public readonly string $origin = 'settings',
		public readonly bool $webhook = false
	) {}
}
