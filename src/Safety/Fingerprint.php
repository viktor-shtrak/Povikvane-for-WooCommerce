<?php
/**
 * Site identity fingerprint.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * A short hash of a site's address. A staging copy of the database has a
 * different address, so its fingerprint differs from the stored one. The scheme,
 * a leading "www." and a trailing slash are ignored, so moving to HTTPS does not
 * count as a different site.
 */
final class Fingerprint {

	/**
	 * Fingerprint of a site URL.
	 *
	 * @param string $url Site URL.
	 */
	public static function of( string $url ): string {
		$url   = strtolower( trim( $url ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return substr( hash( 'sha256', $url ), 0, 16 );
		}

		$host = (string) preg_replace( '/^www\./', '', $parts['host'] );
		$port = isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ? ':' . $parts['port'] : '';
		$path = rtrim( $parts['path'] ?? '', '/' );

		return substr( hash( 'sha256', $host . $port . $path ), 0, 16 );
	}
}
