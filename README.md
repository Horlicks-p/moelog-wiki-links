# Moelog Wiki Links

在 WordPress 文章內文寫 `[[詞彙]]`，自動連到維基百科，並在連結之前先確認條目存在；不存在時輸出 MediaWiki 風格的紅色連結。

已停止維護的 **better-wiki-links** 的替代品。

## 語法

| 寫法 | 結果 |
| --- | --- |
| `[[初音ミク]]` | 連到預設語言維基百科的「初音ミク」 |
| `[[初音ミク\|ミクさん]]` | 同上，但顯示文字為「ミクさん」 |
| `[[en:Vocaloid]]` | 連到英文維基百科的 Vocaloid |
| `[[zh-tw:輕小說]]` | 連到 `zh.wikipedia.org/zh-tw/輕小說` |
| `\[[初音ミク]]` | 跳脫，原樣輸出不轉換 |

`<pre>`、`<code>`、`<kbd>`、`<samp>`、`<script>`、`<style>`、`<textarea>` 內、既有 `<a>` 連結內、以及 HTML 標籤屬性中的 `[[ ]]` 都不處理。

## 預設語言

設定頁的「預設語言」是下拉選單，列出日文、中文（繁體）、English，最後一項「自訂代碼…」選中後會展開文字框，可填任何維基百科語言代碼。選單內容可用 `mwl_languages` 過濾器增減。

`zh-tw` 這類中文變體會走 `zh.wikipedia.org` 並以變體路徑輸出（`https://zh.wikipedia.org/zh-tw/…`）。

## 檔案結構

```
moelog-wiki-links.php          外掛進入點、常數、啟用／解除安裝
includes/
  class-mwl-settings.php       設定值存取與「設定 → Wiki Links」頁
  class-mwl-wikipedia.php      維基百科 API 查詢、網址組裝、transient 快取
  class-mwl-parser.php         the_content 過濾、[[ ]] 解析與連結輸出
  class-mwl-metabox.php        編輯畫面的「Wiki 連結檢查」面板
tests/
  wp-compat.php                從 WP core 複製的 add_query_arg 等實作（非簡化 stub）
  test-parser.php              解析／輸出／網址編碼／批次上限的離線測試
  test-api-parsing.php         用真實 API 回應驗證解析邏輯
  test-settings-render.php     設定頁輸出的離線 smoke test
  test-lifecycle.php           停用／解除安裝與 cron 清理測試
  fixtures/                    從 ja / en 維基百科擷取的實際回應
```

## 存在性檢查

三種模式，在設定頁選擇：

- **background（預設）** — 前台只讀快取，讀不到的詞彙排進 WP-Cron 背景批次查詢，該次先以一般連結輸出，下次瀏覽就正確。前台不發出任何外部請求。
- **realtime** — 前台遇到未快取的詞彙就同步查 API，首次載入會慢一些，但立刻正確。單次請求最多打 `MWL_Wikipedia::REALTIME_MAX_BATCHES`（2）批，額度是**整個請求**共用的：逐批把關，多語言混用也不會各自再吃一份，超出的自動改走背景查詢。儲存文章與編輯面板的即時重查也共用同一上限，避免請求長時間卡住。
- **off** — 完全不查，一律視為條目存在。

啟用存在性檢查時，**儲存文章會在批次上限內立刻預熱快取**，超出的詞彙改排背景查詢，所以文章發佈後前台通常已經有快取。

查詢一律走批次：一個 HTTP 請求最多帶 50 個標題（MediaWiki 對未登入用戶的上限），並帶 `redirects=1` 跟隨重新導向，所以 `[[en:wordpress]]` 會連到正式條目 `WordPress`。

快取以 transient 儲存，鍵值含一個世代編號（`mwl_cache_salt` option），設定頁的「清除全部條目快取」會遞增它，因此使用外部物件快取（Redis／Memcached）的站台也能正確失效。

## 執行測試

不需要 WordPress，也不需要連外網：

```bash
php tests/test-parser.php
php tests/test-api-parsing.php
php tests/test-settings-render.php
php tests/test-lifecycle.php
```

`test-api-parsing.php` 使用 `tests/fixtures/` 裡從維基百科實際擷取的回應，涵蓋重新導向、標題正規化、`missing`、`invalid`、`special` 與 API 錯誤等情況。

`tests/wp-compat.php` 裡的 `add_query_arg()` / `build_query()` / `_http_build_query()` 是從 WP core **原封不動複製**的，不是簡化 stub。原因是「add_query_arg 對新加入的參數值編不編碼」直接決定 `search_url()` / `create_url()` 該不該自己呼叫 `rawurlencode()`——答案是不編碼，所以那層 `rawurlencode()` 是必要的。用手寫的近似 stub 會測出相反結論，`=== 1b. 網址編碼` 區塊就是用來擋這個回歸的。

## Hooks

| Hook | 用途 |
| --- | --- |
| `mwl_should_process` | 過濾是否處理某段內容 |
| `mwl_link_html` | 過濾單一連結的 HTML |
| `mwl_allowed_langs` | 過濾允許的語言前綴 |
| `mwl_inline_css` | 過濾內嵌樣式；回傳空字串即可完全自行接手 |
| `mwl_metabox_post_types` | 過濾要顯示檢查面板的文章類型 |
| `mwl_languages` | 過濾設定頁「預設語言」下拉選單的選項 |

### 給 llms.txt 之類的機器消費端

紅連結預設會連到維基百科的搜尋頁，對讀者合理，但對 LLM 是誤導（看起來像來源，其實是搜尋結果）。要在產生 Markdown 期間把紅連結降級成純文字：

```php
$demote = static function ( $html, $lang, $title, $label, $row ) {
    return ( isset( $row['state'] ) && 'missing' === $row['state'] ) ? esc_html( $label ) : $html;
};
add_filter( 'mwl_link_html', $demote, 10, 5 );
try {
    $content = apply_filters( 'the_content', $post->post_content );
} finally {
    remove_filter( 'mwl_link_html', $demote, 10 );
}
```

存在的條目仍保留真實維基百科網址，那對 LLM 是有價值的資訊。

## 樣式

紅連結的 class 是 `mwl-new`，一般連結是 `mwl-link`，尚未檢查過的多帶一個 `mwl-unchecked`。預設樣式只有幾行內嵌 CSS：

```css
a.mwl-new, span.mwl-new { color: #d33; }
a.mwl-new:hover, a.mwl-new:focus { color: #a00; }
```

要自訂就用 `mwl_inline_css` 過濾器回傳空字串，改由佈景主題的 CSS 接手。

## 授權

GPL-2.0-or-later
