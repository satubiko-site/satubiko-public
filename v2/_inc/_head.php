<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-X3R3DEHC1B"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-X3R3DEHC1B');
</script>

<?php if (!defined('APP_BOOTSTRAPPED')) exit; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta http-equiv="content-language" content="ja" />
<meta name="robots" content="index,follow" />
<meta name="application-name" content="札幌山びこ山友会">
<meta name="description" content="札幌山びこ山友会は、北海道を主な活動範囲とした札幌の山岳会です。">
<title><?= $page_title ?? '札幌山びこ山友会' ?></title>
<link rel="stylesheet" href="<?= asset('base.css') ?>">
<link rel="stylesheet" href="<?= asset('page.css') ?>">
<link rel="shortcut icon" href="<?= asset('gif/yamalogo.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<?php if (!empty($extra_css ?? [])) foreach ($extra_css as $css): ?>
<link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>

<script src="<?= asset('menu.js') ?>" defer></script>
<?php if (!empty($extra_js ?? [])) foreach ($extra_js as $js): ?>
<script src="<?= asset($js) ?>" defer></script>
<?php endforeach; ?>
