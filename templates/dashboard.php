<div class="spread">
  <div>
    <h1>Tableau de bord</h1>
    <div class="muted">Prochaines journées et état des dossiers.</div>
  </div>
  <?php if (can('dossiers.view') && $toReview > 0): ?>
    <a class="btn" href="/dossiers?status=to_review"><?= (int)$toReview ?> dossier(s) à traiter</a>
  <?php endif; ?>
</div>

<?php if (!$cards): ?>
  <div class="card" style="margin-top:14px">Aucune journée active à venir.</div>
<?php endif; ?>

<div class="grid" style="margin-top:14px">
<?php foreach ($cards as $c): $e = $c['event']; $ok = $c['status']['validated'] ?? 0; $pct = $c['dossiers'] > 0 ? (int)round(100 * $ok / $c['dossiers']) : 0; ?>
  <div class="evcard <?= $c['past'] ? 'past' : '' ?>">
    <div class="spread">
      <div class="d"><?= e(fdate($e['event_date'], 'd/m')) ?> <span class="muted" style="font-size:13px;font-weight:600"><?= e(strftime_fr($e['event_date'])) ?></span></div>
      <?php if ($c['past']): ?><span class="tag gr">Passée</span><?php endif; ?>
    </div>
    <div style="font-weight:700;margin-bottom:6px"><?= e($e['circuit_name']) ?></div>
    <div class="muted"><?= (int)$c['entries'] ?> inscrit(s) · <?= (int)$c['dossiers'] ?> dossier(s) · <?= (int)$c['waivers'] ?> décharge(s)</div>
    <div class="bar" title="<?= (int)$pct ?> % de dossiers validés"><i style="width:<?= (int)$pct ?>%"></i></div>
    <div class="row" style="gap:6px;margin-bottom:8px">
      <?php if (($c['status']['to_review'] ?? 0) > 0): ?><span class="tag warn"><?= (int)$c['status']['to_review'] ?> à vérifier</span><?php endif; ?>
      <?php if (($c['status']['pending'] ?? 0) > 0): ?><span class="tag info"><?= (int)$c['status']['pending'] ?> en attente</span><?php endif; ?>
      <?php if (($c['status']['rejected'] ?? 0) > 0): ?><span class="tag ko"><?= (int)$c['status']['rejected'] ?> refusé(s)</span><?php endif; ?>
      <span class="tag ok"><?= (int)$ok ?> validé(s)</span>
    </div>
    <div class="row" style="gap:6px">
      <?php if (can('listing.view')): ?><a class="btn sm" href="/listing?event=<?= (int)$e['id'] ?>">Listing</a><?php endif; ?>
      <?php if (can('starter')): ?><a class="btn sm sec" href="/starter?event=<?= (int)$e['id'] ?>">Starter</a><?php endif; ?>
      <?php if (can('rfid.view')): ?><a class="btn sm sec" href="/rfid?event=<?= (int)$e['id'] ?>">RFID</a><?php endif; ?>
      <?php if (can('dossiers.view')): ?><a class="btn sm sec" href="/dossiers?event=<?= (int)$e['id'] ?>">Dossiers</a><?php endif; ?>
      <?php if (can('listing.view')): ?><a class="btn sm sec" href="/controle?event=<?= (int)$e['id'] ?>">Contrôle</a><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
