<?php
// /member/trip/alarm_cron.php
// CRONから実行：最終連絡時刻超過を安全管理へ通知（重複送信防止）
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/line_notify.php';

$dbPath = __DIR__ . '/trip_manage.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // カラムが無ければ追加（念のため）
    $cols = $pdo->query("PRAGMA table_info(trips)")->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($cols as $c) { if (isset($c['name'])) $names[(string)$c['name']] = true; }
    if (!isset($names['final_contact_deadline'])) $pdo->exec("ALTER TABLE trips ADD COLUMN final_contact_deadline TEXT");
    if (!isset($names['alarm_notified_at'])) $pdo->exec("ALTER TABLE trips ADD COLUMN alarm_notified_at TEXT");

    $now = date('Y-m-d H:i:s');

    // 対象：山行計画 かつ 状態=提出 かつ 最終連絡時刻あり かつ 超過 かつ 未通知
    $sql = "SELECT id,date_ymd,date_to,title,leader,members,descent_contact,final_contact_deadline
            FROM trips
            WHERE category='山行計画'
              AND status='PLAN_SUBMITTED'
              AND final_contact_deadline IS NOT NULL
              AND trim(final_contact_deadline) <> ''
              AND datetime(final_contact_deadline) < datetime('now','localtime')
              AND (alarm_notified_at IS NULL OR trim(alarm_notified_at)='')";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $d1 = (string)($r['date_ymd'] ?? '');
        $d2 = (string)($r['date_to'] ?? '');
        if ($d2 === '') $d2 = $d1;
        $range = ($d2 !== $d1) ? ($d1 . '～' . $d2) : $d1;

        $deadline = (string)($r['final_contact_deadline'] ?? '');
        $msg = [];
        $msg[] = "最終連絡時刻超過";
        // タイトル側で⚠を表示するので、本文先頭は文言のみ（$26A0誤表示の回避も兼ねる）
        $msg[] = $range;
        $msg[] = "山行：" . (string)($r['title'] ?? '');
        if (!empty($r['leader'])) $msg[] = "L:" . (string)$r['leader'];
        if (!empty($r['members'])) $msg[] = "M:" . (string)$r['members'];
        if (!empty($r['descent_contact'])) $msg[] = "下山受け:" . (string)$r['descent_contact'];
        $msg[] = "最終連絡：" . $deadline;

        $url = '';
        // 一覧URL（ボタンのみ。本文にURL文字列は出さない）
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/member/trip/planlist.php?y=' . (int)date('Y') . '&m=' . (int)date('n');
        if (defined('LINE_SAFETY_GROUP_ID') && LINE_SAFETY_GROUP_ID !== '') {
        $ok = line_push_flex(LINE_SAFETY_GROUP_ID, '下山連絡超過', '⚠ 下山連絡超過', $msg, $url);
    } else {
        $ok = line_broadcast_flex('下山連絡超過', '⚠ 下山連絡超過', $msg, $url);
    }
        if ($ok) {
            $pdo->prepare("UPDATE trips SET alarm_notified_at=:t WHERE id=:id")->execute([':t'=>$now, ':id'=>$id]);
            $sent++;
        }
    }

    echo "OK sent={$sent} checked=" . count($rows) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " ALARM_CRON_ERROR " . $e->getMessage() . "\n", FILE_APPEND);
}
