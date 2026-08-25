<?php
/**
 * 測試用的 WordPress 相容層。
 *
 * 這裡的 add_query_arg / build_query / _http_build_query / urlencode_deep /
 * map_deep / wp_parse_str 是直接從 WordPress core（wp-includes/functions.php
 * 與 wp-includes/formatting.php）複製過來的實作，不是簡化的 stub。
 *
 * 理由：add_query_arg() 對「新加入的參數值」到底編不編碼，是這個外掛正確與否
 * 的關鍵。答案是「不編碼」——第 1188 行的 urlencode_deep() 只作用在從既有網址
 * 解析出來的參數（core 自己的註解：This re-URL-encodes things that were already
 * in the query string），新加入的值直接賦值，最後 build_query() 又以
 * $urlencode = false 呼叫 _http_build_query()。用簡化 stub 會測出相反的結論。
 *
 * @package MoelogWikiLinks
 */

if ( ! function_exists( 'map_deep' ) ) {
	/**
	 * WP core: wp-includes/formatting.php
	 *
	 * @param mixed    $value    值。
	 * @param callable $callback 回呼。
	 * @return mixed
	 */
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			$object_vars = get_object_vars( $value );
			foreach ( $object_vars as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'urlencode_deep' ) ) {
	/**
	 * WP core: wp-includes/formatting.php
	 *
	 * @param mixed $value 值。
	 * @return mixed
	 */
	function urlencode_deep( $value ) {
		return map_deep( $value, 'urlencode' );
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	/**
	 * WP core: wp-includes/formatting.php
	 *
	 * @param string $input_string 待解析字串。
	 * @param array  $result       解析結果。
	 */
	function wp_parse_str( $input_string, &$result ) {
		parse_str( (string) $input_string, $result );
		$result = apply_filters( 'wp_parse_str', $result );
	}
}

if ( ! function_exists( '_http_build_query' ) ) {
	/**
	 * WP core: wp-includes/functions.php
	 *
	 * @param array|object $data      資料。
	 * @param string|null  $prefix    數字索引前綴。
	 * @param string|null  $sep       分隔符。
	 * @param string       $key       鍵名前綴。
	 * @param bool         $urlencode 是否編碼。
	 * @return string
	 */
	function _http_build_query( $data, $prefix = null, $sep = null, $key = '', $urlencode = true ) {
		$ret = array();

		foreach ( (array) $data as $k => $v ) {
			if ( $urlencode ) {
				$k = urlencode( $k );
			}

			if ( is_int( $k ) && null !== $prefix ) {
				$k = $prefix . $k;
			}

			if ( ! empty( $key ) ) {
				$k = $key . '%5B' . $k . '%5D';
			}

			if ( null === $v ) {
				continue;
			} elseif ( false === $v ) {
				$v = '0';
			}

			if ( is_array( $v ) || is_object( $v ) ) {
				array_push( $ret, _http_build_query( $v, '', $sep, $k, $urlencode ) );
			} elseif ( $urlencode ) {
				array_push( $ret, $k . '=' . urlencode( $v ) );
			} else {
				array_push( $ret, $k . '=' . $v );
			}
		}

		if ( null === $sep ) {
			$sep = ini_get( 'arg_separator.output' );
		}

		return implode( $sep, $ret );
	}
}

if ( ! function_exists( 'build_query' ) ) {
	/**
	 * WP core: wp-includes/functions.php
	 *
	 * @param array $data 資料。
	 * @return string
	 */
	function build_query( $data ) {
		return _http_build_query( $data, null, '&', '', false );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * WP core: wp-includes/functions.php
	 *
	 * @param mixed ...$args 參數。
	 * @return string
	 */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			if ( count( $args ) < 2 || false === $args[1] ) {
				$uri = $_SERVER['REQUEST_URI'];
			} else {
				$uri = $args[1];
			}
		} else {
			if ( count( $args ) < 3 || false === $args[2] ) {
				$uri = $_SERVER['REQUEST_URI'];
			} else {
				$uri = $args[2];
			}
		}

		$frag = strstr( $uri, '#' );
		if ( $frag ) {
			$uri = substr( $uri, 0, -strlen( $frag ) );
		} else {
			$frag = '';
		}

		if ( 0 === stripos( $uri, 'http://' ) ) {
			$protocol = 'http://';
			$uri      = substr( $uri, 7 );
		} elseif ( 0 === stripos( $uri, 'https://' ) ) {
			$protocol = 'https://';
			$uri      = substr( $uri, 8 );
		} else {
			$protocol = '';
		}

		if ( false !== strpos( $uri, '?' ) ) {
			list( $base, $query ) = explode( '?', $uri, 2 );
			$base                .= '?';
		} elseif ( $protocol || false === strpos( $uri, '=' ) ) {
			$base  = $uri . '?';
			$query = '';
		} else {
			$base  = '';
			$query = $uri;
		}

		wp_parse_str( $query, $qs );
		$qs = urlencode_deep( $qs ); // 只重新編碼「原本就在 query string 裡」的參數。
		if ( is_array( $args[0] ) ) {
			foreach ( $args[0] as $k => $v ) {
				$qs[ $k ] = $v; // 新加入的值不經過編碼。
			}
		} else {
			$qs[ $args[0] ] = $args[1];
		}

		foreach ( $qs as $k => $v ) {
			if ( false === $v ) {
				unset( $qs[ $k ] );
			}
		}

		$ret = build_query( $qs );
		$ret = trim( $ret, '?' );
		$ret = preg_replace( '#=(&|$)#', '$1', $ret );
		$ret = $protocol . $base . $ret . $frag;
		$ret = rtrim( $ret, '?' );
		$ret = str_replace( '?#', '#', $ret );
		return $ret;
	}
}
