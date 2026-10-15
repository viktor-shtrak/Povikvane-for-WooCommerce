<?php
/**
 * Tests for Viber: templates, planning, the request, the SMS reroute and the delivery channel.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Channel;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\OrderEventHandler;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\Rules;
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryActivity;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\TemplateSync;
use Povikvane\WooCommerce\Viber\VariableMapper;
use Povikvane\WooCommerce\Viber\ViberPlanner;
use Povikvane\WooCommerce\Viber\ViberTemplate;
use Povikvane\WooCommerce\Webhook\SelfTest;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

/**
 * Viber behaviour, against the API as documented on 14 October 2026.
 */
final class ViberTest extends TestCase {

	private const NOW = 1760000000;

	/**
	 * Start every test with no stored templates.
	 */
	protected function setUp(): void {
		delete_option( TemplateCatalog::OPTION );
		delete_option( TriggerRepository::OPTION );
	}

	/**
	 * One template as the API returns it.
	 *
	 * @param array<string,mixed> $attributes Attribute overrides.
	 * @param string              $id         Template ID.
	 * @return array<string,mixed>
	 */
	private function api_item( array $attributes = array(), string $id = 'tpl-1' ): array {
		return array(
			'type'       => 'template',
			'id'         => $id,
			'attributes' => array_merge(
				array(
					'name'                  => 'Order shipped',
					'channel'               => 'viber',
					'content'               => 'Hello {{firstName}}, order {{orderNumber}} is shipped.',
					'variables'             => array( 'firstName', 'orderNumber' ),
					'approval_status'       => 'approved',
					'message_type'          => 'transactional',
					'why_not_transactional' => null,
				),
				$attributes
			),
		);
	}

	/**
	 * Store templates in the catalog.
	 *
	 * @param array<int,array<string,mixed>> $items API items.
	 */
	private function catalog( array $items ): TemplateCatalog {
		$templates = array();
		foreach ( $items as $item ) {
			$templates[] = ViberTemplate::from_api( $item );
		}

		$catalog = new TemplateCatalog();
		$catalog->replace( array_filter( $templates ), self::NOW );

		return $catalog;
	}

	/**
	 * Order values.
	 *
	 * @param array<string,string> $overrides Overrides.
	 * @return array<string,string>
	 */
	private function values( array $overrides = array() ): array {
		return array_merge(
			array(
				'first_name'   => 'Maria',
				'order_number' => '1234',
				'shop_name'    => 'Demo Shop',
			),
			$overrides
		);
	}

	// ---- Basics ----

	/**
	 * Unknown values are SMS.
	 */
	public function test_channel_parsing_defaults_to_sms(): void {
		$this->assertSame( Channel::Viber, Channel::from_value( 'viber' ) );
		$this->assertSame( Channel::Sms, Channel::from_value( 'telegram' ) );
		$this->assertSame( Channel::Sms, Channel::from_value( null ) );
		$this->assertSame( Channel::Sms, Channel::from_value( 5 ) );
	}

	/**
	 * A template is read from the API shape and filled.
	 */
	public function test_template_parsing_and_rendering(): void {
		$template = ViberTemplate::from_api( $this->api_item() );

		$this->assertNotNull( $template );
		$this->assertSame( 'tpl-1', $template->id );
		$this->assertSame( array( 'firstName', 'orderNumber' ), $template->variables );
		$this->assertTrue( $template->is_transactional() );
		$this->assertSame(
			'Hello Maria, order 1234 is shipped.',
			$template->render(
				array(
					'firstName'   => 'Maria',
					'orderNumber' => '1234',
				)
			)
		);
		$this->assertNull( ViberTemplate::from_api( array( 'id' => 'x' ) ) );
		$this->assertSame( 'marketing', ViberTemplate::from_api( $this->api_item( array( 'message_type' => 'marketing' ) ) )?->message_type );
		$this->assertFalse(
			ViberTemplate::from_api(
				$this->api_item(
					array(
						'approval_status' => 'pending',
						'message_type'    => null,
					)
				)
			)?->is_transactional()
		);
	}

	/**
	 * Variables are matched to placeholders by name.
	 */
	public function test_variables_map_to_placeholders(): void {
		$names = Placeholders::NAMES;

		$this->assertSame( 'first_name', VariableMapper::placeholder_for( 'firstName', $names ) );
		$this->assertSame( 'order_number', VariableMapper::placeholder_for( 'order_number', $names ) );
		$this->assertSame( 'shop_name', VariableMapper::placeholder_for( 'ShopName', $names ) );
		$this->assertSame( 'first_name', VariableMapper::placeholder_for( 'name', $names ) );
		$this->assertNull( VariableMapper::placeholder_for( 'couponCode', $names ) );
		$this->assertSame( array( 'couponCode' ), VariableMapper::unmapped( array( 'firstName', 'couponCode' ), $names ) );
		$this->assertNull( VariableMapper::map( array( 'firstName', 'couponCode' ), $names ) );
		$this->assertSame( array( 'firstName' => 'first_name' ), VariableMapper::map( array( 'firstName' ), $names ) );
	}

	// ---- Template list ----

	/**
	 * A good answer replaces the stored list; a failed one keeps it; a disabled endpoint empties it.
	 */
	public function test_template_sync(): void {
		$catalog = new TemplateCatalog();

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::Success, 200, array( $this->api_item(), $this->api_item( array( 'name' => 'Other' ), 'tpl-2' ) ) ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW ) );
		$this->assertCount( 2, $catalog->all() );
		$this->assertSame( self::NOW, $catalog->fetched_at() );

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::Retryable, 0 ) );
		$this->assertFalse( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW + 10 ) );
		$this->assertCount( 2, $catalog->all() );

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::NotFound, 404 ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW + 20 ) );
		$this->assertSame( array(), $catalog->all() );
	}

	/**
	 * A fresh list is not fetched again.
	 */
	public function test_refresh_if_stale(): void {
		$catalog = $this->catalog( array( $this->api_item() ) );
		$gateway = new FakeGateway();

		$this->assertFalse( ( new TemplateSync( $gateway, $catalog ) )->refresh_if_stale( self::NOW + 60, 900 ) );
		$this->assertSame( array(), $gateway->calls );

		$gateway->will_return_for( 'templates', new ApiResult( Outcome::Success, 200, array( $this->api_item() ) ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh_if_stale( self::NOW + 1000, 900 ) );
	}

	// ---- Planning ----

	/**
	 * Everything in order: a Viber plan with the SMS fallback.
	 */
	public function test_planner_builds_a_plan(): void {
		$plan = ViberPlanner::plan( 'viber', 'tpl-1', $this->values(), 'Order 1234 shipped.', true, $this->catalog( array( $this->api_item() ) ) );

		$this->assertNotNull( $plan );
		$this->assertSame( 'tpl-1', $plan->template_id );
		$this->assertSame(
			array(
				'firstName'   => 'Maria',
				'orderNumber' => '1234',
			),
			$plan->params
		);
		$this->assertSame( 'Hello Maria, order 1234 is shipped.', $plan->text );
		$this->assertSame( 'Order 1234 shipped.', $plan->fallback_sms );
	}

	/**
	 * Any doubt means an ordinary SMS.
	 */
	public function test_planner_falls_back_to_sms_when_unsure(): void {
		$ok   = $this->catalog( array( $this->api_item() ) );
		$sms  = 'Order 1234 shipped.';
		$none = static fn( string $channel, string $tpl, array $values, string $text, bool $ready, TemplateCatalog $catalog ): ?object => ViberPlanner::plan( $channel, $tpl, $values, $text, $ready, $catalog );

		$this->assertNull( $none( 'sms', 'tpl-1', $this->values(), $sms, true, $ok ), 'SMS chosen' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values(), $sms, false, $ok ), 'Viber not available' );
		$this->assertNull( $none( 'viber', '', $this->values(), $sms, true, $ok ), 'no template chosen' );
		$this->assertNull( $none( 'viber', 'gone', $this->values(), $sms, true, $ok ), 'template not in the list' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values(), '  ', true, $ok ), 'empty SMS text' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values( array( 'first_name' => '' ) ), $sms, true, $ok ), 'a value is missing' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values( array( 'first_name' => str_repeat( 'a', 126 ) ) ), $sms, true, $ok ), 'a value is too long' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values( array( 'first_name' => 'see https://x.test' ) ), $sms, true, $ok ), 'a value has a link' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values(), str_repeat( 'a', 161 ), true, $ok ), 'SMS text longer than one part' );
		$this->assertNull( $none( 'viber', 'tpl-1', $this->values(), str_repeat( 'Я', 71 ), true, $ok ), 'Cyrillic SMS text longer than one part' );

		foreach ( array(
			array(
				'approval_status' => 'pending',
				'message_type'    => null,
			),
			array(
				'approval_status' => 'rejected',
				'message_type'    => null,
			),
			array( 'message_type' => 'marketing' ),
			array(
				'variables' => array( 'firstName', 'couponCode' ),
				'content'   => 'Hi {{firstName}} {{couponCode}}',
			),
			array(
				'variables' => array( 'firstName', 'trackingUrl' ),
				'content'   => 'Hi {{firstName}} {{trackingUrl}}',
			),
		) as $attributes ) {
			$catalog = $this->catalog( array( $this->api_item( $attributes ) ) );
			$this->assertNull( $none( 'viber', 'tpl-1', $this->values( array( 'tracking_url' => 'https://t.test/1' ) ), $sms, true, $catalog ), wp_json_encode( $attributes ) );
		}
	}

	/**
	 * The editor learns why a template is not usable.
	 */
	public function test_problem_reasons(): void {
		$problem = static fn( array $attributes ): ?string => ViberPlanner::problem( ViberTemplate::from_api( $attributes ) );

		$this->assertNull( $problem( $this->api_item() ) );
		$this->assertSame(
			ViberPlanner::PROBLEM_NOT_APPROVED,
			$problem(
				$this->api_item(
					array(
						'approval_status' => 'pending',
						'message_type'    => null,
					)
				)
			)
		);
		$this->assertSame( ViberPlanner::PROBLEM_NOT_TRANSACTIONAL, $problem( $this->api_item( array( 'message_type' => 'marketing' ) ) ) );
		$this->assertSame( ViberPlanner::PROBLEM_UNMAPPED, $problem( $this->api_item( array( 'variables' => array( 'couponCode' ) ) ) ) );
		$this->assertSame( ViberPlanner::PROBLEM_LINK, $problem( $this->api_item( array( 'variables' => array( 'orderLink' ) ) ) ) );
	}

	// ---- Request ----

	/**
	 * A Viber send carries the template, its values and the SMS fallback text.
	 */
	public function test_client_sends_template_and_fallback(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );
		$body      = array(
			'data' => array(
				'id'         => 'x',
				'attributes' => array(),
			),
		);

		$transport->will_return( new Response( 200, array(), $body ) );
		$transport->will_return( new Response( 200, array(), $body ) );

		$client->send( new SendRequest( '+359888123456', 'Hello Maria', 'viber', 'ref-1', 'ref-1', 'tpl-1', array( 'firstName' => 'Maria' ), 'Order shipped.' ) );
		$client->send( new SendRequest( '+359888123456', 'Hi', 'sms', 'ref-2', 'ref-2' ) );

		$first  = json_decode( (string) $transport->requests[0]['body'], true );
		$second = json_decode( (string) $transport->requests[1]['body'], true );

		$this->assertSame( 'viber', $first['message']['channel'] );
		$this->assertSame( 'tpl-1', $first['message']['template_id'] );
		$this->assertSame( array( 'firstName' => 'Maria' ), $first['message']['params'] );
		$this->assertSame( array( 'sms' => 'Order shipped.' ), $first['message']['fallback'] );

		foreach ( array( 'template_id', 'params', 'fallback' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $second['message'] );
		}
	}

	/**
	 * The templates request asks for every Viber template, so pending ones show in the editor.
	 */
	public function test_client_requests_templates(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );
		$transport->will_return( new Response( 404, array(), array() ) );

		$client->templates();

		$this->assertSame( 'https://api.example.test/public-api/v1/templates?status=all&channel=viber', $transport->requests[0]['url'] );
	}

	// ---- Availability ----

	/**
	 * Viber needs the templates endpoint and no refusal of the fallback; the sender list does not matter.
	 */
	public function test_viber_ready(): void {
		$ready = array(
			'templates' => true,
			'fallback'  => null,
			'balance'   => array( 'channels' => array( 'sms', 'viber' ) ),
		);

		$this->assertTrue( Capabilities::viber_ready( $ready ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'fallback' => false ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => false ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => null ) ) ) );
		$this->assertTrue( Capabilities::viber_ready( array_merge( $ready, array( 'balance' => array( 'channels' => array( 'sms' ) ) ) ) ), 'shared sender' );
		$this->assertTrue( Capabilities::viber_ready( array( 'templates' => true ) ), 'no account data needed' );
		$this->assertFalse( Capabilities::viber_ready( '' ) );
	}

	/**
	 * Each check records the templates endpoint and forgets an earlier refusal of the fallback.
	 */
	public function test_probe_records_templates_and_resets_the_fallback_flag(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', new ApiResult( Outcome::Success, 200 ) );

		$caps = Capabilities::evaluate( $gateway, array( 'fallback' => false ), self::NOW );

		$this->assertTrue( $caps['templates'] );
		$this->assertNull( $caps['fallback'] );

		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', new ApiResult( Outcome::Retryable, 0 ) );

		$this->assertTrue( Capabilities::evaluate( $gateway, array( 'templates' => true ), self::NOW )['templates'] );
	}

	/**
	 * The stored channel and template are read back.
	 */
	public function test_trigger_reads_the_stored_channel_and_template(): void {
		update_option(
			TriggerRepository::OPTION,
			array(
				'processing' => array(
					'channel'        => 'viber',
					'viber_template' => 'tpl-1',
				),
				'completed'  => array( 'channel' => 'fax' ),
			)
		);

		$repository = new TriggerRepository( 'en_US' );

		$this->assertSame( 'viber', $repository->for_status( 'processing' )->channel );
		$this->assertSame( 'tpl-1', $repository->for_status( 'processing' )->viber_template );
		$this->assertSame( 'sms', $repository->for_status( 'completed' )->channel );
		$this->assertSame( '', $repository->for_status( 'cancelled' )->viber_template );
	}

	/**
	 * The page can say why Viber is missing.
	 */
	public function test_viber_status_explains_why(): void {
		$channels = array( 'balance' => array( 'channels' => array( 'sms', 'viber' ) ) );

		$this->assertSame( Capabilities::VIBER_NOT_CHECKED, Capabilities::viber_status( '' ) );
		$this->assertSame( Capabilities::VIBER_NOT_CHECKED, Capabilities::viber_status( array( 'templates' => null ) ) );
		$this->assertSame( Capabilities::VIBER_TEMPLATES_OFF, Capabilities::viber_status( array( 'templates' => false ) + $channels ) );
		$this->assertSame( Capabilities::VIBER_READY, Capabilities::viber_status( array( 'templates' => true ) ) );
		$this->assertSame(
			Capabilities::VIBER_FALLBACK_REFUSED,
			Capabilities::viber_status(
				array(
					'templates' => true,
					'fallback'  => false,
				) + $channels
			)
		);
		$this->assertSame(
			Capabilities::VIBER_READY,
			Capabilities::viber_status(
				array(
					'templates' => true,
					'fallback'  => null,
				) + $channels
			)
		);
	}

	/**
	 * The webhook self-test result is read from the answer.
	 */
	public function test_webhook_self_test_results(): void {
		$this->assertSame( SelfTest::OK, SelfTest::interpret( 200, array( 'result' => 'test' ) ) );
		$this->assertSame( SelfTest::UNEXPECTED, SelfTest::interpret( 200, array( 'result' => 'updated' ) ) );
		$this->assertSame( SelfTest::NOT_FOUND, SelfTest::interpret( 404, array() ) );
		$this->assertSame( SelfTest::BLOCKED, SelfTest::interpret( 403, array() ) );
		$this->assertSame( SelfTest::UNREACHABLE, SelfTest::interpret( 0, array() ) );
		$this->assertSame( SelfTest::UNEXPECTED, SelfTest::interpret( 500, array() ) );
		$this->assertSame( 'povikvane.self_test', json_decode( SelfTest::body(), true )['event'] );
	}

	// ---- Order event ----

	/**
	 * Build a handler with a Viber planner.
	 *
	 * @param InMemoryStore      $store     Store.
	 * @param RecordingScheduler $scheduler Scheduler.
	 */
	private function handler( InMemoryStore $store, RecordingScheduler $scheduler ): OrderEventHandler {
		$clock   = new FixedClock( self::NOW );
		$state   = new InMemoryState();
		$catalog = $this->catalog( array( $this->api_item() ) );

		return new OrderEventHandler(
			$store,
			new Enqueuer( $store, $scheduler, $clock ),
			new Normalizer(),
			new StormGuard( $store, $state, $clock, 50, 600 ),
			$state,
			new RecordingNotifier(),
			$clock,
			'ab12cd34',
			new Rules(),
			static fn(): bool => true,
			static fn( Trigger $trigger, array $values, string $text ) => ViberPlanner::plan( $trigger->channel, $trigger->viber_template, $values, $text, true, $catalog )
		);
	}

	/**
	 * Order facts.
	 */
	private function facts(): OrderFacts {
		return new OrderFacts( 1234, 'completed', self::NOW - 3600, 'checkout', '0888 123 456', '+359', $this->values() );
	}

	/**
	 * A Viber status queues a Viber message with the template and the SMS text.
	 */
	public function test_order_event_queues_a_viber_message(): void {
		$store = new InMemoryStore();
		$this->handler( $store, new RecordingScheduler() )->handle( $this->facts(), new Trigger( 'completed', true, 'Order {order_number} shipped.', 0, 'viber', 'tpl-1' ) );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertSame( 'viber', $message->channel );
		$this->assertSame( 'Hello Maria, order 1234 is shipped.', $message->body );
		$this->assertSame(
			array(
				'template_id'  => 'tpl-1',
				'params'       => array(
					'firstName'   => 'Maria',
					'orderNumber' => '1234',
				),
				'fallback_sms' => 'Order 1234 shipped.',
			),
			$message->viber
		);
	}

	/**
	 * A status set to SMS, or a Viber status with no usable template, queues an SMS.
	 */
	public function test_order_event_queues_sms_otherwise(): void {
		foreach ( array(
			new Trigger( 'completed', true, 'Order {order_number} shipped.', 0, 'sms', 'tpl-1' ),
			new Trigger( 'completed', true, 'Order {order_number} shipped.', 0, 'viber', '' ),
			new Trigger( 'completed', true, 'Order {order_number} shipped.', 0, 'viber', 'gone' ),
		) as $trigger ) {
			$store = new InMemoryStore();
			$this->handler( $store, new RecordingScheduler() )->handle( $this->facts(), $trigger );

			$message = $store->find( 1 );
			$this->assertNotNull( $message );
			$this->assertSame( 'sms', $message->channel );
			$this->assertSame( 'Order 1234 shipped.', $message->body );
			$this->assertNull( $message->viber );
		}
	}

	// ---- Sending ----

	/**
	 * Build a dispatcher with one pending Viber message.
	 *
	 * @param FakeGateway   $gateway Gateway.
	 * @param InMemoryStore $store   Store.
	 * @param callable|null $refused Called when the fallback is refused.
	 */
	private function viber_dispatcher( FakeGateway $gateway, InMemoryStore $store, ?callable $refused = null ): Dispatcher {
		$clock   = new FixedClock( self::NOW );
		$message = new Message();

		$message->order_id   = 1234;
		$message->event      = 'completed';
		$message->channel    = 'viber';
		$message->recipient  = '+359888123456';
		$message->body       = 'Hello Maria, order 1234 is shipped.';
		$message->reference  = 'woo:ab12cd34:1234:completed:1';
		$message->created_at = self::NOW;
		$message->updated_at = self::NOW;
		$message->viber      = array(
			'template_id'  => 'tpl-1',
			'params'       => array( 'firstName' => 'Maria' ),
			'fallback_sms' => 'Order 1234 shipped.',
		);
		$store->create( $message );

		return new Dispatcher( $store, $gateway, new RecordingScheduler(), $clock, new RecordingNotifier(), new InMemoryState(), 86400, new \Povikvane\WooCommerce\Support\NullLogger(), null, null, null, $refused );
	}

	/**
	 * The gateway sees the template, values and fallback.
	 */
	public function test_dispatcher_sends_the_viber_request(): void {
		$gateway = ( new FakeGateway() )->will_return(
			new ApiResult(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'segments'        => 1,
						'credits-charged' => 1,
					),
				),
				null,
				null,
				null,
				'api-1'
			)
		);
		$store   = new InMemoryStore();

		$this->viber_dispatcher( $gateway, $store )->handle( 1 );

		$request = $gateway->sent[0];
		$this->assertSame( 'viber', $request->channel );
		$this->assertSame( 'tpl-1', $request->template_id );
		$this->assertSame( array( 'firstName' => 'Maria' ), $request->params );
		$this->assertSame( 'Order 1234 shipped.', $request->fallback_sms );
		$this->assertSame( Status::Queued, $store->find( 1 )?->status );
	}

	/**
	 * A refused fallback sends the message as an SMS and tells the plugin to stop offering Viber.
	 */
	public function test_refused_fallback_reroutes_to_sms(): void {
		$told    = 0;
		$gateway = ( new FakeGateway() )
			->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'SMS fallback is not enabled for this account', null, null, null, 'invalid_fallback' ) )
			->will_return(
				new ApiResult(
					Outcome::Success,
					200,
					array(
						'attributes' => array(
							'segments'        => 1,
							'credits-charged' => 1,
						),
					),
					null,
					null,
					null,
					'api-1'
				)
			);
		$store   = new InMemoryStore();
		$counter = static function () use ( &$told ): void {
			++$told;
		};

		$dispatcher = $this->viber_dispatcher( $gateway, $store, $counter );
		$dispatcher->handle( 1 );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertSame( 1, $told );
		$this->assertSame( Status::Pending, $message->status );
		$this->assertSame( 'sms', $message->channel );
		$this->assertSame( 'Order 1234 shipped.', $message->body );
		$this->assertNull( $message->viber );
		$this->assertSame( 0, $message->attempts );

		$dispatcher->handle( 1 );

		$this->assertSame( 'sms', $gateway->sent[1]->channel );
		$this->assertNull( $gateway->sent[1]->template_id );
		$this->assertNull( $gateway->sent[1]->fallback_sms );
		$this->assertSame( Status::Queued, $store->find( 1 )?->status );
	}

	/**
	 * Any other refusal of a Viber message also becomes an SMS, without changing availability.
	 */
	public function test_other_refusal_reroutes_without_changing_availability(): void {
		$told    = 0;
		$gateway = ( new FakeGateway() )->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'Template not approved' ) );
		$store   = new InMemoryStore();

		$this->viber_dispatcher(
			$gateway,
			$store,
			static function () use ( &$told ): void {
				++$told;
			}
		)->handle( 1 );

		$this->assertSame( 0, $told );
		$this->assertSame( 'sms', $store->find( 1 )?->channel );
		$this->assertSame( Status::Pending, $store->find( 1 )?->status );
	}

	/**
	 * A refused SMS stays failed.
	 */
	public function test_refused_sms_still_fails(): void {
		$gateway = ( new FakeGateway() )->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'Bad request' ) );
		$store   = new InMemoryStore();

		$dispatcher = $this->viber_dispatcher( $gateway, $store );
		$message    = $store->find( 1 );
		$this->assertNotNull( $message );
		$message->channel = 'sms';
		$message->viber   = null;
		$store->save( $message );

		$dispatcher->handle( 1 );

		$this->assertSame( Status::Failed, $store->find( 1 )?->status );
	}

	// ---- Delivery ----

	/**
	 * Build a queued message.
	 *
	 * @param InMemoryStore $store Store.
	 */
	private function queued( InMemoryStore $store ): Message {
		$message                 = new Message();
		$message->order_id       = 1;
		$message->event          = 'completed';
		$message->channel        = 'viber';
		$message->recipient      = '+359888123456';
		$message->body           = 'Hi';
		$message->reference      = 'woo:ab12cd34:1:completed:1';
		$message->status         = Status::Queued;
		$message->api_message_id = 'api-1';
		$message->credits        = 1.0;
		$message->created_at     = self::NOW - 60;
		$message->updated_at     = self::NOW - 60;
		$store->create( $message );

		return $message;
	}

	/**
	 * The webhook records which channel delivered.
	 */
	public function test_webhook_records_the_delivering_channel(): void {
		$store   = new InMemoryStore();
		$clock   = new FixedClock( self::NOW );
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), $clock );
		$this->queued( $store );

		$result = ( new WebhookHandler( $store, $updater, new InMemoryActivity(), $clock ) )->handle(
			array(
				'event' => 'message.status_updated',
				'data'  => array(
					'id'            => 'api-1',
					'status'        => 'delivered',
					'delivered_via' => 'sms',
				),
			)
		);

		$this->assertSame( WebhookHandler::RESULT_UPDATED, $result );
		$message = $store->find_by_api_message_id( 'api-1' );
		$this->assertNotNull( $message );
		$this->assertSame( 'viber', $message->channel );
		$this->assertSame( 'sms', $message->delivered_via );
	}

	/**
	 * A reported channel the plugin does not know is ignored.
	 */
	public function test_unknown_delivering_channel_is_ignored(): void {
		$store   = new InMemoryStore();
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), new FixedClock( self::NOW ) );
		$message = $this->queued( $store );

		$updater->delivered( $message, 'carrier-pigeon' );

		$this->assertSame( Status::Delivered, $message->status );
		$this->assertNull( $message->delivered_via );
	}

	/**
	 * The status check records the delivering channel and the settled charge.
	 */
	public function test_reconciler_records_channel_and_settled_credits(): void {
		$store   = new InMemoryStore();
		$clock   = new FixedClock( self::NOW );
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), $clock );
		$message = $this->queued( $store );

		$message->next_attempt_at = self::NOW - 1;
		$store->save( $message );

		$gateway = ( new FakeGateway() )->will_return_for(
			'status',
			new ApiResult(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'status'          => 'delivered_to_handset',
						'delivered_via'   => 'viber',
						'credits-charged' => 0.8,
					),
				)
			)
		);

		( new Reconciler( $store, $gateway, $updater, new InMemoryState(), $clock ) )->run();

		$stored = $store->find( (int) $message->id );
		$this->assertNotNull( $stored );
		$this->assertSame( Status::Delivered, $stored->status );
		$this->assertSame( 'viber', $stored->delivered_via );
		$this->assertSame( 0.8, $stored->credits );
	}

	/**
	 * Old message text and Viber values are erased together.
	 */
	public function test_retention_erases_viber_values(): void {
		$store   = new InMemoryStore();
		$message = $this->queued( $store );

		$message->status = Status::Delivered;
		$message->viber  = array(
			'template_id'  => 'tpl-1',
			'params'       => array( 'firstName' => 'Maria' ),
			'fallback_sms' => 'Order shipped.',
		);
		$store->save( $message );

		$store->purge_bodies( self::NOW );

		$this->assertNull( $store->find( 1 )?->viber );
		$this->assertSame( '', $store->find( 1 )?->body );
	}
}
