<?php
/**
 * Small building blocks for the admin screens.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Cards, fields, switches and pills, so every screen looks the same. All text is
 * escaped here; callers pass plain text unless a parameter says it is HTML that
 * they have already escaped.
 */
final class Ui {

	/**
	 * Start a card.
	 *
	 * @param string $title Heading, plain text.
	 * @param string $intro Short explanation, plain text.
	 * @param string $css_class Extra CSS class, for example "pvk-card--accent".
	 */
	public static function card_open( string $title = '', string $intro = '', string $css_class = '' ): void {
		echo '<section class="pvk-card' . ( '' !== $css_class ? ' ' . esc_attr( $css_class ) : '' ) . '">';

		if ( '' !== $title || '' !== $intro ) {
			echo '<div class="pvk-card__head">';
			if ( '' !== $title ) {
				echo '<h2 class="pvk-card__title">' . esc_html( $title ) . '</h2>';
			}
			if ( '' !== $intro ) {
				echo '<p class="pvk-card__intro">' . esc_html( $intro ) . '</p>';
			}
			echo '</div>';
		}

		echo '<div class="pvk-card__body">';
	}

	/**
	 * Finish a card.
	 *
	 * @param string $footer_html Footer content, already escaped, or an empty string.
	 */
	public static function card_close( string $footer_html = '' ): void {
		echo '</div>';

		if ( '' !== $footer_html ) {
			echo '<div class="pvk-card__foot">' . $footer_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documented as already escaped.
		}

		echo '</section>';
	}

	/**
	 * Start a labelled field row.
	 *
	 * @param string $label Label, plain text.
	 * @param string $control_id ID of the control the label belongs to, if there is one.
	 * @param string $hint  Help text under the label, plain text.
	 * @param string $under_label_html Something to show under the label, such as a button; already escaped HTML.
	 */
	public static function field_open( string $label, string $control_id = '', string $hint = '', string $under_label_html = '' ): void {
		echo '<div class="pvk-field"><div class="pvk-field__label">';

		echo '' !== $control_id
			? '<label for="' . esc_attr( $control_id ) . '">' . esc_html( $label ) . '</label>'
			: esc_html( $label );

		if ( '' !== $hint ) {
			echo '<div class="pvk-field__hint">' . esc_html( $hint ) . '</div>';
		}

		echo $under_label_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documented as already escaped.

		echo '</div><div class="pvk-field__control">';
	}

	/**
	 * Finish a field row.
	 */
	public static function field_close(): void {
		echo '</div></div>';
	}

	/**
	 * A toggle switch.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Whether it is on.
	 * @param string $text    Label, plain text.
	 * @param string $hint    Smaller text under the label, plain text.
	 * @param string $id      Optional element ID.
	 * @param string $attrs   Extra attributes, already escaped (for example a data attribute).
	 */
	public static function toggle( string $name, bool $checked, string $text, string $hint = '', string $id = '', string $attrs = '' ): void {
		printf(
			'<label class="pvk-switch"%1$s><input type="checkbox" name="%2$s" value="1"%3$s%4$s><span class="pvk-switch__track" aria-hidden="true"></span><span class="pvk-switch__text">%5$s%6$s</span></label>',
			'' !== $id ? ' for="' . esc_attr( $id ) . '"' : '',
			esc_attr( $name ),
			$checked ? ' checked' : '',
			'' !== $attrs ? ' ' . $attrs : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documented as already escaped.
			esc_html( $text ),
			'' !== $hint ? '<span class="pvk-switch__hint">' . esc_html( $hint ) . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on this line.
		);
	}

	/**
	 * A small status label.
	 *
	 * @param string $text Text, plain.
	 * @param string $tone "ok", "warn", "bad", "info" or empty.
	 * @return string HTML, escaped.
	 */
	public static function pill( string $text, string $tone = '' ): string {
		return '<span class="pvk-pill' . ( '' !== $tone ? ' pvk-pill--' . esc_attr( $tone ) : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * A figure tile.
	 *
	 * @param string $label      Small caps label, plain text.
	 * @param string $value_html Main value, already escaped HTML.
	 * @param string $hint       Line under the value, plain text.
	 */
	public static function tile( string $label, string $value_html, string $hint = '' ): void {
		echo '<div class="pvk-tile"><div class="pvk-tile__label">' . esc_html( $label ) . '</div><div class="pvk-tile__value">' . $value_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documented as already escaped.

		if ( '' !== $hint ) {
			echo '<div class="pvk-tile__hint">' . esc_html( $hint ) . '</div>';
		}

		echo '</div>';
	}

	/**
	 * A coloured note.
	 *
	 * @param string $text Plain text.
	 * @param string $tone "info", "ok", "warn" or "bad".
	 */
	public static function note( string $text, string $tone = 'info' ): void {
		echo '<div class="pvk-note' . ( 'info' !== $tone ? ' pvk-note--' . esc_attr( $tone ) : '' ) . '" role="note"><div>' . esc_html( $text ) . '</div></div>';
	}
}
