# satubiko_context.md
本ファイルは、山びこサイトに関するAI依頼時の基本前提情報である。
AIに依頼する際は、必ず本ファイルを参照ファイルとして渡すこと。
本ファイルに記載された制約は、AIの提案よりも常に優先される

---

## 1. システム概要
- サイト名称：札幌山びこ山友会サイト
- URL：https://satubiko.com
- 主構成：PHP + SQLite
- 文字コード：UTF-8
- BOM禁止
- PHPバージョン：8.2

---

## 2. 設計原則（絶対遵守）

### ■ 最小差分原則
- バグ修正は常に最小差分で行う。
- 周辺コードの「ついで改善」「最適化」は禁止。
- 不要なリファクタリングは禁止。

### ■ 既存挙動維持原則
- 既存の入出力仕様を変更しない。
- 既存のログ出力を変更しない。
- 既存の表示文言を勝手に変更しない。

### ■ フォールバック禁止
- 要求されていない機能追加は禁止。
- 自動判定・多段分岐などの冗長な堅牢化は禁止。
- 想定外入力への拡張対応を勝手に追加しない。

---

## 3. 変更禁止事項

- DBスキーマ変更は禁止。
- 状態遷移の追加・削除・意味変更は禁止。
- APIキー管理方法の変更は禁止。
- ファイル構成変更は禁止。
- line_config.php には触れない。

---

## 4. DB関連

- DBはSQLite。
- 原則としてSQL作成依頼はSELECT文のみ。
- UPDATE/DELETEは必ず人間が検証する。

DB配置：
- data/yamabiko.db
- mail/yamabiko_ml.db
- member/trip/trip_manage.db

---

## 5. 主要モジュール

### ingest
run_check_*.php → check_*.php → lib_updates.php
source_state により重複防止。

### trip_manage
状態遷移は固定仕様。勝手に変更しない。

### LINE連携
- line_send.php
- line_webhook.php
- line_config.php（公開禁止）

### member：会員専用ページ
- multiupload：共有書庫
- trip：山行管理表

### smaho：スマホ投稿
- post.php
- data/smaho.csv

### tool：管理ツール
- top.php：総合メニュー
- articles_admin.php：山行記事管理ツール

### 扱う山行記事
- sanko：山行報告
- yamareco：ヤマレコ記事
- youtube：YouTube動画
- smaho：スマホ記事
- blog：FC2ブログ

---

## 6. 公開禁止情報

以下はGitHub公開禁止：
- 本番DB
- mailログDB
- trip管理DB
- line_config.php
- APIキー／トークン
- 会員情報
- 個人メール本文

---

## 7. 出力形式指定

- 修正は unified diff または全文差し替えで提示。
- 変更点は明示する。
- 影響範囲を説明する。

---

環境・設計変更があった場合、本ファイルを必ず更新する。