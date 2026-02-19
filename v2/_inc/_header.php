<?php if (!defined('APP_BOOTSTRAPPED')) exit; ?>
<header id="header">
  <a href="<?= asset('index.php') ?>">
    <img src="<?= asset('gif/ylogo.png') ?>" alt="札幌山びこ山友会">
  </a>

  <input type="checkbox" id="menu-navibtn">
  <label id="navibtn" for="menu-navibtn"><span></span></label>

  <?php include __DIR__ . '/_nav.php'; ?>
</header>