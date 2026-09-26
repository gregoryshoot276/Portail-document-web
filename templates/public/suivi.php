<?php
use JC\Domain\Dossiers;
use JC\Domain\I18n;

/** @var array $p @var array $people @var array $docs @var array $waivers @var string $token @var bool $official */
$t = fn(string $k) => I18n::t($k);
$role = fn(array $r) => $r['participant_type'] === 'passenger' ? 'Passager' : ($r['participant_type'] === 'supplemental_driver' ? 'Pilote supplémentaire' : 'Pilote principal');
$sc = fn(string $s) => match ($s) { 'validated' => 'ok', 'rejected' => 'ko', 'missing' => 'gr', default => 'warn' };
$docStatus = function (array $list, string $type): string {
    $st = array_map(fn($d) => (string)$d['status'], array_values(array_filter($list, fn($d) => $d['document_type'] === $type)));
    if (!$st) return 'missing';
    if (in_array('rejected', $st, true)) return 'rejected';
    if (in_array('to_review', $st, true)) return 'to_review';
    if (in_array('pending', $st, true)) return 'pending';
    return count(array_unique($st)) === 1 && $st[0] === 'validated' ? 'validated' : 'pending';
};
$label = fn(string $s) => Dossiers::STATUS_LABELS[$s] ?? ($s === 'missing' ? 'Manquant' : $s);
$myDocs = $docs[(int)$p['id']] ?? [];
$supp = $p['participant_type'] === 'supplemental_driver';
?>
<div class="card">
  <h1><?= e($t('file_tracking')) ?></h1>
  <p><b><?= e($p['prenom'] . ' ' . $p['nom']) ?></b><br><?= e($role($p)) ?> · <?= e($p['circuit_name'] ?: $p['event_name']) ?><?= !empty($p['event_date']) ? ' · ' . e(fdate($p['event_date'])) : '' ?></p>

  <?php if (count($people) > 1): ?>
  <h2>Participants associés à cette adresse</h2>
  <div class="grid">
    <?php foreach ($people as $cp): $cid = (int)$cp['id']; $cd = $docs[$cid] ?? []; $isP = $cp['participant_type'] === 'passenger'; $isS = $cp['participant_type'] === 'supplemental_driver'; ?>
    <a class="evcard <?= $cid === (int)$p['id'] ? 'on' : '' ?>" href="/suivi/<?= e($cp['public_token']) ?>" style="<?= $cid === (int)$p['id'] ? 'border-color:var(--red)' : '' ?>">
      <b><?= e($cp['prenom'] . ' ' . $cp['nom']) ?></b><div class="muted"><?= e($role($cp)) ?></div>
      <div style="margin-top:6px"><span class="tag <?= isset($waivers[$cid]) ? 'ok' : 'gr' ?>">Décharge <?= isset($waivers[$cid]) ? 'signée' : 'manquante' ?></span>
        <?php if (!$isP): $ps = $docStatus($cd, 'permis'); ?> <span class="tag <?= $sc($ps) ?>">Permis : <?= e($label($ps)) ?></span><?php endif; ?>
        <?php if (!$isP && !$isS): $as = $docStatus($cd, 'assurance'); ?> <span class="tag <?= $sc($as) ?>">Assurance : <?= e($cp['insurance_mode'] === 'organizer' ? 'Organisateur' : $label($as)) ?></span><?php endif; ?>
        <span class="tag <?= $sc((string)$cp['global_status']) ?>">Dossier : <?= e($label((string)$cp['global_status'])) ?></span></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($p['participant_type'] === 'passenger'): ?>
    <div class="flash ok"><?= e($t('passenger_done')) ?></div>
    <?php if ($p['ticket_message']): ?><p class="muted"><?= e($p['ticket_message']) ?></p><?php endif; ?>
  <?php else: ?>
    <table class="t"><thead><tr><th><?= e($t('element')) ?></th><th><?= e($t('status')) ?></th><th><?= e($t('information')) ?></th></tr></thead><tbody>
    <?php foreach ($myDocs as $d): if ($supp && $d['document_type'] === 'assurance') continue; ?>
      <tr><td><?= e($d['document_type'] === 'permis' ? $t('driving_license') . ($d['source'] === 'upload_verso' ? ' — verso' : ($d['source'] === 'upload_recto' ? ' — recto' : '')) : $t('insurance')) ?></td>
        <td><span class="tag <?= $sc((string)$d['status']) ?>"><?= e($label((string)$d['status'])) ?></span></td>
        <td><?= e($d['rejection_reason'] ?: '') ?>
          <?php if ($d['status'] === 'rejected'): ?><br><a class="btn sm" href="/suivi/<?= e($token) ?>/remplacer/<?= (int)$d['id'] ?>"><?= e($t('replace_doc')) ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <tr><td><?= e($t('waiver_signed')) ?></td><td><span class="tag ok"><?= e($t('signed')) ?></span></td><td><?= e($t('registered')) ?></td></tr>
    </tbody></table>
  <?php endif; ?>

  <h2 style="margin-top:18px"><?= e($t('my_waivers')) ?></h2>
  <div class="row">
    <a class="btn sec" href="/decharge/<?= e($token) ?>/jc"><?= e($t('download_waiver')) ?></a>
    <?php if ($official): ?><a class="btn sec" href="/decharge/<?= e($token) ?>/official"><?= e($t('download_circuit_waiver')) ?></a><?php endif; ?>
  </div>
</div>
