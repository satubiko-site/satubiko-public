<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../_inc/utf8_bootstrap.php';
header('Content-Type: text/html; charset=UTF-8');
// smaho/post.php 最終版（CSV＋静的HTML、DB不使用）
//declare(strict_types=1);

// ===== 設定 =====
define('NEXT_ID_PATH', __DIR__ . '/data/next_id.txt');
const PUBLIC_BASE           = '/smaho';                 // 例: '/renew'（ルート直下なら ''）
const DATA_DIR              = __DIR__ . '/data';
const CSV_PATH              = DATA_DIR . '/smaho.csv';
const UPLOAD_DIR            = DATA_DIR . '/uploads';
const ARTICLES_DIR          = __DIR__ . '/articles';
const PUBLIC_UPLOAD_BASE    = PUBLIC_BASE . '/data/uploads';
const PUBLIC_ARTICLES_BASE  = PUBLIC_BASE . '/articles';
const TEMP_UPLOAD_DIR       = DATA_DIR . '/tmp';
const PUBLIC_TEMP_UPLOAD_BASE = PUBLIC_BASE . '/data/tmp';
const MAX_UPLOAD_BYTES      = 8 * 1024 * 1024;         // 1ファイル8MB
const MAX_UPLOAD_FILECOUNT  = 12;                      // 最大12枚
const SUBMIT_PASSWORD       = 'yama81950';             // 投稿用パスワード。不要なら '' のまま

// 既存の設定・定数の直後（重複定義防止）
if (!defined('SMAHO_HEADER_HTML')) {
  define('SMAHO_HEADER_HTML', <<<HTML
<div class="smaho-headbar">
  <img src="assets/img/yamarepo-header.png" alt="投稿ヘッダ">
  <div>
    <div class="title">かんたん山れぽーと</div>
    <div class="sub">写真とテキストでサクッと投稿</div>
  </div>
</div>
HTML);
}

// ===== 共通関数 =====
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ensure_dirs(): void {
  foreach ([DATA_DIR, UPLOAD_DIR, ARTICLES_DIR, TEMP_UPLOAD_DIR] as $d) { if (!is_dir($d)) @mkdir($d, 0777, true); }
}

/**
 * HTML生成（本文→画像→各キャプションの順に並べる）←★キャプション対応に置換
 */
function build_article_html($title, $dateYmd, $author, $bodyText, array $imageUrls, $canonical = '', array $captions = []) {
  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $meta = h(date_author_label($dateYmd, $author));
  // 本文：空行で段落、改行は <br>
  $bodyHtml = '';
  $blocks = preg_split('/\n{2,}/u', (string)$bodyText);
  if ($blocks) {
      foreach ($blocks as $p) {
          $p = preg_replace('/\R/u', '<br>', trim($p));
          if ($p !== '') $bodyHtml .= "<p>{$p}</p>\n";
      }
  }
  if ($bodyHtml === '') $bodyHtml = '<p></p>';

  // 画像＋キャプション（本文の後に 1→2→3… の順で縦並び）
  $figs = '';
  foreach ($imageUrls as $i => $u) {
      $figs .= "<figure class=\"smaho-figure\">\n";
      $figs .= '  <img src="'.$e($u).'" alt="'.$e($title).'" loading="lazy" sizes="(min-width:1024px) 960px, 100vw" style="max-width:100%;height:auto;">'."\n";
      $cap = $captions[$i] ?? '';
      if ($cap !== '') {
          $cap = preg_replace('/\R/u', '<br>', $cap);
          $figs .= '  <figcaption class="smaho-caption" style="color:#555;font-size:.95em;margin-top:4px;">'.$cap."</figcaption>\n";
      }
      $figs .= "</figure>\n";
  }

  $meta = h(date_author_label($dateYmd, $author));
  $header = defined('SMAHO_HEADER_HTML') ? SMAHO_HEADER_HTML : '';

  $html = '<!DOCTYPE html><html lang="ja"><head>'
        . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-X3R3DEHC1B"></script>'
        . '<script>'
        . 'window.dataLayer = window.dataLayer || [];'
        . 'function gtag(){dataLayer.push(arguments);}'
        . 'gtag("js", new Date());'
        . 'gtag("config", "G-X3R3DEHC1B");'
        . '</script>' 
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="/smaho/smaho.css">'
        . '<title>' . h($title) . ' | 山れぽ記事' . '</title>'
        . '</head><body>'
        . '<div class="smaho-headbar">'
        . '<img src="/smaho/assets/img/yamarepo-header.png" alt="投稿ヘッダ">'
        . '<div>'
        . '<div class="title">かんたん山れぽーと</div>'
        . '<div class="sub">写真とテキストでサクッと投稿</div>'
        . '</div>'
        . '</div>'
        . '<article class="smaho-article">'
        . '<header class="smaho-article-head">'
        . '<h1 class="smaho-title">' . h($title) . '</h1>'
        . '<p class="smaho-meta">' . $meta . '</p>'
        . '</header>'
        . '<div class="smaho-body">' . $bodyHtml . '</div>'
        . $figs
        . '</article>'
        . '</body></html>';

  return $html;
}
// --- 共通：日付+投稿者ラベルを統一 ---
if (!function_exists('date_author_label')) {
    function date_author_label(string $dateYmd, string $author): string {
        // 想定入力: "YYYY-MM-DD" or "YYYY/MM/DD" 等 → "YYYY-MM-DD" へ寄せる
        $d = preg_replace('#[^\d]#', '-', $dateYmd);
        $d = preg_replace('#-+#', '-', $d);
        $d = trim($d, '-');
        // 8桁(YYYYMMDD)ならハイフンを挿入
        if (preg_match('#^\d{8}$#', $d)) {
            $d = substr($d,0,4) . '-' . substr($d,4,2) . '-' . substr($d,6,2);
        }
        return $d . '　' . '投稿者:' . $author; // 全角スペース＋半角コロン
    }
}


function allocate_next_id(string $path): int {
  $dir = dirname($path);
  if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
  }
  $fp = fopen($path, 'c+');
  if (!$fp) throw new RuntimeException("Cannot open next_id file: $path");
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException("Cannot lock next_id file: $path"); }
  $raw  = stream_get_contents($fp);
  $last = (int)trim((string)$raw);
  if ($last < 0) $last = 0;
  $next = $last + 1;
  ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$next); fflush($fp);
  flock($fp, LOCK_UN); fclose($fp);
  return $next;
}

/**
 * 画像を縮小・再圧縮して保存（GD）
 */
function save_compressed_image(string $srcPath, string $destPath, string $mime, int $maxLong = 1600, int $jpegQuality = 82): bool {
    if (!is_file($srcPath)) return false;
    // MIME正規化
    $mime = strtolower((string)$mime);
    if ($mime === '' || $mime === 'application/octet-stream') {
        $info = @getimagesize($srcPath);
        if (!empty($info['mime'])) $mime = strtolower($info['mime']);
    }
    if (in_array($mime, ['image/jpg','image/pjpeg'])) $mime = 'image/jpeg';
    if ($mime === 'image/x-png')  $mime = 'image/png';
    if ($mime === 'image/x-webp') $mime = 'image/webp';

    // 読み込み
    $im = null;
    if ($mime === 'image/jpeg') {
        $im = @imagecreatefromjpeg($srcPath);
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($srcPath);
            $orientation = $exif['Orientation'] ?? 1;
            if ($im) {
                switch ($orientation) {
                    case 3: $im = imagerotate($im, 180, 0); break;
                    case 6: $im = imagerotate($im, -90, 0); break;
                    case 8: $im = imagerotate($im, 90, 0); break;
                }
            }
        }
    } elseif ($mime === 'image/png') {
        $im = @imagecreatefrompng($srcPath);
    } elseif ($mime === 'image/webp') {
        $im = @imagecreatefromwebp ? @imagecreatefromwebp($srcPath) : null;
        if (!$im) { $im = @imagecreatefromstring(@file_get_contents($srcPath)); }
    } else {
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if ($ext === 'gif' || $mime === 'image/gif') {
            return @move_uploaded_file($srcPath, $destPath);
        }
        $im = @imagecreatefromstring(@file_get_contents($srcPath));
    }
    if (!$im) return false;

    $w = imagesx($im); $h = imagesy($im);
    $long = max($w, $h);
    $scale = $long > $maxLong ? $maxLong / $long : 1.0;
    $nw = (int)max(1, round($w * $scale));
    $nh = (int)max(1, round($h * $scale));

    if ($scale < 1.0) {
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $im, 0,0,0,0, $nw,$nh, $w,$h);
        imagedestroy($im);
        $im = $dst;
    }

    @mkdir(dirname($destPath), 0775, true);
    $ok = false;
    if ($mime === 'image/jpeg') {
        $ok = @imagejpeg($im, $destPath, $jpegQuality);
    } elseif ($mime === 'image/png') {
        $ok = @imagepng($im, $destPath, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($im, $destPath, 80);
    } else {
        $ok = @imagejpeg($im, $destPath, $jpegQuality);
    }
    imagedestroy($im);
    return (bool)$ok;
}

//===== ルーティング =====
ensure_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  // フォーム表示
  $today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  ?>
  <!doctype html>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-X3R3DEHC1B"></script>
  <script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-X3R3DEHC1B');
  </script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="smaho.css">
  <style>.smaho-article{max-width:720px;margin:0 auto;padding:8px 12px}.smaho-image{margin:0 0 .75rem}.smaho-caption,.smaho-body{margin-left:0;margin-right:0}</style>
  <title>記事投稿</title>
<script>
window.addEventListener('DOMContentLoaded', () => {
  const picker = document.getElementById('photosPicker');  // ラベルで開く実体
  const list   = document.getElementById('files');         // プレビューリスト
  const wrap   = document.getElementById('photosWrap');    // 送信用 input を置く場所
  const sendInput = picker;
  const state = { files: [], caps: [] };

  function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024*1024) return `${(bytes/1024).toFixed(1)} KB`;
    return `${(bytes/1024/1024).toFixed(2)} MB`;
  }
  function syncInputs() {
    const dt = new DataTransfer();
    state.files.forEach(f => dt.items.add(f));
    sendInput.files = dt.files;
  }
  function captureCaps() {
    // 既存の入力（説明文）を保持する
    list.querySelectorAll('.file-item').forEach((it) => {
      const i = parseInt(it.dataset.index, 10);
      const ta = it.querySelector('textarea[name="captions[]"]');
      if (Number.isInteger(i) && ta) state.caps[i] = ta.value;
    });
  }
  function render() {
    captureCaps();
    list.innerHTML = '';
    state.files.forEach((file, idx) => {
      const url = URL.createObjectURL(file);
      const item = document.createElement('div');
      item.className = 'file-item';
      item.draggable = true;
      item.dataset.index = String(idx);
      item.innerHTML = `
        <div class="shot">
          <img alt="" src="${url}">
        </div>
        <div class="meta">
          <div class="name">${file.name}</div>
          <div class="size">${formatSize(file.size)}</div>
          <textarea name="captions[]" rows="2" placeholder="（画像の説明・任意）" style="width:100%;box-sizing:border-box;margin-top:6px;"></textarea>
        </div>
        <div class="actions" style="margin-left:auto;">
          <button type="button" data-act="up">↑</button>
          <button type="button" data-act="down">↓</button>
          <button type="button" data-act="remove">削除</button>
        </div>`;
      const ta = item.querySelector('textarea[name="captions[]"]');
      if (ta) {
        ta.value = state.caps[idx] || '';
        ta.addEventListener('input', () => { state.caps[idx] = ta.value; });
      }
      item.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
          captureCaps();
          const act = btn.dataset.act;
          const i = parseInt(item.dataset.index, 10);
          if (act === 'remove') {
            state.files.splice(i, 1);
            state.caps.splice(i, 1);
          } else if (act === 'up' && i > 0) {
            const t = state.files[i-1]; state.files[i-1] = state.files[i]; state.files[i] = t;
            const c = state.caps[i-1];  state.caps[i-1]  = state.caps[i];  state.caps[i]  = c;
          } else if (act === 'down' && i < state.files.length-1) {
            const t = state.files[i+1]; state.files[i+1] = state.files[i]; state.files[i] = t;
            const c = state.caps[i+1];  state.caps[i+1]  = state.caps[i];  state.caps[i]  = c;
          }
          render(); syncInputs();
        });
      });
      // D&D 並べ替え
      item.addEventListener('dragstart', (e) => {
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.dataset.index);
      });
      item.addEventListener('dragend', () => item.classList.remove('dragging'));
      item.addEventListener('dragover', (e) => { e.preventDefault(); item.classList.add('drop-target'); });
      item.addEventListener('dragleave', () => item.classList.remove('drop-target'));
      item.addEventListener('drop', (e) => {
        e.preventDefault();
        item.classList.remove('drop-target');
        const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        const to = parseInt(item.dataset.index, 10);
        if (Number.isInteger(from) && Number.isInteger(to) && from !== to) {
          captureCaps();
          const f = state.files.splice(from, 1)[0];
          const c = state.caps.splice(from, 1)[0];
          state.files.splice(to, 0, f);
          state.caps.splice(to, 0, c);
          render(); syncInputs();
        }
      });
      list.appendChild(item);
    });
  }
  function addFiles(files) {
    captureCaps();
    const picked = Array.from(files || []);
    if (!picked.length) return;
    const key = f => `${f.name}@${f.size}@${f.lastModified}`;
    const seen = new Set(state.files.map(key));
    picked.forEach(f => {
      if (!seen.has(key(f))) { state.files.push(f); state.caps.push(''); }
    });
    render(); syncInputs();
  }
  picker.addEventListener('change', () => addFiles(picker.files));
  render(); syncInputs();
});
</script>

  <div class="wrap">
<div class="smaho-headbar">
<img src="assets/img/yamarepo-header.png" alt="投稿ヘッダ">
<div>
  <div class="title">かんたん山れぽーと</div>
  <div class="sub">写真とテキストでサクッと投稿</div>
</div>
</div>
<?php
$prefill = $_SESSION['prefill'] ?? [];
unset($_SESSION['prefill']);
$prefill_title  = $prefill['title']  ?? '';
$prefill_body   = $prefill['body']   ?? '';
$prefill_author = $prefill['author'] ?? '';
$prefill_date   = $prefill['date']   ?? '';
$prefill_pass   = $prefill['pass']   ?? '';
$date_value     = $prefill_date !== '' ? $prefill_date : $today;
$prefill_tmp  = $_SESSION['prefill_tmp']  ?? [];
unset($_SESSION['prefill_tmp']);
$prefill_caps = $_SESSION['prefill_caps'] ?? [];
unset($_SESSION['prefill_caps']);
$upload_notice = $_SESSION['upload_notice'] ?? '';
unset($_SESSION['upload_notice']);
?>
<?php if ($upload_notice !== ''): ?>
  <div style="margin:12px 0; padding:10px 12px; border:1px solid #f0c36d; background:#fff8e1; border-radius:8px; font-size:14px; line-height:1.5;">
    <?php echo htmlspecialchars($upload_notice, ENT_QUOTES, 'UTF-8'); ?>
  </div>
<?php endif; ?>
<form action="post.php" method="post" enctype="multipart/form-data" accept-charset="UTF-8" onsubmit="return (this.__submitted?false:(this.__submitted=true))">
      <input type="hidden" name="mode" value="preview">
      <?php if (SUBMIT_PASSWORD !== ''): ?>
        <label>投稿パスワード（会員キー）</label>
        <input type="text" name="pass" required value="<?=h($prefill_pass)?>">
      <?php endif; ?>

      <label>タイトル <span class="hint">（必須）</span></label>
      <input type="text" name="title" required value="<?=h($prefill_title)?>">

      <label>本文 <span class="hint">（必須）</span></label>
      <textarea name="body" required><?=h($prefill_body)?></textarea>

      <div class="row">
        <div>
          <label>日付 <span class="hint"></span></label>
          <input type="date" name="date" value="<?=h($date_value)?>">
        </div>
        <div>
          <label>投稿者<span class="hint">（必須）</span></label>
          <input type="text" name="author" value="<?=h($prefill_author)?>">
        </div>
      </div>

      <label>写真<span class="hint">（複数可）</span></label>
<div id="photosWrap">
  <input id="photosPicker" type="file" name="photos[]" accept="image/*" multiple>
  <?php if (!empty($prefill_tmp)): ?>
  <div class="retained" style="margin-top:8px">
  <div style="margin:8px 0;font-size:0.9em;opacity:0.8">前回の選択画像（保持中）</div>

  <div id="filesRetained" class="files">
  <?php foreach ($prefill_tmp as $i => $bn): ?>
    <?php
      $bn_safe = htmlspecialchars($bn, ENT_QUOTES, 'UTF-8');
      $src = htmlspecialchars(PUBLIC_TEMP_UPLOAD_BASE . '/' . $bn, ENT_QUOTES, 'UTF-8');
      $size = '';
      $p = rtrim(TEMP_UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bn;
      if (is_file($p)) {
          $bytes = filesize($p);
          if ($bytes < 1024)       $size = $bytes . ' B';
          elseif ($bytes < 1024*1024) $size = number_format($bytes/1024, 1) . ' KB';
          else                         $size = number_format($bytes/1024/1024, 1) . ' MB';
      }
    ?>
    <div class="file-item" data-index="<?php echo (int)$i; ?>">
      <div class="shot"><img alt="" src="<?php echo $src; ?>"></div>
      <div class="meta">
        <div class="name"><?php echo $bn_safe; ?></div>
        <div class="size"><?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?></div>
        <textarea name="captions[]" rows="2" placeholder="（画像の説明・任意）" style="width:100%;box-sizing:border-box;margin-top:6px;"><?php echo htmlspecialchars($prefill_caps[$i] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
      </div>
      <div class="actions" style="margin-left:auto;display:flex;gap:8px;align-items:flex-start;">
        <button type="button" data-op="up">↑</button>
        <button type="button" data-op="down">↓</button>
        <button type="button" data-op="delete">削除</button>
      </div>
      <input type="hidden" name="tmp_files[]" value="<?php echo $bn_safe; ?>">
    </div>
  <?php endforeach; ?>
  </div>

  <script>
  (function(){
    var list = document.getElementById('filesRetained');
    if (!list) return;
    list.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-op]'); if (!btn) return;
      var item = btn.closest('.file-item'); if (!item) return;
      var op = btn.getAttribute('data-op');
      if (op === 'delete') { item.remove(); return; }
      if (op === 'up' && item.previousElementSibling) { item.parentNode.insertBefore(item, item.previousElementSibling); return; }
      if (op === 'down' && item.nextElementSibling)  { item.parentNode.insertBefore(item.nextElementSibling, item); return; }
    });
  })();
  </script>
</div>
  </div>
  <?php endif; ?>
</div>
<!--<label for="photosPicker" id="addPhoto" class="actions">写真添付</label>-->
<div id="files" class="files"></div>
<script>
(function(){
  var picker = document.getElementById('photosPicker');
  var list   = document.getElementById('files');
  if (!picker || !list) return;

  var state = { files: [], caps: [] };

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1024/1024).toFixed(2) + ' MB';
  }
  function syncInput() {
    var dt = new DataTransfer();
    state.files.forEach(function(f){ dt.items.add(f); });
    picker.files = dt.files;
  }
  function captureCaps() {
    var items = list.querySelectorAll('.file-item');
    Array.prototype.forEach.call(items, function(it){
      var i = parseInt(it.dataset.index, 10);
      var ta = it.querySelector('textarea[name="captions[]"]');
      if (!isNaN(i) && ta) state.caps[i] = ta.value;
    });
  }
  function render() {
    captureCaps();
    list.innerHTML = '';
    state.files.forEach(function(file, idx){
      var url = URL.createObjectURL(file);
      var item = document.createElement('div');
      item.className = 'file-item';
      item.draggable = true;
      item.dataset.index = String(idx);
      item.innerHTML =
        '<div class="shot"><img alt="" src="' + url + '"></div>' +
        '<div class="meta">' +
          '<div class="name">' + file.name + '</div>' +
          '<div class="size">' + formatSize(file.size) + '</div>' +
          '<textarea name="captions[]" rows="2" placeholder="（画像の説明・任意）" style="width:100%;box-sizing:border-box;margin-top:6px;"></textarea>' +
        '</div>' +
        '<div class="actions" style="margin-left:auto;">' +
          '<button type="button" data-act="up">↑</button>' +
          '<button type="button" data-act="down">↓</button>' +
          '<button type="button" data-act="remove">削除</button>' +
        '</div>';

      var ta = item.querySelector('textarea[name="captions[]"]');
      if (ta) {
        ta.value = state.caps[idx] || '';
        ta.addEventListener('input', function(){ state.caps[idx] = ta.value; });
      }

      Array.prototype.forEach.call(item.querySelectorAll('button'), function(btn){
        btn.addEventListener('click', function(){
          captureCaps();
          var act = this.dataset.act;
          var i = parseInt(item.dataset.index, 10);
          if (act === 'remove') {
            state.files.splice(i, 1);
            state.caps.splice(i, 1);
          } else if (act === 'up' && i > 0) {
            var t = state.files[i-1]; state.files[i-1] = state.files[i]; state.files[i] = t;
            var c = state.caps[i-1];  state.caps[i-1]  = state.caps[i];  state.caps[i]  = c;
          } else if (act === 'down' && i < state.files.length-1) {
            var t = state.files[i+1]; state.files[i+1] = state.files[i]; state.files[i] = t;
            var c = state.caps[i+1];  state.caps[i+1]  = state.caps[i];  state.caps[i]  = c;
          }
          render(); syncInput();
        });
      });

      // Drag & Drop reorder
      item.addEventListener('dragstart', function(e){
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.dataset.index);
      });
      item.addEventListener('dragend', function(){ item.classList.remove('dragging'); });
      item.addEventListener('dragover', function(e){ e.preventDefault(); item.classList.add('drop-target'); });
      item.addEventListener('dragleave', function(){ item.classList.remove('drop-target'); });
      item.addEventListener('drop', function(e){
        e.preventDefault();
        item.classList.remove('drop-target');
        var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        var to = parseInt(item.dataset.index, 10);
        if (!isNaN(from) && !isNaN(to) && from !== to) {
          captureCaps();
          var f = state.files.splice(from, 1)[0];
          var c = state.caps.splice(from, 1)[0];
          state.files.splice(to, 0, f);
          state.caps.splice(to, 0, c);
          render(); syncInput();
        }
      });

      list.appendChild(item);
    });
  }
  function addFiles(files) {
    captureCaps();
    var picked = Array.prototype.slice.call(files || []);
    if (!picked.length) return;
    var key = function(f){ return f.name + '@' + f.size + '@' + f.lastModified; };
    var seen = new Set(state.files.map(key));
    picked.forEach(function(f){
      if(!seen.has(key(f))) { state.files.push(f); state.caps.push(''); }
    });
    render(); syncInput();
  }
  picker.addEventListener('change', function(){ addFiles(picker.files); });
  render(); syncInput();
})();
</script>

      <div class="actions2">
        <button type="submit" class="btn-like" data-loading-msg="確認画面を表示中です…">確認する</button>
        <a class="btn-like" href="manege.php">記事一覧</a>
      </div>
      <p class="actions2">記事一覧から、投稿記事を削除できます。</p>
  <!-- 送信中UI（編集→確認） -->
  <div id="posting-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:2147483647;">
    <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
      <div style="background:#fff; padding:16px 20px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.2); font-size:14px; line-height:1.4; text-align:center;">
        <div id="posting-msg">確認画面を表示中です…（電波状況により数十秒かかることがあります）</div>
        <div id="posting-elapsed" style="margin-top:6px; font-variant-numeric:tabular-nums;">経過: 00:00</div>
        <div id="posting-hint" style="margin-top:8px; display:none; color:#b00;">
          応答がありません。通信が不安定か、画像が大きい可能性があります。<br>
          このままお待ちいただくか、電波の良い場所で再送してください。
        </div>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var form = document.querySelector('form');
    if(!form) return;

    var overlay = document.getElementById('posting-overlay');
    var elapsed = document.getElementById('posting-elapsed');
    var hint    = document.getElementById('posting-hint');
    var msgEl   = document.getElementById('posting-msg');
    var timerId = null, startedAt = 0;

    function fmt(sec){ var m=Math.floor(sec/60), s=sec%60; return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); }

    form.addEventListener('submit', function(e){
      var submitBtn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
      if (submitBtn && submitBtn.disabled) return;
      if (submitBtn) submitBtn.disabled = true;

      var custom = submitBtn && submitBtn.getAttribute && submitBtn.getAttribute('data-loading-msg');
      if (custom) msgEl.textContent = custom;

      overlay.style.display = 'block';
      startedAt = Date.now();
      hint.style.display = 'none';
      timerId = setInterval(function(){
        var sec = Math.floor((Date.now() - startedAt)/1000);
        elapsed.textContent = '経過: ' + fmt(sec);
        if (sec >= 30) hint.style.display = 'block';
      }, 1000);
    });

    window.addEventListener('pageshow', function(){
      if (timerId) clearInterval(timerId);
      if (overlay) overlay.style.display = 'none';
    });
  })();
  </script>

    </form>
  </div>
  <?php exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$raw = $_POST['pass'] ?? '';
$pass = trim((string)$raw);
if (function_exists('mb_convert_kana')) { $pass = mb_convert_kana($pass, 'asKV', 'UTF-8'); }
if (class_exists('Normalizer')) { $pass = Normalizer::normalize($pass, Normalizer::FORM_C); }
if (SUBMIT_PASSWORD != '' && $pass != SUBMIT_PASSWORD) { http_response_code(403); exit('Bad password'); }

/* 認証OK → 合図（1時間） */
if (session_id() === '') { session_start(); }
$_SESSION['yamabiko_auth'] = 1;
setcookie('yamabiko_auth', '1', time() + 3600, '/', '', false, true);

$raw_date = trim($_POST['date'] ?? '');
$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$author   = trim($_POST['author'] ?? '');

$mode = $_POST['mode'] ?? 'preview';
$dt = '';

// CANCEL: TMP削除して戻る
if ($mode === 'cancel') {
  $_SESSION['prefill'] = ['title'=>$title,'body'=>$body,'author'=>$author,'date'=>$raw_date,'pass'=>$pass];
  $_SESSION['prefill_tmp']  = isset($_POST['tmp_files']) && is_array($_POST['tmp_files']) ? array_values(array_map('strval', $_POST['tmp_files'])) : [];
  $_SESSION['prefill_caps'] = isset($_POST['captions'])   && is_array($_POST['captions'])   ? array_values(array_map('strval', $_POST['captions']))   : [];
  // TMPは削除せず保持
  header('Location: post.php');
  exit;
}

// PREVIEW: TMPへ保存して確認表示（DB/CSV未登録）
if ($mode !== 'commit') {
  if (!is_dir(TEMP_UPLOAD_DIR)) { @mkdir(TEMP_UPLOAD_DIR, 0775, true); }
  $tempBasenames = [];
  $heicDetected = false;
  $heicNames = [];
  $fi = @finfo_open(FILEINFO_MIME_TYPE);
  if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $n = count($_FILES['photos']['name']);
    for ($i=0; $i<$n; $i++) {
      $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
      if ($err === UPLOAD_ERR_NO_FILE) continue;
      if ($err !== UPLOAD_ERR_OK) continue;
      $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';
      if ($tmp === '' || !is_file($tmp)) continue;
      $mime = $fi ? (finfo_file($fi, $tmp) ?: '') : '';
      $ext = '';
      $m = strtolower((string)$mime);
      if (in_array($m, ['image/jpeg','image/jpg','image/pjpeg']))      $ext = '.jpg';
      elseif (in_array($m, ['image/png','image/x-png']))               $ext = '.png';
      elseif (in_array($m, ['image/webp','image/x-webp']))             $ext = '.webp';
      elseif ($m === 'image/gif')                                      $ext = '.gif';
      else                                                             $ext = '';
      if ($ext === '') {
        // HEIC/HEIFはここでは扱えないため、検知だけしてスキップ
        $orig = $_FILES['photos']['name'][$i] ?? '';
        $extLower = strtolower(pathinfo((string)$orig, PATHINFO_EXTENSION));
        if (in_array($m, ['image/heic','image/heif','image/heic-sequence','image/heif-sequence']) || in_array($extLower, ['heic','heif'])) {
          $heicDetected = true;
          if ($orig !== '') $heicNames[] = $orig;
        }
        continue;
      }
      $basename = date('Ymd_His') . sprintf('_%02d_', $i) . bin2hex(random_bytes(4)) . $ext;
      $dest = rtrim(TEMP_UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
      if (!@move_uploaded_file($tmp, $dest)) continue;
      $tempBasenames[] = $basename;
    }
  }
  if ($fi) finfo_close($fi);

  if ($heicDetected) {
    $names = '';
    if (!empty($heicNames)) {
      $uniq = array_values(array_unique(array_map('strval', $heicNames)));
      // 長くなりすぎるのを避ける
      $names = '（' . implode('、', array_slice($uniq, 0, 3)) . (count($uniq) > 3 ? ' ほか' : '') . '）';
    }
    $_SESSION['upload_notice'] = 'HEIC/HEIF形式の画像が含まれていたため取り込めませんでした' . $names . '。iPhoneの場合は「設定 > カメラ > フォーマット > 互換性優先」でJPEGにするか、写真をJPEGに変換してから選択してください。';
  }

  // ★保持中(tmp_files[])＋今回新規($tempBasenames)を結合（順序は「保持中→新規」）
  $retained = [];
  if (!empty($_POST['tmp_files']) && is_array($_POST['tmp_files'])) {
      $retained = array_values(array_filter(array_map(function($x){
          return basename((string)$x);
      }, $_POST['tmp_files'])));
  }
  // 重複は先勝ちで除去しつつマージ
  $tempBasenames = array_values(array_unique(array_merge($retained, $tempBasenames)));

  // 確認画面の表示用URLを構成（結合後の配列から一括生成）
  $imageUrls = [];
  foreach ($tempBasenames as $bn) {
      $imageUrls[] = rtrim(PUBLIC_TEMP_UPLOAD_BASE, '/') . '/' . $bn;
  }

  // ★キャプション数を画像数に合わせて調整（不足→空文字追加／余剰→切り詰め）
  $captions = array_map('strval', $_POST['captions'] ?? []);
  $diff = count($tempBasenames) - count($captions);
  if ($diff > 0) {
      $captions = array_merge($captions, array_fill(0, $diff, ''));
  } elseif ($diff < 0) {
      $captions = array_slice($captions, 0, count($tempBasenames));
  }
  // ★キャプション／代表インデックスを受け取り（プレビュー表示・commit へ引継ぎ）
  $bodyHtml = '';
  foreach (preg_split('/\n{2,}/u', (string)$body) ?: [(string)$body] as $p) {
      $p = preg_replace('/\R/u', '<br>', trim((string)$p));
      if ($p !== '') $bodyHtml .= "<p>{$p}</p>\n";
  }
  if ($bodyHtml === '') $bodyHtml = '<p></p>';
  // 確認画面
  ?>
<?php
// 確認画面用：$dt を安全に作成（最小差分）
$dt = '';
if (!empty($raw_date)) {
    $ts = @strtotime($raw_date);
    $dt = $ts ? date('Y-m-d', $ts) : (string)$raw_date;
} elseif (!empty($dateYmd)) {
    $dt = (string)$dateYmd;
} else {
    $dt = date('Y-m-d');
}
?>
<!doctype html>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-X3R3DEHC1B"></script>
  <script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-X3R3DEHC1B');
  </script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="smaho.css">
  <style>.smaho-article{max-width:720px;margin:0 auto;padding:8px 12px}.smaho-image{margin:0 0 .75rem}.smaho-caption,.smaho-body{margin-left:0;margin-right:0}</style>
  <style>.smaho-article{max-width:720px;margin:0 auto;padding:8px 12px}.smaho-image,.smaho-figure{margin:0 0 .75rem}.smaho-image img,.smaho-figure img{display:block;width:100%;height:auto}.smaho-body,.smaho-image,.smaho-figure,.smaho-caption{margin-left:0;margin-right:0}figure{margin:0}</style>
  <?php $dt = ($raw_date !== '' ? date('Y-m-d', strtotime($raw_date)) : date('Y-m-d')); ?>
  <title>投稿の確認</title>
  <body>
  <main class="smaho-result">
    <h1 style="text-align:center;">投稿の確認</h1>
<!--    <div class="smaho-headbar">
        <img src="/smaho/assets/img/yamarepo-header.png" alt="投稿ヘッダ">
        <div>
          <div class="title">かんたん山れぽーと</div>
          <div class="sub">写真とテキストでサクッと投稿</div>
        </div>
    </div>
-->
    <div class="smaho-preview">
      <header class="smaho-article-head">
        <h1 class="smaho-title"><?= h($title) ?></h1>
        <p class="smaho-meta"><?= h(date_author_label($dt, $author)) ?></p>
      </header>

      <div class="smaho-body"><?= $bodyHtml /* 既存の本文 HTML */ ?></div>
      <?php if (!empty($imageUrls) || !empty($tempBasenames)): ?>
      <?php if (!empty($imageUrls)): ?>
<?php foreach ($imageUrls as $i => $u): ?>
  <figure class="smaho-image">
    <img src="<?= h($u) ?>" alt="">
    <?php
      $cap = '';
      if (isset($captions) && is_array($captions)) {
          if (array_key_exists($i, $captions)) { $cap = (string)$captions[$i]; }
          elseif (isset($captions[basename($u)])) { $cap = (string)$captions[basename($u)]; }
          elseif (isset($captions[$u])) { $cap = (string)$captions[$u]; }
      }
    ?>
    <?php if ($cap !== ''): ?>
      <?php $cap = isset($captions[$i]) ? (string)$captions[$i] : (isset($captions[basename($u)]) ? (string)$captions[basename($u)] : (isset($captions[$u]) ? (string)$captions[$u] : '')); ?>
<?php if ($cap !== ''): ?><figcaption class="smaho-caption"><?= nl2br(h($cap)) ?></figcaption><?php endif; ?>
    <?php endif; ?>
  </figure>
<?php endforeach; ?>
<?php endif; ?>

      <?php endif; ?></article>
    </div>

    <div class="actions">
      <form action="post.php" method="post" style="display:inline">
        <input type="hidden" name="pass" value="<?php echo htmlspecialchars($pass, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="mode" value="commit">
        <input type="hidden" name="title"  value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="body"   value="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="author" value="<?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="date"   value="<?php echo htmlspecialchars($raw_date, ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($tempBasenames as $bn): ?>
          <input type="hidden" name="tmp_files[]" value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
        <?php foreach ($captions as $c): ?>
          <input type="hidden" name="captions[]" value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
<button class="btn-like" type="submit">投稿する</button>
      </form>

      <form action="post.php" method="post" style="display:inline;margin-left:8px">
        <input type="hidden" name="pass" value="<?php echo htmlspecialchars($pass, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="title"  value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="body"   value="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="author" value="<?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="date"   value="<?php echo htmlspecialchars($raw_date, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="pass"  value="<?php echo htmlspecialchars($pass, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="mode" value="cancel">
        <?php foreach ($tempBasenames as $bn): ?>
          <input type="hidden" name="tmp_files[]" value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
                <?php foreach ($captions as $c): ?>
          <input type="hidden" name="captions[]" value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
<button class="btn-like" type="submit">編集に戻る</button>
      </form>
    </div>
  </main>
  <!-- 送信中UI（最小） -->
<div id="posting-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:2147483647;">
  <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:16px 20px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.2); font-size:14px; line-height:1.4; text-align:center;">
      <div id="posting-msg">投稿を送信中です…（電波状況により数十秒かかることがあります）</div>
      <div id="posting-elapsed" style="margin-top:6px; font-variant-numeric:tabular-nums;">経過: 00:00</div>
      <div id="posting-hint" style="margin-top:8px; display:none; color:#b00;">
        応答がありません。通信が不安定か、画像が大きい可能性があります。<br>
        このまま待つか、電波の良い場所で再送してください。
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var form = document.querySelector('form'); // 既存の投稿フォーム（1ページ1フォーム想定）
  if(!form) return;

  var overlay = document.getElementById('posting-overlay');
  var elapsed = document.getElementById('posting-elapsed');
  var hint    = document.getElementById('posting-hint');
  var timerId = null, startedAt = 0;

  function fmt(sec){
    var m = Math.floor(sec/60), s = sec%60;
    return (String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0'));
  }

  form.addEventListener('submit', function(){
    // 送信中UI表示＋二重送信防止（disabled）
    var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    overlay.style.display = 'block';
    startedAt = Date.now();
    hint.style.display = 'none';
    timerId = setInterval(function(){
      var sec = Math.floor((Date.now() - startedAt)/1000);
      elapsed.textContent = '経過: ' + fmt(sec);
      // 30秒超でヒント表示
      if (sec >= 30) hint.style.display = 'block';
    }, 1000);
  });

  // ページ遷移（成功）・エラーで戻ってきた場合にも消えるように保険
  window.addEventListener('pageshow', function(){
    if (timerId) clearInterval(timerId);
    if (overlay) overlay.style.display = 'none';
  });
})();
</script>

  </body>
  <?php
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  exit;
}

if ($title === '' || $body === '') { http_response_code(400); exit('title/body is required'); }

$dt = preg_replace('/[\/.]/', '-', $raw_date);
if ($dt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
  $dt = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

// commit処理：キャプション／代表画像インデックスを取り出し ←★追加
$captions = array_map('strval', $_POST['captions'] ?? []);
// 画像アップロード（順番通り保存）
$imageUrls = [];
$fi = @finfo_open(FILEINFO_MIME_TYPE);

if (($mode ?? 'preview') === 'commit' && isset($_POST['tmp_files']) && is_array($_POST['tmp_files'])) {
    if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0775, true); }
    foreach ($_POST['tmp_files'] as $bn) {
        $bn = basename((string)$bn);
        if ($bn === '') continue;
        $src = rtrim(TEMP_UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bn;
        if (!is_file($src)) continue;
        $mime = $fi ? (finfo_file($fi, $src) ?: '') : '';
        $dest = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bn;
        if (save_compressed_image($src, $dest, $mime)) {
            $imageUrls[] = rtrim(PUBLIC_UPLOAD_BASE, '/') . '/' . $bn;
            @unlink($src);
        }
    }
} else if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $n = count($_FILES['photos']['name']);
    if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0775, true); }
    for ($i = 0; $i < $n; $i++) {
        $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK)   continue;
        $tmp  = $_FILES['photos']['tmp_name'][$i] ?? '';
        if ($tmp === '' || !is_file($tmp)) continue;
        $mime = $fi ? (finfo_file($fi, $tmp) ?: '') : '';
        $ext = '';
        $m = strtolower((string)$mime);
        if (in_array($m, ['image/jpeg','image/jpg','image/pjpeg']))      $ext = '.jpg';
        elseif (in_array($m, ['image/png','image/x-png']))               $ext = '.png';
        elseif (in_array($m, ['image/webp','image/x-webp']))             $ext = '.webp';
        elseif ($m === 'image/gif')                                      $ext = '.gif';
        else                                                             $ext = '';
        if ($ext === '') continue;
        $basename = date('Ymd_His') . sprintf('_%02d_', $i) . bin2hex(random_bytes(4)) . $ext;
        $dest     = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
        if (!save_compressed_image($tmp, $dest, $mime)) continue;
        $imageUrls[] = rtrim(PUBLIC_UPLOAD_BASE, '/') . '/' . $basename;
    }
}
if ($fi) finfo_close($fi);

// CSV用の代表サムネ（rep優先、無ければ1枚目） ←★変更
$thumbUrl = $imageUrls[0] ?? '';// CSVオープン
$need_header = !is_file(CSV_PATH) || filesize(CSV_PATH) === 0;
$fp = fopen(CSV_PATH, 'a+'); if (!$fp) { http_response_code(500); exit('CSV open failed'); }
if (!flock($fp, LOCK_EX)) { fclose($fp); http_response_code(500); exit('CSV lock failed'); }

$newId = allocate_next_id(NEXT_ID_PATH);

// 詳細HTMLを生成・保存（/articles/YYYY/MM/ID.html）
$yy = substr($dt,0,4); $mm = substr($dt,5,2);
$dir = ARTICLES_DIR . "/$yy/$mm"; if (!is_dir($dir)) @mkdir($dir, 0777, true);
$articleUrl = PUBLIC_ARTICLES_BASE . "/$yy/$mm/" . str_pad((string)$newId,5,'0',STR_PAD_LEFT) . ".html";

// ★キャプションを渡すよう変更
$html = build_article_html($title, $dt, $author, $body, $imageUrls, $articleUrl, $captions);
file_put_contents($dir . '/' . str_pad((string)$newId,5,'0',STR_PAD_LEFT) . ".html", $html);

// CSV 追記（ヘッダは初回のみ）
fseek($fp, 0, SEEK_END);
if ($need_header) { fputcsv($fp, ['id','date_ymd','title','url','thumb','author','body']); }
fputcsv($fp, [$newId, $dt, $title, $articleUrl, $thumbUrl, $author, $body]);

fflush($fp); flock($fp, LOCK_UN); fclose($fp);

header('Location: done.php'); exit;