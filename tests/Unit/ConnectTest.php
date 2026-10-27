<?php
/**
 * Tests for "Connect with Povikvane".
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Connect\ConnectFlow;
use Povikvane\WooCommerce\Connect\ConnectionStore;
use Povikvane\WooCommerce\Connect\ConnectResult;
use Povikvane\WooCommerce\Support\Crypto;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Webhook\WebhookSecret;

/**
 * The browser flow, the code exchange, what is stored and what happens on revocation.
 */
final class ConnectTest extends TestCase {

	private const SITE     = 'https://shop.example';
	private const CALLBACK = 'https://shop.example/wp-admin/admin-post.php?action=povikvane_connect_callback';

	private Settings $settings;
	private WebhookSecret $secret;
	private ConnectionStore $store;

	/**
	 * Fresh options per test.
	 */
	protected function setUp(): void {
		$GLOBALS['povikvane_test_options']    = array();
		$GLOBALS['povikvane_test_transients'] = array();

		$crypto         = new Crypto( 'salt' );
		$this->settings = new Settings( $crypto );
		$this->secret   = new WebhookSecret( $crypto );
		$this->store    = new ConnectionStore( $this->settings, $this->secret );
	}

	/**
	 * A good exchange answer.
	 *
	 * @param bool $webhook Whether a webhook was registered.
	 * @return ApiResult
	 */
	private function exchange_ok( bool $webhook = true ): ApiResult {
		return new ApiResult(
			Outcome::Success,
			200,
			array(
				'type'       => 'connection',
				'id'         => 'key-1',
				'attributes' => array(
					'api_key'      => 'pvk_live_secretkey',
					'key_prefix'   => 'pvk_live',
					'service_id'   => 'svc-777',
					'company'      => array( 'name' => 'Example Company' ),
					'site_url'     => 'https://shop.example/',
					'site_name'    => 'Example Shop',
					'connected_at' => '2026-10-31T10:00:00.000Z',
					'webhook'      => $webhook ? array(
						'url'    => 'https://shop.example/wp-json/povikvane/v1/dlr/abc',
						'secret' => 'whsec_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
					) : null,
				),
			)
		);
	}

	/**
	 * Start a flow and return the state and the approval URL's query.
	 *
	 * @param ConnectFlow $flow Flow.
	 * @return array{state:string,query:array<string,string>}
	 */
	private function start( ConnectFlow $flow ): array {
		$url = $flow->begin( 5, 'wizard', self::SITE, 'Example Shop', self::CALLBACK, 'https://shop.example/wp-json/povikvane/v1/dlr/abc' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test.

		$this->assertStringStartsWith( 'https://app.example.test/connect/authorize?', $url );

		return array(
			'state' => $query['state'],
			'query' => $query,
		);
	}

	/**
	 * The PKCE helpers match the standard's example.
	 */
	public function test_pkce_challenge_matches_the_rfc_vector(): void {
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', ConnectFlow::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', ConnectFlow::base64url( "\xfb\xff\xfe" ) );
	}

	/**
	 * The approval address carries every required parameter, and never the verifier.
	 */
	public function test_authorisation_url_has_the_required_parameters(): void {
		$flow  = new ConnectFlow( new FakeGateway(), $this->store, 'https://app.example.test/' );
		$start = $this->start( $flow );
		$query = $start['query'];

		$this->assertSame( self::SITE, $query['site_url'] );
		$this->assertSame( 'Example Shop', $query['site_name'] );
		$this->assertSame( self::CALLBACK, $query['redirect_uri'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 43, strlen( $query['code_challenge'] ) );
		$this->assertSame( 43, strlen( $query['state'] ) );
		$this->assertSame( 'https://shop.example/wp-json/povikvane/v1/dlr/abc', $query['webhook_url'] );
		$this->assertArrayNotHasKey( 'code_verifier', $query );
	}

	/**
	 * The webhook address is left out when none is passed.
	 */
	public function test_webhook_is_optional(): void {
		$flow = new ConnectFlow( new FakeGateway(), $this->store, 'https://app.example.test' );
		$url  = $flow->begin( 5, 'settings', self::SITE, '', self::CALLBACK, null );

		$this->assertStringNotContainsString( 'webhook_url', $url );
		$this->assertStringContainsString( 'site_name=shop.example', $url );
	}

	/**
	 * A good callback exchanges the code with the stored verifier and keeps the result.
	 */
	public function test_callback_exchanges_the_code_and_stores_the_result(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', $this->exchange_ok() );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );
		$start   = $this->start( $flow );

		$result = $flow->complete(
			array(
				'code'  => 'one-time',
				'state' => $start['state'],
			),
			5
		);

		$this->assertSame( ConnectResult::CONNECTED, $result->status );
		$this->assertSame( 'wizard', $result->origin );
		$this->assertSame( 'Example Shop', $result->site_name );
		$this->assertTrue( $result->webhook );

		$sent = $gateway->writes[0]['args'];
		$this->assertSame( 'one-time', $sent['code'] );
		$this->assertSame( self::CALLBACK, $sent['redirect'] );
		$this->assertSame( $start['query']['code_challenge'], ConnectFlow::challenge( $sent['verifier'] ) );

		$this->assertSame( 'pvk_live_secretkey', $this->settings->api_key() );
		$this->assertSame( 'svc-777', $this->settings->service_id() );
		$this->assertSame( 'whsec_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8', $this->secret->get() );
		$this->assertSame( 'Example Company', $this->store->info()['company'] ?? null );
		$this->assertStringNotContainsString( 'pvk_live_secretkey', (string) wp_json_encode( get_option( ConnectionStore::OPTION ) ) );
	}

	/**
	 * A wrong state is refused without contacting Povikvane, and a state works once.
	 */
	public function test_state_is_checked_and_single_use(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', $this->exchange_ok() );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );
		$start   = $this->start( $flow );

		$this->assertSame(
			ConnectResult::INVALID,
			$flow->complete(
				array(
					'code'  => 'c',
					'state' => 'forged',
				),
				5
			)->status
		);
		$this->assertSame( ConnectResult::INVALID, $flow->complete( array( 'code' => 'c' ), 5 )->status );
		$this->assertSame( array(), $gateway->writes );

		$this->assertSame(
			ConnectResult::CONNECTED,
			$flow->complete(
				array(
					'code'  => 'c',
					'state' => $start['state'],
				),
				5
			)->status
		);
		$this->assertSame(
			ConnectResult::INVALID,
			$flow->complete(
				array(
					'code'  => 'c',
					'state' => $start['state'],
				),
				5
			)->status
		);
		$this->assertCount( 1, $gateway->writes );
	}

	/**
	 * Only the administrator who started the flow can finish it.
	 */
	public function test_another_user_cannot_finish_the_flow(): void {
		$gateway = new FakeGateway();
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );
		$start   = $this->start( $flow );

		$this->assertSame(
			ConnectResult::INVALID,
			$flow->complete(
				array(
					'code'  => 'c',
					'state' => $start['state'],
				),
				9
			)->status
		);
		$this->assertSame( array(), $gateway->writes );
	}

	/**
	 * Declining changes nothing; a failed exchange is not retried and stores nothing.
	 */
	public function test_declined_and_failed_attempts_store_nothing(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', new ApiResult( Outcome::PermanentFailure, 400, array(), 'x', null, null, null, 'invalid_grant' ) );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );

		$declined = $flow->complete(
			array(
				'error' => 'access_denied',
				'state' => $this->start( $flow )['state'],
			),
			5
		);
		$this->assertSame( ConnectResult::DECLINED, $declined->status );
		$this->assertSame( array(), $gateway->writes );

		$failed = $flow->complete(
			array(
				'code'  => 'c',
				'state' => $this->start( $flow )['state'],
			),
			5
		);
		$this->assertSame( ConnectResult::FAILED, $failed->status );
		$this->assertSame( '', $this->settings->api_key() );
		$this->assertNull( $this->store->info() );
		$this->assertCount( 1, $gateway->writes );
	}

	/**
	 * An answer that is missing something is not stored.
	 */
	public function test_incomplete_answer_is_rejected(): void {
		$broken = $this->exchange_ok();
		$data   = $broken->data;
		unset( $data['attributes']['service_id'] );
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', new ApiResult( Outcome::Success, 200, $data ) );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );

		$this->assertSame(
			ConnectResult::FAILED,
			$flow->complete(
				array(
					'code'  => 'c',
					'state' => $this->start( $flow )['state'],
				),
				5
			)->status
		);
		$this->assertSame( '', $this->settings->api_key() );

		$bad_secret                                    = $this->exchange_ok()->data;
		$bad_secret['attributes']['webhook']['secret'] = 'not-a-secret';
		$this->assertFalse( ConnectFlow::valid_response( $bad_secret, $bad_secret['attributes'] ) );
	}

	/**
	 * Connecting without a webhook removes a secret left from an earlier key.
	 */
	public function test_connecting_without_webhook_drops_the_old_secret(): void {
		$this->secret->set( 'whsec_old' );
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', $this->exchange_ok( false ) );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );

		$result = $flow->complete(
			array(
				'code'  => 'c',
				'state' => $this->start( $flow )['state'],
			),
			5
		);

		$this->assertFalse( $result->webhook );
		$this->assertFalse( $this->secret->exists() );
	}

	/**
	 * Which sites can use the button.
	 */
	public function test_site_requirements(): void {
		$this->assertNull( ConnectFlow::problem( self::SITE, self::CALLBACK ) );
		$this->assertNull( ConnectFlow::problem( 'http://shop.test', 'http://shop.test/wp-admin/admin-post.php' ) );
		$this->assertNull( ConnectFlow::problem( 'http://localhost:8080', 'http://localhost:8080/wp-admin/admin-post.php' ) );
		$this->assertSame( ConnectFlow::PROBLEM_SITE_NOT_HTTPS, ConnectFlow::problem( 'http://shop.example', 'http://shop.example/wp-admin/admin-post.php' ) );
		$this->assertSame( ConnectFlow::PROBLEM_HOST_MISMATCH, ConnectFlow::problem( self::SITE, 'https://admin.shop.example/wp-admin/admin-post.php' ) );
		$this->assertSame( ConnectFlow::PROBLEM_BAD_URL, ConnectFlow::problem( 'nonsense', 'nonsense' ) );
	}

	/**
	 * The exchange sends exactly three fields and no credentials.
	 */
	public function test_client_exchange_request_shape(): void {
		$transport = ( new FakeTransport() )->will_return( new Response( 200, array(), array( 'data' => array( 'id' => 'x' ) ) ) );
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );

		$client->exchange_connect_code( 'abc', 'ver', self::CALLBACK );

		$request = $transport->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.example.test/public-api/v1/connect/token', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertArrayNotHasKey( 'x-service-id', $request['headers'] );
		$this->assertSame(
			array(
				'code'          => 'abc',
				'code_verifier' => 'ver',
				'redirect_uri'  => self::CALLBACK,
			),
			json_decode( (string) $request['body'], true )
		);
	}

	/**
	 * A revoked key is noticed on any call, and a new key ends it.
	 */
	public function test_revoked_key_is_detected_until_a_new_key_is_stored(): void {
		$this->settings->set_api_key( 'old-key' );
		$told      = 0;
		$transport = ( new FakeTransport() )
			->will_return(
				new Response(
					401,
					array(),
					array(
						'errors' => array(
							array(
								'status' => '401',
								'code'   => 'key_revoked',
							),
						),
					)
				)
			)
			->will_return(
				new Response(
					401,
					array(),
					array(
						'errors' => array(
							array(
								'status' => '401',
								'code'   => 'unauthorized',
							),
						),
					)
				)
			);
		$client    = new Client(
			$transport,
			new Credentials( 'old-key', 'svc' ),
			'https://api.example.test',
			new \Povikvane\WooCommerce\Support\NullLogger(),
			function () use ( &$told ): void {
				++$told;
				$this->store->mark_revoked();
			}
		);

		$client->account();
		$this->assertSame( 1, $told );
		$this->assertTrue( $this->store->revoked() );

		// An ordinary 401 is not a revocation.
		$client->account();
		$this->assertSame( 1, $told );

		$this->settings->set_api_key( 'new-key' );
		$this->assertFalse( $this->store->revoked() );
	}

	/**
	 * Disconnecting forgets the credentials on this site.
	 */
	public function test_disconnect_forgets_the_credentials(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'exchange', $this->exchange_ok() );
		$flow    = new ConnectFlow( $gateway, $this->store, 'https://app.example.test' );
		$flow->complete(
			array(
				'code'  => 'c',
				'state' => $this->start( $flow )['state'],
			),
			5
		);

		$this->store->disconnect();

		$this->assertSame( '', $this->settings->api_key() );
		$this->assertSame( '', $this->settings->service_id() );
		$this->assertFalse( $this->secret->exists() );
		$this->assertNull( $this->store->info() );
	}
}
