<?php
/**
 * Tests for dismissible attention items and the channel label.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Admin\AttentionDismissals;
use Povikvane\WooCommerce\Admin\ChannelLabel;
use Povikvane\WooCommerce\Log\Message;

/**
 * Dismissals hide one item for a week; the label names the channel.
 */
final class AttentionDismissalsTest extends TestCase {

	/**
	 * An item with the given key.
	 *
	 * @param string $key Item key.
	 * @return array{key:string,tone:string,title:string,text:string,label:string,url:string,external:bool}
	 */
	private function item( string $key ): array {
		return array(
			'key'      => $key,
			'tone'     => 'warn',
			'title'    => $key,
			'text'     => '',
			'label'    => '',
			'url'      => '',
			'external' => false,
		);
	}

	/**
	 * A dismissal lasts a week.
	 */
	public function test_a_dismissed_item_is_hidden_for_a_week_then_returns(): void {
		$items = array( $this->item( 'a' ), $this->item( 'b' ) );
		$meta  = array( 'a' => 1000 );

		$this->assertSame( array( 'b' ), array_column( AttentionDismissals::visible( $items, $meta, 1000 + 86400 ), 'key' ) );
		$this->assertSame( array( 'a', 'b' ), array_column( AttentionDismissals::visible( $items, $meta, 1000 + 8 * 86400 ), 'key' ) );
	}

	/**
	 * Unreadable stored data hides nothing.
	 */
	public function test_garbage_meta_hides_nothing(): void {
		$items = array( $this->item( 'a' ) );

		$this->assertCount( 1, AttentionDismissals::visible( $items, '', 5 ) );
		$this->assertCount(
			1,
			AttentionDismissals::visible(
				$items,
				array(
					'a' => 'x',
					3   => 9,
				),
				5
			)
		);
	}

	/**
	 * The label names SMS, Viber and the fallback.
	 */
	public function test_channel_label(): void {
		$message = new Message();

		$this->assertSame( 'SMS', ChannelLabel::text( $message ) );

		$message->channel = 'viber';
		$this->assertSame( 'Viber', ChannelLabel::text( $message ) );

		$message->delivered_via = 'sms';
		$this->assertSame( 'Viber, delivered by SMS', ChannelLabel::text( $message ) );
	}
}
