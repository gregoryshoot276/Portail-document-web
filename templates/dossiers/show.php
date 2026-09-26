<?php
use JC\Domain\Dossiers;

/** @var array $p @var array $docs @var ?array $waiver @var array $timeline @var array $mails @var ?array $ticket @var array $entries @var bool $canDecide
 * @var string $ctx @var ?int $ctxPos @var int $ctxTotal @var ?int $prevId @var ?int $nextId */
$pid = (int)$p['id'];
$sc = fn(?string $s) => match ($s) { 'validated' => 'ok', 'rejected' => 'ko', 'to_review' => 'warn', 'pending' => 'info', default => 'gr' };
$ro = read_only();
$typeLabel = ['permis' => 'Permis de conduire', 'assurance' => 'Attestation d’assurance'];
$ctx = $ctx ?? ''; $ctxQs = $ctx !== '' ? '?ctx=' . e($ctx) : '';
?>
<div class="spread">
  <div>
    <h1><?= e($p['prenom']) ?> <?= e($p['nom']) ?> <span class="tag <?= $sc($p['global_status']) ?>"><?= e(Dossiers::STATUS_LABELS[$p['global_status']] ?? $p['global_status']) ?></span><?php if (!empty($p['archived_at'])): ?> <span class="tag gr">Archivé</span><?php endif; ?></h1>
    <div class="muted"><?= e(Dossiers::TYPE_LABELS[$p['participant_type']] ?? $p['participant_type']) ?> · <?= e($p['circuit_name']) ?> · <?= e(fdate($p['event_date'])) ?> · dossier créé le <?= e(fdate($p['created_at'], 'd/m/Y H:i')) ?></div>
  </div>
  <div class="row">
    <?php if ($ctx !== ''): ?>
      <?php if ($prevId): ?><a class="btn sec sm" href="/dossiers/<?= $prevId ?><?= $ctxQs ?>" title="Dossier précédent">◀ Précédent</a><?php else: ?><button class="btn sec sm" disabled>◀ Précédent</button><?php endif; ?>
      <?php if ($ctxPos): ?><span class="muted" style="align-self:center"><?= (int)$ctxPos ?> / <?= (int)$ctxTotal ?></span><?php endif; ?>
      <?php if ($nextId): ?><a class="btn sec sm" href="/dossiers/<?= $nextId ?><?= $ctxQs ?>" title="Dossier suivant">Suivant ▶</a><?php else: ?><button class="btn sec sm" disabled>Suivant ▶</button><?php endif; ?>
    <?php endif; ?>
    <a class="btn sec sm" href="/dossiers?event=<?= (int)$p['event_id'] ?>">← Liste des dossiers</a>
    <?php if ($canDecide && !$ro): ?>
      <form method="post" action="/dossiers/<?= $pid ?>/fix" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="ctx" value="<?= e($ctx) ?>">
        <?php if (empty($p['archived_at'])): ?>
          <input type="hidden" name="action" value="archive">
          <button class="btn sec sm" type="submit" style="color:var(--ko);border-color:var(--ko)" title="Masque ce dossier de la liste (doublon, erreur de saisie...) — réversible via le filtre « Archivés »" onclick="return confirm(<?= e(json_encode('Archiver ce dossier (' . trim($p['prenom'] . ' ' . $p['nom']) . ') ?\n\nIl disparaîtra de la liste par défaut. Utile pour un doublon (même personne, deux dépôts). Réversible.')) ?>);">🗄 Archiver (doublon)</button>
        <?php else: ?>
          <input type="hidden" name="action" value="unarchive">
          <button class="btn sec sm" type="submit">↩ Désarchiver</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-top:12px">
  <div class="card">
    <h2>Identité</h2>
    <table class="t"><tbody>
      <tr><td class="muted">E-mail</td><td><?= e($p['email']) ?></td></tr>
      <tr><td class="muted">Téléphone</td><td><?= e($p['telephone']) ?></td></tr>
      <?php if (!empty($p['birth_date'])): ?><tr><td class="muted">Naissance</td><td><?= e(fdate($p['birth_date'])) ?></td></tr><?php endif; ?>
      <?php if (!empty($p['address'])): ?><tr><td class="muted">Adresse</td><td><?= e(trim($p['address'] . ' ' . $p['postal_code'] . ' ' . $p['city'])) ?></td></tr><?php endif; ?>
      <?php if (!empty($p['permit_number'])): ?><tr><td class="muted">N° de permis</td><td><?= e($p['permit_number']) ?></td></tr><?php endif; ?>
      <?php if (!empty($p['emergency_phone'])): ?><tr><td class="muted">Urgence</td><td><?= e($p['emergency_phone']) ?></td></tr><?php endif; ?>
      <tr><td class="muted">Véhicule</td><td><?= e(trim($p['vehicle'] . ' ' . $p['registration'])) ?></td></tr>
      <tr><td class="muted">Assurance</td><td><?= $p['insurance_mode'] === 'organizer' ? 'Souscrite chez Journée Circuit' : 'Personnelle' ?></td></tr>
      <?php if (!empty($p['main_pilot_name']) || !empty($p['linked_driver_name'])): ?><tr><td class="muted">Pilote lié</td><td><?= e($p['linked_driver_name'] ?: $p['main_pilot_name']) ?></td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($canDecide && !$ro): ?>
    <form method="post" action="/dossiers/<?= $pid ?>/fix" class="row" style="margin-top:10px;padding-top:10px;border-top:1px solid #eee">
      <?= csrf_field() ?><input type="hidden" name="ctx" value="<?= e($ctx) ?>">
      <button class="btn sec sm" type="submit" name="action" value="swap_name" onclick="return confirm('Inverser nom et prénom (<?= e($p['nom']) ?> / <?= e($p['prenom']) ?>) ?');">⇄ Inverser nom/prénom</button>
      <select name="participant_type">
        <option value="pilot" <?= $p['participant_type'] === 'pilot' ? 'selected' : '' ?>>Pilote</option>
        <option value="supplemental_driver" <?= $p['participant_type'] === 'supplemental_driver' ? 'selected' : '' ?>>Pilote suppl.</option>
      </select>
      <button class="btn sec sm" type="submit" name="action" value="set_type">Corriger le type</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Billetweb</h2>
    <?php if ($ticket): ?>
      <table class="t"><tbody>
        <tr><td class="muted">Billet</td><td><?= e($ticket['ticket']) ?></td></tr>
        <tr><td class="muted">Catégorie</td><td><?= e($ticket['category']) ?></td></tr>
        <tr><td class="muted">Commande</td><td><?= e($ticket['order_id']) ?></td></tr>
        <tr><td class="muted">Paiement</td><td><span class="tag <?= (int)$ticket['order_paid'] === 1 ? 'ok' : 'ko' ?>"><?= (int)$ticket['order_paid'] === 1 ? 'Payé' : 'Non payé' ?></span> <?= (int)$ticket['disabled'] === 1 ? '<span class="tag ko">Annulé</span>' : '' ?></td></tr>
        <?php if ($declaredVehicle !== ''): $mismatch = trim((string)($p['vehicle'] ?? '')) !== '' && mb_strtolower($declaredVehicle, 'UTF-8') !== mb_strtolower(trim((string)$p['vehicle']), 'UTF-8'); ?>
        <tr><td class="muted">Véhicule déclaré à l’inscription</td><td><?= e($declaredVehicle) ?><?php if ($mismatch): ?> <span class="tag warn" title="Différent du véhicule renseigné dans le dossier — à comparer avec l’assurance fournie">≠ dossier</span><?php endif; ?></td></tr>
        <?php endif; ?>
      </tbody></table>
      <?php if ($declaredVehicle !== ''): ?><p class="muted" style="margin-top:8px;margin-bottom:0">À comparer avec le véhicule indiqué sur l’attestation d’assurance fournie.</p><?php endif; ?>
    <?php else: ?><p class="muted">Aucun billet rapproché (statut : <?= e($p['ticket_status'] ?: 'non vérifié') ?>).</p><?php endif; ?>
    <?php if ($entries): ?><p style="margin-bottom:0"><?php foreach ($entries as $en): ?><a class="btn sec sm" href="/listing?event=<?= (int)$en['event_id'] ?>#row-<?= (int)$en['id'] ?>">Voir dans le listing</a> <?php endforeach; ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2>Décharge</h2>
    <?php if ($waiver): ?>
      <p><span class="tag ok">Signée</span> le <?= e(fdate($waiver['signed_at'], 'd/m/Y à H:i')) ?><?= $waiver['signed_place'] ? ' à ' . e($waiver['signed_place']) : '' ?></p>
      <div class="muted">Modèle : <?= e(trim(($waiver['template_name'] ?? '') . ' ' . ($waiver['version_label'] ?? ''))) ?: '—' ?> · adresse IP <?= e($waiver['ip_address']) ?></div>
      <?php if (can('files.view')): ?>
      <div class="row" style="margin-top:10px">
        <a class="btn sec sm" target="_blank" rel="noopener" href="/files/waiver/<?= $pid ?>/proof">PDF de preuve</a>
        <?php if (\JC\Domain\Waivers::officialRequired((string)$p['circuit_slug'], (string)$p['participant_type'])): ?><a class="btn sec sm" target="_blank" rel="noopener" href="/files/waiver/<?= $pid ?>/official">Décharge du circuit</a><?php endif; ?>
        <a class="btn sec sm" target="_blank" rel="noopener" href="/files/signature/<?= $pid ?>">Signature</a>
      </div>
      <?php endif; ?>
      <?php if (!empty($waiver['pdf_sha256'])): ?><div class="muted" style="margin-top:8px;word-break:break-all">Empreinte SHA-256 : <?= e($waiver['pdf_sha256']) ?></div><?php endif; ?>
    <?php else: ?><p><span class="tag gr">Non signée</span></p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Documents</h2>
  <?php if (!$docs): ?><p class="muted">Aucun document envoyé.</p><?php endif; ?>
  <?php
  $permisIds = array_values(array_map(fn($d) => (int)$d['id'], array_filter($docs, fn($d) => $d['document_type'] === 'permis' && $d['status'] !== 'validated')));
  ?>
  <?php if ($canDecide && !$ro && count($permisIds) > 1): ?>
    <form method="post" action="/dossiers/<?= $pid ?>/decision" class="row" style="margin-bottom:12px">
      <?= csrf_field() ?><input type="hidden" name="ctx" value="<?= e($ctx) ?>">
      <?php foreach ($permisIds as $did2): ?><input type="hidden" name="document_ids[]" value="<?= $did2 ?>"><?php endforeach; ?>
      <button class="btn sm" style="background:#15803d" name="decision" value="validated">Tout valider le permis (recto + verso, <?= count($permisIds) ?>)</button>
    </form>
  <?php endif; ?>
  <?php foreach ($docs as $d): $did = (int)$d['id']; $isImg = str_starts_with((string)$d['mime_type'], 'image/');
    $fileOk = !empty($d['stored_name']) && \JC\Core\Files::resolve((string)$d['document_type'], (string)$d['stored_name']) !== null; ?>
    <div id="doc-<?= $did ?>" class="card" style="margin-bottom:10px;background:#fafafa">
      <div class="spread">
        <div><b><?= e($typeLabel[$d['document_type']] ?? $d['document_type']) ?></b> <span class="tag <?= $sc($d['status']) ?>"><?= e(Dossiers::STATUS_LABELS[$d['status']] ?? $d['status']) ?></span>
          <div class="muted"><?= e($d['original_name']) ?> · <?= e(fdate($d['created_at'], 'd/m/Y H:i')) ?> · <?= e(round(((int)$d['file_size']) / 1024)) ?> Ko<?= $d['valid_until'] ? ' · valable jusqu’au ' . e(fdate($d['valid_until'])) : '' ?></div>
          <?php if ($d['status'] === 'rejected' && $d['rejection_reason']): ?><div style="color:var(--ko)">Motif : <?= e($d['rejection_reason']) ?></div><?php endif; ?></div>
        <?php if (can('files.view') && $fileOk): ?><a class="btn sm" target="_blank" rel="noopener" href="/files/document/<?= $did ?>">Ouvrir le document</a>
        <?php elseif (!empty($d['stored_name'])): ?><span class="tag ko" title="Le fichier n’est pas dans le dossier de documents configuré">Fichier absent du serveur</span><?php endif; ?>
      </div>
      <?php $isPdf = (string)$d['mime_type'] === 'application/pdf'; $isHeic = in_array((string)$d['mime_type'], ['image/heic', 'image/heif'], true); ?>
      <?php if (can('files.view') && $fileOk && ($isImg || $isPdf)): ?>
        <div class="docprev" data-href="/files/document/<?= $did ?>" data-pdf="<?= $isPdf ? '1' : '0' ?>" style="margin-top:8px;cursor:zoom-in;display:inline-block" title="Cliquer pour agrandir">
          <?php if ($isImg && !$isHeic): ?><img src="/files/document/<?= $did ?>" alt="Aperçu" style="max-width:220px;max-height:220px;border-radius:8px;border:1px solid var(--line);display:block">
          <?php elseif ($isHeic): ?><div class="muted" style="width:120px;height:120px;border:1px dashed var(--line);border-radius:8px;display:flex;align-items:center;justify-content:center;text-align:center;padding:6px">Format HEIC<br>(pas d’aperçu, ouvrir le fichier)</div>
          <?php else: ?><canvas class="pdfthumb" width="160" height="220" style="border-radius:8px;border:1px solid var(--line);background:#f4f4f4;display:block"></canvas>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($canDecide): ?>
      <form method="post" action="/dossiers/<?= $pid ?>/decision" class="row" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= $did ?>"><input type="hidden" name="ctx" value="<?= e($ctx) ?>">
        <input name="reason" placeholder="Motif ou message (obligatoire pour refuser)" class="grow">
        <button class="btn sm" style="background:#15803d" name="decision" value="validated" <?= $ro ? 'disabled title="Lecture seule"' : '' ?>>Valider</button>
        <button class="btn sm" style="background:#0f5c2c" name="decision" value="validated_notify" title="Valide et envoie le message saisi au participant" <?= $ro ? 'disabled' : '' ?>>Valider + notifier</button>
        <button class="btn sm" style="background:#a15c00" name="decision" value="to_review" <?= $ro ? 'disabled' : '' ?>>À vérifier</button>
        <button class="btn sm" name="decision" value="rejected" <?= $ro ? 'disabled' : '' ?>>Refuser</button>
      </form>
      <?php endif; ?>
      <?php if ($d['history']): ?>
        <details style="margin-top:8px"><summary class="muted">Historique (<?= count($d['history']) ?>)</summary>
          <?php foreach ($d['history'] as $h): ?><div class="muted" style="padding:3px 0"><?= e(fdate($h['created_at'], 'd/m/Y H:i')) ?> — <?= e($h['admin_name'] ?: $h['source']) ?> : <b><?= e(Dossiers::STATUS_LABELS[$h['decision']] ?? $h['decision']) ?></b><?= $h['reason'] ? ' — ' . e($h['reason']) : '' ?></div><?php endforeach; ?>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<dialog id="dlg-preview" style="max-width:92vw;max-height:92vh;padding:0;border:0;border-radius:10px">
  <div style="display:flex;flex-direction:column;height:100%">
    <div class="row" style="padding:8px 10px;border-bottom:1px solid var(--line);justify-content:flex-end">
      <button type="button" class="btn sec sm" id="prev-zoom-out" title="Réduire">🔍−</button>
      <button type="button" class="btn sec sm" id="prev-zoom-in" title="Agrandir">🔍+</button>
      <button type="button" class="btn sec sm" id="prev-rotate">⟳ Pivoter 90°</button>
      <button type="button" class="btn sec sm" data-close>Fermer</button>
    </div>
    <div id="prev-body" style="flex:1;overflow:auto;display:flex;align-items:center;justify-content:center;background:#2b2b2b;padding:16px;min-height:200px">
      <img id="prev-img" alt="Document agrandi" style="display:none;max-width:100%;max-height:80vh;transition:transform .15s">
      <canvas id="prev-canvas" style="display:none;max-width:100%;background:#fff"></canvas>
      <iframe id="prev-pdf" title="Document PDF agrandi" style="display:none;width:82vw;height:80vh;border:0;background:#fff;transition:transform .15s"></iframe>
    </div>
  </div>
</dialog>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr))">
  <div class="card"><h2>Journal du dossier</h2>
    <?php if (!$timeline): ?><p class="muted">Aucune action enregistrée.</p><?php endif; ?>
    <?php foreach ($timeline as $l): ?><div style="padding:3px 0;border-bottom:1px solid #eee"><span class="muted"><?= e(fdate($l['created_at'], 'd/m/Y H:i')) ?></span> · <b><?= e($l['action']) ?></b> · <?= e($l['admin_name'] ?: 'système') ?></div><?php endforeach; ?>
  </div>
  <div class="card"><h2>E-mails envoyés</h2>
    <?php if (!$mails): ?><p class="muted">Aucun e-mail.</p><?php endif; ?>
    <?php foreach ($mails as $m): ?><div style="padding:3px 0;border-bottom:1px solid #eee"><span class="muted"><?= e(fdate($m['created_at'], 'd/m/Y H:i')) ?></span> · <?= e($m['mail_type']) ?> · <span class="tag <?= $m['status'] === 'sent' ? 'ok' : 'ko' ?>"><?= e($m['status']) ?></span></div><?php endforeach; ?>
  </div>
</div>
