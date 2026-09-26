<?php
/** @var array $lines @var ?array $focus */
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$ro = read_only();
$maxAbs = 1.0;
foreach ($lines as $l) { $maxAbs = max($maxAbs, abs($l['result']), abs($l['obj'])); }
?>
<h1>Bilan</h1>
<p class="muted">Montants HT (TTC ÷ 1,2), comme votre tableur historique. Les coûts sont saisis à la main ; les recettes viennent du listing.</p>

<?php if ($focus): $e = $focus['event']; ?>
<div class="card">
  <div class="spread"><h2 style="margin:0">Bilan · <?= e($e['circuit_name']) ?> <?= e(fdate($e['event_date'])) ?></h2><a class="btn sec sm" href="/listing?event=<?= (int)$e['id'] ?>">Retour listing</a></div>
  <div class="kpis">
    <?php foreach (['AVR', 'CB', 'ESP', 'DIV', 'VIR', 'Reste'] as $k): ?><div class="kpi"><span><?= e($k) ?></span><b><?= e($eur($focus['rev'][$k])) ?></b></div><?php endforeach; ?>
    <div class="kpi"><span>Résultat</span><b><?= e($eur($focus['result'])) ?></b></div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Bilan annuel</h2>
  <p class="muted">Avoir (AVR) affiché à part : il n’entre pas dans les recettes. Résultat = recettes − coûts. Avances Billetweb sans mode explicite : classées en CB.</p>
  <div class="scroll"><table class="bilan" id="bilan"><thead><tr>
    <th>Date</th><th>Circuit</th><th>Piste</th><th>Assu</th><th>RH</th><th>Service</th><th>Dépl</th><th>Autres</th><th>Coûts</th><th>AVR</th><th>CB</th><th>ESP</th><th>DIV</th><th>VIR</th><th>Reste</th><th>Recettes</th><th>Résultat</th><th>Cumulé</th><th>Objectif</th><th>Obj cum.</th><th>Écart</th><th>Écart cum.</th>
  </tr></thead><tbody>
  <?php foreach ($lines as $l): $e = $l['event']; $f = $l['finance']; ?>
    <tr data-event="<?= (int)$e['id'] ?>">
      <td><a href="/bilan?event=<?= (int)$e['id'] ?>"><?= e(fdate($e['event_date'], 'd.m')) ?></a></td>
      <td><?= e($e['circuit_name']) ?></td>
      <?php foreach (['piste', 'assurance', 'rh', 'service', 'deplacement', 'autres'] as $field): ?>
        <td><input class="edit-fin" data-field="<?= e($field) ?>" value="<?= e(plain_num($f[$field])) ?>" <?= $ro ? 'readonly' : '' ?>></td>
      <?php endforeach; ?>
      <td class="money"><b><?= e($eur($l['cost'])) ?></b></td>
      <?php foreach (['AVR', 'CB', 'ESP', 'DIV', 'VIR', 'Reste'] as $k): ?><td class="money"><?= e($eur($l['rev'][$k])) ?></td><?php endforeach; ?>
      <td class="money"><b><?= e($eur($l['revenue'])) ?></b></td>
      <td class="money <?= $l['result'] >= 0 ? 'good' : 'bad' ?>"><b><?= e($eur($l['result'])) ?></b></td>
      <td class="money"><?= e($eur($l['cum'])) ?></td>
      <td><input class="edit-fin" data-field="objectif" value="<?= e(plain_num($l['obj'])) ?>" <?= $ro ? 'readonly' : '' ?>></td>
      <td class="money"><?= e($eur($l['obj_cum'])) ?></td>
      <td class="money <?= $l['gap'] >= 0 ? 'good' : 'bad' ?>"><?= e($eur($l['gap'])) ?></td>
      <td class="money <?= $l['gap_cum'] >= 0 ? 'good' : 'bad' ?>"><?= e($eur($l['gap_cum'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card">
  <h2>Résultat et objectif par journée</h2>
  <?php foreach ($lines as $l): $w1 = (int)round(100 * abs($l['result']) / $maxAbs); $w2 = (int)round(100 * abs($l['obj']) / $maxAbs); ?>
    <div class="row" style="gap:8px;margin:3px 0;flex-wrap:nowrap">
      <span style="width:52px"><?= e(fdate($l['event']['event_date'], 'd.m')) ?></span>
      <div class="grow">
        <div class="bar" title="Résultat <?= e($eur($l['result'])) ?>" style="height:9px"><i style="width:<?= $w1 ?>%;background:<?= $l['result'] >= 0 ? '#15803d' : '#b91c1c' ?>"></i></div>
        <div class="bar" title="Objectif <?= e($eur($l['obj'])) ?>" style="height:5px"><i style="width:<?= $w2 ?>%;background:#999"></i></div>
      </div>
      <span style="width:90px;text-align:right"><?= e($eur($l['result'])) ?></span>
    </div>
  <?php endforeach; ?>
  <div class="muted">Barre épaisse : résultat (vert si positif, rouge si négatif). Barre fine grise : objectif.</div>
</div>
