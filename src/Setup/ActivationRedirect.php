<?php
/**
 * Opens the wizard after activation.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

use Povikvane\WooCommerce\Admin\SetupWizard;

defined( 'ABSPATH' ) || exit;

/**
 * Activation sets a short-lived marker; the next admin page load follows it to the
 * wizard once, unless setup is already finished or the plugin was activated in bulk.
 */
final class ActivationRedirect {

	/**
	 * Constructor.
	 *
	 * @param SetupState $state Setup flags.
	 */
	public function __construct( private readonly SetupState $state ) {}

	/**
	 * Mark that the next admin page should open the wizard.
	 */
	public static function mark(): void {
		if ( ! ( new SetupState() )->is_completed() ) {
			set_transient( SetupState::REDIRECT, 1, 60 );
		}
	}

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Follow the marker, once.
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( SetupState::REDIRECT ) ) {
			return;
		}

		delete_transient( SetupState::REDIRECT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether WordPress is activating several plugins at once.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_woocommerce' ) || $this->state->is_completed() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . SetupWizard::SLUG ) );
		exit;
	}
}
