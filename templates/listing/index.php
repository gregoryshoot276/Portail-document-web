<?php
/** @var array $event @var array $groups @var array $rows @var array $stats @var array $formats @var array $dupRefs @var int $now */
$ro = read_only();
$eid = (int)$event['id'];
$fmtLabel = fn($e) => fdate($e['event_date'], 'd.m') . ' · ' . $e['circuit_name'];
$headCols = [
    'VAL' => 44, 'Participant' => 190, 'Véhicule / pilote' => 170, 'REF' => 70, 'A' => 40, 'D' => 40, 'Format' => 60,
    'Prix dû' => 80, 'Réduc' => 68, 'Avoir' => 68, 'Avance' => 90, 'Sur place' => 90, 'Reste' => 80, 'Notes' => 140,
    'Tél' => 110, 'Mail' => 170, 'Com.' => 68, '1ère fois' => 68, 'Dossier' => 160,
];
?>
<div class="spread">
  <div>
    <h1>Listing · <?= e($event['circuit_name']) ?> · <?= e(fdate($event['event_date'])) ?></h1>
    <div class="muted"><?= (int)$stats['active'] ?> inscrit(s) actif(s)<?= $stats['cancelled'] ? ' · ' . (int)$stats['cancelled'] . ' annulé(s)' : '' ?></div>
  </div>
  <div class="row">
    <?php if (can('starter')): ?><a class="btn sec sm" href="/starter?event=<?= $eid ?>">Starter</a><?php endif; ?>
    <?php if (can('rfid.view')): ?><a class="btn sec sm" href="/rfid?event=<?= $eid ?>">RFID</a><?php endif; ?>
    <a class="btn sec sm" href="/controle?event=<?= $eid ?>">Contrôle</a>
    <a class="btn sec sm" href="/listing/<?= $eid ?>/export.csv">Exporter (Excel)</a>
    <a class="btn sec sm" href="/listing/<?= $eid ?>/mono">Export MONO (1ère fois)</a>
    <?php if (can('labels')): ?><a class="btn sec sm" id="btn-labels" href="/listing/<?= $eid ?>/etiquettes.pdf" target="_blank" rel="noopener">Étiquettes (PDF)</a><?php endif; ?>
  </div>
</div>

<div class="pills">
<?php foreach (array_merge($groups['visible'], $groups['future']) as $e): ?>
  <a class="pill <?= (int)$e['id'] === $eid ? 'on' : '' ?>" href="/listing?event=<?= (int)$e['id'] ?>"><?= e($fmtLabel($e)) ?></a>
<?php endforeach; ?>
<?php if ($groups['archive']): ?>
  <select id="archive-pick" aria-label="Journées passées"><option value="">Journées passées…</option>
    <?php foreach (array_reverse($groups['archive']) as $e): ?><option value="<?= (int)$e['id'] ?>"><?= e($fmtLabel($e)) ?></option><?php endforeach; ?>
  </select>
<?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi"><span>Inscrits</span><b><?= (int)$stats['active'] ?></b></div>
  <div class="kpi"><span>Dossiers complets</span><b><?= (int)$stats['ready'] ?></b></div>
  <div class="kpi <?= $stats['no_ref'] > 0 ? 'kpi-warn' : '' ?>"><span>REF manquantes</span><b><?= (int)$stats['no_ref'] ?></b></div>
  <div class="kpi <?= $stats['no_price'] > 0 ? 'kpi-warn' : '' ?>"><span>Prix inconnus</span><b><?= (int)$stats['no_price'] ?></b></div>
  <div class="kpi <?= $stats['no_ticket'] > 0 ? 'kpi-info' : '' ?>"><span>Sans billet Billetweb</span><b><?= (int)$stats['no_ticket'] ?></b></div>
  <?php foreach (['Journée' => 'Journée', 'Matin' => 'Matin', 'Après-midi' => 'Après-midi', 'Pilote supplémentaire' => 'Pilote suppl.', 'Pilote supplémentaire femme' => 'Pilote suppl. femme', 'Passager' => 'Passager'] as $fmt => $label): ?>
    <?php if (!empty($stats['by_format'][$fmt])): ?><div class="kpi"><span><?= e($label) ?></span><b><?= (int)$stats['by_format'][$fmt] ?></b></div><?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <input type="search" id="q" placeholder="Rechercher (nom, véhicule, REF, mail, tél…)" autocomplete="off">
  <div class="msel" id="f-format-msel">
    <button type="button" class="btn sec sm" id="f-format-btn">Tous les formats</button>
    <div class="msel-panel" id="f-format-panel" hidden>
      <?php foreach ($formats as $f): ?>
        <label class="row" style="gap:6px;padding:2px 0;white-space:nowrap;flex-wrap:nowrap"><input type="checkbox" class="f-format-cb" value="<?= e($f) ?>" checked><?= e($f) ?></label>
      <?php endforeach; ?>
      <div class="row" style="margin-top:6px;gap:6px;border-top:1px solid var(--line);padding-top:6px">
        <button type="button" class="btn sec sm" id="f-format-all">Tout cocher</button>
        <button type="button" class="btn sec sm" id="f-format-none">Tout décocher</button>
      </div>
    </div>
  </div>
  <select id="f-state">
    <option value="">Tous les dossiers</option>
    <option value="ready">Complets</option>
    <option value="incomplete">Incomplets</option>
    <option value="none">Sans dossier</option>
  </select>
  <label class="row" style="gap:4px"><input type="checkbox" id="f-rest"> Reste à payer</label>
  <label class="row" style="gap:4px"><input type="checkbox" id="f-cancel"> Annulés</label>
  <span class="grow"></span>
  <span class="muted" id="count-shown"></span>
  <button class="btn sec sm" id="btn-colw-reset" type="button" title="Remet la largeur par défaut de toutes les colonnes du tableau">↺ Largeurs colonnes</button>
  <div class="msel" id="f-cols-msel">
    <button type="button" class="btn sec sm" id="f-cols-btn" title="Cocher/décocher pour afficher ou masquer des colonnes — raccourci : clic droit sur un en-tête pour le masquer directement">Colonnes</button>
    <div class="msel-panel" id="f-cols-panel" hidden>
      <?php foreach (array_keys($headCols) as $i => $h): ?>
        <label class="row" style="gap:6px;padding:2px 0;white-space:nowrap;flex-wrap:nowrap"><input type="checkbox" class="col-hide-cb" value="<?= $i + 1 ?>" checked><?= e($h) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$ro && can('listing.edit')): ?>
    <button class="btn sec sm" id="btn-sync" type="button" title="Récupère les dernières inscriptions Billetweb">↻ Billetweb</button>
    <button class="btn sec sm" id="btn-remind-all" type="button" title="Envoie une relance à tous les inscrits dont le dossier est incomplet (lignes affichées)">Relancer incomplets (0)</button>
    <button class="btn sec sm" id="btn-merge" type="button" disabled title="Cochez exactement 2 lignes à fusionner">Fusionner (0/2)</button>
    <button class="btn sm" id="btn-add" type="button">+ Ajouter une inscription</button>
  <?php endif; ?>
</div>

<div class="lwrap">
<table class="listing" id="listing">
  <thead><tr>
    <th style="width:30px" data-colw="0"></th>
    <?php
    foreach (array_values($headCols) as $i => $w):
        $h = array_keys($headCols)[$i]; ?>
      <th data-col="<?= $i + 1 ?>" data-colw="<?= $i + 1 ?>" style="width:<?= $w ?>px" title="Cliquer pour trier — glisser le bord droit pour redimensionner"><?= e($h) ?><span class="colresize" data-col="<?= $i + 1 ?>"></span></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { include __DIR__ . '/_row.php'; } ?>
  </tbody>
</table>
</div>
<p class="muted" style="margin-top:8px">A = assurance · D = décharge · VAL = validation interne (G/R/E/C/J). Les cases modifiées à la main l’emportent sur les données Billetweb. Le tri se fait en cliquant sur un titre de colonne.<br>Pastille devant le nom : <span class="src-dot src-both" style="margin-left:2px"></span> Billetweb + dossier portail · <span class="src-dot src-billetweb"></span> Billetweb sans dossier portail (« Pas de dossier ») · <span class="src-dot src-portal"></span> dossier portail sans Billetweb.</p>

<!-- Fenêtre : paiements -->
<dialog id="dlg-pay">
  <h3 id="pay-title">Paiements</h3>
  <table class="t" id="pay-list"><tbody></tbody></table>
  <?php if (!$ro && can('listing.edit')): ?>
  <form id="pay-form" style="margin-top:10px">
    <div class="g2">
      <div><label class="f">Montant (€)</label><input name="amount" inputmode="decimal" required></div>
      <div><label class="f">Mode</label><select name="method"><?php foreach (\JC\Domain\ListingWriter::PAY_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
    </div>
    <label class="f">Note</label><input name="note" style="width:100%">
    <div class="row" style="margin-top:12px;justify-content:flex-end"><button type="button" class="btn sec" data-close>Fermer</button><button class="btn" type="submit">Ajouter le paiement</button></div>
  </form>
  <?php else: ?><div class="row" style="justify-content:flex-end;margin-top:10px"><button type="button" class="btn sec" data-close>Fermer</button></div><?php endif; ?>
</dialog>

<!-- Fenêtre : fusionner deux lignes -->
<?php if (!$ro && can('listing.edit')): ?>
<dialog id="dlg-merge">
  <form id="merge-form">
    <h3>Fusionner deux lignes</h3>
    <p class="muted">Les deux lignes désignent la même personne. Choisissez la valeur à conserver pour chaque champ. La ligne la plus « commerciale » (Billetweb) est gardée, l’autre est masquée et ses paiements sont rattachés.</p>
    <div id="merge-fields"></div>
    <div class="row" style="margin-top:14px;justify-content:flex-end"><button type="button" class="btn sec" data-close>Annuler</button><button class="btn" type="submit">Fusionner</button></div>
  </form>
</dialog>
<?php endif; ?>

<!-- Fenêtre : ajouter une inscription -->
<?php if (!$ro && can('listing.edit')): ?>
<dialog id="dlg-add">
  <form id="add-form">
    <h3>Ajouter une inscription</h3>
    <label class="f">Nom Prénom *</label><input name="display_name" required style="width:100%">
    <div class="g2">
      <div><label class="f">Format</label><select name="duration"><?php foreach ($formats as $f): ?><option><?= e($f) ?></option><?php endforeach; ?></select></div>
      <div><label class="f">Assurance</label><select name="insurance"><option value="">—</option><option>O</option><option>R</option></select></div>
      <div><label class="f">Véhicule</label><input name="vehicle"></div>
      <div><label class="f">REF</label><input name="reference"></div>
      <div><label class="f">Prix dû (€) — vide = tarif</label><input name="amount_due" inputmode="decimal"></div>
      <div><label class="f">Payé sur place (€)</label><input name="onsite_amount" inputmode="decimal"></div>
      <div><label class="f">Mode du paiement</label><select name="onsite_method"><option value="">—</option><?php foreach (\JC\Domain\ListingWriter::PAY_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label class="f">Téléphone</label><input name="phone"></div>
    </div>
    <label class="f">E-mail</label><input name="email" type="email" style="width:100%">
    <label class="f">Notes</label><input name="notes" style="width:100%">
    <div class="row" style="margin-top:14px;justify-content:flex-end"><button type="button" class="btn sec" data-close>Annuler</button><button class="btn" type="submit">Ajouter</button></div>
  </form>
</dialog>
<?php endif; ?>
