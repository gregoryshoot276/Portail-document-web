<?php
/** @var bool $enabled @var bool $hasPin @var string $link */
?>
<div class="spread">
  <div><h1>Accès Photographe sans connexion</h1><div class="muted">Un lien secret (+ code à 6 chiffres si défini) donnant accès à la page Photographe, sans identifiants staff. Le photographe peut changer de journée comme sur la page normale.</div></div>
  <a class="btn sec sm" href="/photo">Retour à Photo</a>
</div>

<div class="card">
  <form method="post" action="/photo/acces">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="g2">
      <div><label class="f">Activation</label><label class="row" style="gap:6px"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Autoriser l’accès Photographe public</label></div>
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
  <div class="row"><input readonly value="<?= e($link) ?>" class="select-all" style="flex:1;min-width:260px"></div>
  <p class="muted">Ne diffusez pas ce lien largement. S’il a pu être vu par la mauvaise personne, régénérez-le.</p>
  <div class="row">
    <form method="post" action="/photo/acces"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><button class="btn sec sm" type="submit" data-confirm="Régénérer le lien ? L’ancien lien cessera de fonctionner immédiatement.">Régénérer le lien</button></form>
    <form method="post" action="/photo/acces"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><button class="btn sec sm" type="submit" style="color:var(--ko)" data-confirm="Désactiver immédiatement l’accès Photographe public ?">Désactiver immédiatement</button></form>
  </div>
</div>
