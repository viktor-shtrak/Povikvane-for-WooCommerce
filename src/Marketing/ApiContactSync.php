<?php
/**
 * Copies customers who agreed into a Povikvane contact group.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Creating the group is safe on every call (Povikvane returns the existing one by name).
 * Each call carries at most 500 contacts; the screen pages through the customers.
 */
final class ApiContactSync implements ContactSync {

	public const BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Talks to the API.
	 * @param bool    $enabled Whether the plugin is connected.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly bool $enabled
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return $this->enabled;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,array{phone:string,first_name:string,last_name:string,agreed_at:int}> $contacts Customers.
	 */
	public function sync( array $contacts ): int {
		if ( array() === $contacts ) {
			return 0;
		}

		$group = $this->gateway->ensure_group( self::GROUP_NAME );
		$id    = $group->data['id'] ?? '';

		if ( Outcome::Success !== $group->outcome || ! is_string( $id ) || '' === $id ) {
			return -1;
		}

		$done = 0;

		foreach ( array_chunk( $contacts, self::BATCH ) as $chunk ) {
			$result = $this->gateway->upsert_contacts( $id, array_map( array( self::class, 'item' ), $chunk ) );

			if ( Outcome::Success !== $result->outcome ) {
				return -1;
			}

			// Customers Povikvane already holds as opted out are skipped there; they are not an error.
			$done += self::count( $result->data, 'added' ) + self::count( $result->data, 'updated' ) + self::count( $result->data, 'already_present' );
		}

		ContactJobs::mark_synced();

		return $done;
	}

	/**
	 * One contact in the API's shape.
	 *
	 * @param array{phone:string,first_name:string,last_name:string,agreed_at:int} $contact Customer.
	 * @return array<string,string>
	 */
	public static function item( array $contact ): array {
		$item = array(
			'phone'      => $contact['phone'],
			'consent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $contact['agreed_at'] ),
			'source'     => 'woocommerce',
		);

		if ( '' !== $contact['first_name'] ) {
			$item['first_name'] = mb_substr( $contact['first_name'], 0, 100 );
		}

		if ( '' !== $contact['last_name'] ) {
			$item['last_name'] = mb_substr( $contact['last_name'], 0, 100 );
		}

		return $item;
	}

	/**
	 * A count from the response.
	 *
	 * @param array<mixed> $data Response data.
	 * @param string       $key  Field.
	 */
	private static function count( array $data, string $key ): int {
		return isset( $data[ $key ] ) && is_int( $data[ $key ] ) ? $data[ $key ] : 0;
	}
}
