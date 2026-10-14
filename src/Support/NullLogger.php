<?php
/**
 * Logger that discards everything.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Default logger for code that is not wired to WooCommerce logging.
 */
final class NullLogger implements Logger {

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {}
}
