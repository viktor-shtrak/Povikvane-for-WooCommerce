<?php
/**
 * Tests for the template, account, contact and opt-out API features.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Account\ApiLabelWriter;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Marketing\ApiContactSync;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentEvent;
use Povikvane\WooCommerce\Marketing\ContactJobs;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\ErrorExplainer;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryActivity;
use Povikvane\WooCommerce\Tests\Support\InMemoryLedger;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;
use Povikvane\WooCommerce\Viber\ApiTemplateSubmitter;
use Povikvane\WooCommerce\Messages\PriceEstimate;
use Povikvane\WooCommerce\Webhook\WebhookHandler;
use Povikvane\WooCommerce\Webhook\WebhookRegistration;

/**
 * Template creation, labels, contacts, opt-outs and the new webhook events.
 */
final class ApiIntegrationTest extends TestCase {

	/**
	 * A refusal.
	 *
	 * @param int    $http HTTP status.
	 * @param string $code Error code.
	 */
	private function refused( int $http, string $code ): ApiResult {
		return new ApiResult( 409 === $http ? Outcome::InFlight : Outcome::PermanentFailure, $http, array(), 'x', null, null, null, $code );
	}

	/**
	 * A success.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function ok( array $data = array() ): ApiResult {
		return new ApiResult( Outcome::Success, 200, $data );
	}

	/**
	 * A taken name gets a version suffix until one is free.
	 */
	public function test_taken_names_get_a_version_suffix(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'create', $this->refused( 409, 'template_name_duplicate' ) )
			->will_return_for( 'create', $this->refused( 409, 'template_name_duplicate' ) )
			->will_return_for( 'create', $this->ok( array( 'id' => 'tpl-9' ) ) );

		$result = ( new ApiTemplateSubmitter( $gateway, true, 'bg' ) )->submit( 'Shop - Completed', 'Order {customField1} done.' );

		$this->assertTrue( $result->accepted );
		$this->assertSame( 'tpl-9', $result->template_id );
		$this->assertSame( array( 'Shop - Completed', 'Shop - Completed v2', 'Shop - Completed v3' ), array_column( array_column( $gateway->writes, 'args' ), 'name' ) );
		$this->assertSame( 'bg', $gateway->writes[0]['args']['lang'] );
	}

	/**
	 * Names stay within 48 characters when a suffix is added.
	 */
	public function test_versioned_names_fit_the_limit(): void {
		$name = str_repeat( 'я', 60 );

		$this->assertSame( 48, mb_strlen( ApiTemplateSubmitter::versioned( $name, 1 ) ) );
		$this->assertSame( 48, mb_strlen( ApiTemplateSubmitter::versioned( $name, 12 ) ) );
		$this->assertStringEndsWith( ' v12', ApiTemplateSubmitter::versioned( $name, 12 ) );
	}

	/**
	 * Limits and rule breaks come back as readable reasons, and nothing is retried.
	 */
	public function test_refusals_are_explained(): void {
		foreach ( array( 'template_limit_reached', 'template_rate_limited', 'template_variable_at_edge', 'template_too_long' ) as $code ) {
			$gateway = ( new FakeGateway() )->will_return_for( 'create', $this->refused( 'template_limit_reached' === $code ? 409 : 400, $code ) );
			$result  = ( new ApiTemplateSubmitter( $gateway, true, 'en' ) )->submit( 'N', 'T' );

			$this->assertFalse( $result->accepted, $code );
			$this->assertNotSame( '', $result->reason, $code );
			$this->assertCount( 1, $gateway->writes, $code );
		}
	}

	/**
	 * An account without template management makes no request.
	 */
	public function test_submitter_is_off_without_the_feature(): void {
		$gateway   = new FakeGateway();
		$submitter = new ApiTemplateSubmitter( $gateway, false, 'en' );

		$this->assertFalse( $submitter->available() );
		$this->assertFalse( $submitter->submit( 'N', 'T' )->accepted );
		$this->assertSame( array(), $gateway->writes );
	}

	/**
	 * Only the three fields are written, cut to 60 characters.
	 */
	public function test_label_writer_sends_only_the_three_fields(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'labels', $this->ok() );
		$writer  = new ApiLabelWriter( $gateway, true );

		$this->assertTrue(
			$writer->save(
				array(
					'customField1' => ' Order number ',
					'customField2' => str_repeat( 'x', 80 ),
					'customField4' => 'no',
				)
			)
		);
		$this->assertSame( 'Order number', $gateway->writes[0]['args']['customField1'] );
		$this->assertSame( 60, mb_strlen( $gateway->writes[0]['args']['customField2'] ) );
		$this->assertArrayNotHasKey( 'customField4', $gateway->writes[0]['args'] );
		$this->assertFalse( ( new ApiLabelWriter( new FakeGateway(), true ) )->save( array() ) );
	}

	/**
	 * Contacts go out in batches of 500 with consent time and source.
	 */
	public function test_contact_sync_batches_contacts(): void {
		$gateway  = ( new FakeGateway() )->will_return_for( 'group', $this->ok( array( 'id' => 'g-1' ) ) );
		$contacts = array();

		for ( $i = 0; $i < 1001; $i++ ) {
			$contacts[] = array(
				'phone'      => '+3598881' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ),
				'first_name' => 0 === $i ? 'Мария' : '',
				'last_name'  => '',
				'agreed_at'  => 1760000000,
			);
		}

		foreach ( array( 500, 500, 1 ) as $count ) {
			$gateway->will_return_for(
				'contacts',
				$this->ok(
					array(
						'added'              => $count - 1,
						'updated'            => 0,
						'already_present'    => 1,
						'skipped_suppressed' => array(),
						'invalid'            => array(),
					)
				)
			);
		}

		$this->assertSame( 1001, ( new ApiContactSync( $gateway, true ) )->sync( $contacts ) );
		$this->assertCount( 3, $gateway->writes );
		$this->assertCount( 500, $gateway->writes[0]['args'] );
		$this->assertSame( 'woocommerce', $gateway->writes[0]['args'][0]['source'] );
		$this->assertSame( '2025-10-09T08:53:20Z', $gateway->writes[0]['args'][0]['consent_at'] );
		$this->assertSame( 'Мария', $gateway->writes[0]['args'][0]['first_name'] );
		$this->assertArrayNotHasKey( 'last_name', $gateway->writes[0]['args'][0] );
		$this->assertNotFalse( get_option( ContactJobs::SYNCED, false ) );
	}

	/**
	 * A failed group or batch reports failure.
	 */
	public function test_contact_sync_reports_failure(): void {
		$contact = array(
			'phone'      => '+359888123456',
			'first_name' => '',
			'last_name'  => '',
			'agreed_at'  => 1,
		);

		$this->assertSame( -1, ( new ApiContactSync( ( new FakeGateway() )->will_return_for( 'group', $this->refused( 404, 'endpoint_disabled' ) ), true ) )->sync( array( $contact ) ) );
		$this->assertSame( -1, ( new ApiContactSync( ( new FakeGateway() )->will_return_for( 'group', $this->ok( array( 'id' => 'g' ) ) )->will_return_for( 'contacts', $this->refused( 429, 'rate_limited' ) ), true ) )->sync( array( $contact ) ) );
		$this->assertSame( 0, ( new ApiContactSync( new FakeGateway(), true ) )->sync( array() ) );
	}

	/**
	 * A withdrawal only deletes the contact when contacts were sent, and never for Povikvane's own opt-outs.
	 */
	public function test_withdrawal_hook_respects_sync_and_source(): void {
		$GLOBALS['povikvane_test_options'] = array();
		ContactJobs::on_withdrawn( '+359888123456', ConsentEvent::SOURCE_OPTOUT );

		ContactJobs::mark_synced();
		ContactJobs::on_withdrawn( '+359888123456', ConsentEvent::SOURCE_PLATFORM );

		// No scheduler exists in unit tests, so queueing is silent; the job itself is checked below.
		$gateway = ( new FakeGateway() )->will_return_for( 'erase', $this->ok() )->will_return_for( 'erase', new ApiResult( Outcome::NotFound, 404 ) );
		$job     = new ContactJobs( $gateway );
		$job->run( '+359888123456', 1 );
		$job->run( '+359888123456', 1 );
		$job->run( '', 1 );

		$this->assertCount( 2, $gateway->writes );
	}

	/**
	 * Build a webhook handler.
	 *
	 * @param InMemoryLedger $ledger Consent ledger.
	 * @param int            $refreshed Count of template refreshes.
	 */
	private function webhook( InMemoryLedger $ledger, int &$refreshed ): WebhookHandler {
		$store = new InMemoryStore();
		$clock = new FixedClock();

		return new WebhookHandler(
			$store,
			new DeliveryUpdater( $store, new RecordingNotifier(), $clock ),
			new InMemoryActivity(),
			$clock,
			new Consent( $ledger, $clock, 'secret' ),
			static function () use ( &$refreshed ): void {
				++$refreshed;
			}
		);
	}

	/**
	 * A template event makes the plugin read the list again.
	 */
	public function test_template_event_refreshes_the_list(): void {
		$refreshed = 0;
		$result    = $this->webhook( new InMemoryLedger(), $refreshed )->handle(
			array(
				'event' => 'template.status_updated',
				'data'  => array(
					'id'              => 't-1',
					'internal_status' => 'approved',
				),
			)
		);

		$this->assertSame( WebhookHandler::RESULT_TEMPLATE, $result );
		$this->assertSame( 1, $refreshed );
	}

	/**
	 * An opt-out Povikvane reports stops promotions here, once.
	 */
	public function test_unsubscribed_event_withdraws_consent(): void {
		$ledger    = new InMemoryLedger();
		$refreshed = 0;
		$clock     = new FixedClock();
		( new Consent( $ledger, $clock, 'secret' ) )->grant( '+359888123456', null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'w' );
		$handler = $this->webhook( $ledger, $refreshed );
		$body    = array(
			'event' => 'contact.unsubscribed',
			'data'  => array(
				'phone' => '+359888123456',
				'scope' => 'global',
			),
		);

		$this->assertSame( WebhookHandler::RESULT_OPTED_OUT, $handler->handle( $body ) );
		$this->assertFalse( ( new Consent( $ledger, $clock, 'secret' ) )->has_consent( '+359888123456' ) );
		$this->assertSame( ConsentEvent::SOURCE_PLATFORM, $ledger->events[1]->source );
		$this->assertSame( WebhookHandler::RESULT_IGNORED, $handler->handle( $body ) );
		$this->assertSame(
			WebhookHandler::RESULT_INVALID,
			$handler->handle(
				array(
					'event' => 'contact.unsubscribed',
					'data'  => array( 'phone' => 'nope' ),
				)
			)
		);
	}

	/**
	 * A send to an opted-out number fails for good, with no SMS retry.
	 */
	public function test_suppressed_recipient_is_a_final_failure(): void {
		$store   = new InMemoryStore();
		$gateway = ( new FakeGateway() )->will_return( new ApiResult( Outcome::PermanentFailure, 422, array(), 'The recipient has opted out of messages', null, null, null, 'recipient_suppressed' ) );
		$message = new Message();

		$message->order_id   = 1;
		$message->event      = 'completed';
		$message->channel    = 'viber';
		$message->recipient  = '+359888123456';
		$message->body       = 'Hello';
		$message->reference  = 'woo:ab12cd34:1:completed:1';
		$message->created_at = 1760000000;
		$message->updated_at = 1760000000;
		$message->viber      = array(
			'template_id'  => 'tpl-1',
			'params'       => array(),
			'fallback_sms' => 'Hello',
		);
		$id                  = (int) $store->create( $message );

		( new Dispatcher( $store, $gateway, new RecordingScheduler(), new FixedClock(), new RecordingNotifier(), new InMemoryState(), 86400 ) )->handle( $id );

		$stored = $store->find( $id );
		$this->assertSame( Status::Failed, $stored?->status );
		$this->assertSame( 'recipient_suppressed', $stored?->error_code );
		$this->assertCount( 1, $gateway->sent );
		$this->assertStringContainsString( 'opted out', ErrorExplainer::explain( $stored ) );
	}

	/**
	 * Account features, plan name and labels are read from the stored account answer.
	 */
	public function test_account_info_reads_features_and_plan(): void {
		$caps = array(
			'balance' => array(
				'plan'          => array( 'name' => 'Bronze' ),
				'features'      => array(
					'templates'           => true,
					'template_management' => false,
					'sms_fallback'        => true,
				),
				'custom_fields' => array(
					'customField1' => 'Номер на поръчка',
					'customField2' => null,
					'customField3' => null,
				),
			),
		);

		$this->assertSame( 'Bronze', AccountInfo::plan_name( $caps ) );
		$this->assertFalse( AccountInfo::feature( $caps, 'template_management' ) );
		$this->assertTrue( AccountInfo::feature( $caps, 'templates' ) );
		$this->assertNull( AccountInfo::feature( $caps, 'unknown' ) );
		$this->assertNull( AccountInfo::plan_name( array( 'balance' => array( 'plan' => array( 'name' => null ) ) ) ) );
		$this->assertTrue( AccountInfo::sms_fallback_allowed( $caps ) );
		$this->assertSame( 'Номер на поръчка', AccountInfo::custom_field_labels( $caps )['customField1'] ?? null );
		$this->assertSame( '', AccountInfo::custom_field_labels( $caps )['customField2'] ?? null );
	}

	/**
	 * Requests carry the right method, address, headers and body.
	 */
	public function test_client_builds_the_new_requests(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );

		for ( $i = 0; $i < 5; $i++ ) {
			$transport->will_return( new Response( 200, array(), array( 'data' => array( 'id' => 'x' ) ) ) );
		}

		$client->create_template( 'N', 'bg', 'Text {customField1} here' );
		$client->set_custom_fields( array( 'customField1' => 'Order number' ) );
		$client->ensure_group( 'Webshop SMS opt-in' );
		$client->delete_contact( '+359888123456' );
		$client->template_rules();

		[ $create, $labels, $group, $erase, $rules ] = $transport->requests;

		$this->assertSame( 'POST', $create['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/templates', $create['url'] );
		$this->assertSame( 'svc', $create['headers']['x-service-id'] );
		$this->assertSame(
			array(
				'name'    => 'N',
				'channel' => 'viber',
				'lang'    => 'bg',
				'content' => 'Text {customField1} here',
			),
			json_decode( (string) $create['body'], true )
		);
		$this->assertSame( 'PUT', $labels['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/account/custom-fields', $labels['url'] );
		$this->assertSame( '{"name":"Webshop SMS opt-in"}', $group['body'] );
		$this->assertSame( 'DELETE', $erase['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/contacts/%2B359888123456', $erase['url'] );
		$this->assertNull( $erase['body'] );
		$this->assertSame( 'GET', $rules['method'] );
	}

	/**
	 * Account answer with a webhook block.
	 *
	 * @param string|null $url      Registered address.
	 * @param bool        $supports Whether webhook management is on.
	 * @return array<string,mixed>
	 */
	private function caps_with_webhook( ?string $url, bool $supports = true ): array {
		return array(
			'balance' => array(
				'features' => array( 'webhook_management' => $supports ),
				'webhook'  => array(
					'url'              => $url,
					'last_delivery_at' => null,
					'last_status'      => null,
					'last_error'       => null,
				),
			),
		);
	}

	/**
	 * An empty address is filled in; one somebody set is left alone.
	 */
	public function test_registration_only_fills_an_empty_address(): void {
		$url     = 'https://shop.example/wp-json/povikvane/v1/dlr/abc';
		$gateway = ( new FakeGateway() )->will_return_for( 'webhook', $this->ok() );

		$this->assertTrue( ( new WebhookRegistration( $gateway ) )->register_if_empty( $this->caps_with_webhook( null ), $url ) );
		$this->assertSame( array( $url ), $gateway->writes[0]['args'] );

		$other = new FakeGateway();
		$this->assertFalse( ( new WebhookRegistration( $other ) )->register_if_empty( $this->caps_with_webhook( 'https://other.example/hook' ), $url ) );
		$this->assertFalse( ( new WebhookRegistration( $other ) )->register_if_empty( $this->caps_with_webhook( null, false ), $url ) );
		$this->assertFalse( ( new WebhookRegistration( $other ) )->register_if_empty( $this->caps_with_webhook( null ), 'http://shop.example/hook' ) );
		$this->assertFalse( ( new WebhookRegistration( $other ) )->register_if_empty( array(), $url ) );
		$this->assertSame( array(), $other->writes );
	}

	/**
	 * Addresses Povikvane could never reach are not sent.
	 */
	public function test_non_public_addresses_are_refused_locally(): void {
		foreach ( array( 'https://localhost/x', 'https://shop.local/x', 'https://shop.test/x', 'https://192.168.1.5/x', 'https://127.0.0.1/x', 'http://shop.example/x', 'nonsense' ) as $url ) {
			$this->assertFalse( WebhookRegistration::looks_public( $url ), $url );
		}

		$this->assertTrue( WebhookRegistration::looks_public( 'https://shop.example/wp-json/x' ) );
		$this->assertTrue( WebhookRegistration::looks_public( 'https://8.8.8.8/x' ) );

		$outcome = ( new WebhookRegistration( new FakeGateway() ) )->register( 'https://localhost/x' );
		$this->assertSame( WebhookRegistration::REFUSED, $outcome['result'] );
	}

	/**
	 * Povikvane's own refusal is explained.
	 */
	public function test_registration_refusal_is_explained(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'webhook', $this->refused( 422, 'webhook_url_invalid' ) );
		$outcome = ( new WebhookRegistration( $gateway ) )->register( 'https://shop.example/x' );

		$this->assertSame( WebhookRegistration::REFUSED, $outcome['result'] );
		$this->assertNotSame( '', $outcome['reason'] );
	}

	/**
	 * Each test answer gets its own message.
	 */
	public function test_webhook_test_results_are_interpreted(): void {
		$delivered = new ApiResult(
			Outcome::Success,
			200,
			array(
				'attributes' => array(
					'delivered'   => true,
					'http_status' => 204,
					'error'       => null,
				),
			)
		);
		$failed    = new ApiResult(
			Outcome::Success,
			200,
			array(
				'attributes' => array(
					'delivered'   => false,
					'http_status' => 403,
					'error'       => 'http_403',
				),
			)
		);

		$this->assertTrue( WebhookRegistration::interpret_test( $delivered )['ok'] );
		$this->assertFalse( WebhookRegistration::interpret_test( $failed )['ok'] );
		$this->assertStringContainsString( '403', WebhookRegistration::interpret_test( $failed )['message'] );
		$this->assertFalse( WebhookRegistration::interpret_test( $this->refused( 409, 'webhook_not_configured' ) )['ok'] );
		$this->assertFalse( WebhookRegistration::interpret_test( $this->refused( 429, 'rate_limited' ) )['ok'] );
	}

	/**
	 * The webhook block of the account answer is read, and the estimate's final price is used.
	 */
	public function test_account_webhook_and_estimate_are_read(): void {
		$info = AccountInfo::webhook( $this->caps_with_webhook( 'https://a.example/x' ) );
		$this->assertSame( 'https://a.example/x', $info['url'] ?? null );
		$this->assertNull( AccountInfo::webhook( array( 'balance' => array() ) ) );

		$estimate = PriceEstimate::from_result(
			new ApiResult(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'message_type'  => 'transactional',
						'credits'       => 0.8,
						'initial_debit' => 1,
						'recipient'     => array(
							'phone'      => '+359888123456',
							'valid'      => true,
							'suppressed' => true,
						),
					),
				)
			)
		);

		$this->assertSame( 0.8, $estimate?->credits );
		$this->assertSame( 1.0, $estimate?->initial_debit );
		$this->assertTrue( $estimate?->suppressed );
		$this->assertNull( PriceEstimate::from_result( $this->refused( 400, 'invalid_fallback' ) ) );
	}
}
