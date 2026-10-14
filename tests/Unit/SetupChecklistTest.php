<?php
/**
 * Tests for the setup checklist.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Setup\SetupChecklist;

/**
 * The checklist reflects reality.
 */
final class SetupChecklistTest extends TestCase {

	/**
	 * A fresh install has everything left to do.
	 */
	public function test_fresh_install(): void {
		$items = SetupChecklist::evaluate( false, 0, false, false );

		$this->assertSame( array( 'connect', 'messages', 'test', 'reports' ), array_column( $items, 'id' ) );
		$this->assertFalse( SetupChecklist::is_complete( $items ) );
		$this->assertSame( 0, SetupChecklist::percent( $items ) );
		$this->assertSame( 'connect', SetupChecklist::next_step( $items ) );
	}

	/**
	 * Steps are suggested in order.
	 */
	public function test_next_step_follows_the_order(): void {
		$this->assertSame( 'messages', SetupChecklist::next_step( SetupChecklist::evaluate( true, 0, false, false ) ) );
		$this->assertSame( 'test', SetupChecklist::next_step( SetupChecklist::evaluate( true, 2, false, false ) ) );
		$this->assertNull( SetupChecklist::next_step( SetupChecklist::evaluate( true, 1, true, false ) ) );
	}

	/**
	 * Delivery reports are optional: setup is complete without them.
	 */
	public function test_delivery_reports_are_optional(): void {
		$items = SetupChecklist::evaluate( true, 1, true, false );

		$this->assertTrue( SetupChecklist::is_complete( $items ) );
		$this->assertSame( 75, SetupChecklist::percent( $items ) );
		$this->assertFalse( $items[3]['required'] );
	}

	/**
	 * Everything done is 100 per cent.
	 */
	public function test_everything_done(): void {
		$items = SetupChecklist::evaluate( true, 3, true, true );

		$this->assertTrue( SetupChecklist::is_complete( $items ) );
		$this->assertSame( 100, SetupChecklist::percent( $items ) );
	}

	/**
	 * A skipped middle step still blocks completion.
	 */
	public function test_a_missing_required_step_blocks_completion(): void {
		$items = SetupChecklist::evaluate( true, 0, true, true );

		$this->assertFalse( SetupChecklist::is_complete( $items ) );
		$this->assertSame( 'messages', SetupChecklist::next_step( $items ) );
		$this->assertSame( 75, SetupChecklist::percent( $items ) );
	}

	/**
	 * Empty input is safe.
	 */
	public function test_percent_of_nothing(): void {
		$this->assertSame( 0, SetupChecklist::percent( array() ) );
		$this->assertTrue( SetupChecklist::is_complete( array() ) );
	}
}
