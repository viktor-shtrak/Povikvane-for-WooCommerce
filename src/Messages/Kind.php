<?php
/**
 * Message class: transactional or marketing.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional messages describe an order; marketing messages need consent.
 */
enum Kind: string {
	case Transactional = 'transactional';
	case Marketing     = 'marketing';
}
