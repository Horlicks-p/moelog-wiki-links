<?php
/**
 * 編輯畫面的條目檢查面板。
 *
 * @package MoelogWikiLinks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MWL_Metabox
 */
class MWL_Metabox {

	/**
	 * 單例。
	 *
	 * @var MWL_Metabox|null
	 */
	private static $instance = null;

	/**
	 * 取得單例。
	 *
	 * @return MWL_Metabox
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 掛載 hook。
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'wp_ajax_mwl_recheck', array( $this, 'ajax_recheck' ) );
	}

	/**
	 * 加入側欄面板。
	 *
	 * @param string $post_type 文章類型。
	 */
	public function add_box( $post_type ) {
		$types = get_post_types( array( 'public' => true ) );

		/**
		 * 過濾要顯示檢查面板的文章類型。
		 *
		 * @param string[] $types 文章類型清單。
		 */
		$types = apply_filters( 'mwl_metabox_post_types', $types );

		if ( ! in_array( $post_type, (array) $types, true ) ) {
			return;
		}

		add_meta_box(
			'mwl-check',
			__( 'Wiki 連結檢查', 'moelog-wiki-links' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * 輸出面板內容。
	 *
	 * @param WP_Post $post 文章物件。
	 */
	public function render( $post ) {
		?>
		<div id="mwl-check-body"><?php echo self::report_html( $post->ID, 'cache' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<p>
			<button type="button" class="button" id="mwl-recheck"><?php esc_html_e( '重新檢查（會即時查詢維基百科）', 'moelog-wiki-links' ); ?></button>
			<span class="spinner" id="mwl-spinner" style="float:none;margin:0"></span>
		</p>
		<p class="description"><?php esc_html_e( '檢查的是最後一次儲存的內容，草稿存檔後再按重新檢查最準。', 'moelog-wiki-links' ); ?></p>
		<script>
		( function () {
			var btn = document.getElementById( 'mwl-recheck' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var body    = document.getElementById( 'mwl-check-body' );
				var spinner = document.getElementById( 'mwl-spinner' );
				btn.disabled = true;
				spinner.classList.add( 'is-active' );

				var data = new FormData();
				data.append( 'action', 'mwl_recheck' );
				data.append( 'post_id', '<?php echo (int) $post->ID; ?>' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'mwl_recheck_' . $post->ID ) ); ?>' );

				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						body.innerHTML = ( json && json.data && json.data.html ) ? json.data.html : '<?php echo esc_js( __( '檢查失敗，請稍後再試。', 'moelog-wiki-links' ) ); ?>';
					} )
					.catch( function () {
						body.innerHTML = '<?php echo esc_js( __( '檢查失敗，請稍後再試。', 'moelog-wiki-links' ) ); ?>';
					} )
					.finally( function () {
						btn.disabled = false;
						spinner.classList.remove( 'is-active' );
					} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * AJAX：重新檢查。
	 */
	public function ajax_recheck() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $post_id || ! wp_verify_nonce( $nonce, 'mwl_recheck_' . $post_id ) ) {
			wp_send_json_error( array( 'html' => esc_html__( '安全驗證失敗。', 'moelog-wiki-links' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'html' => esc_html__( '權限不足。', 'moelog-wiki-links' ) ), 403 );
		}

		wp_send_json_success( array( 'html' => self::report_html( $post_id, 'force' ) ) );
	}

	/**
	 * 產生檢查結果的 HTML。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param string $mode    查詢模式，cache 或 force。
	 * @return string
	 */
	private static function report_html( $post_id, $mode = 'cache' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '<p>' . esc_html__( '找不到文章。', 'moelog-wiki-links' ) . '</p>';
		}

		$terms = MWL_Parser::extract_terms( $post->post_content );
		if ( ! $terms ) {
			return '<p>' . esc_html__( '這篇文章沒有 [[ ]] 詞彙。', 'moelog-wiki-links' ) . '</p>';
		}

		$by_lang = array();
		foreach ( $terms as $term ) {
			$by_lang[ $term['lang'] ][] = $term['title'];
		}

		$status = array();
		foreach ( $by_lang as $lang => $titles ) {
			$status[ $lang ] = MWL_Wikipedia::lookup( $titles, $lang, $mode );
		}

		$seen    = array();
		$rows    = array();
		$missing = 0;

		foreach ( $terms as $term ) {
			$key = $term['lang'] . '|' . MWL_Wikipedia::normalize( $term['title'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$norm  = MWL_Wikipedia::normalize( $term['title'] );
			$row   = isset( $status[ $term['lang'] ][ $norm ] ) ? $status[ $term['lang'] ][ $norm ] : array( 'state' => 'unknown' );
			$state = $row['state'];

			if ( 'missing' === $state ) {
				++$missing;
				$icon  = '<span style="color:#d33" aria-hidden="true">✗</span>';
				$note  = esc_html__( '尚無條目', 'moelog-wiki-links' );
				$style = 'color:#d33';
			} elseif ( 'exists' === $state ) {
				$icon  = '<span style="color:#00a32a" aria-hidden="true">✓</span>';
				$note  = '';
				$style = '';
			} else {
				$icon  = '<span style="color:#a7aaad" aria-hidden="true">?</span>';
				$note  = esc_html__( '未檢查', 'moelog-wiki-links' );
				$style = 'color:#787c82';
			}

			$rows[] = sprintf(
				'<li style="margin:0 0 4px">%1$s <a href="%2$s" target="_blank" rel="noopener" style="%3$s">%4$s</a> <span class="description">%5$s%6$s</span></li>',
				$icon,
				esc_url( MWL_Wikipedia::article_url( $term['title'], $term['lang'] ) ),
				esc_attr( $style ),
				esc_html( $term['title'] ),
				esc_html( $term['lang'] ),
				$note ? ' · ' . $note : ''
			);
		}

		$summary = sprintf(
			/* translators: 1: 詞彙總數 2: 紅連結數 */
			esc_html__( '共 %1$d 個詞彙，其中 %2$d 個尚無條目。', 'moelog-wiki-links' ),
			count( $rows ),
			$missing
		);

		return '<p>' . $summary . '</p><ul style="margin:0">' . implode( '', $rows ) . '</ul>';
	}
}
