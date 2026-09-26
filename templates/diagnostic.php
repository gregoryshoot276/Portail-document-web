<?php
/** @var array $checks */
$lab = ['ok' => ['OK', 'ok'], 'ko' => ['À corriger', 'ko'], 'warn' => ['Attention', 'warn'], 'info' => ['Info', 'info']];
$counts = array_count_values(array_column($checks, 'status'));
?>
<div class="spread"><div><h1>Diagnostic</h1><div class="muted">Vérifie que le serveur, la base de données et les dossiers de documents sont prêts.</div></div>
  <div class="row"><span class="tag ok"><?= (int)($counts['ok'] ?? 0) ?> OK</span><span class="tag warn"><?= (int)($counts['warn'] ?? 0) ?> attention</span><span class="tag ko"><?= (int)($counts['ko'] ?? 0) ?> à corriger</span></div></div>
<div class="scroll" style="margin-top:12px"><table class="t dg"><tbody>
<?php $grp = ''; foreach ($checks as $c): if ($c['group'] !== $grp): $grp = $c['group']; ?>
  <tr class="grp"><td colspan="3"><?= e($grp) ?></td></tr>
<?php endif; [$l, $cls] = $lab[$c['status']] ?? ['?', 'gr']; ?>
  <tr><td class="s"><span class="tag <?= e($cls) ?>"><?= e($l) ?></span></td><td><?= e($c['label']) ?></td><td class="muted"><?= e($c['detail']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
