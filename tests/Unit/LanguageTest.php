<?php
/**
 * Tests for language selection.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Support\Language;
use Povikvane\WooCommerce\Support\Settings;

/**
 * Which language the plugin speaks.
 */
final class LanguageTest extends TestCase {

	/**
	 * Start with no stored settings and an English site.
	 */
	protected function setUp(): void {
		$GLOBALS['povikvane_test_options'] = array();
		$GLOBALS['povikvane_test_locale']  = 'en_US';
	}

	/**
	 * Cases: setting, locale WordPress would use, result.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function resolutions(): array {
		return array(
			'auto follows a Bulgarian site' => array( 'auto', 'bg_BG', 'bg_BG' ),
			'auto follows an English site'  => array( 'auto', 'en_US', 'en_US' ),
			'auto follows another language' => array( 'auto', 'de_DE', 'de_DE' ),
			'Bulgarian on an English site'  => array( 'bg_BG', 'en_US', 'bg_BG' ),
			'English on a Bulgarian site'   => array( 'en_US', 'bg_BG', 'en_US' ),
			'unknown setting is automatic'  => array( 'fr_FR', 'de_DE', 'de_DE' ),
			'empty setting is automatic'    => array( '', 'bg_BG', 'bg_BG' ),
		);
	}

	/**
	 * The setting and the WordPress locale decide the language.
	 *
	 * @param string $setting   Setting.
	 * @param string $wp_locale WordPress locale.
	 * @param string $expected  Expected locale.
	 */
	#[DataProvider( 'resolutions' )]
	public function test_resolve( string $setting, string $wp_locale, string $expected ): void {
		$this->assertSame( $expected, Language::resolve( $setting, $wp_locale ) );
	}

	/**
	 * Only known values are accepted.
	 */
	public function test_normalize_accepts_only_known_values(): void {
		$this->assertSame( 'bg_BG', Language::normalize( 'bg_BG' ) );
		$this->assertSame( 'en_US', Language::normalize( 'en_US' ) );
		$this->assertSame( 'auto', Language::normalize( 'auto' ) );
		$this->assertSame( 'auto', Language::normalize( '<script>' ) );
		$this->assertSame( array( 'auto', 'bg_BG', 'en_US' ), Language::choices() );
	}

	/**
	 * Bulgarian is recognised however the locale is written.
	 */
	public function test_is_bulgarian(): void {
		$this->assertTrue( Language::is_bulgarian( 'bg_BG' ) );
		$this->assertTrue( Language::is_bulgarian( 'BG' ) );
		$this->assertFalse( Language::is_bulgarian( 'en_US' ) );
		$this->assertFalse( Language::is_bulgarian( 'de_BG_x' ) );
	}

	/**
	 * Without a stored choice the site language is used.
	 */
	public function test_content_locale_follows_the_site_by_default(): void {
		$GLOBALS['povikvane_test_locale'] = 'bg_BG';
		$this->assertSame( 'bg_BG', Language::content_locale() );

		$GLOBALS['povikvane_test_locale'] = 'de_DE';
		$this->assertSame( 'de_DE', Language::content_locale() );
	}

	/**
	 * A stored choice overrides the site language, for the screens and the default wording alike.
	 */
	public function test_stored_choice_overrides_the_site(): void {
		$GLOBALS['povikvane_test_locale'] = 'en_US';
		update_option( Settings::OPTION, array( 'plugin_language' => 'bg_BG' ) );

		$this->assertSame( 'bg_BG', Language::setting() );
		$this->assertSame( 'bg_BG', Language::content_locale() );

		update_option( Settings::OPTION, array( 'plugin_language' => 'not-a-language' ) );
		$this->assertSame( 'auto', Language::setting(), 'a corrupt value falls back to automatic' );
	}
}
