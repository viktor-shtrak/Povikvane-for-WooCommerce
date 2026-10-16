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
		'processing' => 'Получихме поръчка №{order_number} ({order_total}) от {shop_name}. Благодарим ви!',
		'completed'  => 'Поръчка №{order_number} от {shop_name} е изпълнена. Благодарим ви!',
		'on-hold'    => 'Поръчка №{order_number} от {shop_name} изчаква потвърждение. Ще ви уведомим скоро.',
		'cancelled'  => 'Поръчка №{order_number} от {shop_name} беше отказана. Свържете се с нас при въпроси.',
		'refunded'   => 'Сумата за поръчка №{order_number} от {shop_name} беше възстановена.',
		'failed'     => 'Плащането за поръчка №{order_number} от {shop_name} не успя. Опитайте отново.',
		'_other'     => 'Статусът на поръчка №{order_number} от {shop_name} е променен.',
	);

	private const EN = array(
		'processing' => 'We received your order #{order_number} ({order_total}) from {shop_name}. Thank you!',
		'completed'  => 'Your order #{order_number} from {shop_name} is complete. Thank you!',
		'on-hold'    => 'Your order #{order_number} from {shop_name} is on hold. We will update you soon.',
		'cancelled'  => 'Your order #{order_number} from {shop_name} was cancelled. Contact us with any questions.',
		'refunded'   => 'Your order #{order_number} from {shop_name} was refunded.',
		'failed'     => 'Payment for your order #{order_number} from {shop_name} failed. Please try again.',
		'_other'     => 'The status of your order #{order_number} from {shop_name} has changed.',
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
