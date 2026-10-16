<?php
/**
 * Connection check and capability detection.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Finds out whether the credentials work and which optional endpoints exist.
 *
 * Optional endpoints (balance, lookup by reference) are behind flags on the
 * Povikvane side and answer 404 while off, so a 404 means "not available", not
 * an error. A failed probe (network trouble) keeps the previous knowledge.
 */
final class Capabilities {

	public const OPTION = 'povikvane_capabilities';

	public const CONNECTION_OK          = 'ok';
	public const CONNECTION_AUTH        = 'auth';
	public const CONNECTION_UNREACHABLE = 'unreachable';
	public const CONNECTION_CONFIG      = 'config';
	public const CONNECTION_UNKNOWN     = 'unknown';

	/**
	 * A UUID that never exists. A valid key answers 404 for it; a bad key answers 401.
	 */
	private const PROBE_MESSAGE_ID = '00000000-0000-0000-0000-000000000000';

	/**
	 * A reference no message has.
	 */
	private const PROBE_REFERENCE = 'povikvane:capability-probe';

	/**
	 * Check credentials and probe optional endpoints.
	 *
	 * @param Gateway             $gateway  API gateway.
	 * @param array<string,mixed> $previous Previously stored result, kept where a probe is inconclusive.
	 * @param int                 $now      Current time.
	 * @return array{checked_at:int,connection:string,account:?bool,lookup:?bool,templates:?bool,fallback:?bool,balance:?array<string,mixed>}
	 */
	public static function evaluate( Gateway $gateway, array $previous, int $now ): array {
		$caps = array(
			'checked_at' => $now,
			'connection' => self::check_connection( $gateway ),
			'account'    => self::bool_or_null( $previous['account'] ?? null ),
			'lookup'     => self::bool_or_null( $previous['lookup'] ?? null ),
			'templates'  => self::bool_or_null( $previous['templates'] ?? null ),
			// The service accepts or refuses the SMS fallback per request; only a real send shows which, so each check starts over.
			'fallback'   => null,
			'balance'    => is_array( $previous['balance'] ?? null ) ? $previous['balance'] : null,
		);

		if ( self::CONNECTION_OK !== $caps['connection'] ) {
			return $caps;
		}

		$account = $gateway->account();
		if ( Outcome::Success === $account->outcome ) {
			$caps['account'] = true;
			$caps['balance'] = $account->attributes();
		} elseif ( Outcome::NotFound === $account->outcome ) {
			$caps['account'] = false;
			$caps['balance'] = null;
		}

		$lookup = $gateway->find_by_reference( self::PROBE_REFERENCE );
		if ( Outcome::Success === $lookup->outcome ) {
			$caps['lookup'] = true;
		} elseif ( Outcome::NotFound === $lookup->outcome ) {
			$caps['lookup'] = false;
		}

		$templates = $gateway->templates();
		if ( Outcome::Success === $templates->outcome ) {
			$caps['templates'] = true;
		} elseif ( Outcome::NotFound === $templates->outcome ) {
			$caps['templates'] = false;
		}

		return $caps;
	}

	public const VIBER_READY            = 'ready';
	public const VIBER_NOT_CHECKED      = 'not_checked';
	public const VIBER_TEMPLATES_OFF    = 'templates_off';
	public const VIBER_FALLBACK_REFUSED = 'fallback_refused';

	/**
	 * Whether Viber can be used: the API offers the templates endpoint and the service
	 * has not refused the SMS fallback. The account's own sender list is not consulted,
	 * because accounts without their own originator send through Povikvane's.
	 *
	 * @param mixed $stored          The stored capabilities option.
	 * @param bool  $fallback_wanted Whether messages carry an SMS fallback; a refusal only matters then.
	 */
	public static function viber_ready( mixed $stored, bool $fallback_wanted = true ): bool {
		return self::VIBER_READY === self::viber_status( $stored, $fallback_wanted );
	}

	/**
	 * Why Viber is or is not available.
	 *
	 * @param mixed $stored          The stored capabilities option.
	 * @param bool  $fallback_wanted Whether messages carry an SMS fallback; a refusal only matters then.
	 * @return string One of the VIBER_* constants.
	 */
	public static function viber_status( mixed $stored, bool $fallback_wanted = true ): string {
		if ( ! is_array( $stored ) || null === ( $stored['templates'] ?? null ) ) {
			return self::VIBER_NOT_CHECKED;
		}

		if ( true !== $stored['templates'] ) {
			return self::VIBER_TEMPLATES_OFF;
		}

		return $fallback_wanted && false === ( $stored['fallback'] ?? null ) ? self::VIBER_FALLBACK_REFUSED : self::VIBER_READY;
	}

	/**
	 * Check that the credentials are accepted, without sending anything.
	 *
	 * @param Gateway $gateway API gateway.
	 * @return string One of the CONNECTION_* constants.
	 */
	public static function check_connection( Gateway $gateway ): string {
		return match ( $gateway->message_status( self::PROBE_MESSAGE_ID )->outcome ) {
			Outcome::NotFound, Outcome::Success => self::CONNECTION_OK,
			Outcome::AuthFailure                => self::CONNECTION_AUTH,
			Outcome::Retryable, Outcome::Uncertain, Outcome::RateLimited, Outcome::InFlight => self::CONNECTION_UNREACHABLE,
			Outcome::PermanentFailure           => self::CONNECTION_CONFIG,
			default                             => self::CONNECTION_UNKNOWN,
		};
	}

	/**
	 * Coerce a stored value to bool or null.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function bool_or_null( mixed $value ): ?bool {
		return is_bool( $value ) ? $value : null;
	}
}
