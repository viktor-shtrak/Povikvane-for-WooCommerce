<?php
/**
 * Diagnostic logging.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes diagnostics. Callers must never pass secrets (API key, service ID),
 * full phone numbers or message text; the formatter masks obvious secret keys as
 * a safety net, not as a licence.
 */
interface Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Log a message.
	 *
	 * @param string              $level   One of the level constants.
	 * @param string              $message Short description.
	 * @param array<string,mixed> $context Scalar details.
	 */
	public function log( string $level, string $message, array $context = array() ): void;
}
