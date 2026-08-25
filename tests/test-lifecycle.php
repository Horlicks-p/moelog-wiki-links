<?php
/**
 * 外掛生命週期的離線測試：確認停用與解除安裝會清掉所有背景事件。
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mwl_unscheduled_hooks'] = array();
$GLOBALS['mwl_deleted_options']   = array();

function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/moelog-wiki-links/'; }
function plugin_basename( $file ) { return 'moelog-wiki-links/moelog-wiki-links.php'; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_deactivation_hook( $file, $callback ) { $GLOBALS['mwl_deactivation_callback'] = $callback; }
function register_uninstall_hook( $file, $callback ) { $GLOBALS['mwl_uninstall_callback'] = $callback; }
function wp_unschedule_hook( $hook ) { $GLOBALS['mwl_unscheduled_hooks'][] = $hook; return 1; }
function delete_option( $option ) { $GLOBALS['mwl_deleted_options'][] = $option; return true; }
function get_option( $option, $default = false ) { return $default; }
function update_option( $option, $value, $autoload = null ) { return true; }
function wp_using_ext_object_cache() { return true; }

require dirname( __DIR__ ) . '/moelog-wiki-links.php';

$pass = 0;
$fail = 0;

function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  PASS  $label\n";
		return;
	}
	++$fail;
	echo "  FAIL  $label\n";
}

echo "\n=== 停用 ===\n";
call_user_func( $GLOBALS['mwl_deactivation_callback'] );
check( '停用時清除所有帶參數的背景事件', $GLOBALS['mwl_unscheduled_hooks'], array( MWL_Wikipedia::CRON_HOOK ) );

echo "\n=== 解除安裝 ===\n";
$GLOBALS['mwl_unscheduled_hooks'] = array();
call_user_func( $GLOBALS['mwl_uninstall_callback'] );
check( '解除安裝時也清除所有背景事件', $GLOBALS['mwl_unscheduled_hooks'], array( MWL_Wikipedia::CRON_HOOK ) );
check(
	'解除安裝時刪除設定與快取世代 option',
	$GLOBALS['mwl_deleted_options'],
	array( MWL_Settings::OPTION, MWL_Wikipedia::SALT_OPTION )
);

echo "\n========================================\n";
echo "  通過 $pass 項，失敗 $fail 項\n";
echo "========================================\n";
exit( $fail > 0 ? 1 : 0 );
