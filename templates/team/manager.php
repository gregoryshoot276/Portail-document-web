<?php
/** @var string $token @var array $ctx @var array $vehicles @var array $drivers @var array $insurances @var string $msg @var string $err */
$statusLabel = ['not_sent' => 'Non envoyée', 'sent' => 'Envoyée', 'opened' => 'Ouverte', 'completed' => 'Terminée'];
$complete = $missingWaiver = $missingPermit = 0;
foreach ($drivers as $d) {
    $permit = $d['permit_status'] === 'validated';
    $waiver = (int)$d['waiver_count'] > 0;
    if ($d['participant_id'] && $permit && $waiver) {
        $complete++;
    }
    if (!$waiver) {
        $missingWaiver++;
    }
    if (!$permit) {
        $missingPermit++;
    }
}
?>
<h1>Tableau de bord Team Manager — <?= e($ctx['team_name']) ?></h1>
<p class="muted"><?= e($ctx['circuit_name'] . ' — ' . fdate($ctx['event_date'])) ?></p>

<div class="card">
  <div class="flash ko"><strong>Décharges strictement nominatives et personnelles.</strong><br>
    Chaque pilote doit transmettre lui-même son permis, lire la décharge et la signer personnellement. Le Team Manager ne doit pas remplir ni signer une décharge à la place d’un pilote.</div>
  <div class="grid">
    <div><strong>Pilotes prévus</strong><br><?= count($drivers) ?></div>
    <div><strong>Dossiers complets</strong><br><?= $complete ?></div>
    <div><strong>Décharges manquantes</strong><br><?= $missingWaiver ?></div>
    <div><strong>Permis non validés</strong><br><?= $missingPermit ?></div>
  </div>
  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko"><?= e($err) ?></div><?php endif; ?>
</div>

<div class="card">
  <h2>Pilotes</h2>
  <form method="post" action="/team-manager/<?= e($token) ?>/pilote">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="field"><label class="f">Nom</label><input name="nom"></div>
      <div class="field"><label class="f">Prénom</label><input name="prenom"></div>
      <div class="field"><label class="f">E-mail</label><input type="email" name="email" required></div>
    </div>
    <button class="btn" type="submit">Ajouter le pilote et envoyer son lien</button>
  </form>
  <div class="scroll" style="margin-top:14px"><table class="t"><thead><tr><th>Pilote</th><th>Invitation</th><th>Permis</th><th>Décharge</th><th>Statut</th><th>Lien personnel</th></tr></thead><tbody>
  <?php foreach ($drivers as $d): $link = rtrim((string)\JC\Core\App::config('base_url', ''), '/') . '/team/' . $d['invite_token'];
        $name = $d['participant_id'] ? trim($d['prenom'] . ' ' . $d['nom']) : trim(($d['expected_first_name'] ?? '') . ' ' . ($d['expected_last_name'] ?? ''));
        $permit = $d['permit_status'] === 'validated'; $waiver = (int)$d['waiver_count'] > 0; $done = $d['participant_id'] && $permit && $waiver; ?>
    <tr>
      <td><strong><?= e($name ?: 'À attribuer') ?></strong><br><span class="muted"><?= e($d['expected_email'] ?: ($d['email'] ?? '')) ?></span></td>
      <td><?= e($statusLabel[$d['invite_status']] ?? $d['invite_status']) ?></td>
      <td><span class="tag <?= $permit ? 'ok' : 'warn' ?>"><?= $permit ? 'OK' : 'Manquant' ?></span></td>
      <td><span class="tag <?= $waiver ? 'ok' : 'warn' ?>"><?= $waiver ? 'OK' : 'Manquante' ?></span></td>
      <td><span class="tag <?= $done ? 'ok' : 'gr' ?>"><?= $done ? 'Complet' : 'À compléter' ?></span></td>
      <td><a class="btn sec sm" target="_blank" href="<?= e($link) ?>">Ouvrir</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$drivers): ?><tr><td colspan="6" class="muted">Aucun pilote ajouté pour le moment.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card">
  <h2>Véhicules du Team</h2>
  <p class="muted">Les pilotes du Team peuvent rouler sur n’importe lequel de ces véhicules : aucune affectation pilote/véhicule n’est nécessaire.</p>
  <form method="post" action="/team-manager/<?= e($token) ?>/vehicule">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="field"><label class="f">Véhicule</label><input name="vehicle" placeholder="Porsche 992 GT3"></div>
      <div class="field"><label class="f">Immatriculation</label><input name="registration" placeholder="AB-123-CD"></div>
    </div>
    <button class="btn" type="submit">Ajouter le véhicule</button>
  </form>
  <div class="scroll" style="margin-top:14px"><table class="t"><thead><tr><th>Véhicule</th><th>Immatriculation</th></tr></thead><tbody>
  <?php foreach ($vehicles as $v): ?><tr><td><?= e($v['vehicle'] ?: '—') ?></td><td><?= e($v['registration'] ?: '—') ?></td></tr><?php endforeach; ?>
  <?php if (!$vehicles): ?><tr><td colspan="2" class="muted">Aucun véhicule ajouté.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card">
  <h2>Assurance Team / flotte</h2>
  <p class="muted">Le titulaire peut être l’entreprise, l’association ou un tiers. Cette assurance couvre le lot de véhicules du Team pour cette journée et sera contrôlée manuellement par Journée Circuit.</p>
  <form method="post" action="/team-manager/<?= e($token) ?>/assurance" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="field"><label class="f">Véhicule concerné (facultatif, sinon flotte entière)</label>
        <select name="vehicle_id"><option value="">Toute la flotte</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['vehicle'] ?: $v['registration']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label class="f">Fichier</label><input type="file" name="insurance" required accept=".pdf,image/jpeg,image/png,image/webp,.heic,.heif,image/heic,image/heif"></div>
      <div class="field"><label class="f">Valable jusqu’au</label><input type="date" name="valid_until"></div>
    </div>
    <button class="btn" type="submit">Envoyer</button>
  </form>
  <div class="scroll" style="margin-top:14px"><table class="t"><thead><tr><th>Fichier</th><th>Portée</th><th>Validité</th><th>Statut</th></tr></thead><tbody>
  <?php foreach ($insurances as $i): ?>
    <tr>
      <td><?= e($i['original_name']) ?></td>
      <td><?= e($i['vehicle_label'] ?: 'Flotte') ?></td>
      <td><?= e(fdate($i['valid_until'] ?? null) ?: '—') ?></td>
      <td><span class="tag <?= ['validated' => 'ok', 'rejected' => 'ko'][$i['status']] ?? 'warn' ?>"><?= ['pending' => 'À contrôler', 'validated' => 'Validée', 'rejected' => 'Refusée'][$i['status']] ?? $i['status'] ?></span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$insurances): ?><tr><td colspan="4" class="muted">Aucune assurance envoyée.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
