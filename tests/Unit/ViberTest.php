<?php
/**
 * Tests for the Viber groundwork: channel choice, fallback request and reported channel.
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
use Povikvane\WooCommerce\Messages\ChannelPlanner;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryActivity;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

/**
 * Viber behaviour that does not depend on the unreleased API fields.
 */
final class ViberTest extends TestCase {

	private const NOW = 1760000000;

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
	 * Viber is used only when asked for and available.
	 */
	public function test_planner_never_uses_viber_unless_ready(): void {
		$this->assertSame( Channel::Viber, ChannelPlanner::plan( Channel::Viber, true ) );
		$this->assertSame( Channel::Sms, ChannelPlanner::plan( Channel::Viber, false ) );
		$this->assertSame( Channel::Sms, ChannelPlanner::plan( Channel::Sms, true ) );
		$this->assertSame( 'sms', ChannelPlanner::fallback_for( 'viber' ) );
		$this->assertNull( ChannelPlanner::fallback_for( 'sms' ) );
	}

	/**
	 * The "fallback" field is sent for Viber only.
	 */
	public function test_client_sends_fallback_only_when_asked(): void {
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

		$client->send( new SendRequest( '+359888123456', 'Hi', 'viber', 'ref-1', 'ref-1', 'sms' ) );
		$client->send( new SendRequest( '+359888123456', 'Hi', 'sms', 'ref-2', 'ref-2' ) );

		$first  = json_decode( (string) $transport->requests[0]['body'], true );
		$second = json_decode( (string) $transport->requests[1]['body'], true );

		$this->assertSame( 'viber', $first['message']['channel'] );
		$this->assertSame( 'sms', $first['message']['fallback'] );
		$this->assertArrayNotHasKey( 'fallback', $second['message'] );
	}

	/**
	 * The templates endpoint is requested from the right path.
	 */
	public function test_client_requests_templates(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );
		$transport->will_return( new Response( 404, array(), array() ) );

		$client->templates();

		$this->assertSame( 'https://api.example.test/public-api/v1/templates', $transport->requests[0]['url'] );
	}

	/**
	 * Viber needs a Viber sender and the templates endpoint.
	 */
	public function test_viber_ready_needs_channel_and_templates(): void {
		$ready = array(
			'templates' => true,
			'balance'   => array( 'channels' => array( 'sms', 'viber' ) ),
		);

		$this->assertTrue( Capabilities::viber_ready( $ready ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => false ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => null ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'balance' => array( 'channels' => array( 'sms' ) ) ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array( 'templates' => true ) ) );
		$this->assertFalse( Capabilities::viber_ready( '' ) );
	}

	/**
	 * The probe records whether the templates endpoint exists, and keeps the old answer when unsure.
	 */
	public function test_probe_records_templates_availability(): void {
		$ok = new ApiResult( Outcome::Success, 200 );

		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', $ok );

		$caps = Capabilities::evaluate( $gateway, array(), self::NOW );
		$this->assertTrue( $caps['templates'] );

		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', new ApiResult( Outcome::Retryable, 0 ) );

		$caps = Capabilities::evaluate( $gateway, array( 'templates' => true ), self::NOW );
		$this->assertTrue( $caps['templates'] );
	}

	/**
	 * A stored channel is read back, and unknown or missing values are SMS.
	 */
	public function test_trigger_reads_the_stored_channel(): void {
		update_option(
			TriggerRepository::OPTION,
			array(
				'processing' => array( 'channel' => 'viber' ),
				'completed'  => array( 'channel' => 'fax' ),
			)
		);

		$repository = new TriggerRepository( 'en_US' );

		$this->assertSame( 'viber', $repository->for_status( 'processing' )->channel );
		$this->assertSame( 'sms', $repository->for_status( 'completed' )->channel );
		$this->assertSame( 'sms', $repository->for_status( 'cancelled' )->channel );

		delete_option( TriggerRepository::OPTION );
	}

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
	 * The status check reads the delivering channel too.
	 */
	public function test_reconciler_records_the_delivering_channel(): void {
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
						'status'        => 'delivered_to_handset',
						'delivered_via' => 'viber',
					),
				)
			)
		);

		( new Reconciler( $store, $gateway, $updater, new InMemoryState(), $clock ) )->run();

		$stored = $store->find( (int) $message->id );
		$this->assertNotNull( $stored );
		$this->assertSame( Status::Delivered, $stored->status );
		$this->assertSame( 'viber', $stored->delivered_via );
	}
}
