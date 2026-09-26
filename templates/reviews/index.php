<?php
$ro = read_only();
$sc = ['pending' => 'info', 'approved' => 'ok', 'rejected' => 'ko'];
$sl = ['pending' => 'À vérifier', 'approved' => 'Validé', 'rejected' => 'Refusé'];
?>
<h1>Avis &amp; codes promo</h1>
<p class="muted">Validation humaine des avis Google/Facebook envoyés via le formulaire public (<code>/avis.php</code>). Le portail signale simplement si la même adresse a déjà reçu une récompense sur cette plateforme.</p>

<?php if (!$reviews): ?>
  <div class="card"><p class="muted">Aucun avis pour l’instant.</p></div>
<?php endif; ?>

<?php foreach ($reviews as $r): ?>
  <div class="card">
    <div class="spread">
      <div>
        <strong><?= e($r['prenom'] . ' ' . $r['nom']) ?></strong> · <?= e(ucfirst((string)$r['platform'])) ?>
        <?php if (!empty($r['circuit_name'])): ?> · <?= e($r['circuit_name']) ?><?= !empty($r['event_date']) ? ' ' . e(fdate($r['event_date'])) : '' ?><?php endif; ?>
        <div class="muted"><?= e($r['email']) ?> · reçu le <?= e(fdate($r['created_at'], 'd/m/Y H:i')) ?></div>
      </div>
      <span class="tag <?= e($sc[$r['status']] ?? 'gr') ?>"><?= e($sl[$r['status']] ?? $r['status']) ?></span>
    </div>

    <?php if ((int)$r['prior_same_platform'] > 0): ?>
      <div class="flash warn">Attention : cette adresse a déjà reçu une récompense <?= e(ucfirst((string)$r['platform'])) ?>.</div>
    <?php endif; ?>

    <p><a target="_blank" rel="noopener" href="/avis/<?= (int)$r['id'] ?>/fichier">Ouvrir la capture</a></p>
    <?php if (str_starts_with((string)$r['screenshot_mime'], 'image/')): ?>
      <img src="/avis/<?= (int)$r['id'] ?>/fichier" alt="Capture avis" style="max-width:360px;max-height:240px;border:1px solid var(--line);border-radius:8px">
    <?php endif; ?>

    <?php if ($r['promo_code']): ?>
      <p><strong><?= money((float)$r['reward_amount']) ?> · Code <?= e($r['promo_code']) ?></strong> · <?= !empty($r['billetweb_created']) ? 'Créé dans BilletWeb' : 'À créer dans BilletWeb' ?></p>
    <?php endif; ?>
    <?php if (!empty($r['mail_status'])): ?>
      <p class="muted">Mail : <strong><?= e($r['mail_status'] === 'sent' ? 'envoyé' : 'ERREUR') ?></strong><?= !empty($r['mail_error']) ? ' · ' . e($r['mail_error']) : '' ?></p>
    <?php endif; ?>

    <?php if (!$ro): ?>
      <?php if ($r['status'] === 'pending'): ?>
        <form method="post" action="/avis/<?= (int)$r['id'] ?>/decision" class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
          <?= csrf_field() ?>
          <button class="btn" type="submit" name="action" value="approve_10">Valider 10 €</button>
          <button class="btn" type="submit" name="action" value="approve_15">Valider 15 €</button>
          <div class="field" style="margin:0"><label>Motif si refus</label><input name="reason"></div>
          <button class="btn sec" type="submit" name="action" value="reject">Refuser</button>
        </form>
      <?php elseif ($r['status'] === 'approved' && !$r['billetweb_created']): ?>
        <form method="post" action="/avis/<?= (int)$r['id'] ?>/decision">
          <?= csrf_field() ?>
          <button class="btn sec" type="submit" name="action" value="mark_bw">Marquer créé dans BilletWeb</button>
        </form>
      <?php elseif ($r['status'] === 'rejected'): ?>
        <p><strong>Motif :</strong> <?= e($r['rejection_reason'] ?? '') ?></p>
        <form method="post" action="/avis/<?= (int)$r['id'] ?>/decision" class="row" style="gap:8px;flex-wrap:wrap">
          <?= csrf_field() ?>
          <button class="btn" type="submit" name="action" value="approve_10">Valider finalement 10 €</button>
          <button class="btn" type="submit" name="action" value="approve_15">Valider finalement 15 €</button>
          <button class="btn sec" type="submit" name="action" value="reopen">Repasser à vérifier</button>
          <button class="btn sec" type="submit" name="action" value="resend">Renvoyer le mail de correction</button>
        </form>
      <?php endif; ?>
      <?php if ($r['status'] === 'approved'): ?>
        <form method="post" action="/avis/<?= (int)$r['id'] ?>/decision" style="margin-top:8px">
          <?= csrf_field() ?>
          <button class="btn sec" type="submit" name="action" value="resend">Renvoyer le mail du code</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
