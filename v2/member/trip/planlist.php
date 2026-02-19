<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dbPath = __DIR__ . '/trip_manage.db';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ymd(int $y,int $m,int $d): string { return sprintf('%04d-%02d-%02d',$y,$m,$d); }

function wday_jp(string $ymd): string {
  // $ymd: YYYY-MM-DD
  $ts = strtotime($ymd);
  $w = (int)date('w', $ts); // 0=Sun
  $map = ['日','月','火','水','木','金','土'];
  return $map[$w] ?? '';
}

function csv_bom_header(string $filename): void {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  // Excel対策（UTF-8 BOM）
  echo "\xEF\xBB\xBF";
}

try {
  $pdo=new PDO('sqlite:'.$dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  // 追加：人数カラムが無ければ追加（既存DBのまま機能追加できるように）
  try{
    $cols=$pdo->query("PRAGMA table_info(trips)")->fetchAll(PDO::FETCH_ASSOC);
    $has=false;
    foreach($cols as $c){ if(isset($c['name']) && $c['name']==='people'){ $has=true; break; } }
    if(!$has){ $pdo->exec("ALTER TABLE trips ADD COLUMN people INTEGER"); }

    // 追加：下山連絡最終時刻カラムが無ければ追加
    $hasD=false;
    foreach($cols as $c){ if(isset($c['name']) && $c['name']==='final_contact_deadline'){ $hasD=true; break; } }
    if(!$hasD){ $pdo->exec("ALTER TABLE trips ADD COLUMN final_contact_deadline TEXT"); }


    // 追加：安全確認通知（重複防止）カラムが無ければ追加
    $hasSafetyN=false;
    foreach($cols as $c){ if(isset($c['name']) && $c['name']==='safety_notified_at'){ $hasSafetyN=true; break; } }
    if(!$hasSafetyN){ $pdo->exec("ALTER TABLE trips ADD COLUMN safety_notified_at TEXT"); }

    // 追加：最終連絡超過通知（重複防止）カラムが無ければ追加
    $hasAlarmN=false;
    foreach($cols as $c){ if(isset($c['name']) && $c['name']==='alarm_notified_at'){ $hasAlarmN=true; break; } }
    if(!$hasAlarmN){ $pdo->exec("ALTER TABLE trips ADD COLUMN alarm_notified_at TEXT"); }

  }catch(Throwable $e){
    // 失敗しても致命ではないため握りつぶす
  }


  $y=isset($_GET['y'])?(int)$_GET['y']:(int)date('Y');
  $m=isset($_GET['m'])?(int)$_GET['m']:(int)date('n');
  if($y<2000||$y>2100)$y=(int)date('Y');
  if($m<1||$m>12)$m=(int)date('n');


  // --- 下山受け了承／下山確認（一覧から） ---
  // DB列（無い前提）：descent_ack_at を追加（受け了承時刻。表示には使わない）
  try{
    $has = $pdo->query("SELECT 1 FROM pragma_table_info('trips') WHERE name='descent_ack_at'")->fetchColumn();
    if(!$has){
      $pdo->exec("ALTER TABLE trips ADD COLUMN descent_ack_at TEXT");
    }
  }catch(Throwable $e){
    // 失敗しても致命ではない（旧DBのまま動かす）
  }

  // 監査ログ（TSV）
  function trip_event_log(string $title, string $action, string $label, string $from, string $to, string $extra=''): void{
    $ts = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = mb_substr((string)$ua, 0, 120, 'UTF-8');
    $line = $ts."\t".
            "title=".$title."\t".
            "action=".$action."\t".
            "label=".$label."\t".
            "status=".$from."->".$to."\t".
            "ip=".$ip."\t".
            "ua=".$ua;
    if($extra!=='') $line .= "\t".$extra;
    $line .= "\n";
    $path = __DIR__ . '/trip_event.log';
    $fp = @fopen($path, 'ab');
    if($fp){
      @flock($fp, LOCK_EX);
      @fwrite($fp, $line);
      @flock($fp, LOCK_UN);
      @fclose($fp);
    }
  }

  // 下山受け了承（提出/精査の計画に対して）
  if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='descent_ack'){
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $backY = isset($_POST['back_y']) ? (int)$_POST['back_y'] : $y;
    $backM = isset($_POST['back_m']) ? (int)$_POST['back_m'] : $m;

    $ok=false; $msg='下山受け承知済'; $can_confirm=false;

    try{
      if($id>0){
        $st0=''; $title='';
        $q = $pdo->prepare("SELECT id,title,status,date_ymd,date_to,descent_ack FROM trips WHERE id=:id");
        $q->execute([':id'=>$id]);
        $r0 = $q->fetch(PDO::FETCH_ASSOC);
        if($r0){
          $title = (string)($r0['title'] ?? '');
          $st0 = (string)($r0['status'] ?? '');
          $ack0 = (int)($r0['descent_ack'] ?? 0);

          if($ack0!==1){
            $now = date('Y-m-d H:i:s');
            $up = $pdo->prepare("UPDATE trips SET descent_ack=1, descent_ack_at=:t, updated_at=:u WHERE id=:id");
            $up->execute([':t'=>$now, ':u'=>$now, ':id'=>$id]);
            trip_event_log($title, 'descent_ack', '下山受け了承', $st0, $st0, 'id='.$id);
          }
          $ok=true;

          // 当日なら「下山確認」ボタンを出す（提出/精査のみ）
          $d1 = (string)($r0['date_ymd'] ?? '');
          $d2 = (string)($r0['date_to'] ?? '');
          if($d2==='') $d2=$d1;
          $today = date('Y-m-d');
          if($d1!=='' && $today>=$d1 && $today<=$d2 && ($st0==='PLAN_SUBMITTED' || $st0==='APPROVED')){
            $can_confirm=true;
          }
        }
      }
    }catch(Throwable $e){
      $ok=false;
    }

    if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH']==='XMLHttpRequest'){
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>$ok,'msg'=>$msg,'can_confirm'=>$can_confirm], JSON_UNESCAPED_UNICODE);
      exit;
    }
    header('Location: ?y='.$backY.'&m='.$backM.'&msg='.rawurlencode($msg));
    exit;
  }

  // 下山確認（一覧から確定）- ボタン表示側で当日制御
  if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='descend_confirm'){
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $backY = isset($_POST['back_y']) ? (int)$_POST['back_y'] : $y;
    $backM = isset($_POST['back_m']) ? (int)$_POST['back_m'] : $m;

    $ok=false; $msg='下山確認を記録しました'; $hm='';

    try{
      if($id>0){
        $q = $pdo->prepare("SELECT id,title,status FROM trips WHERE id=:id");
        $q->execute([':id'=>$id]);
        $r0 = $q->fetch(PDO::FETCH_ASSOC);
        if($r0){
          $title = (string)($r0['title'] ?? '');
          $st0 = (string)($r0['status'] ?? '');
          if($st0!=='DESCENDED'){
            $now = date('Y-m-d H:i:s');
            $up = $pdo->prepare("UPDATE trips SET status='DESCENDED', descended_at=:t, updated_at=:u WHERE id=:id");
            $up->execute([':t'=>$now, ':u'=>$now, ':id'=>$id]);
            $hm = date('H:i', strtotime($now));
            trip_event_log($title, 'descend_confirm', '下山確認', $st0, 'DESCENDED', 'id='.$id);
          }else{
            $ok=true;
          }
          $ok=true;
        }
      }
    }catch(Throwable $e){
      $ok=false;
    }

    if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH']==='XMLHttpRequest'){
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>$ok,'msg'=>$msg,'hm'=>$hm], JSON_UNESCAPED_UNICODE);
      exit;
    }
    header('Location: ?y='.$backY.'&m='.$backM.'&msg='.rawurlencode($msg));
    exit;
  }

  $first=new DateTime(sprintf('%04d-%02d-01',$y,$m));
  $daysInMonth=(int)$first->format('t');

  $start=ymd($y,$m,1);
  $end=ymd($y,$m,$daysInMonth);

  $hasRecruit = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='trip_recruit_join'")->fetchColumn();

  if($hasRecruit){
    $stmt=$pdo->prepare("SELECT t.*, COALESCE(r.cnt,0) AS recruit_cnt, COALESCE(r.names,'') AS recruit_names FROM trips t LEFT JOIN (SELECT trip_id, COUNT(*) cnt, group_concat(display_name,'、') names FROM trip_recruit_join GROUP BY trip_id) r ON r.trip_id=t.id WHERE t.date_ymd BETWEEN :s AND :e ORDER BY t.date_ymd,t.id");
  }else{
    $stmt=$pdo->prepare("SELECT * FROM trips WHERE date_ymd BETWEEN :s AND :e ORDER BY date_ymd,id");
  }
  $stmt->execute([':s'=>$start,':e'=>$end]);
  $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

  // ===== CSV出力（省力化） =====
  if(isset($_GET['export']) && $_GET['export']==='tansin'){
    // 山行短信（当月）: status=CLOSED のみ（必要なら条件を調整）
    csv_bom_header(sprintf('%04d%02d_山行短信.csv',$y,$m));
    $out=fopen('php://output','w');
    fputcsv($out, ['日付','曜日','種別','形態','山名','リーダー','メンバー','下山受け']);

    foreach($rows as $r){
      if((string)($r['status'] ?? '')==='PLANNING') continue;
      if((string)($r['status'] ?? '')==='RECRUIT') continue;
      if((string)($r['category'] ?? '')!=='山行計画') continue;
      $d1=(string)$r['date_ymd'];
      $d2=isset($r['date_to']) && $r['date_to']!=='' ? (string)$r['date_to'] : $d1;

      // 日付表現
      $d1Y=(int)substr($d1,0,4); $d1M=(int)substr($d1,5,2); $d1D=(int)substr($d1,8,2);
      $d2Y=(int)substr($d2,0,4); $d2M=(int)substr($d2,5,2); $d2D=(int)substr($d2,8,2);

      if($d2===$d1){
        $dateCell=(string)$d1D;
        $wCell=wday_jp($d1);
      }else{
        if($d1Y===$d2Y && $d1M===$d2M){
          $dateCell=$d1D.'～'.$d2D;
          $wCell=wday_jp($d1).'～'.wday_jp($d2);
        }else{
          // 月跨ぎ: 例）30～\n2/1
          $dateCell=$d1D.'～' . "\n" . $d2M.'/'.$d2D;
          $wCell=wday_jp($d1).'～'.wday_jp($d2);
        }
      }

      fputcsv($out, [
        $dateCell,
        $wCell,
        (string)($r['trip_type'] ?? ''),
        (string)($r['trip_style'] ?? ''),
        (string)($r['title'] ?? ''),
        (string)($r['leader'] ?? ''),
        (string)($r['members'] ?? ''),
        (string)($r['descent_contact'] ?? ''),
      ]);
    }
    fclose($out);
    exit;
  }

  if(isset($_GET['export']) && $_GET['export']==='sanko'){
    // 「これまでに登った山」用（年次）: status=CLOSED に加えて PLAN_SUBMITTED / APPROVED / DESCENDED も対象
    $ystart=sprintf('%04d-01-01',$y);
    $yend=sprintf('%04d-12-31',$y);
    $stmt2=$pdo->prepare("SELECT * FROM trips WHERE date_ymd BETWEEN :s AND :e AND status IN ('CLOSED','PLAN_SUBMITTED','APPROVED','DESCENDED') AND category='山行計画' ORDER BY date_ymd,id");
    $stmt2->execute([':s'=>$ystart,':e'=>$yend]);
    $rows2=$stmt2->fetchAll(PDO::FETCH_ASSOC);

    csv_bom_header(sprintf('sanko%04d.csv',$y));
    $out=fopen('php://output','w');
    fputcsv($out, ['月','山名','人数','形態','URL']);

    foreach($rows2 as $r){
      $d1=(string)$r['date_ymd'];
      $mm=(int)substr($d1,5,2);

      $p=$r['people'] ?? '';
      if($p===null || $p===''){
        $peopleCell='';
      }elseif(is_numeric($p)){
        $peopleCell=(string)((int)$p).'人';
      }else{
        $peopleCell=(string)$p;
      }

      fputcsv($out, [
        $mm,
        (string)($r['title'] ?? ''),
        $peopleCell,
        (string)($r['trip_style'] ?? ''),
        '', // URL: 記事URL等がある場合はここに出力
      ]);
    }
    fclose($out);
    exit;
  }

  $byDate=[];
  foreach($rows as $r){ $byDate[$r['date_ymd']][]=$r; }

  $prev=(clone $first)->modify('-1 month');
  $next=(clone $first)->modify('+1 month');

}catch(Throwable $e){
  echo "<pre>ERROR: ".h($e->getMessage())."</pre>";
  exit;
}

function status_badge(string $st):string{
  $map=[
    'PLANNING'=>'予定',
    'RECRUIT'=>'募集',
    'PLAN_SUBMITTED'=>'提出',
    'CLIMBING'=>'登山中',
    'APPROVED'=>'精査',
    'DESCENDED'=>'下山',
    'CLOSED'=>'終了',
  ];
    $label = $map[$st] ?? $st;
  $cls = 'st-'.preg_replace('/[^A-Z0-9_]/', '', $st);
  return '<span class="badge '.$cls.'">'.h($label).'</span>';
}


function catcls(string $c):string{
  if($c==='イベント')return 'cat-event';
  if($c==='その他')return 'cat-other';
  return 'cat-plan';
}


function fmt_deadline(string $dl, string $baseDate): string{
  // $dl: 'YYYY-MM-DD HH:MM' を想定。空なら空。
  if($dl==='') return '';
  $ts=strtotime($dl);
  if($ts===false) return $dl; // 形式が想定外ならそのまま出す（最小差分）
  $d=date('Y-m-d',$ts);
  if($d===$baseDate){
    return date('H:i',$ts);
  }
  return date('n/j H:i',$ts);
}

function is_over_deadline(string $status, string $dl): bool{
  if($status!=='PLAN_SUBMITTED') return false; // 「提出」状態のみ監視
  if($dl==='') return false;
  $ts=strtotime($dl);
  if($ts===false) return false;
  return time() > $ts;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>山行一覧表</title>
<style>
body{font-family:system-ui,Meiryo,sans-serif;margin:12px;}
.topbar{display:flex;justify-content:space-between;margin-bottom:8px;}
table{border-collapse:collapse;width:100%;}
th,td{border:1px solid #ccc;padding:6px 8px;font-size:14px;vertical-align:top;}
th{background:#f3f3f3;}
.daycell{width:50px;text-align:center;}
.w-sun{color:#c00;}
.w-sat{color:#06c;}

.item{padding:6px;border-left:6px solid transparent;border-radius:6px;}
.item+.item{border-top:1px dashed #bbb;margin-top:6px;padding-top:8px;}

.line1{display:flex;gap:8px;flex-wrap:wrap;align-items:baseline;}
.line2{font-size:14px;color:#111;margin-top:2px;}
.note{font-size:12px;color:#444;margin-top:2px;white-space:pre-wrap;}

.badge{border:1px solid #666;border-radius:999px;padding:1px 6px;font-size:12px;background:#fff;}

/* 状態ごとの色分け（バッジ） */
.badge.st-PLANNING{background:#f5f5f5;border-color:#888;}
.badge.st-RECRUIT{background:#dbeafe;border-color:#2563eb; font-weight:700;}
.badge.st-PLAN_SUBMITTED{background:#f3f0ff;border-color:#6b46c1;}
.badge.st-APPROVED{background:#fff3e0;border-color:#d97706;}
.badge.st-DESCENDED{background:#fffbe6;border-color:#b7791f;}
.badge.st-CLOSED{background:#e8fff0;border-color:#2f855a;}
.badge.st-CAT{background:#f0f0f0;border-color:#888;}

/* アラーム（最終連絡超過）: 行の背景と強調 */
.item.alarm{background:#fff1f2 !important; border-left-color:#e11d48 !important;}
.item.alarm .badge{border-color:#e11d48;}
.alarmtext{color:#b00020;font-weight:700;}

/* 募集：カード全体で識別（アラームより弱い） */
.item.recruit{
  background:#eef7f5;
  border-left-color:#1b8f7a;
}


.cat-plan{border-left-color:#1e6bd6;background:#f6f9ff;}
.cat-event{border-left-color:#d67c1e;background:#fff8f1;}
.cat-other{border-left-color:#666;background:#f7f7f7;}

.recruitcnt{margin-left:6px;font-size:12px;white-space:nowrap;}

/* 下山受け了承／下山確認（一覧での受付操作） */
form.ackform, form.confirmform{display:inline-block;margin:2px 0;}
button.ackbtn{
  padding:4px 12px;
  border:1px solid #2f855a;
  border-radius:999px;
  background:linear-gradient(#ffffff,#e6fff2);
  color:#2f855a;
  font-weight:800;
  cursor:pointer;

  box-shadow:
    0 1px 0 rgba(255,255,255,.9) inset,
    0 2px 6px rgba(0,0,0,.15);

  transition:all .15s ease;
}

button.ackbtn:hover{
  transform:translateY(-1px);
  box-shadow:
    0 1px 0 rgba(255,255,255,.9) inset,
    0 4px 10px rgba(0,0,0,.18);
}

button.ackbtn:active{
  transform:translateY(0);
  box-shadow:
    0 1px 0 rgba(255,255,255,.8) inset,
    0 1px 3px rgba(0,0,0,.15);
}

button.ackbtn:focus-visible{
  outline:3px solid rgba(0,120,255,.35);
  outline-offset:2px;
}

button.ackbtn:disabled{
  opacity:.6;
  cursor:default;
}
.ack-ok{color:#2f855a;font-weight:800;}
.descended-ok{color:#2f855a;font-weight:800;}
.flash{background:#fff7cc;}
</style>
</head>
<body>
<div id="lineStatus" style="display:none; margin:8px 0; padding:8px 10px; border:1px solid #ccc; background:#f7f7f7; border-radius:6px; font-size:14px;"></div>
<div class="topbar">
  <div>
    <a href="?y=<?=$prev->format('Y')?>&m=<?=$prev->format('n')?>">&lt;前月</a>
    <strong style="font-size:140%;"><?=$y?>年<?=$m?>月</strong>
    <a href="?y=<?=$next->format('Y')?>&m=<?=$next->format('n')?>">次月&gt;</a>
    <span style="margin-left:12px; font-size:90%;">
      <a href="?y=<?=$y?>&m=<?=$m?>&export=tansin">短信CSV</a>
      |
      <a href="?y=<?=$y?>&export=sanko">sanko<?=$y?>.csv</a>
    </span>
  </div>

  <div>
    <a href="edit.php?mode=new&date=<?=h(sprintf('%04d-%02d-01',$y,$m))?>&back_y=<?=$y?>&back_m=<?=$m?>"
       style="display:inline-block;padding:6px 10px;border:1px solid #666;border-radius:6px;text-decoration:none;background:#fafafa;">
      新規登録
    </a>
  </div>
</div>

<table>
<thead><tr><th class="daycell">日</th><th>登山計画など</th></tr></thead>
<tbody>
<?php
for($d=1;$d<=$daysInMonth;$d++){
  $date=ymd($y,$m,$d);
  $dt=new DateTime($date);
  $w=(int)$dt->format('w');
  $wlabel=['日','月','火','水','木','金','土'][$w];
  $wcls=$w===0?'w-sun':($w===6?'w-sat':'');

  echo "<tr>";
  echo '<td class="daycell '.$wcls.'"><a href="edit.php?mode=new&date='.h($date).'&back_y='.$y.'&back_m='.$m.'">'.$d.'</a><br>'.$wlabel.'</td>';
  echo '<td>';

  if(!empty($byDate[$date])){
    foreach($byDate[$date] as $r){
      $dl = isset($r['final_contact_deadline']) ? (string)$r['final_contact_deadline'] : '';
      $over = is_over_deadline((string)$r['status'], $dl);
      echo '<div id="trip-'.h((string)$r['id']).'" class="item '.catcls($r['category']).(($r['status']==='RECRUIT')?' recruit':'').($over?' alarm':'').'">';

      // 1行目
      echo '<div class="line1">';
      $cat = (string)($r['category'] ?? '');
      $stDisp = (string)($r['status'] ?? '');
      // 提出のまま当日（期間内）なら表示だけ「登山中」
      $d1s = (string)($r['date_ymd'] ?? '');
      $d2s = (isset($r['date_to']) && $r['date_to'] !== '') ? (string)$r['date_to'] : $d1s;
      $todayS = date('Y-m-d');
      if($stDisp==='PLAN_SUBMITTED' && $d1s!=='' && $todayS>=$d1s && $todayS<=$d2s){
        $stDisp='CLIMBING';
      }
      if($cat==='イベント' || $cat==='その他'){
        echo '<span class="badge st-CAT">'.h($cat).'</span>';
      }else{
        echo status_badge($stDisp);
      }

      // 追加：募集の参加希望数（参加希望のみカウント）
      if((string)($r['status'] ?? '')==='RECRUIT'){
        $cnt = (int)($r['recruit_cnt'] ?? 0);
        if($cnt>0){
          $names = (string)($r['recruit_names'] ?? '');
          $title = $names!=='' ? ' title="'.h('参加希望: '.$names).'"' : '';
          echo '<span class="recruitcnt"'.$title.'>参加希望'.$cnt.'名</span>';
        }
      }
      $t = (string)$r['title'];
      $d1 = (string)$r['date_ymd'];
      $d2 = isset($r['date_to']) && $r['date_to'] !== '' ? (string)$r['date_to'] : $d1;
      echo '<strong>'.h($t).'</strong>';
      if($d2 !== $d1){
        // 例）25日～26日 → 「※26日下山」
        $endY = (int)substr($d2, 0, 4);
        $endM = (int)substr($d2, 5, 2);
        $endD = (int)substr($d2, 8, 2);
        if ($endY !== (int)substr($d1, 0, 4) || $endM !== (int)substr($d1, 5, 2)) {
          echo '<span class="multi">※'.h((string)$endM).'月'.h((string)$endD).'日下山</span>';
        } else {
          echo '<span class="multi">※'.h((string)$endD).'日下山</span>';
        }
      }

      if($r['trip_type']||$r['trip_style']){
        echo '<span>['.h(trim($r['trip_type'].'・'.$r['trip_style'])).']</span>';
        // 計画書リンクは山行形態の右横に表示
        if($r['plan_path']) echo '&nbsp;&nbsp;<a href="download_plan.php?id='.(int)$r['id'].'" target="_blank">計画書</a>';
      }
      echo '<span style="margin-left:auto;">';
      if((string)($r['status'] ?? '') !== 'DESCENDED'){
        echo '&nbsp;&nbsp;';
      }
      echo '<a href="edit.php?mode=edit&id='.(int)$r['id'].'&back_y='.$y.'&back_m='.$m.'">修正</a></span>';
      echo '</div>';

      // 2行目以降
      $line2a=[];
      if($r['leader']) $line2a[] = 'L:'.h((string)$r['leader']);

      $mem = (string)($r['members'] ?? '');
      $people = isset($r['people']) && (string)$r['people'] !== '' ? (string)$r['people'] : '';
      if($mem !== ''){
        if($people !== '') $mem .= '（計'.$people.'人）'; // メンバー末尾に人数を付与
        $line2a[] = 'M:'.h($mem);
      } elseif ($people !== '') {
        // メンバー未入力でも人数だけ入っているケース
        $line2a[] = 'M:（計'.$people.'人）';
      }

      $line2b=[];
      if($r['descent_contact']) $line2b[] = '（下山:'.h((string)$r['descent_contact']).'）';

// 追加：下山受け了承（提出された計画に対して）
$st = (string)($r['status'] ?? '');
if($st==='PLAN_SUBMITTED' || $st==='APPROVED' || $st==='DESCENDED'){
  if($r['descent_contact']){
    // 下山済みなら確認時刻を表示
    if($st==='DESCENDED' && !empty($r['descended_at'])){
      $hm = date('H:i', strtotime((string)$r['descended_at']));
      $line2b[] = '<span class="descended-ok">'.$hm.'下山確認済</span>';
    }else{
      $ack = (int)($r['descent_ack'] ?? 0);
      if($ack!==1){
        // 未受け了承：ボタン
        $line2b[] = '<form class="ackform" method="post" action="" style="display:inline;">'
                  . '<input type="hidden" name="action" value="descent_ack">'
                  . '<input type="hidden" name="id" value="'.h((string)$r['id']).'">'
                  . '<input type="hidden" name="back_y" value="'.h((string)$y).'">'
                  . '<input type="hidden" name="back_m" value="'.h((string)$m).'">'
                  . '<button class="ackbtn" type="submit" onclick="return confirm(\'下山受けを承知します。よろしいですか？\')">下山受け了承</button>'
                  . '</form>';
      }else{
        // 受け了承済：メッセージ＋当日なら下山確認ボタン
        $line2b[] = '<span class="ack-ok">下山受け承知済</span>';

        $d1 = (string)($r['date_ymd'] ?? '');
        $d2 = isset($r['date_to']) && $r['date_to']!=='' ? (string)$r['date_to'] : $d1;
        $today = date('Y-m-d');
        if($d1!=='' && $today>=$d1 && $today<=$d2 && ($st==='PLAN_SUBMITTED' || $st==='APPROVED')){
          $line2b[] = '<form class="confirmform" method="post" action="" style="display:inline;">'
                    . '<input type="hidden" name="action" value="descend_confirm">'
                    . '<input type="hidden" name="id" value="'.h((string)$r['id']).'">'
                    . '<input type="hidden" name="back_y" value="'.h((string)$y).'">'
                    . '<input type="hidden" name="back_m" value="'.h((string)$m).'">'
                    . '<button class="ackbtn" type="submit" onclick="return confirm(\'下山確認を記録します。よろしいですか？\')">下山確認</button>'
                    . '</form>';
        }
      }
    }
  }
}

       if($dl!=='') array_unshift($line2b, '（最終連絡:'.h(fmt_deadline($dl,(string)$r['date_ymd'])).'）');

      $line2='';
      if(count($line2a)===2){
        // リーダーとメンバーの間だけ余白を広めに
        $line2 = $line2a[0].'&nbsp;&nbsp;'.$line2a[1];
      } elseif(count($line2a)===1){
        $line2 = $line2a[0];
      }
      if(!empty($line2b)){
        $line2 = ($line2!=='' ? $line2.' ' : '').implode(' ',$line2b);
      }

      if($line2!==''){
        echo '<div class="line2">'.$line2.'</div>';
      }
      if($over){
        echo '<div class="line2 alarmtext">&#x26A0; 最終連絡時刻を過ぎています</div>';
      }

      // 追加：下山当日の「下山連絡待ち」表示（提出以降・未下山のみ）
      $st2 = (string)($r['status'] ?? '');
      $today = date('Y-m-d');
      if((string)($r['date_ymd'] ?? '') !== '' && $today>=(string)($r['date_ymd'] ?? '') && $today<=((isset($r['date_to']) && $r['date_to']!=='')?(string)$r['date_to']:(string)($r['date_ymd'] ?? '')) && ($st2==='PLAN_SUBMITTED' || $st2==='APPROVED')){
        echo '<div class="line2 waittext">下山連絡待ちです</div>';
      }

      if($r['note']){
        echo '<div class="note">'.h($r['note']).'</div>';
      }

      echo '</div>';
    }
  }

  echo "</td></tr>";
}
?>
</tbody>
</table>

<script>
(function(){
  function ajaxify(selector, onSuccess, onFail){
    var forms = document.querySelectorAll(selector);
    if(!forms || !forms.forEach) return;
    forms.forEach(function(form){
      form.addEventListener('submit', function(ev){
        if(!window.fetch) return;
        ev.preventDefault();
        var btn = form.querySelector('button');
        if(btn) btn.disabled = true;
        var fd = new FormData(form);
        fetch(form.getAttribute('action') || location.href, {
          method: 'POST',
          body: fd,
          headers: {'X-Requested-With':'XMLHttpRequest'}
        }).then(function(res){ return res.json(); })
          .then(function(j){
            if(!j || !j.ok) throw new Error('bad');
            onSuccess(form, fd, j);
          }).catch(function(){
            if(btn) btn.disabled = false;
            if(onFail) onFail(form, fd);
          });
      });
    });
  }

  function flashItem(id){
    var item = id ? document.getElementById('trip-' + id) : null;
    if(item){
      item.classList.add('flash');
      setTimeout(function(){ item.classList.remove('flash'); }, 700);
    }
    return item;
  }

  // 下山受け了承：ボタン→「下山受けを承知しました」へ、その場更新。必要なら下山確認ボタンも追加。
  ajaxify('form.ackform', function(form, fd, j){
    var id = fd.get('id');
    var item = flashItem(id);

    // 受け了承メッセージに差し替え
    var span = document.createElement('span');
    span.className = 'ack-ok';
    span.textContent = '下山受け承知済';
    form.replaceWith(span);

    // 当日なら「下山確認」ボタンを追加
    if(j.can_confirm && item){
      // すでに存在する場合は追加しない
      if(!item.querySelector('form.confirmform')){
        var f = document.createElement('form');
        f.className = 'confirmform';
        f.method = 'post';
        f.action = '';
        f.style.display = 'inline';

        function hidden(name,val){
          var i=document.createElement('input');
          i.type='hidden'; i.name=name; i.value=val;
          return i;
        }
        f.appendChild(hidden('action','descend_confirm'));
        f.appendChild(hidden('id', id));
        f.appendChild(hidden('back_y', fd.get('back_y') || ''));
        f.appendChild(hidden('back_m', fd.get('back_m') || ''));

        var b = document.createElement('button');
        b.className='ackbtn';
        b.type='submit';
        b.textContent='下山確認';
        b.onclick=function(){ return confirm('下山確認を記録します。よろしいですか？'); };
        f.appendChild(b);

        // span の右側に追加
        span.insertAdjacentElement('afterend', f);

        // 追加したフォームにもAJAXを付ける（1個だけなので直付け）
        f.addEventListener('submit', function(ev){
          if(!window.fetch) return;
          ev.preventDefault();
          b.disabled=true;
          var fd2 = new FormData(f);
          fetch(f.getAttribute('action') || location.href, {
            method:'POST', body:fd2, headers:{'X-Requested-With':'XMLHttpRequest'}
          }).then(function(r){return r.json();}).then(function(j2){
            if(!j2 || !j2.ok) throw new Error('bad');
            // confirm成功処理（下の関数を使う）
            onConfirmSuccess(f, fd2, j2);
          }).catch(function(){ b.disabled=false; });
        });
      }
    }
  });

  function setStatusDescended(item){
    if(!item) return;
    var badge = item.querySelector('.badge');
    if(badge){
      badge.className = 'badge st-DESCENDED';
      badge.textContent = '下山';
    }
    // recruit/alarm の補助クラスを外す
    item.classList.remove('recruit');
    item.classList.remove('alarm');
  }

  function onConfirmSuccess(form, fd, j){
    var id = fd.get('id');
    var item = flashItem(id);
    if(item){
      var hm = j.hm || '';
      var t = hm ? (hm + '下山確認済') : '下山確認済';

            // アラーム／待ち表示は不要になるため消す
      var alarm = item.querySelector('.alarmtext');
      if(alarm) alarm.remove();
      var waiting = item.querySelector('.waittext');
      if(waiting) waiting.remove();

      // 「下山受け承知済」を上書き（同じ行でテイスト統一）
      var ack = item.querySelector('.ack-ok');
      if(ack){
        ack.className = 'descended-ok';
        ack.textContent = t;
      }else{
        var div = document.createElement('span');
        div.className = 'descended-ok';
        div.textContent = t;
        form.replaceWith(div);
        return;
      }

      // 下山確認ボタンは消す
      form.remove();

      // ステータス表示を下山に変更
      setStatusDescended(item);
    }
  }

  // 下山確認：メッセージを上書き＋ステータスも更新
  ajaxify('form.confirmform', onConfirmSuccess);

})();
</script>

<iframe name="bg_line_send" style="display:none;"></iframe>
</body>
</html>