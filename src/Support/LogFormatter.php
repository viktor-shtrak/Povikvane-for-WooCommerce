<?php
/**
 * Log line formatting.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a message and context into one safe line.
 */
final class LogFormatter {

	private const MAX_VALUE_LENGTH = 200;

	/**
	 * Context keys containing these words are never printed.
	 */
	private const SECRET_WORDS = array( 'key', 'token', 'secret', 'authorization', 'password', 'bearer' );

	/**
	 * Format a log line.
	 *
	 * @param string              $message Short description.
	 * @param array<string,mixed> $context Details.
	 */
	public static function format( string $message, array $context ): string {
		$parts = array();

		foreach ( $context as $name => $value ) {
			$parts[] = $name . '=' . ( self::is_secret( (string) $name ) ? '[hidden]' : self::value( $value ) );
		}

		return array() === $parts ? $message : $message . ' | ' . implode( ' ', $parts );
	}

	/**
	 * Whether a context key looks like it holds a secret.
	 *
	 * @param string $name Context key.
	 */
	private static function is_secret( string $name ): bool {
		$name = strtolower( $name );

		foreach ( self::SECRET_WORDS as $word ) {
			if ( str_contains( $name, $word ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a value on one line, shortened.
	 *
	 * @param mixed $value Value.
	 */
	private static function value( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( ! is_scalar( $value ) ) {
			return '[' . gettype( $value ) . ']';
		}

		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );

		return strlen( $text ) > self::MAX_VALUE_LENGTH ? substr( $text, 0, self::MAX_VALUE_LENGTH ) . '…' : $text;
	}
}
