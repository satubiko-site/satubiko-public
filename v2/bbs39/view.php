<?php
// /bbs39/view.php

mb_internal_encoding('UTF-8');

const BBS_DATA_FILE = __DIR__ . '/bbsdata/bbs.txt';
const BBS_DATA_FILE_ALL = __DIR__ . '/bbsdata/bbs_all.txt';
// 画像ディレクトリ（現行 CGI に合わせて調整してください）
const BBS_IMAGE_DIR = 'bbsdata'; // 公開URL

// bbx.txt を読み込んで [no => レコード配列] と [parent_no => 子一覧] を作る
function load_bbx() {
  $items    = [];
  $children = [];

  // まず現行 bbs.txt を読み込む
  $path  = BBS_DATA_FILE;
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
      throw new RuntimeException("bbs.txt を読み込めません: " . $path);
  }

  foreach ($lines as $line) {
      // 旧BBSはSJIS系のことが多いので、必要ならここで変換
      $line = mb_convert_encoding($line, 'UTF-8', 'SJIS-win,CP932,UTF-8');

      $cols = explode('<>', $line);
      // 期待する列数に満たない行はスキップ（古い形式など）
      if (count($cols) < 7) continue;

      $no        = trim($cols[0]);                      // 記事No
      $parent_no = trim($cols[1]);                      // 親No（空なら親）
      $imgSpec   = $cols[2] ?? '';                      // 画像指定
      $dateStr   = trim($cols[3] ?? '');                // 日付時刻
      $author    = trim($cols[4] ?? '');                // S など
      $title     = trim($cols[5] ?? '');                // タイトル
      $body      = trim($cols[6] ?? '');                // 本文
      $bgcolor   = trim($cols[7] ?? '');                // 背景色
      $imgBase   = trim($cols[8] ?? '');                // 画像ベース名
      $host      = trim($cols[9] ?? '');                // ホスト
      $captions  = $cols[10] ?? '';                     // キャプション列

      $item = [
          'no'        => $no,
          'parent_no' => $parent_no,
          'img_spec'  => $imgSpec,
          'date'      => $dateStr,
          'author'    => $author,
          'title'     => $title,
          'body'      => $body,
          'bgcolor'   => $bgcolor,
          'img_base'  => $imgBase,
          'host'      => $host,
          'captions'  => $captions,
      ];

      $items[$no] = $item;

      if ($parent_no !== '') {
          $children[$parent_no][] = $no;
      }
  }

  // 追加読み込み: 過去ログ bbs_all.txt があれば、
  // bbs.txt に無い記事番号だけをマージする
  if (defined('BBS_DATA_FILE_ALL')) {
      $pathAll = BBS_DATA_FILE_ALL;
      if (is_file($pathAll)) {
          $linesAll = @file($pathAll, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          if ($linesAll !== false) {
              foreach ($linesAll as $line) {
                  $line = mb_convert_encoding($line, 'UTF-8', 'SJIS-win,CP932,UTF-8');

                  $cols = explode('<>', $line);
                  if (count($cols) < 7) continue;

                  $no = trim($cols[0]);
                  // no 未設定、またはすでに bbs.txt 側にあるものは飛ばす
                  if ($no === '' || isset($items[$no])) {
                      continue;
                  }

                  $parent_no = trim($cols[1]);
                  $imgSpec   = $cols[2] ?? '';
                  $dateStr   = trim($cols[3] ?? '');
                  $author    = trim($cols[4] ?? '');
                  $title     = trim($cols[5] ?? '');
                  $body      = trim($cols[6] ?? '');
                  $bgcolor   = trim($cols[7] ?? '');
                  $imgBase   = trim($cols[8] ?? '');
                  $host      = trim($cols[9] ?? '');
                  $captions  = $cols[10] ?? '';

                  $item = [
                      'no'        => $no,
                      'parent_no' => $parent_no,
                      'img_spec'  => $imgSpec,
                      'date'      => $dateStr,
                      'author'     => $author,
                      'title'     => $title,
                      'body'      => $body,
                      'bgcolor'   => $bgcolor,
                      'img_base'  => $imgBase,
                      'host'      => $host,
                      'captions'  => $captions,
                  ];

                  $items[$no] = $item;

                  if ($parent_no !== '') {
                      $children[$parent_no][] = $no;
                  }
              }
          }
      }
  }

  return [$items, $children];
}

// 1レコードから画像リストを作る
function build_image_list(array $item) {
  $imgSpec  = $item['img_spec'];
  $captions = array_filter(explode('!:', $item['captions']), fn($s) => $s !== '');

  // 「jpg!」の個数＝画像枚数（最大4枚）
  $maxImages = 4;
  $count = min($maxImages, substr_count($imgSpec, 'jpg!'));

  $images = [];
  for ($i = 0; $i < $count; $i++) {
      // ★ ファイル名は「記事番号-0.jpg」「記事番号-1.jpg」… という規則
      $filename = $item['no'] . '-' . $i . '.jpg';
      $url = BBS_IMAGE_DIR . '/' . $filename;

      $caption = $captions[$i] ?? '';
      $images[] = [
          'url'     => $url,
          'caption' => $caption,
      ];
  }

  return $images;
}

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// -------- メイン処理 --------

$no = isset($_GET['no']) ? trim($_GET['no']) : '';
if ($no === '') {
    http_response_code(400);
    echo 'no パラメータが指定されていません。';
    exit;
}

try {
    [$items, $childrenMap] = load_bbx();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo h($e->getMessage());
    exit;
}

if (!isset($items[$no])) {
    http_response_code(404);
    echo '指定された記事が見つかりません。';
    exit;
}

// 親記事と、その子記事一覧を作る
$parent = $items[$no];
$childNos = $childrenMap[$no] ?? [];
$childItems = [];
foreach ($childNos as $cno) {
    if (isset($items[$cno])) {
        $childItems[] = $items[$cno];
    }
}

// ここから HTML
?>
<!DOCTYPE html>
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($parent['title']) ?> | 山行報告記事</title>
<link rel="stylesheet" href="../base.css">
<link rel="stylesheet" href="../page.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@100..900&display=swap" rel="stylesheet">
</head>
<body>

<main id="sanko-disp">
  <h2>山びこ山行報告</h2>
  <?php
    $all = array_merge([$parent], $childItems);
    foreach ($all as $idx => $item):
      $images = build_image_list($item);
  ?>
    <article class="sanko-article-wrap">
      <header class="sanko-header">
        <div class="sanko-title"><?= h($item['title']) ?></div>
        <div class="sanko-meta">
          <?= h($item['author']) ?> &nbsp; <?= h($item['date']) ?>
        </div>
      </header>

      <?php if ($item['body'] !== ''): ?>
        <div class="sanko-body">
        <?php
          // 旧CGIの本文はすでにHTML整形済みなので、そのまま出力する
          echo $item['body'];
        ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($images)): ?>
        <div class="sanko-photos">
          <?php foreach ($images as $img): ?>
            <figure class="sanko-photo">
              <img src="<?= h($img['url']) ?>" alt="">
              <?php if ($img['caption'] !== ''): ?>
                <figcaption class="sanko-photo-caption"><?= h($img['caption']) ?></figcaption>
              <?php endif; ?>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

</main>

</body>
</html>