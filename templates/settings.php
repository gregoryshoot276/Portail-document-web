<?php
/** @var array $mode @var array $admins @var array $roles */
$ro = (bool)$mode['read_only'];
?>
<h1>Réglages</h1>
<div class="card">
  <h2>Mode de fonctionnement</h2>
  <p>Actuellement : <span class="modebadge <?= $ro ? 'ro' : 'rw' ?>"><?= $ro ? 'LECTURE SEULE' : 'ÉCRITURE ACTIVE' ?></span>
    <?php if ($mode['changed_at']): ?><span class="muted"> · modifié le <?= e(fdate($mode['changed_at'], 'd/m/Y H:i')) ?> par <?= e($mode['changed_by']) ?></span><?php endif; ?></p>
  <?php if ($ro): ?>
    <p class="muted">En lecture seule, ce portail affiche vos données réelles sans rien modifier : aucune écriture n’est envoyée à la base (garantie par le code <b>et</b> par MySQL). Vous pouvez comparer avec l’ancien portail sans risque.</p>
    <form method="post" action="/settings/mode" class="row">
      <?= csrf_field() ?><input type="hidden" name="target" value="write">
      <input name="confirm" placeholder="Tapez ECRITURE pour confirmer" autocomplete="off" style="min-width:250px">
      <button class="btn" type="submit">Activer l’écriture</button>
    </form>
    <p class="muted">À faire seulement quand l’ancien portail n’est plus utilisé pour la saisie de cette journée : les deux interfaces partagent la même base.</p>
  <?php else: ?>
    <p class="muted">Les modifications faites ici sont enregistrées dans la base partagée avec l’ancien portail.</p>
    <form method="post" action="/settings/mode"><?= csrf_field() ?><input type="hidden" name="target" value="read"><button class="btn dark" type="submit">Repasser en lecture seule</button></form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Comptes (table partagée avec l’ancien portail)</h2>
  <div class="scroll"><table class="t"><thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Actif</th><th>Dernière connexion</th></tr></thead><tbody>
  <?php foreach ($admins as $a): ?>
    <tr><td><?= e($a['display_name']) ?></td><td><?= e($a['email']) ?></td><td><?= e($roles[$a['role']] ?? $a['role']) ?></td>
      <td><span class="tag <?= (int)$a['is_active'] ? 'ok' : 'gr' ?>"><?= (int)$a['is_active'] ? 'Oui' : 'Non' ?></span></td><td class="muted"><?= e(fdate($a['last_login_at'], 'd/m/Y H:i')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="muted" style="margin-bottom:0">La gestion des comptes reste dans l’ancien portail pour l’instant.</p>
</div>
