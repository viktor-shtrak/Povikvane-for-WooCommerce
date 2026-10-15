<?php
/**
 * Decides whether an order message can go out over Viber.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

use Povikvane\WooCommerce\Messages\Channel;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\SegmentCounter;

defined( 'ABSPATH' ) || exit;

/**
 * Viber is used only when everything is certain: the account can do it, the chosen
 * template is approved and transactional, every variable can be filled with a short
 * value without a link (otherwise Povikvane bills the message as promotional), and the
 * SMS text fits in one part so the fallback is allowed. In every other case the same
 * message goes out as an ordinary SMS, so an order update is never lost.
 */
final class ViberPlanner {

	public const MAX_VALUE_LENGTH = 125;

	public const PROBLEM_NOT_APPROVED      = 'not_approved';
	public const PROBLEM_NOT_TRANSACTIONAL = 'not_transactional';
	public const PROBLEM_UNMAPPED          = 'unmapped';
	public const PROBLEM_LINK              = 'link';

	/**
	 * Plan a Viber message, or return null to send an SMS.
	 *
	 * @param string               $channel  Channel chosen for the status ("sms" or "viber").
	 * @param string               $template Chosen template ID.
	 * @param array<string,string> $values   Placeholder values for the order.
	 * @param string               $sms_text The message as an SMS.
	 * @param bool                 $ready    Whether the account can use Viber.
	 * @param TemplateCatalog      $catalog  Template list.
	 */
	public static function plan( string $channel, string $template, array $values, string $sms_text, bool $ready, TemplateCatalog $catalog ): ?ViberPlan {
		if ( Channel::Viber->value !== $channel || ! $ready || '' === $template || '' === trim( $sms_text ) ) {
			return null;
		}

		$found = $catalog->find( $template );

		if ( null === $found || null !== self::problem( $found ) ) {
			return null;
		}

		$map    = VariableMapper::map( $found->variables, Placeholders::NAMES );
		$params = array();

		foreach ( (array) $map as $variable => $placeholder ) {
			$value = trim( $values[ $placeholder ] ?? '' );

			if ( '' === $value || mb_strlen( $value ) > self::MAX_VALUE_LENGTH || 1 === preg_match( '~(?:https?://|www\.)~i', $value ) ) {
				return null;
			}

			$params[ $variable ] = $value;
		}

		if ( 1 !== ( new SegmentCounter() )->analyse( $sms_text )->segments ) {
			return null;
		}

		return new ViberPlan( $found->id, $params, $found->render( $params ), $sms_text );
	}

	/**
	 * Why a template cannot be used for order messages, or null if it can.
	 *
	 * @param ViberTemplate $template Template.
	 * @return string|null A PROBLEM_* constant.
	 */
	public static function problem( ViberTemplate $template ): ?string {
		if ( ! $template->is_approved() ) {
			return self::PROBLEM_NOT_APPROVED;
		}

		if ( ! $template->is_transactional() ) {
			return self::PROBLEM_NOT_TRANSACTIONAL;
		}

		if ( array() !== VariableMapper::unmapped( $template->variables, Placeholders::NAMES ) ) {
			return self::PROBLEM_UNMAPPED;
		}

		foreach ( (array) VariableMapper::map( $template->variables, Placeholders::NAMES ) as $placeholder ) {
			if ( in_array( $placeholder, VariableMapper::LINK_PLACEHOLDERS, true ) ) {
				return self::PROBLEM_LINK;
			}
		}

		return null;
	}
}
