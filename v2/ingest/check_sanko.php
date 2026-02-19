<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib_updates.php'; // $pdo, insert_articles()

const SOURCE        = 'sanko';
const BBS_TXT       = __DIR__ . '/../bbs39/bbsdata/bbs.txt'; // 位置は環境に合わせて
const PAGE_URL_BASE = '/bbs39/bbs39.cgi?mode=disp&num='; // 本番に合わせて
const THUMB_URL_TPL = '/bbs39/bbsdata/%d-0.jpg';             // 〃
const DRY_RUN = false;
const ENABLE_MAIL_ON_INSERT = true;

mb_internal_encoding('UTF-8');
mb_detect_order(['UTF-8','SJIS-win','EUC-JP','ISO-2022-JP','ASCII']);

function to_utf8(?string $s): string {
    if ($s === null) return '';
    // 既にUTF-8か判定して、違えばUTF-8に変換
    $enc = mb_detect_encoding($s, ['UTF-8','SJIS-win','EUC-JP','ISO-2022-JP','ASCII'], true);
    if ($enc && $enc !== 'UTF-8') {
        $s = mb_convert_encoding($s, 'UTF-8', $enc);
    }
    // 制御文字などを軽く整形（任意）
    return trim(str_replace(["\r\n","\r"], "\n", $s));
}

// ---- 取り出し（bbs.txt 1行 = 1記事、区切り "<>"） ----
// 仕様：親記事NO, 子記事NO, 画像情報, 投稿日時, 投稿者, 件名, 本文, ...（以降は無視）
// 子記事NOが空の行のみ対象（＝親記事）
if (!is_readable(BBS_TXT)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'bbs.txt not readable: '.BBS_TXT], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // DB接続
    $pdo = require __DIR__ . '/pdo_bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$fp = fopen(BBS_TXT, 'r');
if ($fp === false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'bbs.txt open failed: '.BBS_TXT], JSON_UNESCAPED_UNICODE);
    exit;
}

$allItems = [];
while (($line = fgets($fp)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;

    // "<>" 区切り
    $cols = explode('<>', $line);

    // 最低限：親ID(0), 子ID(1), 画像情報(2), 日時(3), 投稿者(4), 件名(5), 本文(6)
    // 子IDが空文字のみ対象
    $parent = to_utf8($cols[0] ?? '');
    $child  = to_utf8($cols[1] ?? '');
    if ($parent === '' || $child !== '') continue;
    
    $nid = (int)$parent;
    if ($nid <= 0) continue;
    
    $dateStr = to_utf8($cols[3] ?? '');      // 例: "2014年10月28日(火)  8:56"
    $author  = to_utf8($cols[4] ?? '');
    $title   = to_utf8($cols[5] ?? '');
    $body    = to_utf8($cols[6] ?? '');
    
    // ★ここで必ず YYYY-MM-DD を作る
    if ($dateStr !== '' && preg_match('/(\d{4}).*?(\d{1,2}).*?(\d{1,2})/u', $dateStr, $m)) {
        $date_ymd = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    } else {
        $date_ymd = date('Y-m-d');
    }
    
    // URL / サムネ生成（必要に応じて定数側を本番URLに）
    $url   = PAGE_URL_BASE . $nid;
    $thumb = sprintf(THUMB_URL_TPL, $nid);
    
    $allItems[] = [
        '_nid'         => $nid,
        'source'        => SOURCE,
        'canonical_id'  => SOURCE . ':' . $nid,
        'date_ymd'      => $date_ymd,  // ← これでOK
        'title'         => $title,
        'author'        => $author,
        'summary'       => $body,
        'url'           => $url,
        'thumbnail_url' => $thumb,
    ];
}
fclose($fp);

// ---- 新着抽出（共通） ----
$sth = $pdo->prepare("
    SELECT MAX(CAST(SUBSTR(canonical_id, INSTR(canonical_id, ':')+1) AS INTEGER))
      FROM articles WHERE source=?
");
$sth->execute([SOURCE]);
$dbMax = (int)($sth->fetchColumn() ?? 0);

$newItems = [];
$insertedMaxId = $dbMax;
foreach ($allItems as $it) {
    if ($it['_nid'] > $dbMax) {
        $newItems[] = $it;
        if ($it['_nid'] > $insertedMaxId) $insertedMaxId = $it['_nid'];
    }
}
foreach ($newItems as &$r) { unset($r['_nid']); } unset($r);

// ---- 応答（新着なし） ----
if (!$newItems) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>true,'source'=>SOURCE,'inserted'=>0,'new_count'=>0,
        'counts'=>['all'=>count($allItems),'new'=>0],
        'last_id'=>['before'=>$dbMax,'after'=>$dbMax],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- DB登録 ----
$result = insert_articles($pdo, $newItems, []);

// ---- メール通知 ----
if (ENABLE_MAIL_ON_INSERT && !DRY_RUN && function_exists('send_new_articles_mail')) {
    // 今回挿入された分のみ送る（取り込み「前」の最大IDを基準に抽出）
    $itemsForMail = function_exists('fetch_recently_inserted')
        ? fetch_recently_inserted($pdo, SOURCE, (int)$dbMax)  // $dbMax = 取り込み前のMAX
        : $newItems;
        foreach ($itemsForMail as &$item) {
            if (!isset($item['source'])) {
                $item['source'] = 'sanko';  // 必ず source を設定する
            }
        }
        unset($item);  // 参照解除
        if (!empty($itemsForMail)) {
        $mailSent = send_new_articles_mail($itemsForMail);
    }
}

// ---- state 更新 ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS source_state(
        source TEXT PRIMARY KEY, last_id INTEGER, updated_at TEXT
    )");
    $stmt = $pdo->prepare("INSERT INTO source_state(source,last_id,updated_at)
        VALUES(:src,:last_id,CURRENT_TIMESTAMP)
        ON CONFLICT(source) DO UPDATE SET last_id=excluded.last_id, updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([':src'=>SOURCE, ':last_id'=>$insertedMaxId]);
} catch (Throwable $e) { /* ログがあれば記録 */ }

// ---- 応答 ----
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'=>true,'source'=>SOURCE,
    'inserted'=>$result['inserted'] ?? count($newItems),
    'new_count'=>$result['new_count'] ?? count($newItems),
    'counts'=>['all'=>count($allItems),'new'=>count($newItems)],
    'last_id'=>['before'=>$dbMax,'inserted_max'=>$insertedMaxId,'after'=>max($dbMax,$insertedMaxId)],
    'mail' => ['enabled' => ENABLE_MAIL_ON_INSERT, 'sent' => ($mailSent ?? 0)],
], JSON_UNESCAPED_UNICODE);