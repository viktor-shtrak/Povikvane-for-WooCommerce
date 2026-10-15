<?php
/**
 * Remembers which "Needs attention" items a user has hidden.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Dismissal;

defined( 'ABSPATH' ) || exit;

/**
 * Each item can be dismissed on its own. It stays hidden for a week (see
 * Dismissal) and then comes back if the problem is still there.
 */
final class AttentionDismissals {

	public const ACTION   = 'povikvane_dismiss_attention';
	public const NONCE    = 'povikvane_dismiss_attention';
	public const USERMETA = 'povikvane_attention_dismissed';

	/**
	 * Register the AJAX handler.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Remember that this user dismissed one item.
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( '' === $key ) {
			wp_send_json_error( array(), 400 );
		}

		$stored         = self::stored( get_user_meta( get_current_user_id(), self::USERMETA, true ) );
		$stored[ $key ] = time();

		update_user_meta( get_current_user_id(), self::USERMETA, $stored );
		wp_send_json_success();
	}

	/**
	 * The items that are not currently dismissed.
	 *
	 * @param array<int,array{key:string,tone:string,title:string,text:string,label:string,url:string,external:bool}> $items     All items.
	 * @param mixed                                                                                                   $dismissed Stored user meta.
	 * @param int                                                                                                     $now       Current time.
	 * @return array<int,array{key:string,tone:string,title:string,text:string,label:string,url:string,external:bool}>
	 */
	public static function visible( array $items, mixed $dismissed, int $now ): array {
		$stored = self::stored( $dismissed );

		return array_values(
			array_filter(
				$items,
				static fn ( array $item ): bool => ! Dismissal::hides( $stored[ $item['key'] ] ?? null, $now )
			)
		);
	}

	/**
	 * Normalise the stored value.
	 *
	 * @param mixed $value Raw user meta.
	 * @return array<string,int>
	 */
	private static function stored( mixed $value ): array {
		$out = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $time ) {
				if ( is_string( $key ) && is_numeric( $time ) ) {
					$out[ $key ] = (int) $time;
				}
			}
		}

		return $out;
	}
}
