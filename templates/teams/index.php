<?php
/** @var array $events @var array $rows */
?>
<h1>Teams</h1>
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px"><h2 style="margin:0">Nouvelle participation Team</h2><a class="btn sec" href="/teams/insurances">Contrôler les assurances Team</a></div>
  <p class="muted">Chaque tableau de bord Team est lié à un événement précis. Un même Team peut revenir sur plusieurs journées sans mélanger ses pilotes.</p>
  <form method="post" action="/teams">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="field"><label>Team</label><input name="name" required></div>
      <div class="field"><label>Team Manager</label><input name="manager_name" required></div>
      <div class="field"><label>E-mail manager</label><input type="email" name="manager_email" required></div>
      <div class="field"><label>Téléphone</label><input name="manager_phone"></div>
      <div class="field"><label>Événement</label>
        <select name="event_id" required>
          <option value="">Choisir…</option>
          <?php foreach ($events as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e($ev['circuit'] . ' — ' . fdate($ev['event_date'])) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn" type="submit">Créer le tableau de bord Team</button>
  </form>
</div>

<div class="card">
  <h2>Participations</h2>
  <div class="scroll"><table class="t"><thead><tr><th>Team</th><th>Événement</th><th>Manager</th><th>Pilotes</th><th>Assurances</th><th>Lien manager</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $url = rtrim((string)\JC\Core\App::config('base_url', ''), '/') . '/team-manager/' . $r['manager_token']; ?>
    <tr>
      <td><strong><?= e($r['team_name']) ?></strong></td>
      <td><?= e($r['circuit'] . ' — ' . fdate($r['event_date'])) ?></td>
      <td><?= e($r['manager_name']) ?><br><span class="muted"><?= e($r['manager_email']) ?></span></td>
      <td><?= (int)$r['completed'] ?> / <?= (int)$r['drivers'] ?></td>
      <td><?php if ((int)$r['pending_insurance'] > 0): ?><span class="tag warn"><?= (int)$r['pending_insurance'] ?> à contrôler</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><a class="btn sec sm" target="_blank" href="<?= e($url) ?>">Ouvrir</a><br><code style="font-size:11px"><?= e($url) ?></code></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
