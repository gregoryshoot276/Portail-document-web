<?php
use JC\Core\Auth;
use JC\Core\Csrf;
use JC\Core\Http;

$path = Http::path();
// Regroupé par activité (bandeau latéral) plutôt qu'en une seule liste à plat, devenue trop longue.
$navGroups = [
    'Participant' => [
        ['/dossiers',          'Dossiers',           'dossiers.view',    fn($p) => str_starts_with($p, '/dossiers')],
        ['/listing',           'Listing',            'listing.view',     fn($p) => str_starts_with($p, '/listing')],
        ['/controle',          'Contrôle',           'listing.view',     fn($p) => str_starts_with($p, '/controle')],
        ['/billetweb-control', 'Contrôle Billetweb', 'billetweb_control',fn($p) => str_starts_with($p, '/billetweb-control')],
        ['/mailcenter',        'Mail Center',        'mailcenter',       fn($p) => str_starts_with($p, '/mailcenter')],
        ['/teams',             'Teams',              'teams',            fn($p) => str_starts_with($p, '/teams')],
        ['/avis',              'Avis & codes promo', 'reviews',          fn($p) => str_starts_with($p, '/avis')],
        ['/changements',       'Annulations / remplacements', 'changes', fn($p) => str_starts_with($p, '/changements')],
    ],
    'Logistique' => [
        ['/starter', 'Starter', 'starter',      fn($p) => str_starts_with($p, '/starter')],
        ['/rfid',    'RFID',    'rfid.view',    fn($p) => str_starts_with($p, '/rfid')],
        ['/photo',   'Photo',   'photo.view',   fn($p) => str_starts_with($p, '/photo')],
    ],
    'Bilan' => [
        ['/bilan',  'Bilan',  'finance',     fn($p) => str_starts_with($p, '/bilan')],
        ['/tarifs', 'Tarifs', 'tarifs.view', fn($p) => str_starts_with($p, '/tarifs')],
    ],
    'Administration' => [
        ['/diagnostic', 'Diagnostic', 'diagnostic', fn($p) => str_starts_with($p, '/diagnostic')],
        ['/settings',   'Réglages',   'settings',   fn($p) => str_starts_with($p, '/settings')],
    ],
];
$u = Auth::user();
$bodyClass = $bodyClass ?? '';
$page = $page ?? '';
$boot = $boot ?? null;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(Csrf::token()) ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?>Journée Circuit</title>
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>">
<link rel="manifest" href="<?= e(asset('manifest-admin.json')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<meta name="theme-color" content="#141414">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="JC Admin">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>" data-page="<?= e($page) ?>" data-readonly="<?= read_only() ? '1' : '0' ?>">
<div class="shell">
<nav class="nav sidenav">
  <a class="brand" href="/admin"><img src="<?= e(asset('logo-jc.png')) ?>" alt="Journée Circuit"></a>
  <a class="nl <?= $path === '/admin' ? 'on' : '' ?>" href="/admin">Accueil</a>
  <?php foreach ($navGroups as $groupLabel => $groupItems):
      $visible = array_filter($groupItems, fn($it) => $it[2] === null || can($it[2]));
      if (!$visible) continue; ?>
    <div class="navgroup">
      <div class="navgroup-title"><?= e($groupLabel) ?></div>
      <?php foreach ($visible as [$href, $label, $perm, $active]): ?>
        <a class="nl <?= $active($path) ? 'on' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <span class="sp"></span>
  <span class="modebadge <?= read_only() ? 'ro' : 'rw' ?>" title="<?= read_only() ? 'Aucune écriture en base de données' : 'Les modifications sont enregistrées' ?>"><?= read_only() ? 'LECTURE SEULE' : 'ÉCRITURE ACTIVE' ?></span>
  <?php if ($u): ?>
    <span class="userbox"><?= e($u['name']) ?> · <?= e(Auth::ROLE_LABELS[$u['role']] ?? $u['role']) ?></span>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="btn sec sm" type="submit">Déconnexion</button></form>
  <?php endif; ?>
</nav>

<div class="shell-main">
<main class="<?= e($mainClass ?? 'wrap') ?>">
<?php foreach (flash_take() as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php if (read_only() && ($showRoBanner ?? true)): ?>
  <div class="robanner"><b>Mode lecture seule.</b> Vous pouvez tout consulter et imprimer ; les modifications ne sont pas enregistrées. Utilisez l’ancien portail pour saisir.</div>
<?php endif; ?>
<?= $content ?>
</main>
</div>
</div>
<?php if ($boot !== null): ?><script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
