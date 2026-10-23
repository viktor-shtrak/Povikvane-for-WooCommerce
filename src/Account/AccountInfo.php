<?php
/**
 * What the Povikvane account says about itself, read from the stored account answer.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The account answer is stored with the connection check (the "balance" entry of the
 * capabilities option). Everything here is optional: an account that does not report
 * something yields null, never a guess.
 */
final class AccountInfo {

	public const FIELDS = array( 'customField1', 'customField2', 'customField3' );

	/**
	 * Whether the plan includes the SMS fallback for Viber messages.
	 *
	 * False when Povikvane refused a fallback or the account says its plan has none;
	 * true when the account says it has one; null when nothing is known yet.
	 *
	 * @param mixed $caps Stored capabilities option.
	 */
	public static function sms_fallback_allowed( mixed $caps ): ?bool {
		if ( ! is_array( $caps ) ) {
			return null;
		}

		if ( false === ( $caps['fallback'] ?? null ) ) {
			return false;
		}

		$account = is_array( $caps['balance'] ?? null ) ? $caps['balance'] : array();

		foreach ( array( $account['plan']['sms_fallback'] ?? null, $account['features']['sms_fallback'] ?? null, $account['sms_fallback'] ?? null ) as $value ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Whether the account reports a feature as available (templates, template_management, sms_fallback).
	 *
	 * @param mixed  $caps    Stored capabilities option.
	 * @param string $feature Feature key.
	 * @return bool|null Null when the account does not report features.
	 */
	public static function feature( mixed $caps, string $feature ): ?bool {
		$account = is_array( $caps ) && is_array( $caps['balance'] ?? null ) ? $caps['balance'] : array();
		$value   = is_array( $account['features'] ?? null ) ? ( $account['features'][ $feature ] ?? null ) : null;

		return is_bool( $value ) ? $value : null;
	}

	/**
	 * What Povikvane says about the webhook address of this key.
	 *
	 * @param mixed $caps Stored capabilities option.
	 * @return array{url:?string,last_delivery_at:?string,last_status:?string,last_error:?string,secret_configured:bool,consecutive_failures:int,pending_deliveries:int}|null Null when the account does not report it.
	 */
	public static function webhook( mixed $caps ): ?array {
		$account = is_array( $caps ) && is_array( $caps['balance'] ?? null ) ? $caps['balance'] : array();
		$raw     = $account['webhook'] ?? null;

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$text = static fn( string $key ): ?string => isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== $raw[ $key ] ? $raw[ $key ] : null;

		return array(
			'url'                  => $text( 'url' ),
			'last_delivery_at'     => $text( 'last_delivery_at' ),
			'last_status'          => $text( 'last_status' ),
			'last_error'           => $text( 'last_error' ),
			'secret_configured'    => true === ( $raw['secret_configured'] ?? null ),
			'consecutive_failures' => isset( $raw['consecutive_failures'] ) && is_int( $raw['consecutive_failures'] ) ? $raw['consecutive_failures'] : 0,
			'pending_deliveries'   => isset( $raw['pending_deliveries'] ) && is_int( $raw['pending_deliveries'] ) ? $raw['pending_deliveries'] : 0,
		);
	}

	/**
	 * Display name of the plan, when the account has one.
	 *
	 * @param mixed $caps Stored capabilities option.
	 */
	public static function plan_name( mixed $caps ): ?string {
		$account = is_array( $caps ) && is_array( $caps['balance'] ?? null ) ? $caps['balance'] : array();
		$name    = is_array( $account['plan'] ?? null ) ? ( $account['plan']['name'] ?? null ) : null;

		return is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : null;
	}

	/**
	 * The labels the account has for its three custom fields.
	 *
	 * @param mixed $caps Stored capabilities option.
	 * @return array<string,string>|null Field => label (empty string when unlabelled), or null when the account does not report labels.
	 */
	public static function custom_field_labels( mixed $caps ): ?array {
		$account = is_array( $caps ) && is_array( $caps['balance'] ?? null ) ? $caps['balance'] : array();
		$raw     = $account['custom_fields'] ?? $account['customFields'] ?? null;

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$labels = array_fill_keys( self::FIELDS, '' );

		foreach ( $raw as $key => $item ) {
			$field = is_string( $key ) ? $key : '';
			$label = $item;

			if ( is_array( $item ) ) {
				$field = (string) ( $item['name'] ?? $item['key'] ?? $item['id'] ?? '' );
				$label = $item['label'] ?? $item['description'] ?? '';
			}

			if ( isset( $labels[ $field ] ) && is_string( $label ) ) {
				$labels[ $field ] = trim( $label );
			}
		}

		return $labels;
	}
}
