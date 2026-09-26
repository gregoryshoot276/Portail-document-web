<?php
/** @var array $event @var array $groups @var array $rows @var array $dups @var array $log @var array $bracelets */
$eid = (int)$event['id'];
$log = $log ?? [];
$bracelets = $bracelets ?? [];
$dupSet = array_flip($dups);
$pill = function (array $r) use ($dupSet): string {
    $isDup = $r['ref'] !== '' && isset($dupSet[mb_strtoupper($r['ref'], 'UTF-8')]);
    $cls = $r['status'] === 'nok' ? 'nok' : ($r['status'] === 'recheck' ? 'recheck' : ($r['ref'] === '' ? 'missing' : ''));
    return '<button type="button" class="refpill ' . $cls . ($isDup ? ' dup' : '') . '" data-id="' . (int)$r['id'] . '" data-ref="' . e($r['ref']) . '" data-vehicle="' . e($r['vehicle']) . '" data-format="' . e($r['format']) . '" data-checked="' . e($r['checked']) . '" data-search="' . e(mb_strtolower($r['ref'] . ' ' . $r['vehicle'] . ' ' . $r['format'], 'UTF-8')) . '">' . e($r['ref'] !== '' ? $r['ref'] : 'SANS REF') . '</button>';
};
$pending = array_filter($rows, fn($r) => $r['status'] === 'pending');
$attention = array_filter($rows, fn($r) => in_array($r['status'], ['nok', 'recheck'], true));
$done = count(array_filter($rows, fn($r) => $r['status'] === 'ok'));
?>
<div class="spread">
  <div>
    <h1>Starter · <?= e($event['circuit_name']) ?> · <?= e(fdate($event['event_date'])) ?></h1>
    <div class="muted">Touchez un numéro pour contrôler la voiture et son format. OK retire la pastille. PAS OK la garde en rouge jusqu’au changement du véhicule ; après modification elle passe orange, en attente de revalidation.</div>
  </div>
  <div class="row">
    <input type="search" id="sq" placeholder="Filtrer par REF / véhicule…">
    <a class="btn sec sm" href="/starter">Journées</a>
    <?php if (can('listing.view')): ?><a class="btn sec sm" href="/listing?event=<?= $eid ?>">Listing</a><?php endif; ?>
    <a class="btn sec sm" href="/starter/acces">Accès isolé</a>
  </div>
</div>

<?php include __DIR__ . '/_body.php'; ?>
