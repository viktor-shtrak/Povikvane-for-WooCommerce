<?php
/**
 * Activation tasks.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

use Povikvane\WooCommerce\Log\Schema;
use Povikvane\WooCommerce\Support\SiteIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the database table and the per-install salt.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		Schema::install();
		SiteIdentity::salt();
	}
}
