<?php
/** @var array $events @var bool $enabled @var int $eventId @var bool $hasPin @var string $link */
?>
<div class="spread">
  <div><h1>Accès Starter sans connexion</h1><div class="muted">Un lien secret (+ code à 6 chiffres si défini) donnant accès à la seule page Starter, verrouillée sur une journée, sans identifiants staff.</div></div>
  <a class="btn sec sm" href="/starter">Retour au Starter</a>
</div>

<div class="card">
  <form method="post" action="/starter/acces">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="g2">
      <div><label class="f">Activation</label><label class="row" style="gap:6px"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Autoriser l’accès Starter public</label></div>
      <div>
        <label class="f">Journée accessible</label>
        <select name="event_id" required>
          <option value="">— Choisir la journée —</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>" <?= $eventId === (int)$ev['id'] ? 'selected' : '' ?>><?= e(fdate($ev['event_date']) . ' · ' . $ev['circuit_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="muted">Le Starter public sera verrouillé sur cette journée uniquement (changer la journée ici ne change pas le lien).</div>
      </div>
      <div>
        <label class="f">Code PIN à 6 chiffres (recommandé)</label>
        <input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="pin" placeholder="<?= $hasPin ? '••••••' : 'Ex. 482731' ?>">
        <div class="muted">Laisser vide pour conserver le code actuel. Sans code, le lien seul suffit.</div>
      </div>
    </div>
    <button class="btn" type="submit" style="margin-top:14px">Enregistrer</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Lien secret</h2>
  <div class="row"><input readonly value="<?= e($link) ?>" id="starter-link" style="flex:1;min-width:260px"><button type="button" class="btn sec sm" onclick="navigator.clipboard.writeText(document.getElementById('starter-link').value)">Copier</button></div>
  <p class="muted">Ne diffusez pas ce lien largement. S’il a pu être vu par la mauvaise personne, régénérez-le.</p>
  <div class="row">
    <form method="post" action="/starter/acces"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><button class="btn sec sm" type="submit" onclick="return confirm('Régénérer le lien ? L’ancien lien cessera de fonctionner immédiatement.')">Régénérer le lien</button></form>
    <form method="post" action="/starter/acces"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><button class="btn sec sm" type="submit" style="color:var(--ko)" onclick="return confirm('Désactiver immédiatement l’accès Starter public ?')">Désactiver immédiatement</button></form>
  </div>
</div>
