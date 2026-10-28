<?php
/**
 * Asks Povikvane what an order message would cost.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\ViberPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the message exactly as a real send would (Viber when a matching approved
 * template exists and Viber is on, otherwise SMS) with sample order values, and
 * prices it. Nothing is sent and nothing is charged.
 */
final class PriceEstimator {

	/**
	 * Constructor.
	 *
	 * @param Gateway         $gateway  API gateway.
	 * @param TemplateCatalog $catalog  Templates of the account.
	 * @param bool            $viber_on Whether Viber is switched on.
	 * @param bool            $ready    Whether the account can use Viber now.
	 * @param bool            $fallback Whether an SMS fallback is wanted.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly TemplateCatalog $catalog,
		private readonly bool $viber_on,
		private readonly bool $ready,
		private readonly bool $fallback
	) {}

	/**
	 * Price a message.
	 *
	 * @param string               $template Message with {placeholders}.
	 * @param string               $to       Sample recipient in international format.
	 * @param array<string,string> $values   Sample placeholder values.
	 * @return array{channel:string,estimate:PriceEstimate}|null Null when Povikvane did not price it.
	 */
	public function estimate( string $template, string $to, array $values ): ?array {
		$text = Placeholders::render( $template, $values );

		if ( '' === $text ) {
			return null;
		}

		$plan    = ViberPlanner::plan( $this->viber_on, $this->ready, $this->fallback, $template, $values, $text, $this->catalog );
		$message = array(
			'to'      => $to,
			'text'    => null === $plan ? $text : $plan->text,
			'channel' => null === $plan ? 'sms' : 'viber',
		);

		if ( null !== $plan ) {
			$message['template_id'] = $plan->template_id;
			$message['params']      = (object) $plan->params;

			if ( null !== $plan->fallback_sms ) {
				$message['fallback'] = array( 'sms' => $plan->fallback_sms );
			}
		}

		$estimate = PriceEstimate::from_result( $this->gateway->estimate( $message ) );

		return null === $estimate ? null : array(
			'channel'  => $message['channel'],
			'estimate' => $estimate,
		);
	}
}
