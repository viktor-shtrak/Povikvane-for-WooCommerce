<?php
/**
 * Plugin settings and credentials.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Orders\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Reads settings, and stores the API key encrypted. A POVIKVANE_API_KEY constant
 * in wp-config.php takes precedence over the stored key.
 */
final class Settings {

	public const OPTION     = 'povikvane_settings';
	public const KEY_OPTION = 'povikvane_api_key_enc';

	private const LANDLINE_MIGRATION = 'povikvane_landline_default';

	public const KEY_SOURCE_CONSTANT   = 'constant';
	public const KEY_SOURCE_STORED     = 'stored';
	public const KEY_SOURCE_UNREADABLE = 'unreadable';
	public const KEY_SOURCE_NONE       = 'none';

	private const DEFAULTS = array(
		'service_id'            => '',
		'default_country'       => 'BG',
		'max_age_hours'         => 24,
		'debug_log'             => false,
		'max_order_age_days'    => 7,
		'skip_admin_created'    => false,
		'mobile_only'           => true,
		'storm_threshold'       => 50,
		'low_balance_threshold' => 50,
		'retention_body_days'   => 90,
		'retention_row_days'    => 365,
		'export_full_numbers'   => false,
		'delete_on_uninstall'   => false,
		'viber_enabled'         => false,
		'viber_sms_fallback'    => false,
		'plugin_language'       => 'bg_BG',
	);

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Encrypts the stored API key.
	 */
	public function __construct( private readonly Crypto $crypto ) {}

	/**
	 * All settings with defaults applied.
	 *
	 * @return array{service_id:string,default_country:string,max_age_hours:int,debug_log:bool,max_order_age_days:int,skip_admin_created:bool,mobile_only:bool,storm_threshold:int,low_balance_threshold:int,retention_body_days:int,retention_row_days:int,export_full_numbers:bool,delete_on_uninstall:bool,viber_enabled:bool,viber_sms_fallback:bool,plugin_language:string}
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = static fn( string $key ): mixed => $stored[ $key ] ?? self::DEFAULTS[ $key ];

		return array(
			'service_id'            => (string) $value( 'service_id' ),
			'default_country'       => (string) $value( 'default_country' ),
			'max_age_hours'         => (int) $value( 'max_age_hours' ),
			'debug_log'             => (bool) $value( 'debug_log' ),
			'max_order_age_days'    => (int) $value( 'max_order_age_days' ),
			'skip_admin_created'    => (bool) $value( 'skip_admin_created' ),
			'mobile_only'           => (bool) $value( 'mobile_only' ),
			'storm_threshold'       => (int) $value( 'storm_threshold' ),
			'low_balance_threshold' => (int) $value( 'low_balance_threshold' ),
			'retention_body_days'   => (int) $value( 'retention_body_days' ),
			'retention_row_days'    => (int) $value( 'retention_row_days' ),
			'export_full_numbers'   => (bool) $value( 'export_full_numbers' ),
			'delete_on_uninstall'   => (bool) $value( 'delete_on_uninstall' ),
			'viber_enabled'         => (bool) $value( 'viber_enabled' ),
			'viber_sms_fallback'    => (bool) $value( 'viber_sms_fallback' ),
			'plugin_language'       => Language::normalize( (string) $value( 'plugin_language' ) ),
		);
	}

	/**
	 * Service ID.
	 */
	public function service_id(): string {
		return $this->all()['service_id'];
	}

	/**
	 * Default ISO country code, used to read national phone numbers.
	 */
	public function default_country(): string {
		return $this->all()['default_country'];
	}

	/**
	 * Maximum message age in seconds before a waiting message is dropped.
	 */
	public function max_age_seconds(): int {
		return max( 1, $this->all()['max_age_hours'] ) * HOUR_IN_SECONDS;
	}

	/**
	 * Eligibility rules for automatic messages.
	 */
	public function rules(): Rules {
		$all = $this->all();

		return new Rules( max( 0, $all['max_order_age_days'] ), $all['skip_admin_created'], $all['mobile_only'] );
	}

	/**
	 * Messages created within ten minutes that pause sending (0 = guard off).
	 */
	public function storm_threshold(): int {
		return max( 0, $this->all()['storm_threshold'] );
	}

	/**
	 * Credits below which the administrator is warned (0 = no warning).
	 */
	public function low_balance_threshold(): int {
		return max( 0, $this->all()['low_balance_threshold'] );
	}

	/**
	 * Days after which message text is erased (0 = never).
	 */
	public function retention_body_days(): int {
		return max( 0, $this->all()['retention_body_days'] );
	}

	/**
	 * Days after which message records are deleted (0 = never).
	 */
	public function retention_row_days(): int {
		return max( 0, $this->all()['retention_row_days'] );
	}

	/**
	 * Whether order messages are sent by Viber when a matching approved template exists.
	 */
	public function viber_enabled(): bool {
		return $this->all()['viber_enabled'];
	}

	/**
	 * Whether Viber messages carry an SMS the service sends if Viber cannot deliver.
	 * Off by default: the Starter and Bronze plans do not include the fallback.
	 */
	public function viber_sms_fallback(): bool {
		return $this->all()['viber_sms_fallback'];
	}

	/**
	 * Whether the message log export shows full phone numbers.
	 */
	public function export_full_numbers(): bool {
		return $this->all()['export_full_numbers'];
	}

	/**
	 * The Language setting: "auto", "bg_BG" or "en_US".
	 */
	public function plugin_language(): string {
		return $this->all()['plugin_language'];
	}

	/**
	 * Whether verbose diagnostics are written to the WooCommerce log. A
	 * POVIKVANE_DEBUG constant set to true in wp-config.php forces it on.
	 */
	public function debug_logging(): bool {
		return ( defined( 'POVIKVANE_DEBUG' ) && true === constant( 'POVIKVANE_DEBUG' ) ) || $this->all()['debug_log'];
	}

	/**
	 * API key from the constant or the encrypted option; empty if unavailable.
	 */
	public function api_key(): string {
		if ( self::KEY_SOURCE_CONSTANT === $this->api_key_source() ) {
			return (string) constant( 'POVIKVANE_API_KEY' );
		}

		$stored = get_option( self::KEY_OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? (string) $this->crypto->decrypt( $stored ) : '';
	}

	/**
	 * Where the API key comes from.
	 *
	 * @return string One of the KEY_SOURCE_* constants.
	 */
	public function api_key_source(): string {
		if ( defined( 'POVIKVANE_API_KEY' ) && is_string( constant( 'POVIKVANE_API_KEY' ) ) && '' !== constant( 'POVIKVANE_API_KEY' ) ) {
			return self::KEY_SOURCE_CONSTANT;
		}

		$stored = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return self::KEY_SOURCE_NONE;
		}

		return null === $this->crypto->decrypt( $stored ) ? self::KEY_SOURCE_UNREADABLE : self::KEY_SOURCE_STORED;
	}

	/**
	 * Store a new API key, encrypted.
	 *
	 * @param string $api_key Plain API key.
	 */
	public function set_api_key( string $api_key ): void {
		update_option( self::KEY_OPTION, $this->crypto->encrypt( $api_key ), false );
	}

	/**
	 * Store the service ID, keeping the other settings.
	 *
	 * @param string $service_id Service ID.
	 */
	public function set_service_id( string $service_id ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::OPTION, array_merge( $stored, array( 'service_id' => sanitize_text_field( $service_id ) ) ) );
	}

	/**
	 * Defaults that were settled after some sites had already been saved: skipping
	 * landline numbers is on, and the language is Bulgarian. They are applied once;
	 * after that the choice is theirs.
	 */
	public function apply_defaults_once(): void {
		if ( false !== get_option( self::LANDLINE_MIGRATION ) ) {
			return;
		}

		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && array() !== $stored ) {
			if ( array_key_exists( 'mobile_only', $stored ) && ! $stored['mobile_only'] ) {
				$stored['mobile_only'] = true;
			}

			if ( 'auto' === ( $stored['plugin_language'] ?? '' ) ) {
				$stored['plugin_language'] = 'bg_BG';
			}

			update_option( self::OPTION, $stored );
		}

		update_option( self::LANDLINE_MIGRATION, '1', true );
	}

	/**
	 * Remove the stored API key.
	 */
	public function clear_api_key(): void {
		delete_option( self::KEY_OPTION );
	}

	/**
	 * Credentials for the API client.
	 */
	public function credentials(): Credentials {
		return new Credentials( $this->api_key(), $this->service_id() );
	}

	/**
	 * API base URL. A POVIKVANE_API_BASE_URL constant (https only) overrides the default for testing.
	 */
	public function base_url(): string {
		if ( defined( 'POVIKVANE_API_BASE_URL' ) ) {
			$url = constant( 'POVIKVANE_API_BASE_URL' );

			if ( is_string( $url ) && str_starts_with( $url, 'https://' ) ) {
				return $url;
			}
		}

		return Client::DEFAULT_BASE_URL;
	}
}
