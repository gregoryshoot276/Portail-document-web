<?php
/** @var array $events @var array $maps @var array $codes */
$ro = read_only();
?>
<div class="spread"><div><h1>Tarifs du listing</h1><div class="muted">Une valeur explicite par journée et par code. Le listing ne devine aucun tarif : une case vide signifie « aucun tarif configuré ».</div></div>
  <a class="btn sec sm" href="/listing">Retour listing</a></div>
<div class="flash warn" style="background:#fff8d8;color:#6b4200">PS et PSP sont distincts, comme PSF/PSFP et PA/PAP. O = assurance prise à l’inscription, R = assurance post-inscription.</div>
<form method="post" action="/tarifs">
  <?= csrf_field() ?>
  <div class="scroll"><table class="t tariff"><thead><tr><th>Code / tarif</th>
    <?php foreach ($events as $e): ?><th><?= e(fdate($e['event_date'], 'd.m') . ' · ' . $e['circuit_name']) ?></th><?php endforeach; ?>
  </tr></thead><tbody>
  <?php foreach ($codes as $code => $label): ?>
    <tr><td><b><?= e($code) ?></b> · <?= e($label) ?></td>
    <?php foreach ($events as $e): $id = (int)$e['id']; $v = $maps[$id][$code] ?? null; ?>
      <td><input inputmode="decimal" name="tariff[<?= $id ?>][<?= e($code) ?>]" value="<?= $v === null ? '' : e(plain_num($v)) ?>" <?= $ro ? 'readonly' : '' ?>> €</td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!$ro && can('tarifs.edit')): ?><div style="margin-top:12px"><button class="btn" type="submit">Enregistrer tous les tarifs</button></div><?php endif; ?>
</form>
