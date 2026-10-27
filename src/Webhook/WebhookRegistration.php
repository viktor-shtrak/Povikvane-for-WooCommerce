<?php
/**
 * Registers the site's webhook address with Povikvane.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Povikvane keeps one webhook address per API key and only accepts public https
 * addresses. The plugin fills in an empty address by itself, but never replaces one
 * somebody else set: that only happens when the shop owner presses the button.
 */
final class WebhookRegistration {

	/**
	 * Option holding when Povikvane last reached this site with its own test.
	 */
	public const VERIFIED_OPTION = 'povikvane_webhook_verified_at';

	public const REGISTERED = 'registered';
	public const REFUSED    = 'refused';
	public const FAILED     = 'failed';

	/**
	 * Constructor.
	 *
	 * @param Gateway            $gateway Talks to the API.
	 * @param WebhookSecret|null $secret  Where the signing secret is kept.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly ?WebhookSecret $secret = null
	) {}

	/**
	 * Keep the address and the signing secret in order: fill in an empty address, and
	 * make sure that an address that is this site's has a secret stored here.
	 *
	 * @param mixed  $caps Stored capabilities option.
	 * @param string $url  The site's webhook address.
	 */
	public function maintain( mixed $caps, string $url ): void {
		$ours = $this->register_if_empty( $caps, $url ) || ( AccountInfo::webhook( $caps )['url'] ?? null ) === $url;

		if ( $ours && self::supported( $caps ) ) {
			$this->ensure_secret();
		}
	}

	/**
	 * Create a secret unless this site already has one.
	 *
	 * @return bool Whether a secret is stored afterwards.
	 */
	public function ensure_secret(): bool {
		return null !== $this->secret && ( $this->secret->exists() || $this->rotate_secret() );
	}

	/**
	 * Ask Povikvane for a new secret and keep it. The old one stays valid for 24 hours there.
	 *
	 * @return bool Whether a new secret was stored.
	 */
	public function rotate_secret(): bool {
		if ( null === $this->secret ) {
			return false;
		}

		$result = $this->gateway->create_webhook_secret();
		$value  = $result->attributes()['secret'] ?? null;

		if ( Outcome::Success !== $result->outcome || ! is_string( $value ) || ! str_starts_with( $value, 'whsec_' ) ) {
			return false;
		}

		$this->secret->set( $value );

		return true;
	}

	/**
	 * Whether the account can have its webhook address set from the plugin.
	 *
	 * @param mixed $caps Stored capabilities option.
	 */
	public static function supported( mixed $caps ): bool {
		return true === AccountInfo::feature( $caps, 'webhook_management' );
	}

	/**
	 * Whether the address is one Povikvane could ever reach.
	 *
	 * @param string $url Address.
	 */
	public static function looks_public( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '' === $host || 'localhost' === $host ) {
			return false;
		}

		foreach ( array( '.local', '.test', '.localhost', '.internal' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		$ip = trim( $host, '[]' );

		// An address, not a name: only public ranges count.
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) || false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Fill in the address when Povikvane has none for this key.
	 *
	 * @param mixed  $caps Stored capabilities option.
	 * @param string $url  The site's webhook address.
	 * @return bool Whether a request was made and accepted.
	 */
	public function register_if_empty( mixed $caps, string $url ): bool {
		$current = AccountInfo::webhook( $caps );

		if ( ! self::supported( $caps ) || null === $current || null !== $current['url'] || ! self::looks_public( $url ) ) {
			return false;
		}

		return Outcome::Success === $this->gateway->set_webhook( $url )->outcome;
	}

	/**
	 * Set the address, replacing any other.
	 *
	 * @param string $url The site's webhook address.
	 * @return array{result:string,reason:string}
	 */
	public function register( string $url ): array {
		if ( ! self::looks_public( $url ) ) {
			return array(
				'result' => self::REFUSED,
				'reason' => __( 'Povikvane can only call a public https address. This site is not on one, so delivery reports will be found by checking regularly instead.', 'povikvane-for-woocommerce' ),
			);
		}

		$result = $this->gateway->set_webhook( $url );

		if ( Outcome::Success === $result->outcome ) {
			$this->ensure_secret();

			return array(
				'result' => self::REGISTERED,
				'reason' => '',
			);
		}

		return array(
			'result' => 422 === $result->http_status ? self::REFUSED : self::FAILED,
			'reason' => 'webhook_url_invalid' === $result->error_code ? __( 'Povikvane did not accept this address. It must be a public https address.', 'povikvane-for-woocommerce' ) : __( 'Povikvane could not save the address right now. Try again in a moment.', 'povikvane-for-woocommerce' ),
		);
	}

	/**
	 * Ask Povikvane to call the stored address once.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array {
		$result = $this->gateway->test_webhook();

		return self::interpret_test( $result );
	}

	/**
	 * What a test answer means.
	 *
	 * @param ApiResult $result Answer.
	 * @return array{ok:bool,message:string}
	 */
	public static function interpret_test( ApiResult $result ): array {
		if ( 409 === $result->http_status ) {
			return array(
				'ok'      => false,
				'message' => __( 'Povikvane has no webhook address for this key yet. Register the address first.', 'povikvane-for-woocommerce' ),
			);
		}

		if ( 429 === $result->http_status ) {
			return array(
				'ok'      => false,
				'message' => __( 'You have run the test too many times this hour. Try again later.', 'povikvane-for-woocommerce' ),
			);
		}

		if ( Outcome::Success !== $result->outcome ) {
			return array(
				'ok'      => false,
				'message' => __( 'Povikvane could not run the test right now. Try again in a moment.', 'povikvane-for-woocommerce' ),
			);
		}

		$attributes = $result->attributes();

		if ( true === ( $attributes['delivered'] ?? null ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Povikvane reached your site. Delivery reports will arrive as soon as messages are delivered.', 'povikvane-for-woocommerce' ),
			);
		}

		$error = isset( $attributes['error'] ) && is_string( $attributes['error'] ) ? $attributes['error'] : '';

		return array(
			'ok'      => false,
			'message' => self::error_text( $error ),
		);
	}

	/**
	 * Plain-language reason a delivery failed.
	 *
	 * @param string $error Error code from Povikvane.
	 */
	public static function error_text( string $error ): string {
		if ( 'http_401' === $error ) {
			return __( 'Povikvane called your site but the signature did not match. Create a new secret and try again.', 'povikvane-for-woocommerce' );
		}

		if ( str_starts_with( $error, 'http_' ) ) {
			return sprintf(
				/* translators: %s: HTTP status number. */
				__( 'Povikvane called your site but it answered with an error (%s). Check that the REST API is not blocked by a security plugin or firewall.', 'povikvane-for-woocommerce' ),
				substr( $error, 5 )
			);
		}

		return match ( $error ) {
			'timeout'           => __( 'Povikvane called your site but it did not answer in time.', 'povikvane-for-woocommerce' ),
			'connection_failed' => __( 'Povikvane could not connect to your site. Check that the site is online and its address is public.', 'povikvane-for-woocommerce' ),
			default             => __( 'Povikvane called your site but did not get a valid answer.', 'povikvane-for-woocommerce' ),
		};
	}
}
