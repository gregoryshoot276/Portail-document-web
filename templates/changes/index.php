<?php
$ro = read_only();
$sc = ['pending' => 'info', 'processed' => 'ok'];
$sl = ['pending' => 'À traiter', 'processed' => 'Traité'];
$tl = ['cancellation' => 'ANNULATION', 'replacement' => 'REMPLACEMENT'];
?>
<h1>Annulations / remplacements</h1>
<p class="muted">Demandes envoyées via le formulaire public (<code>/annulation</code>). Le rafraîchissement BilletWeb ci-dessous est le même que celui du Listing (limité à une fois toutes les 90 s, sauf ici où le clic est toujours forcé).</p>

<?php if (!$changes): ?>
  <div class="card"><p class="muted">Aucune demande pour l’instant.</p></div>
<?php endif; ?>

<?php foreach ($changes as $r): ?>
  <div class="card">
    <div class="spread">
      <div>
        <strong><?= e($r['prenom'] . ' ' . $r['nom']) ?></strong> · <?= e($r['email']) ?>
        <div class="muted"><?= e($r['circuit_name']) ?> <?= e(fdate($r['event_date'])) ?> · reçu le <?= e(fdate($r['created_at'], 'd/m/Y H:i')) ?></div>
      </div>
      <div class="row" style="gap:6px">
        <span class="tag <?= $r['request_type'] === 'replacement' ? 'warn' : 'gr' ?>"><?= e($tl[$r['request_type']] ?? $r['request_type']) ?></span>
        <span class="tag <?= e($sc[$r['status']] ?? 'gr') ?>"><?= e($sl[$r['status']] ?? $r['status']) ?></span>
      </div>
    </div>

    <?php if ($r['request_type'] === 'replacement'): ?>
      <p>→ Remplaçant : <strong><?= e($r['replacement_prenom'] . ' ' . $r['replacement_nom']) ?></strong> · <?= e($r['replacement_email']) ?><?= $r['replacement_phone'] ? ' · ' . e($r['replacement_phone']) : '' ?><?= $r['replacement_vehicle'] ? ' · ' . e($r['replacement_vehicle']) : '' ?></p>
    <?php endif; ?>

    <?php if (!empty($r['sync_note'])): ?><p class="muted">Dernier rafraîchissement BilletWeb : <?= e($r['sync_note']) ?></p><?php endif; ?>

    <?php if (!$ro): ?>
      <form method="post" action="/changements/<?= (int)$r['id'] ?>/decision" class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <div class="field" style="margin:0;flex:1;min-width:220px"><label>Note admin</label><input name="admin_note" value="<?= e($r['admin_note'] ?? '') ?>"></div>
        <?php if ($r['status'] === 'pending'): ?>
          <button class="btn" type="submit" name="action" value="process" title="<?= $r['request_type'] === 'replacement' ? 'Marque traité et envoie au remplaçant le lien pour créer son dossier' : 'Marque traité' ?>">Marquer traité<?= $r['request_type'] === 'replacement' ? ' + prévenir le remplaçant' : '' ?></button>
        <?php else: ?>
          <button class="btn sec" type="submit" name="action" value="note">Enregistrer la note</button>
          <button class="btn sec" type="submit" name="action" value="reopen">Repasser à traiter</button>
        <?php endif; ?>
        <button class="btn sec" type="submit" name="action" value="sync">↻ Rafraîchir BilletWeb</button>
        <a class="btn sec" href="/listing?event=<?= (int)$r['event_id'] ?>" target="_blank" rel="noopener">Ouvrir le Listing de cette journée</a>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
