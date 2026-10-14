<?php
/**
 * Stand-ins for the WordPress functions the admin screens call, so they can be
 * rendered to static HTML for a visual check. Development tool only: never shipped.
 *
 * @package Povikvane\WooCommerce
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'Povikvane\\WooCommerce\\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/povikvane-for-woocommerce.php' );
define( 'Povikvane\\WooCommerce\\VERSION', '0.5.0-preview' );

$GLOBALS['pvk_options']    = array();
$GLOBALS['pvk_transients'] = array();

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
$GLOBALS['pvk_mo'] = array();
function pvk_t( $t ) { return $GLOBALS['pvk_mo'][ $t ] ?? $t; }
function __( $t, $d = '' ) { return pvk_t( $t ); }
function esc_html__( $t, $d = '' ) { return esc_html( pvk_t( $t ) ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( pvk_t( $t ) ); }
function _n( $s, $p, $n, $d = '' ) {
	$key = $s . "\0" . $p;
	if ( isset( $GLOBALS['pvk_mo'][ $key ] ) ) {
		$forms = explode( "\0", $GLOBALS['pvk_mo'][ $key ] );
		return $forms[ 1 === (int) $n ? 0 : 1 ] ?? $s;
	}
	return 1 === (int) $n ? $s : $p;
}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, '.', ',' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_date( $f, $t = null ) { return gmdate( $f, (int) $t ); }
function plugins_url( $path = '', $plugin = '' ) { return '/assets-root/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return '/wp-admin/' . ltrim( $path, '/' ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) { $h = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; if ( $echo ) { echo $h; } return $h; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked' : ''; if ( $echo ) { echo $r; } return $r; }
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected' : ''; if ( $echo ) { echo $r; } return $r; }
function get_option( $n, $d = false ) { return $GLOBALS['pvk_options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['pvk_options'][ $n ] = $v; return true; }
function get_transient( $n ) { return $GLOBALS['pvk_transients'][ $n ] ?? false; }
function delete_transient( $n ) { unset( $GLOBALS['pvk_transients'][ $n ] ); return true; }
function set_transient( $n, $v, $e = 0 ) { $GLOBALS['pvk_transients'][ $n ] = $v; return true; }
function get_current_user_id() { return 1; }
function current_user_can( $c ) { return true; }
function get_locale() { return getenv( 'PVK_LANG' ) ?: 'en_US'; }
function home_url( $p = '' ) { return 'https://shop.example.com' . $p; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function get_bloginfo( $k = '' ) { return 'Demo Shop'; }
function wp_specialchars_decode( $s, $q = 0 ) { return htmlspecialchars_decode( $s, $q ); }
function wc_get_order_statuses() { return array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' ); }
function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=nonce'; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function wp_kses_post( $t ) { return $t; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Sofia' ); }
function wp_localize_script() {}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '"><input type="hidden" name="action" value="update"><input type="hidden" name="_wpnonce" value="n"><input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php?page=povikvane-sms">'; }
function settings_errors() {}
function rest_url( $p = '' ) { return 'https://shop.example.com/wp-json/' . $p; }
function wp_html_excerpt( $t, $n ) { return substr( $t, 0, $n ); }
function wp_get_referer() { return false; }
function absint( $v ) { return abs( (int) $v ); }
function wp_die( $m = '' ) { echo $m; }
function wp_salt( $s = '' ) { return 'preview-salt'; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}

class PvkCountries {
	public function get_countries() { return array( 'BG' => 'Bulgaria', 'DE' => 'Germany', 'GR' => 'Greece', 'RO' => 'Romania', 'GB' => 'United Kingdom', 'US' => 'United States' ); }
	public function get_country_calling_code( $c ) { return array( 'BG' => '+359', 'DE' => '+49', 'GR' => '+30', 'RO' => '+40', 'GB' => '+44', 'US' => '+1' )[ $c ] ?? ''; }
}
class PvkWC { public $countries; public function __construct() { $this->countries = new PvkCountries(); } }
function WC() { static $wc; return $wc ??= new PvkWC(); }

class PvkWpdb {
	public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return '0'; }
	public function get_results( $q, $o = null ) { return str_contains( (string) $q, 'povikvane_messages' ) ? ( $GLOBALS['pvk_rows'] ?? array() ) : array(); }
	public function get_col( $q ) { return array(); }
	public function get_row( $q, $o = null ) { $rows = $GLOBALS['pvk_rows'] ?? array(); return $rows[4] ?? null; }
	public function esc_like( $t ) { return $t; }
}
$GLOBALS['wpdb'] = new PvkWpdb();

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
