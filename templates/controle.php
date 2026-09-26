<?php
/** @var array $event @var array $groups @var array $checks @var int $total */
$eid = (int)$event['id'];
$lv = ['ko' => 'ko', 'warn' => 'warn', 'info' => 'info'];
?>
<div class="spread">
  <div>
    <h1>Contrôle · <?= e($event['circuit_name']) ?> · <?= e(fdate($event['event_date'])) ?></h1>
    <div class="muted">Anomalies à corriger avant le jour J (<?= (int)$total ?> inscrit(s) actif(s)).</div>
  </div>
  <a class="btn sec sm" href="/listing?event=<?= $eid ?>">Ouvrir le listing</a>
</div>
<div class="pills">
<?php foreach (array_merge($groups['visible'], $groups['future']) as $x): ?>
  <a class="pill <?= (int)$x['id'] === $eid ? 'on' : '' ?>" href="/controle?event=<?= (int)$x['id'] ?>"><?= e(fdate($x['event_date'], 'd.m') . ' · ' . $x['circuit_name']) ?></a>
<?php endforeach; ?>
</div>

<div class="kpis">
<?php foreach ($checks as $c): ?>
  <a href="#c-<?= e($c['id']) ?>" style="text-decoration:none"><div class="kpi"><span><?= e($c['title']) ?></span><b><span class="tag <?= count($c['rows']) === 0 ? 'ok' : e($lv[$c['level']]) ?>" style="font-size:18px"><?= count($c['rows']) ?></span></b></div></a>
<?php endforeach; ?>
</div>

<?php foreach ($checks as $c): if (!$c['rows']) continue; ?>
<div class="card" id="c-<?= e($c['id']) ?>">
  <h2><span class="tag <?= e($lv[$c['level']]) ?>"><?= count($c['rows']) ?></span> <?= e($c['title']) ?></h2>
  <div class="scroll"><table class="t"><thead><tr><th>Participant</th><th>Véhicule</th><th>REF</th><th>Format</th><th>Reste</th><th>Dossier</th></tr></thead><tbody>
  <?php foreach ($c['rows'] as $r): ?>
    <tr>
      <td><a href="/listing?event=<?= $eid ?>#row-<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
      <td class="muted"><?= e($r['vehicle']) ?></td>
      <td><b><?= e($r['reference']) ?></b></td>
      <td><?= e($r['code']) ?></td>
      <td><?= $r['price_known'] ? e(money($r['remaining'])) : '?' ?></td>
      <td><span class="dot <?= e($r['progress']['state']) ?>"></span> <?= e($r['progress']['label']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endforeach; ?>
<?php if (!array_filter($checks, fn($c) => $c['rows'])): ?><div class="card"><span class="tag ok">Tout est en ordre</span> Aucune anomalie détectée pour cette journée.</div><?php endif; ?>
