<?php
/**
 * Tests for the Messages tab logic: removed placeholders and sending texts for approval.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Messages\LegacyPlaceholders;
use Povikvane\WooCommerce\Viber\SubmitResult;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\TemplateRequestService;
use Povikvane\WooCommerce\Viber\TemplateRequests;
use Povikvane\WooCommerce\Viber\TemplateSubmitter;
use Povikvane\WooCommerce\Viber\UnavailableSubmitter;
use Povikvane\WooCommerce\Viber\ViberTemplate;

/**
 * Old placeholders disappear tidily; texts are sent for approval once, and only when they can be templates.
 */
final class MessagesTabTest extends TestCase {

	/**
	 * Start with nothing stored.
	 */
	protected function setUp(): void {
		delete_option( TemplateCatalog::OPTION );
		delete_option( TemplateRequests::OPTION );
	}

	/**
	 * A submitter that records what it was given.
	 *
	 * @param bool $accept Whether it accepts templates.
	 */
	private function submitter( bool $accept = true ): object {
		return new class( $accept ) implements TemplateSubmitter {
			/**
			 * Templates received.
			 *
			 * @var array<int,array{string,string}>
			 */
			public array $sent = array();

			/**
			 * Constructor.
			 *
			 * @param bool $accept Whether it accepts templates.
			 */
			public function __construct( private readonly bool $accept ) {}

			/**
			 * Always available.
			 */
			public function available(): bool {
				return true;
			}

			/**
			 * Record and answer.
			 *
			 * @param string $name    Name.
			 * @param string $content Content.
			 */
			public function submit( string $name, string $content ): SubmitResult {
				$this->sent[] = array( $name, $content );

				return $this->accept ? new SubmitResult( true, 'new-1' ) : new SubmitResult( false, '', 'No.' );
			}
		};
	}

	/**
	 * Service around a submitter.
	 *
	 * @param TemplateSubmitter $submitter Submitter.
	 */
	private function service( TemplateSubmitter $submitter ): TemplateRequestService {
		return new TemplateRequestService( $submitter, new TemplateCatalog(), new TemplateRequests() );
	}

	/**
	 * The dropped placeholders go, with the comma in front of them.
	 */
	public function test_legacy_placeholders_are_stripped_tidily(): void {
		$this->assertSame( 'Hello! Order {order_number}.', LegacyPlaceholders::strip( 'Hello, {first_name}! Order {order_number}.' ) );
		$this->assertSame( 'Hi, order 5', LegacyPlaceholders::strip( 'Hi {first_name}, order 5' ) );
		$this->assertSame( 'Track: {tracking_url}', LegacyPlaceholders::strip( 'Track: {tracking_url}' ), 'kept placeholders stay' );
		$this->assertSame( 'Order {order_number}.', LegacyPlaceholders::strip( 'Order {order_number}. {ORDER_STATUS_URL}' ) );
		$this->assertSame( '', LegacyPlaceholders::strip( '{first_name} {last_name}' ) );
	}

	/**
	 * A text is sent once, and not again until it is edited.
	 */
	public function test_text_is_sent_once(): void {
		$submitter = $this->submitter();
		$service   = $this->service( $submitter );
		$text      = 'Order {order_number} from {shop_name} is complete.';
		$labels    = array( 'completed' => 'Completed' );

		$first = $service->send( array( 'completed' => $text ), $labels, 'Demo', 1000 );
		$this->assertSame( TemplateRequestService::SENT, $first['completed']['result'] );
		$this->assertSame( array( array( 'Demo - Completed', 'Order {customField1} from {customField3} is complete.' ) ), $submitter->sent );
		$this->assertSame( 1000, ( new TemplateRequests() )->sent_at( 'completed', $text ) );

		$again = $service->send( array( 'completed' => $text ), $labels, 'Demo', 2000 );
		$this->assertSame( TemplateRequestService::COVERED, $again['completed']['result'] );
		$this->assertCount( 1, $submitter->sent );

		$this->assertSame( 0, ( new TemplateRequests() )->sent_at( 'completed', $text . ' Bye.' ), 'an edited text is a new text' );
		$this->assertSame( 1000, ( new TemplateRequests() )->sent_at( 'completed', "Order {order_number}  from {shop_name}\nis complete. " ), 'spacing does not matter' );
	}

	/**
	 * A text that cannot be a template is explained, not sent.
	 */
	public function test_unusable_text_is_blocked_with_a_reason(): void {
		$submitter = $this->submitter();
		$result    = $this->service( $submitter )->send( array( 'completed' => 'Done. {shop_name}' ), array(), 'Demo', 1 );

		$this->assertSame( TemplateRequestService::BLOCKED, $result['completed']['result'] );
		$this->assertStringContainsString( 'cannot start or end with a field', $result['completed']['reason'] );
		$this->assertSame( array(), $submitter->sent );
	}

	/**
	 * A text that already has an approved or waiting template is left alone.
	 */
	public function test_text_with_a_template_is_not_sent(): void {
		$catalog = new TemplateCatalog();
		$catalog->replace(
			array(
				ViberTemplate::from_array(
					array(
						'id'              => 't1',
						'name'            => 'Complete',
						'content'         => 'Order {customField1} is complete.',
						'variables'       => array( 'customField1' ),
						'approval_status' => 'pending',
					)
				),
			),
			1
		);

		$submitter = $this->submitter();
		$service   = new TemplateRequestService( $submitter, $catalog, new TemplateRequests() );
		$result    = $service->send( array( 'completed' => 'Order {order_number} is complete.' ), array(), 'Demo', 1 );

		$this->assertSame( TemplateRequestService::COVERED, $result['completed']['result'] );
		$this->assertSame( array(), $submitter->sent );
	}

	/**
	 * A refusal is reported and not remembered.
	 */
	public function test_refused_template_is_not_remembered(): void {
		$result = $this->service( $this->submitter( false ) )->send( array( 'completed' => 'Order {order_number} is complete.' ), array(), 'Demo', 5 );

		$this->assertSame( TemplateRequestService::FAILED, $result['completed']['result'] );
		$this->assertSame( 'No.', $result['completed']['reason'] );
		$this->assertSame( 0, ( new TemplateRequests() )->sent_at( 'completed', 'Order {order_number} is complete.' ) );
	}

	/**
	 * Until the API can do it, the plugin says so.
	 */
	public function test_unavailable_submitter_says_no(): void {
		$submitter = new UnavailableSubmitter();

		$this->assertFalse( $submitter->available() );
		$this->assertFalse( $submitter->submit( 'x', 'y' )->accepted );
	}

	/**
	 * Names fit the 48 characters Povikvane allows.
	 */
	public function test_template_names_are_short_enough(): void {
		$this->assertSame( 'Demo - Completed', TemplateRequestService::name_for( 'Completed', 'Demo' ) );
		$this->assertSame( 48, mb_strlen( TemplateRequestService::name_for( str_repeat( 'Я', 60 ), 'Магазин' ) ) );
	}
}
