<?php
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MWL_VERSION', '1.0.0' );
define( 'MWL_FILE', __FILE__ );
$GLOBALS['mwl_options'] = array();
$GLOBALS['mwl_transients'] = array();
$GLOBALS['fixture'] = '';
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $t, $v, ...$r ) { return $v; }
function get_option( $n, $d = false ) { return $GLOBALS['mwl_options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['mwl_options'][ $n ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['mwl_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['mwl_transients'][ $k ] = $v; return true; }
function wp_using_ext_object_cache() { return false; }
function home_url( $p = '/' ) { return 'https://www.moelog.com' . $p; }
function get_bloginfo( $w ) { return '6.8'; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_post( $url, $args ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return $GLOBALS['fixture']; }
require __DIR__ . '/wp-compat.php';
require __DIR__ . '/../includes/class-mwl-settings.php';
require __DIR__ . '/../includes/class-mwl-wikipedia.php';

$pass = 0; $fail = 0;
function expect( $label, $row, $state, $target ) {
	global $pass, $fail;
	$ok = isset( $row['state'] ) && $row['state'] === $state && $row['target'] === $target;
	if ( $ok ) { ++$pass; echo "  PASS  $label\n"; }
	else { ++$fail; echo "  FAIL  $label\n        期望 $state / " . json_encode( $target, JSON_UNESCAPED_UNICODE ) . "\n        實際 " . json_encode( $row, JSON_UNESCAPED_UNICODE ) . "\n"; }
}

echo "\n=== 日文維基實際回應 ===\n";
$GLOBALS['fixture'] = file_get_contents( __DIR__ . '/fixtures/ja.json' );
$titles = array( '初音ミク', '涼宮ハルヒの憂鬱', 'ぜつたいにそんざいしないこうもく9x8z', 'ボカロ', 'Special:検索' );
$r = MWL_Wikipedia::fetch( array_map( array( 'MWL_Wikipedia', 'normalize' ), $titles ), 'ja', 10 );
expect( '一般條目存在', $r['初音ミク'], 'exists', '初音ミク' );
expect( '重新導向解析到正式條目', $r['涼宮ハルヒの憂鬱'], 'exists', '涼宮ハルヒシリーズ' );
expect( '重新導向（ボカロ → VOCALOID）', $r['ボカロ'], 'exists', 'VOCALOID' );
expect( '不存在的條目', $r['ぜつたいにそんざいしないこうもく9x8z'], 'missing', 'ぜつたいにそんざいしないこうもく9x8z' );
expect( '特殊頁面視為不存在', $r['Special:検索'], 'missing', '特別:検索' );

echo "\n--- 快取內容 ---\n";
echo count( $GLOBALS['mwl_transients'] ) . " 筆\n";

echo "\n=== 英文維基實際回應 ===\n";
$GLOBALS['fixture'] = file_get_contents( __DIR__ . '/fixtures/en.json' );
$r2 = MWL_Wikipedia::fetch( array( 'Wordpress', 'Cowboy bebop', 'Zzzz nonexistent qqq9x8z' ), 'en', 10 );
expect( '大小寫重新導向 Wordpress → WordPress', $r2['Wordpress'], 'exists', 'WordPress' );
expect( 'Cowboy bebop → Cowboy Bebop', $r2['Cowboy bebop'], 'exists', 'Cowboy Bebop' );
expect( '不存在的英文條目', $r2['Zzzz nonexistent qqq9x8z'], 'missing', 'Zzzz nonexistent qqq9x8z' );

echo "\n=== 錯誤處理 ===\n";
$GLOBALS['fixture'] = '{"error":{"code":"maxlag","info":"Waiting for a database server"}}';
$r3 = MWL_Wikipedia::fetch( array( 'Whatever' ), 'en', 10 );
if ( null === $r3 ) { ++$pass; echo "  PASS  API 回錯誤時回傳 null（交給背景重試）\n"; } else { ++$fail; echo "  FAIL  API 錯誤未被攔截\n"; }

$GLOBALS['fixture'] = 'not json at all';
$r4 = MWL_Wikipedia::fetch( array( 'Whatever2' ), 'en', 10 );
if ( null === $r4 ) { ++$pass; echo "  PASS  回應非 JSON 時回傳 null\n"; } else { ++$fail; echo "  FAIL  非 JSON 未被攔截\n"; }

$before = count( $GLOBALS['mwl_transients'] );
$GLOBALS['fixture'] = '{"error":{"code":"x"}}';
MWL_Wikipedia::fetch( array( 'Whatever3' ), 'en', 10 );
if ( count( $GLOBALS['mwl_transients'] ) === $before ) { ++$pass; echo "  PASS  失敗時不寫入錯誤快取\n"; } else { ++$fail; echo "  FAIL  失敗卻寫了快取\n"; }

echo "\n========================================\n  通過 $pass 項，失敗 $fail 項\n========================================\n";
exit( $fail > 0 ? 1 : 0 );
