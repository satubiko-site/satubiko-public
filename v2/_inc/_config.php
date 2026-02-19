<?php
// 開発中はエラーを出す（本番で 0 に）
ini_set('display_errors','1'); error_reporting(E_ALL);

// 文字コード（BOMなしUTF-8で保存！）
mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=UTF-8');

// 直アクセス防止用フラグ（部品のガードに使う）
define('APP_BOOTSTRAPPED', true);

// BASE_URL 自動推定（http(s)://localhost/renew/ など）
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https':'http';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base   = ($base === '') ? '/' : $base . '/';
define('BASE_URL', $scheme.'://'.$_SERVER['HTTP_HOST'].$base);

// アセットURL生成（CSS/JS/画像はこれ経由に）
function asset(string $path): string {
  return rtrim(BASE_URL,'/').'/'.ltrim($path,'/');
}
