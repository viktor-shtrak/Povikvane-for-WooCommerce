<?php
/**
 * Tests for the wizard's automatic custom field names and its stacked notices.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Account\LabelWriter;
use Povikvane\WooCommerce\Admin\FieldLabelsPanel;
use Povikvane\WooCommerce\Admin\Flash;
use Povikvane\WooCommerce\Api\Capabilities;

/**
 * An account without field names gets the plugin's; one with names is left alone.
 */
final class WizardLabelsTest extends TestCase {

	/**
	 * Start with nothing stored.
	 */
	protected function setUp(): void {
		delete_option( Capabilities::OPTION );
		delete_transient( 'povikvane_flash_' . get_current_user_id() );
	}

	/**
	 * A writer that records what it was given.
	 *
	 * @param bool $works Whether saving succeeds.
	 */
	private function writer( bool $works = true ): object {
		return new class( $works ) implements LabelWriter {
			/**
			 * Labels received.
			 *
			 * @var array<int,array<string,string>>
			 */
			public array $saved = array();

			/**
			 * Constructor.
			 *
			 * @param bool $works Whether saving succeeds.
			 */
			public function __construct( private readonly bool $works ) {}

			/**
			 * Always available.
			 */
			public function available(): bool {
				return true;
			}

			/**
			 * Record the labels.
			 *
			 * @param array<string,string> $labels Labels.
			 */
			public function save( array $labels ): bool {
				$this->saved[] = $labels;

				return $this->works;
			}
		};
	}

	/**
	 * Store what the account says about its custom fields.
	 *
	 * @param array<string,string>|null $labels Labels, or null when the account says nothing.
	 */
	private function account_says( ?array $labels ): void {
		update_option(
			Capabilities::OPTION,
			array(
				'account' => true,
				'balance' => null === $labels ? array() : array( 'custom_fields' => $labels ),
			)
		);
	}

	/**
	 * No names at all: the plugin's three are written without asking.
	 */
	public function test_empty_account_gets_the_plugin_names(): void {
		$this->account_says( array() );
		$writer = $this->writer();

		$this->assertTrue( ( new FieldLabelsPanel( static fn() => null, $writer ) )->fill_if_empty() );
		$this->assertCount( 1, $writer->saved );
		$this->assertSame( array( 'customField1', 'customField2', 'customField3' ), array_keys( $writer->saved[0] ) );
	}

	/**
	 * Names the owner chose are never overwritten without asking.
	 */
	public function test_existing_names_are_left_alone(): void {
		$this->account_says( array( 'customField1' => 'Birthday' ) );
		$writer = $this->writer();

		$this->assertFalse( ( new FieldLabelsPanel( static fn() => null, $writer ) )->fill_if_empty() );
		$this->assertSame( array(), $writer->saved );
	}

	/**
	 * When the account does not say what its names are, nothing is written blindly.
	 */
	public function test_unknown_names_are_not_overwritten(): void {
		$this->account_says( null );
		$writer = $this->writer();

		$this->assertFalse( ( new FieldLabelsPanel( static fn() => null, $writer ) )->fill_if_empty() );
		$this->assertSame( array(), $writer->saved );
	}

	/**
	 * A refused write is reported, not swallowed.
	 */
	public function test_a_refused_write_is_reported(): void {
		$this->account_says( array() );

		$this->assertFalse( ( new FieldLabelsPanel( static fn() => null, $this->writer( false ) ) )->fill_if_empty() );

		ob_start();
		Flash::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
	}

	/**
	 * Several notices from one step all reach the next page.
	 */
	public function test_every_notice_is_shown(): void {
		Flash::add( 'success', 'Saved' );
		Flash::add( 'success', 'Sent' );
		Flash::add( 'error', 'Refused' );

		ob_start();
		Flash::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Saved', $html );
		$this->assertStringContainsString( 'Sent', $html );
		$this->assertStringContainsString( 'Refused', $html );

		ob_start();
		Flash::render();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
