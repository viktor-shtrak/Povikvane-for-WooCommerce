<?php
/**
 * Default message templates.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Starting texts in Bulgarian or English, chosen by the site language. Only the
 * "completed" status sends a message out of the box.
 */
final class TemplateDefaults {

	private const BG = array(
		'processing' => 'Здравейте, {first_name}! Получихме поръчка №{order_number} ({order_total}). {shop_name}',
		'completed'  => 'Здравейте, {first_name}! Поръчка №{order_number} е изпълнена. Благодарим! {shop_name}',
		'on-hold'    => 'Поръчка №{order_number} изчаква потвърждение. {shop_name}',
		'cancelled'  => 'Поръчка №{order_number} беше отказана. {shop_name}',
		'refunded'   => 'Сумата за поръчка №{order_number} беше възстановена. {shop_name}',
		'failed'     => 'Плащането за поръчка №{order_number} не успя. {shop_name}',
		'_other'     => 'Статусът на поръчка №{order_number} е променен. {shop_name}',
	);

	private const EN = array(
		'processing' => 'Hi {first_name}, we received your order #{order_number} ({order_total}). {shop_name}',
		'completed'  => 'Hi {first_name}, your order #{order_number} is complete. Thank you! {shop_name}',
		'on-hold'    => 'Your order #{order_number} is on hold. We will update you soon. {shop_name}',
		'cancelled'  => 'Your order #{order_number} was cancelled. {shop_name}',
		'refunded'   => 'Your order #{order_number} was refunded. {shop_name}',
		'failed'     => 'Payment for order #{order_number} failed. {shop_name}',
		'_other'     => 'The status of your order #{order_number} has changed. {shop_name}',
	);

	/**
	 * Default template for a status.
	 *
	 * @param string $status Status slug without the "wc-" prefix.
	 * @param string $locale Site locale, for example "bg_BG".
	 */
	public static function for_status( string $status, string $locale ): string {
		$texts = str_starts_with( strtolower( $locale ), 'bg' ) ? self::BG : self::EN;

		return $texts[ $status ] ?? $texts['_other'];
	}

	/**
	 * Whether a status sends a message before the shop owner changes anything.
	 *
	 * @param string $status Status slug without the "wc-" prefix.
	 */
	public static function enabled_by_default( string $status ): bool {
		return 'completed' === $status;
	}
}
