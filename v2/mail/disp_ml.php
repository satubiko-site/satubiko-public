<?php
// /mail/index.php

// ▼ 必要なら最上部で会員認証を行う ▼
// 例: require __DIR__ . '/../member/_inc/auth.php';
// （実際のファイル構成に合わせてください）
header('Content-Type: text/html; charset=UTF-8');

$dbPath = dirname(__DIR__) . '/mail/yamabiko_ml.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('DB接続失敗: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function format_from($raw) {
  if ($raw === null) $raw = '';
  $raw = trim($raw);
  if ($raw === '') return '';

  // 基本は生の文字列を使う
  $decoded = $raw;

  // MIME エンコードっぽい時だけデコードを試す（失敗しても死なないように）
  if (strpos($raw, '=?') !== false) {
      $tmp = @mb_decode_mimeheader($raw);
      if (is_string($tmp) && $tmp !== '') {
          $decoded = $tmp;
      }
  }

  // "名前 <addr@example.com>" 形式なら、名前とアドレスに分ける
  // 例: "=?ISO-2022-JP?B?GyR..." <foo@example.com>
  if (preg_match('/^(.*)<(.+)>$/u', $decoded, $m)) {
      $name = trim($m[1], "\" \t");
      $addr = trim($m[2]);
      if ($name !== '') {
          return $name . ' <' . $addr . '>';
      } else {
          return $addr;
      }
  }

  // それ以外は、そのまま表示（少なくとも空欄にはしない）
  return $decoded;
}

function maybe_decode_iso2022jp($s) {
  if ($s === null || $s === '') return $s;

  // 1) ESC (0x1B) を含む → ISO-2022-JP と決め打ちでデコード
  if (strpos($s, "\x1B") !== false) {
      // ★ from_encoding を ISO-2022-JP 単独にする
      $decoded = @mb_convert_encoding($s, 'UTF-8', 'ISO-2022-JP');
      if (is_string($decoded) && $decoded !== '') {
          return $decoded;
      }
  }
  // 2) SQLite のダンプなどで ESC が "^[" に化けて格納されている場合
  //    実際の文字列は「^[$B.....^[(B」なので、そのまま探す
  if (strpos($s, '^[$B') !== false || strpos($s, '^[(B') !== false) {
      $tmp = str_replace('^[', "\x1B", $s);
      $decoded = @mb_convert_encoding($tmp, 'UTF-8', 'ISO-2022-JP');
      if (is_string($decoded) && $decoded !== '') {
          return $decoded;
      }
  }
  // 3) 「$B ... (B」だけが残っているパターンを救済
  if (strpos($s, '$B') !== false && strpos($s, '(B') !== false) {
      $tmp = "\x1B" . $s . "\x1B(B";
      $decoded = @mb_convert_encoding($tmp, 'UTF-8', 'ISO-2022-JP');
      if (is_string($decoded) && $decoded !== '') {
          return $decoded;
      }
  }
  // どれにも当てはまらなければ、そのまま返す
  return $s;
}


// 一覧の並び順（新しい順 / 古い順）
$sort = (isset($_GET['sort']) && $_GET['sort'] === 'oldest') ? 'oldest' : 'newest';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 既読メールID一覧（Cookieから取得）
$readIds = [];

// ★ Cookie 名とパスを明示
$cookieName = 'ml_read_ml';          // 新しい名前に変更（衝突回避）
$cookiePath = '/mail';            // このページがあるディレクトリに限定

if (!empty($_COOKIE[$cookieName])) {
    $readIds = explode(',', $_COOKIE[$cookieName]);
}

// ------------------------------------
// 詳細表示
// ------------------------------------
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM ml_messages WHERE id = :id');
    $stmt->execute(array(':id' => $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die('メールが見つかりません。');
    }

    // ★ このメールを既読として Cookie に反映（HTML 出力前に実行）
    if (!in_array($row['id'], $readIds)) {
        $newIds = $readIds;
        $newIds[] = $row['id'];
        setcookie($cookieName, implode(',', $newIds), time() + 60*60*24*365, $cookiePath);
    }
    
    // ★ここから追加：詳細表示用の日付＆From 整形 -----------------
    // 日付 → yyyy-mm-dd hh:mm
    $rawDate  = $row['send_date'] ?? '';
    $dispDate = $rawDate;
    if ($rawDate !== '') {
        $ts = strtotime($rawDate);
        if ($ts !== false) {
            $dispDate = date('Y-m-d H:i', $ts);
        }
    }

    // From → MIME デコード（日本語名を復元）
    // From 表示用（名前がダメでもアドレスだけは出す）
    $dispFrom = format_from($row['from_addr'] ?? '');

    // 同じ ml_no の返信一覧（is_reply = 1）
    $replies = array();
    if (!empty($row['ml_no'])) {
        $stmtRep = $pdo->prepare('
            SELECT *
              FROM ml_messages
             WHERE is_reply = 1
               AND ml_no    = :ml_no
             ORDER BY send_date ASC, id ASC
        ');
        $stmtRep->execute(array(':ml_no' => $row['ml_no']));
        $replies = $stmtRep->fetchAll(PDO::FETCH_ASSOC);
    }
    ?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>メーリングリストログ 詳細</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-size: .9rem; color: #444; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 1rem; line-height: 1.4; }
    header { margin-bottom: 1rem; }
    pre { white-space: pre-wrap; background: #f7f7f7; padding: .8rem; border-radius: 4px; }
    a { color: #0066cc; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .meta { font-size: .9rem; color: #555; margin-bottom: .5rem; }
    .replies { margin-top: 2rem; }
    .reply-item { border-top: 1px solid #ddd; padding-top: .6rem; margin-top: .6rem; font-size: .9rem; }
  </style>
</head>
<body>
  <header>
  <?php $dispSubject = maybe_decode_iso2022jp($row['subject'] ?? ''); ?>
    <p><a href="disp_ml.php">&laquo; 一覧に戻る</a></p>
    <h2><?= h($dispSubject) ?></h2>
    <p class="meta">
      From: <?= h($dispFrom) ?><br>
      Date: <?= h($dispDate) ?><br>
    </p>
  </header>

  <?php

    // ★ 元メールの有無にかかわらず「このメールの本文」だけを表示する
    $bodyRaw = $row['body'] ?? '';

    // ★ デバッグ：特定の ML No の本文をそのまま可視化 
/*    if (!empty($row['ml_no']) && (int)$row['ml_no'] === 30023) {
        echo "<hr><pre>=== DEBUG RAW body (before maybe_decode_iso2022jp) ===\n";
        // 生テキスト（見える範囲だけ）
        echo "TEXT:\n";
        echo htmlspecialchars(
            mb_substr($row['body'], 0, 200, 'UTF-8'),
            ENT_QUOTES,
            'UTF-8'
        );
        echo "\n\nHEX:\n";
        echo bin2hex($row['body']);
        echo "\n=== END DEBUG ===</pre><hr>";
    }
*/
    // ★ 元メールの有無にかかわらず「このメールの本文」だけを表示する
    $bodyRaw = $row['body'] ?? '';
    // iso-2022-jp + quoted-printable っぽい場合の救済デコード
    $bodyRaw = maybe_decode_iso2022jp($bodyRaw);

    // iso-2022-jp + quoted-printable っぽい場合の救済デコード
    $bodyRaw = maybe_decode_iso2022jp($bodyRaw);
    // 一旦そのまま表示用変数に入れる
    $bodyForDisplay = $bodyRaw;
    // ★ やまびこMLの引用行で、先頭が文字化けしてしまうケースの救済
    //    例: 「菅原さん、???....[yamabikomail :30023] 山行届け・下山連絡のお願い」
    //    → 「[yamabikomail :30023] 山行届け・下山連絡のお願い」だけ残す
    $bodyForDisplay = preg_replace_callback(
        '/^.*(\[yamabikomail\s*:\s*\d+\].*)$/m',
        function ($m) {
            // 行のうち、"[yamabikomail :数字] ..." 以降だけを返す
            return $m[1];
        },
        $bodyForDisplay
    );



    // HTMLらしい本文かどうか簡易判定
    $looksLikeHtml = (bool)preg_match(
        '/<\/?(div|span|p|br|html|body|blockquote|a)(\s|>)/i',
        $bodyRaw
    );

    if ($looksLikeHtml) {
        // Gmail が付ける引用部などは落とす（先頭の自分の本文だけ残す想定）
        $bodyForDisplay = preg_replace(
            '~<div class="gmail_extra".*~is',
            '',
            $bodyForDisplay
        );
        $bodyForDisplay = preg_replace(
            '~<blockquote class="gmail_quote".*~is',
            '',
            $bodyForDisplay
        );

        // <br> と </div> を改行に
        $bodyForDisplay = preg_replace('~<br\s*/?>~i', "\n", $bodyForDisplay);
        $bodyForDisplay = preg_replace('~</div\s*>~i', "\n", $bodyForDisplay);

        // 残りのタグを削除
        $bodyForDisplay = strip_tags($bodyForDisplay);

        // HTMLエンティティをデコード（&amp; → & など）
        $bodyForDisplay = html_entity_decode(
            $bodyForDisplay,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    // 末尾の改行・空白を削除（実際のメールにないダラっとした空行を消す）
    $bodyForDisplay = rtrim($bodyForDisplay, "\r\n\t \x0B\0");

    // 表示は最大 50 行までに制限
    $lines = preg_split("/\r\n|\r|\n/", $bodyForDisplay);
    $maxLines = 50;
    if (count($lines) > $maxLines) {
      $bodyForDisplay = implode("\n", array_slice($lines, 0, $maxLines));
      $bodyForDisplay .= "\n\n[この先の本文は省略しています。元のメールを参照してください]";
    }
  ?>

  <section>
    <pre><?= h($bodyForDisplay) ?></pre>
  </section>

</body>
</html>
    <?php
exit;

}

// ------------------------------------
// 一覧表示（元メールのみ）
// ------------------------------------
// 並び順に応じた ORDER BY 句
$orderBy = ($sort === 'oldest')
    ? 'ORDER BY m.uid ASC'
    : 'ORDER BY m.uid DESC';

$sql = '
    SELECT *
    FROM ml_messages m
    WHERE
      m.is_reply = 0
      OR (
           m.is_reply = 1
           AND m.ml_no IS NOT NULL
           AND NOT EXISTS (
                 SELECT 1 FROM ml_messages r
                  WHERE r.ml_no    = m.ml_no
                    AND r.is_reply = 0
           )
           AND m.id = (
                 SELECT MIN(r2.id)
                   FROM ml_messages r2
                  WHERE r2.ml_no    = m.ml_no
                    AND r2.is_reply = 1
           )
      )
  ' . $orderBy . '
    LIMIT 100
';

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 一覧ヘッダの昇順／降順切り替え用
$nextSort = ($sort === 'newest') ? 'oldest' : 'newest';
$sortMark = ($sort === 'newest') ? '▼' : '▲';
?>
<!-- debug ml_read_ml: <?= h(implode(',', $readIds)) ?> -->
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>山びこメーリングリスト送信ログ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 1rem; }
    h1 { font-size: 1.6rem; margin-bottom: .5rem; }
    p.desc { font-size: .9rem; margin-bottom: 1rem; color: #555; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { padding: .4rem .5rem; border-bottom: 1px solid #ddd; vertical-align: top; }
    th { background: #f0f0f0; text-align: left; }
    a { color: #0066cc; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .ml-flag-image { font-size: .8rem; margin-left: .4em; color: #888; }
    .ml-replies { margin-top: .2rem; }
    .ml-reply-item { margin-left: 1.5em; font-size: .9rem; color: #555; }
@media (max-width: 600px) {
  /* テーブル系要素はブロック表示のまま */
  table, thead, tbody, th { display: block; }
  thead { display: none; }

  /* 各行を「縦方向のflexコンテナ」にして順序を制御 */
  tr {
    display: flex;
    flex-direction: column;
    margin-bottom: .7rem;
    border: 1px solid #ddd;
    border-radius: 4px;
  }

  td {
    display: block;
    border: none;
    padding: .3rem .5rem;
  }

  td:before {
    font-weight: bold;
    display: inline-block;
    width: 5em;
  }

  /* ★ 縦の並び順を Date → From → 件名 にするための order 指定 */
  td.col-date   { order: 1; }
  td.col-from   { order: 2; }
  td.col-subject{ order: 3; }

  td.col-date:before    { content: "Date"; }
  td.col-from:before    { content: "From"; }
  td.col-subject:before { content: "件名"; }
  
  /* ★ スマホで Date／From／件名 の行間を詰める */
  td.col-date,
  td.col-from,
  td.col-subject {
    padding: .15rem .5rem;      /* ← 既存 .3rem より少なめ */
    line-height: 1.2;           /* ← 行の高さを詰める */
  }

  /* ラベル部分（Date／From／件名）の行間も詰める */
  td:before {
    line-height: 1.2;
  }
  /* 件名：1行目の書き出し位置で折り返す（スマホ） */
  td.col-subject {
    position: relative;
    padding-left: 5.5em;   /* 「件名」ラベル用のスペースを左側に確保 */
  }

  td.col-subject:before {
    position: absolute;
    left: .5rem;           /* セル内の左端寄りに「件名」ラベルを配置 */
    top: .2rem;
    width: 4.5em;          /* ラベルの幅 */
    display: inline-block;
  }
}
  </style>
</head>
<body>
  <h1>山びこメーリングリスト送信ログ</h1>
  <p class="desc">
    メーリングリストで送信されたメールの一覧は１時間（毎正時）ごとに更新します。<br>
    件名をクリックするとメールの本文を確認できます。
  </p>

  <table>
  <thead>
    <tr>
    <th style="width:8em;">
      <a href="disp_ml.php?sort=<?= h($nextSort) ?>">Date<?= h($sortMark) ?></a>
    </th>
    <th>件名</th>
    <th style="width:18em;">From</th>
    </tr>
  </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          // 日付を yyyy-mm-dd hh:mm 形式に整形
          $rawDate  = $r['send_date'] ?? '';
          $dispDate = $rawDate;
          if ($rawDate !== '') {
              $ts = strtotime($rawDate);
              if ($ts !== false) {
                  $dispDate = date('Y-m-d H:i', $ts);
              }
          }

          // From を MIME デコード（日本語名を復元）
          $dispFrom = format_from($r['from_addr'] ?? '');

          // 件名（ISO-2022-JP の場合を救済）
          $dispSubject = maybe_decode_iso2022jp($r['subject'] ?? '');

          // 画像ありフラグ
          $hasImage = !empty($r['has_image']);

          // 返信一覧の取得
          $replies = [];
          if (!empty($r['ml_no'])) {
              $stmtRep = $pdo->prepare("
                  SELECT *
                    FROM ml_messages
                   WHERE is_reply = 1
                     AND ml_no    = :ml_no
                   ORDER BY send_date ASC, id ASC
              ");
              $stmtRep->execute([':ml_no' => $r['ml_no']]);
              $allReps = $stmtRep->fetchAll(PDO::FETCH_ASSOC);
              foreach ($allReps as $rep) {
                  if ((int)$rep['id'] === (int)$r['id']) continue; // 自分自身は除外
                  $replies[] = $rep;
              }
          }

          // 元メールの既読判定
          $isRead = in_array($r['id'], $readIds);
        ?>
        <tr>
          <td class="col-date"><?= h($dispDate) ?></td>
          <td class="col-subject">
            <!-- 件名（元メール） -->
            <a href="disp_ml.php?id=<?= (int)$r['id'] ?>"<?= $isRead ? '' : ' style="font-weight:bold;"' ?>>
              <?= h(mb_strimwidth($dispSubject, 0, 60, '…', 'UTF-8')) ?>
            </a>
            <?php if ($hasImage): ?>
              <span class="ml-flag-image">[画像あり]</span>
            <?php endif; ?>

            <?php if (!empty($replies)): ?>
              <div class="ml-replies">
                <?php foreach ($replies as $rep): ?>
                  <?php
                    $repIsRead = in_array($rep['id'], $readIds);
                    $rDispFrom = format_from($rep['from_addr'] ?? '');
                    $repDateRaw = $rep['send_date'] ?? '';
                    $repDate = $repDateRaw;
                    if ($repDateRaw !== '') {
                        $ts2 = strtotime($repDateRaw);
                        if ($ts2 !== false) {
                            $repDate = date('Y-m-d H:i', $ts2);
                        }
                    }
                    // 件名（ISO-2022-JP の場合を救済）
                    $repSubjectDecoded = maybe_decode_iso2022jp($rep['subject'] ?? '');
                  ?>
                  <div class="ml-reply-item">
                    ┗ <a href="disp_ml.php?id=<?= (int)$rep['id'] ?>"<?= $repIsRead ? '' : ' style="font-weight:bold;"' ?>>
                        <?= h(mb_strimwidth($repSubjectDecoded, 0, 50, '…', 'UTF-8')) ?>
                       </a>
                    <span>（<?= h($repDate) ?> / <?= h($rDispFrom) ?>）</span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="col-from"><?= h($dispFrom) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
