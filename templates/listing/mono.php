<?php
/** @var array $event @var array $rows */
$eid = (int)$event['id'];
?>
<p><a href="/listing?event=<?= $eid ?>">← Listing</a></p>
<h1>Premières fois — Export MONO</h1>
<div class="spread">
  <div class="muted"><?= e($event['circuit_name'] . ' — ' . fdate($event['event_date'])) ?> · <?= count($rows) ?> personne(s)</div>
  <div class="row">
    <input type="search" id="q" placeholder="Filtrer…" style="min-width:220px">
    <a class="btn sec sm" href="/listing/<?= $eid ?>/mono?format=csv">Export CSV</a>
    <button class="btn sec sm" type="button" id="mono-print">Imprimer</button>
  </div>
</div>
<div class="scroll"><table class="t" id="monoTable">
  <thead><tr><th>REF</th><th>Participant</th><th>Véhicule</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-search="<?= e(mb_strtolower($r['reference'] . ' ' . $r['name'] . ' ' . $r['vehicle'], 'UTF-8')) ?>">
      <td><b><?= e($r['reference'] ?: '—') ?></b></td><td><?= e($r['name']) ?></td><td><?= e($r['vehicle'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="3" class="muted">Aucune personne marquée « Première fois » pour cette journée.</td></tr><?php endif; ?>
  </tbody>
</table></div>
