<?php
/**
 * Activation tasks.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

use Povikvane\WooCommerce\Log\Schema;
use Povikvane\WooCommerce\Safety\Fingerprint;
use Povikvane\WooCommerce\Safety\SiteGuard;
use Povikvane\WooCommerce\Support\SiteIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the database table, the per-install salt and the site fingerprint.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		Schema::install();
		SiteIdentity::salt();

		if ( ! is_string( get_option( SiteGuard::OPTION ) ) || '' === get_option( SiteGuard::OPTION ) ) {
			update_option( SiteGuard::OPTION, Fingerprint::of( home_url() ), false );
		}
	}
}
