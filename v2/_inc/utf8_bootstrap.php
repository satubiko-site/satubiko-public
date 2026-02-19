<?php
// UTF-8 を強制（BOMなし保存前提）
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// 既定文字コード（CGI でも効くことが多い）
@ini_set('default_charset', 'UTF-8');

// mbstring の内部設定（存在チェック付き）
if (function_exists('mb_internal_encoding'))  @mb_internal_encoding('UTF-8');
if (function_exists('mb_http_output'))        @mb_http_output('UTF-8');

// 必要なら出力バッファ開始（早期出力でヘッダーが送れないのを防ぐ）
if (!ob_get_level()) { @ob_start(); }

// ---- PHP 8 関数のポリフィル（7.4/5.x 互換）----
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}
