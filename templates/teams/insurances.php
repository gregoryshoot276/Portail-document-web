<?php
/** @var array $rows */
$labels = ['pending' => 'À contrôler', 'to_review' => 'À contrôler', 'validated' => 'Validée', 'rejected' => 'Refusée'];
$tagClass = ['pending' => 'warn', 'to_review' => 'warn', 'validated' => 'ok', 'rejected' => 'ko'];
?>
<p><a href="/teams">← Teams</a></p>
<h1>Assurances Team</h1>
<div class="card">
  <div class="scroll"><table class="t"><thead><tr><th>Team</th><th>Événement</th><th>Portée</th><th>Fichier</th><th>Validité</th><th>Statut</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><strong><?= e($r['team_name']) ?></strong></td>
      <td><?= e($r['circuit'] . ' — ' . fdate($r['event_date'])) ?></td>
      <td><?= e($r['vehicle_label'] ?: 'Flotte') ?></td>
      <td><a href="/teams/insurances/<?= (int)$r['id'] ?>/fichier" target="_blank"><?= e($r['original_name'] ?: 'document') ?></a></td>
      <td><?= e(fdate($r['valid_until'] ?? null) ?: '—') ?></td>
      <td><span class="tag <?= e($tagClass[$r['status']] ?? 'gr') ?>"><?= e($labels[$r['status']] ?? $r['status']) ?></span></td>
      <td>
        <?php if (in_array($r['status'], ['pending', 'to_review'], true)): ?>
        <form method="post" action="/teams/insurances/<?= (int)$r['id'] ?>/decision" style="display:flex;gap:6px">
          <?= csrf_field() ?>
          <button class="btn sec sm" type="submit" name="decision" value="validated">Valider</button>
          <button class="btn sec sm" type="submit" name="decision" value="rejected">Refuser</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">Aucune assurance Team pour le moment.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
