<?php
/** @var array $rows @var array $circuits @var array $briefings @var string $transport @var bool $mailEnabled */
$transportLabel = ['php' => 'PHP mail()', 'smtp' => 'SMTP (PHPMailer)', 'brevo' => 'Brevo (API transactionnelle)'][$transport] ?? $transport;
?>
<h1>Mail Center</h1>

<div class="card">
  <h2>Envoi des e-mails</h2>
  <p>Statut : <span class="tag <?= $mailEnabled ? 'ok' : 'gr' ?>"><?= $mailEnabled ? 'Actif' : 'Désactivé' ?></span>
    · transport actuel : <b><?= e($transportLabel) ?></b></p>
  <?php if (!$mailEnabled): ?><p class="muted">Le réglage <code>mail_enabled</code> de l’ancien portail est désactivé : aucun e-mail ne part, quel que soit le transport choisi ici.</p><?php endif; ?>
  <form method="post" action="/mailcenter/transport">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="field"><label>Transport</label>
        <select name="mail_transport">
          <option value="php" <?= $transport === 'php' ? 'selected' : '' ?>>PHP mail()</option>
          <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>>SMTP (réglages dans l’ancien portail)</option>
          <option value="brevo" <?= $transport === 'brevo' ? 'selected' : '' ?>>Brevo (API)</option>
        </select>
      </div>
      <div class="field"><label>Clé API Brevo</label><input type="password" name="brevo_api_key" placeholder="laisser vide pour ne pas changer" autocomplete="off"></div>
      <div class="field"><label>Expéditeur Brevo</label><input type="email" name="brevo_sender_email" placeholder="documents@journeecircuit.fr"></div>
      <div class="field"><label>Nom expéditeur</label><input name="brevo_sender_name" placeholder="Journée Circuit"></div>
    </div>
    <button class="btn" type="submit">Enregistrer</button>
  </form>
  <p class="muted" style="margin-bottom:0">La clé Brevo, une fois enregistrée, n’est jamais réaffichée ici.</p>
</div>

<div class="card">
  <h2>Modèles d’e-mails participants</h2>
  <div class="scroll"><table class="t"><thead><tr><th>Modèle</th><th>Objet actuel</th><th>Personnalisé</th><th>Modifié le</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['label']) ?></td>
      <td class="muted"><?= e($r['subject']) ?></td>
      <td><span class="tag <?= $r['customized'] ? 'ok' : 'gr' ?>"><?= $r['customized'] ? 'Oui' : 'Texte par défaut' ?></span></td>
      <td class="muted"><?= e(fdate($r['updated_at'] ?? null, 'd/m/Y H:i')) ?></td>
      <td><a class="btn sec sm" href="/mailcenter/<?= e($r['key']) ?>">Modifier</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card">
  <h2>Briefing en ligne par circuit</h2>
  <p class="muted">Ajouté automatiquement dans l’e-mail de validation dès qu’un lien est renseigné pour le circuit concerné.</p>
  <?php foreach ($circuits as $c): $b = $briefings[(int)$c['id']] ?? []; ?>
    <form method="post" action="/mailcenter/briefing/<?= (int)$c['id'] ?>" style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e5e1d6">
      <?= csrf_field() ?>
      <div class="grid">
        <div class="field"><label><b><?= e($c['name']) ?></b> — lien du briefing</label><input type="url" name="briefing_url" value="<?= e($b['briefing_url'] ?? '') ?>" placeholder="https://…"></div>
        <div class="field"><label>Texte affiché</label><input name="briefing_text" value="<?= e($b['briefing_text'] ?? '') ?>" placeholder="Briefing en ligne : avez-vous pensé à le réaliser avant votre journée ?"></div>
        <div class="field"><label>Texte du lien</label><input name="briefing_link_text" value="<?= e($b['briefing_link_text'] ?? '') ?>" placeholder="Accéder au briefing"></div>
      </div>
      <button class="btn sec" type="submit">Enregistrer</button>
    </form>
  <?php endforeach; ?>
</div>
