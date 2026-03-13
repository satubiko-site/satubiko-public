<?php
$page_title = 'お問い合わせ - 札幌山びこ山友会';
$nav_current  = 'contact';
require_once __DIR__ . '/_inc/_config.php';

session_start();

mb_internal_encoding("UTF-8");
mb_language("Japanese");

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$errors = [];
$done   = false;

$old = ['name'=>'','email'=>'','subject'=>'','message'=>''];

// ▼ CSRFトークン生成は“必ず最初に”
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Populate $old and validate (added)
    $old['name']    = trim($_POST['name'] ?? '');
    $old['email']   = trim($_POST['email'] ?? '');
    $old['subject'] = trim($_POST['subject'] ?? '');
    $old['message'] = trim($_POST['message'] ?? '');

    // ==========================================
    // 追加(1): honeypot（bot対策）
    //  - フォームにある "website" が埋まっていたら bot と判断して破棄
    // ==========================================
    if (!empty($_POST['website'] ?? '')) {
        exit;
    }

    // ==========================================
    // 追加(2): 英語スパム簡易フィルタ（本文/件名）
    //  - 典型的な営業スパムの特徴語を含む場合は破棄
    //  - 必要に応じて単語を追加
    //  - 2025/12/29 追加 
    // ==========================================
    $spam_words = [
      'monetize',
      'localbizai',
      'jvz8.com',
      'WITHOUT',
      'cold-calling',
      'client-getting',
      'agency automation',
  
      // 追加: VA/デジマ支援系の典型スパム
      'virtual assistant',
      'digital marketing assistant',
      'meta ads',
      '$250/month',
      'first month is completely free',
      'fill out the form',
      'get started',
      'order management',
  ];

    $haystack = ($old['subject'] ?? '') . "\n" . ($old['message'] ?? '');
    foreach ($spam_words as $w) {
        if ($w !== '' && stripos($haystack, $w) !== false) {
            exit;
        }
    }

    if ($old['name'] === '')    { $errors['name'] = 'お名前は必須です。'; }
    if ($old['email'] === '')   { $errors['email'] = 'メールアドレスは必須です。'; }
    if ($old['subject'] === '') { $errors['subject'] = '件名は必須です。'; }
    if ($old['message'] === '') { $errors['message'] = 'お問い合わせ内容は必須です。'; }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'メールアドレスの形式が正しくありません。';
    }
    // ==========================================
    // 追加(3): 件名に全角文字が含まれているか？
    //  - 件名に全角文字（漢字、ひらがな、カタカナ）が含まれない場合に破棄
    //  - 2026/1/8 追加 
    // ==========================================
    $str = ($old['subject'] ?? '');
    if ( $str !== '' ) {
      if ( preg_match( "/[ぁ-ん]+|[ァ-ヴー]+|[一-龠]/u", $str) ) {
        // 日本語文字列が含まれている
      } else {
        // 日本語文字列が含まれていない
        $errors['subject'] = '件名には全角文字が含まれていること。';
      }
    }
    
    // ▼ トークン検証
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $errors[] = '無効なリクエストです。もう一度お試しください。';

    }

    // 送信処理（エラーが無い場合のみ）
    if (!$errors) {
        $TO = 'info@satubiko.com'; // 宛先

        // ハニーポット（bot対策）※上で破棄しているが二重安全策として残す
        if (!empty($_POST['website'])) {
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?sent=1');
            exit;
        }

        // アプリ内部の文字コード（現在のソースは Shift-JIS）
        $srcEnc = 'UTF-8'; // もし今後UTF-8化するなら 'UTF-8' に変更

        // 日本語メール設定
        mb_language('Japanese');
        mb_internal_encoding($srcEnc);

        $fromEmail = $old['email'] ?: 'info@satubiko.com';
        $fromName  = '札幌山びこ山友会'; // 差出人名（任意）

        // 件名・本文（アプリ内の文字列）
        $subject_app = '【お問い合わせ】' . $old['subject'];
        $body_app =
            "以下の内容でお問い合わせを受け付けました。\n\n".
            "お名前: {$old['name']}\n".
            "メール: {$old['email']}\n".
            "件名  : {$old['subject']}\n".
            "---------\n{$old['message']}\n---------\n".
            "送信元: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n".
            "UA    : " . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n";
        
        // 件名・本文は UTF-8 のまま mb_send_mail に渡す
        $subject_hdr = $subject_app;
        $body_to_send = $body_app;

        // 差出人名（必要ならエンコード、無理せずメールアドレスだけでも可）
        $from_name_hdr = mb_encode_mimeheader($fromName, 'ISO-2022-JP-MS');
        $from_hdr = $from_name_hdr . ' <' . $fromEmail . '>';

        // ヘッダ（JISを明示、7bit、行末は \r\n）
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: ' . $from_hdr;
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'Content-Type: text/plain; charset=ISO-2022-JP';
        $headers[] = 'Content-Transfer-Encoding: 7bit';
        $headers_str = implode("\r\n", $headers);

        // ログ（内部文字コードのままでOK：表示確認用）
        @file_put_contents(
            __DIR__ . '/contact_mail.log',
            date('c') . "\t" . $subject_app . "\n" . $body_app . "\n\n",
            FILE_APPEND
        );

        // 送信
        mb_send_mail($TO, $subject_hdr, $body_to_send, $headers_str);

        header('Location: ' . $_SERVER['REQUEST_URI'] . '?sent=1');
        exit;
    }

}
// 以下、バリデーションや送信処理のコード…

?>
<!doctype html>
<html lang="ja">
<head><?php include __DIR__ . '/_inc/_head.php'; ?>
</head>
<body class="has-crumb">
    <div id="container">
    <?php include __DIR__ . '/_inc/_header.php'; ?>
    <div id="breadcrumb">
      <div class="breadcrumb-inner">
        <a href="index.php"><i class="fa fa-home" aria-hidden="true"></i>トップ</a> &gt; 会について &gt; お問い合わせ
      </div>
    </div>
  <main id="contact">
  <div class="wrap">
    <h1>当会へのお問い合わせ</h1>

      <?php if (isset($_GET['sent'])): ?>
        <p class="ok">
          送信しました。ありがとうございます。返信までしばらくお待ちください。
        </p>
        <p><a href="index.php" class="btn">トップへ戻る</a></p>
      <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert">
          入力内容に誤りがあります。赤字のメッセージをご確認ください。
        </div>
      <?php endif; ?>

    <form method="post" action="<?= h($_SERVER['REQUEST_URI']) ?>" novalidate>
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">     
      <!-- ハニーポット -->
      <div class="hp" aria-hidden="true">
        <label>サイト（空のまま）<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="field">
        <label for="name">お名前<span aria-hidden="true">（必須）</span></label>
        <input id="name" type="text" name="name" required autocomplete="name" value="<?= h($old['name']) ?>"
          aria-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['name'])): ?>
        <div class="error">
          <?= h($errors['name']) ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="email">メールアドレス<span aria-hidden="true">（必須）</span></label>
        <input id="email" type="email" name="email" required autocomplete="email" value="<?= h($old['email']) ?>"
          aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['email'])): ?>
        <div class="error">
          <?= h($errors['email']) ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="subject">件名<span aria-hidden="true">（必須）</span></label>
        <input id="subject" type="text" name="subject" required value="<?= h($old['subject']) ?>"
          aria-invalid="<?= isset($errors['subject']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['subject'])): ?>
        <div class="error">
          <?= h($errors['subject']) ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="message">お問い合わせ内容<span aria-hidden="true">（必須）</span></label>
        <textarea id="message" name="message" required
          aria-invalid="<?= isset($errors['message']) ? 'true' : 'false' ?>"><?= h($old['message']) ?></textarea>
        <?php if (isset($errors['message'])): ?>
        <div class="error">
          <?= h($errors['message']) ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <button class="btn btn-primary" type="submit">送信</button>
      </div>
      <div class="priv-msg">
        <h5>個人情報の取扱いについて</h5>
        <p>
        当会へのお問い合わせでお知らせいただいた情報は、お問い合わせ対応以外の目的には利用いたしません。
        </p>
      </div>
      </div>

    </form>
    <?php endif; ?>

  </div>
</main>
<?php include __DIR__ . '/_inc/_footer.php'; ?>
</body>
</html>
