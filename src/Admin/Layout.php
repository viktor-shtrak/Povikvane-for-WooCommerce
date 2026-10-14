<?php
/**
 * The shell shared by the Povikvane admin screens.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Header with the logo, the tab bar, and the buttons that post to admin-post.php.
 * The settings screen is one big form, and forms cannot be nested, so those
 * buttons point at small forms printed after it (HTML's "form" attribute).
 */
final class Layout {

	/**
	 * Forms waiting to be printed, keyed by element ID.
	 *
	 * @var array<string,string>
	 */
	private static array $forms = array();

	/**
	 * Open the page.
	 *
	 * @param string $modifier Extra CSS class for the root element.
	 * @param string $attrs    Extra attributes for the root element, already escaped.
	 */
	public static function open( string $modifier = '', string $attrs = '' ): void {
		self::$forms = array();

		echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__( 'Povikvane SMS', 'povikvane-for-woocommerce' ) . '</h1><hr class="wp-header-end">';

		settings_errors();
		Flash::render();

		echo '<div class="pvk' . ( '' !== $modifier ? ' ' . esc_attr( $modifier ) : '' ) . '"' . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documented as already escaped.

		printf(
			'<header class="pvk-header"><img class="pvk-header__logo" src="%1$s" alt="Povikvane" width="140" height="24"><span class="pvk-header__product">%2$s</span><span class="pvk-header__version">%3$s</span></header>',
			esc_url( plugins_url( 'assets/img/povikvane-logo.svg', PLUGIN_FILE ) ),
			esc_html__( 'SMS for WooCommerce', 'povikvane-for-woocommerce' ),
			esc_html( 'v' . VERSION )
		);
	}

	/**
	 * Print the tab bar. Tabs with a URL are links to other screens; the rest are
	 * switched on this page by script (without script, every panel is shown).
	 *
	 * @param array<int,array{id:string,label:string,url?:string,dot?:bool,current?:bool}> $tabs Tabs in order.
	 * @param string                                                                       $current ID of the tab shown first.
	 */
	public static function tabs( array $tabs, string $current ): void {
		echo '<nav class="pvk-tabs" role="tablist" aria-label="' . esc_attr__( 'Povikvane SMS sections', 'povikvane-for-woocommerce' ) . '">';

		foreach ( $tabs as $tab ) {
			$dot = ! empty( $tab['dot'] ) ? '<span class="pvk-tab__dot" title="' . esc_attr__( 'Needs attention', 'povikvane-for-woocommerce' ) . '"></span>' : '';

			if ( isset( $tab['url'] ) ) {
				printf( '<a class="pvk-tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>', ! empty( $tab['current'] ) ? ' is-current' : '', esc_url( $tab['url'] ), ! empty( $tab['current'] ) ? ' aria-current="page"' : '', esc_html( $tab['label'] ), $dot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				continue;
			}

			printf(
				'<button type="button" role="tab" class="pvk-tab" id="pvk-tab-%1$s" aria-controls="pvk-panel-%1$s" aria-selected="%2$s" data-pvk-tab="%1$s">%3$s%4$s</button>',
				esc_attr( $tab['id'] ),
				$tab['id'] === $current ? 'true' : 'false',
				esc_html( $tab['label'] ),
				$dot // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		echo '</nav>';
	}

	/**
	 * Print a button that submits a small admin-post.php form kept outside the main form.
	 *
	 * @param string               $label        Button text.
	 * @param string               $action       admin-post action (also the nonce action).
	 * @param array<string,string> $fields       Extra hidden fields.
	 * @param string               $css_class    CSS classes for the button.
	 * @param string               $confirm_text If set, ask for confirmation first.
	 */
	public static function action_button( string $label, string $action, array $fields = array(), string $css_class = 'pvk-btn', string $confirm_text = '' ): void {
		$id = self::register_form( $action, $fields );

		printf(
			'<button type="submit" form="%1$s" class="%2$s"%3$s>%4$s</button>',
			esc_attr( $id ),
			esc_attr( $css_class ),
			'' !== $confirm_text ? ' data-povikvane-confirm="' . esc_attr( $confirm_text ) . '"' : '',
			esc_html( $label )
		);
	}

	/**
	 * Register a small admin-post.php form to be printed after the page, and return
	 * its ID. Inputs and buttons join it with the HTML "form" attribute.
	 *
	 * @param string               $action admin-post action (also the nonce action).
	 * @param array<string,string> $fields Extra hidden fields.
	 * @return string Element ID of the form.
	 */
	public static function register_form( string $action, array $fields = array() ): string {
		$id = 'pvk-form-' . substr( md5( $action . wp_json_encode( $fields ) ), 0, 10 );

		if ( ! isset( self::$forms[ $id ] ) ) {
			$html = '<form method="post" id="' . esc_attr( $id ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

			$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
			$html .= wp_nonce_field( $action, '_wpnonce', true, false );

			foreach ( $fields as $name => $value ) {
				$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}

			self::$forms[ $id ] = $html . '</form>';
		}

		return $id;
	}

	/**
	 * Close the page and print the forms the buttons point at.
	 */
	public static function close(): void {
		echo '</div></div>';

		foreach ( self::$forms as $form ) {
			echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in action_button().
		}

		self::$forms = array();
	}
}
