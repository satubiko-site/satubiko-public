<?php
  $page_title = '札幌山びこ山友会のホームページ';
  $nav_current  = 'home';
  $extra_css    = [];           // ページ専用CSSがあれば ['top.css'] など
  $extra_js     = [];           // ページ専用JSがあれば ['top.js'] など
  require_once __DIR__ . '/_inc/_config.php';
?>
<?php
// index.php の最上部（HTMLより前）
$__base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
$APP_BASE = ($__base === '/' ? '' : $__base); // "/" は空文字に正規化 → "" or "/renew"
?>
<!doctype html>
<html lang="ja">
<head><?php include __DIR__ . '/_inc/_head.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
</head>
<body>
<div id="container">
  <?php include __DIR__ . '/_inc/_header.php'; ?>

    <main id="toppage">
      <!-- Hero Slider: ここから -->
      <section class="hero-slider wrapper" data-interval="10000" aria-label="会のイメージスライド">
      <div class="hero-slide" data-h2="北海道の山を遊び尽くす" data-p="札幌を拠点に四季の山へ。<br>道外や海外に遠征する人も">
          <img src="gif/hero-c1.jpg" alt="">
        </div>
        <div class="hero-slide" data-h2="安全登山をバックアップ" data-p="講習や山行で技術を磨く。<br>冬山などの入門訓練も充実">
        <img src="gif/hero-a2.jpg" alt="">
      </div>
      <div class="hero-slide is-active" data-h2="会員募集中！"
          data-p="メンバーは20代～80代。<br>自分のペースで楽しめます">
          <img src="gif/hero-b3.jpg" alt="">
        </div>
       <div class="hero-caption">
          <h2></h2>
          <p></p>
         </div>
        <div class="hero-dots" role="tablist" aria-label="スライドインジケータ"></div>
      </section>
      <section class="activities">
        <h2>やりたいことが&nbsp;きっとある</h2>
        <p>札幌山びこ山友会はあなたの挑戦を応援します</p>
        <div class="activity-grid">
          <figure>
            <img src="gif/genre-juso1.jpg" alt="縦走">
            <figcaption>縦走</figcaption>
          </figure>
          <figure>
            <img src="gif/genre-sawa.jpg" alt="沢登り">
            <figcaption>沢登り</figcaption>
          </figure>
          <figure>
            <img src="gif/genre-iwa1.jpg" alt="岩登り">
            <figcaption>岩登り</figcaption>
          </figure>
          <figure>
            <img src="gif/genre-fuyu.jpg" alt="冬山">
            <figcaption>冬山</figcaption>
          </figure>
          <figure>
            <img src="gif/genre-dogai.jpg" alt="道外">
            <figcaption>道外</figcaption>
          </figure>
          <figure>
            <img src="gif/genre-kaigai.jpg" alt="海外">
            <figcaption>海外</figcaption>
          </figure>

      </section>

      <section class="article">
        <div class="ind-wrapper">
          <img src="gif/mt_logo.png" alt="山行活動記録">
          <div class="ind-text">最近の山行から</div>
        </div>
        <div class="article-container">
          <?php include("article.php"); ?>
        </div>
        <div class="flame-text"><a href="search.php"><i class="fa-solid fa-magnifying-glass"></i>&nbsp;もっとみる</a></div>
      </section>

      <section class="projects-intro" aria-label="取組企画の紹介">
        <ul class="pi-list">
        <!-- 1. 150峰 -->
          <li class="pi-item">
            <div class="pi-body">
              <span class="pi-badge">山びこ企画</span>
              <h3 class="pi-title">めざせ！札幌 150 峰</h3>
              <p class="pi-text">
              百名山ばかりが山じゃない。<br>
              札幌50峰（さっぽろ文庫）を拡大。<br>
              札幌を睨んでいざ、名もなき峰へ</p>
            </div>
            <div class="pi-media">
              <a href="150pou.php"><img src="gif/img-150pou.png" alt="めざせ札幌150峰のイメージ"></a>
            </div>
          </li>
        <!-- 2. 札幌の沢50（左右反転） -->
          <li class="pi-item reverse">
            <div class="pi-body">
              <span class="pi-badge">山びこ企画</span>
              <h3 class="pi-title">札幌の沢50</h3>
              <p class="pi-text">
              藪漕ぎ、懸垂下降もどんと来い。<br>
              道なき道をバリ山行、沢の魅力がここに。<br>
              挑戦者求む！入門講座もやってます</p>
            </div>
            <div class="pi-media">
              <a href="sawa50.php"><img src="gif/kikaku-sawa.jpg" alt="札幌の50沢のイメージ"></a>
            </div>
          </li>

        <!-- 3. 花年表 -->
          <li class="pi-item">
            <div class="pi-body">
              <span class="pi-badge">山びこ企画</span>
              <h3 class="pi-title">みんなの花年表</h3>
              <p class="pi-text">
              登山者に元気をくれる山の花々。<br>
              会員の花だよりを年表にしました。<br>
              今年はいつ、どこへ会いに行こうか</p>
            </div>
            <div class="pi-media">
              <a href="hana.php"><img src="gif/kikaku-hana1.jpg" alt="山びこ花年表のイメージ"></a>
            </div>
          </li>
          <!-- 4.   気象随筆「雲見の蛙」 -->
          <li class="pi-item reverse">
            <div class="pi-body">
              <span class="pi-badge">山びこ企画</span>
              <h3 class="pi-title">気象随筆「雲見の蛙」</h3>
              <p class="pi-text">
              蛙は空を見上げて雲を眺めているー。<br>
              気象のスペシャリストである元会員・<br>
              村松照男さんの著作を集めました</p>
            </div>
            <div class="pi-media">
              <a href="weather.php"><img src="gif/kikaku-kumo.jpg" alt="気象随筆雲見の蛙のイメージ"></a>
            </div>
          </li>
        </ul>
      </section>

    </main>

<?php include __DIR__ . '/_inc/_footer.php'; ?>
</div>
<div id="floating-buttons" data-fab-scope>
  <a href="nyukai.php" class="fab">
    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
    <span>入会案内</span>
  </a>
  <a href="profile.php" class="fab">
    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
    <span>会の紹介</span>
  </a>
</div>

<!-- 新着投稿チェック、メール通知、DB追加 -->
<script>
fetch('ingest/check_smaho.php',   {credentials:'same-origin'}).then(r=>r.json()).then(console.log);
fetch('ingest/check_sanko.php',   {credentials:'same-origin'}).then(r=>r.json()).then(console.log);
fetch('ingest/check_yamareco.php',{credentials:'same-origin'}).then(r=>r.json()).then(console.log);
fetch('ingest/check_youtube.php', {credentials:'same-origin'}).then(r=>r.json()).then(console.log);
</script>
</body>
</html>
