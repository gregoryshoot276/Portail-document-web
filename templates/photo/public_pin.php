<?php
/** @var ?string $error @var string $k */
?>
<div style="max-width:380px;margin:60px auto 0">
  <div class="card">
    <h1 style="margin-top:0">Photographe</h1>
    <?php if (!empty($error)): ?><div class="flash ko"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/photo-public?k=<?= e($k) ?>">
      <?= csrf_field() ?>
      <label class="f" for="pin">Code à 6 chiffres</label>
      <input id="pin" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required autofocus style="width:100%;font-size:22px;letter-spacing:6px;text-align:center">
      <button class="btn big" type="submit" style="margin-top:14px">Valider</button>
    </form>
  </div>
</div>
