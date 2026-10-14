<?php
/**
 * Default texts for promotional messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Starting wording in Bulgarian or English. The consent wording in particular is
 * a legal text: have it reviewed before going live.
 */
final class Defaults {

	/**
	 * Checkbox wording at checkout.
	 *
	 * @param string $locale Site locale.
	 */
	public static function consent_text( string $locale ): string {
		return self::is_bulgarian( $locale )
			? 'Искам да получавам SMS съобщения с оферти и новини от магазина. Мога да се отпиша по всяко време.'
			: 'I would like to receive SMS messages with offers and news from this shop. I can opt out at any time.';
	}

	/**
	 * Review-request text.
	 *
	 * @param string $locale Site locale.
	 */
	public static function followup_template( string $locale ): string {
		return self::is_bulgarian( $locale )
			? 'Здравейте, {first_name}! Как ви хареса поръчката от {shop_name}? Ще се радваме на мнението ви: {shop_url}'
			: 'Hi {first_name}, how was your order from {shop_name}? We would love your feedback: {shop_url}';
	}

	/**
	 * Label put before the opt-out link.
	 *
	 * @param string $locale Site locale.
	 */
	public static function optout_label( string $locale ): string {
		return self::is_bulgarian( $locale ) ? 'Отписване:' : 'Stop:';
	}

	/**
	 * Whether a locale is Bulgarian.
	 *
	 * @param string $locale Site locale.
	 */
	private static function is_bulgarian( string $locale ): bool {
		return str_starts_with( strtolower( $locale ), 'bg' );
	}
}
