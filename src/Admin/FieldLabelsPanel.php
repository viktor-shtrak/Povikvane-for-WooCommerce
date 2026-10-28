<?php
/**
 * Custom field labels: what the account has, and what WooCommerce would give.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Account\FieldLabelPlan;
use Povikvane\WooCommerce\Account\LabelWriter;
use Povikvane\WooCommerce\Account\UnavailableLabelWriter;
use Povikvane\WooCommerce\Api\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Viber templates fill their custom fields with the order number (field 1), the order
 * total (field 2) and the shop name (field 3). Labels in Povikvane tell people what each
 * field holds, so this panel checks them and offers to set them.
 */
final class FieldLabelsPanel {

	public const ACTION = 'povikvane_field_labels';

	/**
	 * Runs a connection check, so the account answer is fresh afterwards.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Constructor.
	 *
	 * @param callable    $probe  Runs a capability probe.
	 * @param LabelWriter $writer Changes labels in Povikvane.
	 */
	public function __construct( callable $probe, private readonly LabelWriter $writer = new UnavailableLabelWriter() ) {
		$this->probe = $probe;
	}

	/**
	 * Register the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Labels WooCommerce gives the three fields.
	 *
	 * @return array<string,string>
	 */
	public static function woo_labels(): array {
		return array(
			'customField1' => __( 'Order number', 'povikvane-for-woocommerce' ),
			'customField2' => __( 'Order total', 'povikvane-for-woocommerce' ),
			'customField3' => __( 'Shop name', 'povikvane-for-woocommerce' ),
		);
	}

	/**
	 * The comparison for the stored account answer.
	 */
	public static function plan(): FieldLabelPlan {
		return FieldLabelPlan::compare( AccountInfo::custom_field_labels( get_option( Capabilities::OPTION, array() ) ), self::woo_labels() );
	}

	/**
	 * Print the panel.
	 *
	 * @param string $back Address to come back to after a change.
	 */
	public function render( string $back ): void {
		$plan = self::plan();

		Ui::field_open( __( 'Custom field labels', 'povikvane-for-woocommerce' ), '', __( 'Viber templates use three custom fields for order values. Labels tell people in Povikvane what each one holds.', 'povikvane-for-woocommerce' ) );

		if ( FieldLabelPlan::UNKNOWN === $plan->state ) {
			echo '<p>' . esc_html__( 'Your Povikvane account does not report its custom field labels yet, so they cannot be checked from here. The plugin uses field 1 for the order number, field 2 for the order total and field 3 for the shop name.', 'povikvane-for-woocommerce' ) . '</p>';
			Ui::field_close();

			return;
		}

		echo '<p>' . esc_html( self::headline( $plan->state ) ) . '</p>';
		$this->table( $plan );

		if ( FieldLabelPlan::MATCHES !== $plan->state ) {
			echo '<p>';
			$this->buttons( $plan->state, $back );
			echo '</p>';

			if ( ! $this->writer->available() ) {
				echo '<p class="pvk-field__hint">' . esc_html__( 'Changing the labels from here is coming soon. Until then, set them in Povikvane under Settings.', 'povikvane-for-woocommerce' ) . '</p>';
			}
		}

		Ui::field_close();
	}

	/**
	 * Apply a choice.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$only_empty = isset( $_POST['mode'] ) && 'fill' === sanitize_key( wp_unslash( $_POST['mode'] ) );
		$back       = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';

		$this->apply( $only_empty );

		wp_safe_redirect( '' !== $back ? $back : admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}

	/**
	 * Write the WooCommerce labels to the account and tell the user how it went.
	 *
	 * @param bool $only_empty Keep labels the account already has.
	 */
	public function apply( bool $only_empty ): void {
		if ( ! $this->writer->available() ) {
			Flash::add( 'error', __( 'Changing custom field labels from here is not available yet.', 'povikvane-for-woocommerce' ) );
		} elseif ( $this->writer->save( self::plan()->labels_to_save( $only_empty ) ) ) {
			( $this->probe )();
			Flash::add( 'success', __( 'The custom field labels were updated in Povikvane.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', __( 'Povikvane did not accept the labels. Try again in a moment.', 'povikvane-for-woocommerce' ) );
		}
	}

	/**
	 * Fill in the labels when the account has none, without asking: there is nothing to
	 * overwrite, and the plugin needs them to be these.
	 *
	 * @return bool Whether the labels were written.
	 */
	public function fill_if_empty(): bool {
		if ( FieldLabelPlan::NONE !== self::plan()->state || ! $this->writer->available() ) {
			return false;
		}

		if ( ! $this->writer->save( self::plan()->labels_to_save( true ) ) ) {
			Flash::add( 'error', __( 'Povikvane did not accept the custom field names. You can set them later under Settings, Messages.', 'povikvane-for-woocommerce' ) );

			return false;
		}

		( $this->probe )();

		return true;
	}

	/**
	 * The setup wizard's version: shown only when the account already names some of its
	 * custom fields differently, which is the one case where the owner has to choose. Each
	 * field has a box to tick; the ticked ones are renamed when the owner continues, so the
	 * choices on the page are saved in the same step.
	 */
	public function render_for_wizard(): void {
		$plan = self::plan();

		if ( FieldLabelPlan::UNKNOWN === $plan->state ) {
			// Povikvane did not say which names the fields have, so the plugin cannot compare; the owner should still know.
			Ui::field_open( __( 'Custom field names', 'povikvane-for-woocommerce' ), '', __( 'Viber templates use three custom fields for order values. The names tell people in Povikvane what each one holds.', 'povikvane-for-woocommerce' ) );
			echo '<p>' . esc_html__( 'The plugin cannot see the names you have given your custom fields in Povikvane. It puts the order number in field 1, the order total in field 2 and the shop name in field 3. If you already use these fields for something else, check their names in Povikvane.', 'povikvane-for-woocommerce' ) . '</p>';
			Ui::field_close();

			return;
		}

		if ( ! in_array( $plan->state, array( FieldLabelPlan::PARTIAL, FieldLabelPlan::FULL ), true ) ) {
			return;
		}

		$can = $this->writer->available();

		Ui::field_open( __( 'Custom field names', 'povikvane-for-woocommerce' ), '', __( 'Viber templates use three custom fields for order values. The names tell people in Povikvane what each one holds.', 'povikvane-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Your Povikvane account already names some of these fields. The plugin puts the order number in field 1, the order total in field 2 and the shop name in field 3.', 'povikvane-for-woocommerce' ) . '</p>';
		echo '<table class="pvk-table pvk-labels"><thead><tr><th scope="col">' . esc_html__( 'Field', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'In Povikvane now', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'From WooCommerce', 'povikvane-for-woocommerce' ) . '</th>' . ( $can ? '<th scope="col">' . esc_html__( 'Rename', 'povikvane-for-woocommerce' ) . '</th>' : '' ) . '</tr></thead><tbody>';

		foreach ( $plan->wanted as $field => $label ) {
			$now   = $plan->current[ $field ] ?? '';
			$match = $now === $label;

			printf(
				'<tr><td class="pvk-mono">%1$s</td><td>%2$s</td><td>%3$s</td>',
				esc_html( $field ),
				'' === $now ? '<em>' . esc_html__( 'empty', 'povikvane-for-woocommerce' ) . '</em>' : esc_html( $now ),
				esc_html( $label )
			);

			if ( $can ) {
				// Empty fields are ticked, fields with a name of their own are not: renaming those is the owner's call.
				echo $match ? '<td>' . esc_html__( 'Already the same', 'povikvane-for-woocommerce' ) . '</td>' : sprintf( '<td><label class="screen-reader-text" for="pvk-rename-%1$s">%2$s</label><input type="checkbox" id="pvk-rename-%1$s" name="pvk_label_fields[]" value="%1$s"%3$s></td>', esc_attr( $field ), esc_html( $field ), '' === $now ? ' checked' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in both branches.
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $can ) {
			echo '<input type="hidden" name="pvk_labels_asked" value="1"><p class="pvk-field__hint">' . esc_html__( 'Ticked fields are renamed when you continue. Fields you leave unticked keep their names, and the order values still go into them, so you may want to rename them.', 'povikvane-for-woocommerce' ) . '</p>';
		} else {
			echo '<p class="pvk-field__hint">' . esc_html__( 'Changing the names from here is coming soon. Until then, set them in Povikvane under Settings.', 'povikvane-for-woocommerce' ) . '</p>';
		}

		Ui::field_close();
	}

	/**
	 * Rename only the fields the owner picked.
	 *
	 * @param string[] $fields Fields to rename.
	 */
	public function apply_fields( array $fields ): void {
		$labels = self::plan()->labels_for( $fields );

		if ( array() === $labels ) {
			return;
		}

		if ( $this->writer->save( $labels ) ) {
			( $this->probe )();
			Flash::add( 'success', __( 'The custom field names were updated in Povikvane.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', __( 'Povikvane did not accept the names. Try again in a moment.', 'povikvane-for-woocommerce' ) );
		}
	}

	/**
	 * The comparison table.
	 *
	 * @param FieldLabelPlan $plan What the account has and what WooCommerce would give.
	 */
	private function table( FieldLabelPlan $plan ): void {
		echo '<table class="pvk-table pvk-labels"><thead><tr><th scope="col">' . esc_html__( 'Field', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'In Povikvane now', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'From WooCommerce', 'povikvane-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( $plan->wanted as $field => $label ) {
			$now = $plan->current[ $field ] ?? '';
			printf(
				'<tr><td class="pvk-mono">%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $field ),
				'' === $now ? '<em>' . esc_html__( 'empty', 'povikvane-for-woocommerce' ) . '</em>' : esc_html( $now ),
				esc_html( $label )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The sentence above the table.
	 *
	 * @param string $state A FieldLabelPlan state.
	 */
	private static function headline( string $state ): string {
		return match ( $state ) {
			FieldLabelPlan::NONE    => __( 'You have no custom field labels yet. Fill them in with the ones from WooCommerce?', 'povikvane-for-woocommerce' ),
			FieldLabelPlan::PARTIAL => __( 'Some of your custom fields have labels and some do not. You can replace them with the ones from WooCommerce, or fill in only the empty ones.', 'povikvane-for-woocommerce' ),
			FieldLabelPlan::FULL    => __( 'You already have custom field labels. Replace them with the ones from WooCommerce?', 'povikvane-for-woocommerce' ),
			default                 => __( 'Your custom field labels already match the ones from WooCommerce.', 'povikvane-for-woocommerce' ),
		};
	}

	/**
	 * The buttons that fit the state.
	 *
	 * @param string $state  A FieldLabelPlan state.
	 * @param string $back Address to come back to.
	 */
	private function buttons( string $state, string $back ): void {
		$choices = match ( $state ) {
			FieldLabelPlan::NONE    => array( 'fill' => __( 'Fill them in', 'povikvane-for-woocommerce' ) ),
			FieldLabelPlan::PARTIAL => array(
				'replace' => __( 'Replace all', 'povikvane-for-woocommerce' ),
				'fill'    => __( 'Fill only the empty ones', 'povikvane-for-woocommerce' ),
			),
			default                 => array( 'replace' => __( 'Replace them', 'povikvane-for-woocommerce' ) ),
		};

		foreach ( $choices as $mode => $label ) {
			if ( $this->writer->available() ) {
				Layout::action_button(
					$label,
					self::ACTION,
					array(
						'mode'   => $mode,
						'return' => $back,
					),
					'pvk-btn'
				);
				echo ' ';
			} else {
				echo '<button type="button" class="pvk-btn" disabled>' . esc_html( $label ) . '</button> ';
			}
		}
	}
}
