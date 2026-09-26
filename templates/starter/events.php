<h1>Starter</h1>
<p class="muted">Choisissez la journée à contrôler. Le contrôle porte uniquement sur la voiture et son format.</p>
<div class="grid">
<?php foreach (array_merge($groups['visible'], $groups['future']) as $x): ?>
  <a class="evcard" href="/starter?event=<?= (int)$x['id'] ?>">
    <div class="d"><?= e(fdate($x['event_date'])) ?></div>
    <div class="muted"><?= e($x['circuit_name']) ?></div>
  </a>
<?php endforeach; ?>
</div>
