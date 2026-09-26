<?php
/** @var array $events @var string $ok @var string $err */
?>
<div class="card" style="max-width:720px;margin:0 auto;border-top-width:4px">
  <h1>Annulation / remplacement</h1>
  <p>Identifiez votre inscription comme pour votre dossier participant. Aucune modification n’est faite automatiquement dans BilletWeb : notre équipe traite chaque demande.</p>

  <?php if ($ok): ?><div class="flash ok"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$ok): ?>
    <form method="post" action="/annulation" id="changeForm">
      <?= csrf_field() ?>
      <div class="g2">
        <div class="field"><label>Journée concernée</label><select name="event_id" required><option value="">— Choisir —</option><?php foreach ($events as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e(fdate($ev['event_date']) . ' · ' . $ev['circuit_name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Nom</label><input name="nom" required></div>
        <div class="field"><label>Prénom</label><input name="prenom" required></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" required></div>
      </div>

      <h3 style="margin-top:18px">Avez-vous un remplaçant ?</h3>
      <label class="row" style="display:inline-flex;gap:4px"><input type="radio" name="has_replacement" value="0" checked> Non, simple annulation</label>
      &nbsp;&nbsp;
      <label class="row" style="display:inline-flex;gap:4px"><input type="radio" name="has_replacement" value="1"> Oui, quelqu’un me remplace</label>

      <div id="changeReplacement" hidden style="margin-top:12px">
        <div class="g2">
          <div class="field"><label>Nom du remplaçant</label><input name="replacement_nom"></div>
          <div class="field"><label>Prénom du remplaçant</label><input name="replacement_prenom"></div>
          <div class="field"><label>E-mail du remplaçant</label><input type="email" name="replacement_email"></div>
          <div class="field"><label>Téléphone</label><input name="replacement_phone"></div>
          <div class="field"><label>Véhicule</label><input name="replacement_vehicle"></div>
        </div>
      </div>

      <p style="margin-top:14px"><label><input type="checkbox" name="certification" value="1" required> Je certifie l’exactitude de cette demande et, en cas de remplacement, avoir informé le remplaçant des conditions de la journée.</label></p>

      <button class="btn" type="submit">Envoyer ma demande</button>
    </form>
  <?php endif; ?>
</div>
