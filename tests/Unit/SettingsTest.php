<?php
/**
 * Tests for plugin settings and their defaults.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Support\Crypto;
use Povikvane\WooCommerce\Support\Settings;

/**
 * Every setting must have a default and a typed value.
 */
final class SettingsTest extends TestCase {

	/**
	 * Start each test with no stored settings.
	 */
	protected function setUp(): void {
		$GLOBALS['povikvane_test_options'] = array();
	}

	/**
	 * Build settings.
	 */
	private function settings(): Settings {
		return new Settings( new Crypto( 'test' ) );
	}

	/**
	 * Out of the box every setting has its documented default.
	 */
	public function test_defaults(): void {
		$all = $this->settings()->all();

		$this->assertSame(
			array(
				'service_id'            => '',
				'default_country'       => 'BG',
				'max_age_hours'         => 24,
				'debug_log'             => false,
				'max_order_age_days'    => 7,
				'skip_admin_created'    => false,
				'mobile_only'           => true,
				'storm_threshold'       => 50,
				'low_balance_threshold' => 50,
				'retention_body_days'   => 90,
				'retention_row_days'    => 365,
				'export_full_numbers'   => false,
				'delete_on_uninstall'   => false,
				'viber_enabled'         => false,
				'viber_sms_fallback'    => false,
				'plugin_language'       => 'bg_BG',
			),
			$all
		);
	}

	/**
	 * Every key the settings page reads exists, so no screen can hit an undefined key.
	 */
	public function test_every_accessor_works_without_stored_settings(): void {
		$settings = $this->settings();

		$this->assertSame( 50, $settings->low_balance_threshold(), 'the low-credits warning is on by default' );
		$this->assertSame( 50, $settings->storm_threshold() );
		$this->assertSame( 90, $settings->retention_body_days() );
		$this->assertSame( 365, $settings->retention_row_days() );
		$this->assertFalse( $settings->export_full_numbers() );
		$this->assertFalse( $settings->debug_logging() );
		$this->assertSame( 7, $settings->rules()->max_age_days );
		$this->assertTrue( $settings->rules()->mobile_only );
		$this->assertSame( 86400, $settings->max_age_seconds() );
		$this->assertSame( 'BG', $settings->default_country() );
	}

	/**
	 * Stored values override defaults and are cast to their types.
	 */
	public function test_stored_values_override_defaults(): void {
		update_option(
			Settings::OPTION,
			array(
				'low_balance_threshold' => '120',
				'mobile_only'           => '',
				'storm_threshold'       => 0,
				'service_id'            => 'svc-1',
			)
		);

		$settings = $this->settings();

		$this->assertSame( 120, $settings->low_balance_threshold() );
		$this->assertFalse( $settings->rules()->mobile_only );
		$this->assertSame( 0, $settings->storm_threshold() );
		$this->assertSame( 'svc-1', $settings->service_id() );
		$this->assertSame( 90, $settings->retention_body_days(), 'unset keys keep their default' );
	}

	/**
	 * Negative numbers never reach the rest of the plugin.
	 */
	public function test_negative_numbers_are_clamped(): void {
		update_option(
			Settings::OPTION,
			array(
				'low_balance_threshold' => -5,
				'storm_threshold'       => -1,
				'retention_body_days'   => -3,
			)
		);

		$settings = $this->settings();

		$this->assertSame( 0, $settings->low_balance_threshold() );
		$this->assertSame( 0, $settings->storm_threshold() );
		$this->assertSame( 0, $settings->retention_body_days() );
	}

	/**
	 * A site saved with landlines allowed gets the safe default once; a later choice is kept.
	 */
	public function test_landline_default_is_applied_once(): void {
		$GLOBALS['povikvane_test_options'][ Settings::OPTION ] = array(
			'mobile_only'     => false,
			'service_id'      => 'abc',
			'plugin_language' => 'auto',
		);

		$this->settings()->apply_defaults_once();
		$this->assertTrue( $this->settings()->all()['mobile_only'] );
		$this->assertSame( 'bg_BG', $this->settings()->all()['plugin_language'] );
		$this->assertSame( 'abc', $this->settings()->all()['service_id'] );

		$GLOBALS['povikvane_test_options'][ Settings::OPTION ]['mobile_only'] = false;
		$this->settings()->apply_defaults_once();
		$this->assertFalse( $this->settings()->all()['mobile_only'] );
	}
}
