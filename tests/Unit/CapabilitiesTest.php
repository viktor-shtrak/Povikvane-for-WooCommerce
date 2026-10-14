<?php
/**
 * Tests for connection checks and capability detection.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;

/**
 * Probe behaviour with the flagged endpoints on and off.
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * Build a result.
	 *
	 * @param Outcome             $outcome Outcome.
	 * @param int                 $http    HTTP status.
	 * @param array<string,mixed> $data    Data.
	 */
	private function res( Outcome $outcome, int $http, array $data = array() ): ApiResult {
		return new ApiResult( $outcome, $http, $data );
	}

	/**
	 * A valid key sees 404 for the nil UUID, which proves the credentials work.
	 */
	public function test_not_found_on_probe_id_means_connection_ok(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->res( Outcome::NotFound, 404 ) );

		$this->assertSame( Capabilities::CONNECTION_OK, Capabilities::check_connection( $gateway ) );
	}

	/**
	 * Connection check outcomes.
	 */
	public function test_connection_failures_are_distinguished(): void {
		$cases = array(
			array( Outcome::AuthFailure, 401, Capabilities::CONNECTION_AUTH ),
			array( Outcome::Retryable, 0, Capabilities::CONNECTION_UNREACHABLE ),
			array( Outcome::RateLimited, 429, Capabilities::CONNECTION_UNREACHABLE ),
			array( Outcome::PermanentFailure, 400, Capabilities::CONNECTION_CONFIG ),
		);

		foreach ( $cases as $case ) {
			$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->res( $case[0], $case[1] ) );
			$this->assertSame( $case[2], Capabilities::check_connection( $gateway ), (string) $case[1] );
		}
	}

	/**
	 * Flags on: both optional endpoints answer.
	 */
	public function test_detects_available_endpoints_and_balance(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', $this->res( Outcome::NotFound, 404 ) )
			->will_return_for(
				'account',
				$this->res(
					Outcome::Success,
					200,
					array(
						'attributes' => array(
							'credits-available' => 142.8,
							'credits-locked'    => 0,
							'channels'          => array( 'sms', 'viber' ),
						),
					)
				)
			)
			->will_return_for( 'lookup', $this->res( Outcome::Success, 200 ) );

		$caps = Capabilities::evaluate( $gateway, array(), 1760000000 );

		$this->assertSame( Capabilities::CONNECTION_OK, $caps['connection'] );
		$this->assertTrue( $caps['account'] );
		$this->assertTrue( $caps['lookup'] );
		$this->assertSame( 142.8, $caps['balance']['credits-available'] );
		$this->assertSame( 1760000000, $caps['checked_at'] );
	}

	/**
	 * Flags off: 404 means not available, and the stale balance is dropped.
	 */
	public function test_404_means_not_available(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', $this->res( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', $this->res( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', $this->res( Outcome::NotFound, 404 ) );

		$caps = Capabilities::evaluate(
			$gateway,
			array(
				'account' => true,
				'lookup'  => true,
				'balance' => array( 'credits-available' => 5 ),
			),
			1760000000
		);

		$this->assertFalse( $caps['account'] );
		$this->assertFalse( $caps['lookup'] );
		$this->assertNull( $caps['balance'] );
	}

	/**
	 * Rejected credentials stop the probe and keep earlier knowledge.
	 */
	public function test_auth_failure_skips_optional_probes(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->res( Outcome::AuthFailure, 401 ) );

		$caps = Capabilities::evaluate(
			$gateway,
			array(
				'account' => true,
				'lookup'  => false,
			),
			1760000000
		);

		$this->assertSame( Capabilities::CONNECTION_AUTH, $caps['connection'] );
		$this->assertTrue( $caps['account'] );
		$this->assertFalse( $caps['lookup'] );
		$this->assertSame( array( 'status' ), $gateway->calls );
	}

	/**
	 * A transient failure on one probe does not erase what we knew.
	 */
	public function test_inconclusive_probe_keeps_previous_value(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', $this->res( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', $this->res( Outcome::Retryable, 0 ) )
			->will_return_for( 'lookup', $this->res( Outcome::Retryable, 500 ) );

		$caps = Capabilities::evaluate(
			$gateway,
			array(
				'account' => true,
				'lookup'  => true,
				'balance' => array( 'credits-available' => 9 ),
			),
			1760000000
		);

		$this->assertTrue( $caps['account'] );
		$this->assertTrue( $caps['lookup'] );
		$this->assertSame( 9, $caps['balance']['credits-available'] );
	}
}
