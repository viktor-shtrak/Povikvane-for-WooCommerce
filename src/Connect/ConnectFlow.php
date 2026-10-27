<?php
/**
 * "Connect with Povikvane": the browser flow that replaces pasting a key.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Connect;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Authorisation code flow with PKCE. The verifier and state stay on the server, in a
 * short-lived record that can be used once; the browser only ever sees the address of the
 * approval page and, coming back, a one-time code. The code is never retried: any failure
 * means starting again from the button.
 */
final class ConnectFlow {

	public const TTL    = 600;
	public const PREFIX = 'povikvane_connect_';

	public const PROBLEM_SITE_NOT_HTTPS = 'site_not_https';
	public const PROBLEM_HOST_MISMATCH  = 'host_mismatch';
	public const PROBLEM_BAD_URL        = 'bad_url';

	/**
	 * Constructor.
	 *
	 * @param Gateway         $gateway Talks to the API (the exchange needs no credentials).
	 * @param ConnectionStore $store   Keeps the result.
	 * @param string          $base    Povikvane base URL, https.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly ConnectionStore $store,
		private readonly string $base
	) {}

	/**
	 * Why this site cannot be connected this way, or null when it can.
	 *
	 * Povikvane accepts plain http only for local development hosts, and wants the
	 * callback on the same host as the shop.
	 *
	 * @param string $site_url Shop address.
	 * @param string $redirect Callback address.
	 */
	public static function problem( string $site_url, string $redirect ): ?string {
		$site = wp_parse_url( $site_url );
		$back = wp_parse_url( $redirect );

		if ( ! is_array( $site ) || ! is_array( $back ) || empty( $site['host'] ) || empty( $back['host'] ) || strlen( $site_url ) > 512 || strlen( $redirect ) > 2048 ) {
			return self::PROBLEM_BAD_URL;
		}

		if ( strtolower( (string) $site['host'] ) !== strtolower( (string) $back['host'] ) || ( $site['scheme'] ?? '' ) !== ( $back['scheme'] ?? '' ) ) {
			return self::PROBLEM_HOST_MISMATCH;
		}

		$host  = strtolower( (string) $site['host'] );
		$local = 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' );

		if ( 'https' !== ( $site['scheme'] ?? '' ) && ! ( 'http' === ( $site['scheme'] ?? '' ) && $local ) ) {
			return self::PROBLEM_SITE_NOT_HTTPS;
		}

		return null;
	}

	/**
	 * Start a connection: remember what is needed to finish it, and return the approval page address.
	 *
	 * @param int         $user_id   The administrator who pressed the button.
	 * @param string      $origin    Where to come back to ("settings" or "wizard").
	 * @param string      $site_url  Shop address.
	 * @param string      $site_name Shop name.
	 * @param string      $redirect  Callback address; sent back unchanged at the exchange.
	 * @param string|null $webhook   This site's webhook address, when Povikvane can reach it.
	 */
	public function begin( int $user_id, string $origin, string $site_url, string $site_name, string $redirect, ?string $webhook ): string {
		$verifier = self::base64url( random_bytes( 32 ) );
		$state    = self::base64url( random_bytes( 32 ) );

		set_transient(
			self::key( $state ),
			array(
				'verifier' => $verifier,
				'redirect' => $redirect,
				'user_id'  => $user_id,
				'return'   => $origin,
			),
			self::TTL
		);

		$query = array(
			'site_url'              => $site_url,
			'site_name'             => mb_substr( '' !== trim( $site_name ) ? trim( $site_name ) : (string) wp_parse_url( $site_url, PHP_URL_HOST ), 0, 120 ),
			'redirect_uri'          => $redirect,
			'code_challenge'        => self::challenge( $verifier ),
			'code_challenge_method' => 'S256',
			'state'                 => $state,
		);

		if ( null !== $webhook ) {
			$query['webhook_url'] = $webhook;
		}

		return rtrim( $this->base, '/' ) . '/connect/authorize?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Finish a connection from the callback.
	 *
	 * @param array<string,mixed> $query   The callback's query (code and state, or error and state).
	 * @param int                 $user_id The administrator who is back.
	 */
	public function complete( array $query, int $user_id ): ConnectResult {
		$state   = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';
		$pending = '' === $state ? false : get_transient( self::key( $state ) );

		// One use only: whatever happens next, this attempt is over.
		if ( '' !== $state ) {
			delete_transient( self::key( $state ) );
		}

		if ( ! is_array( $pending ) || (int) ( $pending['user_id'] ?? 0 ) !== $user_id || ! is_string( $pending['verifier'] ?? null ) || ! is_string( $pending['redirect'] ?? null ) ) {
			return new ConnectResult( ConnectResult::INVALID );
		}

		$origin = 'wizard' === ( $pending['return'] ?? '' ) ? 'wizard' : 'settings';

		if ( isset( $query['error'] ) ) {
			return new ConnectResult( ConnectResult::DECLINED, '', $origin );
		}

		$code = isset( $query['code'] ) && is_string( $query['code'] ) ? $query['code'] : '';

		if ( '' === $code ) {
			return new ConnectResult( ConnectResult::INVALID, '', $origin );
		}

		$result = $this->gateway->exchange_connect_code( $code, $pending['verifier'], $pending['redirect'] );
		$data   = $result->data;
		$attrs  = $result->attributes();

		if ( Outcome::Success !== $result->outcome || ! self::valid_response( $data, $attrs ) ) {
			return new ConnectResult( ConnectResult::FAILED, '', $origin );
		}

		$this->store->persist( $attrs );

		return new ConnectResult( ConnectResult::CONNECTED, (string) $attrs['site_name'], $origin, is_array( $attrs['webhook'] ) );
	}

	/**
	 * Whether the exchange answer has everything that must be stored.
	 *
	 * @param array<mixed> $data  The "data" object.
	 * @param array<mixed> $attrs Its attributes.
	 */
	public static function valid_response( array $data, array $attrs ): bool {
		foreach ( array( 'api_key', 'key_prefix', 'service_id', 'site_url', 'site_name', 'connected_at' ) as $field ) {
			if ( ! is_string( $attrs[ $field ] ?? null ) || '' === $attrs[ $field ] ) {
				return false;
			}
		}

		if ( 'connection' !== ( $data['type'] ?? '' ) || ! isset( $data['id'] ) || ! array_key_exists( 'webhook', $attrs ) ) {
			return false;
		}

		$webhook = $attrs['webhook'];

		return null === $webhook || ( is_array( $webhook ) && is_string( $webhook['url'] ?? null ) && is_string( $webhook['secret'] ?? null ) && str_starts_with( $webhook['secret'], 'whsec_' ) );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding, not obfuscation.
	}

	/**
	 * The PKCE challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Storage key for a pending request. The state itself is not used as a key.
	 *
	 * @param string $state State.
	 */
	private static function key( string $state ): string {
		return self::PREFIX . substr( hash( 'sha256', $state ), 0, 40 );
	}
}
