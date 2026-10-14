<?php
/**
 * The Connection tab.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Countries;
use Povikvane\WooCommerce\Support\Language;
use Povikvane\WooCommerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Account credentials, defaults, delivery reports and diagnostics.
 */
final class ConnectionPanel {

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param DeliveryPanel $delivery Delivery reports card.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DeliveryPanel $delivery
	) {}

	/**
	 * Print the panel. Must be called inside the settings form.
	 *
	 * @param array<string,mixed> $values Current settings.
	 */
	public function render( array $values ): void {
		$name   = Settings::OPTION;
		$source = $this->settings->api_key_source();

		Ui::card_open( __( 'Your Povikvane account', 'povikvane-for-woocommerce' ), __( 'Find your API key and service ID in Povikvane under Settings > API. They stay on this site, and the key is stored encrypted.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'API key', 'povikvane-for-woocommerce' ), 'povikvane_api_key' );

		if ( Settings::KEY_SOURCE_CONSTANT === $source ) {
			echo '<p>' . Ui::pill( __( 'Set in wp-config.php', 'povikvane-for-woocommerce' ), 'info' ) . ' <code>POVIKVANE_API_KEY</code></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
		} else {
			printf(
				'<input type="password" id="povikvane_api_key" name="%1$s[api_key]" value="" autocomplete="new-password" placeholder="%2$s">',
				esc_attr( $name ),
				esc_attr( Settings::KEY_SOURCE_STORED === $source ? __( 'Saved. Type a new key to replace it.', 'povikvane-for-woocommerce' ) : '' )
			);

			if ( Settings::KEY_SOURCE_UNREADABLE === $source ) {
				Ui::note( __( 'The saved key can no longer be read (the site security keys changed). Enter it again.', 'povikvane-for-woocommerce' ), 'warn' );
			}

			if ( Settings::KEY_SOURCE_STORED === $source ) {
				Ui::toggle( $name . '[remove_api_key]', false, __( 'Remove the saved key', 'povikvane-for-woocommerce' ) );
			}
		}

		Ui::field_close();

		Ui::field_open( __( 'Service ID', 'povikvane-for-woocommerce' ), 'povikvane_service_id' );
		printf(
			'<input type="text" id="povikvane_service_id" name="%1$s[service_id]" value="%2$s" autocomplete="off" class="pvk-mono">',
			esc_attr( $name ),
			esc_attr( (string) $values['service_id'] )
		);
		Ui::field_close();

		Ui::card_close( '<a href="' . esc_url( 'https://app.povikvane.com/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Povikvane', 'povikvane-for-woocommerce' ) . '</a>' );

		Ui::card_open( __( 'Defaults', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Default country', 'povikvane-for-woocommerce' ), 'povikvane_default_country', __( 'Used to read phone numbers written without a country code.', 'povikvane-for-woocommerce' ) );
		printf( '<select id="povikvane_default_country" name="%s[default_country]">', esc_attr( $name ) );
		foreach ( Countries::all() as $iso => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $iso ), selected( $values['default_country'], (string) $iso, false ), esc_html( (string) $label ) );
		}
		echo '</select>';
		Ui::field_close();

		Ui::field_open( __( 'Language', 'povikvane-for-woocommerce' ), 'povikvane_language', __( 'Used for these screens and for the default message wording. "Automatic" follows the site language.', 'povikvane-for-woocommerce' ) );
		printf( '<select id="povikvane_language" name="%s[plugin_language]">', esc_attr( $name ) );
		foreach ( array(
			Language::AUTO      => __( 'Automatic (follow the site language)', 'povikvane-for-woocommerce' ),
			Language::BULGARIAN => 'Български',
			Language::ENGLISH   => 'English',
		) as $code => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $values['plugin_language'], $code, false ), esc_html( $label ) );
		}
		echo '</select>';
		Ui::field_close();

		Ui::field_open( __( 'Drop waiting messages after', 'povikvane-for-woocommerce' ), 'povikvane_max_age', __( 'A status message that arrives a day late is worse than none, so messages stuck longer than this are not sent.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><input type="number" id="povikvane_max_age" name="%1$s[max_age_hours]" value="%2$d" min="1" max="168"> <span>%3$s</span></div>',
			esc_attr( $name ),
			(int) $values['max_age_hours'],
			esc_html__( 'hours', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::card_close();

		$this->delivery->render();

		Ui::card_open( __( 'Diagnostics', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Debug logging', 'povikvane-for-woocommerce' ) );
		Ui::toggle(
			$name . '[debug_log]',
			(bool) $values['debug_log'],
			__( 'Write detailed diagnostics to the WooCommerce log', 'povikvane-for-woocommerce' ),
			__( 'Never includes the API key or message text; phone numbers are shortened. Warnings and errors are always logged.', 'povikvane-for-woocommerce' )
		);
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ), esc_html__( 'View logs', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::card_close();
	}
}
