<?php
/**
 * [[詞彙]] 的解析與連結輸出。
 *
 * @package MoelogWikiLinks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MWL_Parser
 */
class MWL_Parser {

	/**
	 * 比對 [[條目]]、[[條目|顯示文字]]，前置反斜線代表跳脫。
	 */
	const PATTERN = '/(\\\\?)\[\[[ \t]*([^\[\]\|\r\n]+?)[ \t]*(?:\|[ \t]*([^\[\]\r\n]*?)[ \t]*)?\]\]/u';

	/**
	 * 不進行替換的 HTML 區塊，以及所有 HTML 標籤本身。
	 */
	const PROTECT_PATTERN = '#<(pre|code|kbd|samp|script|style|textarea)\b[^>]*>.*?</\1\s*>|<a\b[^>]*>.*?</a\s*>|<!--.*?-->|<[^>]*>#is';

	/**
	 * 單例。
	 *
	 * @var MWL_Parser|null
	 */
	private static $instance = null;

	/**
	 * 本次渲染中，目前這批詞彙的查詢結果，格式為 lang => title => row。
	 *
	 * @var array
	 */
	private $status = array();

	/**
	 * 被保護起來的 HTML 片段。
	 *
	 * @var array
	 */
	private $shelf = array();

	/**
	 * 取得單例。
	 *
	 * @return MWL_Parser
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
		// 優先權 10：在 do_blocks（9）之後、do_shortcode（11）之前，
		// 這樣動態區塊的輸出也會被處理，而 [[foo]] 不會先被當成跳脫短碼吃掉。
		add_filter( 'the_content', array( $this, 'filter_content' ), 10 );

		if ( MWL_Settings::get( 'apply_excerpt' ) ) {
			add_filter( 'the_excerpt', array( $this, 'filter_content' ), 10 );
		}

		add_action( 'wp_head', array( $this, 'print_styles' ) );
		add_action( 'save_post', array( $this, 'warm_post' ), 20, 2 );
	}

	/* --------------------------------------------------------------------- *
	 * 解析
	 * --------------------------------------------------------------------- */

	/**
	 * 從內容中取出所有 wiki 詞彙。
	 *
	 * @param string $content 內容。
	 * @return array 每筆為 array{lang:string,title:string,label:string}。
	 */
	public static function extract_terms( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[[' ) ) {
			return array();
		}

		$parser  = self::instance();
		$content = $parser->protect( $content );

		$terms = array();
		if ( preg_match_all( self::PATTERN, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( '' !== $m[1] ) {
					continue; // 被跳脫。
				}
				list( $lang, $title ) = self::split_lang( $m[2] );
				if ( '' === $title ) {
					continue;
				}
				$terms[] = array(
					'lang'  => $lang,
					'title' => $title,
					'label' => ( isset( $m[3] ) && '' !== $m[3] ) ? $m[3] : $title,
				);
			}
		}

		$parser->shelf = array();

		return $terms;
	}

	/**
	 * 拆出語言前綴。
	 *
	 * @param string $raw 中括號內的原始字串。
	 * @return array{0:string,1:string} 語言代碼與條目名稱。
	 */
	private static function split_lang( $raw ) {
		$raw     = trim( $raw );
		$default = (string) MWL_Settings::get( 'default_lang', 'ja' );

		if ( preg_match( '/^:?[ \t]*([a-z]{2,3}(?:-[a-z]{2,8})?)[ \t]*:[ \t]*(.+)$/iu', $raw, $m ) ) {
			$code = strtolower( $m[1] );
			if ( in_array( $code, MWL_Settings::allowed_langs(), true ) ) {
				return array( $code, trim( $m[2] ) );
			}
		}

		return array( $default, trim( ltrim( $raw, ':' ) ) );
	}

	/**
	 * 把 HTML 標籤與不該處理的區塊換成佔位符。
	 *
	 * @param string $content 內容。
	 * @return string
	 */
	private function protect( $content ) {
		$this->shelf = array();
		$shelf       = &$this->shelf;

		return (string) preg_replace_callback(
			self::PROTECT_PATTERN,
			static function ( $m ) use ( &$shelf ) {
				$key           = "\x02mwl" . count( $shelf ) . "\x03";
				$shelf[ $key ] = $m[0];
				return $key;
			},
			$content
		);
	}

	/**
	 * 還原被保護的片段。
	 *
	 * @param string $content 內容。
	 * @return string
	 */
	private function restore( $content ) {
		if ( $this->shelf ) {
			$content     = strtr( $content, $this->shelf );
			$this->shelf = array();
		}
		return $content;
	}

	/* --------------------------------------------------------------------- *
	 * 渲染
	 * --------------------------------------------------------------------- */

	/**
	 * the_content 過濾器。
	 *
	 * @param string $content 內容。
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[[' ) ) {
			return $content;
		}

		/**
		 * 是否要處理這段內容。
		 *
		 * @param bool   $enabled 預設為 true。
		 * @param string $content 內容。
		 */
		if ( ! apply_filters( 'mwl_should_process', true, $content ) ) {
			return $content;
		}

		$terms = self::extract_terms( $content );

		// 就算一個詞彙都沒有也要往下跑：內容可能只有 \[[跳脫]] 寫法，
		// 那個反斜線得在這一輪吃掉。
		$this->status = $terms ? $this->resolve( $terms ) : array();

		$content = $this->protect( $content );
		$content = (string) preg_replace_callback( self::PATTERN, array( $this, 'render_match' ), $content );
		$content = $this->restore( $content );

		return $content;
	}

	/**
	 * 依設定的檢查模式查出每個詞彙的狀態。
	 *
	 * @param array $terms 詞彙清單。
	 * @return array lang => title => row。
	 */
	private function resolve( $terms ) {
		$mode = (string) MWL_Settings::get( 'check_mode', 'background' );
		if ( 'off' === $mode ) {
			return array();
		}

		$by_lang = array();
		foreach ( $terms as $term ) {
			$by_lang[ $term['lang'] ][] = $term['title'];
		}

		// 即時模式的批次上限交給 lookup() 逐批把關；在這裡先判斷的話，
		// 單一語言只要一進入 realtime 就會把它所有批次全部送出。
		$realtime = ( 'realtime' === $mode );
		$status   = array();

		foreach ( $by_lang as $lang => $titles ) {
			$status[ $lang ] = $realtime
				? MWL_Wikipedia::lookup( $titles, $lang, 'realtime', MWL_Wikipedia::REALTIME_MAX_BATCHES )
				: MWL_Wikipedia::lookup( $titles, $lang, 'cache' );
		}

		return $status;
	}

	/**
	 * 單一比對結果的輸出。
	 *
	 * @param array $m 比對結果。
	 * @return string
	 */
	private function render_match( $m ) {
		if ( '' !== $m[1] ) {
			// 跳脫：吃掉反斜線，原樣輸出。
			return substr( $m[0], 1 );
		}

		list( $lang, $title ) = self::split_lang( $m[2] );
		if ( '' === $title ) {
			return $m[0];
		}

		$label = ( isset( $m[3] ) && '' !== $m[3] ) ? $m[3] : $title;
		$key   = MWL_Wikipedia::normalize( $title );
		$row   = isset( $this->status[ $lang ][ $key ] )
			? $this->status[ $lang ][ $key ]
			: array(
				'state'  => 'unknown',
				'target' => $title,
			);

		return $this->build_link( $lang, $title, $label, $row );
	}

	/**
	 * 組出連結 HTML。
	 *
	 * @param string $lang  語言代碼。
	 * @param string $title 條目名稱。
	 * @param string $label 顯示文字。
	 * @param array  $row   狀態。
	 * @return string
	 */
	private function build_link( $lang, $title, $label, $row ) {
		$missing = ( 'missing' === $row['state'] );
		$target  = '' !== (string) $row['target'] ? $row['target'] : $title;
		$classes = array( 'mwl-link' );

		if ( $missing ) {
			$classes[] = 'mwl-new';
			$behaviour = (string) MWL_Settings::get( 'redlink_target', 'search' );

			if ( 'none' === $behaviour ) {
				$html = sprintf(
					'<span class="%1$s"%2$s>%3$s</span>',
					esc_attr( implode( ' ', $classes ) ),
					$this->tooltip_attr( $title, true ),
					esc_html( $label )
				);

				/** This filter is documented at the end of this method. */
				return apply_filters( 'mwl_link_html', $html, $lang, $title, $label, $row );
			}

			if ( 'create' === $behaviour ) {
				$url = MWL_Wikipedia::create_url( $title, $lang );
			} elseif ( 'article' === $behaviour ) {
				$url = MWL_Wikipedia::article_url( $title, $lang );
			} else {
				$url = MWL_Wikipedia::search_url( $title, $lang );
			}
		} else {
			if ( 'unknown' === $row['state'] ) {
				$classes[] = 'mwl-unchecked';
			}
			$url = MWL_Wikipedia::article_url( $target, $lang );
		}

		$rel = array();
		if ( MWL_Settings::get( 'nofollow' ) ) {
			$rel[] = 'nofollow';
		}

		$attrs = '';
		if ( MWL_Settings::get( 'new_window' ) ) {
			$attrs = ' target="_blank"';
			$rel[] = 'noopener';
		}
		if ( $rel ) {
			$attrs .= ' rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"';
		}

		$html = sprintf(
			'<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
			esc_attr( implode( ' ', $classes ) ),
			esc_url( $url ),
			$this->tooltip_attr( $missing ? $title : $target, $missing ),
			$attrs,
			esc_html( $label )
		);

		/**
		 * 過濾單一 wiki 連結的 HTML。
		 *
		 * @param string $html  輸出的 HTML。
		 * @param string $lang  語言代碼。
		 * @param string $title 條目名稱。
		 * @param string $label 顯示文字。
		 * @param array  $row   查詢狀態。
		 */
		return apply_filters( 'mwl_link_html', $html, $lang, $title, $label, $row );
	}

	/**
	 * 產生 title 屬性。
	 *
	 * @param string $title   條目名稱。
	 * @param bool   $missing 是否為紅連結。
	 * @return string
	 */
	private function tooltip_attr( $title, $missing ) {
		if ( ! MWL_Settings::get( 'show_tooltip' ) ) {
			return '';
		}

		$text = $missing
			/* translators: %s: 條目名稱 */
			? sprintf( __( '維基百科尚無「%s」這個條目', 'moelog-wiki-links' ), $title )
			/* translators: %s: 條目名稱 */
			: sprintf( __( '維基百科：%s', 'moelog-wiki-links' ), $title );

		return ' title="' . esc_attr( $text ) . '"';
	}

	/* --------------------------------------------------------------------- *
	 * 樣式與預熱
	 * --------------------------------------------------------------------- */

	/**
	 * 輸出紅連結樣式。
	 */
	public function print_styles() {
		$css = 'a.mwl-new,span.mwl-new{color:#d33;}a.mwl-new:hover,a.mwl-new:focus{color:#a00;}span.mwl-new{cursor:help;}';

		/**
		 * 過濾內嵌樣式，回傳空字串即可完全接手樣式。
		 *
		 * @param string $css 預設樣式。
		 */
		$css = apply_filters( 'mwl_inline_css', $css );

		if ( '' === trim( (string) $css ) ) {
			return;
		}

		echo '<style id="mwl-inline-css">' . wp_strip_all_tags( $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * 儲存文章時先把快取熱起來，前台就不會遇到未知狀態。
	 *
	 * @param int      $post_id 文章 ID。
	 * @param  WP_Post $post    文章物件。
	 */
	public function warm_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'off' === MWL_Settings::get( 'check_mode' ) ) {
			return;
		}

		$terms = self::extract_terms( $post->post_content );
		if ( ! $terms ) {
			return;
		}

		$by_lang = array();
		foreach ( array_slice( $terms, 0, 200 ) as $term ) {
			$by_lang[ $term['lang'] ][] = $term['title'];
		}

		foreach ( $by_lang as $lang => $titles ) {
			MWL_Wikipedia::lookup( $titles, $lang, 'realtime' );
		}
	}
}
