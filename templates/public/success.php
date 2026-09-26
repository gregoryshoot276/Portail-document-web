<?php
use JC\Domain\I18n;

/** @var string $token @var bool $official */
$t = fn(string $k) => I18n::t($k);
?>
<div class="card">
  <h1><?= e($t('thanks')) ?></h1>
  <p><?= e($t('confirm_email')) ?></p>
  <div class="row">
    <a class="btn" href="/suivi/<?= e($token) ?>"><?= e($t('view_file')) ?></a>
    <a class="btn sec" href="/decharge/<?= e($token) ?>/jc"><?= e($t('download_waiver')) ?></a>
    <?php if ($official): ?><a class="btn sec" href="/decharge/<?= e($token) ?>/official"><?= e($t('download_circuit_waiver')) ?></a><?php endif; ?>
  </div>
</div>
