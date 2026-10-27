<?php
/**
 * What the plugin keeps about a connection made with "Connect with Povikvane".
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Connect;

use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Webhook\WebhookSecret;

defined( 'ABSPATH' ) || exit;

/**
 * The key, service ID and signing secret go to the places the plugin already keeps them
 * (encrypted key, settings, encrypted secret). The option here holds only what is safe to
 * show: the shop and company names and when the connection was made.
 */
final class ConnectionStore {

	public const OPTION = 'povikvane_connection';

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Stored settings and API key.
	 * @param WebhookSecret $secret   Stored signing secret.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly WebhookSecret $secret
	) {}

	/**
	 * Keep what Povikvane returned. Nothing is reported as connected until all of it is stored.
	 *
	 * @param array<string,mixed> $attributes The "attributes" of the connection response.
	 */
	public function persist( array $attributes ): void {
		$this->settings->set_api_key( (string) $attributes['api_key'] );
		$this->settings->set_service_id( (string) $attributes['service_id'] );

		$webhook = $attributes['webhook'] ?? null;

		if ( is_array( $webhook ) && is_string( $webhook['secret'] ?? null ) ) {
			$this->secret->set( $webhook['secret'] );
		} else {
			// A secret from an earlier key does not belong to this one.
			$this->secret->clear();
		}

		$company = is_array( $attributes['company'] ?? null ) && is_string( $attributes['company']['name'] ?? null ) ? $attributes['company']['name'] : '';

		update_option(
			self::OPTION,
			array(
				'site_name'    => (string) $attributes['site_name'],
				'site_url'     => (string) $attributes['site_url'],
				'company'      => $company,
				'key_prefix'   => (string) $attributes['key_prefix'],
				'connected_at' => (string) $attributes['connected_at'],
				'webhook'      => is_array( $webhook ),
				'revoked_for'  => '',
			),
			false
		);
	}

	/**
	 * What is known about the connection, or null when the key was entered by hand.
	 *
	 * @return array{site_name:string,site_url:string,company:string,key_prefix:string,connected_at:string,webhook:bool}|null
	 */
	public function info(): ?array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['site_name'] ) ) {
			return null;
		}

		return array(
			'site_name'    => (string) $stored['site_name'],
			'site_url'     => (string) ( $stored['site_url'] ?? '' ),
			'company'      => (string) ( $stored['company'] ?? '' ),
			'key_prefix'   => (string) ( $stored['key_prefix'] ?? '' ),
			'connected_at' => (string) ( $stored['connected_at'] ?? '' ),
			'webhook'      => true === ( $stored['webhook'] ?? false ),
		);
	}

	/**
	 * Note that Povikvane said the current key was revoked (the owner disconnected the shop).
	 */
	public function mark_revoked(): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::OPTION, array_merge( $stored, array( 'revoked_for' => $this->key_hash() ) ), false );
	}

	/**
	 * Whether the key now in use is the one that was revoked. Entering or connecting a new key ends it.
	 */
	public function revoked(): bool {
		$stored = get_option( self::OPTION, array() );
		$hash   = is_array( $stored ) && is_string( $stored['revoked_for'] ?? null ) ? $stored['revoked_for'] : '';

		return '' !== $hash && hash_equals( $hash, $this->key_hash() );
	}

	/**
	 * Forget the credentials on this site. Message history stays.
	 */
	public function disconnect(): void {
		$this->settings->clear_api_key();
		$this->settings->set_service_id( '' );
		$this->secret->clear();
		delete_option( self::OPTION );
	}

	/**
	 * A fingerprint of the key in use, so a revoked key can be told from a new one.
	 */
	private function key_hash(): string {
		$key = $this->settings->api_key();

		return '' === $key ? '' : substr( hash( 'sha256', $key ), 0, 16 );
	}
}
