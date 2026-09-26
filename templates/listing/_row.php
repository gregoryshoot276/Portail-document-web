<?php
/** @var array $r ligne calculée par ListingBuilder ; @var bool $ro ; @var array $dupRefs */
use JC\Domain\Formats;

$id = (int)$r['id'];
$dis = $ro ? ' readonly' : '';
$dupRefs = $dupRefs ?? [];
$dupNames = $dupNames ?? [];
$refKey = mb_strtoupper(trim($r['reference']), 'UTF-8');
$refClass = '';
if ($refKey === '' && $r['type'] === 'pilot' && Formats::isRolling($r['duration']) && !$r['cancelled']) {
    $refClass = 'ref-missing';
} elseif ($refKey !== '' && in_array($refKey, $dupRefs, true)) {
    $refClass = 'ref-dup';
}
$nameClass = (!$r['cancelled'] && \JC\Domain\Text::normalize((string)$r['name']) !== '' && in_array(\JC\Domain\Text::normalize((string)$r['name']), $dupNames, true)) ? 'ref-dup' : '';
$hasTicket = !empty($r['entry']['billetweb_attendee_id']);
$hasDossier = (int)$r['participant_id'] > 0;
$srcState = $hasTicket && $hasDossier ? 'both' : ($hasTicket ? 'billetweb' : 'portal');
$srcTitle = ['both' => 'Billetweb + dossier portail rattachés.', 'billetweb' => 'Billet Billetweb trouvé, mais aucun dossier déposé sur le portail (statut « Pas de dossier »).', 'portal' => 'Dossier déposé sur le portail uniquement : aucune correspondance Billetweb trouvée.'][$srcState];
$rest = $r['remaining'];
$restClass = !$r['price_known'] ? '' : ($rest > 0.005 ? 'rest-pos' : ($rest < -0.005 ? 'rest-neg' : 'rest-zero'));
$search = mb_strtolower($r['name'] . ' ' . $r['vehicle'] . ' ' . $r['reference'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['notes'], 'UTF-8');
$manualOv = fn(string $f) => array_key_exists($f, $r['ov']);
$comm = ['not_delivered' => ['—', 'gr'], 'delivered' => ['Reçu', 'info'], 'opened' => ['Ouvert', 'ok'], 'clicked' => ['Cliqué', 'ok']][$r['comm']] ?? ['—', 'gr'];
$pid = (int)$r['participant_id'];
?>
<tr id="row-<?= $id ?>" data-id="<?= $id ?>" data-pid="<?= $pid ?>" data-search="<?= e($search) ?>" data-format="<?= e($r['duration']) ?>" data-state="<?= e($r['progress']['state']) ?>" data-cancelled="<?= $r['cancelled'] ? '1' : '0' ?>" data-remaining="<?= e(plain_num($r['remaining'])) ?>" data-name="<?= e($r['name']) ?>" data-vehicle="<?= e($r['vehicle']) ?>" data-email="<?= e($r['email']) ?>" data-phone="<?= e($r['phone']) ?>" data-remind="<?= (!$r['cancelled'] && !$r['no_remind'] && in_array($r['progress']['state'], ['none', 'missing', 'partial', 'correction'], true) && filter_var(trim($r['email']), FILTER_VALIDATE_EMAIL)) ? '1' : '0' ?>" class="<?= $r['cancelled'] ? 'cancelled' : ($r['ready'] ? 'ready' : '') ?>">
  <td><input type="checkbox" class="pick" aria-label="Sélectionner"></td>
  <td data-v="<?= e($r['validator']) ?>"><button type="button" class="cb <?= $r['validator'] !== '' ? 'v-' . e($r['validator']) : '' ?>" data-choice="validator" data-options="|G|R|E|C|J" data-value="<?= e($r['validator']) ?>" <?= $ro ? 'disabled' : '' ?>><?= e($r['validator'] !== '' ? $r['validator'] : '—') ?></button></td>
  <td class="c-name" data-v="<?= e($r['name']) ?>" title="<?= $nameClass ? 'Nom identique à une autre ligne active : doublon probable, à fusionner ?' : '' ?>"><?php if ($r['cancelled']): ?><span class="cancel-badge">ANNULÉ</span><?php endif; ?><?php if (!$r['cancelled']): ?><span class="src-dot src-<?= $srcState ?>" title="<?= e($srcTitle) ?>"></span><?php endif; ?><input class="ed <?= $nameClass ?>" data-field="display_name" value="<?= e($r['name']) ?>"<?= $dis ?>></td>
  <td class="c-veh" data-v="<?= e($r['vehicle']) ?>"><input class="ed <?= $r['starter'] === 'nok' ? 'st-nok' : '' ?>" data-field="vehicle" value="<?= e($r['vehicle']) ?>" placeholder="<?= in_array($r['type'], ['passenger', 'supplemental_driver'], true) ? 'Pilote principal ?' : '' ?>"<?= $dis ?> title="<?= $r['starter'] === 'nok' ? 'STARTER : véhicule NON OK — à remplacer puis recontrôler' : ($r['starter'] === 'recheck' ? 'STARTER : véhicule modifié — à revalider' : '') ?>"><?= $r['starter'] === 'nok' ? '<span title="Starter : PAS OK">⚠</span>' : ($r['starter'] === 'recheck' ? '<span title="Starter : à revalider">↻</span>' : '') ?><?php if ($r['vehicle_status'] === 'V'): ?><span class="tag ok" title="Confirmé : identique au véhicule déclaré à l’inscription">V</span><?php elseif ($r['vehicle_status'] === 'X'): ?><span class="tag warn" title="Modifié par le participant par rapport à l’inscription Billetweb">X</span><?php endif; ?></td>
  <td class="c-ref" data-v="<?= e($r['reference']) ?>"><input class="ed <?= $refClass ?>" data-field="reference" value="<?= e($r['reference']) ?>"<?= $dis ?> title="<?= $refClass === 'ref-missing' ? 'REF manquante pour un format de roulage' : ($refClass === 'ref-dup' ? 'REF utilisée plusieurs fois' : '') ?>"></td>
  <td data-v="<?= e($r['insurance']) ?>"><button type="button" class="cb <?= e($r['insurance']) ?>" data-choice="insurance" data-options="|O|R|V|X" data-value="<?= e($r['insurance']) ?>" title="O = prise à l’inscription · R = post-inscription · V = attestation validée · X = refusée" <?= $ro || $r['is_option'] ? 'disabled' : '' ?>><?= e($r['insurance'] !== '' ? $r['insurance'] : '—') ?></button></td>
  <td data-v="<?= e($r['decharge']) ?>"><button type="button" class="cb <?= e($r['decharge']) ?>" data-waiver="1" data-value="<?= e($r['decharge']) ?>" title="V = décharge signée · J = forcée (papier, ex. JotForm) · X = refusée" <?= $ro ? 'disabled' : '' ?>><?= e($r['decharge'] !== '' ? $r['decharge'] : '—') ?></button></td>
  <td data-v="<?= e($r['duration']) ?>"><button type="button" class="cb" data-choice="duration" data-options="@formats" data-value="<?= e($r['duration']) ?>" title="<?= e($r['duration']) ?>" <?= $ro ? 'disabled' : '' ?>><?= e($r['code']) ?></button></td>
  <td class="num" data-v="<?= e(plain_num($r['amount_due'])) ?>"><input class="ed num <?= $r['manual_amount'] ? 'manual' : ($r['price_known'] ? '' : 'price-unknown') ?>" inputmode="decimal" data-field="amount_due" value="<?= e($r['price_known'] ? plain_num($r['amount_due']) : '') ?>" placeholder="?" title="<?= $r['price_known'] ? ($r['manual_amount'] ? 'Prix saisi à la main' : 'Prix calculé d’après les tarifs') : 'Aucun tarif configuré pour le format « ' . e($r['duration']) . ' » (code ' . e($r['code']) . ') — à ajouter dans l’onglet Tarifs.' ?>"<?= $dis ?>></td>
  <td class="num" data-v="<?= e(plain_num($r['discount'])) ?>"><input class="ed num" inputmode="decimal" data-field="discount" value="<?= e(plain_num($r['discount'], true)) ?>"<?= $dis ?>></td>
  <td class="num" data-v="<?= e(plain_num($r['credit'])) ?>"><input class="ed num" inputmode="decimal" data-field="credit" value="<?= e(plain_num($r['credit'], true)) ?>"<?= $dis ?>></td>
  <td class="num" data-v="<?= e(plain_num($r['advance'])) ?>"><button type="button" class="cb" data-pay="advance" title="Avance (payée sur Billetweb ou saisie)"><?= $r['advance'] > 0.004 ? e(money($r['advance'])) : '—' ?></button></td>
  <td class="num" data-v="<?= e(plain_num($r['onsite'])) ?>"><button type="button" class="cb" data-pay="onsite" title="Payé sur place"><?= $r['onsite'] > 0.004 ? e(money($r['onsite'])) : '—' ?></button></td>
  <td class="num <?= $restClass ?>" data-v="<?= e(plain_num($r['remaining'])) ?>"><?= $r['price_known'] ? e(money($rest)) : '<span class="muted" title="Aucun tarif configuré">?</span>' ?></td>
  <td class="c-note" data-v="<?= e($r['notes']) ?>"><input class="ed" data-field="notes" value="<?= e($r['notes']) ?>"<?= $dis ?>></td>
  <td class="c-tel" data-v="<?= e($r['phone']) ?>"><input class="ed" data-field="phone" value="<?= e($r['phone']) ?>"<?= $dis ?>></td>
  <td class="c-mail" data-v="<?= e($r['email']) ?>"><input class="ed" type="email" data-field="email" value="<?= e($r['email']) ?>"<?= $dis ?>></td>
  <td data-v="<?= e($r['comm']) ?>"><span class="tag <?= e($comm[1]) ?>"><?= e($comm[0]) ?></span></td>
  <td data-v="<?= e($r['first_time']) ?>"><?= e($r['first_time'] ?: '') ?></td>
  <td data-v="<?= e($r['progress']['state']) ?>">
    <?php if ($pid > 0 && can('dossiers.view')): ?><a href="/dossiers/<?= $pid ?>" title="<?= e($r['progress']['detail']) ?>"><span class="dot <?= e($r['progress']['state']) ?>"></span> <?= e($r['progress']['label']) ?></a>
    <?php else: ?><span title="<?= e($r['progress']['detail']) ?>"><span class="dot <?= e($r['progress']['state']) ?>"></span> <?= e($r['progress']['label']) ?></span><?php endif; ?>
    <?php if ($r['rfid'] !== ''): ?><span class="tag info" title="Puce RFID <?= e($r['rfid']) ?>">RFID</span><?php endif; ?>
    <?php if (!$ro && !$r['cancelled'] && !$r['no_remind'] && in_array($r['progress']['state'], ['none', 'missing', 'partial', 'correction'], true) && filter_var(trim($r['email']), FILTER_VALIDATE_EMAIL)): ?><button type="button" class="cb" data-remind="1" title="Envoyer une relance par e-mail">✉</button><?php endif; ?>
    <?php if (!$ro && !$r['cancelled']): ?><label class="cb" style="display:inline-flex;align-items:center;gap:3px" title="Ne pas relancer cette personne par e-mail (déjà contactée par ailleurs) — n'affecte pas les comptes"><input type="checkbox" class="no-remind-cb" style="margin:0" <?= $r['no_remind'] ? 'checked' : '' ?>>🔕</label><?php endif; ?>
    <?php if (!$ro && $r['source'] !== 'billetweb'): ?><button type="button" class="cb" data-del="1" title="<?= $r['source'] === 'manual' ? 'Supprimer cette ligne ajoutée à la main' : 'Masquer cette ligne' ?>">✕</button><?php endif; ?>
  </td>
</tr>
