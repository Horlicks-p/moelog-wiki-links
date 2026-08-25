<?php
/**
 * Moelog Wiki Links 的離線測試：以 stub 取代 WordPress 函式，不連外網。
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MWL_VERSION', '1.0.0' );
define( 'MWL_FILE', __FILE__ );

$GLOBALS['mwl_options']    = array();
$GLOBALS['mwl_transients'] = array();
$GLOBALS['mwl_scheduled']  = array();
$GLOBALS['mwl_http_calls'] = 0;
$GLOBALS['mwl_http_batches'] = array();
$GLOBALS['mwl_http_fail'] = false;

function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['mwl_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mwl_options'][ $name ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['mwl_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['mwl_transients'][ $key ] = $value; return true; }
function wp_using_ext_object_cache() { return false; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['mwl_scheduled'][] = array( $hook, $args ); return true; }
function home_url( $path = '/' ) { return 'https://www.moelog.com' . $path; }
function get_bloginfo( $what ) { return '6.8'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_remote_post( $url, $args ) {
	++$GLOBALS['mwl_http_calls'];
	if ( ! empty( $GLOBALS['mwl_http_fail'] ) ) {
		return new WP_Error();
	}
	$titles = explode( '|', $args['body']['titles'] );
	$GLOBALS['mwl_http_batches'][] = $titles;
	$pages = array();
	foreach ( $titles as $t ) {
		$pages[] = array( 'pageid' => 1, 'ns' => 0, 'title' => $t );
	}
	return array( 'code' => 200, 'body' => wp_json_encode( array( 'batchcomplete' => true, 'query' => array( 'pages' => $pages ) ) ) );
}
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
class WP_Error {}
class WP_Post {
	public $ID = 0;
	public $post_content = '';
}
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function plugin_basename( $f ) { return 'moelog-wiki-links/moelog-wiki-links.php'; }
function admin_url( $p = '' ) { return 'https://www.moelog.com/wp-admin/' . $p; }

require __DIR__ . '/wp-compat.php';
require __DIR__ . '/../includes/class-mwl-settings.php';
require __DIR__ . '/../includes/class-mwl-wikipedia.php';
require __DIR__ . '/../includes/class-mwl-parser.php';

/* --------------------------------------------------------------------- */

$pass = 0;
$fail = 0;

function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
		echo "        期望: " . var_export( $expected, true ) . "\n";
		echo "        實際: " . var_export( $actual, true ) . "\n";
	}
}

function contains( $label, $haystack, $needle ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
		echo "        找不到: $needle\n";
		echo "        內容中: $haystack\n";
	}
}

function absent( $label, $haystack, $needle ) {
	global $pass, $fail;
	if ( false === strpos( $haystack, $needle ) ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label（不該出現 $needle）\n";
		echo "        內容中: $haystack\n";
	}
}

/** 重設「本次請求已送出的批次數」這個 static 計數。 */
function reset_batches() {
	$ref  = new ReflectionClass( 'MWL_Wikipedia' );
	$prop = $ref->getProperty( 'batches_this_request' );
	// PHP 8.1 起 reflection 預設就能存取私有成員，setAccessible() 在 8.5 已標為
	// deprecated；8.0 以下則非呼叫不可，否則會拋 ReflectionException。
	if ( PHP_VERSION_ID < 80100 ) {
		$prop->setAccessible( true );
	}
	$prop->setValue( null, 0 );
	$GLOBALS['mwl_http_calls']   = 0;
	$GLOBALS['mwl_http_batches'] = array();
	$GLOBALS['mwl_scheduled']    = array();
}

/** 直接把某個條目的狀態塞進快取。 */
function seed( $title, $lang, $exists, $target = null ) {
	$ref = new ReflectionClass( 'MWL_Wikipedia' );
	$m   = $ref->getMethod( 'write_cache' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	$m->invoke( null, MWL_Wikipedia::normalize( $title ), $lang, $exists, null === $target ? MWL_Wikipedia::normalize( $title ) : $target );
}

echo "\n=== 1. 網址組裝 ===\n";
check( 'ja 條目網址', MWL_Wikipedia::article_url( '涼宮ハルヒの憂鬱', 'ja' ), 'https://ja.wikipedia.org/wiki/' . rawurlencode( '涼宮ハルヒの憂鬱' ) );
check( '空白轉底線', MWL_Wikipedia::article_url( 'Cowboy Bebop', 'en' ), 'https://en.wikipedia.org/wiki/Cowboy_Bebop' );
check( '括號保持原樣', MWL_Wikipedia::article_url( 'Fate/stay night (TV)', 'en' ), 'https://en.wikipedia.org/wiki/Fate/stay_night_(TV)' );
check( 'zh-tw 變體用 zh 網域＋變體路徑', MWL_Wikipedia::article_url( 'WordPress', 'zh-tw' ), 'https://zh.wikipedia.org/zh-tw/WordPress' );
check( 'zh-tw 的 API 端點是 zh', MWL_Wikipedia::api_endpoint( 'zh-tw' ), 'https://zh.wikipedia.org/w/api.php' );
echo "\n=== 1b. 網址編碼（使用 WP core 的 add_query_arg 實作）===\n";
check(
	'搜尋網址只編碼一次',
	MWL_Wikipedia::search_url( '初音ミク', 'ja' ),
	'https://ja.wikipedia.org/w/index.php?search=' . rawurlencode( '初音ミク' ) . '&title=Special:Search&fulltext=1'
);
absent( '搜尋網址沒有雙重編碼', MWL_Wikipedia::search_url( '初音ミク', 'ja' ), '%25' );
contains( '搜尋網址解碼一次即還原', rawurldecode( MWL_Wikipedia::search_url( '初音ミク', 'ja' ) ), '初音ミク' );
check(
	'建立頁網址只編碼一次',
	MWL_Wikipedia::create_url( '存在しない条目', 'ja' ),
	'https://ja.wikipedia.org/w/index.php?title=' . rawurlencode( '存在しない条目' ) . '&action=edit&redlink=1'
);
absent( '建立頁網址沒有雙重編碼', MWL_Wikipedia::create_url( '存在しない条目', 'ja' ), '%25' );
check(
	'建立頁網址把空格轉底線',
	MWL_Wikipedia::create_url( 'Cowboy Bebop', 'en' ),
	'https://en.wikipedia.org/w/index.php?title=Cowboy_Bebop&action=edit&redlink=1'
);
absent( '條目網址沒有雙重編碼', MWL_Wikipedia::article_url( '初音ミク', 'ja' ), '%25' );
contains( '條目網址解碼一次即還原', rawurldecode( MWL_Wikipedia::article_url( '初音ミク', 'ja' ) ), '初音ミク' );


echo "\n=== 2. 標題正規化 ===\n";
check( '底線與空白等價', MWL_Wikipedia::normalize( 'cowboy_bebop' ), MWL_Wikipedia::normalize( 'cowboy bebop' ) );
check( '首字母大寫', MWL_Wikipedia::normalize( 'wordpress' ), 'Wordpress' );
check( '日文不受影響', MWL_Wikipedia::normalize( '初音ミク' ), '初音ミク' );

echo "\n=== 3. 詞彙擷取 ===\n";
$content = '參考 [[初音ミク]] 與 [[en:Vocaloid|ボカロ]]，還有 \[[這個不轉換]]。';
$terms   = MWL_Parser::extract_terms( $content );
check( '擷取數量', count( $terms ), 2 );
check( '預設語言為 ja', $terms[0]['lang'], 'ja' );
check( '條目名稱', $terms[0]['title'], '初音ミク' );
check( '語言前綴', $terms[1]['lang'], 'en' );
check( '前綴後的條目名', $terms[1]['title'], 'Vocaloid' );
check( '自訂顯示文字', $terms[1]['label'], 'ボカロ' );

$terms2 = MWL_Parser::extract_terms( '<pre>[[不該處理]]</pre><code>[[也不該]]</code><a href="/x">[[更不該]]</a> [[應該處理]]' );
check( '受保護區塊被略過', count( $terms2 ), 1 );
check( '只留下該處理的', $terms2[0]['title'], '應該處理' );

$terms3 = MWL_Parser::extract_terms( '<img src="/a[[b]].png" alt="[[c]]"> [[真的]]' );
check( 'HTML 屬性內不處理', count( $terms3 ), 1 );

$terms4 = MWL_Parser::extract_terms( '[[Category:動畫]] 與 [[xx:未知前綴]]' );
check( '非允許前綴視為條目一部分', $terms4[0]['title'], 'Category:動畫' );
check( '未知語言碼不當前綴', $terms4[1]['title'], 'xx:未知前綴' );

echo "\n=== 4. 連結輸出（存在的條目）===\n";
seed( '初音ミク', 'ja', true );
seed( 'Vocaloid', 'en', true, 'Vocaloid' );
$parser = MWL_Parser::instance();
$out    = $parser->filter_content( '參考 [[初音ミク]] 與 [[en:Vocaloid|ボカロ]]。' );
contains( '藍色連結指向 ja 維基', $out, 'href="https://ja.wikipedia.org/wiki/' . rawurlencode( '初音ミク' ) . '"' );
contains( '英文條目用 en 網域', $out, 'href="https://en.wikipedia.org/wiki/Vocaloid"' );
contains( '顯示文字被採用', $out, '>ボカロ</a>' );
absent( '存在的條目不加紅連結 class', $out, 'mwl-new' );
contains( '預設加 nofollow', $out, 'rel="nofollow"' );

echo "\n=== 5. 連結輸出（不存在的條目）===\n";
seed( 'ぜったいに存在しない条目', 'ja', false );
$out = $parser->filter_content( '這個 [[ぜったいに存在しない条目]] 沒有。' );
contains( '紅連結 class', $out, 'mwl-new' );
contains( '預設連到搜尋頁', $out, 'title=Special:Search' );
contains( '提示文字', $out, '尚無' );

$GLOBALS['mwl_options']['mwl_settings'] = array( 'redlink_target' => 'none' );
MWL_Settings::reset_cache();
$out = $parser->filter_content( '這個 [[ぜったいに存在しない条目]] 沒有。' );
contains( 'none 模式輸出 span', $out, '<span class="mwl-link mwl-new"' );
absent( 'none 模式不產生連結', $out, '<a class="mwl-link mwl-new"' );

$GLOBALS['mwl_options']['mwl_settings'] = array( 'redlink_target' => 'create' );
MWL_Settings::reset_cache();
$out = $parser->filter_content( '[[ぜったいに存在しない条目]]' );
contains( 'create 模式連到建立頁', $out, 'redlink=1' );

echo "\n=== 6. 重新導向解析 ===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array();
MWL_Settings::reset_cache();
seed( 'wordpress', 'en', true, 'WordPress' );
$out = $parser->filter_content( '[[en:wordpress]]' );
contains( '連到重新導向後的正式條目', $out, 'href="https://en.wikipedia.org/wiki/WordPress"' );
contains( '顯示文字維持原輸入', $out, '>wordpress</a>' );

echo "\n=== 7. 保護與跳脫 ===\n";
$out = $parser->filter_content( '<code>[[初音ミク]]</code> 但這個 [[初音ミク]] 要轉。' );
check( '只轉換一處', substr_count( $out, '<a class="mwl-link' ), 1 );
contains( 'code 內原樣保留', $out, '<code>[[初音ミク]]</code>' );

$out = $parser->filter_content( '\[[初音ミク]] 保持原樣' );
contains( '跳脫後輸出原文', $out, '[[初音ミク]]' );
absent( '跳脫後不產生連結', $out, '<a class' );
absent( '跳脫後反斜線被吃掉', $out, '\[[' );

echo "\n=== 8. 背景模式不打 API ===\n";
$GLOBALS['mwl_http_calls'] = 0;
$GLOBALS['mwl_scheduled']  = array();
$out = $parser->filter_content( '[[完全沒查過的詞彙]]' );
check( '未快取時不同步打 API', $GLOBALS['mwl_http_calls'], 0 );
check( '排入背景佇列', count( $GLOBALS['mwl_scheduled'] ), 0 ); // 佇列在 shutdown 才排程
MWL_Wikipedia::schedule_queue();
check( 'shutdown 後排程一筆 cron', count( $GLOBALS['mwl_scheduled'] ), 1 );
contains( '未知狀態先當作存在輸出', $out, 'mwl-unchecked' );

echo "\n=== 9. 效能：大量詞彙只跑一次解析 ===\n";
$big  = str_repeat( '前面 [[初音ミク]] 中間 [[en:Vocaloid]] 後面。', 200 );
$t0   = microtime( true );
$out  = $parser->filter_content( $big );
$ms   = ( microtime( true ) - $t0 ) * 1000;
check( '400 個詞彙全部轉換', substr_count( $out, '<a class="mwl-link' ), 400 );
echo '  INFO  耗時 ' . round( $ms, 1 ) . " ms\n";

echo "\n=== 10. 沒有 [[ ]] 時提早返回 ===\n";
$plain = '完全沒有中括號的內容。';
check( '原樣返回', $parser->filter_content( $plain ), $plain );

echo "\n=== 11. 即時模式的批次上限 ===\n";
$GLOBALS['mwl_options']['mwl_settings'] = array( 'check_mode' => 'realtime' );
MWL_Settings::reset_cache();
$GLOBALS['mwl_transients'] = array();
reset_batches();

$many = '';
for ( $i = 1; $i <= 150; $i++ ) {
	$many .= '[[Term ' . $i . ']] ';
}
$out = $parser->filter_content( $many );

check( '150 個詞彙只送出 2 批 API', $GLOBALS['mwl_http_calls'], MWL_Wikipedia::REALTIME_MAX_BATCHES );
check( '前兩批各 50 個標題', array( count( $GLOBALS['mwl_http_batches'][0] ), count( $GLOBALS['mwl_http_batches'][1] ) ), array( 50, 50 ) );
check( '超出額度的 50 個仍未確認', substr_count( $out, 'mwl-unchecked' ), 50 );
MWL_Wikipedia::schedule_queue();
check( '超出額度的批次改排背景查詢', count( $GLOBALS['mwl_scheduled'] ), 1 );

echo "\n=== 12. 額度以整個請求為單位，跨語言共用 ===\n";
$GLOBALS['mwl_transients'] = array();
reset_batches();

$mixed = '';
for ( $i = 1; $i <= 60; $i++ ) {
	$mixed .= '[[JaTerm ' . $i . ']] ';
}
for ( $i = 1; $i <= 60; $i++ ) {
	$mixed .= '[[en:EnTerm ' . $i . ']] ';
}
$parser->filter_content( $mixed );
check( '兩個語言合計仍不超過上限', $GLOBALS['mwl_http_calls'], MWL_Wikipedia::REALTIME_MAX_BATCHES );

echo "\n=== 13. 儲存文章時不受前台額度限制 ===\n";
$GLOBALS['mwl_transients'] = array();
reset_batches();

$post               = new WP_Post();
$post->ID           = 1;
$post->post_content = $many;
$parser->warm_post( 1, $post );
check( '儲存時 150 個詞彙全部查完（3 批）', $GLOBALS['mwl_http_calls'], 3 );

$GLOBALS['mwl_options']['mwl_settings'] = array();
MWL_Settings::reset_cache();

echo "\n=== 14. 預設語言選單 ===\n";
$settings = MWL_Settings::instance();

$r = $settings->sanitize( array( 'default_lang' => 'ja' ) );
check( '選單選日文', $r['default_lang'], 'ja' );

$r = $settings->sanitize( array( 'default_lang' => 'zh-tw' ) );
check( '選單選中文繁體', $r['default_lang'], 'zh-tw' );

$r = $settings->sanitize( array( 'default_lang' => '__custom__', 'default_lang_custom' => 'nl' ) );
check( '自訂代碼生效', $r['default_lang'], 'nl' );

$r = $settings->sanitize( array( 'default_lang' => '__custom__', 'default_lang_custom' => 'NL' ) );
check( '自訂代碼轉小寫', $r['default_lang'], 'nl' );

$r = $settings->sanitize( array( 'default_lang' => '__custom__', 'default_lang_custom' => '' ) );
check( '自訂但留空退回 ja', $r['default_lang'], 'ja' );

$r = $settings->sanitize( array( 'default_lang' => '__custom__', 'default_lang_custom' => '<script>x</script>' ) );
check( '自訂代碼不合法退回 ja', $r['default_lang'], 'ja' );

$r = $settings->sanitize( array( 'default_lang' => '__custom__' ) );
check( '哨兵值本身不會被存進設定', $r['default_lang'], 'ja' );

$langs = MWL_Settings::languages();
check( '選單第一個是日文', array_key_first( $langs ), 'ja' );
check( '選單含中文繁體', isset( $langs['zh-tw'] ), true );
check( '選單不含哨兵值', isset( $langs[ MWL_Settings::CUSTOM_LANG ] ), false );

MWL_Settings::reset_cache();

echo "\n========================================\n";
echo "  通過 $pass 項，失敗 $fail 項\n";
echo "========================================\n";
exit( $fail > 0 ? 1 : 0 );
