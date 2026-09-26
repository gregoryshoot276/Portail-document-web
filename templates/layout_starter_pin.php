<?php
use JC\Core\Csrf;

$boot = $boot ?? null;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(Csrf::token()) ?>">
<title><?= e($pageTitle ?? 'Starter') ?> · Journée Circuit</title>
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<meta name="theme-color" content="#141414">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e($appTitle ?? 'JC Starter') ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('public.css')) ?>">
</head>
<body data-page="<?= e($page ?? '') ?>" data-readonly="<?= read_only() ? '1' : '0' ?>">
<div class="wrap" style="max-width:1100px;margin:0 auto;padding:16px">
<?php foreach (flash_take() as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php if (read_only()): ?>
  <div class="robanner"><b>Mode lecture seule.</b> Les contrôles ne sont pas enregistrés pour l’instant.</div>
<?php endif; ?>
<?= $content ?>
</div>
<?php if ($boot !== null): ?><script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
