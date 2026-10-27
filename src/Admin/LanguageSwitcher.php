<?php
/**
 * Language choice in the header of every Povikvane screen.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Lets each person pick the language of the plugin screens for themselves. The
 * choice is kept per user, so it never changes the wording of customer messages,
 * which follows the Language setting. "Default" follows that setting, which in
 * turn follows the person's own WordPress language unless it is forced.
 */
final class LanguageSwitcher {

	public const ACTION = 'povikvane_set_language';

	/**
	 * Register the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Print the small language form.
	 */
	public static function render(): void {
		$current = Language::user_setting();

		echo '<form class="pvk-header__lang" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<label class="screen-reader-text" for="pvk-language">' . esc_html__( 'Language of these screens', 'povikvane-for-woocommerce' ) . '</label>';
		echo '<span class="pvk-header__lang-box"><svg class="pvk-header__globe" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.6 2.4 4 5.6 4 9s-1.4 6.6-4 9c-2.6-2.4-4-5.6-4-9s1.4-6.6 4-9z"/></svg>';
		echo '<select id="pvk-language" name="language" data-pvk-native data-povikvane-autosubmit>';

		foreach ( array(
			''                  => __( 'Language: automatic', 'povikvane-for-woocommerce' ),
			Language::ENGLISH   => 'English',
			Language::BULGARIAN => 'Български',
		) as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) );
		}

		echo '</select></span><noscript><button type="submit" class="pvk-btn">' . esc_html__( 'Save', 'povikvane-for-woocommerce' ) . '</button></noscript></form>';
	}

	/**
	 * Save the choice for the current user and go back.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$choice = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( in_array( $choice, array( Language::ENGLISH, Language::BULGARIAN ), true ) ) {
			update_user_meta( get_current_user_id(), Language::USER_META, $choice );
		} else {
			delete_user_meta( get_current_user_id(), Language::USER_META );
		}

		$back = wp_get_referer();

		wp_safe_redirect( false !== $back ? $back : admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}
}
