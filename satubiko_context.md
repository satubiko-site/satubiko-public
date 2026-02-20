# 基本前提情報

## システム概要
- サイト名称：札幌山びこ山友会サイト
- サイトURL：https://satubiko.com

## 環境固定事項（変更しない）
- PHP 8.2
- SQLite
- UTF-8
- BOM禁止

## 設計原則（絶対）
- 最小差分のみ
- 周辺改善禁止（ついで改善・最適化禁止）
- フォールバック禁止（自動判定・多段分岐などの冗長化禁止）
- 既存の入出力・挙動・ログを変えない

## ルート構造（簡潔）
/v2/
- ingest/
- member/
  - trip/
- smaho/
- tool/
- data/

## DB配置（公開禁止）
- data/yamabiko.db
- mail/yamabiko_ml.db
- member/trip/trip_manage.db

## ingest（入口と本体）
- run_check_*.php → check_*.php → lib_updates.php
- source_state により重複防止

## trip_manage（重要事項）
- 状態遷移は固定（勝手に増減・意味変更しない）

## LINE関連
- line_send.php
- line_webhook.php
- ※ line_config.php（キー類含む）は公開禁止

## 外部API
- ヤマレコ
- YouTube

## AI利用時の必須遵守事項（毎回適用）
### 絶対ルール
- バグ改修は常に最小差分。周辺コードの「ついで改善」「最適化」は禁止。
- フォールバックや冗長な堅牢化（自動判定・多段分岐など）を入れない。
- 既存の入出力・挙動・ログを変えない。
- 変更点は unified diff で提示するか、差し替え用の全文を提示する。

## 変更禁止事項

- DBスキーマの変更は禁止
- 状態遷移の追加・削除・意味変更は禁止
- APIキー管理方法の変更は禁止
- 既存のファイル分割構造の変更は禁止


