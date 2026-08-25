<?php
/**
 * 維基百科條目存在性查詢與快取。
 *
 * @package MoelogWikiLinks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MWL_Wikipedia
 */
class MWL_Wikipedia {

	/** 單次 API 請求的標題數量上限（未登入帳號的 MediaWiki 限制）。 */
	const BATCH_SIZE = 50;

	/** 即時模式下，單一前台請求最多送出的 API 批次數；超出的改走背景查詢。 */
	const REALTIME_MAX_BATCHES = 2;

	/** 快取鍵前綴。 */
	const CACHE_PREFIX = 'mwl';

	/** 快取世代編號的 option 名稱，遞增即等於全部失效。 */
	const SALT_OPTION = 'mwl_cache_salt';

	/** 背景查詢的 cron hook。 */
	const CRON_HOOK = 'mwl_warm_cache';

	/**
	 * 單例。
	 *
	 * @var MWL_Wikipedia|null
	 */
	private static $instance = null;

	/**
	 * 本次請求中待背景查詢的詞彙，格式為 lang => title[]。
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * 本次請求已經打過的 API 批次數，避免單一頁面拖太久。
	 *
	 * @var int
	 */
	private static $batches_this_request = 0;

	/**
	 * 使用 zh 網域但以路徑指定字形變體的語言代碼。
	 *
	 * @var string[]
	 */
	private static $zh_variants = array( 'zh-tw', 'zh-cn', 'zh-hk', 'zh-mo', 'zh-sg', 'zh-my', 'zh-hans', 'zh-hant' );

	/**
	 * 取得單例。
	 *
	 * @return MWL_Wikipedia
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
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_warm_cache' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'schedule_queue' ), 100 );
	}

	/* --------------------------------------------------------------------- *
	 * 網址
	 * --------------------------------------------------------------------- */

	/**
	 * 語言代碼對應的維基百科主機（子網域）。
	 *
	 * @param string $lang 語言代碼。
	 * @return string
	 */
	public static function host_for( $lang ) {
		$lang = strtolower( $lang );
		return in_array( $lang, self::$zh_variants, true ) ? 'zh' : $lang;
	}

	/**
	 * API 端點。
	 *
	 * @param string $lang 語言代碼。
	 * @return string
	 */
	public static function api_endpoint( $lang ) {
		return 'https://' . self::host_for( $lang ) . '.wikipedia.org/w/api.php';
	}

	/**
	 * 把條目名稱編成網址片段。
	 *
	 * @param string $title 條目名稱。
	 * @return string
	 */
	private static function encode_title( $title ) {
		$encoded = rawurlencode( str_replace( ' ', '_', $title ) );
		// 這幾個字元在維基網址中習慣保持原樣，可讀性較好且伺服器一樣接受。
		return str_replace( array( '%2F', '%3A', '%28', '%29', '%21', '%2C', '%24' ), array( '/', ':', '(', ')', '!', ',', '$' ), $encoded );
	}

	/**
	 * 條目網址。
	 *
	 * @param string $title 條目名稱。
	 * @param string $lang  語言代碼。
	 * @return string
	 */
	public static function article_url( $title, $lang ) {
		$host = self::host_for( $lang );
		$path = in_array( strtolower( $lang ), self::$zh_variants, true ) ? strtolower( $lang ) : 'wiki';
		return 'https://' . $host . '.wikipedia.org/' . $path . '/' . self::encode_title( $title );
	}

	/**
	 * 搜尋結果網址。
	 *
	 * 這裡的 rawurlencode() 是必要的，不是重複編碼：WordPress 的 add_query_arg()
	 * 只會對「原本就在網址 query string 裡」的參數呼叫 urlencode_deep()，新加入的
	 * 值是直接賦值，最後又以 $urlencode = false 呼叫 build_query()。拿掉這層編碼，
	 * 非 ASCII 的條目名稱就會原樣進到網址裡。tests/wp-compat.php 用的是 core 的
	 * 原始實作，test-parser.php 的「網址編碼」區塊會擋住這個回歸。
	 *
	 * @param string $title 搜尋詞。
	 * @param string $lang  語言代碼。
	 * @return string
	 */
	public static function search_url( $title, $lang ) {
		return add_query_arg(
			array(
				'search'   => rawurlencode( $title ),
				'title'    => 'Special:Search',
				'fulltext' => 1,
			),
			'https://' . self::host_for( $lang ) . '.wikipedia.org/w/index.php'
		);
	}

	/**
	 * 條目建立頁網址（MediaWiki 紅連結原生行為）。
	 *
	 * rawurlencode() 的理由同 search_url()。
	 *
	 * @param string $title 條目名稱。
	 * @param string $lang  語言代碼。
	 * @return string
	 */
	public static function create_url( $title, $lang ) {
		return add_query_arg(
			array(
				'title'   => rawurlencode( str_replace( ' ', '_', $title ) ),
				'action'  => 'edit',
				'redlink' => 1,
			),
			'https://' . self::host_for( $lang ) . '.wikipedia.org/w/index.php'
		);
	}

	/* --------------------------------------------------------------------- *
	 * 快取
	 * --------------------------------------------------------------------- */

	/**
	 * 標題正規化，讓大小寫、底線、空白的差異共用同一筆快取。
	 *
	 * @param string $title 條目名稱。
	 * @return string
	 */
	public static function normalize( $title ) {
		$title = trim( preg_replace( '/[\s_]+/u', ' ', (string) $title ) );
		if ( '' === $title ) {
			return '';
		}
		// MediaWiki 的首字母不分大小寫，統一成大寫可以少查一次。
		return function_exists( 'mb_strtoupper' )
			? mb_substr( mb_strtoupper( mb_substr( $title, 0, 1 ) ), 0, 1 ) . mb_substr( $title, 1 )
			: ucfirst( $title );
	}

	/**
	 * 快取世代編號。
	 *
	 * @return int
	 */
	private static function salt() {
		return (int) get_option( self::SALT_OPTION, 1 );
	}

	/**
	 * 產生快取鍵。
	 *
	 * @param string $title 條目名稱（已正規化）。
	 * @param string $lang  語言代碼。
	 * @return string
	 */
	private static function cache_key( $title, $lang ) {
		return self::CACHE_PREFIX . self::salt() . '_' . md5( self::host_for( $lang ) . '|' . $title );
	}

	/**
	 * 讀取單筆快取。
	 *
	 * @param string $title 條目名稱（已正規化）。
	 * @param string $lang  語言代碼。
	 * @return array|false 快取內容，或 false 表示未快取。
	 */
	private static function read_cache( $title, $lang ) {
		$value = get_transient( self::cache_key( $title, $lang ) );
		return is_array( $value ) && isset( $value['e'] ) ? $value : false;
	}

	/**
	 * 寫入單筆快取。
	 *
	 * @param string $title  條目名稱（已正規化）。
	 * @param string $lang   語言代碼。
	 * @param bool   $exists 是否存在。
	 * @param string $target 重新導向解析後的實際條目名稱。
	 */
	private static function write_cache( $title, $lang, $exists, $target ) {
		$days = $exists ? (int) MWL_Settings::get( 'ttl_hit', 30 ) : (int) MWL_Settings::get( 'ttl_miss', 1 );
		set_transient(
			self::cache_key( $title, $lang ),
			array(
				'e' => $exists ? 1 : 0,
				't' => $target,
			),
			max( 1, $days ) * DAY_IN_SECONDS
		);
	}

	/**
	 * 清除所有條目快取。
	 *
	 * @return int 實際從資料表刪掉的筆數（使用外部物件快取時為 0）。
	 */
	public static function flush_all_cache() {
		global $wpdb;

		$deleted = 0;
		if ( ! wp_using_ext_object_cache() ) {
			$like    = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$timeout = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// 遞增世代編號，確保外部物件快取中的舊資料也一併失效。
		update_option( self::SALT_OPTION, self::salt() + 1, false );

		return $deleted;
	}

	/* --------------------------------------------------------------------- *
	 * 查詢
	 * --------------------------------------------------------------------- */

	/**
	 * 查詢一批條目的狀態。
	 *
	 * @param string[] $titles 條目名稱清單（未正規化）。
	 * @param string   $lang   語言代碼。
	 * @param string   $mode        查詢模式：cache（只讀快取）、realtime（缺的就查）、force（全部重查）。
	 * @param int      $max_batches 本次請求最多可送出的 API 批次數，0 表示不限。
	 *                              超出額度的批次會改排背景查詢，避免前台被拖住。
	 * @return array title(正規化) => array{state:string,target:string}
	 */
	public static function lookup( array $titles, $lang, $mode = 'cache', $max_batches = 0 ) {
		$result  = array();
		$pending = array();

		foreach ( $titles as $raw ) {
			$title = self::normalize( $raw );
			if ( '' === $title || isset( $result[ $title ] ) ) {
				continue;
			}

			$cached = 'force' === $mode ? false : self::read_cache( $title, $lang );
			if ( false !== $cached ) {
				$result[ $title ] = array(
					'state'  => $cached['e'] ? 'exists' : 'missing',
					'target' => '' !== (string) $cached['t'] ? $cached['t'] : $title,
				);
				continue;
			}

			$result[ $title ] = array(
				'state'  => 'unknown',
				'target' => $title,
			);
			$pending[]        = $title;
		}

		if ( ! $pending || 'cache' === $mode ) {
			if ( $pending ) {
				self::enqueue( $pending, $lang );
			}
			return $result;
		}

		foreach ( array_chunk( $pending, self::BATCH_SIZE ) as $chunk ) {
			// 額度是以「整個請求」為單位計算的，多語言混用時也不會各自再吃一份。
			if ( $max_batches > 0 && ! self::can_fetch_more( $max_batches ) ) {
				self::enqueue( $chunk, $lang );
				continue;
			}

			$fetched = self::fetch( $chunk, $lang );
			if ( null === $fetched ) {
				// API 失敗：剩下的丟給背景重試，不要卡住這次輸出。
				self::enqueue( $chunk, $lang );
				continue;
			}
			foreach ( $fetched as $title => $row ) {
				$result[ $title ] = $row;
			}
		}

		return $result;
	}

	/**
	 * 實際打 API 並寫入快取。
	 *
	 * @param string[] $titles 已正規化的條目名稱（不超過 BATCH_SIZE 筆）。
	 * @param string   $lang   語言代碼。
	 * @param int      $timeout 逾時秒數。
	 * @return array|null 查詢結果，或 null 表示請求失敗。
	 */
	public static function fetch( array $titles, $lang, $timeout = 4 ) {
		$titles = array_values( array_filter( array_unique( $titles ) ) );
		if ( ! $titles ) {
			return array();
		}

		++self::$batches_this_request;

		$response = wp_remote_post(
			self::api_endpoint( $lang ),
			array(
				'timeout'    => $timeout,
				'user-agent' => self::user_agent(),
				'headers'    => array( 'Accept' => 'application/json' ),
				'body'       => array(
					'action'        => 'query',
					'format'        => 'json',
					'formatversion' => '2',
					'redirects'     => '1',
					'maxlag'        => '5',
					'titles'        => implode( '|', $titles ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || isset( $data['error'] ) || ! isset( $data['query']['pages'] ) ) {
			return null;
		}

		// normalized / redirects 都是 from → to 的對照，串起來才能找到最終條目。
		$chain = array();
		foreach ( array( 'normalized', 'redirects' ) as $section ) {
			if ( empty( $data['query'][ $section ] ) || ! is_array( $data['query'][ $section ] ) ) {
				continue;
			}
			foreach ( $data['query'][ $section ] as $row ) {
				if ( isset( $row['from'], $row['to'] ) ) {
					$chain[ $row['from'] ] = $row['to'];
				}
			}
		}

		$pages = array();
		foreach ( $data['query']['pages'] as $page ) {
			if ( ! isset( $page['title'] ) ) {
				continue;
			}
			// missing 之外，invalid（標題不合法）與 special（特殊頁面）也都沒有真正的條目可連。
			$pages[ $page['title'] ] = empty( $page['missing'] ) && empty( $page['invalid'] ) && empty( $page['special'] );
		}

		$out = array();
		foreach ( $titles as $title ) {
			$final = $title;
			for ( $i = 0; $i < 10 && isset( $chain[ $final ] ); $i++ ) {
				$final = $chain[ $final ];
			}

			$exists = isset( $pages[ $final ] ) ? $pages[ $final ] : false;

			self::write_cache( $title, $lang, $exists, $final );

			$out[ $title ] = array(
				'state'  => $exists ? 'exists' : 'missing',
				'target' => $final,
			);
		}

		return $out;
	}

	/**
	 * 送給 Wikimedia 的 User-Agent，依其 API 禮節政策需可辨識來源。
	 *
	 * @return string
	 */
	private static function user_agent() {
		return sprintf( 'MoelogWikiLinks/%s (+%s) WordPress/%s', MWL_VERSION, home_url( '/' ), get_bloginfo( 'version' ) );
	}

	/* --------------------------------------------------------------------- *
	 * 背景查詢
	 * --------------------------------------------------------------------- */

	/**
	 * 把詞彙排進背景查詢佇列。
	 *
	 * @param string[] $titles 已正規化的條目名稱。
	 * @param string   $lang   語言代碼。
	 */
	public static function enqueue( array $titles, $lang ) {
		if ( 'off' === MWL_Settings::get( 'check_mode' ) ) {
			return;
		}
		if ( ! isset( self::$queue[ $lang ] ) ) {
			self::$queue[ $lang ] = array();
		}
		self::$queue[ $lang ] = array_merge( self::$queue[ $lang ], $titles );
	}

	/**
	 * 請求結束時，把佇列排成 cron 事件。
	 */
	public static function schedule_queue() {
		if ( ! self::$queue ) {
			return;
		}

		foreach ( self::$queue as $lang => $titles ) {
			$titles = array_values( array_unique( $titles ) );
			sort( $titles );

			foreach ( array_chunk( $titles, self::BATCH_SIZE ) as $chunk ) {
				$args = array( (string) $lang, $chunk );
				// wp_schedule_single_event 會自行擋掉短時間內的相同事件。
				if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
					wp_schedule_single_event( time() + 10, self::CRON_HOOK, $args );
				}
			}
		}

		self::$queue = array();
	}

	/**
	 * cron 執行的批次查詢。
	 *
	 * @param string   $lang   語言代碼。
	 * @param string[] $titles 條目名稱。
	 */
	public static function run_warm_cache( $lang, $titles ) {
		if ( ! is_array( $titles ) || ! $titles ) {
			return;
		}
		foreach ( array_chunk( $titles, self::BATCH_SIZE ) as $chunk ) {
			self::fetch( $chunk, (string) $lang, 10 );
		}
	}

	/**
	 * 本次請求是否還能再打 API（即時模式用來設上限）。
	 *
	 * @param int $limit 上限批次數。
	 * @return bool
	 */
	public static function can_fetch_more( $limit = self::REALTIME_MAX_BATCHES ) {
		return self::$batches_this_request < $limit;
	}
}
