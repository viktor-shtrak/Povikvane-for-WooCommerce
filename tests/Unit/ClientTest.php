<?php
/**
 * Tests for the API client.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;

/**
 * Request building and response parsing, with no network.
 */
final class ClientTest extends TestCase {

	private FakeTransport $transport;

	private Client $client;

	/**
	 * Fresh client per test.
	 */
	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->client    = new Client( $this->transport, new Credentials( 'key-123', 'svc-456' ), 'https://api.example.test/' );
	}

	/**
	 * A realistic send success.
	 *
	 * @return array<string,mixed>
	 */
	private function success_body(): array {
		return array(
			'data' => array(
				'type'       => 'sms',
				'id'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
				'attributes' => array(
					'status'          => 'queued_on_smsc',
					'segments'        => 1,
					'credits-charged' => 1,
				),
			),
		);
	}

	/**
	 * The send request carries auth, idempotency key, service ID and reference.
	 */
	public function test_send_builds_the_request(): void {
		$this->transport->will_return( new Response( 200, array(), $this->success_body() ) );

		$this->client->send( new SendRequest( '+359888123456', 'Hello', 'sms', 'woo:ab:1:processing:1', 'woo:ab:1:processing:1' ) );

		$request = $this->transport->requests[0];
		$body    = json_decode( (string) $request['body'], true );

		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/sms', $request['url'] );
		$this->assertSame( 'Bearer key-123', $request['headers']['Authorization'] );
		$this->assertSame( 'woo:ab:1:processing:1', $request['headers']['Idempotency-Key'] );
		$this->assertSame( 'svc-456', $body['service-id'] );
		$this->assertSame(
			array(
				'to'        => '+359888123456',
				'text'      => 'Hello',
				'channel'   => 'sms',
				'reference' => 'woo:ab:1:processing:1',
			),
			$body['message']
		);
	}

	/**
	 * The API rejects "reference": null, so the key must be absent without a reference.
	 */
	public function test_send_omits_reference_and_idempotency_key_when_absent(): void {
		$this->transport->will_return( new Response( 200, array(), $this->success_body() ) );

		$this->client->send( new SendRequest( '+359888123456', 'Hello' ) );

		$request = $this->transport->requests[0];
		$body    = json_decode( (string) $request['body'], true );

		$this->assertArrayNotHasKey( 'reference', $body['message'] );
		$this->assertStringNotContainsString( 'reference', (string) $request['body'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $request['headers'] );
	}

	/**
	 * Parse a successful send.
	 */
	public function test_send_success_is_parsed(): void {
		$this->transport->will_return( new Response( 200, array( 'x-request-id' => 'req-1' ), $this->success_body() ) );

		$result = $this->client->send( new SendRequest( '+359888123456', 'Hello' ) );

		$this->assertSame( Outcome::Success, $result->outcome );
		$this->assertSame( 'f47ac10b-58cc-4372-a567-0e02b2c3d479', $result->message_id );
		$this->assertSame( 'req-1', $result->request_id );
		$this->assertSame( 1, $result->attributes()['credits-charged'] );
	}

	/**
	 * The 504 body carries the message ID in the error meta.
	 */
	public function test_504_exposes_the_message_id(): void {
		$this->transport->will_return(
			new Response(
				504,
				array(),
				array(
					'errors' => array(
						array(
							'status' => '504',
							'title'  => 'Gateway Timeout',
							'detail' => 'Message delivery status is uncertain. Check status later using the message ID.',
							'meta'   => array( 'message-id' => 'abc-123' ),
						),
					),
				)
			)
		);

		$result = $this->client->send( new SendRequest( '+359888123456', 'Hello' ) );

		$this->assertSame( Outcome::Uncertain, $result->outcome );
		$this->assertSame( 'abc-123', $result->message_id );
	}

	/**
	 * A 429 reports Retry-After.
	 */
	public function test_429_reads_retry_after(): void {
		$this->transport->will_return( new Response( 429, array( 'retry-after' => '15' ), array( 'errors' => array( array( 'detail' => 'Slow down' ) ) ) ) );

		$result = $this->client->send( new SendRequest( '+359888123456', 'Hello' ) );

		$this->assertSame( Outcome::RateLimited, $result->outcome );
		$this->assertSame( 15, $result->retry_after );
		$this->assertSame( 'Slow down', $result->detail );
	}

	/**
	 * Transport errors become retryable results with the error text.
	 */
	public function test_transport_error_is_retryable(): void {
		$this->transport->will_return( new Response( 0, array(), null, 'timed out' ) );

		$result = $this->client->send( new SendRequest( '+359888123456', 'Hello' ) );

		$this->assertSame( Outcome::Retryable, $result->outcome );
		$this->assertSame( 0, $result->http_status );
		$this->assertSame( 'timed out', $result->detail );
	}

	/**
	 * GETs keep the service ID out of the URL.
	 */
	public function test_status_request_uses_header_for_service_id(): void {
		$this->transport->will_return( new Response( 200, array(), $this->success_body() ) );

		$this->client->message_status( 'f47ac10b-58cc-4372-a567-0e02b2c3d479' );

		$request = $this->transport->requests[0];

		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/sms/f47ac10b-58cc-4372-a567-0e02b2c3d479', $request['url'] );
		$this->assertSame( 'svc-456', $request['headers']['x-service-id'] );
		$this->assertNull( $request['body'] );
	}

	/**
	 * The reference is URL-encoded in the lookup.
	 */
	public function test_lookup_encodes_the_reference(): void {
		$this->transport->will_return( new Response( 200, array(), array( 'data' => array() ) ) );

		$this->client->find_by_reference( 'woo:ab12cd34:1234:processing:1' );

		$this->assertSame(
			'https://api.example.test/public-api/v1/sms?reference=woo%3Aab12cd34%3A1234%3Aprocessing%3A1',
			$this->transport->requests[0]['url']
		);
	}

	/**
	 * A flagged endpoint that is off answers 404, which means "not available".
	 */
	public function test_account_404_means_unavailable(): void {
		$this->transport->will_return( new Response( 404, array(), array( 'errors' => array( array( 'detail' => 'Not found' ) ) ) ) );

		$this->assertSame( Outcome::NotFound, $this->client->account()->outcome );
		$this->assertSame( 'https://api.example.test/public-api/v1/account', $this->transport->requests[0]['url'] );
	}

	/**
	 * Credentials never leak through var_dump or print_r style output.
	 */
	public function test_credentials_hide_the_api_key_when_dumped(): void {
		ob_start();
		var_dump( new Credentials( 'super-secret-key', 'svc-456' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- Asserting that secrets are hidden.
		$dump = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'super-secret-key', $dump );
		$this->assertStringContainsString( 'svc-456', $dump );
	}

	/**
	 * The error code is exposed on the result when the service sends one.
	 */
	public function test_error_code_is_exposed(): void {
		$this->transport->will_return(
			new Response(
				422,
				array(),
				array(
					'errors' => array(
						array(
							'code'   => 'invalid_reference',
							'detail' => 'Bad reference',
						),
					),
				)
			)
		);
		$this->transport->will_return( new Response( 429, array(), array( 'errors' => array( array( 'detail' => 'No code here' ) ) ) ) );

		$this->assertSame( 'invalid_reference', $this->client->send( new SendRequest( '+359888123456', 'Hi' ) )->error_code );
		$this->assertNull( $this->client->send( new SendRequest( '+359888123456', 'Hi' ) )->error_code );
	}
}
