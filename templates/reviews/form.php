<?php
/** @var string $token @var array|null $editing @var array $events @var string $ok @var string $err */
$statusTag = ['pending' => ['tag info', 'En cours de vérification'], 'approved' => ['tag ok', 'Validée'], 'rejected' => ['tag ko', 'À corriger']];
?>
<div class="card" style="max-width:720px;margin:0 auto;border-top-width:4px">
  <h1><?= $editing ? 'Mettre à jour votre avis' : 'Merci pour votre avis' ?></h1>

  <?php if ($editing): ?>
    <p><strong><?= e($editing['prenom'] . ' ' . $editing['nom']) ?></strong> · <?= e(ucfirst((string)$editing['platform'])) ?><?= !empty($editing['circuit_name']) ? ' · ' . e($editing['circuit_name']) : '' ?></p>
    <p>
      <?php [$cls, $label] = $statusTag[$editing['status']] ?? ['tag gr', $editing['status']]; ?>
      <span class="<?= e($cls) ?>"><?= e($label) ?></span>
    </p>
    <?php if ($editing['status'] === 'rejected'): ?>
      <p><strong>Motif :</strong> <?= e((string)$editing['rejection_reason']) ?></p>
    <?php elseif ($editing['status'] === 'approved'): ?>
      <p>Votre code promo a été attribué.</p>
    <?php endif; ?>
  <?php else: ?>
    <p>Vous pouvez envoyer votre avis Google, votre avis Facebook, ou les deux en une seule fois.</p>
    <div class="flash info" style="background:#fff7e6;color:#7a4d00"><strong>10&nbsp;€</strong> pour un avis publié · <strong>15&nbsp;€</strong> si votre avis contient une ou plusieurs photos.<br>Une récompense peut être attribuée une fois sur Google et une fois sur Facebook.</div>
  <?php endif; ?>

  <?php if ($ok): ?><div class="flash ok"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko"><?= e($err) ?></div><?php endif; ?>

  <?php if ($editing && $editing['status'] !== 'approved' && !$ok): ?>
    <form method="post" action="/avis.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field"><label>Nouvelle capture de l’avis</label><input type="file" name="screenshot" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
      <button class="btn" type="submit">Renvoyer ma demande</button>
    </form>
  <?php elseif (!$editing && !$ok): ?>
    <form method="post" action="/avis.php" enctype="multipart/form-data" id="reviewForm">
      <?= csrf_field() ?>
      <div class="g2">
        <div class="field"><label>Journée</label><select name="event_id" required><option value="">— Choisir —</option><?php foreach ($events as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e(fdate($ev['event_date']) . ' · ' . $ev['circuit_name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Nom</label><input name="nom" required></div>
        <div class="field"><label>Prénom</label><input name="prenom" required></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" required></div>
      </div>
      <div class="field">
        <label>Plateforme(s)</label>
        <div class="g2">
          <div class="card" style="padding:12px 14px;margin:0">
            <label><input class="review-platform-cb" type="checkbox" name="platforms[]" value="google"> <strong>Google</strong></label>
            <div class="field" data-file-for="google" hidden style="margin-top:8px"><label>Capture Google</label><input type="file" name="screenshot_google" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
          </div>
          <div class="card" style="padding:12px 14px;margin:0">
            <label><input class="review-platform-cb" type="checkbox" name="platforms[]" value="facebook"> <strong>Facebook</strong></label>
            <div class="field" data-file-for="facebook" hidden style="margin-top:8px"><label>Capture Facebook</label><input type="file" name="screenshot_facebook" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
          </div>
        </div>
      </div>
      <button class="btn" type="submit">Envoyer ma demande</button>
    </form>
  <?php endif; ?>
</div>
