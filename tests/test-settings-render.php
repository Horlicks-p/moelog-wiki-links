<?php
/**
 * 設定頁渲染的 smoke test：確認選單能正確輸出、且選中狀態正確。
 *
 * 不需要 WordPress，也不連外網。
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MWL_VERSION', '1.0.0' );
define( 'MWL_FILE', __FILE__ );

$GLOBALS['mwl_options'] = array();

function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function get_option( $n, $d = false ) { return isset( $GLOBALS['mwl_options'][ $n ] ) ? $GLOBALS['mwl_options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['mwl_options'][ $n ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function wp_using_ext_object_cache() { return false; }
function home_url( $p = '/' ) { return 'https://www.moelog.com' . $p; }
function get_bloginfo( $w ) { return '6.8'; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return str_replace( '&amp;', '&#038;', htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) ); }
function esc_js( $s ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function selected( $sel, $cur = true, $echo = true ) {
	$r = ( (string) $sel === (string) $cur ) ? " selected='selected'" : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function checked( $sel, $cur = true, $echo = true ) {
	$r = ( (string) $sel === (string) $cur ) ? " checked='checked'" : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '">'; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( null === $text ? 'Save' : $text ) . '</button>'; }
function admin_url( $p = '' ) { return 'https://www.moelog.com/wp-admin/' . $p; }
function current_user_can( $c ) { return true; }
function plugin_basename( $f ) { return 'moelog-wiki-links/moelog-wiki-links.php'; }
function wp_using_ext_object_cache_stub() {}

require __DIR__ . '/wp-compat.php';
require __DIR__ . '/../includes/class-mwl-settings.php';
require __DIR__ . '/../includes/class-mwl-wikipedia.php';

$pass = 0;
$fail = 0;

function contains( $label, $haystack, $needle ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n        找不到: $needle\n";
	}
}

function absent( $label, $haystack, $needle ) {
	global $pass, $fail;
	if ( false === strpos( $haystack, $needle ) ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label -- 不該出現: $needle\n";
	}
}

/** 取得設定頁的 HTML。 */
function render() {
	MWL_Settings::reset_cache();
	ob_start();
	MWL_Settings::instance()->render_page();
	return ob_get_clean();
}

echo "\n=== 預設值（ja）===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array();
$html                                   = render();
contains( '輸出 select 元素', $html, '<select name="mwl_settings[default_lang]" id="mwl_default_lang">' );
contains( '日文被選中', $html, "value=\"ja\" selected='selected'" );
contains( '選單顯示語言名稱與代碼', $html, '日本語（日文）　—　ja' );
contains( '有自訂代碼選項', $html, 'value="__custom__"' );
contains( '自訂欄位預設隱藏', $html, 'style="display:none"' );
absent( '自訂選項未被選中', $html, "value=\"__custom__\" selected='selected'" );

echo "\n=== 清單內的語言（zh-tw）===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array( 'default_lang' => 'zh-tw' );
$html                                   = render();
contains( '中文繁體被選中', $html, "value=\"zh-tw\" selected='selected'" );
absent( '日文未被選中', $html, "value=\"ja\" selected='selected'" );

echo "\n=== 清單外的語言（nl）===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array( 'default_lang' => 'nl' );
$html                                   = render();
contains( '自訂選項被選中', $html, "value=\"__custom__\" selected='selected'" );
contains( '自訂欄位帶出原值', $html, 'id="mwl_default_lang_custom"' );
contains( '自訂欄位的值是 nl', $html, 'value="nl"' );
absent( '自訂欄位不隱藏', $html, 'style="display:none"' );

echo "\n=== 其他欄位仍正常 ===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array();
$html                                   = render();
contains( '紅連結行為欄位', $html, 'mwl_settings[redlink_target]' );
contains( '檢查模式欄位', $html, 'mwl_settings[check_mode]' );
contains( '快取天數欄位', $html, 'mwl_settings[ttl_hit]' );
contains( '摘要選項註明只影響手動摘要', $html, '手動摘要（the_excerpt）也套用' );
contains( '清除快取表單', $html, 'value="mwl_flush_cache"' );

echo "\n========================================\n";
echo "  通過 $pass 項，失敗 $fail 項\n";
echo "========================================\n";
exit( $fail > 0 ? 1 : 0 );
