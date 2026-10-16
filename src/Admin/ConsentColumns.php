<?php
/**
 * Shows who agreed to promotional messages in the user list, the profile and the
 * WooCommerce customer list.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Marketing\ConsentEvent;
use Povikvane\WooCommerce\Marketing\ConsentRepository;
use Povikvane\WooCommerce\Support\Dates;
use WP_User;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only: agreements are only ever recorded by the customer (checkout, My Account
 * or the unsubscribe link), never edited by hand.
 */
final class ConsentColumns {

	private const COLUMN      = 'povikvane_consent';
	private const SUMMARY_MAX = 5000;

	/**
	 * Constructor.
	 *
	 * @param ConsentRepository $ledger Consent records.
	 */
	public function __construct( private readonly ConsentRepository $ledger ) {}

	/**
	 * Register the hooks.
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'column' ), 10, 3 );
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_customers' ) );
	}

	/**
	 * Add the column to Users.
	 *
	 * @param mixed $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( mixed $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		if ( current_user_can( 'manage_woocommerce' ) ) {
			$columns[ self::COLUMN ] = __( 'SMS promotions', 'povikvane-for-woocommerce' );
		}

		return $columns;
	}

	/**
	 * Cell of the Users list.
	 *
	 * @param mixed  $output  Existing cell content.
	 * @param string $column  Column key.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function column( mixed $output, string $column, int $user_id ): string {
		$output = is_string( $output ) ? $output : '';

		if ( self::COLUMN !== $column || ! current_user_can( 'manage_woocommerce' ) ) {
			return $output;
		}

		$event = $this->event_for( $user_id );

		if ( null === $event ) {
			return '<span aria-label="' . esc_attr__( 'Has not agreed', 'povikvane-for-woocommerce' ) . '">&mdash;</span>';
		}

		return '<strong>' . esc_html__( 'Agreed', 'povikvane-for-woocommerce' ) . '</strong><br><small>' . esc_html( Dates::format( $event->created_at ) ) . '</small>';
	}

	/**
	 * Section on the profile page.
	 *
	 * @param WP_User $user Edited user.
	 */
	public function profile( WP_User $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$event = $this->event_for( (int) $user->ID );

		echo '<h2>' . esc_html__( 'Promotional SMS', 'povikvane-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Consent', 'povikvane-for-woocommerce' ) . '</th><td>';

		if ( null === $event ) {
			echo esc_html__( 'Has not agreed to promotional messages.', 'povikvane-for-woocommerce' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: where the customer agreed, for example "at checkout". */
					__( 'Agreed on %1$s %2$s.', 'povikvane-for-woocommerce' ),
					Dates::format( $event->created_at ),
					self::source_text( $event->source )
				)
			);
		}

		echo '<p class="description">' . esc_html__( 'Recorded by the Povikvane plugin. Only the customer can change it.', 'povikvane-for-woocommerce' ) . '</p></td></tr></table>';
	}

	/**
	 * Let the WooCommerce customer list show the same.
	 */
	public function enqueue_customers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to load a script.
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['page'] ) || 'wc-admin' !== $_GET['page'] ) {
			return;
		}

		wp_enqueue_script( 'povikvane-customers', plugins_url( 'assets/js/customers.js', PLUGIN_FILE ), array( 'wp-hooks' ), VERSION, true );

		$users  = array();
		$emails = array();

		foreach ( $this->ledger->granted_summary( self::SUMMARY_MAX ) as $row ) {
			if ( $row['user_id'] > 0 ) {
				$users[ $row['user_id'] ] = Dates::format( $row['time'], 'Y-m-d' );
			}

			if ( '' !== $row['email'] ) {
				$emails[ $row['email'] ] = Dates::format( $row['time'], 'Y-m-d' );
			}
		}

		wp_localize_script(
			'povikvane-customers',
			'povikvaneCustomers',
			array(
				'users'  => (object) $users,
				'emails' => (object) $emails,
				'i18n'   => array(
					'label' => __( 'SMS promotions', 'povikvane-for-woocommerce' ),
					'yes'   => __( 'Agreed', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The agreement of one account.
	 *
	 * @param int $user_id User ID.
	 */
	private function event_for( int $user_id ): ?ConsentEvent {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return $this->ledger->granted_for_accounts( array( $user_id => (string) $user->user_email ) )[ $user_id ] ?? null;
	}

	/**
	 * Where an agreement was given.
	 *
	 * @param string $source A ConsentEvent::SOURCE_* constant.
	 */
	private static function source_text( string $source ): string {
		return match ( $source ) {
			ConsentEvent::SOURCE_CHECKOUT => __( 'at checkout', 'povikvane-for-woocommerce' ),
			ConsentEvent::SOURCE_ACCOUNT  => __( 'in My Account', 'povikvane-for-woocommerce' ),
			default                       => '',
		};
	}
}
