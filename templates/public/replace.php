<?php
use JC\Domain\I18n;

/** @var array $p @var array $doc @var string $token @var array $errors @var bool $readonly */
$t = fn(string $k) => I18n::t($k);
?>
<div class="card">
  <h1><?= e($t('replace')) ?> — <?= e($doc['document_type'] === 'permis' ? $t('driving_license') : $t('insurance')) ?></h1>
  <?php if ($readonly): ?><div class="flash warn"><?= e($t('readonly_notice')) ?></div><?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="flash ko"><?= e($er) ?></div><?php endforeach; ?>
  <p><?= e($t('replacement_reason')) ?> : <b><?= e($doc['rejection_reason']) ?></b></p>
  <form method="post" action="/suivi/<?= e($token) ?>/remplacer/<?= (int)$doc['id'] ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label class="f" for="document"><?= e($t('choose_file')) ?></label>
    <input id="document" type="file" name="document" required accept=".pdf,image/jpeg,image/png,image/webp,.heic,.heif,image/heic,image/heif">
    <div style="margin-top:12px"><button class="btn" type="submit" <?= $readonly ? 'disabled' : '' ?>><?= e($t('send')) ?></button> <a class="btn sec" href="/suivi/<?= e($token) ?>">←</a></div>
  </form>
</div>
