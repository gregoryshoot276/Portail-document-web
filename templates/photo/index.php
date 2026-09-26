<?php
/** @var array $event @var array $groups @var array $rows */
$eid = (int)$event['id'];
$fmtLabel = fn($e) => fdate($e['event_date'], 'd.m') . ' · ' . $e['circuit_name'];
?>
<div class="spread">
  <h1>Photographe</h1>
  <?php if (can('photo.view')): ?><a class="btn sec sm" href="/photo/acces">Accès sans connexion (PIN)</a><?php endif; ?>
</div>
<div class="pills">
<?php foreach (array_merge($groups['visible'], $groups['future']) as $e): ?>
  <a class="pill <?= (int)$e['id'] === $eid ? 'on' : '' ?>" href="/photo?event=<?= (int)$e['id'] ?>"><?= e($fmtLabel($e)) ?></a>
<?php endforeach; ?>
</div>

<div class="spread">
  <div class="muted"><?= e($event['circuit_name'] . ' — ' . fdate($event['event_date'])) ?> · <?= count($rows) ?> véhicule(s) en roulage</div>
  <div class="row">
    <input type="search" id="q" placeholder="Rechercher nom, REF, voiture, mail…" style="min-width:240px">
    <a class="btn sec sm" href="/photo?event=<?= $eid ?>&export=csv">Export CSV</a>
  </div>
</div>

<div class="scroll"><table class="t" id="photoTable">
  <thead><tr><th>Participant</th><th>Véhicule</th><th>REF</th><th>Format</th><th>Email</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['vehicle'] . ' ' . $r['reference'] . ' ' . $r['email'], 'UTF-8')) ?>">
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['vehicle']) ?></td>
      <td><b><?= e($r['reference'] ?: '—') ?></b></td>
      <td><?= e($r['duration']) ?></td>
      <td><?= e($r['email']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">Aucun véhicule en roulage pour cette journée.</td></tr><?php endif; ?>
  </tbody>
</table></div>
