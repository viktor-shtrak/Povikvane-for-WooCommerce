<?php
/**
 * Tests that logging never leaks secrets.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Support\LogFormatter;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Tests\Support\RecordingLogger;

/**
 * Log content safety.
 */
final class LoggingTest extends TestCase {

	/**
	 * Context keys that look secret are replaced.
	 */
	public function test_formatter_hides_secret_looking_keys(): void {
		$line = LogFormatter::format(
			'API call',
			array(
				'api_key'       => 'super-secret-key',
				'Authorization' => 'Bearer super-secret-key',
				'token'         => 'abc',
				'status'        => 200,
			)
		);

		$this->assertStringNotContainsString( 'super-secret-key', $line );
		$this->assertStringNotContainsString( 'abc', $line );
		$this->assertStringContainsString( 'api_key=[hidden]', $line );
		$this->assertStringContainsString( 'status=200', $line );
	}

	/**
	 * Values are rendered safely on one line.
	 */
	public function test_formatter_renders_values_on_one_line(): void {
		$line = LogFormatter::format(
			'x',
			array(
				'none'  => null,
				'yes'   => true,
				'multi' => "a\nb   c",
				'long'  => str_repeat( 'z', 500 ),
				'list'  => array( 1 ),
			)
		);

		$this->assertStringContainsString( 'none=null', $line );
		$this->assertStringContainsString( 'yes=true', $line );
		$this->assertStringContainsString( 'multi=a b c', $line );
		$this->assertStringContainsString( 'list=[array]', $line );
		$this->assertLessThan( 400, strlen( $line ) );
		$this->assertSame( 'plain', LogFormatter::format( 'plain', array() ) );
	}

	/**
	 * Phone masking.
	 */
	public function test_phone_is_masked(): void {
		$this->assertSame( '+359…456', Mask::phone( '+359888123456' ) );
		$this->assertSame( '+3591234', Mask::phone( '+3591234' ) );
	}

	/**
	 * The client logs requests without credentials, recipient or message text.
	 */
	public function test_client_logs_no_credentials_recipient_or_text(): void {
		$logger    = new RecordingLogger();
		$transport = new FakeTransport();
		$transport->will_return(
			new Response(
				429,
				array(
					'retry-after'  => '5',
					'x-request-id' => 'req-9',
				),
				array( 'errors' => array( array( 'detail' => 'Slow down' ) ) )
			)
		);

		$client = new Client( $transport, new Credentials( 'super-secret-key', 'svc-456' ), 'https://api.example.test', $logger );
		$client->send( new SendRequest( '+359888123456', 'Private message text', 'sms', 'woo:ab:1:processing:1', 'woo:ab:1:processing:1' ) );

		$this->assertCount( 1, $logger->lines );
		$line = $logger->lines[0];

		$this->assertStringContainsString( 'API POST /public-api/v1/sms', $line );
		$this->assertStringContainsString( 'status=429', $line );
		$this->assertStringContainsString( 'request_id=req-9', $line );
		$this->assertStringNotContainsString( 'super-secret-key', $line );
		$this->assertStringNotContainsString( 'svc-456', $line );
		$this->assertStringNotContainsString( '+359888123456', $line );
		$this->assertStringNotContainsString( 'Private message text', $line );
	}
}
