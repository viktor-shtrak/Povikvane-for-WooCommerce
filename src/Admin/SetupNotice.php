<?php
/**
 * Reminder to finish setting up.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Setup\SetupChecklist;
use Povikvane\WooCommerce\Setup\SetupProgress;
use Povikvane\WooCommerce\Setup\SetupState;

defined( 'ABSPATH' ) || exit;

/**
 * A dismissible notice on the dashboard, plugins and WooCommerce screens until
 * the required steps are done. It never appears on the plugin's own screens, and
 * it disappears for good once setup is complete.
 */
final class SetupNotice {

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Constructor.
	 *
	 * @param SetupState    $state    Setup flags.
	 * @param SetupProgress $progress Setup checklist.
	 */
	public function __construct(
		private readonly SetupState $state,
		private readonly SetupProgress $progress
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_povikvane_dismiss_setup', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Show the reminder when it is useful.
	 */
	public function render(): void {
		if ( $this->state->is_completed() || $this->state->is_dismissed() || ! current_user_can( self::CAPABILITY ) || ! $this->on_relevant_screen() ) {
			return;
		}

		$items = $this->progress->checklist();

		if ( SetupChecklist::is_complete( $items ) ) {
			$this->state->complete();
			return;
		}

		$required = array_filter( $items, static fn( array $item ): bool => $item['required'] );
		$done     = count( array_filter( $required, static fn( array $item ): bool => $item['done'] ) );

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Povikvane', 'povikvane-for-woocommerce' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: steps done, 2: total steps. */
				__( 'is almost ready: %1$d of %2$d steps done. Finish setting up so your customers get their order messages.', 'povikvane-for-woocommerce' ),
				$done,
				count( $required )
			)
		) . '</p><p>';

		printf( '<a class="button button-primary" href="%1$s">%2$s</a> ', esc_url( admin_url( 'admin.php?page=' . SetupWizard::SLUG ) ), esc_html__( 'Continue setup', 'povikvane-for-woocommerce' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="povikvane_dismiss_setup">';
		wp_nonce_field( 'povikvane_dismiss_setup' );
		printf( '<button type="submit" class="button-link">%s</button></form></p></div>', esc_html__( 'Dismiss', 'povikvane-for-woocommerce' ) );
	}

	/**
	 * Stop showing the reminder.
	 */
	public function handle_dismiss(): void {
		check_admin_referer( 'povikvane_dismiss_setup' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$this->state->dismiss();

		wp_safe_redirect( wp_get_referer() ? (string) wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Whether the current screen is one where the reminder belongs.
	 */
	private function on_relevant_screen(): bool {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return false;
		}

		if ( str_contains( $screen->id, 'povikvane' ) ) {
			return false;
		}

		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || str_contains( $screen->id, 'woocommerce' ) || str_contains( $screen->id, 'shop_order' );
	}
}
