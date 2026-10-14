<?php
/**
 * Whether sending is paused.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Global pause switch with a reason: the API key was rejected, or credits ran out.
 */
interface SendingState {

	public const REASON_AUTH    = 'auth';
	public const REASON_CREDITS = 'credits';

	/**
	 * Current pause reason, or null when sending is active.
	 */
	public function paused_reason(): ?string;

	/**
	 * Pause sending.
	 *
	 * @param string $reason One of the REASON_* constants.
	 */
	public function pause( string $reason ): void;

	/**
	 * Resume sending.
	 */
	public function resume(): void;
}
