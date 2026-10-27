<?php
/**
 * Plugin language.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

use const Povikvane\WooCommerce\PLUGIN_DIR;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's source strings are English, and a complete Bulgarian translation
 * ships with it. By default the language follows the site (or the user's own
 * language in the admin). The Language setting can force Bulgarian or English,
 * which suits a Bulgarian shop owner who runs WordPress in English. The same
 * choice picks the language of the default message wording.
 */
final class Language {

	public const DOMAIN    = 'povikvane-for-woocommerce';
	public const AUTO      = 'auto';
	public const BULGARIAN = 'bg_BG';
	public const ENGLISH   = 'en_US';

	/**
	 * User meta key holding one person's own choice of language for these screens.
	 */
	public const USER_META = 'povikvane_language';

	/**
	 * The values the setting accepts.
	 *
	 * @return string[]
	 */
	public static function choices(): array {
		return array( self::AUTO, self::BULGARIAN, self::ENGLISH );
	}

	/**
	 * A stored or submitted value, or "auto" if it is not one we know.
	 *
	 * @param string $value Setting value.
	 */
	public static function normalize( string $value ): string {
		return in_array( $value, self::choices(), true ) ? $value : self::AUTO;
	}

	/**
	 * The locale to use for a setting.
	 *
	 * @param string $setting   The Language setting.
	 * @param string $wp_locale The locale WordPress would use.
	 */
	public static function resolve( string $setting, string $wp_locale ): string {
		return match ( self::normalize( $setting ) ) {
			self::BULGARIAN => self::BULGARIAN,
			self::ENGLISH   => self::ENGLISH,
			default         => $wp_locale,
		};
	}

	/**
	 * Whether a locale is Bulgarian.
	 *
	 * @param string $locale Locale such as "bg_BG".
	 */
	public static function is_bulgarian( string $locale ): bool {
		return str_starts_with( strtolower( $locale ), 'bg' );
	}

	/**
	 * The stored Language setting.
	 */
	public static function setting(): string {
		$stored = get_option( Settings::OPTION, array() );

		return is_array( $stored ) ? self::normalize( (string) ( $stored['plugin_language'] ?? self::BULGARIAN ) ) : self::BULGARIAN;
	}

	/**
	 * One person's own choice, or an empty string when they follow the plugin's setting.
	 *
	 * @param int $user_id User ID; the current user when zero.
	 */
	public static function user_setting( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$value   = $user_id > 0 ? get_user_meta( $user_id, self::USER_META, true ) : '';

		return is_string( $value ) && in_array( $value, array( self::BULGARIAN, self::ENGLISH ), true ) ? $value : '';
	}

	/**
	 * The setting that picks the language of the screens: the person's own choice in
	 * the admin, otherwise the plugin's setting.
	 */
	public static function screen_setting(): string {
		$own = is_admin() ? self::user_setting() : '';

		return '' !== $own ? $own : self::setting();
	}

	/**
	 * Locale for default message wording: the shop's customers' language.
	 */
	public static function content_locale(): string {
		return self::resolve( self::setting(), get_locale() );
	}

	/**
	 * Load the translations for the chosen language.
	 */
	public static function register(): void {
		add_filter(
			'plugin_locale',
			static fn( $locale, $domain ) => self::DOMAIN === $domain ? self::resolve( self::screen_setting(), (string) $locale ) : $locale,
			10,
			2
		);

		add_action(
			'init',
			static function (): void {
				$locale = self::resolve( self::screen_setting(), determine_locale() );
				$file   = PLUGIN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';

				// Whatever WordPress loaded by itself for the site language is dropped, so the choice made here wins.
				unload_textdomain( self::DOMAIN );

				if ( is_readable( $file ) ) {
					// WordPress 6.5 and later only reads a file filed under the locale it is currently using, so the
					// file is filed under that one even when it holds another language.
					$current = class_exists( '\\WP_Translation_Controller' ) ? \WP_Translation_Controller::get_instance()->get_locale() : determine_locale();

					load_textdomain( self::DOMAIN, $file, $current );
				}
			},
			0
		);
	}
}
