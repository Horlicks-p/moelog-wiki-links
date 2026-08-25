<?php
/**
 * Plugin Name:       Moelog Wiki Links
 * Plugin URI:        https://www.moelog.com/
 * Description:       在文章內文使用 [[詞彙]] 語法自動連結到維基百科，並自動檢查條目是否存在；不存在時以 MediaWiki 風格的紅色連結呈現。better-wiki-links 的現代替代品。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Moelog
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       moelog-wiki-links
 *
 * @package MoelogWikiLinks
 */

defined( 'ABSPATH' ) || exit;

define( 'MWL_VERSION', '1.0.0' );
define( 'MWL_FILE', __FILE__ );
define( 'MWL_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWL_URL', plugin_dir_url( __FILE__ ) );

require_once MWL_DIR . 'includes/class-mwl-settings.php';
require_once MWL_DIR . 'includes/class-mwl-wikipedia.php';
require_once MWL_DIR . 'includes/class-mwl-parser.php';
require_once MWL_DIR . 'includes/class-mwl-metabox.php';

/**
 * 外掛啟動點。
 */
function mwl_bootstrap() {
	MWL_Parser::instance()->register();
	MWL_Wikipedia::instance()->register();

	if ( is_admin() ) {
		MWL_Settings::instance()->register();
		MWL_Metabox::instance()->register();
	}
}
add_action( 'plugins_loaded', 'mwl_bootstrap' );

/**
 * 停用時只清掉待跑的背景查詢；快取留著，重新啟用時不必整批重查。
 */
function mwl_deactivate() {
	wp_unschedule_hook( MWL_Wikipedia::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'mwl_deactivate' );

/**
 * 解除安裝時一併移除設定。
 */
function mwl_uninstall() {
	wp_unschedule_hook( MWL_Wikipedia::CRON_HOOK );
	delete_option( MWL_Settings::OPTION );
	MWL_Wikipedia::flush_all_cache();

	// flush_all_cache() 會遞增世代編號，解除安裝時要連這個 option 一起帶走，
	// 否則資料庫裡會留下一筆 mwl_cache_salt。
	delete_option( MWL_Wikipedia::SALT_OPTION );
}
register_uninstall_hook( __FILE__, 'mwl_uninstall' );
