<?php
$boot = $boot ?? null;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($pageTitle ?? 'Journée Circuit') ?></title>
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('public.css')) ?>">
</head>
<body class="pub" style="background:#525659">
<?= $content ?>
<?php if ($boot !== null): ?><script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
<script src="<?= e(asset('official-reader.js')) ?>" defer></script>
</body>
</html>
