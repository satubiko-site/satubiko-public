<?php
// 一番最初に（何も出力する前に）
header('Content-Type: text/html; charset=UTF-8');
// 可能なら内部/出力も明示
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
?>
<!doctype html>
<html lang="ja">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-X3R3DEHC1B"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-X3R3DEHC1B');
</script> 
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>山びこＨＰのＤＢ管理ツール</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans JP","Hiragino Kaku Gothic ProN","Yu Gothic UI",sans-serif;font-size:14px;margin:16px;line-height:1.6}
h1{font-size:24px;margin:0 0 8px}
h4{font-size:18px;margin:0 0 8px 16px}
li{font-size:16px;font-weight:600;}
li p{font-size:14px;font-weight:500;}
.card{border:1px solid #e5e5e5;border-radius:10px;padding:12px;margin:10px 0;background:#fff}
label{display:inline-block;margin:4px 0 2px}
input[type=text]{width:100%;max-width:520px;padding:6px 8px;border:1px solid #ccc;border-radius:6px}
.btn{display:inline-flex;align-items:center;gap:.35em;padding:.4em .8em;border:1px solid #ccc;border-radius:8px;background:#fafafa;cursor:pointer}
.badge{display:inline-block;padding:.2em .5em;border:1px solid #ddd;border-radius:9999px;margin-right:4px;background:#f9f9f9}
small{color:#666}
.muted{color:#666;font-size:.92em}
.flex{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.headerbar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px}
</style>
</head>
<body>
<div class="headerbar"><h1>山びこHPのDB管理ツール</h1></div>
<div class="card">

<ol>
  <li>記事データ管理ツール（<a href="articles_admin.php" target="_blank">articles_admin.php</a>）
    <p>データベース（/data/yamabico.db）に格納されている記事の削除や復元を行います。<br>
記事削除の都度tagsテーブルは更新しません。記事削除において残されたタグ情報は、「未使用タグの削除」でまとめて削除します。
    </p>
  </li>
  <li>タグ・エディタ（<a href="tag_editor.php" target="_blank">tag_editor.php</a>）
    <p>データベース（/data/yamabico.db）に格納されている記事にタグ情報を付与（追加、更新、削除）します。<br>
本処理では、対象記事の件名と要約文から候補タグ辞書（<a href="tag_keywords.json">tag_keywords.json</a>）を使って候補タグを抽出します。
    </p>
  </li>
  <li>SQLiteコンソール（Read Only）（<a href="sql_console.php" target="_blank">sql_console.php</a>）
    <p>データベース（/data/yamabico.db）を対象にSQLコマンドの操作を行います。<br>
処理結果は入力画面の下部に表示されますが、指定によりCSVへの出力も可能です。<br> 
本操作は安全のため読込系に限定しています。サイトのDBを更新する場合にはDBをローカル環境にコピーし、シェルからのコマンドラインで変更してください。
    </p>
  </li>
  <li>新しい記事100件表示（<a href="list.php" target="_blank">list.php</a>）
    <p>新しい山行記事を100件表示します<br>
    </p>
  </li>
  <li>一括タグ削除（<a href="delete_tag.php" target="_blank">delete_tag.php</a>）<font color="red">（要注意）</font>
    <p>指定したタグ名のタグをデータベース（/data/yamabico.db）上から一括で削除します。<br>
本処理においては、誤操作による影響が甚大であるため、実行に当たっては必ずDBのバックアップを取得してください。
    </p>
  </li>
  <li>スマホ記事投稿（<a href="https://satubiko.com/smaho/post.php" target="_blank">かんたん山れぽーと</a>）
    <p>スマホから記事を投稿します。（https://satubiko.com/smaho/post.php）
    </p>
  </li>
  <li>DBから記事データをエキスポート（<a href="export_csv.php?download=1">export_csv.php?download=1</a>）
    <p>データベース（/data/yamabico.db）に格納されている記事を/data/articles.csvにエキスポート（バックアップ）します。<br>
本処理では、ソフト削除の記事も対象とし、作成したarticles.csvを自PCにもダウンロードします。<br>
/tool/export_csv.php?sync_tags=1&download=1　でDB上からすべてのタグを消去することもできます。<font color="red">（要注意）</font>
    </p>
  </li>
  <li>CSVデータからDBへインポート（<a href="import_csv_normalize.php">import_csv_normalized.php</a>）（<a href="import_csv_normalize_with_backup.php">with buckup</a>）
    <p>csv形式の記事データ（/data/articles.csv）を入力とし、データベース（/data/yamareco.db）を更新します。<br>
with buckupは、現在のデータベースを/tool/backupにバックアップしてから更新処理を行います。<br>
/tool/import_csv_normalize.php?sync_tags=1　でDB上のタグを消去し、csvで設定したタグに置き換えることができます。<br>
CSVの形式を<a href="csv-data/articles_sample.csv">articles_sample.csv</a>に示します。
    </p>
  </li>
  <li>山びこサイト環境情報リスト（<a href="envcheck.php">envcheck.php</a>）　※データ移行用
    <p>山びこサイトの環境情報を一覧で表示する。</p>
  </li> 
  <li>山行報告記事のエキスポート（<a href="export_sanko.php">export_sanko.php</a>）　※データ移行用
    <p>山行報告の記事データ（/bbs.txt）を入力とし、結果をCSV（sanko_export.csv）に出力します。<br>
bbs.txtは、サイトの/bbs39/bbsdata以下にありますので、それをffftpなどで取り出してください。<br>
    </p>
  </li>
  <li>ヤマレコ記事のエキスポート（<a href="export_yamareco.php">export_yamareco.php</a>）<font color="red">（要注意）</font>　※データ移行用
    <p>山びこに関するヤマレコ記事データをCSV（yamareco_export.csv）に出力します。<br>
本処理は直接ヤマレコにアクセスして情報を取り出しますので、むやみやたらに実行しないでください。
    </p>
  </li>
  <li>ブログ記事のエキスポート（<a href="export_blog.php">export_blog.php</a>）<font color="red"></font>　※データ移行用
    <p>FC2ブログから取り出したデータを指定し、CSV（blog_import_yyyymmdd.csv）に出力します。<br>
入力データとしてFC2管理者画面のツール＞データバックアップで取り出したデータを使用します。
    </p>
  </li>
  <li>新着記事のチェック＆DB更新
    <p>山行記事、ヤマレコ、youtube、スマホ記事について、DBの登録状況をチェックし、該当記事をDBに追加します。<br>
本処理は、トップページが開かれるタイミングで実行されますが、個別に手動で実行したい場合に使用します。<br>
　・<a href="/../ingest/check_smaho.php?dbg=1" target="_blank">/ingest/check_smaho.php?dbg=1<a><br>
　・<a href="/../ingest/check_sanko.php" target="_blank">/ingest/check_sanko.php</a><br>
　・<a href="/../ingest/check_yamareco.php" target="_blank">/ingest/check_yamareco.php</a><br>
　・<a href="/../ingest/check_youtube.php" target="_blank">/ingest/check_youtube.php</a><br>
    </p>
  </li>
  <li>山行報告画像の縮小処理
    <p>通常cron（1日1回）で行う山行報告画像のサイズ縮小を手動で行います。処理完了した対象記事番号はauto_shrink.state.jsonに記録されます。 <br>
本処理結果は、auto_shrink.logで確認することができます。<br>
　・<a href="/../bbs39/auto_shrink_sanko_images.php" target="_blank">/bbs39/auto_shrink_sanko_images.php<a><br>
    </p>
  </li>
  <li><a href="https://analytics.google.com/analytics/web/?utm_source=OGB&utm_medium=app&authuser=0#/a376352429p514697671/reports/intelligenthome" target="_blank">Googleアナリティクス</a>
    <p>ホームページのアクセス状況を確認できます。<br>
本サービスの利用には、事前に利用者のアカウント登録が必要です。<br>
    </p>
  </li>
</ol>
<h4>ツールの実行方法</h4>
<ol>
  <li>ブラウザから実行
    <p>ブラウザから直接URL「https://satubiko.com/｛処理名｝」を入力して実行します。<br>
      また、上記説明にある処理名をクリックすることでも実行できます。<br>
      実行に際しては、ffftpなどで必要なファイルを所定の場所に配置しておく必要があります。
    </p>
  </li>
  <li>shellから実行(CLI)
    <p>Windows PowerShellからコマンドラインでphpを実行します。<br>
  本方法は、XAMPP※が自PCにインストールされていることが前提です。<br>
  （入力例）<br>
  PS C:\Users\USER> & "C:\xampp\php\php.exe" "C:\xampp\htdocs\renew\export_yamareco.php"<br>
  ※XAMPPとは<br>
 <a href="https://www.apachefriends.org/jp/index.html">XAMPP</a>は、自PC上に疑似的なオンライン環境を構築するソフトウェアで、phpを実行することができます。
    </p>
  </li>
</ol>
<h4>テーブル構成(/data/yamabiko.db)</h4>
<ol>
  <li>articles
    <p>id,canonical_id,source,source_id,title,date_ymd,author,summary,url,thumbnail_url,tags,created_at,updated_at,is_deleted,deleted_at</p>
  </li>
  <li>tags
    <p>id,name</p>
  </li>
  <li>article_tags
    <p>article_id,tag_id</p>
  </li>
  <li>seen_items
    <p>canonical_id,first_seen,last_seen</p>
  </li>
  <li>source_state
    <p>source,last_id,last_hash,last_checked_at</p>
  </li>
  <li>smaho_csv (/smaho/data/smaho.csv)
    <p>id,date_ymd,title,url,thumb,author,body</p>
  </li>
</ol>
</div>
<p style="text-align:right; font-size:0.8em;">最終更新日 2025/12/06</p>

</body>
</html>
