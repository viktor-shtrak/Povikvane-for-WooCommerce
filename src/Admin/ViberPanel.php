<?php
/**
 * The Viber card on the Connection tab.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\ViberPlanner;
use Povikvane\WooCommerce\Viber\ViberTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Shows whether Viber can be used and which templates the account has, so setting
 * Viber up never depends on guessing.
 */
final class ViberPanel {

	/**
	 * Constructor.
	 *
	 * @param TemplateCatalog $catalog Template list.
	 */
	public function __construct( private readonly TemplateCatalog $catalog = new TemplateCatalog() ) {}

	/**
	 * Print the card.
	 */
	public function render(): void {
		$status    = Capabilities::viber_status( get_option( Capabilities::OPTION, array() ) );
		$templates = $this->catalog->all();

		Ui::card_open( __( 'Viber', 'povikvane-for-woocommerce' ), __( 'Send order updates over Viber with an automatic SMS if Viber cannot deliver. It needs an approved transactional template in your Povikvane account.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Status', 'povikvane-for-woocommerce' ) );
		echo Ui::pill( Capabilities::VIBER_READY === $status ? __( 'Available', 'povikvane-for-woocommerce' ) : __( 'Not available', 'povikvane-for-woocommerce' ), Capabilities::VIBER_READY === $status ? 'ok' : 'warn' ) . ' ' . esc_html( ViberView::reason( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill() and esc_html().
		Ui::field_close();

		Ui::field_open( __( 'Templates', 'povikvane-for-woocommerce' ), '', __( 'Create and register templates in Povikvane. They are listed here once they exist.', 'povikvane-for-woocommerce' ) );

		if ( array() === $templates ) {
			echo esc_html__( 'No Viber templates yet.', 'povikvane-for-woocommerce' );
		} else {
			echo '<ul class="pvk-list">';

			foreach ( $templates as $template ) {
				echo '<li>' . $this->row( $template ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in row().
			}

			echo '</ul>';
		}

		Ui::field_close();

		ob_start();
		Layout::action_button( __( 'Check connection and refresh templates', 'povikvane-for-woocommerce' ), 'povikvane_check_connection' );
		Ui::card_close( (string) ob_get_clean() );
	}

	/**
	 * One template line, as HTML.
	 *
	 * @param ViberTemplate $template Template.
	 */
	private function row( ViberTemplate $template ): string {
		$problem = ViberPlanner::problem( $template );

		return '<strong>' . esc_html( $template->name ) . '</strong> ' . ( null === $problem
			? Ui::pill( __( 'Ready to use', 'povikvane-for-woocommerce' ), 'ok' )
			: Ui::pill( ViberView::problem_label( $problem ), 'warn' ) );
	}
}
