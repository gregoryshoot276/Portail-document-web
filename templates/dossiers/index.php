<?php
use JC\Domain\Dossiers;

/** @var array $f @var array $todo @var array $done @var array $circuits @var array $events @var array $admins */
$sc = fn(?string $s) => match ($s) { 'validated' => 'ok', 'rejected' => 'ko', 'to_review' => 'warn', 'pending' => 'info', default => 'gr' };
$link = function (array $change = []) use ($f): string {
    $q = array_merge($f, $change);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return '/dossiers' . ($q ? '?' . http_build_query($q) : '');
};
// Liste ordonnée transmise à chaque dossier (« à traiter » puis « validés », dans l'ordre affiché) pour permettre
// d'y naviguer au suivant/précédent sans revenir sans cesse à ce tableau.
$ctx = implode(',', array_merge(array_column($todo, 'id'), array_column($done, 'id')));
$renderRows = function (array $rows, string $group) use ($sc, $ctx): void {
    foreach ($rows as $r):
        $search = mb_strtolower($r['nom'] . ' ' . $r['prenom'] . ' ' . $r['email'] . ' ' . $r['circuit_name'], 'UTF-8'); ?>
    <tr data-group="<?= e($group) ?>" data-search="<?= e($search) ?>">
      <td><a href="/dossiers/<?= (int)$r['id'] ?>?ctx=<?= e($ctx) ?>"><b><?= e($r['nom']) ?></b> <?= e($r['prenom']) ?></a>
        <?php if ($r['updated_after_reject']): ?><span class="tag warn" title="Un document a été renvoyé après un refus">Mis à jour</span><?php endif; ?>
        <div class="muted"><?= e($r['email']) ?></div></td>
      <td><?= e(fdate($r['event_date'], 'd/m/Y')) ?><div class="muted"><?= e($r['circuit_name']) ?></div></td>
      <td><?= e(Dossiers::TYPE_LABELS[$r['participant_type']] ?? $r['participant_type']) ?><div class="muted"><?= e($r['vehicle']) ?></div></td>
      <td><span class="tag <?= $sc($r['permis_status']) ?>"><?= e(Dossiers::STATUS_LABELS[$r['permis_status']] ?? '—') ?></span></td>
      <td><span class="tag <?= $sc($r['assurance_status']) ?>"><?= e(Dossiers::STATUS_LABELS[$r['assurance_status']] ?? '—') ?></span></td>
      <td><span class="tag <?= $r['has_waiver'] ? 'ok' : 'gr' ?>"><?= $r['has_waiver'] ? 'Signée' : 'Non' ?></span></td>
      <td><span class="tag <?= $sc($r['global_status']) ?>"><?= e(Dossiers::STATUS_LABELS[$r['global_status']] ?? $r['global_status']) ?></span></td>
      <td class="muted"><?= e(fdate($r['created_at'], 'd/m H:i')) ?></td>
    </tr>
<?php endforeach; };
?>
<h1>Dossiers</h1>
<?php if (!empty($staleInsurance)): ?>
  <div class="flash warn">
    <b><?= count($staleInsurance) ?> case(s) « Assurance » du Listing figée(s)</b> sur une ancienne valeur alors que le document est encore en attente de vérification (séquelle d'un bug corrigé) :
    <ul style="margin:6px 0 10px">
      <?php foreach ($staleInsurance as $s): ?>
        <li><?= e($s['prenom'] . ' ' . $s['nom']) ?> — <?= e($s['circuit_name']) ?> <?= e(fdate($s['event_date'])) ?> (affichait « <?= e($s['override_value'] ?: 'vide') ?> »)</li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="/dossiers/nettoyer-assurance">
      <?= csrf_field() ?>
      <button class="btn sec sm" type="submit" data-confirm="Débloquer ces <?= count($staleInsurance) ?> case(s) Assurance ? Le Listing affichera de nouveau le vrai statut du document (jamais l'inverse : ça ne fera jamais apparaître une case validée à tort).">Débloquer ces cases</button>
    </form>
  </div>
<?php endif; ?>
<div class="pills">
  <a class="pill <?= $f['type'] === 'pilot' ? 'on' : '' ?>" href="<?= e($link(['type' => 'pilot'])) ?>">Pilotes</a>
  <a class="pill <?= $f['type'] === 'passenger' ? 'on' : '' ?>" href="<?= e($link(['type' => 'passenger'])) ?>">Passagers</a>
</div>
<div class="pills">
  <a class="pill <?= !$f['event'] ? 'on' : '' ?>" href="<?= e($link(['event' => 0, 'q' => ''])) ?>">Toutes les journées</a>
  <?php foreach (array_merge($events['visible'], $events['future']) as $e): ?>
    <a class="pill <?= (int)$f['event'] === (int)$e['id'] ? 'on' : '' ?>" href="<?= e($link(['event' => (int)$e['id'], 'q' => '', 'circuit' => 0])) ?>"><?= e(fdate($e['event_date'], 'd.m') . ' · ' . $e['circuit_name']) ?></a>
  <?php endforeach; ?>
</div>
<form method="get" action="/dossiers" class="card row" id="dossiers-filters">
  <input type="hidden" name="type" value="<?= e($f['type']) ?>">
  <?php if ($f['event']): ?><input type="hidden" name="event" value="<?= (int)$f['event'] ?>"><?php endif; ?>
  <input type="search" name="q" id="df-q" value="<?= e($f['q']) ?>" placeholder="Nom, prénom, e-mail, journée… (filtre instantané)" class="grow" autocomplete="off">
  <select name="status"><option value="">Tous les statuts</option><?php foreach (Dossiers::STATUS_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if (!$f['event']): ?>
  <select name="circuit"><option value="">Tous les circuits</option><?php foreach ($circuits as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$f['circuit'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select name="reviewer"><option value="">Tous les valideurs</option><?php foreach ($admins as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$f['reviewer'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
  <select name="sort"><option value="oldest" <?= $f['sort'] !== 'newest' ? 'selected' : '' ?>>Plus anciens d’abord</option><option value="newest" <?= $f['sort'] === 'newest' ? 'selected' : '' ?>>Plus récents d’abord</option></select>
  <label class="row" style="gap:4px"><input type="checkbox" name="archived" value="1" <?= $f['archived'] ? 'checked' : '' ?>> Archivés</label>
  <noscript><button class="btn" type="submit">Filtrer</button></noscript>
</form>

<h2 id="todo-count" style="margin:18px 0 6px"><?= count($todo) ?> dossier(s) à traiter</h2>
<div class="scroll scroll-dossiers-todo"><table class="t dossiers-table" id="dossiers-table-todo">
  <thead><tr><th>Participant</th><th>Journée</th><th>Type / véhicule</th><th>Permis</th><th>Assurance</th><th>Décharge</th><th>Statut</th><th>Créé</th></tr></thead>
  <tbody><?php $renderRows($todo, 'todo'); ?></tbody>
</table></div>

<h2 id="done-count" style="margin:24px 0 6px;color:#166534"><?= count($done) ?> dossier(s) validé(s)</h2>
<div class="scroll scroll-dossiers-done"><table class="t dossiers-table" id="dossiers-table-done">
  <thead><tr><th>Participant</th><th>Journée</th><th>Type / véhicule</th><th>Permis</th><th>Assurance</th><th>Décharge</th><th>Statut</th><th>Créé</th></tr></thead>
  <tbody><?php $renderRows($done, 'done'); ?></tbody>
</table></div>
<p class="muted">1 000 dossiers maximum affichés (recherche instantanée sur ce qui est déjà chargé ; les autres filtres s'appliquent immédiatement).</p>
