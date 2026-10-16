<?php
/**
 * Tests for what the account reports: plan features and custom field labels.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Account\FieldLabelPlan;
use Povikvane\WooCommerce\Setup\SetupChecklist;

/**
 * Nothing is guessed: what the account does not say stays unknown.
 */
final class AccountInfoTest extends TestCase {

	/**
	 * The WooCommerce labels used in the comparisons.
	 *
	 * @return array<string,string>
	 */
	private function woo(): array {
		return array(
			'customField1' => 'Order number',
			'customField2' => 'Order total',
			'customField3' => 'Shop name',
		);
	}

	/**
	 * A refusal or the account's own word decides; silence is unknown.
	 */
	public function test_sms_fallback_allowed(): void {
		$this->assertNull( AccountInfo::sms_fallback_allowed( '' ) );
		$this->assertNull( AccountInfo::sms_fallback_allowed( array( 'balance' => array( 'available' => 5 ) ) ) );
		$this->assertFalse( AccountInfo::sms_fallback_allowed( array( 'fallback' => false ) ), 'Povikvane refused one' );
		$this->assertFalse( AccountInfo::sms_fallback_allowed( array( 'balance' => array( 'plan' => array( 'sms_fallback' => false ) ) ) ) );
		$this->assertTrue( AccountInfo::sms_fallback_allowed( array( 'balance' => array( 'features' => array( 'sms_fallback' => true ) ) ) ) );
		$this->assertTrue( AccountInfo::sms_fallback_allowed( array( 'balance' => array( 'sms_fallback' => true ) ) ) );
		$this->assertNull( AccountInfo::sms_fallback_allowed( array( 'balance' => array( 'plan' => array( 'sms_fallback' => 'yes' ) ) ) ), 'not a real answer' );
	}

	/**
	 * Labels are read from either shape the account might use.
	 */
	public function test_custom_field_labels_are_read(): void {
		$this->assertNull( AccountInfo::custom_field_labels( array( 'balance' => array() ) ), 'not reported' );
		$this->assertNull( AccountInfo::custom_field_labels( '' ) );

		$this->assertSame(
			array(
				'customField1' => 'Order number',
				'customField2' => '',
				'customField3' => '',
			),
			AccountInfo::custom_field_labels(
				array(
					'balance' => array(
						'custom_fields' => array(
							'customField1' => ' Order number ',
							'customField9' => 'x',
						),
					),
				)
			)
		);

		$this->assertSame(
			array(
				'customField1' => '',
				'customField2' => 'Total',
				'customField3' => 'Shop',
			),
			AccountInfo::custom_field_labels(
				array(
					'balance' => array(
						'custom_fields' => array(
							array(
								'name'  => 'customField2',
								'label' => 'Total',
							),
							array(
								'key'   => 'customField3',
								'label' => 'Shop',
							),
							array(
								'name'  => 'customField1',
								'label' => null,
							),
						),
					),
				)
			)
		);
	}

	/**
	 * The comparison tells the four stories the screen has to tell.
	 */
	public function test_label_plan_states(): void {
		$empty = array_fill_keys( AccountInfo::FIELDS, '' );

		$this->assertSame( FieldLabelPlan::UNKNOWN, FieldLabelPlan::compare( null, $this->woo() )->state );
		$this->assertSame( FieldLabelPlan::NONE, FieldLabelPlan::compare( $empty, $this->woo() )->state );
		$this->assertSame( FieldLabelPlan::PARTIAL, FieldLabelPlan::compare( array_merge( $empty, array( 'customField2' => 'Sum' ) ), $this->woo() )->state );
		$this->assertSame(
			FieldLabelPlan::FULL,
			FieldLabelPlan::compare(
				array(
					'customField1' => 'A',
					'customField2' => 'B',
					'customField3' => 'C',
				),
				$this->woo()
			)->state
		);
		$this->assertSame( FieldLabelPlan::MATCHES, FieldLabelPlan::compare( $this->woo(), $this->woo() )->state );
	}

	/**
	 * Replacing sends all three; filling keeps what is already there.
	 */
	public function test_labels_to_save(): void {
		$plan = FieldLabelPlan::compare(
			array(
				'customField1' => '',
				'customField2' => 'Sum',
				'customField3' => '',
			),
			$this->woo()
		);

		$this->assertSame( $this->woo(), $plan->labels_to_save( false ) );
		$this->assertSame(
			array(
				'customField1' => 'Order number',
				'customField2' => 'Sum',
				'customField3' => 'Shop name',
			),
			$plan->labels_to_save( true )
		);
	}

	/**
	 * Continuing setup goes to the first step that is still open.
	 */
	public function test_setup_resumes_at_the_first_open_step(): void {
		$this->assertSame( SetupChecklist::CONNECT, SetupChecklist::next_step( SetupChecklist::evaluate( false, 0, false, false ) ) );
		$this->assertSame( SetupChecklist::MESSAGES, SetupChecklist::next_step( SetupChecklist::evaluate( true, 0, false, false ) ) );
		$this->assertSame( SetupChecklist::TEST, SetupChecklist::next_step( SetupChecklist::evaluate( true, 2, false, false ) ) );
		$this->assertNull( SetupChecklist::next_step( SetupChecklist::evaluate( true, 2, true, false ) ) );
	}
}
