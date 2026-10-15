<?php
/**
 * Tests for promotional settings and the uninstall list.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Balance\OptionBalanceStore;
use Povikvane\WooCommerce\Log\Schema;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Safety\SiteGuard;
use Povikvane\WooCommerce\Setup\SetupState;
use Povikvane\WooCommerce\Support\OptionSendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SiteIdentity;
use Povikvane\WooCommerce\Webhook\OptionActivity;
use Povikvane\WooCommerce\Webhook\Token;

/**
 * Defaults are safe, and uninstall forgets nothing.
 */
final class MarketingSettingsTest extends TestCase {

	/**
	 * Start with no stored settings.
	 */
	protected function setUp(): void {
		$GLOBALS['povikvane_test_options'] = array();
	}

	/**
	 * Out of the box promotions are off, with cautious limits.
	 */
	public function test_defaults_are_safe(): void {
		$settings = new MarketingSettings( 'en_US' );

		$this->assertFalse( $settings->enabled() );
		$this->assertFalse( $settings->followup()->enabled );
		$this->assertSame( 1, $settings->cap_max() );
		$this->assertSame( 7, $settings->cap_days() );
		$this->assertSame( 7, $settings->followup()->days );
		$this->assertStringContainsString( 'opt out', $settings->consent_text() );
		$this->assertSame( 'Stop:', $settings->followup()->optout_label );
		$this->assertSame( 12, strlen( $settings->wording_hash() ) );
		$this->assertSame( 9 * 3600, $settings->quiet_hours( new DateTimeZone( 'UTC' ) )->seconds_until_open( gmmktime( 0, 0, 0, 3, 10, 2026 ) ) );
	}

	/**
	 * The follow-up only runs when both switches are on.
	 */
	public function test_followup_needs_both_switches(): void {
		update_option( MarketingSettings::OPTION, array( 'followup_enabled' => true ) );
		$this->assertFalse( ( new MarketingSettings( 'en_US' ) )->followup()->enabled, 'promotions are still off' );

		update_option(
			MarketingSettings::OPTION,
			array(
				'enabled'          => true,
				'followup_enabled' => true,
			)
		);
		$this->assertTrue( ( new MarketingSettings( 'en_US' ) )->followup()->enabled );
	}

	/**
	 * The wording follows the site language until the owner writes their own.
	 */
	public function test_wording_follows_language_until_customised(): void {
		$bulgarian = new MarketingSettings( 'bg_BG' );
		$english   = new MarketingSettings( 'en_US' );

		$this->assertStringContainsString( 'отпиша', $bulgarian->consent_text() );
		$this->assertNotSame( $bulgarian->wording_hash(), $english->wording_hash(), 'each language has its own default wording' );

		update_option( MarketingSettings::OPTION, array( 'consent_text' => 'My own words' ) );

		$this->assertSame( 'My own words', $bulgarian->consent_text() );
		$this->assertSame( 'My own words', $english->consent_text(), 'custom wording is used in every language' );
	}

	/**
	 * Changing the wording changes the stored fingerprint, so old agreements still show what was seen.
	 */
	public function test_wording_hash_changes_with_the_wording(): void {
		$before = ( new MarketingSettings( 'en_US' ) )->wording_hash();
		update_option( MarketingSettings::OPTION, array( 'consent_text' => 'Different' ) );

		$this->assertNotSame( $before, ( new MarketingSettings( 'en_US' ) )->wording_hash() );
	}

	/**
	 * Out-of-range values are brought back into range.
	 */
	public function test_values_are_clamped(): void {
		update_option(
			MarketingSettings::OPTION,
			array(
				'quiet_open'    => 99,
				'quiet_close'   => -4,
				'cap_max'       => 5000,
				'cap_days'      => -1,
				'followup_days' => 900,
			)
		);
		$all = ( new MarketingSettings( 'en_US' ) )->all();

		$this->assertSame( array( 23, 0, 100, 0, 60 ), array( $all['quiet_open'], $all['quiet_close'], $all['cap_max'], $all['cap_days'], $all['followup_days'] ) );
	}

	/**
	 * Every option the plugin creates is removed on uninstall.
	 */
	public function test_uninstall_removes_every_option(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$options   = array(
			Settings::OPTION,
			Settings::KEY_OPTION,
			Capabilities::OPTION,
			TemplateCatalog::OPTION,
			TriggerRepository::OPTION,
			MarketingSettings::OPTION,
			SetupState::OPTION,
			SiteGuard::OPTION,
			OptionBalanceStore::OPTION,
			OptionBalanceStore::ALERT_OPTION,
			Token::OPTION,
			OptionActivity::OPTION,
			OptionSendingState::OPTION,
			SiteIdentity::SALT_OPTION,
			Schema::OPTION,
		);

		foreach ( $options as $option ) {
			$this->assertStringContainsString( "'" . $option . "'", $uninstall, "uninstall.php should delete $option" );
		}

		$this->assertStringContainsString( 'povikvane_consent', $uninstall, 'the consent table is dropped too' );
		$this->assertStringContainsString( 'povikvane_messages', $uninstall );
	}

	/**
	 * Uninstall unschedules every recurring job.
	 */
	public function test_uninstall_unschedules_every_job(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$hooks     = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/ActionSchedulerScheduler.php' );

		preg_match_all( "/public const [A-Z_]+_HOOK\s*=\s*'([a-z_]+)'/", $hooks, $found );

		$this->assertNotEmpty( $found[1] );

		foreach ( $found[1] as $hook ) {
			$this->assertStringContainsString( "'" . $hook . "'", $uninstall, "uninstall.php should unschedule $hook" );
		}
	}
}
