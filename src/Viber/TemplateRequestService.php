<?php
/**
 * Sends the chosen messages for review.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * For each status: skip it when a template already covers the text, explain when the
 * text could not be a Viber template, otherwise send it and remember that it was sent.
 */
final class TemplateRequestService {

	public const SENT    = 'sent';
	public const COVERED = 'covered';
	public const BLOCKED = 'blocked';
	public const FAILED  = 'failed';

	/**
	 * Constructor.
	 *
	 * @param TemplateSubmitter $submitter Sends templates to Povikvane.
	 * @param TemplateCatalog   $catalog   Templates of the account.
	 * @param TemplateRequests  $requests  Requests made so far.
	 */
	public function __construct(
		private readonly TemplateSubmitter $submitter,
		private readonly TemplateCatalog $catalog,
		private readonly TemplateRequests $requests
	) {}

	/**
	 * Send the texts for review.
	 *
	 * @param array<string,string> $texts  Order status slug => message text.
	 * @param array<string,string> $labels Order status slug => readable status name.
	 * @param string               $shop   Shop name, used in template names.
	 * @param int                  $now    Current time.
	 * @return array<string,array{result:string,reason:string}> Per status.
	 */
	public function send( array $texts, array $labels, string $shop, int $now ): array {
		$out = array();

		foreach ( $texts as $status => $text ) {
			$match = TemplateMatcher::find( $text, $this->catalog );

			if ( TemplateMatch::NONE !== $match->state ) {
				$out[ $status ] = array(
					'result' => self::COVERED,
					'reason' => '',
				);
				continue;
			}

			if ( 0 !== $this->requests->sent_at( $status, $text ) ) {
				$out[ $status ] = array(
					'result' => self::COVERED,
					'reason' => '',
				);
				continue;
			}

			$draft = TemplateDraft::from_text( $text );

			if ( null !== $draft->problem ) {
				$out[ $status ] = array(
					'result' => self::BLOCKED,
					'reason' => self::problem_text( $draft->problem ),
				);
				continue;
			}

			$result = $this->submitter->submit( self::name_for( $labels[ $status ] ?? $status, $shop ), $draft->content );

			if ( $result->accepted ) {
				$this->requests->record( $status, $text, $now );
			}

			$out[ $status ] = array(
				'result' => $result->accepted ? self::SENT : self::FAILED,
				'reason' => $result->reason,
			);
		}

		return $out;
	}

	/**
	 * Template name: shop and status, at most 48 characters.
	 *
	 * @param string $status Readable status name.
	 * @param string $shop   Shop name.
	 */
	public static function name_for( string $status, string $shop ): string {
		$name = trim( $shop . ' - ' . $status );

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 48 ) : substr( $name, 0, 48 );
	}

	/**
	 * Plain-language reason a text cannot be a Viber template.
	 *
	 * @param string $problem A TemplateDraft::PROBLEM_* constant.
	 */
	public static function problem_text( string $problem ): string {
		return match ( $problem ) {
			TemplateDraft::PROBLEM_EMPTY    => __( 'The message is empty.', 'povikvane-for-woocommerce' ),
			TemplateDraft::PROBLEM_EDGE     => __( 'A Viber message cannot start or end with a field such as {order_number} or {shop_name}. Put some words before and after it.', 'povikvane-for-woocommerce' ),
			TemplateDraft::PROBLEM_LINK     => __( 'A Viber message cannot contain a field that is a link.', 'povikvane-for-woocommerce' ),
			TemplateDraft::PROBLEM_TOO_MANY => __( 'A Viber message can have at most three different fields.', 'povikvane-for-woocommerce' ),
			default                         => __( 'The message is too long for Viber.', 'povikvane-for-woocommerce' ),
		};
	}
}
