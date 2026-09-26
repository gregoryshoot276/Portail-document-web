<?php
/** @var string $key @var string $label @var string $subject @var string $body @var bool $customized @var array $preview */
?>
<p><a href="/mailcenter">← Mail Center</a></p>
<h1><?= e($label) ?></h1>
<?php if ($customized): ?>
  <p><span class="tag ok">Personnalisé</span>
    <form method="post" action="/mailcenter/<?= e($key) ?>/reset" style="display:inline" onsubmit="return confirm('Revenir au texte par défaut de l’application ?');">
      <?= csrf_field() ?><button class="btn sec sm" type="submit">Revenir au texte par défaut</button>
    </form>
  </p>
<?php else: ?>
  <p><span class="tag gr">Texte par défaut de l’application</span></p>
<?php endif; ?>

<div class="grid" style="align-items:start;grid-template-columns:1.1fr 1fr">
  <div class="card">
    <h2>Contenu</h2>
    <form method="post" action="/mailcenter/<?= e($key) ?>">
      <?= csrf_field() ?>
      <div class="field"><label>Objet</label><input name="subject" value="<?= e($subject) ?>" required maxlength="200"></div>
      <div class="field"><label>Corps (HTML)</label>
        <textarea name="body" rows="18" style="width:100%;font-family:monospace;font-size:12.5px;box-sizing:border-box" required><?= e($body) ?></textarea>
      </div>
      <p class="muted">Variables : <code>{prenom}</code> <code>{nom}</code> <code>{participant}</code> <code>{evenement}</code> <code>{circuit}</code>
        <code>{date_evenement}</code> <code>{lien_suivi}</code> <code>{motif}</code> (message de refus/validation) <code>{bloc_briefing}</code> (auto, si configuré ci-dessous).</p>
      <button class="btn" type="submit">Enregistrer</button>
    </form>
  </div>
  <div class="card">
    <h2>Aperçu (données fictives)</h2>
    <p class="muted">Objet : <b><?= e($preview['subject']) ?></b></p>
    <iframe title="Aperçu de l’e-mail" sandbox="" srcdoc="<?= e($preview['body']) ?>" style="width:100%;height:460px;border:1px solid #e5e1d6;border-radius:8px;background:#fff"></iframe>
  </div>
</div>
