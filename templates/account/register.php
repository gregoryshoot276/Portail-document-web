<?php
use JC\Domain\I18n;
$t = fn(string $k) => I18n::t($k);
/** @var array $errors @var array $old */
$o = fn(string $k) => e((string)($old[$k] ?? ''));
?>
<div class="card" style="max-width:480px;margin:32px auto">
  <h1><?= e($t('create_space')) ?></h1>
  <p class="muted"><?= e($t('space_desc')) ?></p>
  <?php foreach ($errors as $er): ?><div class="flash ko"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" action="/mon-espace/creer">
    <?= csrf_field() ?>
    <div class="g2">
      <div class="field"><label class="f" for="nom"><?= e($t('last_name')) ?></label><input id="nom" name="nom" value="<?= $o('nom') ?>" required maxlength="120" autocomplete="family-name"></div>
      <div class="field"><label class="f" for="prenom"><?= e($t('first_name')) ?></label><input id="prenom" name="prenom" value="<?= $o('prenom') ?>" required maxlength="120" autocomplete="given-name"></div>
    </div>
    <div class="field"><label class="f" for="email"><?= e($t('email')) ?></label><input id="email" type="email" name="email" value="<?= $o('email') ?>" required autocomplete="email"></div>
    <div class="field"><label class="f" for="telephone"><?= e($t('phone')) ?></label><input id="telephone" name="telephone" value="<?= $o('telephone') ?>" autocomplete="tel"></div>
    <div class="field"><label class="f" for="password"><?= e($t('password')) ?></label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></div>
    <label class="row"><input type="checkbox" name="consent" value="1" checked> <?= e($t('reuse_consent')) ?></label>
    <button class="btn big" type="submit" style="width:100%;margin-top:12px"><?= e($t('create_space')) ?></button>
  </form>
  <p style="margin-top:14px"><a href="/mon-espace">Déjà un espace ? Se connecter</a> · <a href="/participer"><?= e($t('guest_continue')) ?></a></p>
</div>
