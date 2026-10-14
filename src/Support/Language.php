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

		return is_array( $stored ) ? self::normalize( (string) ( $stored['plugin_language'] ?? self::AUTO ) ) : self::AUTO;
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
			static fn( $locale, $domain ) => self::DOMAIN === $domain ? self::resolve( self::setting(), (string) $locale ) : $locale,
			10,
			2
		);

		add_action(
			'init',
			static function (): void {
				// The bundled translations are loaded directly so the Language setting can override the site language.
				$locale = (string) apply_filters( 'plugin_locale', determine_locale(), self::DOMAIN );
				$file   = PLUGIN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';

				if ( is_readable( $file ) ) {
					load_textdomain( self::DOMAIN, $file, $locale );
				}
			},
			0
		);
	}
}
