<?php
/**
 * Remembers which message texts were sent for review, and when.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Keyed by order status. A request only counts for the exact text it was made for:
 * edit the message and the status goes back to "not sent".
 */
final class TemplateRequests {

	public const OPTION = 'povikvane_template_requests';

	/**
	 * Fingerprint of a text, ignoring spacing.
	 *
	 * @param string $text Message text.
	 */
	public static function fingerprint( string $text ): string {
		return substr( sha1( (string) preg_replace( '/\s+/u', ' ', trim( $text ) ) ), 0, 16 );
	}

	/**
	 * Record a request.
	 *
	 * @param string $status Order status slug.
	 * @param string $text   The text that was sent.
	 * @param int    $time   Unix time.
	 */
	public function record( string $status, string $text, int $time ): void {
		$all            = $this->all();
		$all[ $status ] = array(
			'hash' => self::fingerprint( $text ),
			'time' => $time,
		);

		update_option( self::OPTION, $all, false );
	}

	/**
	 * When this exact text was sent for review, or 0.
	 *
	 * @param string $status Order status slug.
	 * @param string $text   Current text.
	 */
	public function sent_at( string $status, string $text ): int {
		$row = $this->all()[ $status ] ?? null;

		return null !== $row && self::fingerprint( $text ) === $row['hash'] ? $row['time'] : 0;
	}

	/**
	 * Everything stored, cleaned.
	 *
	 * @return array<string,array{hash:string,time:int}>
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $status => $row ) {
			if ( is_string( $status ) && is_array( $row ) && is_string( $row['hash'] ?? null ) && is_numeric( $row['time'] ?? null ) ) {
				$out[ $status ] = array(
					'hash' => $row['hash'],
					'time' => (int) $row['time'],
				);
			}
		}

		return $out;
	}
}
