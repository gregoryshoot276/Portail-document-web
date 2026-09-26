<?php
use JC\Domain\I18n;

/** @var array $events @var array $circuits @var array $old @var array $errors @var string $key @var bool $readonly @var bool $pdfjs
 * @var ?array $customer @var array $accDocs @var ?array $teamInvite */
$t = fn(string $k, array $v = []) => I18n::t($k, $v);
$o = fn(string $k, string $d = '') => (string)($old[$k] ?? $d);
$type = $o('participant_type', 'pilot');
$checked = fn(string $k, string $val, string $default = '') => ($o($k, $default) === $val) ? 'checked' : '';
$clausesPilot = I18n::clauses('pilot');
$clausesPass = I18n::clauses('passenger');
$customer = $customer ?? null;
$accDocs = $accDocs ?? ['permis' => null, 'assurance' => null];
$teamInvite = $teamInvite ?? null;
$formAction = $teamInvite ? '/team/' . $teamInvite['token'] : '/participer';
?>
<h1><?= e($t('your_file')) ?></h1>
<p class="muted">Les champs marqués d’un <b>*</b> sont obligatoires.</p>
<div class="wizard-bar hidden" id="wiz-bar">
  <div class="wizard-track"><div class="wizard-fill" id="wiz-fill"></div></div>
  <div class="muted" id="wiz-label" style="margin-top:4px;font-size:12.5px"></div>
</div>

<?php if ($teamInvite): ?>
  <div class="flash ok"><b><?= e($t('team_invite')) ?> — <?= e($teamInvite['team_name']) ?></b><br><?= e($t('team_personal')) ?></div>
<?php elseif ($customer): ?>
  <div class="flash ok"><?= e($t('my_space')) ?> : <?= e(trim(($customer['prenom'] ?? '') . ' ' . ($customer['email'] ?? ''))) ?>
    · <form method="post" action="/mon-espace/deconnexion" style="display:inline"><?= csrf_field() ?><button type="submit" class="btn sec sm"><?= e($t('logout')) ?></button></form></div>
<?php else: ?>
  <div class="flash" style="background:#f2f0e8"><?= e($t('space_desc')) ?> <a href="/mon-espace/creer"><?= e($t('create_space')) ?></a> · <a href="/mon-espace"><?= e($t('my_space')) ?></a></div>
<?php endif; ?>

<?php if ($readonly): ?><div class="flash warn"><?= e($t('readonly_notice')) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="flash ko"><b><?= e($t('fix_errors')) ?></b><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" id="pform" autocomplete="on">
<?= csrf_field() ?>
<input type="hidden" name="submission_key" value="<?= e($key) ?>">
<input type="hidden" name="signature_data" id="signature_data" value="">
<input type="hidden" name="official_scrolled" id="official_scrolled" value="">
<input type="hidden" name="official_opened" id="official_opened" value="">
<input type="hidden" name="confirmed_attendee_id" id="confirmed_attendee_id" value="">
<input type="hidden" name="ignored_candidate_id" id="ignored_candidate_id" value="">

<?php if ($teamInvite): ?>
  <input type="hidden" name="participant_type" value="pilot">
<?php else: ?>
<section class="card">
  <h2><?= e($t('you_are')) ?></h2>
  <div class="typeboxes">
    <label class="typebox"><input type="radio" name="participant_type" value="pilot" <?= $checked('participant_type', 'pilot', 'pilot') ?>><b><?= e($t('pilot')) ?></b><span><?= e($t('pilot_desc')) ?></span></label>
    <label class="typebox"><input type="radio" name="participant_type" value="supplemental_driver" <?= $checked('participant_type', 'supplemental_driver') ?>><b><?= e($t('supp')) ?></b><span><?= e($t('supp_desc')) ?></span></label>
    <label class="typebox"><input type="radio" name="participant_type" value="passenger" <?= $checked('participant_type', 'passenger') ?>><b><?= e($t('passenger')) ?></b><span><?= e($t('passenger_desc')) ?></span></label>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <h2><?= e($t('your_day')) ?></h2>
  <div class="g2">
    <div><label class="f" for="circuit"><?= e($t('circuit')) ?> *</label>
      <select id="circuit" required><option value=""><?= e($t('choose')) ?></option><?php foreach ($circuits as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="f" for="event_id"><?= e($t('date')) ?> *</label>
      <select id="event_id" name="event_id" required data-selected="<?= e($o('event_id')) ?>"><option value=""><?= e($t('choose_circuit_first')) ?></option></select></div>
  </div>
</section>

<section class="card">
  <h2><?= e($t('identity')) ?></h2>
  <div class="g2">
    <div><label class="f" for="nom"><?= e($t('last_name')) ?> *</label><input id="nom" name="nom" value="<?= e($o('nom')) ?>" required maxlength="120" autocomplete="family-name"></div>
    <div><label class="f" for="prenom"><?= e($t('first_name')) ?> *</label><input id="prenom" name="prenom" value="<?= e($o('prenom')) ?>" required maxlength="120" autocomplete="given-name"></div>
    <div><label class="f" for="email"><?= e($t('email')) ?> *</label><input id="email" type="email" name="email" value="<?= e($o('email')) ?>" required maxlength="190" autocomplete="email"></div>
    <div><label class="f" for="telephone"><?= e($t('phone')) ?></label><input id="telephone" type="tel" name="telephone" value="<?= e($o('telephone')) ?>" maxlength="60" autocomplete="tel"></div>
  </div>
  <div id="box-linked" class="hidden field"><label class="f" for="linked_driver_name"><?= e($t('linked_driver')) ?></label><input id="linked_driver_name" name="linked_driver_name" value="<?= e($o('linked_driver_name')) ?>" maxlength="190"></div>
  <div id="box-buyer" class="hidden field"><label class="f" for="buyer_ref"><?= e($t('buyer_label')) ?></label><input id="buyer_ref" name="buyer_ref" value="<?= e($o('buyer_ref')) ?>" placeholder="<?= e($t('buyer_placeholder')) ?>" maxlength="190"><div class="muted"><?= e($t('buyer_hint')) ?></div></div>
  <div id="bw-status" class="bwbox muted" aria-live="polite"><?= e($t('fill_identity')) ?></div>
  <div id="bw-confirm" class="bwbox hidden"><span id="bw-confirm-text"></span> <button type="button" class="btn sm" id="bw-yes"><?= e($t('confirm_mine')) ?></button> <button type="button" class="btn sec sm" id="bw-no"><?= e($t('confirm_not')) ?></button></div>
</section>

<section class="card hidden" id="box-extra">
  <h2><?= e($t('extra_info')) ?></h2>
  <div class="g2">
    <div data-x="birth_date"><label class="f" for="birth_date"><?= e($t('birth_date')) ?></label><input id="birth_date" type="date" name="birth_date" value="<?= e($o('birth_date')) ?>"></div>
    <div data-x="address"><label class="f" for="address"><?= e($t('address')) ?></label><input id="address" name="address" value="<?= e($o('address')) ?>" maxlength="250" autocomplete="street-address"></div>
    <div data-x="postal_code"><label class="f" for="postal_code"><?= e($t('postal')) ?></label><input id="postal_code" name="postal_code" value="<?= e($o('postal_code')) ?>" maxlength="20" autocomplete="postal-code"></div>
    <div data-x="city"><label class="f" for="city"><?= e($t('city')) ?></label><input id="city" name="city" value="<?= e($o('city')) ?>" maxlength="120" autocomplete="address-level2"></div>
    <div data-x="permit_number"><label class="f" for="permit_number"><?= e($t('permit_no')) ?></label><input id="permit_number" name="permit_number" value="<?= e($o('permit_number')) ?>" maxlength="60"></div>
    <div data-x="emergency_phone"><label class="f" for="emergency_phone"><?= e($t('emergency')) ?></label><input id="emergency_phone" type="tel" name="emergency_phone" value="<?= e($o('emergency_phone')) ?>" maxlength="60"></div>
    <div data-x="club"><label class="f" for="club">Club</label><input id="club" name="club" value="<?= e($o('club')) ?>" maxlength="120"></div>
    <div data-x="license_number"><label class="f" for="license_number">N° licence</label><input id="license_number" name="license_number" value="<?= e($o('license_number')) ?>" maxlength="60"></div>
    <div data-x="aco_member_number"><label class="f" for="aco_member_number">N° adhérent ACO</label><input id="aco_member_number" name="aco_member_number" value="<?= e($o('aco_member_number')) ?>" maxlength="60"></div>
  </div>
  <div data-x="image_rights" class="field"><div class="f"><?= e($t('image_rights')) ?></div>
    <label class="row"><input type="radio" name="image_rights" value="yes" <?= $checked('image_rights', 'yes') ?>> <?= e($t('authorize')) ?></label>
    <label class="row"><input type="radio" name="image_rights" value="no" <?= $checked('image_rights', 'no') ?>> <?= e($t('refuse')) ?></label></div>
</section>

<section class="card" id="box-docs">
  <h2><?= e($t('pilot_docs')) ?></h2>
  <div id="box-vehicle" class="hidden field">
    <label class="f" for="vehicle">Véhicule <span id="vehicle-status"></span></label>
    <input id="vehicle" name="vehicle" value="<?= e($o('vehicle')) ?>" maxlength="190" placeholder="Marque, modèle et couleur">
    <div class="muted" id="vehicle-hint">Indiquez le véhicule avec lequel vous roulerez. Ce n’est pas obligatoire de rouler avec le véhicule indiqué à l’inscription, mais votre assurance doit correspondre au véhicule que vous utiliserez réellement.</div>
  </div>
  <div class="permit">
    <div class="permit-title"><?= e($t('driving_license')) ?> — <?= e($t('category_b')) ?></div>
    <p class="muted"><?= e($t('permit_note')) ?></p>
    <?php if ($accDocs['permis']): ?>
      <label class="row"><input type="checkbox" name="reuse_permit" value="1" checked>
        Réutiliser mon permis déjà validé (<?= e((string)$accDocs['permis']['original_name']) ?>)</label>
    <?php endif; ?>
    <label class="f" for="permis"><?= e($t('permit_front')) ?> *</label><input id="permis" type="file" name="permis" accept=".pdf,image/jpeg,image/png,image/webp,.heic,.heif,image/heic,image/heif">
    <label class="f" for="permis_verso"><?= e($t('permit_back')) ?></label><input id="permis_verso" type="file" name="permis_verso" accept=".pdf,image/jpeg,image/png,image/webp,.heic,.heif,image/heic,image/heif">
  </div>
  <div id="box-insurance" class="permit">
    <div class="permit-title"><?= e($t('insurance')) ?></div>
    <?php if ($teamInvite): ?>
      <div class="flash ok"><?= e($t('team_insurance_managed')) ?></div>
    <?php else: ?>
    <div id="ins-found" class="flash ok hidden"><?= e($t('insurance_found')) ?></div>
    <div id="ins-missing" class="hidden">
      <div class="flash warn"><b><?= e($t('rc_missing')) ?></b><br><?= e($t('rc_missing_help')) ?></div>
      <?php if ($accDocs['assurance']): ?>
        <label class="row"><input type="checkbox" name="reuse_insurance" value="1" checked>
          Réutiliser mon assurance déjà validée (<?= e((string)$accDocs['assurance']['original_name']) ?>, valable jusqu’au <?= e(fdate($accDocs['assurance']['valid_until'] ?? null)) ?>)</label>
      <?php endif; ?>
      <label class="row"><input type="radio" name="insurance_mode" value="personal" <?= $checked('insurance_mode', 'personal', 'personal') ?>> <?= e($t('personal_insurance')) ?></label>
      <label class="row"><input type="radio" name="insurance_mode" value="organizer" <?= $checked('insurance_mode', 'organizer') ?>> <?= e($t('organizer_insurance')) ?></label>
      <div id="ins-file"><label class="f" for="assurance"><?= e($t('certificate')) ?></label><input id="assurance" type="file" name="assurance" accept=".pdf,image/jpeg,image/png,image/webp,.heic,.heif,image/heic,image/heif">
        <label class="f" for="insurance_valid_until"><?= e($t('valid_until')) ?></label><input id="insurance_valid_until" type="date" name="insurance_valid_until" value="<?= e($o('insurance_valid_until')) ?>"><div class="muted"><?= e($t('valid_hint')) ?></div></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <h2><?= e($t('waiver')) ?></h2>
  <div id="box-official" class="hidden">
    <div class="flash" style="background:#eef2ff;color:#3730a3;font-weight:700"><?= e($t('legal_french')) ?> <?= e($t('official_read')) ?></div>
    <div class="row" style="justify-content:flex-end;margin:8px 0 6px">
      <button type="button" class="btn sec sm" id="pdf-zoom-out" aria-label="Réduire">A−</button>
      <button type="button" class="btn sec sm" id="pdf-zoom-in" aria-label="Agrandir">A+</button>
      <a class="btn sec sm" id="pdf-open-link" href="#" target="_blank" rel="opener">↗ <?= e($t('open_official')) ?></a>
    </div>
    <div id="pdf-progress-wrap" class="hidden" style="margin:8px 0">
      <div style="height:8px;border-radius:4px;background:#e5e1d6;overflow:hidden"><div id="pdf-progress-bar" style="height:100%;width:0%;background:var(--red);transition:width .15s"></div></div>
      <div class="muted" id="pdf-progress-text" style="margin-top:4px;font-size:12.5px">0 %</div>
    </div>
    <div id="pdf-host" class="pdfhost" data-src=""><div class="muted" id="pdf-msg"><?= e($t('loading')) ?></div></div>
    <div id="pdf-fallback" class="hidden"><a class="btn sec" id="pdf-link" target="_blank" rel="noopener" href="#"><?= e($t('open_official')) ?></a>
      <label class="row" style="margin-top:8px"><input type="checkbox" id="pdf-check"> <?= e($t('official_check')) ?></label></div>
    <div id="pdf-done" class="flash ok hidden">✓ <?= e($t('pdf_done')) ?></div>
  </div>
  <h3><?= e($t('commitments')) ?></h3>
  <div id="clauses-pilot" class="clauses"><?php foreach ($clausesPilot as $k => $txt): ?><label class="row clause"><input type="checkbox" name="waiver_clause[<?= e($k) ?>]" value="1" <?= !empty($old['waiver_clause'][$k]) ? 'checked' : '' ?>> <span><?= e($txt) ?></span></label><?php endforeach; ?></div>
  <div id="clauses-passenger" class="clauses hidden"><?php foreach ($clausesPass as $k => $txt): ?><label class="row clause"><input type="checkbox" name="waiver_clause[<?= e($k) ?>]" value="1" data-pass="1" disabled> <span><?= e($txt) ?></span></label><?php endforeach; ?></div>
  <div class="muted" id="scroll-required" style="display:none"><?= e($t('scroll_required')) ?></div>
</section>

<section class="card">
  <h2><?= e($t('signature')) ?></h2>
  <div class="g2">
    <div><label class="f" for="signed_place"><?= e($t('signed_at')) ?> *</label><input id="signed_place" name="signed_place" value="<?= e($o('signed_place')) ?>" required maxlength="190"></div>
    <div><label class="f"><?= e($t('on')) ?></label><input value="<?= e(date('d/m/Y')) ?>" disabled></div>
  </div>
  <canvas id="sig" width="700" height="220" aria-label="<?= e($t('signature')) ?>"></canvas>
  <div class="row" style="margin-top:8px"><button type="button" class="btn sec sm" id="sig-clear"><?= e($t('clear')) ?></button><span class="muted"><?= e($t('guest_note')) ?></span></div>
</section>

<div class="wizard-nav" id="wiz-nav">
  <button type="button" class="btn sec hidden" id="wiz-prev"><?= e($t('previous')) ?></button>
  <button type="button" class="btn hidden" id="wiz-next"><?= e($t('next')) ?></button>
  <button class="btn big" type="submit" id="btn-submit" <?= $readonly ? 'disabled' : '' ?>><?= e($t('submit')) ?></button>
</div>
</form>
