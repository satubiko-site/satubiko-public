<?php if (!defined('APP_BOOTSTRAPPED')) exit; ?>
<?php
// $nav_current（各ページでセット済み）と照合
if (!function_exists('nav_on')) {
  function nav_on($keys): bool {
    $cur = $GLOBALS['nav_current'] ?? '';
    foreach ((array)$keys as $key) {
      if ($key === '') continue;
      if (substr($key, -1) === '*') {               // 例: 'sanko*' → sanko2013 などに一致
        $pre = substr($key, 0, -1);
        if ($pre !== '' && strncmp($cur, $pre, strlen($pre)) === 0) return true;
      } else {
        if ($cur === $key) return true;             // 完全一致
      }
    }
    return false;
  }
}
if (!function_exists('nav_active'))   { function nav_active($keys): string { return nav_on($keys) ? 'is-active' : ''; } }
if (!function_exists('nav_expanded')) { function nav_expanded($keys): string { return nav_on($keys) ? 'true' : 'false'; } }
?>

<nav id="nav">
  <ul id="menu">
    <li class="<?= nav_active('home') ?>">
      <a href="<?= asset('index.php') ?>"
        aria-current="<?= nav_on('home') ? 'page' : 'false' ?>">
        <i class="fa-solid fa-house"></i>トップ
      </a>
    </li>

    <li class="<?= nav_active('notice') ?>">
      <a href="<?= asset('notice.php') ?>"
        aria-current="<?= nav_on('notice') ? 'page' : 'false' ?>">
        お知らせ
      </a>
    </li>

    <li class="has-children <?= nav_active(['profile','nyukai','nyumon','contact']) ?>">
      <a href="" aria-haspopup="true" aria-expanded="<?= nav_expanded(['profile','nyukai','nyumon','contact']) ?>">
        会について
      </a>
      <ul>
        <li class="<?= nav_active('profile') ?>">
          <a href="<?= asset('profile.php') ?>" aria-current="<?= nav_on('profile')?'page':'false' ?>">会の紹介</a>
        </li>
        <li class="<?= nav_active('nyukai') ?>">
          <a href="<?= asset('nyukai.php') ?>" aria-current="<?= nav_on('nyukai')?'page':'false' ?>">入会案内</a>
        </li>
        <li class="<?= nav_active('nyumon') ?>">
          <a href="<?= asset('nyumon.php') ?>" aria-current="<?= nav_on('nyumon')?'page':'false' ?>">入門訓練</a>
        </li>
        <li class="<?= nav_active('faq') ?>">
          <a href="<?= asset('faq.php') ?>" aria-current="<?= nav_on('faq')?'page':'false' ?>">よくある質問</a>
        </li>
        <li class="<?= nav_active('contact') ?>">
          <a href="<?= asset('contact.php') ?>" aria-current="<?= nav_on('contact')?'page':'false' ?>">お問い合わせ</a>
        </li>
      </ul>
    </li>
    <li class="has-children <?= nav_active(['sanko*','search']) ?>">
      <a href="" aria-haspopup="true" aria-expanded="<?= nav_expanded(['sanko*','search']) ?>">
        山行記録
      </a>
      <ul>
        <li class="<?= nav_active('search') ?>">
          <a href="<?= asset('search.php') ?>" aria-current="<?= nav_on('search')?'page':'false' ?>">全件記事検索</a>
        </li>
        <li class="<?= nav_active('sanko') ?>">
          <a href="<?= asset('bbs39/bbs39.cgi') ?>" aria-current="<?= nav_on('sanko')?'page':'false' ?>">山行報告記事</a>
        </li>
        <li class="<?= nav_active('') ?>">
          <a href="https://www.yamareco.com/modules/yamareco/clubrecs-786-listview-1-0.html" aria-current="<?= nav_on('')?'page':'false' ?>">ヤマレコ記事</a>
        </li>
        <li class="<?= nav_active('') ?>">
          <a href="http://satuyamabiko.blog103.fc2.com/" aria-current="<?= nav_on('')?'page':'false' ?>">ブログ記事</a>
        </li>
        <li class="<?= nav_active('') ?>">
          <a href="https://www.youtube.com/channel/UCqA82_jlIuPwXqOIR0L-GSw" aria-current="<?= nav_on('')?'page':'false' ?>">YouTube動画</a>
        </li>
        <li class="<?= nav_active('sankolist') ?>">
          <a href="<?= asset('sankolist.php') ?>" aria-current="<?= nav_on('sankolist')?'page':'false' ?>">これまで登った山</a>
        </li>
      </ul>
    </li>
    <li class="has-children <?= nav_active(['150pou','sawa50','hana','weather']) ?>">
      <a href="" aria-haspopup="true" aria-expanded="<?= nav_expanded(['150pou','sawa50','hana','weather']) ?>">
        山びこ企画
      </a>
      <ul>
        <li class="<?= nav_active('150pou') ?>">
          <a href="<?= asset('150pou.php') ?>" aria-current="<?= nav_on('150pou')?'page':'false' ?>">めざせ！札幌150峰</a>
        </li>
        <li class="<?= nav_active('sawa50') ?>">
          <a href="<?= asset('sawa50.php') ?>" aria-current="<?= nav_on('sawa50')?'page':'false' ?>">札幌の沢50</a>
        </li>
        <li class="<?= nav_active('hana') ?>">
          <a href="<?= asset('hana.php') ?>" aria-current="<?= nav_on('hana')?'page':'false' ?>">みんなの花年表</a>
        </li>
        <li class="<?= nav_active('weather') ?>">
          <a href="<?= asset('weather.php') ?>" aria-current="<?= nav_on('weather')?'page':'false' ?>">気象随筆「雲見の蛙」</a>
        </li>
      </ul>
    </li>
    <li class="has-children <?= nav_active(['link']) ?>">
      <a href="" aria-haspopup="true" aria-expanded="<?= nav_expanded(['link']) ?>">
        お役立ち情報
      </a>
      <ul>
        <li class="<?= nav_active('') ?>">
          <a href="https://maps.gsi.go.jp/?z=10&ll=43.07230,141.35194#10/43.072300/141.351940/&base=std&ls=std&disp=1&vs=c1g1j0h0k0l0u0t0z0r0s0m0f0" aria-current="<?= nav_on('')?'page':'false' ?>">地理院地図</a>
        </li>
        <li class="<?= nav_active('') ?>">
          <a href="https://tenkura.n-kishou.co.jp/tk/kanko/kasel.html?ba=hk&type=15" aria-current="<?= nav_on('')?'page':'false' ?>">てんきとくらす</a>
        </li>
        <li class="<?= nav_active('link') ?>">
          <a href="<?= asset('link.php') ?>" aria-current="<?= nav_on('link')?'page':'false' ?>">各種リンク</a>
        </li>
      </ul>
    </li>
    <li><a href="<?= asset('/member/top.html') ?>">🔑会員専用</a></li>
  </ul>
</nav>
