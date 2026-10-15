<?php
/**
 * Renders the real admin screens to static HTML in build/preview/ for a visual check.
 * Usage: php tests/preview/build.php   then   php -S 127.0.0.1:8765 -t build/preview
 *
 * @package Povikvane\WooCommerce
 */

// phpcs:disable

require __DIR__ . '/stubs.php';

use Povikvane\WooCommerce\Admin\ConnectionPanel;
use Povikvane\WooCommerce\Admin\DeliveryPanel;
use Povikvane\WooCommerce\Admin\MarketingSection;
use Povikvane\WooCommerce\Admin\OverviewPanel;
use Povikvane\WooCommerce\Admin\AdvancedPanel;
use Povikvane\WooCommerce\Admin\SettingsPage;
use Povikvane\WooCommerce\Admin\SetupWizard;
use Povikvane\WooCommerce\Admin\TemplatesSection;
use Povikvane\WooCommerce\Admin\TestSender;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Balance\OptionBalanceStore;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Marketing\ConsentRepository;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Setup\SetupProgress;
use Povikvane\WooCommerce\Setup\SetupState;
use Povikvane\WooCommerce\Support\Crypto;
use Povikvane\WooCommerce\Support\OptionSendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SettingsSanitizer;
use Povikvane\WooCommerce\Support\SystemClock;
use Povikvane\WooCommerce\Webhook\OptionActivity;
use Povikvane\WooCommerce\Webhook\Token;

if ( 'bg_BG' === getenv( 'PVK_LANG' ) ) {
	$GLOBALS['pvk_mo'] = Povikvane\WooCommerce\Tests\Support\MoFile::read( dirname( __DIR__, 2 ) . '/languages/povikvane-for-woocommerce-bg_BG.mo' );
}
$prefix = 'bg_BG' === getenv( 'PVK_LANG' ) ? 'bg-' : '';
$out = dirname( __DIR__, 2 ) . '/build/preview';
@mkdir( $out, 0777, true );

// WordPress's own admin stylesheets, copied from a local site, so the page sits in realistic surroundings.
$wp = getenv( 'HOME' ) . '/Local Sites/wp-plugin-tests/app/public';
@mkdir( $out . '/wp-css', 0777, true );
foreach ( array( 'wp-admin/css/common.min.css', 'wp-admin/css/forms.min.css', 'wp-admin/css/list-tables.min.css', 'wp-admin/css/admin-menu.min.css', 'wp-includes/css/buttons.min.css', 'wp-admin/css/colors/fresh/colors.min.css' ) as $file ) {
	if ( is_readable( "$wp/$file" ) ) {
		copy( "$wp/$file", $out . '/wp-css/' . basename( $file ) );
	}
}
if ( ! is_link( $out . '/assets-root' ) ) {
	@symlink( dirname( __DIR__, 2 ), $out . '/assets-root' );
}

/**
 * Wrap a screen in a minimal WordPress admin page.
 */
function pvk_page( string $title, string $body ): string {
	$config = 'window.povikvaneAdmin=' . json_encode( array(
		'names'   => array( 'order_number', 'order_total', 'first_name', 'last_name', 'shop_name', 'order_status_url', 'tracking_number', 'tracking_url', 'shop_url', 'optout_link' ),
		'samples' => array(
			'latin'    => array( 'order_number' => '1234', 'order_total' => '45.00 EUR', 'first_name' => 'Maria', 'last_name' => 'Petrova', 'shop_name' => 'Demo Shop', 'shop_url' => 'https://shop.example.com', 'order_status_url' => 'https://shop.example.com/checkout/order-received/1234/?key=wc_order_abcdefghijklm', 'optout_link' => 'https://shop.example.com/?po=0123456789ab-0123456789ab' ),
			'cyrillic' => array( 'order_number' => '1234', 'order_total' => '45.00 EUR', 'first_name' => 'Мария', 'last_name' => 'Петрова', 'shop_name' => 'Demo Shop', 'shop_url' => 'https://shop.example.com', 'order_status_url' => 'https://shop.example.com/checkout/order-received/1234/?key=wc_order_abcdefghijklm', 'optout_link' => 'https://shop.example.com/?po=0123456789ab-0123456789ab' ),
		),
		'i18n'    => array( 'gsm7' => __( 'Standard SMS' ), 'ucs2' => __( 'Unicode SMS (e.g. Cyrillic)' ), 'characters' => __( 'characters' ), 'segment' => __( 'SMS part' ), 'segments' => __( 'SMS parts' ), 'credit' => __( 'credit' ), 'credits' => __( 'credits' ), 'cyrillic' => __( 'With a Cyrillic name:' ), 'unknown' => __( 'Unknown placeholder:' ), 'empty' => __( 'Empty message: nothing will be sent.' ) ),
	) ) . ';';

	return '<!doctype html><html lang="' . ( 'bg_BG' === getenv( 'PVK_LANG' ) ? 'bg' : 'en' ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>'
		. '<link rel="stylesheet" href="wp-css/common.min.css"><link rel="stylesheet" href="wp-css/forms.min.css"><link rel="stylesheet" href="wp-css/buttons.min.css"><link rel="stylesheet" href="wp-css/list-tables.min.css"><link rel="stylesheet" href="wp-css/colors.min.css">'
		. '<link rel="stylesheet" href="assets-root/assets/css/admin.css">'
		. '<style>body{margin:0;background:#f0f0f1}#pvk-bar{height:32px;background:#1d2327}#pvk-side{position:absolute;top:32px;left:0;bottom:0;width:160px;background:#1d2327}#wpcontent{margin-left:160px;padding-left:20px;padding-top:0}@media(max-width:782px){#pvk-side{display:none}#wpcontent{margin-left:0;padding-left:10px}}</style></head>'
		. '<body class="wp-admin wp-core-ui"><div id="pvk-bar"></div><div id="pvk-side"></div><div id="wpcontent"><div id="wpbody-content">' . $body . '</div></div>'
		. '<script>' . $config . '</script>'
		. '<script src="assets-root/assets/js/tabs.js"></script><script src="assets-root/assets/js/segments.js"></script><script src="assets-root/assets/js/admin.js"></script><script src="assets-root/assets/js/status.js"></script><script src="assets-root/assets/js/select.js"></script></body></html>';
}

/**
 * Fill the fake options for a scenario.
 *
 * @param array<string,mixed> $extra Extra options.
 */
function pvk_options( bool $connected, array $extra = array() ): void {
	$crypto = new Crypto( 'preview-salt' );

	$GLOBALS['pvk_options'] = array_merge(
		array(
			Settings::OPTION => array( 'service_id' => $connected ? 'a1b2c3d4-e5f6-7890-abcd-ef1234567890' : '', 'default_country' => 'BG' ),
		),
		$connected ? array(
			Settings::KEY_OPTION          => $crypto->encrypt( 'sk_live_preview' ),
			Capabilities::OPTION          => array( 'checked_at' => time() - 300, 'connection' => 'ok', 'account' => true, 'lookup' => true, 'balance' => array() ),
			OptionBalanceStore::OPTION    => array( 'available' => 142.8, 'locked' => 0, 'channels' => array( 'sms' ), 'fetched_at' => time() - 120, 'estimated' => false ),
			OptionActivity::OPTION        => array( 'time' => time() - 900, 'result' => 'updated' ),
		) : array(),
		$extra
	);
}

function pvk_settings_page(): SettingsPage {
	$settings  = new Settings( new Crypto( 'preview-salt' ) );
	$repo      = new Repository();
	$state     = new OptionSendingState();
	$triggers  = new TriggerRepository( get_locale() );
	$templates = new TemplatesSection( $triggers );
	$activity  = new OptionActivity();
	$delivery  = new DeliveryPanel( new Token(), $activity, $repo );
	$marketing = new MarketingSection( new MarketingSettings( get_locale() ), new ConsentRepository(), get_locale() );
	$tester    = new TestSender( $settings, new Normalizer(), new Povikvane\WooCommerce\Messages\Enqueuer( $repo, new Povikvane\WooCommerce\Support\ActionSchedulerScheduler(), new SystemClock() ) );

	return new SettingsPage(
		$settings, $repo, $state, $triggers, $templates, new OptionBalanceStore(), $marketing,
		new OverviewPanel(), new ConnectionPanel( $settings, $delivery ), new AdvancedPanel(), $activity, $tester,
		new SettingsSanitizer( $settings, $state ), new SetupProgress( $triggers, $repo, $activity ), static fn() => null
	);
}

function pvk_wizard(): SetupWizard {
	$settings  = new Settings( new Crypto( 'preview-salt' ) );
	$repo      = new Repository();
	$state     = new OptionSendingState();
	$triggers  = new TriggerRepository( get_locale() );
	$activity  = new OptionActivity();
	$tester    = new TestSender( $settings, new Normalizer(), new Povikvane\WooCommerce\Messages\Enqueuer( $repo, new Povikvane\WooCommerce\Support\ActionSchedulerScheduler(), new SystemClock() ) );

	return new SetupWizard(
		$settings, new SettingsSanitizer( $settings, $state ), $triggers, $repo, $tester, new SetupState(),
		new SetupProgress( $triggers, $repo, $activity ), new Token(), $activity, new TemplatesSection( $triggers ), static fn() => null
	);
}

/**
 * Sample message rows in the shape the database returns them.
 */
function pvk_rows(): array {
	$base = array( 'order_id' => 1234, 'kind' => 'transactional', 'channel' => 'sms', 'body' => 'Hi', 'reference' => '', 'api_message_id' => null, 'skip_reason' => null, 'error_code' => null, 'error_detail' => null, 'segments' => '1.00', 'credits' => '1.00', 'attempts' => 1, 'checks' => 0, 'send_after' => null, 'next_attempt_at' => null, 'final_at' => null );
	$when = static fn( int $ago ): string => gmdate( 'Y-m-d H:i:s', time() - $ago );
	$row  = static function ( int $id, string $event, string $status, string $phone, int $ago, array $over = array() ) use ( $base, $when ): array {
		return array_merge( $base, array( 'id' => $id, 'event' => $event, 'status' => $status, 'recipient' => $phone, 'reference' => 'woo:ab:' . $id, 'created_at' => $when( $ago ), 'updated_at' => $when( $ago ) ), $over );
	};

	return array(
		$row( 5, 'completed', 'delivered', '+359888123456', 600, array( 'api_message_id' => 'a5' ) ),
		$row( 4, 'completed', 'queued', '+359877123456', 3600, array( 'api_message_id' => 'a4' ) ),
		$row( 3, 'completed', 'failed', '+359899123456', 7200, array( 'error_detail' => 'stat:REJECTD err:002 code:-1', 'api_message_id' => 'a3' ) ),
		$row( 2, 'processing', 'skipped', '', 9000, array( 'skip_reason' => 'not_mobile' ) ),
		$row( 1, 'test', 'delivered', '+359888123456', 86400, array( 'order_id' => 0, 'api_message_id' => 'a1' ) ),
	);
}

$jobs = array();

foreach ( array( 'overview', 'messages', 'promotions', 'settings', 'advanced' ) as $tab ) {
	$jobs[ 'tab-' . $tab ] = array( 'settings', $tab, true );
}
$jobs['tab-overview-new'] = array( 'settings', 'overview', false );
$jobs['tab-overview-attention'] = array( 'settings', 'overview', true );
$jobs['tab-messages-viber'] = array( 'settings', 'messages', true );
$jobs['tab-connection-viber'] = array( 'settings', 'settings', true );

foreach ( array( 'welcome', 'connect', 'messages', 'test', 'reports', 'done' ) as $step ) {
	$jobs[ 'wizard-' . $step ] = array( 'wizard', $step, 'wizard-test' === 'x' );
}

foreach ( $jobs as $name => $job ) {
	list( $kind, $which, $connected ) = $job;

	unset( $GLOBALS['pvk_override_summary'] );
	pvk_options( 'wizard' === $kind ? in_array( $which, array( 'messages', 'test', 'reports', 'done' ), true ) : (bool) $connected );

	if ( 'tab-overview-attention' === $name ) {
		$GLOBALS['pvk_options'][ OptionBalanceStore::OPTION ] = array( 'available' => 20, 'locked' => 0, 'channels' => array( 'sms' ), 'fetched_at' => time(), 'estimated' => false );
		$GLOBALS['pvk_options'][ OptionActivity::OPTION ]     = null;
		$GLOBALS['pvk_override_summary']                      = array(
			array( 'status' => 'delivered', 'channel' => 'sms', 'delivered_via' => 'sms', 'total' => 6, 'credits' => 6.0 ),
			array( 'status' => 'failed', 'channel' => 'sms', 'delivered_via' => null, 'total' => 4, 'credits' => 0 ),
		);
	}

	if ( in_array( $name, array( 'tab-messages-viber', 'tab-connection-viber' ), true ) ) {
		$GLOBALS['pvk_options'][ Capabilities::OPTION ]    = array( 'checked_at' => time(), 'connection' => 'ok', 'account' => true, 'lookup' => true, 'templates' => true, 'fallback' => null, 'balance' => array( 'channels' => array( 'sms', 'viber' ) ) );
		$GLOBALS['pvk_options'][ TriggerRepository::OPTION ] = array(
			'processing' => array( 'enabled' => true, 'channel' => 'viber', 'viber_template' => 'tpl-1', 'viber_map' => array( 'tpl-1' => array( 'customField1' => 'order_number' ) ), 'template' => 'Order {order_number} is being processed.' ),
			'completed'  => array( 'enabled' => true, 'channel' => 'viber', 'viber_template' => 'tpl-2', 'template' => 'Order {order_number} shipped.' ),
		);
		$GLOBALS['pvk_options'][ Povikvane\WooCommerce\Viber\TemplateCatalog::OPTION ] = array(
			'fetched_at' => time(),
			'items'      => array(
				array( 'id' => 'tpl-1', 'name' => 'Order update', 'content' => 'Hi {firstName}, order {customField1}.', 'variables' => array( 'firstName', 'customField1' ), 'status' => 'approved', 'message_type' => 'transactional' ),
				array( 'id' => 'tpl-2', 'name' => 'Shipped notice', 'content' => 'Track {customField1} total {customField2}', 'variables' => array( 'customField1', 'customField2' ), 'status' => 'approved', 'message_type' => 'transactional' ),
				array( 'id' => 'tpl-3', 'name' => 'New offer', 'content' => 'Hi {firstName}', 'variables' => array( 'firstName' ), 'status' => 'pending', 'message_type' => null ),
			),
		);
	}
	$GLOBALS['pvk_rows'] = ( 'settings' === $kind && ! $connected ) ? array() : pvk_rows();
	$GLOBALS['pvk_summary'] = $GLOBALS['pvk_override_summary'] ?? ( ( 'settings' === $kind && ! $connected ) ? array() : array(
		array( 'status' => 'delivered', 'channel' => 'sms', 'delivered_via' => 'sms', 'total' => 41, 'credits' => 43.0 ),
		array( 'status' => 'delivered', 'channel' => 'viber', 'delivered_via' => 'viber', 'total' => 18, 'credits' => 14.4 ),
		array( 'status' => 'delivered', 'channel' => 'viber', 'delivered_via' => 'sms', 'total' => 3, 'credits' => 3.0 ),
		array( 'status' => 'failed', 'channel' => 'sms', 'delivered_via' => null, 'total' => 4, 'credits' => 0 ),
		array( 'status' => 'unconfirmed', 'channel' => 'sms', 'delivered_via' => null, 'total' => 2, 'credits' => 2.0 ),
		array( 'status' => 'queued', 'channel' => 'sms', 'delivered_via' => null, 'total' => 1, 'credits' => 1.0 ),
		array( 'status' => 'skipped', 'channel' => 'sms', 'delivered_via' => null, 'total' => 5, 'credits' => 0 ),
	) );
	$_GET = array();

	if ( 'settings' === $kind ) {
		$_GET['tab'] = $which;
		$render      = static fn() => pvk_settings_page()->render();
	} else {
		$_GET['step'] = $which;
		if ( 'test' === $which ) {
			$_GET['sent'] = '1';
		}
		$render = static fn() => pvk_wizard()->render();
	}

	ob_start();
	$render();
	file_put_contents( "$out/$prefix$name.html", pvk_page( $name, (string) ob_get_clean() ) );
	echo "wrote $name.html\n";
}
