<?php
/**
 * WooCommerce log integration.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce > Status > Logs under the source "povikvane". Warnings and
 * errors are always recorded; debug and info only when debug logging is enabled.
 */
final class WcLogger implements Logger {

	public const SOURCE = 'povikvane';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Tells whether debug logging is on.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$important = in_array( $level, array( Logger::WARNING, Logger::ERROR ), true );

		if ( ! $important && ! $this->settings->debug_logging() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, LogFormatter::format( $message, $context ), array( 'source' => self::SOURCE ) );
	}
}
