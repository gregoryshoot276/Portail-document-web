<?php
/** @var array $event @var array $groups @var string $mode @var array $cars @var array $log @var int $total @var bool $urtime */
$eid = (int)$event['id'];
$ro = read_only();
?>
<div class="spread">
  <div>
    <h1>RFID roulage · <?= e($event['circuit_name']) ?> · <?= e(fdate($event['event_date'])) ?></h1>
    <div class="muted">Affectation rapide REF ↔ puce, journal des bips, et sessions de roulage issues d’URTime.</div>
  </div>
  <div class="row">
    <?php if (can('listing.view')): ?><a class="btn sec sm" href="/listing?event=<?= $eid ?>">Listing</a><?php endif; ?>
    <?php if (can('starter')): ?><a class="btn sec sm" href="/starter?event=<?= $eid ?>">Starter</a><?php endif; ?>
  </div>
</div>
<div class="pills">
<?php foreach ($groups['visible'] as $x): ?>
  <a class="pill <?= (int)$x['id'] === $eid ? 'on' : '' ?>" href="/rfid?event=<?= (int)$x['id'] ?>&amp;mode=<?= e($mode) ?>"><?= e(fdate($x['event_date'], 'd.m') . ' · ' . $x['circuit_name']) ?></a>
<?php endforeach; ?>
</div>
<div class="pills">
  <a class="pill <?= $mode === 'assign' ? 'on' : '' ?>" href="/rfid?event=<?= $eid ?>&amp;mode=assign">Affectation puces</a>
  <a class="pill <?= $mode === 'log' ? 'on' : '' ?>" href="/rfid?event=<?= $eid ?>&amp;mode=log">Passages / bips (<?= (int)$total ?>)</a>
  <a class="pill <?= $mode === 'roulage' ? 'on' : '' ?>" href="/rfid?event=<?= $eid ?>&amp;mode=roulage">Sessions URTime</a>
</div>

<?php if ($mode === 'assign'): ?>
  <div class="scroll" style="max-width:760px"><table class="t assign"><thead><tr><th>REF</th><th>Puce RFID</th><th>Véhicule</th></tr></thead><tbody>
  <?php foreach ($cars as $r): if ($r['cancelled']) continue; ?>
    <tr><td class="ref"><?= e($r['ref'] !== '' ? $r['ref'] : 'SANS REF') ?></td>
      <td><input class="tag-in" data-entry="<?= (int)$r['id'] ?>" value="<?= e($r['tag']) ?>" placeholder="Scanner / saisir la puce puis Entrée" <?= $ro ? 'readonly' : '' ?> autocomplete="off"></td>
      <td class="muted"><?= e($r['vehicle']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="muted">Astuce : après Entrée, le curseur passe automatiquement à la ligne suivante.</p>

<?php elseif ($mode === 'log'): ?>
  <?php if (!$ro): ?>
  <div class="scanbox"><b>Noter la REF :</b><input id="scan" autocomplete="off" placeholder="Ex. B600 puis Entrée"><span id="scan-msg" class="msg"></span></div>
  <?php endif; ?>
  <div class="field" style="max-width:720px"><input id="vsearch" placeholder="Rechercher un véhicule pour retrouver sa REF…" style="width:100%"><div id="vresults" class="row" style="margin-top:6px"></div></div>
  <div class="scroll"><table class="t"><thead><tr><th>Bip</th><th>Heure</th><th>REF</th><th>Véhicule</th><th>Puce</th><th>Format</th></tr></thead><tbody>
  <?php foreach ($log as $i => $r): ?>
    <tr><td><b>#<?= (int)($total - $i) ?></b></td><td><?= e($r['scanned_at']) ?></td><td><b><?= e(($r['ref'] ?? '') !== '' ? $r['ref'] : '—') ?></b></td><td><?= e(($r['vehicle'] ?? '') !== '' ? $r['vehicle'] : '—') ?></td><td><?= e($r['tag_uid']) ?></td><td><?= e(($r['format'] ?? '') !== '' ? $r['format'] : '—') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="6" class="muted">Aucun passage enregistré.</td></tr><?php endif; ?>
  </tbody></table></div>

<?php else: ?>
  <?php if (!$urtime): ?><div class="flash warn">Aucune clé API URTime trouvée (réglage <code>urtime_api_key</code> de l’ancien portail ou variable d’environnement <code>URTIME_API_KEY</code>).</div><?php endif; ?>
  <div class="card">
    <div class="row">
      <button class="btn sm" id="ur-load" type="button" <?= $urtime ? '' : 'disabled' ?>>Charger les événements URTime</button>
      <select id="ur-event" style="min-width:260px"><option value="">— événement URTime —</option></select>
      <label class="row" style="gap:4px">Du <input type="datetime-local" id="ur-start"></label>
      <label class="row" style="gap:4px">Au <input type="datetime-local" id="ur-end"></label>
      <button class="btn sm" id="ur-go" type="button" disabled>Calculer les sessions</button>
    </div>
    <p class="muted" style="margin-bottom:0">Chaque voiture (puce) : une détection ouvre une session, la suivante la ferme. Les puces sont rapprochées de la REF, du pilote et du véhicule du listing.</p>
  </div>
  <div id="ur-out"></div>
<?php endif; ?>
<script type="application/json" id="cars"><?= json_encode(array_values(array_map(fn($r) => ['ref' => $r['ref'], 'vehicle' => $r['vehicle'], 'format' => $r['format']], $cars)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
