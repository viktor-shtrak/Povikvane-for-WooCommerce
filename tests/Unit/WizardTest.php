<?php
/**
 * Tests for the wizard sequence, setup state and settings sanitising.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Setup\SetupState;
use Povikvane\WooCommerce\Setup\WizardSteps;

/**
 * Wizard behaviour that does not need WordPress.
 */
final class WizardTest extends TestCase {

	/**
	 * Start with no stored state.
	 */
	protected function setUp(): void {
		$GLOBALS['povikvane_test_options'] = array();
	}

	/**
	 * The steps run welcome to done.
	 */
	public function test_steps_in_order(): void {
		$this->assertSame( array( 'welcome', 'connect', 'messages', 'test', 'reports', 'done' ), WizardSteps::all() );
	}

	/**
	 * Moving forward and back stays inside the sequence.
	 */
	public function test_next_and_previous_are_bounded(): void {
		$this->assertSame( 'connect', WizardSteps::next( 'welcome' ) );
		$this->assertSame( 'test', WizardSteps::next( 'messages' ) );
		$this->assertSame( 'done', WizardSteps::next( 'done' ), 'no step after the last' );
		$this->assertSame( 'welcome', WizardSteps::previous( 'welcome' ), 'no step before the first' );
		$this->assertSame( 'messages', WizardSteps::previous( 'test' ) );
	}

	/**
	 * Unknown input falls back to the first step instead of breaking.
	 */
	public function test_unknown_steps_fall_back_to_welcome(): void {
		$this->assertSame( 'welcome', WizardSteps::normalize( 'hacked' ) );
		$this->assertSame( 'welcome', WizardSteps::normalize( '' ) );
		$this->assertSame( 'connect', WizardSteps::next( 'nonsense' ), 'treated as the first step' );
		$this->assertSame( 1, WizardSteps::position( 'welcome' ) );
		$this->assertSame( 4, WizardSteps::position( 'test' ) );
	}

	/**
	 * A fresh install is neither completed nor dismissed; each flag is independent.
	 */
	public function test_setup_state_flags(): void {
		$state = new SetupState();

		$this->assertFalse( $state->is_completed() );
		$this->assertFalse( $state->is_dismissed() );

		$state->dismiss();
		$this->assertTrue( $state->is_dismissed() );
		$this->assertFalse( $state->is_completed(), 'dismissing does not complete setup' );

		$state->complete();
		$this->assertTrue( $state->is_completed() );
		$this->assertTrue( $state->is_dismissed(), 'earlier flags are kept' );
	}

	/**
	 * Resetting brings the reminder back.
	 */
	public function test_reset_clears_everything(): void {
		$state = new SetupState();
		$state->complete();
		$state->dismiss();

		$state->reset();

		$this->assertFalse( $state->is_completed() );
		$this->assertFalse( $state->is_dismissed() );
	}
}
