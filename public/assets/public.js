/* Formulaire public participant (aucun script en ligne : CSP « script-src self »). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const boot = (() => { try { return JSON.parse(($('#boot') || {}).textContent || 'null') || {}; } catch (e) { return {}; } })();
  const form = $('#pform');
  if (!form) return;
  const S = boot.i18n || {};
  const EVENTS = boot.events || [];
  // Déclarés en tête : refresh() les utilise dès la première exécution.
  let timer = null, seq = 0, officialScrolled = false, loadedFor = null;

  const show = (el, on) => { if (el) el.classList.toggle('hidden', !on); };
  const val = (name) => (form.elements[name] || {}).value || '';
  const typeNow = () => (form.querySelector('input[name="participant_type"]:checked') || {}).value || 'pilot';
  const eventNow = () => EVENTS.find((e) => String(e.id) === String($('#event_id').value)) || null;

  // ---------- circuit -> dates ----------
  const circuitSel = $('#circuit'), eventSel = $('#event_id');
  function fillEvents() {
    const c = Number(circuitSel.value);
    const wanted = eventSel.dataset.selected || '';
    eventSel.innerHTML = '';
    const list = EVENTS.filter((e) => e.circuit === c);
    const head = document.createElement('option'); head.value = '';
    head.textContent = !c ? S.choose_circuit_first : (list.length ? S.choose_date : S.no_date);
    eventSel.appendChild(head);
    list.forEach((e) => {
      const o = document.createElement('option'); o.value = e.id;
      const d = new Date(e.date + 'T12:00:00');
      o.textContent = d.toLocaleDateString(boot.lang || 'fr', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
      if (String(e.id) === wanted) o.selected = true;
      eventSel.appendChild(o);
    });
    eventSel.dataset.selected = '';
    refresh();
  }
  // Pré-sélection après une erreur de saisie
  function initFromOld() {
    const sel = eventSel.dataset.selected;
    const ev = EVENTS.find((e) => String(e.id) === String(sel));
    if (ev) circuitSel.value = ev.circuit;
    fillEvents();
  }
  circuitSel.addEventListener('change', fillEvents);
  eventSel.addEventListener('change', refresh);

  // ---------- rôle, circuit : quels blocs afficher ----------
  const OFFICIAL = (slug, type) => slug === 'magny-cours' || (slug === 'bugatti' && (type === 'pilot' || type === 'supplemental_driver'));
  const EXTRA = { 'magny-cours': ['birth_date', 'address', 'postal_code', 'city'], 'bugatti': ['birth_date', 'address', 'postal_code', 'city', 'permit_number', 'emergency_phone', 'image_rights'] };

  function refresh() {
    const type = typeNow(), ev = eventNow(), slug = ev ? ev.slug : '';
    const pass = type === 'passenger', supp = type === 'supplemental_driver';
    show($('#box-linked'), supp);
    show($('#box-buyer'), pass);
    show($('#box-docs'), !pass);
    show($('#box-vehicle'), type === 'pilot');
    show($('#box-insurance'), type === 'pilot');
    // clauses
    show($('#clauses-pilot'), !pass);
    show($('#clauses-passenger'), pass);
    $$('#clauses-pilot input').forEach((i) => { i.disabled = pass; });
    $$('#clauses-passenger input').forEach((i) => { i.disabled = !pass; });
    // champs demandés par le circuit
    const official = !!ev && OFFICIAL(slug, type);
    const extras = official ? (EXTRA[slug] || []) : [];
    show($('#box-extra'), extras.length > 0);
    $$('#box-extra [data-x]').forEach((d) => show(d, extras.includes(d.dataset.x)));
    // décharge officielle
    show($('#box-official'), official);
    if (official) prepareOfficial(ev);
    lockClauses(official && !officialScrolled);
    lookupSoon();
    renderWizard();
  }
  $$('input[name="participant_type"]').forEach((r) => r.addEventListener('change', refresh));

  // ---------- déroulé en étapes : une section .card à la fois, avec Précédent / Suivant ----------
  let wizStep = 0;
  const stepSections = () => $$('#pform > section.card');
  const visibleSteps = () => stepSections().filter((s) => !s.classList.contains('hidden'));
  function updateNav(vis) {
    const first = wizStep === 0, last = wizStep >= vis.length - 1;
    show($('#wiz-prev'), !first);
    show($('#wiz-next'), !last);
    show($('#btn-submit'), last);
  }
  function updateProgress(vis) {
    const bar = $('#wiz-bar');
    if (!bar) return;
    show(bar, vis.length > 1);
    if (vis.length <= 1) return;
    $('#wiz-fill').style.width = (wizStep / (vis.length - 1)) * 100 + '%';
    const lbl = $('#wiz-label');
    if (lbl) lbl.textContent = (S.step_of || 'Étape {n} sur {total}').replace('{n}', String(wizStep + 1)).replace('{total}', String(vis.length));
  }
  function renderWizard() {
    const vis = visibleSteps();
    if (wizStep > vis.length - 1) wizStep = Math.max(0, vis.length - 1);
    stepSections().forEach((s) => s.classList.add('step-hide'));
    const cur = vis[wizStep];
    if (cur) {
      cur.classList.remove('step-hide');
      // Le canvas de signature n'a une taille correcte que mesuré une fois visible (une section masquée a une
      // largeur nulle) : on la (re)calcule au moment où cette étape apparaît, tant que rien n'est signé.
      if (cur.contains(cv) && !drawn) fitCanvas();
    }
    updateNav(vis);
    updateProgress(vis);
  }
  const wizNext = $('#wiz-next'), wizPrev = $('#wiz-prev');
  if (wizNext) wizNext.addEventListener('click', () => {
    const vis = visibleSteps(), cur = vis[wizStep];
    if (cur) {
      const invalid = $$('input, select, textarea', cur).find((el) => !el.disabled && el.offsetParent !== null && !el.checkValidity());
      if (invalid) { invalid.reportValidity(); return; }
    }
    wizStep = Math.min(vis.length - 1, wizStep + 1);
    renderWizard();
    if (vis[wizStep]) vis[wizStep].scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  if (wizPrev) wizPrev.addEventListener('click', () => {
    wizStep = Math.max(0, wizStep - 1);
    renderWizard();
    const vis = visibleSteps();
    if (vis[wizStep]) vis[wizStep].scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  function lockClauses(locked) {
    $$('.clause').forEach((c) => c.classList.toggle('locked', locked));
    $$('.clause input').forEach((i) => { if (locked) { i.dataset.was = i.disabled ? '1' : ''; i.disabled = true; } else { i.disabled = (i.closest('#clauses-passenger') ? typeNow() !== 'passenger' : typeNow() === 'passenger'); } });
    const sr = $('#scroll-required'); if (sr) sr.style.display = locked ? '' : 'none';
  }

  // ---------- PDF officiel ----------
  const progWrap = $('#pdf-progress-wrap'), progBar = $('#pdf-progress-bar'), progText = $('#pdf-progress-text');
  function setProgress(pct) {
    if (!progBar) return;
    pct = Math.max(0, Math.min(100, Math.round(pct)));
    progBar.style.width = pct + '%';
    if (progText) progText.textContent = pct + ' %';
  }
  function markScrolled() {
    if (officialScrolled) return;
    officialScrolled = true;
    $('#official_scrolled').value = '1';
    setProgress(100);
    show($('#pdf-done'), true);
    lockClauses(false);
  }
  let pdfDoc = null, pdfZoom = 1, officialEventId = null, pdfSeq = 0;
  const ZOOM_MIN = 1, ZOOM_MAX = 2.5, ZOOM_STEP = 0.25;
  // Zoom = simple multiplicateur appliqué à l'échelle « ajustée à la largeur » : le texte de la décharge
  // officielle est parfois trop petit pour être lu confortablement sur téléphone. Le lien ↗ ouvre le même
  // document, en grand, dans un vrai nouvel onglet (bien plus lisible qu'un cadre sur la page) ; ce nouvel
  // onglet prévient celui-ci par postMessage une fois arrivé en bas, exactement comme le ferait un scroll ici.
  // pdfSeq protège contre un aller-retour rapide entre deux journées (ou deux clics de zoom rapprochés) : sans
  // ce garde-fou, deux chargements/rendus qui se chevauchent pourraient mélanger leurs pages dans le cadre.
  async function renderPdfPages() {
    const mySeq = ++pdfSeq;
    const host = $('#pdf-host');
    host.innerHTML = '';
    for (let n = 1; n <= pdfDoc.numPages; n++) {
      const page = await pdfDoc.getPage(n);
      if (mySeq !== pdfSeq) return;
      const vp0 = page.getViewport({ scale: 1 });
      const fit = Math.min(2, Math.max(0.8, (host.clientWidth - 24) / vp0.width));
      const vp = page.getViewport({ scale: fit * pdfZoom });
      const cv = document.createElement('canvas'); cv.width = Math.floor(vp.width); cv.height = Math.floor(vp.height);
      host.appendChild(cv);
      await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
      if (mySeq !== pdfSeq) return;
    }
    // Fin de lecture détectée par une sentinelle observée : plus fiable que le calcul scrollTop/scrollHeight
    // (arrondis différents selon l'appareil, notamment sur mobile), qui reste actif en secours ci-dessous.
    // C'est cette double détection qui évite le bug historique « j'ai bien scrollé mais ça dit le contraire ».
    const sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    host.appendChild(sentinel);
    if (!officialScrolled && 'IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        if (entries.some((x) => x.isIntersecting)) { markScrolled(); io.disconnect(); }
      }, { root: host, threshold: 0 });
      io.observe(sentinel);
    }
  }
  function updateZoomButtons() {
    const out = $('#pdf-zoom-out'), inn = $('#pdf-zoom-in');
    if (out) out.disabled = pdfZoom <= ZOOM_MIN + 0.001;
    if (inn) inn.disabled = pdfZoom >= ZOOM_MAX - 0.001;
  }
  function setZoom(z) {
    pdfZoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
    updateZoomButtons();
    renderPdfPages();
  }
  const zoomOutBtn = $('#pdf-zoom-out'), zoomInBtn = $('#pdf-zoom-in');
  if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => setZoom(pdfZoom - ZOOM_STEP));
  if (zoomInBtn) zoomInBtn.addEventListener('click', () => setZoom(pdfZoom + ZOOM_STEP));

  const pdfOpenLink = $('#pdf-open-link');
  if (pdfOpenLink) pdfOpenLink.addEventListener('click', () => { $('#official_opened').value = '1'; });
  // Le nouvel onglet (même origine) signale la fin de lecture par postMessage : on la traite comme un scroll ici.
  window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin || !e.data || e.data.type !== 'official-waiver-read') return;
    if (String(e.data.eventId) === String(officialEventId)) markScrolled();
  });

  async function prepareOfficial(ev) {
    if (loadedFor === ev.id) return;
    loadedFor = ev.id; officialEventId = ev.id; officialScrolled = false; pdfZoom = 1; pdfDoc = null;
    $('#official_scrolled').value = ''; $('#official_opened').value = ''; show($('#pdf-done'), false);
    show(progWrap, false); setProgress(0);
    const src = '/participer/officiel/' + ev.id;
    if (pdfOpenLink) pdfOpenLink.href = '/participer/decharge-lecture/' + ev.id;
    const host = $('#pdf-host'), msg = $('#pdf-msg');
    show($('#pdf-fallback'), false); host.innerHTML = ''; host.appendChild(msg); msg.textContent = S.loading || '';
    if (boot.pdfjs) {
      try {
        const pdfjs = await import('/assets/vendor/pdfjs/pdf.min.mjs');
        pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';
        const doc = await pdfjs.getDocument({ url: src }).promise;
        if (officialEventId !== ev.id) return; // une autre journée a été sélectionnée entre-temps
        pdfDoc = doc;
        await renderPdfPages();
        if (officialEventId !== ev.id) return;
        show(progWrap, true);
        updateZoomButtons();
        const check = () => {
          const span = host.scrollHeight - host.clientHeight;
          setProgress(span <= 0 ? 100 : (host.scrollTop / span) * 100);
          if (host.scrollTop + host.clientHeight >= host.scrollHeight - 40) markScrolled();
        };
        host.addEventListener('scroll', check, { passive: true });
        check();
        return;
      } catch (e) { /* on retombe sur la version simple */ }
    }
    // Sans pdf.js : lien d'ouverture + case à cocher (déclaration sur l'honneur)
    host.innerHTML = ''; show(host, false);
    const link = $('#pdf-link'); link.href = src; show($('#pdf-fallback'), true);
    $('#pdf-check').onchange = (e) => { if (e.target.checked) markScrolled(); };
  }

  // ---------- vérification Billetweb ----------
  const box = $('#bw-status'), confirmBox = $('#bw-confirm');
  function lookupSoon() { clearTimeout(timer); timer = setTimeout(lookup, 700); }
  ['nom', 'prenom', 'email', 'buyer_ref'].forEach((n) => { const el = form.elements[n]; if (el) el.addEventListener('input', lookupSoon); });

  // ---------- domaine e-mail mal orthographié (yahoo.come, gmial.com...) ----------
  const KNOWN_DOMAINS = ['gmail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr',
    'icloud.com', 'live.fr', 'laposte.net', 'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'bbox.fr', 'neuf.fr', 'numericable.fr'];
  function levenshtein(a, b) {
    const m = a.length, n = b.length;
    const d = []; for (let i = 0; i <= m; i++) { d.push([i]); }
    for (let j = 1; j <= n; j++) { d[0][j] = j; }
    for (let i = 1; i <= m; i++) { for (let j = 1; j <= n; j++) { d[i][j] = a[i - 1] === b[j - 1] ? d[i - 1][j - 1] : 1 + Math.min(d[i - 1][j], d[i][j - 1], d[i - 1][j - 1]); } }
    return d[m][n];
  }
  function emailSuggestion(value) {
    const at = value.lastIndexOf('@');
    if (at < 1) { return null; }
    const domain = value.slice(at + 1).toLowerCase();
    if (!domain || KNOWN_DOMAINS.includes(domain)) { return null; }
    let best = null, bestDist = 3;
    KNOWN_DOMAINS.forEach((d) => { const dist = levenshtein(domain, d); if (dist > 0 && dist < bestDist) { bestDist = dist; best = d; } });
    return best ? value.slice(0, at + 1) + best : null;
  }
  const emailEl = form.elements['email'];
  if (emailEl) {
    const emailHint = document.createElement('div');
    emailHint.className = 'bwbox warn hidden';
    emailEl.insertAdjacentElement('afterend', emailHint);
    emailEl.addEventListener('blur', () => {
      const suggestion = emailSuggestion(emailEl.value.trim());
      show(emailHint, !!suggestion);
      if (!suggestion) { return; }
      emailHint.innerHTML = '';
      emailHint.appendChild(document.createTextNode('Vouliez-vous dire '));
      const link = document.createElement('button');
      link.type = 'button'; link.className = 'btn sec sm'; link.textContent = suggestion;
      link.addEventListener('click', () => { emailEl.value = suggestion; show(emailHint, false); lookupSoon(); });
      emailHint.appendChild(link);
      emailHint.appendChild(document.createTextNode(' ?'));
    });
  }
  function setBox(text, cls) { box.textContent = text; box.className = 'bwbox ' + (cls || 'muted'); }
  async function lookup() {
    const ev = eventNow(), nom = val('nom').trim(), prenom = val('prenom').trim(), email = val('email').trim();
    show(confirmBox, false);
    if (!ev || !nom || !prenom || !email) { setBox(S.fill_identity || '', 'muted'); insurance(null); return; }
    const my = ++seq; setBox(S.search_bw || '', 'muted');
    const q = new URLSearchParams({ event_id: ev.id, participant_type: typeNow(), nom, prenom, email, buyer_ref: val('buyer_ref'), confirmed_attendee_id: $('#confirmed_attendee_id').value });
    try {
      const r = await fetch('/participer/verifier?' + q.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json();
      if (my !== seq) return;
      const st = j.status;
      let msg = j.message || '';
      if (j.identity_warning) msg += ' ' + j.identity_warning.message;
      setBox(msg || (S.check_done || ''), st === 'found' ? 'ok' : (st === 'not_found' || st === 'wrong_type' || st === 'confirm_needed' ? 'warn' : 'muted'));
      if (st === 'confirm_needed' && j.candidate_id) {
        show(confirmBox, true);
        $('#bw-confirm-text').textContent = (j.candidate_email_hint || '') + ' — ';
        $('#bw-yes').onclick = () => { $('#confirmed_attendee_id').value = j.candidate_id; $('#ignored_candidate_id').value = ''; lookup(); };
        $('#bw-no').onclick = () => { $('#ignored_candidate_id').value = j.candidate_id; $('#confirmed_attendee_id').value = ''; show(confirmBox, false); setBox(S.check_done || '', 'muted'); insurance(false); };
      }
      insurance(st === 'unknown' ? false : !!j.insurance_found);
      applyDeclaredVehicle(j.vehicle || '');
    } catch (e) { if (my === seq) { setBox(S.check_unavailable || '', 'muted'); insurance(false); } }
  }
  // ---------- véhicule déclaré à l'inscription (confirmation V / X, modifiable) ----------
  let vehicleDeclared = '';
  function refreshVehicleStatus() {
    const vEl = $('#vehicle'), st = $('#vehicle-status');
    if (!vEl || !st) return;
    if (!vehicleDeclared) { st.innerHTML = ''; return; }
    const cur = vEl.value.trim();
    if (cur !== '' && norm(cur) === norm(vehicleDeclared)) {
      st.innerHTML = '<span class="tag ok" title="Identique au véhicule indiqué à l’inscription">V — confirmé</span>';
    } else {
      st.innerHTML = '<span class="tag warn" title="Différent du véhicule indiqué à l’inscription">X — modifié</span>';
    }
  }
  function applyDeclaredVehicle(declared) {
    const vEl = $('#vehicle'), hint = $('#vehicle-hint');
    if (!vEl || !hint) return;
    if (!declared) { return; }
    vehicleDeclared = declared;
    // On ne pré-remplit/écrase que si le champ est vide ou contient encore notre précédent pré-remplissage
    // (jamais une saisie que la personne a faite elle-même).
    if (vEl.value.trim() === '' || vEl.value.trim() === declared) {
      vEl.value = declared;
    }
    hint.textContent = 'Vous êtes inscrit(e) avec « ' + declared + ' ». Confirmez si c’est bien ce véhicule, ou modifiez le champ si vous roulez finalement avec un autre — assurez-vous alors que votre assurance lui corresponde.';
    refreshVehicleStatus();
  }
  const vehicleEl = $('#vehicle');
  if (vehicleEl) vehicleEl.addEventListener('input', refreshVehicleStatus);
  function insurance(found) {
    const pilot = typeNow() === 'pilot';
    show($('#ins-found'), pilot && found === true);
    show($('#ins-missing'), pilot && found !== true);
  }
  const modeRadios = $$('input[name="insurance_mode"]');
  modeRadios.forEach((r) => r.addEventListener('change', () => show($('#ins-file'), (form.querySelector('input[name="insurance_mode"]:checked') || {}).value !== 'organizer')));
  insurance(false);

  // ---------- signature ----------
  const cv = $('#sig'), ctx = cv.getContext('2d');
  let drawing = false, drawn = false;
  function fitCanvas() {
    const r = cv.getBoundingClientRect(), ratio = window.devicePixelRatio || 1;
    cv.width = Math.max(300, Math.floor(r.width * ratio)); cv.height = Math.floor(r.height * ratio);
    ctx.scale(ratio, ratio); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111'; drawn = false;
  }
  fitCanvas();
  // Rotation d'écran / clavier qui se ferme avant la signature : on réajuste la résolution du cadre tant que
  // rien n'est dessiné (changer width/height d'un canvas l'efface, donc jamais après un trait déjà tracé) et
  // seulement s'il est actuellement affiché (sinon getBoundingClientRect renverrait des dimensions nulles).
  window.addEventListener('resize', () => { if (!drawn && cv.offsetParent !== null) fitCanvas(); });
  const pos = (e) => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  cv.addEventListener('pointerdown', (e) => { drawing = true; cv.setPointerCapture(e.pointerId); const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); e.preventDefault(); });
  cv.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); drawn = true; e.preventDefault(); });
  ['pointerup', 'pointercancel'].forEach((n) => cv.addEventListener(n, () => { drawing = false; }));
  $('#sig-clear').addEventListener('click', () => { ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, cv.width, cv.height); ctx.restore(); drawn = false; });

  form.addEventListener('submit', (e) => {
    if (!drawn) { e.preventDefault(); alert(S.signature_required || 'Signature obligatoire.'); return; }
    // PNG transparent : la signature se superpose proprement sur le PDF officiel du circuit.
    $('#signature_data').value = cv.toDataURL('image/png');
    $('#btn-submit').disabled = true;
  });

  // Toutes les fonctions et constantes sont définies : on peut initialiser l'affichage (déclenche aussi
  // renderWizard() via refresh()).
  initFromOld();

  // ---------- après une erreur : montrer directement le bon champ (et la bonne étape), pas juste la liste en haut ----------
  const errBox = $('.flash.ko');
  if (errBox) {
    const errText = errBox.textContent || '';
    const FIELD_HINTS = [
      [/permis/i, '#permis'], [/assurance/i, '#box-insurance'], [/signature/i, '#sig'],
      [/lieu de signature/i, '#signed_place'], [/clauses/i, '#clauses-pilot'],
      [/décharge officielle|parcourir/i, '#box-official'], [/nom et prénom/i, '#nom'],
      [/e-?mail/i, '#email'], [/événement/i, '#event_id'], [/pilote principal/i, '#linked_driver_name'],
      [/droit à l.image/i, '[data-x="image_rights"]'], [/naissance/i, '#birth_date'],
    ];
    let target = null;
    for (const [re, sel] of FIELD_HINTS) { if (re.test(errText)) { target = document.querySelector(sel); if (target) { break; } } }
    if (target) {
      const stepIdx = visibleSteps().indexOf(target.closest('#pform > section.card'));
      if (stepIdx !== -1) { wizStep = stepIdx; renderWizard(); }
    }
    (target || errBox).scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (target) { target.classList.add('field-error'); setTimeout(() => target.classList.remove('field-error'), 3000); }
  }
})();

/* Formulaire « Avis » (avis.php) : révèle le champ de capture propre à chaque plateforme cochée. */
(function () {
  'use strict';
  const form = document.getElementById('reviewForm');
  if (!form) return;
  form.querySelectorAll('.review-platform-cb').forEach((cb) => {
    cb.addEventListener('change', () => {
      const box = form.querySelector('[data-file-for="' + cb.value + '"]');
      if (!box) return;
      box.hidden = !cb.checked;
      const f = box.querySelector('input[type=file]');
      if (f) f.required = cb.checked;
    });
  });
})();

/* Formulaire « Annulation / remplacement » (annulation) : révèle les champs du remplaçant si « Oui » est coché. */
(function () {
  'use strict';
  const form = document.getElementById('changeForm');
  if (!form) return;
  const box = document.getElementById('changeReplacement');
  const fields = box ? box.querySelectorAll('input') : [];
  form.querySelectorAll('[name="has_replacement"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const on = radio.value === '1' && radio.checked;
      if (!box) return;
      box.hidden = !on;
      fields.forEach((f) => { if (f.name === 'replacement_nom' || f.name === 'replacement_prenom' || f.name === 'replacement_email') f.required = on; });
    });
  });
})();
