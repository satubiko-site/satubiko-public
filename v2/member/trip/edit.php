<?php
// /member/trip/edit.php
declare(strict_types=1);

/* 追加：原因調査用（画面にエラーを出す） */
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/html; charset=UTF-8');
// 追加：ブラウザ/フレームの表示キャッシュを避ける
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dbPath = __DIR__ . '/trip_manage.db';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// 追加：LINE送信のHTTPステータス（直近）を画面側で判別するため
function line_last_http(): int { return (int)($GLOBALS['LINE_LAST_HTTP'] ?? 0); }


function get_param(string $k, string $default=''): string {
    return isset($_REQUEST[$k]) ? trim((string)$_REQUEST[$k]) : $default;
}

// 追加：line_webhook.php と同一の鍵（承知ボタン用）
function make_line_key(int $id, string $date_ymd): string {
    if (!defined('LINE_APP_SECRET')) return '';
    return substr(hash_hmac('sha256', $id . '|' . $date_ymd, (string)LINE_APP_SECRET), 0, 12);
}

// 追加：Flex + postback ボタンを push（承知ボタン用）
function line_push_flex_postback(string $to, string $altText, string $titleBox, array $lines, string $buttonLabel, string $postbackData): bool {
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) return false;

    $contents = [
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => array_merge(
                [['type'=>'text','text'=>$titleBox,'weight'=>'bold','wrap'=>true]],
                array_map(function($s){
                    return ['type'=>'text','text'=>(string)$s,'wrap'=>true,'size'=>'sm'];
                }, $lines)
            ),
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [[
                'type' => 'button',
                'style' => 'primary',
                'action' => [
                    'type' => 'postback',
                    'label' => $buttonLabel,
                    'data'  => $postbackData,
                ],
            ]],
        ],
    ];

    $payload = [
        'to' => $to,
        'messages' => [[
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $contents,
        ]],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . (string)LINE_CHANNEL_ACCESS_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $GLOBALS['LINE_LAST_HTTP'] = $http;
    curl_close($ch);

    if ($http >= 200 && $http < 300) return true;

    @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " PUSH_FLEX_POSTBACK_FAIL http={$http} resp=" . (is_string($resp)?$resp:'') . "\n", FILE_APPEND);
    return false;
}

// 追加：複数ボタン付き Flex を push（募集用）
function line_push_flex_postback_multi(string $to, string $altText, string $titleBox, array $lines, array $buttons): bool {
    // $buttons = [['label'=>..., 'data'=>..., 'style'=>'primary|secondary'], ...]
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) return false;

    $btnObjs = [];
    foreach ($buttons as $b) {
        if (!is_array($b)) continue;
        $label = (string)($b['label'] ?? '');
        $data  = (string)($b['data'] ?? '');
        if ($label === '' || $data === '') continue;
        $style = (string)($b['style'] ?? 'secondary');
        if ($style !== 'primary' && $style !== 'secondary' && $style !== 'link') $style = 'secondary';

        $btnObjs[] = [
            'type' => 'button',
            'style' => $style,
            'action' => [
                'type' => 'postback',
                'label' => $label,
                'data'  => $data,
            ],
        ];
    }
    if (count($btnObjs) === 0) return false;

    $contents = [
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => array_merge(
                [['type'=>'text','text'=>$titleBox,'weight'=>'bold','wrap'=>true]],
                array_map(function($s){
                    return ['type'=>'text','text'=>(string)$s,'wrap'=>true,'size'=>'sm'];
                }, $lines)
            ),
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => $btnObjs,
        ],
    ];

    $payload = [
        'to' => $to,
        'messages' => [[
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $contents,
        ]],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $GLOBALS['LINE_LAST_HTTP'] = $code;
    curl_close($ch);
    if ($code !== 200) {
        @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " PUSH_FLEX_MULTI_FAIL HTTP={$code} resp=" . (string)$resp . "\n", FILE_APPEND);
        return false;
    }
    return true;
}



function redirect_back(int $y, int $m): void {
    // 追加：クエリにタイムスタンプを付けてキャッシュ表示を回避
    header('Location: planlist.php?y=' . $y . '&m=' . $m . '&_=' . time(), true, 303);
    exit;
}

function load_trip(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM trips WHERE id=:id");
    $st->execute([':id'=>$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}


/**
 * 監査ログ（山行管理の操作ログ）
 * 1行=1イベント（TSV）
 */
function trip_event_log(?array $before, ?array $after, string $action, string $label=''): void {
    $ts = date('Y-m-d H:i:s');
    $id = (string)($after['id'] ?? ($before['id'] ?? ''));
    $title = (string)($after['title'] ?? ($before['title'] ?? ''));
    $from = (string)($before['status'] ?? '');
    $to   = (string)($after['status'] ?? '');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $line = $ts . "\t"
          . "id=" . $id . "\t"
          . "title=" . $title . "\t"
          . "action=" . $action . "\t"
          . "label=" . $label . "\t"
          . "status=" . $from . "->" . $to . "\t"
          . "ip=" . $ip . "\t"
          . "ua=" . substr($ua, 0, 120)
          . "\n";

    @file_put_contents(__DIR__ . '/trip_event.log', $line, FILE_APPEND);
}


// 7) 計画書フォルダから候補ファイルを列挙（date_ymd → /.../{YYYY}plan/{MM}m）
function _plan_urlencode_filename(string $f): string {
    // ファイル名に %XX が含まれる場合：
    // それが「URLエンコード済み文字列をそのままファイル名にした」ケースがあるため、
    // URL上では '%' を %25 にして「文字としての%」を指定する。
    if (preg_match('/%[0-9A-Fa-f]{2}/', $f)) {
        return str_replace('%', '%25', $f);
    }
    // 通常：そのままURLエンコード
    return rawurlencode($f);
}

function list_plan_files(string $dateYmd): array {
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $dateYmd, $m)) return [];

    $yyyy=$m[1];
    $mm=$m[2];

    $dirFs=rtrim($_SERVER['DOCUMENT_ROOT'],'/').
      '/member/multiupload/upload/root/1.plan/'.$yyyy.'plan/'.$mm.'m';
    $dirUrl='/member/multiupload/upload/root/1.plan/'.$yyyy.'plan/'.$mm.'m';

    if(!is_dir($dirFs))return [];

    $files=[];
    foreach(scandir($dirFs) as $f){
        if($f==='.'||$f==='..')continue;
        if(!preg_match('/\.(xls|xlsx)$/i',$f))continue;

        $disp=urldecode($f);
        if(function_exists('mb_convert_encoding')){
            $tmp=@mb_convert_encoding($disp,'UTF-8','SJIS-win');
            if($tmp!==false)$disp=$tmp;
        }

        $files[]=[
            'value'=>$dirUrl.'/'._plan_urlencode_filename($f),
            'label'=>$disp
        ];
    }

    usort($files,function($a,$b){return strcmp($a['label'],$b['label']);});
    return $files;
}

$mode = get_param('mode', '');
$backY = (int)get_param('back_y', date('Y'));
$backM = (int)get_param('back_m', date('n'));
if ($backY < 2000 || $backY > 2100) $backY = (int)date('Y');
if ($backM < 1 || $backM > 12) $backM = (int)date('n');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 追加：人数カラムが無ければ追加（既存DBのまま機能追加できるように）
    try {
        $cols = $pdo->query("PRAGMA table_info(trips)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPeople = false;
        foreach ($cols as $c) {
            if (isset($c['name']) && $c['name'] === 'people') { $hasPeople = true; break; }
        }
        if (!$hasPeople) {
            $pdo->exec("ALTER TABLE trips ADD COLUMN people INTEGER");
        }

        // 追加：下山連絡最終時刻カラムが無ければ追加
        $hasDeadline = false;
        foreach ($cols as $c) {
            if (isset($c['name']) && $c['name'] === 'final_contact_deadline') { $hasDeadline = true; break; }
        }
        if (!$hasDeadline) {
            $pdo->exec("ALTER TABLE trips ADD COLUMN final_contact_deadline TEXT");
        }


        // 追加：精査通知（重複防止）カラムが無ければ追加
        $hasSafetyN = false;
        foreach ($cols as $c) {
            if (isset($c['name']) && $c['name'] === 'safety_notified_at') { $hasSafetyN = true; break; }
        }
        if (!$hasSafetyN) { $pdo->exec("ALTER TABLE trips ADD COLUMN safety_notified_at TEXT"); }

                // 追加：提出通知（重複防止）カラムが無ければ追加
        $hasSubmit = false;
        foreach ($cols as $c) { if ($c['name'] === 'submit_notified_at') { $hasSubmit = true; break; } }
        if (!$hasSubmit) { $pdo->exec("ALTER TABLE trips ADD COLUMN submit_notified_at TEXT"); }

        // 追加：募集通知（重複防止）カラムが無ければ追加
        $hasRecruit = false;
        foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'recruit_notified_at') { $hasRecruit = true; break; } }
        if (!$hasRecruit) { $pdo->exec("ALTER TABLE trips ADD COLUMN recruit_notified_at TEXT"); }

// 追加：最終連絡超過通知（重複防止）カラムが無ければ追加
        $hasAlarmN = false;
        foreach ($cols as $c) {
            if (isset($c['name']) && $c['name'] === 'alarm_notified_at') { $hasAlarmN = true; break; }
        }
        if (!$hasAlarmN) { $pdo->exec("ALTER TABLE trips ADD COLUMN alarm_notified_at TEXT"); }

        // 追加：募集終了フラグが無ければ追加（任意運用）
        $hasRecruitClosed = false;
        foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'recruit_closed') { $hasRecruitClosed = true; break; } }
        if (!$hasRecruitClosed) { $pdo->exec("ALTER TABLE trips ADD COLUMN recruit_closed INTEGER NOT NULL DEFAULT 0"); }

        // 追加：精査終了フラグが無ければ追加（任意運用）
        $hasSafetyClosed = false;
        foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'safety_closed') { $hasSafetyClosed = true; break; } }
        if (!$hasSafetyClosed) { $pdo->exec("ALTER TABLE trips ADD COLUMN safety_closed INTEGER NOT NULL DEFAULT 0"); }

        // 追加：下山受け了承フラグが無ければ追加（任意運用）
        $hasDescentAck = false;
        foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'descent_ack') { $hasDescentAck = true; break; } }
        if (!$hasDescentAck) { $pdo->exec("ALTER TABLE trips ADD COLUMN descent_ack INTEGER NOT NULL DEFAULT 0"); }

    } catch (Throwable $e) {
        // 失敗しても致命ではないため握りつぶす
    }

} catch (Throwable $e) {
    echo "<pre>ERROR: " . h($e->getMessage()) . "</pre>";
    exit;
}

$error = '';
$info = '';
            $info_type = 'ok';
$info_type = 'ok'; // ok|warn|error
$row = [
    'id' => '',
    'date_ymd' => '',
    'title' => '',
    'category' => '山行計画',
    'trip_type' => '',
    'trip_style' => '',
    'leader' => '',
    'members' => '',
    'people' => '',
    'descent_contact' => '',
    'final_contact_deadline' => '',
    'plan_path' => '',
    'status' => 'PLANNING',
    'recruit_closed' => 0,
    'safety_closed' => 0,
    'descent_ack' => 0,
    'note' => '',
];

// 追加：LINE送信確認（方式A）用。GET表示でも未定義にならないよう初期化
$prevStatus = '';
$prevSafetyNotified = '';
$prevSubmitNotified = '';

// 期間 To は未入力を許容（Undefined array key 回避のみ）
if (!isset($row['date_to'])) { $row['date_to'] = ''; }
if (!isset($row['final_contact_deadline'])) { $row['final_contact_deadline'] = ''; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = get_param('action', 'save');
    $id = (int)get_param('id', '0');

    $sendLine = (get_param('send_line', '0') === '1');

    $beforeRow = ($id > 0) ? load_trip($pdo, $id) : null;

    // 追加：新規登録（id=0）で「保存」以外を押して警告/エラーになっても入力値を保持する
    //       （特に日付が空に戻ると計画書の選択肢生成ができなくなるため）
    if ($id <= 0) {
        $row['date_ymd'] = get_param('date_ymd', $row['date_ymd']);
        $row['date_to'] = get_param('date_to', $row['date_to'] ?? '');
        $row['title'] = get_param('title', $row['title']);
        $row['category'] = get_param('category', $row['category']);
        $row['trip_type'] = get_param('trip_type', $row['trip_type']);
        $row['trip_style'] = get_param('trip_style', $row['trip_style']);
        $row['leader'] = get_param('leader', $row['leader']);
        $row['members'] = get_param('members', $row['members']);
        $row['people'] = get_param('people', (string)($row['people'] ?? ''));
        $row['descent_contact'] = get_param('descent_contact', $row['descent_contact']);
        $row['final_contact_deadline'] = get_param('final_contact_deadline', $row['final_contact_deadline']);
        $row['plan_path'] = get_param('plan_path', $row['plan_path']);
        $row['status'] = get_param('status', $row['status']);
        $row['note'] = get_param('note', $row['note']);
    }

    // 1) 削除
    if ($action === 'delete' && $id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM trips WHERE id=:id");
            $stmt->execute([':id' => $id]);
            trip_event_log($beforeRow, null, 'delete', '削除');
            
            $info = '山行一覧表から山行計画を削除しました';
            $info_type = 'ok';
            // 削除後は新規入力状態に戻す（画面は残す）
            $row = [
                'id' => '0',
                'date_ymd' => date('Y-m-d'),
                'date_to' => '',
                'title' => '',
                'category' => '山行計画',
                'trip_type' => '',
                'trip_style' => '',
                'leader' => '',
                'members' => '',
                'people' => '',
                'descent_contact' => '',
                'final_contact_deadline' => '',
                'plan_path' => '',
                'status' => 'PLANNING',
                'note' => '',
            ];
            $action = '';

        } catch (Throwable $e) {
            $error = 'DB削除エラー: ' . $e->getMessage();
        }
    }

    // 追加：提出通知（保存後に実行）
    if ($action === 'submit_notify') {
        require_once __DIR__ . '/line_config.php';
        if ($id <= 0) {
            $info = '提出通知は、いったん保存してから実行してください。';
            $info_type = 'warn';
            $error = '';
        } else {
            // DBの保存済み内容で通知する（未保存変更は対象外）
            $st = $pdo->prepare("SELECT * FROM trips WHERE id=:id");
            $st->execute([':id'=>$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $error = '提出通知対象が見つかりません。';
            } else {
                // 必須チェック
                $miss = [];
                if ((string)($r['category'] ?? '') !== '山行計画') $miss[] = 'カテゴリ（山行計画）';
                if (trim((string)($r['title'] ?? '')) === '') $miss[] = '山行名';
                if (trim((string)($r['date_ymd'] ?? '')) === '') $miss[] = '期間From';
                if (trim((string)($r['leader'] ?? '')) === '') $miss[] = 'リーダー';
                if (trim((string)($r['descent_contact'] ?? '')) === '') $miss[] = '下山受け';
                if (trim((string)($r['final_contact_deadline'] ?? '')) === '') $miss[] = '最終下山連絡時刻';
                if (trim((string)($r['plan_path'] ?? '')) === '') $miss[] = '計画書';

                if (!empty($miss)) {
                    $error = '提出通知に必要な情報が不足しています: ' . implode('、', $miss);
                } else {
                    // 送信済みの場合は再送確認が必要（JSで確認→confirm_resend=1）
                    // 追加：LINE通知しない（いいえ）場合でもステータスは更新する
                    if (!$sendLine) {
                        $now = date('Y-m-d H:i:s');
                        $pdo->prepare("UPDATE trips SET status='PLAN_SUBMITTED', updated_at=:u WHERE id=:id")
                            ->execute([':u'=>$now, ':id'=>$id]);
                        $info = '管理状態を提出に更新しました。（LINE通知は行いませんでした）';
                        $info_type = 'ok';
                        $error = '';
                        $row = load_trip($pdo, (int)$id) ?: $row;
                        trip_event_log($beforeRow, $row, 'submit_notify', '提出');
                        $action = '';
                    } else {
$already = trim((string)($r['submit_notified_at'] ?? '')) !== '';
                    $confirmResend = (get_param('confirm_resend', '0') === '1');
                    if ($already && !$confirmResend) {
                        $error = 'この計画書提出通知は既に送信済みです。再送する場合は確認のうえ実行してください。';
                    } else {
                        require_once __DIR__ . '/line_notify.php';

                        $date = (string)$r['date_ymd'];
                        $date_to = (string)($r['date_to'] ?? '');
                        $days = $date;
                        if ($date_to !== '' && $date_to !== $date) $days .= '～' . $date_to;

                        $typeLine = (string)($r['trip_type'] ?? '');
                        $trip_style = (string)($r['trip_style'] ?? '');
                        if ($trip_style !== '') $typeLine .= '　形態：' . $trip_style;

                        $lines = [];
                        $lines[] = '以下の山行について計画書を提出します。';
                        $lines[] = '下山連絡受けをよろしくお願いします。';
                        $lines[] = '日程：' . $days;
                        $lines[] = '山名：' . (string)$r['title'];
                        if ($typeLine !== '') $lines[] = '種別：' . $typeLine;
                        $lines[] = 'リーダー：' . (string)$r['leader'];
                        $lines[] = '下山受け：' . (string)$r['descent_contact'];
                        $lines[] = '最終下山連絡時刻：' . (string)$r['final_contact_deadline'];

                        $ok = false;
                        if (defined('LINE_SUBMIT_GROUP_ID') && LINE_SUBMIT_GROUP_ID !== '') {
                            $k = make_line_key((int)$id, (string)$date);
                            $ackData = http_build_query(['action'=>'descent_ack','id'=>(int)$id,'k'=>$k], '', '&');
                            $ok = line_push_flex_postback(
                              LINE_SUBMIT_GROUP_ID,
                                '計画書提出',
                                '【計画書提出】',
                                $lines,
                                '下山連絡受け 承知',
                                $ackData
                            );
                        }

                        // ボタン操作で状態を提出へ揃える（LINE送信成否に関わらず）
                        $now = date('Y-m-d H:i:s');
                        $pdo->prepare("UPDATE trips SET status='PLAN_SUBMITTED', updated_at=:u WHERE id=:id")
                            ->execute([':u'=>$now, ':id'=>$id]);

                        if ($ok) {
                            // 送信済みフラグ更新
                            $pdo->prepare("UPDATE trips SET submit_notified_at=:t, updated_at=:u WHERE id=:id")
                                ->execute([':t'=>$now, ':u'=>$now, ':id'=>$id]);
                            $info = '管理状態を提出に更新し、LINE通知しました。';
                            $info_type = 'ok';
                            $row = load_trip($pdo, (int)$id) ?: $row;
                            $action = '';
                            trip_event_log($beforeRow, $row, 'submit_notify', '提出');
                            // 画面表示へ（一覧へ戻らない）
                        } else {
                            // 状態は提出へ更新済み。LINEは失敗。
                            $row = load_trip($pdo, (int)$id) ?: $row;
                        
                            $http = line_last_http();
                            $info = '管理状態を提出に更新しましたが、LINE通知に失敗しました。' . ($http===429 ? '（送信上限超過:429）' : '');
                            $info_type = 'warn';
                            $error = '';
                            // 状態更新は完了しているためログに残す
                            trip_event_log($beforeRow, $row, 'submit_notify', '提出');
                            @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " SUBMIT_NOTIFY_FAIL id=" . $id . "\n", FILE_APPEND);
                        }
                    }
                }
            }
        }
                    }
// submit_notify の場合、保存処理へ進まない
    }
    // 追加：精査通知（保存後に実行）
    if ($action === 'safety_notify') {
      require_once __DIR__ . '/line_config.php';
        if ($id <= 0) {
            $info = '精査通知は、いったん保存してから実行してください。';
            $info_type = 'warn';
            $error = '';
        } else {
            $st = $pdo->prepare("SELECT * FROM trips WHERE id=:id");
            $st->execute([':id'=>$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $error = '精査通知対象が見つかりません。';
            } else {
                $miss = [];
                if ((string)($r['category'] ?? '') !== '山行計画') $miss[] = 'カテゴリ（山行計画）';
                if (trim((string)($r['title'] ?? '')) === '') $miss[] = '山行名';
                if (trim((string)($r['date_ymd'] ?? '')) === '') $miss[] = '期間From';
                if (trim((string)($r['leader'] ?? '')) === '') $miss[] = 'リーダー';
                if (trim((string)($r['plan_path'] ?? '')) === '') $miss[] = '計画書';
                if (!empty($miss)) {
                    $error = '精査通知に必要な情報が不足しています: ' . implode('、', $miss);
                } else {
                    // 追加：LINE通知しない（いいえ）場合でもステータスは更新する
                    if (!$sendLine) {
                        $now = date('Y-m-d H:i:s');
                        $pdo->prepare("UPDATE trips SET status='APPROVED', updated_at=:u WHERE id=:id")
                            ->execute([':u'=>$now, ':id'=>$id]);
                        $info = '管理状態を精査中に更新しました。（LINE通知は行いませんでした）';
                        $info_type = 'ok';
                        $error = '';
                        $row = load_trip($pdo, (int)$id) ?: $row;
                        trip_event_log($beforeRow, $row, 'safety_notify', '精査');
                        $action = '';
                    } else {
$already = trim((string)($r['safety_notified_at'] ?? '')) !== '';
                    $confirmResend = (get_param('confirm_resend_safety', '0') === '1');
                    if ($already && !$confirmResend) {
                        $error = 'この精査通知は既に送信済みです。再送する場合は確認のうえ実行してください。';
                    } else {
                        if (!defined('LINE_SAFETY_GROUP_ID') || LINE_SAFETY_GROUP_ID === '') {
                            $error = 'LINE_SAFETY_GROUP_ID（安全管理グループID）が未設定です。';
                        } else {
                            $date = (string)$r['date_ymd'];
                            $date_to = (string)($r['date_to'] ?? '');
                            $days = $date;
                            if ($date_to !== '' && $date_to !== $date) $days .= '～' . $date_to;

                            $lines = [];
                            $lines[] = $days;
                            $lines[] = '山行：' . (string)$r['title'];
                            $lines[] = 'L：' . (string)$r['leader'];
                            $mem = trim((string)($r['members'] ?? ''));
                            if ($mem !== '') $lines[] = 'M：' . $mem;
                            $dc = trim((string)($r['descent_contact'] ?? ''));
                            if ($dc !== '') $lines[] = '下山受け：' . $dc;
                            $dl = trim((string)($r['final_contact_deadline'] ?? ''));
                            if ($dl !== '') $lines[] = '最終下山連絡時刻：' . $dl;

                            $k = make_line_key((int)$id, (string)$date);
                            $buttons = [
                                ['label'=>'確認しました', 'data'=>http_build_query(['action'=>'ack_safety','id'=>(int)$id,'k'=>$k], '', '&'), 'style'=>'secondary']
                            ];
                            $ok = line_push_flex_postback_multi(
                              LINE_SAFETY_GROUP_ID,
                                '安全確認',
                                '【安全確認】',
                                $lines,
                                $buttons
                            );

                            // ボタン操作で状態を精査へ揃える（LINE送信成否に関わらず）
                            $now = date('Y-m-d H:i:s');
                            $pdo->prepare("UPDATE trips SET status='APPROVED', updated_at=:u WHERE id=:id")
                                ->execute([':u'=>$now, ':id'=>$id]);

                            if ($ok) {
                                $pdo->prepare("UPDATE trips SET safety_notified_at=:t, updated_at=:u WHERE id=:id")
                                    ->execute([':t'=>$now, ':u'=>$now, ':id'=>$id]);
                                $info = '精査状態に更新し、LINE通知しました。';
                                $info_type = 'ok';
                                $row = load_trip($pdo, (int)$id) ?: $row;
                                $action = '';
                            trip_event_log($beforeRow, $row, 'safety_notify', '精査');
                            } else {
                                // 状態は精査へ更新済み。LINEは失敗。
                                $row = load_trip($pdo, (int)$id) ?: $row;
                            
                                $http = line_last_http();
                                $info = '管理状態を精査中に更新しましたが、LINE通知に失敗しました。' . ($http===429 ? '（送信上限超過:429）' : '');
                            $info_type = 'warn';
                            $error = '';
                            trip_event_log($beforeRow, $row, 'safety_notify', '精査');
                                @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " SAFETY_NOTIFY_FAIL id=" . $id . "\n", FILE_APPEND);
                            }
                        }
                    }
                }
            }
        }
                    }
// safety_notify の場合、保存処理へ進まない
    }

    // 追加：募集通知（保存後に実行）
    if ($action === 'recruit_notify') {
      require_once __DIR__ . '/line_config.php';
        if ($id <= 0) {
            $info = '募集通知は、いったん保存してから実行してください。';
            $info_type = 'warn';
            $error = '';
        } else {
            $st = $pdo->prepare("SELECT * FROM trips WHERE id=:id");
            $st->execute([':id'=>$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $error = '募集通知対象が見つかりません。';
            } else {
                $miss = [];
                if ((string)($r['category'] ?? '') !== '山行計画') $miss[] = 'カテゴリ（山行計画）';
                if (trim((string)($r['title'] ?? '')) === '') $miss[] = '山行名';
                if (trim((string)($r['date_ymd'] ?? '')) === '') $miss[] = '期間From';
                if (trim((string)($r['leader'] ?? '')) === '') $miss[] = 'リーダー';
                if (trim((string)($r['plan_path'] ?? '')) === '') $miss[] = '計画書';
                if (!empty($miss)) {
                    $error = '募集通知に必要な情報が不足しています: ' . implode('、', $miss);
                } else {
                    // 追加：LINE通知しない（いいえ）場合でもステータスは更新する
                    if (!$sendLine) {
                        $now = date('Y-m-d H:i:s');
                        $pdo->prepare("UPDATE trips SET status='RECRUIT', updated_at=:u WHERE id=:id")
                            ->execute([':u'=>$now, ':id'=>$id]);
                        $info = '管理状態を募集中に更新しました。（LINE通知は行いませんでした）';
                        $info_type = 'ok';
                        $error = '';
                        $row = load_trip($pdo, (int)$id) ?: $row;
                        trip_event_log($beforeRow, $row, 'recruit_notify', '参加募集');
                        $action = '';
                    } else {
$already = trim((string)($r['recruit_notified_at'] ?? '')) !== '';
                    $confirmResend = (get_param('confirm_resend_recruit', '0') === '1');
                    if ($already && !$confirmResend) {
                        $error = 'この募集通知は既に送信済みです。再送する場合は確認のうえ実行してください。';
                    } else {
                        if (!defined('LINE_RECRUIT_GROUP_ID') || LINE_RECRUIT_GROUP_ID === '') {
                            $error = 'LINE_RECRUIT_GROUP_ID（募集通知グループID）が未設定です。';
                        } else {
                            $date = (string)$r['date_ymd'];
                            $date_to = (string)($r['date_to'] ?? '');
                            $days = $date;
                            if ($date_to !== '' && $date_to !== $date) $days .= '～' . $date_to;

                            $typeLine = (string)($r['trip_type'] ?? '');
                            $trip_style = (string)($r['trip_style'] ?? '');
                            if ($trip_style !== '') $typeLine .= '　形態：' . $trip_style;

                            $lines = [];
                            $lines[] = $days;
                            $lines[] = '山行：' . (string)$r['title'];
                            if ($typeLine !== '') $lines[] = '種別：' . $typeLine;
                            $lines[] = 'L：' . (string)$r['leader'];
                            $mem = trim((string)($r['members'] ?? ''));
                            if ($mem !== '') $lines[] = 'M：' . $mem;

                            $k = make_line_key((int)$id, (string)$date);
                            $buttons = [
                                ['label'=>'確認しました', 'data'=>http_build_query(['action'=>'ack_recruit','id'=>(int)$id,'k'=>$k], '', '&'), 'style'=>'secondary']
                            ];
                            $ok = line_push_flex_postback_multi(
                                LINE_RECRUIT_GROUP_ID,
                                '募集',
                                '【募集】',
                                $lines,
                                $buttons
                            );

                            // ボタン操作で状態を募集へ揃える（LINE送信成否に関わらず）
                            $now = date('Y-m-d H:i:s');
                            $pdo->prepare("UPDATE trips SET status='RECRUIT', updated_at=:u WHERE id=:id")
                                ->execute([':u'=>$now, ':id'=>$id]);

                            if ($ok) {
                                $pdo->prepare("UPDATE trips SET recruit_notified_at=:t, updated_at=:u WHERE id=:id")
                                    ->execute([':t'=>$now, ':u'=>$now, ':id'=>$id]);
                                $info = '管理状態を募集中に更新し、LINE通知しました。';
                                $info_type = 'ok';
                                $row = load_trip($pdo, (int)$id) ?: $row;
                                $action = '';
                            trip_event_log($beforeRow, $row, 'recruit_notify', '参加募集');
                            } else {
                                // 状態は募集へ更新済み。LINEは失敗。
                                $row = load_trip($pdo, (int)$id) ?: $row;
                            
                                $http = line_last_http();
                                $info = '管理状態を募集中に更新しましたが、LINE通知に失敗しました。' . ($http===429 ? '（送信上限超過:429）' : '');
                            $info_type = 'warn';
                            $error = '';
                            trip_event_log($beforeRow, $row, 'recruit_notify', '参加募集');
                                @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " RECRUIT_NOTIFY_FAIL id=" . $id . "\n", FILE_APPEND);
                            }
                        }
                    }
                }
            }
        }
                    }
// recruit_notify の場合、保存処理へ進まない
    }



    // 追加：募集終了（募集終了フラグを立てる）
    if ($action === 'recruit_end') {
        if ($id <= 0) {
            $error = '募集終了は、いったん保存してから実行してください。';
        } else {
            // 募集状態でない場合は、状態は変更せずフラグのみ（最小差分）
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE trips SET status='PLANNING', recruit_closed=1, updated_at=:u WHERE id=:id")
                ->execute([':u'=>$now, ':id'=>$id]);
            $info = '管理状態を予定に戻しました（応募終了）';
            $info_type = 'ok';
            $row = load_trip($pdo, (int)$id) ?: $row;
            trip_event_log($beforeRow, $row, 'recruit_end', '応募終了');
            $action = '';
        }
        // recruit_end の場合、保存処理へ進まない
    }

    // 追加：精査終了（精査終了フラグを立てる）
    if ($action === 'safety_end') {
        if ($id <= 0) {
            $error = '精査終了は、いったん保存してから実行してください。';
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE trips SET status='PLAN_SUBMITTED', safety_closed=1, updated_at=:u WHERE id=:id")
                ->execute([':u'=>$now, ':id'=>$id]);
            $info = '管理状態を提出に戻しました（精査終了）';
            $info_type = 'ok';
            $row = load_trip($pdo, (int)$id) ?: $row;
            trip_event_log($beforeRow, $row, 'safety_end', '精査終了');
            $action = '';
        }
        // safety_end の場合、保存処理へ進まない
    }

    // 追加：下山完了（状態を下山に更新）
    if ($action === 'descend_complete') {
        require_once __DIR__ . '/line_config.php';
        if ($id <= 0) {
            $error = '下山終了は、いったん保存してから実行してください。';
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE trips SET status='DESCENDED', descended_at=:u, updated_at=:u WHERE id=:id")
                ->execute([':u'=>$now, ':id'=>$id]);
            $info = '管理状態を下山完了としました';
            $info_type = 'ok';
            $row = load_trip($pdo, (int)$id) ?: $row;
            trip_event_log($beforeRow, $row, 'descend_complete', '下山完了');
            $action = '';
        }
        // descend_complete の場合、保存処理へ進まない
    }

    
    // 追加：完了（状態を完了に更新）
    if ($action === 'close_complete') {
        require_once __DIR__ . '/line_config.php';
        if ($id <= 0) {
            $error = '終了は、いったん保存してから実行してください。';
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE trips SET status='CLOSED', updated_at=:u WHERE id=:id")
                ->execute([':u'=>$now, ':id'=>$id]);
            $info = '管理状態を終了としました';
            $info_type = 'ok';
            $row = load_trip($pdo, (int)$id) ?: $row;
            trip_event_log($beforeRow, $row, 'close_complete', '終了');
            $action = '';
        }
        // close_complete の場合、保存処理へ進まない
    }

// save
    if ($action === 'save') {
    $date = get_param('date_ymd', '');
    $date_to = trim(get_param('date_to', ''));
    $title = get_param('title', '');
    $category = get_param('category', '山行計画');
    $trip_type = get_param('trip_type', '');
    $trip_style = get_param('trip_style', '');
    $leader = get_param('leader', '');
    $members = get_param('members', '');
    $people_raw = trim(get_param('people', ''));
    // 人数は数字のみを保存（例: "3" または "3人" → 3）
    $people_digits = preg_replace('/\D+/', '', $people_raw);
    $people = ($people_digits !== '') ? (int)$people_digits : null;
    $descent_contact = get_param('descent_contact', '');
    $descent_ack = (get_param('descent_ack', '') !== '') ? 1 : 0;
    $final_contact_deadline = get_param('final_contact_deadline', '');
    $plan_path = get_param('plan_path', '');
    $status = get_param('status', 'PLANNING');
    $note = get_param('note', '');

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = '日付が不正です（YYYY-MM-DD）。';
    } elseif ($title === '') {
        $error = '山行名（山名）が空です。';
    } else {
        $now = date('Y-m-d H:i:s');
        // 追加：状態変化検知（精査通知用）
        $prevStatus = '';
        $prevSafetyNotified = '';
        if ($id > 0) {
            $st = $pdo->prepare("SELECT status,safety_notified_at,submit_notified_at FROM trips WHERE id=:id");
            $st->execute([':id'=>$id]);
            $pr = $st->fetch(PDO::FETCH_ASSOC);
            if ($pr) {
                $prevStatus = (string)($pr['status'] ?? '');
                $prevSafetyNotified = (string)($pr['safety_notified_at'] ?? '');
                $prevSubmitNotified = (string)($pr['submit_notified_at'] ?? '');
            }
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
UPDATE trips SET
  date_ymd=:date_ymd,
  date_to=:date_to,
  title=:title,
  category=:category,
  trip_type=:trip_type,
  trip_style=:trip_style,
  leader=:leader,
  members=:members,
  people=:people,
  descent_contact=:descent_contact,
  descent_ack=:descent_ack,
  final_contact_deadline=:final_contact_deadline,
  plan_path=:plan_path,
  status=:status,
  note=:note,
  updated_at=:updated_at
WHERE id=:id
");
                $stmt->execute([
                    ':date_ymd'=>$date,
                    ':date_to'=>$date_to,
                    ':title'=>$title,
                    ':category'=>$category,
                    ':trip_type'=>$trip_type,
                    ':trip_style'=>$trip_style,
                    ':leader'=>$leader,
                    ':members'=>$members,
                    ':people'=>$people,
                    ':descent_contact'=>$descent_contact,
                    ':descent_ack'=>$descent_ack,
                    ':final_contact_deadline'=>$final_contact_deadline,
                    ':plan_path'=>$plan_path,
                    ':status'=>$status,
                    ':note'=>$note,
                    ':updated_at'=>$now,
                    ':id'=>$id,
                ]);
            } else {
                $stmt = $pdo->prepare("
INSERT INTO trips(
  date_ymd,date_to,title,category,trip_type,trip_style,leader,members,people,descent_contact,descent_ack,final_contact_deadline,plan_path,status,note,updated_at
) VALUES (
  :date_ymd,:date_to,:title,:category,:trip_type,:trip_style,:leader,:members,:people,:descent_contact,:descent_ack,:final_contact_deadline,:plan_path,:status,:note,:updated_at
)
");
                $stmt->execute([
                    ':date_ymd'=>$date,
                    ':date_to'=>$date_to,
                    ':title'=>$title,
                    ':category'=>$category,
                    ':trip_type'=>$trip_type,
                    ':trip_style'=>$trip_style,
                    ':leader'=>$leader,
                    ':members'=>$members,
                    ':people'=>$people,
                    ':descent_contact'=>$descent_contact,
                    ':descent_ack'=>$descent_ack,
                    ':final_contact_deadline'=>$final_contact_deadline,
                    ':plan_path'=>$plan_path,
                    ':status'=>$status,
                    ':note'=>$note,
                    ':updated_at'=>$now,
                ]);
            }


            
            // 追加：保存したID（INSERT時はlastInsertId）
            $savedId = ($id > 0) ? (int)$id : (int)$pdo->lastInsertId();


            // 追加：INSERT直後でも以降の処理で正しいIDを参照できるようにする
            $id = $savedId;
            // 追加：通知フラグのリセット（提出に戻した場合など）
            // - 提出に戻したら「要安全確認」通知フラグを消す（再度安全確認にした時に再通知できる）
            // - 提出に戻したら「超過通知」フラグも消す（再監視対象として再通知できる）
            // - 提出以外に変えたら「超過通知」フラグを消す（状態が変わったらいったんリセット）
            if ($category === '山行計画') {
                if ($status === 'PLAN_SUBMITTED' && $prevStatus !== 'PLAN_SUBMITTED') {
                    $pdo->prepare("UPDATE trips SET safety_notified_at=NULL, alarm_notified_at=NULL WHERE id=:id")
                        ->execute([':id'=>$savedId]);
                    // 以降の処理（精査通知判定）は prevSafetyNotified を空扱いにしない（= 提出に戻しただけで通知は出さない）
                }
                if ($status !== 'PLAN_SUBMITTED' && $prevStatus === 'PLAN_SUBMITTED') {
                    $pdo->prepare("UPDATE trips SET alarm_notified_at=NULL WHERE id=:id")
                        ->execute([':id'=>$savedId]);
                }
            }


            // 追加：提出になったタイミングで会員向けグループへ通知（重複防止）
            // ※提出通知は「提出通知」ボタンで行うため、保存時の自動送信は行わない
            $needSubmitNotify = false;
            if ($sendLine && $needSubmitNotify) {
                require_once __DIR__ . '/line_notify.php';
                
                // 計画書提出メッセージ（指定文面）
                $days = (string)$date;
                if ($date_to !== '' && $date_to !== $date) $days .= '～' . (string)$date_to;

                $typeLine = (string)$trip_type;
                if ($trip_style !== '') $typeLine .= '　形態：' . (string)$trip_style;

                $lines = [];
                $lines[] = '以下の山行について計画書を提出します。';
                $lines[] = '下山連絡受けをよろしくお願いします。';
                $lines[] = '日程：' . $days;
                $lines[] = '山名：' . (string)$title;
                $lines[] = '種別：' . $typeLine;
                if ($leader !== '') $lines[] = 'リーダー：' . (string)$leader;
                if ($descent_contact !== '') $lines[] = '下山受け：' . (string)$descent_contact;
                if ($final_contact_deadline !== '') $lines[] = '最終下山連絡時刻：' . (string)$final_contact_deadline;

                $ok = false;
                if (defined('LINE_SUBMIT_GROUP_ID') && LINE_SUBMIT_GROUP_ID !== '') {
                    $k = make_line_key((int)$savedId, (string)$date);
                    $ackData = http_build_query(['action'=>'descent_ack','id'=>(int)$savedId,'k'=>$k], '', '&');
                    $ok = line_push_flex_postback(
                        LINE_SUBMIT_GROUP_ID,
                        '計画書提出',
                        '【計画書提出】',
                        $lines,
                        '下山連絡受け 承知',
                        $ackData
                    );
                }
                if ($ok) {
                    $pdo->prepare("UPDATE trips SET submit_notified_at=:t WHERE id=:id")
                        ->execute([':t'=>date('Y-m-d H:i:s'), ':id'=>$savedId]);
                } else {
                    @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " SUBMIT_NOTIFY_FAIL id=" . $savedId . "\n", FILE_APPEND);
                }
            }

// 追加：安全確認になったタイミングで安全管理グループへ通知（重複防止）
            $needSafetyNotify = ($category === '山行計画' && $status === 'APPROVED' && $prevStatus !== 'APPROVED' && trim((string)$prevSafetyNotified) === '');
            if ($sendLine && $needSafetyNotify) {
                require_once __DIR__ . '/line_notify.php';
                $msgLines = [];
                $msgLines[] = '【要安全確認】';
                $msgLines[] = (string)$date . (($date_to !== '' && $date_to !== $date) ? ('～' . (string)$date_to) : '');
                $msgLines[] = '山行：' . (string)$title;
                if ($leader !== '') $msgLines[] = 'L:' . (string)$leader;
                if ($members !== '') $msgLines[] = 'M:' . (string)$members;
                if ($descent_contact !== '') $msgLines[] = '下山受け:' . (string)$descent_contact;
                // 一覧URL（絶対URL）
                $base = (function(){
                    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                    $scheme = $https ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
                    return $scheme . '://' . $host . $dir;
                })();
                                $text = implode("
", $msgLines);

                // Flex（ボックス）通知
                $alt = '要安全確認';
                $titleBox = '【要安全確認】';
                // 表示用：先頭行の【要安全確認】はボックスに出すので除外
                $lines = $msgLines;
                if (count($lines) > 0 && strpos($lines[0], '【') === 0) array_shift($lines);

                $url = $base . '/planlist.php?y=' . $backY . '&m=' . $backM;

                if (defined('LINE_SAFETY_GROUP_ID') && LINE_SAFETY_GROUP_ID !== '') {
                    $ok = line_push_flex(LINE_SAFETY_GROUP_ID, $alt, $titleBox, $lines, $url);
                } else {
                    $ok = line_broadcast_flex($alt, $titleBox, $lines, $url);
                }if ($ok) {
                    $pdo->prepare("UPDATE trips SET safety_notified_at=:t WHERE id=:id")->execute([':t'=>$now, ':id'=>$savedId]);
                } else {
                    @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " SAFETY_NOTIFY_FAIL id=" . $savedId . "\n", FILE_APPEND);
                }
            }

            

            // 追加：募集になったタイミングで会員グループへ「参加募集」通知（ボタン3種）
            $needRecruitNotify = ($category === '山行計画' && $status === 'RECRUIT' && $prevStatus !== 'RECRUIT');
            if ($sendLine && $needRecruitNotify) {
                require_once __DIR__ . '/line_notify.php';

                $days = (string)$date;
                if ($date_to !== '' && $date_to !== $date) $days .= '～' . (string)$date_to;

                $typeLine = (string)$trip_type;
                if ($trip_style !== '') $typeLine .= '　形態：' . (string)$trip_style;

                $lines = [];
                $lines[] = '一緒に行きませんか？';
                $lines[] = '参加希望／検討中／見送り をボタンでお知らせください。';
                $lines[] = '日程：' . $days;
                $lines[] = '山名：' . (string)$title;
                $lines[] = '種別：' . $typeLine;
                if ($leader !== '') $lines[] = 'リーダー：' . (string)$leader;
                $lines[] = '' . $note;

                $ok = false;
                if (defined('LINE_SUBMIT_GROUP_ID') && LINE_SUBMIT_GROUP_ID !== '') {
                    $k = make_line_key((int)$savedId, (string)$date);
                    $btnJoin = http_build_query(['action'=>'recruit_join','id'=>(int)$savedId,'k'=>$k], '', '&');
                    $btnMaybe = http_build_query(['action'=>'recruit_consider','id'=>(int)$savedId,'k'=>$k], '', '&');
                    $btnNo = http_build_query(['action'=>'recruit_decline','id'=>(int)$savedId,'k'=>$k], '', '&');

                    $ok = line_push_flex_postback_multi(
                        LINE_SUBMIT_GROUP_ID,
                        '参加募集',
                        '【参加募集】',
                        $lines,
                        [
                            ['label'=>'参加を希望します', 'data'=>$btnJoin, 'style'=>'primary'],
                            ['label'=>'検討させてください', 'data'=>$btnMaybe, 'style'=>'secondary'],
                            ['label'=>'今回は見送ります', 'data'=>$btnNo, 'style'=>'secondary'],
                        ]
                    );
                }
                if (!$ok) {
                    @file_put_contents(__DIR__ . '/line_notify.log', date('Y-m-d H:i:s') . " RECRUIT_NOTIFY_FAIL id=" . $savedId . "\n", FILE_APPEND);
                }
            }

            $info = '入力された情報を保存しました';
            $info_type = 'ok';
            $row = load_trip($pdo, (int)$savedId) ?: $row;
            if ($beforeRow && (string)($beforeRow['status'] ?? '') !== (string)($row['status'] ?? '')) {
                trip_event_log($beforeRow, $row, 'save', '保存');
            }
            $action = '';
            // 画面表示へ（一覧へ戻らない）


        } catch (Throwable $e) {
            $error = 'DB保存エラー: ' . $e->getMessage();
        }
    }

    // エラー時は入力保持
    if ($error !== '') {
        $row = [
        'id' => (string)$id,
        'date_ymd' => $date,
        'date_to' => $date_to,
        'title' => $title,
        'category' => $category,
        'trip_type' => $trip_type,
        'trip_style' => $trip_style,
        'leader' => $leader,
        'members' => $members,
        'people' => $people_raw,
        'descent_contact' => $descent_contact,
        'final_contact_deadline' => $final_contact_deadline,
        'plan_path' => $plan_path,
        'status' => $status,
        'note' => $note,
        ];
    }

    }


} else {
    if ($mode === 'edit') {
        $id = (int)get_param('id', '0');
        $stmt = $pdo->prepare("SELECT * FROM trips WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $error = '対象データが見つかりません。';
        } else {
            $row = $r;
            // 追加：保存時confirm用（現状値を保持）
            $prevStatus = (string)($row['status'] ?? '');
            $prevSafetyNotified = (string)($row['safety_notified_at'] ?? '');
            $prevSubmitNotified = (string)($row['submit_notified_at'] ?? '');
        if (!isset($row['date_to']) || $row['date_to'] === '') { $row['date_to'] = $row['date_ymd']; }
        // 追加：最終下山連絡時刻の初期値（下山日=期間To の 22:00）
        if (!isset($row['final_contact_deadline']) || trim((string)$row['final_contact_deadline']) === '') {
            $row['final_contact_deadline'] = $row['date_to'] . ' 22:00';
        }
        }
    } else {
        $date = get_param('date', '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $row['date_ymd'] = $date;
        if (!isset($row['date_to']) || $row['date_to'] === '') { $row['date_to'] = $row['date_ymd']; }
        // 追加：最終下山連絡時刻の初期値（下山日=期間To の 22:00）
        if (!isset($row['final_contact_deadline']) || trim((string)$row['final_contact_deadline']) === '') {
            $row['final_contact_deadline'] = $row['date_to'] . ' 22:00';
        }
    }
}

$categoryOptions = ['山行計画','イベント','その他'];
$typeOptions = ['会山行','サークル','個人','単独'];
$styleOptions = ['尾根','沢','岩','山スキー','スノーシュー','氷雪'];
$statusOptions = [
    'PLANNING'       => '予定',
    'RECRUIT'        => '募集',
    'PLAN_SUBMITTED' => '提出',
    'APPROVED'       => '精査',
    'DESCENDED'      => '下山',
    'CLOSED'         => '完了',
];

// 7) 画面表示用：候補ファイル一覧（現在の日付から）
$planFiles = list_plan_files((string)$row['date_ymd']);

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>山行計画入力/修正</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",Meiryo,sans-serif; margin:12px; color:#111;}
  .title{font-size:130%; font-weight:600; text-align:center;}
  .bar{display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;}
  .bar a{text-decoration:none;}  .err{color:#c00; margin:8px 0; white-space:pre-wrap;}
  .msg{margin:8px 0; white-space:pre-wrap; padding:8px 10px; border-radius:6px;}
  .msg[data-type="ok"]{background:#e7f6e7; color:#064; border:1px solid #bfe6bf;}
  .msg[data-type="warn"]{background:#fff7d6; color:#654; border:1px solid #f0d98c;}
  .msg[data-type="error"]{background:#ffe7e7; color:#844; border:1px solid #f1b2b2;}
  label{display:block; margin-top:10px; font-weight:600;}
  input[type=text], textarea, select{width:100%; box-sizing:border-box; padding:6px 8px; font-size:14px;}
  input[type=number] {width:20%; box-sizing:border-box; padding:6px 8px; font-size:14px;}
  textarea{min-height:80px;}
  .row2{display:grid; grid-template-columns:1fr 1fr; gap:10px;}
  .btns{margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
  button{padding:8px 14px; font-size:14px;}
  .danger{border:1px solid #c00; color:#c00; background:#fff;}
  .hint{font-size:12px; color:#444; margin-top:4px;}

/* === 基本ボタン（主要フロー） === */
button.primary-btn{
  min-width:100px;
  padding:6px 16px;
  font-weight:800;
  border:2px solid #2563eb;
  background:linear-gradient(#ffffff,#e8f0ff);
  color:#1e40af;
  border-radius:8px;
  box-shadow:0 2px 6px rgba(0,0,0,.15);
}
button.primary-btn:hover{
  transform:translateY(-1px);
  box-shadow:0 4px 10px rgba(0,0,0,.2);
}

/* === 任意ボタン（補助操作） === */
button.secondary-btn{
  padding:6px 12px;
  border:1px solid #999;
  background:#f5f5f5;
  color:#333;
  border-radius:8px;
}

</style>
</head>
<body>

<div class="bar">
  <div class="title">山行一覧表の入力/修正</div>
  <div><a href="planlist.php?y=<?php echo $backY; ?>&m=<?php echo $backM; ?>">一覧へ戻る</a></div>
</div>

<form method="post" action="edit.php" id="tripForm">
  <input type="hidden" name="back_y" value="<?php echo $backY; ?>">
  <input type="hidden" name="back_m" value="<?php echo $backM; ?>">
  <input type="hidden" name="id" value="<?php echo h((string)$row['id']); ?>">
  <input type="hidden" name="action" value="save" id="actionField">
  <input type="hidden" name="send_line" value="0" id="sendLineField">
  <input type="hidden" name="confirm_resend" value="0" id="confirmResendField">
  <input type="hidden" name="confirm_resend_safety" value="0" id="confirmResendSafetyField">
  <input type="hidden" name="confirm_resend_recruit" value="0" id="confirmResendRecruitField">
  <input type="hidden" id="submitNotifiedAtVal" value="<?php echo h((string)($row['submit_notified_at'] ?? '')); ?>">
  <input type="hidden" id="safetyNotifiedAtVal" value="<?php echo h((string)($row['safety_notified_at'] ?? '')); ?>">
  <input type="hidden" id="recruitNotifiedAtVal" value="<?php echo h((string)($row['recruit_notified_at'] ?? '')); ?>">


  <div class="row2">
    <div>
      <label>期間 From（YYYY-MM-DD）</label>
      <input type="text" name="date_ymd" value="<?php echo h((string)$row['date_ymd']); ?>">
    </div>
    <div>
      <label>期間 To（YYYY-MM-DD）</label>
      <input type="text" name="date_to" value="<?php echo h((string)$row['date_to']); ?>">
    </div>
  </div>
  <label>山行名（山名）</label>
  <input type="text" name="title" value="<?php echo h((string)$row['title']); ?>">

  <div class="row2">
    <div>
      <label>項目区分</label>
      <select name="category">
        <?php foreach ($categoryOptions as $opt): ?>
          <option value="<?php echo h($opt); ?>" <?php echo ($row['category']===$opt?'selected':''); ?>><?php echo h($opt); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>管理状態</label>
      <select name="status">
        <?php foreach ($statusOptions as $k=>$label): ?>
          <option value="<?php echo h($k); ?>" <?php echo ($row['status']===$k?'selected':''); ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row2">
    <div>
      <label>山行種別</label>
      <select name="trip_type">
        <option value="">（未設定）</option>
        <?php foreach ($typeOptions as $opt): ?>
          <option value="<?php echo h($opt); ?>" <?php echo ($row['trip_type']===$opt?'selected':''); ?>><?php echo h($opt); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>山行形態</label>
      <select name="trip_style">
        <option value="">（未設定）</option>
        <?php foreach ($styleOptions as $opt): ?>
          <option value="<?php echo h($opt); ?>" <?php echo ($row['trip_style']===$opt?'selected':''); ?>><?php echo h($opt); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row2">
    <div>
      <label>リーダー</label>
      <input type="text" name="leader" value="<?php echo h((string)$row['leader']); ?>">
    </div>
    <div>
      <label>下山受担当</label>
      <input type="text" name="descent_contact" value="<?php echo h((string)$row['descent_contact']); ?>">
    
      <div style="margin-top:6px;font-size:13px;">
        <label style="display:inline;font-weight:400;">
          <input type="checkbox" name="descent_ack" value="1" <?php echo ((int)($row['descent_ack'] ?? 0)===1?'checked':''); ?>> 下山受け了承
        </label>
      </div>
</div>
    <div>
      <label>下山連絡最終時刻（手入力）</label>
      <input type="text" name="final_contact_deadline" placeholder="YYYY-MM-DD HH:MM" value="<?php echo h((string)$row['final_contact_deadline']); ?>">
      <div style="font-size:12px;color:#555;margin-top:2px;">例：2026-02-14 18:00（最終時刻を過ぎると一覧でアラーム表示）</div>
    </div>
  </div>

  <label>メンバー（自由記述）</label>
  <input type="text" name="members" value="<?php echo h((string)$row['members']); ?>">

  <label>人数（数字）</label>
  <input type="number" name="people" min="1" step="1" value="<?php echo h((string)($row['people'] ?? '')); ?>">

  <!-- 計画書：select が保存対象 -->
  <label>計画書（該当月から選択）</label>
  <select name="plan_path" id="planPathSelect">
    <option value="">（選択しない）</option>
    <?php foreach ($planFiles as $pf): ?>
      <option value="<?php echo h($pf['value']); ?>" <?php echo ($row['plan_path']===$pf['value']?'selected':''); ?>>
        <?php echo h($pf['label']); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <div style="margin-top:6px;">
    <button class="secondary-btn" type="button" id="importPlanBtn">計画書から取り込み</button>
    <span style="font-size:12px;color:#555;margin-left:6px;">※選択中の計画書（Excel）から山名・日程・種別/形態・リーダー・メンバー・下山受け・最終連絡時刻をフォームに反映します（保存はしません）</span>
  </div>

  <label>メモ（自由記述）</label>
  <textarea name="note"><?php echo h((string)$row['note']); ?></textarea>

  <div class="btns">
    <button class="primary-btn" type="submit">保存</button>
    <button class="secondary-btn" type="button" id="recruitNotifyBtn">募集</button>
    <button class="secondary-btn" type="button" id="recruitEndBtn">募集終了</button>
    <button class="primary-btn" type="button" id="submitNotifyBtn">提出</button>
    <button class="secondary-btn" type="button" id="safetyNotifyBtn">精査</button>
    <button class="secondary-btn" type="button" id="safetyEndBtn">精査終了</button>
    <button class="primary-btn" type="button" id="descendCompleteBtn">下山完了</button>
    <button class="primary-btn" type="button" id="closeBtn">終了</button>
    <a href="planlist.php?y=<?php echo $backY; ?>&m=<?php echo $backM; ?>">一覧に戻る</a>

    <?php if ((int)$row['id'] > 0): ?>
      <button type="button" class="danger" id="deleteBtn">計画抹消</button>
    <?php endif; ?>
  </div>
  <?php
    // 表示メッセージ（エラー優先）
    $msg_text = ($error !== '') ? $error : $info;
    $msg_type = ($error !== '') ? 'error' : $info_type;
?>
<div id="actionMsg" class="msg" data-type="<?php echo h($msg_type); ?>" style="margin-top:8px;<?php echo ($msg_text===''?'display:none;':''); ?>"><?php echo h($msg_text); ?></div>

</form>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
(function(){

  function showInlineMsg(type, text){
    var box = document.getElementById('actionMsg');
    if (!box) return;
    box.textContent = String(text || '');
    box.setAttribute('data-type', type || 'ok');
    box.style.display = '';
    try { box.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e) {}
  }
  function clearInlineMsg(){
    var box = document.getElementById('actionMsg');
    if (!box) return;
    box.textContent = '';
    box.removeAttribute('data-type');
    box.style.display = 'none';
  }



  // 追加: confirm() の代替（Firefox等で「このページのダイアログを抑止」されても動く）
  function confirmDialog(message, onYes, onNo) {
    var ov = document.getElementById('confirmOverlay');
    var box = document.getElementById('confirmBox');
    var msg = document.getElementById('confirmMsg');
    var yes = document.getElementById('confirmYesBtn');
    var no  = document.getElementById('confirmNoBtn');

    // フォールバック: 通常confirm（ブラウザ抑止される場合もある）
    if (!ov || !box || !msg || !yes || !no) {
      if (confirm(message)) { if (onYes) onYes(); }
      else { if (onNo) onNo(); }
      return;
    }

    msg.textContent = message;
    ov.style.display = '';

    // クリックしたボタン付近に表示（無理なら中央）
    try {
      var anchor = (window.__confirmAnchor && window.__confirmAnchor.getBoundingClientRect) ? window.__confirmAnchor : document.activeElement;
      if (anchor && anchor.getBoundingClientRect) {
        // 一旦中央に戻す
        box.style.left = '50%';
        box.style.top = '35%';
        box.style.transform = 'translate(-50%, -50%)';

        var rect = anchor.getBoundingClientRect();
        var bw = box.offsetWidth || 520;
        var bh = box.offsetHeight || 120;

        var left = rect.left + rect.width / 2 - bw / 2;
        left = Math.max(10, Math.min(left, window.innerWidth - bw - 10));

        var top = rect.bottom + 12;
        top = Math.max(10, Math.min(top, window.innerHeight - bh - 10));

        box.style.left = left + 'px';
        box.style.top = top + 'px';
        box.style.transform = 'none';
      }
    } catch (e) {}

    var cleanup = function(){
      ov.style.display = 'none';
      yes.onclick = null;
      no.onclick = null;
      window.__confirmAnchor = null;
    };
    yes.onclick = function(){ cleanup(); if (onYes) onYes(); };
    no.onclick  = function(){ cleanup(); if (onNo) onNo(); };
  }


  // 削除
  var del = document.getElementById('deleteBtn');
  if (del) {
    del.addEventListener('click', function(){
      clearInlineMsg();
      confirmDialog('この山行を削除します。よろしいですか？', function(){
        document.getElementById('actionField').value = 'delete';
        document.getElementById('tripForm').submit();
      }, function(){});
    });
  }

  // 追加：保存時にLINE送信の確認（方式A）
  var form = document.getElementById('tripForm');
  // 追加：未保存変更の検出（通知ボタンは保存後のみ）
  var dirty = false;
  if (form) {
    form.addEventListener('input', function(){ dirty = true; }, true);
    form.addEventListener('change', function(){ dirty = true; }, true);
  }

  function doNotifyAction(actionValue, notifiedValId, confirmFieldId, resendMessage, firstMessage) {
    if (!form) return;

    if (dirty) {
      showInlineMsg('warn', '保存されていない入力があります。先に［保存］してから、もう一度お試しください。');
      return;
    }

    var notifiedVal = '';
    var nv = document.getElementById(notifiedValId);
    if (nv) notifiedVal = (nv.value || '').trim();

    // 送信有無（はい=1 / いいえ=0）
    var sl = document.getElementById('sendLineField');
    if (sl) sl.value = '0';

    // 再送確認（再送=1）
    var cf = document.getElementById(confirmFieldId);
    if (cf) cf.value = '0';

    var act = document.getElementById('actionField');

    var submitNow = function(){
      if (act) act.value = actionValue;
      form.submit();
    };

    // 「同時にLINE通知するか？」を確認。いいえでもステータス更新は行う。
    var __anchor = window.__confirmAnchor;
    window.__confirmAnchor = __anchor;
    confirmDialog(firstMessage, function(){
      // はい：LINE通知も行う
      if (sl) sl.value = '1';

      // 既に送信済み → 再送するか確認
      if (notifiedVal !== '') {
        window.__confirmAnchor = __anchor;
        confirmDialog(resendMessage + '\n（最終送信: ' + notifiedVal + '）', function(){
          if (cf) cf.value = '1';
          submitNow();
        }, function(){
          // 再送しない（いいえ）→ ステータス更新のみ（LINE通知しない）
          if (sl) sl.value = '0';
          if (cf) cf.value = '0';
          submitNow();
        });
        return;
      }

      // 未送信 → そのまま送信
      submitNow();
    }, function(){
      // いいえ：LINE通知は行わず、ステータス更新のみ
      if (sl) sl.value = '0';
      if (cf) cf.value = '0';
      submitNow();
    });
  }


  // 提出通知
  var btnSubmit = document.getElementById('submitNotifyBtn');
  if (btnSubmit) {
    btnSubmit.addEventListener('click', function(){
      clearInlineMsg();
      window.__confirmAnchor = this;
      doNotifyAction('submit_notify', 'submitNotifiedAtVal', 'confirmResendField', 'この計画書提出通知は既に送信済みです。もう一度送りますか？', '同時にLINEに【提出】を通知しますか？');
    });
  }
  // 精査通知
  var btnSafety = document.getElementById('safetyNotifyBtn');
  if (btnSafety) {
    btnSafety.addEventListener('click', function(){
      clearInlineMsg();
      window.__confirmAnchor = this;
      doNotifyAction('safety_notify', 'safetyNotifiedAtVal', 'confirmResendSafetyField', 'この精査通知は既に送信済みです。もう一度送りますか？', '同時にLINEに【精査】を通知しますか？');
    });
  }
  // 募集通知
  var btnRecruit = document.getElementById('recruitNotifyBtn');
  if (btnRecruit) {
    btnRecruit.addEventListener('click', function(){
      clearInlineMsg();
      window.__confirmAnchor = this;
      doNotifyAction('recruit_notify', 'recruitNotifiedAtVal', 'confirmResendRecruitField', 'この募集通知は既に送信済みです。もう一度送りますか？', '同時にLINEに【参加募集】を通知しますか？');
    });
  }

  if (form) {
    form.addEventListener('submit', function(){
      var act = document.getElementById('actionField');
      if (!act || act.value !== 'save') return;

      var sendField = document.getElementById('sendLineField');
      if (!sendField) return;
      sendField.value = '0';
      // 以降の自動LINE送信確認は行わない（通知はボタン操作で実行）
      return;

      var cat = (form.elements['category'] ? form.elements['category'].value : '');
      var st  = (form.elements['status'] ? form.elements['status'].value : '');

      var prevStatus = <?php echo json_encode($prevStatus, JSON_UNESCAPED_UNICODE); ?>;
      var prevSubmitNotified = <?php echo json_encode($prevSubmitNotified, JSON_UNESCAPED_UNICODE); ?>;
      var prevSafetyNotified = <?php echo json_encode($prevSafetyNotified, JSON_UNESCAPED_UNICODE); ?>;

      var needSubmit = false;
      var needSafety = (cat === '山行計画' && st === 'APPROVED' && prevStatus !== 'APPROVED' && String(prevSafetyNotified).trim() === '');
      var needRecruit = (cat === '山行計画' && st === 'RECRUIT' && prevStatus !== 'RECRUIT');

      var msg = '';
      if (needSafety) {
        msg = '同時にLINEに【精査】を通知しますか？\n（キャンセルすると保存のみ行い、通知はしません）';
      } else if (needRecruit) {
        msg = '同時にLINEに【参加募集】を通知しますか？\n（キャンセルすると保存のみ行い、通知はしません）';
      }

      if (msg !== '') {
        confirmDialog(msg, function(){ sendField.value = '1'; }, function(){});
      }
    });
  }




  // 募集終了（フラグ）
  var btnRecruitEnd = document.getElementById('recruitEndBtn');
  if (btnRecruitEnd) {
    btnRecruitEnd.addEventListener('click', function(){
      clearInlineMsg();
      if (dirty) {
        showInlineMsg('warn', '保存されていない入力があります。先に［保存］してから、もう一度お試しください。');
        return;
      }
      var act = document.getElementById('actionField');
      if (act) act.value = 'recruit_end';
      form.submit();
    });
  }

  // 精査終了（フラグ）
  var btnSafetyEnd = document.getElementById('safetyEndBtn');
  if (btnSafetyEnd) {
    btnSafetyEnd.addEventListener('click', function(){
      clearInlineMsg();
      if (dirty) {
        showInlineMsg('warn', '保存されていない入力があります。先に［保存］してから、もう一度お試しください。');
        return;
      }
      var act = document.getElementById('actionField');
      if (act) act.value = 'safety_end';
      form.submit();
    });
  }

  // 下山完了（状態を下山へ）
  var btnDescend = document.getElementById('descendCompleteBtn');
  if (btnDescend) {
    btnDescend.addEventListener('click', function(){
      clearInlineMsg();
      if (dirty) {
        showInlineMsg('warn', '保存されていない入力があります。先に［保存］してから、もう一度お試しください。');
        return;
      }
      var act = document.getElementById('actionField');
      if (act) act.value = 'descend_complete';
      form.submit();
    });
  }

  // 完了（状態を完了へ）
  var btnClose = document.getElementById('closeBtn');
  if (btnClose) {
    btnClose.addEventListener('click', function(){
      clearInlineMsg();
      if (dirty) {
        showInlineMsg('warn', '保存されていない入力があります。先に［保存］してから、もう一度お試しください。');
        return;
      }
      var act = document.getElementById('actionField');
      if (act) act.value = 'close_complete';
      form.submit();
    });
  }

  // 計画書：select→確認用テキストへ反映
  var sel = document.getElementById('planPathSelect');
  var txt = document.getElementById('planPath');
  if (sel && txt) {
    sel.addEventListener('change', function(){
      txt.value = sel.value || '';
    });
  }

  // 計画書（Excel）から取り込み（ブラウザで解析してフォームへ反映）
  var importBtn = document.getElementById('importPlanBtn');
  if (importBtn) {
    function norm(s){ return (s==null?'':String(s)).replace(/\s+/g,' ').trim(); }

    function pad2(n){ n = String(n); return (n.length===1?('0'+n):n); }

    function parseYmd(v, fallbackYear){
      var s = norm(v);
      if (!s) return '';
      // YYYY-MM-DD / YYYY/MM/DD / YYYY年M月D日
      var m = s.match(/(\d{4})\s*[\/\-年]\s*(\d{1,2})\s*[\/\-月]\s*(\d{1,2})/);
      if (m) return m[1]+'-'+pad2(m[2])+'-'+pad2(m[3]);
      // M/D (fallback year)
      m = s.match(/(\d{1,2})\s*[\/\-月]\s*(\d{1,2})/);
      if (m && fallbackYear) return String(fallbackYear)+'-'+pad2(m[1])+'-'+pad2(m[2]);
      return '';
    }

    function parseHm(v){
      var s = norm(v);
      if (!s) return '';
      var m = s.match(/(\d{1,2})\s*[:：]\s*(\d{2})/);
      if (m) return pad2(m[1])+':'+m[2];
      return '';
    }

    function findRightValue(data, re){
      for (var r=0; r<data.length; r++){
        var row = data[r] || [];
        for (var c=0; c<row.length; c++){
          var cell = norm(row[c]);
          if (!cell) continue;
          if (re.test(cell)){
            // 右隣
            if (c+1 < row.length && norm(row[c+1])) return norm(row[c+1]);
            // 下
            if (r+1 < data.length && data[r+1] && norm((data[r+1]||[])[c])) return norm((data[r+1]||[])[c]);
          }
        }
      }
      return '';
    }

    function findPeriod(data, fallbackYear){
      // 期間っぽい文字列を拾って2日付に分解
      var s = findRightValue(data, /(山行期間|日程|行動予定|登山期間)/);
      if (!s) s = findRightValue(data, /(実施日|実施期間)/);
      if (!s) return {from:'', to:''};

      // "2025年12月14日～15日" 等を想定
      var parts = s.split(/[～$301C\-$2013$2014]/);
      if (parts.length >= 2){
        var from = parseYmd(parts[0], fallbackYear);
        var to = parseYmd(parts[1], fallbackYear);
        // 後半が "15日" のように年/月が省略されている場合
        if (!to && from){
          var m = norm(parts[1]).match(/(\d{1,2})\s*日/);
          if (m){
            var y = from.slice(0,4), mo = from.slice(5,7);
            to = y+'-'+mo+'-'+pad2(m[1]);
          }
        }
        return {from:from, to:to || from};
      }
      var one = parseYmd(s, fallbackYear);
      return {from:one, to:one};
    }

    

    importBtn.addEventListener('click', async function(){

      clearInlineMsg();


      try{


        if (typeof XLSX === 'undefined') {


          alert('Excel読み込みライブラリ(XLSX)が読み込めませんでした。ネットワーク環境をご確認ください。');


          return;


        }


        var sel = document.getElementById('planPathSelect');


        var url = sel ? (sel.value || '') : '';


        if (!url) { alert('計画書を選択してください。'); return; }



        async function fetchTry(u){


          return await fetch(u, {credentials:'same-origin'});


        }



        var res = await fetchTry(url);


        if (!res.ok && res.status === 404) {


          // URLエンコード違いの救済（日本語ファイル名など）


          var alt = url;


          try {


            if (url.indexOf('%') >= 0) alt = decodeURI(url);


            else alt = encodeURI(url);


          } catch(e) {}


          if (alt && alt !== url) {


            var res2 = await fetchTry(alt);


            if (res2.ok) { res = res2; url = alt; }


          }


        }



        if (!res.ok) {


          alert('計画書の取得に失敗しました。HTTP ' + res.status + "\nURL: " + url);


          return;


        }



        var buf = await res.arrayBuffer();


        var wb = XLSX.read(buf, {type:'array'});



        // 標準様式：まず「計画書」シートを優先


        var sheetName = null;
        if (wb.Sheets && wb.Sheets['計画書']) sheetName = '計画書';
        if (!sheetName && wb.SheetNames) {
          // '計画書' が無い場合は、それっぽいシート名を優先
          for (var i=0;i<wb.SheetNames.length;i++){
            var nm = wb.SheetNames[i];
            if (String(nm).indexOf('計画書')>=0) { sheetName = nm; break; }
          }
          if (!sheetName) {
            for (var i=0;i<wb.SheetNames.length;i++){
              var nm = wb.SheetNames[i];
              if (String(nm).indexOf('計画')>=0) { sheetName = nm; break; }
            }
          }
          if (!sheetName && wb.SheetNames.length) sheetName = wb.SheetNames[0];
        }


        if (!sheetName) { alert('シートが見つかりませんでした。'); return; }


        var ws = wb.Sheets[sheetName];



        // フォーム要素


        var elTitle  = document.querySelector('[name="title"]');


        var elFrom   = document.querySelector('[name="date_ymd"]');


        var elTo     = document.querySelector('[name="date_to"]');


        var elDesc   = document.querySelector('[name="descent_contact"]');


        var elFinal  = document.querySelector('[name="final_contact_deadline"]');
        var elType  = document.querySelector('[name="trip_type"]');
        var elStyle = document.querySelector('[name="trip_style"]');
        var elLeader= document.querySelector('[name="leader"]');
        var elMembers = document.querySelector('[name="members"]');



        function norm(s){ return (s==null?'':String(s)).replace(/\s+/g,' ').trim(); }


        function pad2(n){ n = String(n==null?'':n).trim(); return (n.length===1?('0'+n):n); }


        function cell(addr){


          var c = ws && ws[addr];


          if (!c || c.v == null) return '';


          return norm(c.v);


        }


        function isNumLike(s){ return /^[0-9]+$/.test(String(s).trim()); }



        
        // 取り込み用の軽い正規化（最小差分）
        function normalizeTripType(s){
          s = norm(s);
          if (!s) return '';
          if (s === '会山行') return s; // そのまま
          if (s.slice(-2) === '山行') s = s.slice(0, -2); // サークル山行→サークル 等
          return s;
        }

        function normalizeName(s){
          s = norm(s).replace(/　+/g,' ').trim(); // 全角スペース→半角
          if (!s) return '';
          // 末尾の区切り記号を除去（例: ",：" が混ざる対策）
          s = s.replace(/[,:：;；]+$/,'').trim();
          if (!s) return '';
          // 「苗字 名前」の場合は苗字だけ
          var parts = s.split(' ');
          if (parts.length >= 2 && parts[0]) return parts[0];
          return s;
        }
// 1) 標準様式の固定セル（添付例で確認）


        var y  = cell('AG2');


        var m1 = cell('AK2');


        var d1 = cell('AN2');


        var m2 = cell('AV2');


        var d2 = cell('BC2');



        var title = cell('AG3'); // 山名（標準様式）

        var tripType = cell('AG4'); // 山行種別（標準様式）
        var tripStyle = cell('AG5'); // 山行形態（標準様式）

        // AG3 がラベル等の場合は、'山名' というセルの右側から拾う
        if (!title || title === '山行形態' || title === '山名') {
          // まず AA3 が '山名' の想定だが、ズレても探す
          var found = '';
          try {
            var range = XLSX.utils.decode_range(ws['!ref'] || 'A1:BH60');
            for (var r=range.s.r; r<=Math.min(range.e.r, 20); r++){
              for (var c=range.s.c; c<=Math.min(range.e.c, 80); c++){
                var addr = XLSX.utils.encode_cell({r:r,c:c});
                var v = cell(addr);
                if (v === '山名') {
                  // 右方向で最初の非空を探す
                  for (var cc=c+1; cc<=Math.min(c+40, range.e.c); cc++){
                    var addr2 = XLSX.utils.encode_cell({r:r,c:cc});
                    var v2 = cell(addr2);
                    if (v2 && v2 !== '山行形態') { found = v2; break; }
                  }
                }
                if (found) break;
              }
              if (found) break;
            }
          } catch(e){}
          if (found) title = found;
        }


        var descent = normalizeName(cell('J7')); // 下山連絡先（氏名）

        // メンバー/役割（標準様式：B11-B23=役割, E11-E23=氏名）
        var members = [];
        var leaders = [];
        for (var r=11; r<=23; r++) {
          var role = cell('B'+r);
          var name = cell('E'+r);
          var nm = normalizeName(name);
          var rnorm = String(role||'').trim();
          rnorm = rnorm.replace(/[Ｌｌ]/g,'L').replace(/[l]/g,'L').toUpperCase();

          // 役割にLが入っていればリーダー（全角/半角/L/l混在を吸収）
          var isLeader = (nm && rnorm.indexOf('L') !== -1);

          if (nm && nm !== '℡' && nm.toUpperCase() !== 'TEL') {
            if (isLeader) leaders.push(nm);
            else members.push(nm);
          }
        }


        // リーダーが見つからない場合は先頭メンバーを暫定リーダーにする（メンバーからは除外）
        if (!leaders.length && members.length) { leaders = [members[0]]; members = members.slice(1); }


        var dy = cell('AG9') || y;


        var dm = cell('K9');


        var dd = cell('O9');


        var dh = cell('S9');


        var dmin = cell('W9');



        // 2) フォールバック（固定セルが取れない場合のみ、従来のラベル探索）


        var data = null;


        function ensureData(){


          if (data) return;


          data = XLSX.utils.sheet_to_json(ws, {header:1, raw:false});


        }


        function findRightValue(data, re){


          for (var r=0; r<data.length; r++){


            var row = data[r] || [];


            for (var c=0; c<row.length; c++){


              var v = norm(row[c]);


              if (!v) continue;


              if (re.test(v)){


                if (c+1 < row.length && norm(row[c+1])) return norm(row[c+1]);


                if (r+1 < data.length && data[r+1] && norm((data[r+1]||[])[c])) return norm((data[r+1]||[])[c]);


              }


            }


          }


          return '';


        }



        // 山名


        if ((!title || title === '山行形態') ) {


          ensureData();


          title = findRightValue(data, /(山行名|山行名称|山名|目的地)/);


        }


        if (title && elTitle) elTitle.value = title;

        // 山行種別/山行形態（プルダウンに同じ値がある前提）
        if (tripType && elType) elType.value = normalizeTripType(tripType);
        if (tripStyle && elStyle) elStyle.value = tripStyle;

        // リーダー（役割L）
        if (leaders.length && elLeader) elLeader.value = leaders.join('、');

        // メンバー（全角、区切り）
        if (members.length && elMembers) elMembers.value = members.join('、');



        // 日程


        function mkDate(yy, mm, dd){


          if (!isNumLike(yy) || !isNumLike(mm) || !isNumLike(dd)) return '';


          return yy + '-' + pad2(mm) + '-' + pad2(dd);


        }


        var from = mkDate(y, m1, d1);


        var to   = mkDate(y, m2, d2);


        if (from && elFrom) elFrom.value = from;


        if (to && elTo) elTo.value = to;


        if (from && !to && elTo) elTo.value = from;



        // 下山受け


        if (!descent || descent === '℡') {


          ensureData();


          descent = findRightValue(data, /(下山(連絡|受け|受付|担当)|下山連絡先)/);


          if (descent === '℡') descent = '';


        }


        if (descent && elDesc) elDesc.value = descent;



        // 最終連絡時刻


        function mkDeadline(yy, mm, dd, hh, mi){


          if (!isNumLike(yy) || !isNumLike(mm) || !isNumLike(dd) || !isNumLike(hh)) return '';


          if (!isNumLike(mi)) mi = '00';


          return yy + '-' + pad2(mm) + '-' + pad2(dd) + ' ' + pad2(hh) + ':' + pad2(mi);


        }


        var deadline = mkDeadline(dy, dm, dd, dh, dmin);


        if (deadline && elFinal) elFinal.value = deadline;



        alert('取り込みが完了しました。内容を確認して保存してください。');


      } catch(e) {


        console.error(e);


        alert('取り込みに失敗しました。(' + (e && e.message ? e.message : e) + ')');


      }


    });
  }

})();
</script>


<!-- 追加: confirm() がブラウザ設定で抑止される場合に備えた簡易ダイアログ -->
<div id="confirmOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div id="confirmBox" style="max-width:520px; background:#fff; border-radius:10px; padding:14px 16px; box-shadow:0 8px 24px rgba(0,0,0,.25); position:fixed; left:50%; top:35%; transform:translate(-50%, -50%);">
    <div id="confirmMsg" style="white-space:pre-wrap; font-size:14px; line-height:1.5; margin-bottom:12px;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button class="secondary-btn" type="button" id="confirmNoBtn">いいえ</button>
      <button class="secondary-btn" type="button" id="confirmYesBtn">はい</button>
    </div>
  </div>
</div>

</body>
</html>