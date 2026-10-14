<?php
/**
 * Why a message was not sent.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable skip reasons, stored in the message log.
 */
final class SkipReason {

	public const NO_PHONE         = 'no_phone';
	public const INVALID_PHONE    = 'invalid_phone';
	public const NOT_MOBILE       = 'not_mobile';
	public const TOO_OLD          = 'too_old';
	public const ADMIN_CREATED    = 'admin_created';
	public const SITE_UNCONFIRMED = 'site_unconfirmed';
	public const VETOED           = 'vetoed';
	public const EMPTY_MESSAGE    = 'empty_message';
	public const STATUS_CHANGED   = 'status_changed';
	public const ORDER_MISSING    = 'order_missing';
	public const DISCARDED        = 'discarded';
	public const NO_CONSENT       = 'no_consent';
	public const FREQUENCY_CAP    = 'frequency_cap';
	public const MARKETING_OFF    = 'marketing_off';
}
