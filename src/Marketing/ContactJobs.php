<?php
/**
 * Keeps contacts in Povikvane in step with the shop's consent decisions.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Support\ActionSchedulerScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs that delete a contact in Povikvane: when a customer withdraws consent
 * (so campaigns there stop reaching them) and when a privacy erasure is requested.
 * Deleting the contact is deliberate: it does not touch order messages, which an
 * opt-out entry in Povikvane would also block.
 */
final class ContactJobs {

	public const HOOK         = 'povikvane_delete_contact';
	public const SYNCED       = 'povikvane_contacts_synced';
	public const MAX_ATTEMPTS = 5;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Talks to the API.
	 */
	public function __construct( private readonly Gateway $gateway ) {}

	/**
	 * Register the job.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Remember that contacts were sent, so later withdrawals are worth a request.
	 */
	public static function mark_synced(): void {
		update_option( self::SYNCED, 1, false );
	}

	/**
	 * Delete the contact for a customer who withdrew consent. Does nothing when no
	 * contacts were ever sent from this shop.
	 *
	 * @param string $e164   Number.
	 * @param string $source Where the withdrawal came from.
	 */
	public static function on_withdrawn( string $e164, string $source ): void {
		if ( ConsentEvent::SOURCE_PLATFORM === $source || false === get_option( self::SYNCED, false ) ) {
			return;
		}

		self::queue( $e164 );
	}

	/**
	 * Queue a deletion.
	 *
	 * @param string $e164 Number.
	 */
	public static function queue( string $e164 ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $e164, 1 ), ActionSchedulerScheduler::GROUP );
		}
	}

	/**
	 * Run one deletion; a temporary failure is tried again later.
	 *
	 * @param mixed $e164    Number.
	 * @param mixed $attempt Attempt number.
	 */
	public function run( mixed $e164 = '', mixed $attempt = 1 ): void {
		if ( ! is_string( $e164 ) || '' === $e164 ) {
			return;
		}

		$attempt = is_numeric( $attempt ) ? (int) $attempt : 1;
		$result  = $this->gateway->delete_contact( $e164 );

		// Deleted, or there was no such contact: both are done.
		if ( Outcome::Success === $result->outcome || Outcome::NotFound === $result->outcome ) {
			return;
		}

		if ( in_array( $result->outcome, array( Outcome::Retryable, Outcome::RateLimited, Outcome::InFlight ), true ) && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 60, $result->retry_after ?? 0, 60 * $attempt ), self::HOOK, array( $e164, $attempt + 1 ), ActionSchedulerScheduler::GROUP );
		}
	}
}
