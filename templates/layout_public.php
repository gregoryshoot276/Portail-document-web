<?php
use JC\Core\Csrf;
use JC\Core\CustomerAuth;
use JC\Domain\I18n;

$boot = $boot ?? null;
$q = $_GET;
unset($q['lang']);
$customer = CustomerAuth::user();
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(Csrf::token()) ?>">
<title><?= e($pageTitle ?? 'Journée Circuit') ?></title>
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>">
<link rel="manifest" href="<?= e(asset('manifest-public.json')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<meta name="theme-color" content="#c21819">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Journée Circuit">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('public.css')) ?>">
</head>
<body class="pub">
<noscript><div class="flash ko" style="margin:16px;border-radius:10px">Ce formulaire nécessite JavaScript pour fonctionner (vérification des inscriptions, décharge, signature). Merci d’activer JavaScript dans votre navigateur, ou d’essayer avec un autre navigateur (Chrome, Safari, Firefox à jour).</div></noscript>
<header class="pubbar"><div class="pubwrap">
  <a class="brand" href="/participer"><img src="<?= e(asset('logo-jc.png')) ?>" alt="Journée Circuit"></a>
  <nav class="langs" aria-label="Language">
  <?php foreach (I18n::LANGS as $code => $label): ?>
    <a href="?<?= e(http_build_query($q + ['lang' => $code])) ?>" class="<?= I18n::lang() === $code ? 'on' : '' ?>" title="<?= e($label) ?>"><img src="<?= e(asset('flag-' . ($code === 'en' ? 'gb' : $code) . '.svg')) ?>" alt="<?= e($label) ?>"></a>
  <?php endforeach; ?>
  </nav>
  <div class="accountbox">
    <?php if ($customer): ?>
      <a href="/participer"><?= e((string)($customer['prenom'] ?? $customer['email'] ?? '')) ?></a>
    <?php else: ?>
      <a href="/mon-espace"><?= e(I18n::t('my_space')) ?></a>
    <?php endif; ?>
  </div>
</div></header>
<main class="pubwrap">
<?= $content ?>
</main>
<?php if ($boot !== null): ?><script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
<script src="<?= e(asset('public.js')) ?>" defer></script>
</body>
</html>
