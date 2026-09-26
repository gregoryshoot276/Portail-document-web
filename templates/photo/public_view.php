<?php
/** @var array $event @var array $groups @var array $rows @var string $k */
$eid = (int)$event['id'];
$fmtLabel = fn($e) => fdate($e['event_date'], 'd.m') . ' · ' . $e['circuit_name'];
$kq = '&k=' . e($k);
?>
<h1>Photographe</h1>
<div class="pills">
<?php foreach (array_merge($groups['visible'], $groups['future']) as $e): ?>
  <a class="pill <?= (int)$e['id'] === $eid ? 'on' : '' ?>" href="/photo-public?event=<?= (int)$e['id'] ?><?= $kq ?>"><?= e($fmtLabel($e)) ?></a>
<?php endforeach; ?>
</div>

<div class="spread">
  <div class="muted"><?= e($event['circuit_name'] . ' — ' . fdate($event['event_date'])) ?> · <?= count($rows) ?> véhicule(s) en roulage</div>
  <div class="row">
    <input type="search" id="q" placeholder="Rechercher nom, REF, voiture, mail…" style="min-width:240px">
    <a class="btn sec sm" href="/photo-public?event=<?= $eid ?>&export=csv<?= $kq ?>">Export CSV</a>
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
