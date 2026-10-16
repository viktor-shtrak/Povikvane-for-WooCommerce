<?php
/**
 * Tests for template placeholders and default templates.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Orders\TemplateDefaults;

/**
 * Placeholder rendering and defaults.
 */
final class PlaceholdersTest extends TestCase {

	/**
	 * Known placeholders are replaced.
	 */
	public function test_replaces_known_placeholders(): void {
		$text = Placeholders::render(
			'Hi {first_name}, order #{order_number} ({order_total}). {shop_name}',
			array(
				'first_name'   => 'Maria',
				'order_number' => '1234',
				'order_total'  => '45.00 EUR',
				'shop_name'    => 'Demo Shop',
			)
		);

		$this->assertSame( 'Hi Maria, order #1234 (45.00 EUR). Demo Shop', $text );
	}

	/**
	 * Names are case-insensitive.
	 */
	public function test_placeholder_names_are_case_insensitive(): void {
		$this->assertSame( 'Hi Maria', Placeholders::render( 'Hi {First_Name}', array( 'first_name' => 'Maria' ) ) );
	}

	/**
	 * An empty or unknown value disappears and spacing is tidied, never "{name}".
	 */
	public function test_missing_values_are_removed_and_spacing_tidied(): void {
		$this->assertSame( 'Hi , order', Placeholders::render( 'Hi {first_name}, order', array() ) );
		$this->assertSame( 'Hi Maria', Placeholders::render( 'Hi  {tracking_number}  Maria', array( 'tracking_number' => '' ) ) );
		$this->assertSame( 'a b', Placeholders::render( 'a {nope} b', array() ) );
	}

	/**
	 * Values cannot inject extra lines or control characters into a message.
	 */
	public function test_values_are_kept_on_one_line(): void {
		$text = Placeholders::render( '{first_name}!', array( 'first_name' => "Ma\r\nria\x07" ) );

		$this->assertSame( 'Ma ria!', $text );
	}

	/**
	 * Values containing braces or regex characters are inserted literally.
	 */
	public function test_values_are_inserted_literally(): void {
		$this->assertSame( 'Hi $1 \\1 {x}', Placeholders::render( 'Hi {first_name}', array( 'first_name' => '$1 \\1 {x}' ) ) );
	}

	/**
	 * Unsupported placeholders can be reported to the editor.
	 */
	public function test_unknown_placeholders_are_listed(): void {
		$this->assertSame( array( 'frist_name', 'nope' ), Placeholders::unknown( 'Hi {frist_name} {shop_name} {nope} {nope}' ) );
		$this->assertSame( array(), Placeholders::unknown( 'Order {order_number}' ) );
	}

	/**
	 * Bulgarian sites get Bulgarian text, everything else gets English.
	 */
	public function test_default_template_follows_site_language(): void {
		$this->assertStringContainsString( 'Поръчка', TemplateDefaults::for_status( 'completed', 'bg_BG' ) );
		$this->assertStringContainsString( 'Your order', TemplateDefaults::for_status( 'completed', 'en_US' ) );
		$this->assertStringContainsString( 'Your order', TemplateDefaults::for_status( 'completed', 'de_DE' ) );
	}

	/**
	 * Unknown (custom) statuses still get a sensible default.
	 */
	public function test_custom_status_gets_generic_default(): void {
		$this->assertStringContainsString( 'has changed', TemplateDefaults::for_status( 'awaiting-shipment', 'en_US' ) );
		$this->assertStringContainsString( 'променен', TemplateDefaults::for_status( 'awaiting-shipment', 'bg_BG' ) );
	}

	/**
	 * Only "completed" is switched on out of the box.
	 */
	public function test_only_completed_is_enabled_by_default(): void {
		$this->assertTrue( TemplateDefaults::enabled_by_default( 'completed' ) );

		foreach ( array( 'processing', 'on-hold', 'cancelled', 'refunded', 'failed', 'pending', 'custom' ) as $status ) {
			$this->assertFalse( TemplateDefaults::enabled_by_default( $status ), $status );
		}
	}

	/**
	 * Every default template uses only supported placeholders.
	 */
	public function test_default_templates_use_supported_placeholders(): void {
		foreach ( array( 'bg_BG', 'en_US' ) as $locale ) {
			foreach ( array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed', 'other' ) as $status ) {
				$this->assertSame( array(), Placeholders::unknown( TemplateDefaults::for_status( $status, $locale ) ), "$locale $status" );
			}
		}
	}
}
