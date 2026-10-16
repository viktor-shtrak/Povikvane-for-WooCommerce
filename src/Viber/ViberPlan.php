<?php
/**
 * How one message will go out over Viber.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Template, filled text and the SMS the API falls back to.
 */
final class ViberPlan {

	/**
	 * Constructor.
	 *
	 * @param string               $template_id  Template ID.
	 * @param array<string,string> $params       Variable values.
	 * @param string               $text         Filled Viber text, for the log.
	 * @param string|null          $fallback_sms SMS text, one segment; null when the account sends no fallback.
	 */
	public function __construct(
		public readonly string $template_id,
		public readonly array $params,
		public readonly string $text,
		public readonly ?string $fallback_sms
	) {}

	/**
	 * What is stored with the message.
	 *
	 * @return array{template_id:string,params:array<string,string>,fallback_sms:string|null}
	 */
	public function payload(): array {
		return array(
			'template_id'  => $this->template_id,
			'params'       => $this->params,
			'fallback_sms' => $this->fallback_sms,
		);
	}
}
