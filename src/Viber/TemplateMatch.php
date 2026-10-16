<?php
/**
 * How a message text relates to the Viber templates of the account.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * The result of looking for a template with the same wording as a message.
 */
final class TemplateMatch {

	public const READY           = 'ready';
	public const NOT_TRANSACTION = 'not_transactional';
	public const PENDING         = 'pending';
	public const REJECTED        = 'rejected';
	public const NONE            = 'none';

	/**
	 * Constructor.
	 *
	 * @param string               $state    One of the constants above.
	 * @param ViberTemplate|null   $template The template with the same wording, if any.
	 * @param array<string,string> $mapping  Template variable => placeholder that fills it.
	 */
	public function __construct(
		public readonly string $state,
		public readonly ?ViberTemplate $template = null,
		public readonly array $mapping = array()
	) {}

	/**
	 * Whether a Viber message can be sent with this match.
	 */
	public function is_ready(): bool {
		return self::READY === $this->state;
	}

	/**
	 * Nothing matched.
	 */
	public static function none(): self {
		return new self( self::NONE );
	}
}
