<?php
use JC\Domain\I18n;
$t = fn(string $k) => I18n::t($k);
/** @var string $error */
?>
<div class="card" style="max-width:420px;margin:32px auto">
  <h1><?= e($t('my_space')) ?></h1>
  <?php if ($error): ?><div class="flash ko"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/mon-espace/connexion">
    <?= csrf_field() ?>
    <div class="field"><label class="f" for="email"><?= e($t('email')) ?></label><input id="email" type="email" name="email" required autocomplete="email"></div>
    <div class="field"><label class="f" for="password"><?= e($t('password')) ?></label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn big" type="submit" style="width:100%"><?= e($t('login')) ?></button>
  </form>
  <p style="margin-top:14px"><a href="/mon-espace/creer"><?= e($t('create_space')) ?></a> · <a href="/participer"><?= e($t('guest_continue')) ?></a></p>
</div>
