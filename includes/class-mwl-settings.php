<?php
/**
 * 設定值存取與後台設定頁。
 *
 * @package MoelogWikiLinks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MWL_Settings
 */
class MWL_Settings {

	const OPTION = 'mwl_settings';
	const GROUP  = 'mwl_settings_group';

	/** 語言選單中代表「自訂代碼」的哨兵值。 */
	const CUSTOM_LANG = '__custom__';

	/**
	 * 單例。
	 *
	 * @var MWL_Settings|null
	 */
	private static $instance = null;

	/**
	 * 已讀取的設定快取。
	 *
	 * @var array|null
	 */
	private static $cached = null;

	/**
	 * 取得單例。
	 *
	 * @return MWL_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 預設值。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_lang'   => 'ja',
			'allowed_langs'  => 'ja,zh-tw,en',
			'redlink_target' => 'search',
			'check_mode'     => 'background',
			'new_window'     => 0,
			'nofollow'       => 1,
			'show_tooltip'   => 1,
			'apply_excerpt'  => 0,
			'ttl_hit'        => 30,
			'ttl_miss'       => 1,
		);
	}

	/**
	 * 讀取全部設定（含預設值）。
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cached ) {
			$saved        = get_option( self::OPTION, array() );
			self::$cached = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cached;
	}

	/**
	 * 讀取單一設定值。
	 *
	 * @param string $key     設定鍵名。
	 * @param mixed  $fallback 找不到時的回傳值。
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * 清掉記憶體內的設定快取。
	 */
	public static function reset_cache() {
		self::$cached = null;
	}

	/**
	 * 設定頁下拉選單用的語言清單。
	 *
	 * 不是維基百科的完整語言列表，只列常用的；要用清單外的語言，
	 * 選單最後有「自訂」可以直接填代碼。
	 *
	 * @return array 語言代碼 => 顯示名稱。
	 */
	public static function languages() {
		$languages = array(
			'ja'    => '日本語（日文）',
			'zh-tw' => '中文（繁體）',
			'en'    => 'English（英文）',
		);

		/**
		 * 過濾設定頁的語言選單。
		 *
		 * @param array $languages 語言代碼 => 顯示名稱。
		 */
		return apply_filters( 'mwl_languages', $languages );
	}

	/**
	 * 取得允許作為前綴的語言代碼清單。
	 *
	 * @return string[]
	 */
	public static function allowed_langs() {
		$raw  = (string) self::get( 'allowed_langs' );
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', strtolower( $raw ) ) ) );
		$list = array_values( array_unique( array_merge( $list, array( (string) self::get( 'default_lang' ) ) ) ) );

		/**
		 * 過濾允許的維基語言前綴。
		 *
		 * @param string[] $list 語言代碼清單。
		 */
		return apply_filters( 'mwl_allowed_langs', $list );
	}

	/**
	 * 掛載後台頁面。
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MWL_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_post_mwl_flush_cache', array( $this, 'handle_flush' ) );
	}

	/**
	 * 外掛列表的「設定」捷徑。
	 *
	 * @param string[] $links 既有連結。
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=moelog-wiki-links' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( '設定', 'moelog-wiki-links' ) . '</a>' );
		return $links;
	}

	/**
	 * 註冊設定頁。
	 */
	public function add_page() {
		add_options_page(
			__( 'Wiki Links 設定', 'moelog-wiki-links' ),
			__( 'Wiki Links', 'moelog-wiki-links' ),
			'manage_options',
			'moelog-wiki-links',
			array( $this, 'render_page' )
		);
	}

	/**
	 * 註冊 Settings API。
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * 清洗送出的設定值。
	 *
	 * @param mixed $input 表單輸入。
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$lang = strtolower( sanitize_text_field( isset( $input['default_lang'] ) ? $input['default_lang'] : '' ) );

		// 選單選了「自訂」時，實際代碼在另一個欄位。
		if ( self::CUSTOM_LANG === $lang ) {
			$lang = strtolower( sanitize_text_field( isset( $input['default_lang_custom'] ) ? $input['default_lang_custom'] : '' ) );
		}

		$out['default_lang'] = preg_match( '/^[a-z]{2,3}(-[a-z]{2,8})?$/', $lang ) ? $lang : 'ja';

		$langs = strtolower( sanitize_text_field( isset( $input['allowed_langs'] ) ? $input['allowed_langs'] : '' ) );
		$langs = array_filter(
			(array) preg_split( '/[\s,]+/', $langs ),
			static function ( $code ) {
				return (bool) preg_match( '/^[a-z]{2,3}(-[a-z]{2,8})?$/', $code );
			}
		);

		$out['allowed_langs'] = implode( ',', array_unique( $langs ) );

		$redlink               = isset( $input['redlink_target'] ) ? $input['redlink_target'] : '';
		$out['redlink_target'] = in_array( $redlink, array( 'search', 'create', 'article', 'none' ), true ) ? $redlink : 'search';

		$mode              = isset( $input['check_mode'] ) ? $input['check_mode'] : '';
		$out['check_mode'] = in_array( $mode, array( 'background', 'realtime', 'off' ), true ) ? $mode : 'background';

		foreach ( array( 'new_window', 'nofollow', 'show_tooltip', 'apply_excerpt' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['ttl_hit']  = max( 1, min( 365, (int) ( isset( $input['ttl_hit'] ) ? $input['ttl_hit'] : 30 ) ) );
		$out['ttl_miss'] = max( 1, min( 365, (int) ( isset( $input['ttl_miss'] ) ? $input['ttl_miss'] : 1 ) ) );

		self::reset_cache();

		return $out;
	}

	/**
	 * 處理「清除快取」按鈕。
	 */
	public function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'moelog-wiki-links' ) );
		}
		check_admin_referer( 'mwl_flush_cache' );

		$count = MWL_Wikipedia::flush_all_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'moelog-wiki-links',
					'mwl_flush' => $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * 輸出設定頁。
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Moelog Wiki Links', 'moelog-wiki-links' ); ?></h1>

			<?php if ( isset( $_GET['mwl_flush'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: 被清除的快取筆數 */
						esc_html__( '已清除 %d 筆條目快取。', 'moelog-wiki-links' ),
						(int) $_GET['mwl_flush'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( '在文章內文寫 [[條目]] 即可連到維基百科。支援 [[條目|顯示文字]] 與 [[en:條目]] 這類語言前綴；在前面加一個反斜線可跳脫不轉換。', 'moelog-wiki-links' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mwl_default_lang"><?php esc_html_e( '預設語言', 'moelog-wiki-links' ); ?></label></th>
						<td>
							<?php
							$languages   = self::languages();
							$current     = (string) $o['default_lang'];
							$is_listed   = isset( $languages[ $current ] );
							$select_val  = $is_listed ? $current : self::CUSTOM_LANG;
							$custom_val  = $is_listed ? '' : $current;
							?>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[default_lang]" id="mwl_default_lang">
								<?php foreach ( $languages as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $select_val, $code ); ?>>
										<?php echo esc_html( $name . '　—　' . $code ); ?>
									</option>
								<?php endforeach; ?>
								<option value="<?php echo esc_attr( self::CUSTOM_LANG ); ?>"<?php selected( $select_val, self::CUSTOM_LANG ); ?>>
									<?php esc_html_e( '自訂代碼…', 'moelog-wiki-links' ); ?>
								</option>
							</select>
							<input
								name="<?php echo esc_attr( self::OPTION ); ?>[default_lang_custom]"
								id="mwl_default_lang_custom"
								type="text"
								class="regular-text code"
								placeholder="<?php esc_attr_e( '例如 nl、sv、pl', 'moelog-wiki-links' ); ?>"
								value="<?php echo esc_attr( $custom_val ); ?>"
								style="<?php echo $is_listed ? 'display:none' : ''; ?>">
							<p class="description"><?php esc_html_e( '沒有指定前綴的 [[條目]] 會連到這個語言的維基百科。ACGN 條目以日文維基百科最完整。', 'moelog-wiki-links' ); ?></p>
							<script>
							( function () {
								var sel = document.getElementById( 'mwl_default_lang' );
								var txt = document.getElementById( 'mwl_default_lang_custom' );
								if ( ! sel || ! txt ) { return; }
								sel.addEventListener( 'change', function () {
									var custom = ( sel.value === '<?php echo esc_js( self::CUSTOM_LANG ); ?>' );
									txt.style.display = custom ? '' : 'none';
									if ( custom ) { txt.focus(); }
								} );
							}() );
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mwl_allowed_langs"><?php esc_html_e( '允許的語言前綴', 'moelog-wiki-links' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[allowed_langs]" id="mwl_allowed_langs" type="text" class="large-text code" value="<?php echo esc_attr( $o['allowed_langs'] ); ?>">
							<p class="description"><?php esc_html_e( '以逗號分隔。只有清單內的代碼才會被當成語言前綴，其餘含冒號的字串仍視為條目名稱的一部分。', 'moelog-wiki-links' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '條目不存在時', 'moelog-wiki-links' ); ?></th>
						<td>
							<fieldset>
								<?php
								$targets = array(
									'search'  => __( '紅色連結，連到維基百科搜尋結果（推薦）', 'moelog-wiki-links' ),
									'create'  => __( '紅色連結，連到條目建立頁（最接近 MediaWiki 原生行為）', 'moelog-wiki-links' ),
									'article' => __( '紅色連結，仍連到條目網址（讀者會看到 404 頁）', 'moelog-wiki-links' ),
									'none'    => __( '不加連結，只輸出紅色文字', 'moelog-wiki-links' ),
								);
								foreach ( $targets as $value => $label ) :
									?>
									<label style="display:block;margin-bottom:4px">
										<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[redlink_target]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $o['redlink_target'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '存在性檢查', 'moelog-wiki-links' ); ?></th>
						<td>
							<fieldset>
								<?php
								$modes = array(
									'background' => __( '背景檢查（推薦）：前台只讀快取，未知的詞彙排入背景批次查詢，不拖慢頁面', 'moelog-wiki-links' ),
									'realtime'   => __( '即時檢查：前台遇到沒快取的詞彙就直接查 API，首次載入會變慢', 'moelog-wiki-links' ),
									'off'        => __( '關閉：一律視為條目存在，完全不查 API', 'moelog-wiki-links' ),
								);
								foreach ( $modes as $value => $label ) :
									?>
									<label style="display:block;margin-bottom:4px">
										<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[check_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $o['check_mode'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( '不論哪種模式，儲存文章時都會立刻查一次，所以文章發佈後前台通常已經有快取。', 'moelog-wiki-links' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '連結行為', 'moelog-wiki-links' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[new_window]" value="1" <?php checked( $o['new_window'], 1 ); ?>> <?php esc_html_e( '在新視窗開啟', 'moelog-wiki-links' ); ?></label>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[nofollow]" value="1" <?php checked( $o['nofollow'], 1 ); ?>> <?php esc_html_e( '加上 rel="nofollow"', 'moelog-wiki-links' ); ?></label>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_tooltip]" value="1" <?php checked( $o['show_tooltip'], 1 ); ?>> <?php esc_html_e( '加上 title 提示文字', 'moelog-wiki-links' ); ?></label>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[apply_excerpt]" value="1" <?php checked( $o['apply_excerpt'], 1 ); ?>> <?php esc_html_e( '摘要（the_excerpt）也套用', 'moelog-wiki-links' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '快取天數', 'moelog-wiki-links' ); ?></th>
						<td>
							<label><?php esc_html_e( '條目存在', 'moelog-wiki-links' ); ?>
								<input name="<?php echo esc_attr( self::OPTION ); ?>[ttl_hit]" type="number" min="1" max="365" class="small-text" value="<?php echo esc_attr( $o['ttl_hit'] ); ?>">
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( '條目不存在', 'moelog-wiki-links' ); ?>
								<input name="<?php echo esc_attr( self::OPTION ); ?>[ttl_miss]" type="number" min="1" max="365" class="small-text" value="<?php echo esc_attr( $o['ttl_miss'] ); ?>">
							</label>
							<p class="description"><?php esc_html_e( '不存在的條目建議設短一點，之後被人建立時才會較快轉成正常連結。', 'moelog-wiki-links' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( '快取', 'moelog-wiki-links' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mwl_flush_cache">
				<?php wp_nonce_field( 'mwl_flush_cache' ); ?>
				<?php submit_button( __( '清除全部條目快取', 'moelog-wiki-links' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
