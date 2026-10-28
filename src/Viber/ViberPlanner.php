<?php
/**
 * Decides whether an order message goes out over Viber.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

use Povikvane\WooCommerce\Messages\SegmentCounter;

defined( 'ABSPATH' ) || exit;

/**
 * A message is sent over Viber when Viber is switched on and available, an approved
 * transactional template has the same wording as the message, every variable can be
 * filled with a short value without a link (otherwise Povikvane bills the message as
 * promotional) and, when the account sends an SMS fallback, that SMS fits in one part.
 * In every other case the same message goes out as an ordinary SMS, so an order update
 * is never lost.
 */
final class ViberPlanner {

	public const MAX_VALUE_LENGTH = 125;

	public const PROBLEM_NOT_APPROVED      = 'not_approved';
	public const PROBLEM_NOT_TRANSACTIONAL = 'not_transactional';

	/**
	 * The plan for a message, or null to send it as an SMS.
	 *
	 * @param bool                 $enabled   Whether the shop switched Viber on.
	 * @param bool                 $ready     Whether the account can use Viber right now.
	 * @param bool                 $fallback  Whether to ask the service for an SMS fallback.
	 * @param string               $template  The status message with {placeholders}.
	 * @param array<string,string> $values    Placeholder values for the order.
	 * @param string               $sms_text  The filled message.
	 * @param TemplateCatalog      $catalog   Templates of the account.
	 */
	public static function plan( bool $enabled, bool $ready, bool $fallback, string $template, array $values, string $sms_text, TemplateCatalog $catalog ): ?ViberPlan {
		if ( ! $enabled || ! $ready || '' === trim( $sms_text ) ) {
			return null;
		}

		$match = TemplateMatcher::find( $template, $catalog );

		if ( ! $match->is_ready() || null === $match->template ) {
			return null;
		}

		$params = array();

		foreach ( $match->mapping as $variable => $placeholder ) {
			if ( in_array( $placeholder, VariableMapper::LINK_PLACEHOLDERS, true ) ) {
				return null;
			}

			$value = trim( $values[ $placeholder ] ?? '' );

			if ( '' === $value || mb_strlen( $value ) > self::MAX_VALUE_LENGTH || 1 === preg_match( '~(?:https?://|www\.)~i', $value ) ) {
				return null;
			}

			$params[ $variable ] = $value;
		}

		if ( $fallback && 1 !== ( new SegmentCounter() )->analyse( $sms_text )->segments ) {
			return null;
		}

		return new ViberPlan( $match->template->id, $params, $match->template->render( $params ), $fallback ? $sms_text : null );
	}

	/**
	 * Why a template cannot be used, or null when it can.
	 *
	 * @param ViberTemplate $template Template.
	 * @return string|null A PROBLEM_* constant.
	 */
	public static function problem( ViberTemplate $template ): ?string {
		if ( ! $template->is_approved() || ! $template->is_carrier_approved() ) {
			return self::PROBLEM_NOT_APPROVED;
		}

		return $template->is_transactional() ? null : self::PROBLEM_NOT_TRANSACTIONAL;
	}
}
