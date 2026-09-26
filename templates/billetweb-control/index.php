<?php
/** @var array $event @var array $groups @var array $local */
?>
<h1>Contrôle Billetweb</h1>
<p class="muted">Compare en direct ce que dit Billetweb à ce que le portail a en base, pour repérer d’où vient un écart sans changer d’écran. Outil de lecture seule : n’écrit jamais en base, même en mode écriture.</p>

<form method="get" action="/billetweb-control" class="row">
  <select name="event" onchange="this.form.submit()">
    <?php foreach (array_merge($groups['visible'], $groups['future'], $groups['archive']) as $e): ?>
      <option value="<?= (int)$e['id'] ?>" <?= (int)$event['id'] === (int)$e['id'] ? 'selected' : '' ?>><?= e(fdate($e['event_date']) . ' · ' . $e['circuit_name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="card">
  <h2>État local (base du portail)</h2>
  <div class="grid">
    <div><strong>Identifiant Billetweb</strong><br><?= e($local['billetweb_event_id'] ?: '— non configuré') ?></div>
    <div><strong>Inscrits stockés (actifs et payés)</strong><br><?= (int)$local['attendees_count'] ?> <span class="muted">/ <?= (int)$local['attendees_total'] ?> au total</span></div>
    <div><strong>Dernière synchro billetterie</strong><br><?= e(fdate($local['last_sync'], 'd/m/Y H:i') ?: 'jamais') ?></div>
    <div><strong>Options post-inscription (cette date)</strong><br><?= (int)$local['post_count'] ?></div>
    <div><strong>Dernière synchro post-inscription</strong><br><?= e(fdate((string)$local['post_last_sync'], 'd/m/Y H:i') ?: 'jamais') ?></div>
  </div>
  <button class="btn" type="button" id="bwc-run" style="margin-top:12px">Vérifier en direct sur Billetweb maintenant</button>
</div>

<div id="bwc-out"></div>
