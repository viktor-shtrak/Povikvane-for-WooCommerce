<?php
/**
 * Identifies this installation.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A short token derived from the site URL and a random per-install salt. It is
 * part of every message reference, so messages from a staging copy of the shop
 * (different URL, same database) can be told apart from the live site's.
 */
final class SiteIdentity {

	public const SALT_OPTION = 'povikvane_install_salt';

	/**
	 * Random per-install salt, created on first use.
	 */
	public static function salt(): string {
		$salt = get_option( self::SALT_OPTION );

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = bin2hex( random_bytes( 16 ) );
			update_option( self::SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	/**
	 * Eight-character site token for references.
	 */
	public static function token(): string {
		return substr( hash( 'sha256', home_url() . '|' . self::salt() ), 0, 8 );
	}
}
