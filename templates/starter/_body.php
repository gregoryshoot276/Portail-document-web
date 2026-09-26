<?php
/** Partagé entre starter/index.php (admin) et starter/pin_view.php (accès isolé par code).
 * @var array $bracelets @var array $pending @var array $attention @var int $done @var \Closure $pill @var array $log */
use JC\Domain\StarterLog;
?>
<div class="row bracelets" id="bracelets" style="margin:10px 0">
  <?php foreach (['pilot' => 'Pilote', 'pilot_f' => 'Pilote femme', 'passenger' => 'Passager'] as $bk => $bl): ?>
    <label class="bracelet-chip" title="<?= !read_only() ? 'Cliquer pour changer la couleur du bracelet ' . e(mb_strtolower($bl, 'UTF-8')) : '' ?>">
      <span class="swatch" style="background:<?= e($bracelets[$bk] ?? '#ccc') ?>"></span> <?= e($bl) ?>
      <?php if (!read_only()): ?><input type="color" data-bracelet="<?= e($bk) ?>" value="<?= e($bracelets[$bk] ?? '#cccccc') ?>"><?php endif; ?>
    </label>
  <?php endforeach; ?>
</div>
<div class="kpis">
  <div class="kpi"><span>À contrôler</span><b><?= count($pending) ?></b></div>
  <div class="kpi"><span>Contrôlées OK</span><b><?= $done ?></b></div>
  <div class="kpi"><span>À corriger / revalider</span><b><?= count($attention) ?></b></div>
</div>
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(240px,1fr);gap:18px;align-items:start" class="split"><?php /* .split : une seule colonne sur téléphone (voir app.css) */ ?>
  <section><h2>À contrôler</h2><div class="pgrid" id="gp"><?php foreach ($pending as $r) echo $pill($r); ?></div></section>
  <aside><h2>À corriger / revalider</h2><div class="pgrid" id="ga"><?php foreach ($attention as $r) echo $pill($r); ?></div></aside>
</div>

<section class="card" id="starter-log" style="margin-top:18px">
  <h2 style="margin-top:0">Main courante</h2>
  <?php if (!read_only()): ?>
  <div class="row" style="align-items:flex-start">
    <input type="text" id="sl-reason" placeholder="Motif (facultatif)" class="grow">
    <button type="button" class="btn" id="sl-red" style="background:var(--ko)">🔴 Piste arrêtée</button>
    <button type="button" class="btn" id="sl-green" style="background:var(--ok)">🟢 Piste relancée</button>
    <button type="button" class="btn sec" id="sl-yellow">🟡 Note</button>
  </div>
  <?php else: ?><p class="muted">Lecture seule : la main courante ne peut pas être complétée.</p><?php endif; ?>
  <div class="muted" style="margin-top:10px" id="sl-list">
    <?php if (!$log): ?>Aucune entrée pour l’instant.<?php else: foreach ($log as $e): ?>
      <div class="sl-entry sl-<?= e($e['status']) ?>">
        <b><?= e(fdate($e['created_at'], 'H:i')) ?></b> — <?= e(StarterLog::STATUSES[$e['status']] ?? $e['status']) ?><?= $e['reason'] !== '' ? ' — ' . e($e['reason']) : '' ?><?= $e['created_by'] !== '' ? ' <span class="muted">(' . e($e['created_by']) . ')</span>' : '' ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<dialog id="dlg-st">
  <div class="bigref" id="st-ref"></div>
  <div class="bigveh" id="st-veh"></div>
  <div class="muted" style="text-align:center" id="st-fmt"></div>
  <div class="choices"><button type="button" class="yes" data-status="ok" <?= read_only() ? 'disabled' : '' ?>>OK</button><button type="button" class="no" data-status="nok" <?= read_only() ? 'disabled' : '' ?>>PAS OK</button></div>
  <?php if (!read_only()): ?>
  <div class="row" style="margin-top:14px"><input id="st-refedit" placeholder="Modifier la REF" class="grow"><button type="button" class="btn sec sm" id="st-refsave">Modifier REF</button></div>
  <?php else: ?><p class="muted" style="text-align:center;margin-bottom:0">Lecture seule : le contrôle n’est pas enregistré.</p><?php endif; ?>
  <div class="muted" style="text-align:center;margin-top:10px" id="st-meta"></div>
  <div style="text-align:center;margin-top:12px"><button type="button" class="btn sec" data-close>Fermer</button></div>
</dialog>
