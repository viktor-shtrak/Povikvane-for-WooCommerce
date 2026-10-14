<?php
/**
 * Tests for data retention and CSV safety.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\Retention;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Csv;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;

/**
 * Retention and CSV helpers.
 */
final class RetentionCsvTest extends TestCase {

	private const NOW = 1760000000;

	/**
	 * Add a message created some days ago.
	 *
	 * @param InMemoryStore $store    Store.
	 * @param int           $days_old Age in days.
	 * @param Status        $status   Status.
	 * @param string        $suffix   Reference suffix.
	 */
	private function add( InMemoryStore $store, int $days_old, Status $status, string $suffix ): void {
		$message             = new Message();
		$message->reference  = 'woo:ab:' . $suffix;
		$message->body       = 'Private text';
		$message->status     = $status;
		$message->created_at = self::NOW - $days_old * 86400;
		$store->create( $message );
	}

	/**
	 * Old text is erased first; much older rows are deleted; waiting messages are never touched.
	 */
	public function test_retention_erases_text_then_deletes_rows(): void {
		$store = new InMemoryStore();
		$this->add( $store, 10, Status::Delivered, 'recent' );
		$this->add( $store, 100, Status::Delivered, 'old' );
		$this->add( $store, 400, Status::Failed, 'ancient' );
		$this->add( $store, 400, Status::Pending, 'waiting' );

		$result = ( new Retention( $store, new FixedClock( self::NOW ), 90, 365 ) )->run();

		$this->assertSame(
			array(
				'bodies' => 2,
				'rows'   => 1,
			),
			$result,
			'text of the 100- and 400-day-old messages is erased; only the 400-day-old row is deleted'
		);
		$this->assertSame( 'Private text', $store->find_by_reference( 'woo:ab:recent' )->body );
		$this->assertSame( '', $store->find_by_reference( 'woo:ab:old' )->body );
		$this->assertNull( $store->find_by_reference( 'woo:ab:ancient' ) );
		$this->assertNotNull( $store->find_by_reference( 'woo:ab:waiting' ), 'a waiting message is never deleted' );
		$this->assertSame( 'Private text', $store->find_by_reference( 'woo:ab:waiting' )->body );
	}

	/**
	 * Zero disables each step.
	 */
	public function test_zero_days_disables_a_step(): void {
		$store = new InMemoryStore();
		$this->add( $store, 1000, Status::Delivered, 'old' );

		$result = ( new Retention( $store, new FixedClock( self::NOW ), 0, 0 ) )->run();

		$this->assertSame(
			array(
				'bodies' => 0,
				'rows'   => 0,
			),
			$result
		);
		$this->assertNotNull( $store->find_by_reference( 'woo:ab:old' ) );
	}

	/**
	 * Cells are quoted and cannot run as spreadsheet formulas.
	 */
	public function test_csv_cells_are_quoted_and_safe(): void {
		$this->assertSame( '"hello"', Csv::cell( 'hello' ) );
		$this->assertSame( '"say ""hi"""', Csv::cell( 'say "hi"' ) );
		$this->assertSame( '"\'=SUM(A1)"', Csv::cell( '=SUM(A1)' ) );
		$this->assertSame( '"\'@cmd"', Csv::cell( '@cmd' ) );
		$this->assertSame( '"\'+359888123456x"', Csv::cell( '+359888123456x' ) );
		$this->assertSame( '"+359888123456"', Csv::cell( '+359888123456' ), 'a plain phone number stays readable' );
		$this->assertSame( '"-5"', Csv::cell( -5 ) );
		$this->assertSame( '""', Csv::cell( null ) );
		$this->assertSame( '"1","a,b","0.8"', Csv::row( array( 1, 'a,b', 0.8 ) ) );
	}
}
