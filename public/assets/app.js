/* Portail Journée Circuit v3 — scripts des pages (aucun script en ligne : CSP « script-src self »). */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const CSRF = ($('meta[name="csrf"]') || {}).content || '';
  const RO = document.body.dataset.readonly === '1';
  const boot = (() => { try { return JSON.parse(($('#boot') || {}).textContent || 'null') || {}; } catch (e) { return {}; } })();

  function toast(msg, kind) {
    const t = document.createElement('div');
    t.className = 'toast ' + (kind || '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), kind === 'ko' ? 5000 : 2600);
  }

  /** POST JSON avec jeton CSRF. Lève une erreur lisible si le serveur refuse. */
  async function api(url, payload) {
    let r;
    try {
      r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
      });
    } catch (e) { throw new Error('Connexion impossible. Réessayez.'); }
    let j = {};
    try { j = await r.json(); } catch (e) { /* réponse non JSON */ }
    if (!r.ok || j.ok === false) {
      const err = new Error(j.message || ('Erreur ' + r.status));
      err.readonly = !!j.readonly;
      err.data = j;
      throw err;
    }
    return j;
  }

  function fail(e) { toast(e.message, e.readonly ? 'ro' : 'ko'); }

  // Fermeture des fenêtres <dialog>
  document.addEventListener('click', (ev) => {
    const c = ev.target.closest('[data-close]');
    if (c) { const d = c.closest('dialog'); if (d) d.close(); }
  });

  // Confirmation avant soumission (remplace les onclick="return confirm(...)" en ligne, bloqués par la CSP
  // script-src 'self') et sélection en un clic d'un champ en lecture seule (liens secrets à copier).
  document.addEventListener('submit', (ev) => {
    const btn = ev.submitter;
    if (btn && btn.dataset.confirm && !confirm(btn.dataset.confirm)) ev.preventDefault();
  });
  document.addEventListener('click', (ev) => {
    if (ev.target.classList && ev.target.classList.contains('select-all')) ev.target.select();
  });

  const norm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  /* ========================= LISTING ========================= */
  function initListing() {
    const table = $('#listing'); if (!table) return;
    const tbody = $('tbody', table);
    const EVENT = boot.event;
    const URL_API = '/listing/' + EVENT + '/api';
    let since = boot.now || 0;

    const rowEl = (id) => document.getElementById('row-' + id);

    function replaceRow(id, html) {
      const old = rowEl(id); if (!old || !html) return;
      const tpl = document.createElement('template'); tpl.innerHTML = html.trim();
      const neu = tpl.content.firstElementChild; if (!neu) return;
      old.replaceWith(neu);
      applyFilters(); refreshRest();
    }

    async function save(input) {
      const tr = input.closest('tr'); const id = tr.dataset.id;
      const value = input.value;
      if (input.dataset.old === value) return;
      try {
        const j = await api(URL_API, { action: 'save', entry_id: Number(id), field: input.dataset.field, value: value });
        replaceRow(id, j.html); toast('Enregistré');
      } catch (e) { input.value = input.dataset.old || ''; fail(e); }
    }

    // Édition en ligne
    tbody.addEventListener('focusin', (ev) => { if (ev.target.classList.contains('ed')) ev.target.dataset.old = ev.target.value; });
    tbody.addEventListener('change', (ev) => { if (ev.target.classList.contains('ed') && !ev.target.readOnly) save(ev.target); });
    tbody.addEventListener('keydown', (ev) => {
      if (ev.target.classList.contains('ed') && ev.key === 'Enter') { ev.preventDefault(); ev.target.blur(); }
      if (ev.target.classList.contains('ed') && ev.key === 'Escape') { ev.target.value = ev.target.dataset.old || ''; ev.target.blur(); }
    });

    // Boutons à choix (VAL, A, D, format)
    let menu = null;
    function closeMenu() { if (menu) { menu.remove(); menu = null; } }
    document.addEventListener('click', (ev) => { if (menu && !ev.target.closest('.choice-menu')) closeMenu(); });

    tbody.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button'); if (!b) return;
      const tr = b.closest('tr'); const id = Number(tr.dataset.id);

      if (b.dataset.choice) {
        ev.stopPropagation(); closeMenu();
        let opts = b.dataset.options === '@formats' ? (boot.formats || []) : (b.dataset.options || '').split('|');
        menu = document.createElement('div'); menu.className = 'choice-menu';
        opts.forEach((o) => {
          const it = document.createElement('button'); it.type = 'button'; it.textContent = o === '' ? '— (vide)' : o;
          it.addEventListener('click', async () => {
            closeMenu();
            try { const j = await api(URL_API, { action: 'save', entry_id: id, field: b.dataset.choice, value: o }); replaceRow(id, j.html); toast('Enregistré'); }
            catch (e) { fail(e); }
          });
          menu.appendChild(it);
        });
        const r = b.getBoundingClientRect();
        menu.style.left = (window.scrollX + r.left) + 'px'; menu.style.top = (window.scrollY + r.bottom + 2) + 'px';
        document.body.appendChild(menu);
        return;
      }
      if (b.dataset.waiver) {
        const order = ['', 'V', 'J', 'X']; const next = order[(order.indexOf(b.dataset.value) + 1) % order.length];
        try { const j = await api(URL_API, { action: 'waiver_override', entry_id: id, status: next }); replaceRow(id, j.html); toast('Décharge : ' + (next || 'automatique')); }
        catch (e) { fail(e); }
        return;
      }
      if (b.dataset.pay) { openPayments(id, b.dataset.pay, tr.dataset.name); return; }
      if (b.dataset.del) {
        if (!confirm('Retirer cette ligne du listing ?')) return;
        try { await api(URL_API, { action: 'delete_row', entry_id: id }); tr.remove(); refreshRest(); toast('Ligne retirée'); }
        catch (e) { fail(e); }
      }
    });

    // Ne pas relancer : fait taire les relances pour quelqu'un déjà contacté par ailleurs — le cas le plus
    // courant est justement un billet Billetweb SANS dossier portail.
    tbody.addEventListener('change', async (ev) => {
      const cb = ev.target.closest('.no-remind-cb'); if (!cb) return;
      const tr = cb.closest('tr'); const id = Number(tr.dataset.id);
      try {
        const j = await api(URL_API, { action: 'no_remind', entry_id: id, value: cb.checked });
        replaceRow(id, j.html); refreshRemind();
        toast(cb.checked ? 'Relances désactivées pour cette personne' : 'Relances réactivées pour cette personne');
      } catch (e) { cb.checked = !cb.checked; fail(e); }
    });

    // Paiements
    const dlgPay = $('#dlg-pay'); let payEntry = 0, payCtx = 'advance';
    async function loadPayments() {
      const j = await api(URL_API, { action: 'payment_list', entry_id: payEntry });
      const tb = $('#pay-list tbody'); tb.innerHTML = '';
      const rows = (j.rows || []).filter((r) => r.payment_context === payCtx);
      if (payCtx === 'advance' && Number(j.auto) > 0) {
        const tr = document.createElement('tr'); const td = document.createElement('td'); td.colSpan = 2;
        td.textContent = 'Payé en ligne (Billetweb, net de commission) — ' + Number(j.auto).toLocaleString('fr-FR') + ' €';
        tr.appendChild(td); tb.appendChild(tr);
      }
      if (!rows.length && !(payCtx === 'advance' && Number(j.auto) > 0)) { tb.innerHTML = '<tr><td class="muted">Aucun paiement saisi.</td></tr>'; }
      rows.forEach((r) => {
        const tr = document.createElement('tr');
        const td1 = document.createElement('td'); td1.textContent = r.payment_method + ' — ' + Number(r.amount).toLocaleString('fr-FR') + ' €' + (r.note ? ' (' + r.note + ')' : '');
        const td2 = document.createElement('td'); td2.style.textAlign = 'right';
        if (!RO) { const d = document.createElement('button'); d.className = 'btn sec sm'; d.type = 'button'; d.textContent = 'Supprimer';
          d.addEventListener('click', async () => { try { await api(URL_API, { action: 'payment_delete', id: Number(r.id) }); await afterPay(); } catch (e) { fail(e); } });
          td2.appendChild(d); }
        tr.append(td1, td2); tb.appendChild(tr);
      });
    }
    async function afterPay() {
      await loadPayments();
      const j = await (await fetch('/listing/' + EVENT + '/rows?ids=' + payEntry, { credentials: 'same-origin' })).json();
      if (j.rows && j.rows[payEntry]) replaceRow(payEntry, j.rows[payEntry]);
    }
    async function openPayments(id, ctx, name) {
      payEntry = id; payCtx = ctx;
      $('#pay-title').textContent = (ctx === 'advance' ? 'Avance' : 'Payé sur place') + ' — ' + (name || '');
      try { await loadPayments(); dlgPay.showModal(); } catch (e) { fail(e); }
    }
    const payForm = $('#pay-form');
    if (payForm) payForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(payForm);
      try {
        await api(URL_API, { action: 'payment_add', entry_id: payEntry, context: payCtx, method: fd.get('method'), amount: fd.get('amount'), note: fd.get('note') });
        payForm.reset(); await afterPay(); toast('Paiement ajouté');
      } catch (e) { fail(e); }
    });

    // Ajout d'une inscription
    const dlgAdd = $('#dlg-add'), btnAdd = $('#btn-add'), addForm = $('#add-form');
    if (btnAdd && dlgAdd) btnAdd.addEventListener('click', () => dlgAdd.showModal());
    if (addForm) addForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const data = Object.fromEntries(new FormData(addForm).entries());
      try { await api(URL_API, Object.assign({ action: 'create_row' }, data)); location.reload(); } catch (e) { fail(e); }
    });

    // Filtres
    const q = $('#q'), fState = $('#f-state'), fRest = $('#f-rest'), fCancel = $('#f-cancel');
    // Filtre Format à cases à cocher (plusieurs à la fois : ex. Journée + Matin + Après-midi, ou Pilote suppl.
    // + Pilote suppl. femme ensemble) plutôt qu'un menu déroulant à un seul choix.
    const fFormatCbs = () => $$('.f-format-cb');
    function formatChecked() { return fFormatCbs().filter((cb) => cb.checked); }
    function applyFilters() {
      const s = norm(q.value); let shown = 0;
      const fmtAll = fFormatCbs(), fmtChecked = formatChecked(), fmtFiltering = fmtChecked.length !== fmtAll.length;
      const fmtSet = new Set(fmtChecked.map((cb) => cb.value));
      $$('tr', tbody).forEach((tr) => {
        let ok = true;
        if (s && !norm(tr.dataset.search + ' ' + tr.dataset.name + ' ' + tr.dataset.vehicle).includes(s)) ok = false;
        if (ok && fmtFiltering && !fmtSet.has(tr.dataset.format)) ok = false;
        if (ok && fState.value) {
          const st = tr.dataset.state;
          if (fState.value === 'ready' && st !== 'ready') ok = false;
          if (fState.value === 'incomplete' && !['missing', 'partial', 'review', 'correction'].includes(st)) ok = false;
          if (fState.value === 'none' && st !== 'none') ok = false;
        }
        if (ok && fRest.checked && !(parseFloat(tr.dataset.remaining) > 0.005)) ok = false;
        if (ok && !fCancel.checked && tr.dataset.cancelled === '1') ok = false;
        tr.hidden = !ok; if (ok) shown++;
      });
      $('#count-shown').textContent = shown + ' ligne(s) affichée(s)';
      refreshRest();
    }
    function refreshRest() {
      let t = 0;
      $$('tr', tbody).forEach((tr) => { if (!tr.hidden && tr.dataset.cancelled !== '1') { const v = parseFloat(tr.dataset.remaining); if (v > 0) t += v; } });
      const k = $('#kpi-rest'); if (k) k.textContent = t.toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' €';
    }
    [q, fState, fRest, fCancel].forEach((el) => el.addEventListener(el === q ? 'input' : 'change', applyFilters));
    const arch = $('#archive-pick'); if (arch) arch.addEventListener('change', () => { if (arch.value) location.href = '/listing?event=' + arch.value; });

    const fFormatBtn = $('#f-format-btn'), fFormatPanel = $('#f-format-panel');
    function updateFormatBtnLabel() {
      if (!fFormatBtn) return;
      const all = fFormatCbs(), checked = formatChecked();
      if (checked.length === 0) fFormatBtn.textContent = 'Aucun format';
      else if (checked.length === all.length) fFormatBtn.textContent = 'Tous les formats';
      else if (checked.length <= 2) fFormatBtn.textContent = checked.map((cb) => cb.value).join(', ');
      else fFormatBtn.textContent = checked.length + ' formats';
    }
    if (fFormatBtn && fFormatPanel) {
      fFormatBtn.addEventListener('click', (ev) => { ev.stopPropagation(); fFormatPanel.hidden = !fFormatPanel.hidden; });
      document.addEventListener('click', (ev) => { if (!fFormatPanel.hidden && !ev.target.closest('#f-format-msel')) fFormatPanel.hidden = true; });
      fFormatCbs().forEach((cb) => cb.addEventListener('change', () => { updateFormatBtnLabel(); applyFilters(); }));
      const fmtAllBtn = $('#f-format-all'), fmtNoneBtn = $('#f-format-none');
      if (fmtAllBtn) fmtAllBtn.addEventListener('click', () => { fFormatCbs().forEach((cb) => { cb.checked = true; }); updateFormatBtnLabel(); applyFilters(); });
      if (fmtNoneBtn) fmtNoneBtn.addEventListener('click', () => { fFormatCbs().forEach((cb) => { cb.checked = false; }); updateFormatBtnLabel(); applyFilters(); });
    }

    fCancel.checked = false; applyFilters();

    // Largeurs de colonnes modifiables (glisser le bord droit d'un en-tête), mémorisées dans ce navigateur.
    // Minimum par colonne (pas un simple 30px pour toutes) : sans ça, une colonne texte (Participant, Véhicule,
    // Mail...) réduite par erreur devient illisible — juste une pastille suivie de « … », plus aucun nom visible.
    const LS_COLW = 'jc_listing_colw';
    const COLW_MIN = { 1: 44, 2: 110, 3: 90, 4: 50, 5: 40, 6: 40, 7: 50, 8: 60, 9: 50, 10: 50, 11: 60, 12: 60, 13: 50, 14: 60, 15: 70, 16: 100, 17: 44, 18: 44, 19: 90 };
    function loadColWidths() { try { return JSON.parse(localStorage.getItem(LS_COLW) || '{}'); } catch (e) { return {}; } }
    function saveColWidths(w) { try { localStorage.setItem(LS_COLW, JSON.stringify(w)); } catch (e) { /* stockage indisponible */ } }
    (function initColResize() {
      const saved = loadColWidths();
      $$('th[data-colw]', table).forEach((th) => { const w = saved[th.dataset.colw]; if (w) th.style.width = Math.max(COLW_MIN[th.dataset.colw] || 44, w) + 'px'; });
      let resizing = null, startX = 0, startW = 0;
      $$('.colresize', table).forEach((handle) => {
        handle.addEventListener('click', (ev) => ev.stopPropagation());
        handle.addEventListener('pointerdown', (ev) => {
          ev.preventDefault(); ev.stopPropagation();
          resizing = { th: handle.closest('th'), col: handle.dataset.col };
          startX = ev.clientX; startW = resizing.th.offsetWidth;
          handle.classList.add('active'); handle.setPointerCapture(ev.pointerId);
        });
        handle.addEventListener('pointermove', (ev) => {
          if (!resizing) return;
          resizing.th.style.width = Math.max(COLW_MIN[resizing.col] || 44, startW + (ev.clientX - startX)) + 'px';
        });
        ['pointerup', 'pointercancel'].forEach((n) => handle.addEventListener(n, () => {
          if (!resizing) return;
          const w = loadColWidths(); w[resizing.col] = resizing.th.offsetWidth; saveColWidths(w);
          handle.classList.remove('active'); resizing = null;
        }));
      });
      const resetBtn = $('#btn-colw-reset');
      if (resetBtn) resetBtn.addEventListener('click', () => {
        try { localStorage.removeItem(LS_COLW); } catch (e) { /* ignore */ }
        location.reload();
      });
    })();

    // Colonnes masquables (case à cocher dans le panneau « Colonnes », ou clic droit sur un en-tête en raccourci),
    // mémorisées dans ce navigateur. Un <style> généré à la volée cible aussi bien l'en-tête (data-col) que les
    // cellules du corps (nth-child) : ça s'applique automatiquement aux lignes reconstruites après une action
    // (replaceRow), sans avoir à ré-appliquer le masquage ligne par ligne.
    const LS_COLHIDE = 'jc_listing_colhide';
    function loadColHide() { try { return new Set(JSON.parse(localStorage.getItem(LS_COLHIDE) || '[]')); } catch (e) { return new Set(); } }
    function saveColHide(set) { try { localStorage.setItem(LS_COLHIDE, JSON.stringify(Array.from(set))); } catch (e) { /* stockage indisponible */ } }
    (function initColHide() {
      const colHide = loadColHide();
      let style = document.getElementById('colhide-style');
      if (!style) { style = document.createElement('style'); style.id = 'colhide-style'; document.head.appendChild(style); }
      function apply() {
        const sel = Array.from(colHide).map((c) => 'table.listing th[data-col="' + c + '"], table.listing td:nth-child(' + (Number(c) + 1) + ')');
        style.textContent = sel.length ? sel.join(',\n') + '{display:none}' : '';
      }
      const cbs = $$('.col-hide-cb');
      cbs.forEach((cb) => { cb.checked = !colHide.has(cb.value); });
      apply();
      cbs.forEach((cb) => cb.addEventListener('change', () => {
        if (cb.checked) colHide.delete(cb.value); else colHide.add(cb.value);
        saveColHide(colHide); apply();
      }));
      $$('thead th[data-col]', table).forEach((th) => th.addEventListener('contextmenu', (ev) => {
        ev.preventDefault();
        const col = th.dataset.col;
        colHide.add(col); saveColHide(colHide); apply();
        const cb = cbs.find((c) => c.value === col); if (cb) cb.checked = false;
        toast('Colonne masquée — décochez-la dans « Colonnes » pour la réafficher');
      }));
      const colsBtn = $('#f-cols-btn'), colsPanel = $('#f-cols-panel');
      if (colsBtn && colsPanel) {
        colsBtn.addEventListener('click', (ev) => { ev.stopPropagation(); colsPanel.hidden = !colsPanel.hidden; });
        document.addEventListener('click', (ev) => { if (!colsPanel.hidden && !ev.target.closest('#f-cols-msel')) colsPanel.hidden = true; });
      }
    })();

    // Tri par colonne
    let sortState = { col: -1, dir: 1 };
    $$('thead th[data-col]', table).forEach((th) => th.addEventListener('click', () => {
      const col = Number(th.dataset.col);
      sortState.dir = sortState.col === col ? -sortState.dir : 1; sortState.col = col;
      const rows = $$('tr', tbody);
      const val = (tr) => { const td = tr.children[col]; return td ? (td.dataset.v !== undefined ? td.dataset.v : td.textContent.trim()) : ''; };
      rows.sort((a, b) => {
        const x = val(a), y = val(b), nx = parseFloat(x.replace(',', '.')), ny = parseFloat(y.replace(',', '.'));
        if (!isNaN(nx) && !isNaN(ny) && /^-?[\d.,]+$/.test(x) && /^-?[\d.,]+$/.test(y)) return (nx - ny) * sortState.dir;
        return x.localeCompare(y, 'fr', { numeric: true, sensitivity: 'base' }) * sortState.dir;
      });
      rows.forEach((r) => tbody.appendChild(r));
    }));

    // Sélection -> étiquettes
    const bl = $('#btn-labels');
    if (bl) bl.addEventListener('click', (ev) => {
      const ids = $$('.pick:checked', tbody).map((c) => c.closest('tr').dataset.id);
      if (ids.length) bl.href = '/listing/' + EVENT + '/etiquettes.pdf?ids=' + ids.join(',');
      else bl.href = '/listing/' + EVENT + '/etiquettes.pdf';
    });

    // Relances par e-mail
    const btnRemindAll = $('#btn-remind-all');
    function refreshRemind() {
      if (!btnRemindAll) return;
      const n = $$('tr[data-remind="1"]', tbody).filter((tr) => !tr.hidden).length;
      btnRemindAll.textContent = 'Relancer incomplets (' + n + ')'; btnRemindAll.disabled = n === 0;
    }
    tbody.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button[data-remind]'); if (!b) return;
      const tr = b.closest('tr');
      if (!confirm('Envoyer une relance par e-mail à ' + (tr.dataset.name || 'ce participant') + ' ?')) return;
      try { const j = await api(URL_API, { action: 'reminder_mail', entry_id: Number(tr.dataset.id) }); toast(j.message || 'Relance envoyée'); }
      catch (e) { fail(e); }
    });
    if (btnRemindAll) {
      btnRemindAll.addEventListener('click', async () => {
        const trs = $$('tr[data-remind="1"]', tbody).filter((tr) => !tr.hidden);
        if (!trs.length || !confirm('Envoyer ' + trs.length + ' relance(s) par e-mail aux dossiers incomplets affichés ?')) return;
        let ok = 0, ko = 0; btnRemindAll.disabled = true;
        for (const tr of trs) {
          try { await api(URL_API, { action: 'reminder_mail', entry_id: Number(tr.dataset.id) }); ok++; } catch (e) { ko++; if (e.readonly) break; }
          btnRemindAll.textContent = 'Envoi… ' + (ok + ko) + '/' + trs.length;
        }
        toast(ok + ' relance(s) envoyée(s)' + (ko ? ', ' + ko + ' échec(s)' : ''), ko ? 'ko' : '');
        refreshRemind();
      });
      const origApply = applyFilters; // le compteur suit les filtres
      ['input', 'change'].forEach((n) => document.addEventListener(n, (e) => { if (e.target && ['q', 'f-format', 'f-state', 'f-rest', 'f-cancel'].includes(e.target.id)) setTimeout(refreshRemind, 0); }));
      refreshRemind();
    }

    // Fusion de deux lignes
    const btnMerge = $('#btn-merge'), dlgMerge = $('#dlg-merge'), mergeForm = $('#merge-form');
    function picked() { return $$('.pick:checked', tbody).map((c) => c.closest('tr')); }
    tbody.addEventListener('change', (ev) => {
      if (!ev.target.classList.contains('pick') || !btnMerge) return;
      const n = picked().length; btnMerge.textContent = 'Fusionner (' + n + '/2)'; btnMerge.disabled = n !== 2;
    });
    if (btnMerge) {
      btnMerge.addEventListener('click', () => {
        const [a, b] = picked(); if (!a || !b) return;
        const box = $('#merge-fields'); box.innerHTML = '';
        [['display_name', 'Nom', 'name'], ['vehicle', 'Véhicule', 'vehicle'], ['email', 'E-mail', 'email'], ['phone', 'Téléphone', 'phone']].forEach(([field, label, key]) => {
          const va = a.dataset[key] || '', vb = b.dataset[key] || '';
          const wrap = document.createElement('div'); wrap.className = 'field';
          const l = document.createElement('div'); l.className = 'f'; l.textContent = label; wrap.appendChild(l);
          if (va === vb) { const h = document.createElement('input'); h.type = 'hidden'; h.name = field; h.value = va; wrap.appendChild(h); const s = document.createElement('div'); s.textContent = va || '(vide)'; wrap.appendChild(s); }
          else {
            [[va, 'Ligne 1'], [vb, 'Ligne 2']].forEach(([v, t], i) => {
              const lab = document.createElement('label'); lab.className = 'row';
              const r = document.createElement('input'); r.type = 'radio'; r.name = field; r.value = v; r.checked = i === 0 ? !!v || !vb : !va && !!vb;
              lab.appendChild(r); lab.appendChild(document.createTextNode(' ' + t + ' : ' + (v || '(vide)'))); wrap.appendChild(lab);
            });
          }
          box.appendChild(wrap);
        });
        mergeForm.dataset.a = a.dataset.id; mergeForm.dataset.b = b.dataset.id;
        dlgMerge.showModal();
      });
      mergeForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const data = { action: 'merge_rows', entry_a: Number(mergeForm.dataset.a), entry_b: Number(mergeForm.dataset.b) };
        new FormData(mergeForm).forEach((v, k) => { data[k] = v; });
        try { await api(URL_API, data); location.reload(); } catch (e) { fail(e); }
      });
    }

    // Synchronisation Billetweb (manuelle, et automatique toutes les 3 minutes en mode écriture)
    const btnSync = $('#btn-sync');
    async function syncNow(force) {
      try {
        const j = await api(URL_API, { action: 'sync_remote', force: !!force });
        if (j.errors && j.errors.length) toast(j.errors[0], 'ko');
        else if (force) toast(j.skipped ? (j.message || 'Déjà à jour') : (j.changed ? 'Nouvelles inscriptions : rechargement…' : 'Billetweb synchronisé'));
        if (j.changed) setTimeout(() => location.reload(), 800);
      } catch (e) { if (force) fail(e); }
    }
    if (btnSync) {
      btnSync.addEventListener('click', () => { btnSync.disabled = true; syncNow(true).finally(() => { btnSync.disabled = false; }); });
      setInterval(() => { if (!document.hidden) syncNow(false); }, 180000);
    }

    // Actualisation légère : recharge uniquement les lignes modifiées par un autre poste
    async function poll() {
      if (document.hidden) return;
      try {
        const j = await (await fetch('/listing/' + EVENT + '/poll?since=' + since, { credentials: 'same-origin' })).json();
        if (!j.ok) return;
        since = j.now;
        if (j.new_rows) { toast('Nouvelles inscriptions : rechargement…'); setTimeout(() => location.reload(), 900); return; }
        const active = document.activeElement;
        const ids = (j.ids || []).filter((id) => !(active && active.closest && active.closest('#row-' + id)));
        if (ids.length) {
          const r = await (await fetch('/listing/' + EVENT + '/rows?ids=' + ids.slice(0, 60).join(','), { credentials: 'same-origin' })).json();
          Object.keys(r.rows || {}).forEach((id) => replaceRow(id, r.rows[id]));
        }
      } catch (e) { /* réseau indisponible : on réessaiera */ }
    }
    setInterval(poll, 8000);
  }

  /* ========================= STARTER ========================= */
  function initStarter() {
    const dlg = $('#dlg-st'); if (!dlg) return;
    const EVENT = boot.event; const API = boot.apiBase || ('/starter/' + EVENT + '/api'); let current = null;
    $$('.refpill').forEach((b) => b.addEventListener('click', () => {
      current = b;
      $('#st-ref').textContent = b.dataset.ref || 'SANS REF';
      $('#st-veh').textContent = b.dataset.vehicle || 'Véhicule non renseigné';
      $('#st-fmt').textContent = b.dataset.format || 'Format non renseigné';
      $('#st-meta').textContent = b.dataset.checked ? 'Dernier contrôle : ' + b.dataset.checked : 'Pas encore contrôlé';
      const re = $('#st-refedit'); if (re) re.value = b.dataset.ref || '';
      dlg.showModal();
    }));
    $$('.choices button', dlg).forEach((btn) => btn.addEventListener('click', async () => {
      if (!current) return;
      try {
        await api(API, { action: 'check', entry_id: Number(current.dataset.id), status: btn.dataset.status });
        dlg.close();
        if (btn.dataset.status === 'ok') { current.remove(); toast('OK enregistré'); } else { location.reload(); }
      } catch (e) { fail(e); }
    }));
    const sr = $('#st-refsave');
    if (sr) sr.addEventListener('click', async () => {
      if (!current) return;
      try { await api(API, { action: 'reference', entry_id: Number(current.dataset.id), reference: $('#st-refedit').value.trim() }); location.reload(); }
      catch (e) { fail(e); }
    });
    const sq = $('#sq');
    if (sq) sq.addEventListener('input', () => { const s = norm(sq.value.trim()); $$('.refpill').forEach((b) => { b.hidden = !!s && !norm(b.dataset.search).includes(s); }); });

    $$('#bracelets input[type=color]').forEach((inp) => inp.addEventListener('change', async () => {
      const chip = inp.closest('.bracelet-chip'), sw = chip ? $('.swatch', chip) : null;
      try {
        await api(API, { action: 'bracelet', type: inp.dataset.bracelet, color: inp.value });
        if (sw) sw.style.background = inp.value;
      } catch (e) { fail(e); }
    }));

    const slList = $('#sl-list');
    const STATUS_LABELS = { red: 'Piste arrêtée', green: 'Piste relancée', yellow: 'Note' };
    function logClick(status) {
      return async () => {
        const reasonEl = $('#sl-reason');
        const reason = reasonEl ? reasonEl.value.trim() : '';
        try {
          const r = await api(API, { action: 'log', status, reason });
          const e = r.entry || {};
          const div = document.createElement('div');
          div.className = 'sl-entry sl-' + status;
          const time = (e.created_at || '').split(' ')[1] || (e.created_at || '');
          div.innerHTML = '<b>' + (time.slice(0, 5) || '') + '</b> — ' + (STATUS_LABELS[status] || status)
            + (reason ? ' — ' + reason.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])) : '')
            + (e.created_by ? ' <span class="muted">(' + e.created_by.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])) + ')</span>' : '');
          if (slList.textContent.trim() === 'Aucune entrée pour l’instant.') slList.innerHTML = '';
          slList.insertBefore(div, slList.firstChild);
          if (reasonEl) reasonEl.value = '';
        } catch (e) { fail(e); }
      };
    }
    const slRed = $('#sl-red'), slGreen = $('#sl-green'), slYellow = $('#sl-yellow');
    if (slRed) slRed.addEventListener('click', logClick('red'));
    if (slGreen) slGreen.addEventListener('click', logClick('green'));
    if (slYellow) slYellow.addEventListener('click', logClick('yellow'));
  }

  /* ========================= RFID ========================= */
  function initRfid() {
    const EVENT = boot.event; if (!EVENT) return;
    const tags = $$('.tag-in');
    tags.forEach((i, idx) => {
      if (i.readOnly) return;
      const save = async () => {
        try {
          await api('/rfid/' + EVENT + '/api', { action: 'set_tag', entry_id: Number(i.dataset.entry), tag_uid: i.value });
          i.classList.add('saved'); setTimeout(() => i.classList.remove('saved'), 800);
          if (tags[idx + 1]) tags[idx + 1].focus();
        } catch (e) { fail(e); }
      };
      i.addEventListener('change', save);
      i.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); save(); } });
    });
    if (tags[0] && !tags[0].readOnly) tags[0].focus();

    const scan = $('#scan'), msg = $('#scan-msg');
    if (scan) {
      scan.focus();
      scan.addEventListener('keydown', async (ev) => {
        if (ev.key !== 'Enter') return; ev.preventDefault();
        const ref = scan.value.trim(); if (!ref) return;
        try {
          const r = await api('/rfid/' + EVENT + '/api', { action: 'scan_ref', reference: ref });
          msg.textContent = 'Bip enregistré : ' + ref; msg.className = 'msg okm'; setTimeout(() => location.reload(), 250);
        } catch (e) {
          const d = e.data || {};
          msg.textContent = d.unknown_ref ? 'REF inconnue : ' + ref : d.duplicate_ref ? 'REF en doublon : ' + ref : d.no_tag ? 'Aucune puce affectée à ' + ref : e.message;
          msg.className = 'msg kom';
        }
        scan.value = ''; scan.focus();
      });
    }
    const vs = $('#vsearch'), vr = $('#vresults'), cars = (() => { try { return JSON.parse($('#cars').textContent); } catch (e) { return []; } })();
    if (vs && vr) vs.addEventListener('input', () => {
      const s = norm(vs.value.trim()); vr.innerHTML = ''; if (s.length < 2) return;
      cars.filter((x) => norm((x.vehicle || '') + ' ' + (x.ref || '')).includes(s)).slice(0, 12).forEach((x) => {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'btn sec sm'; b.textContent = (x.ref || 'SANS REF') + ' · ' + (x.vehicle || 'Véhicule non renseigné');
        b.addEventListener('click', () => { if (scan) { scan.value = x.ref || ''; scan.focus(); } vr.innerHTML = ''; vs.value = x.vehicle || ''; });
        vr.appendChild(b);
      });
    });

    // Sessions URTime
    const load = $('#ur-load'), sel = $('#ur-event'), go = $('#ur-go'), out = $('#ur-out');
    if (load) {
      const url = '/rfid/' + EVENT + '/urtime';
      load.addEventListener('click', async () => {
        try {
          const j = await api(url, { action: 'events' });
          sel.innerHTML = '<option value="">— événement URTime —</option>';
          (j.data || []).forEach((e) => { const o = document.createElement('option'); o.value = e.id; o.textContent = (e.name || e.title || 'Événement') + ' (#' + e.id + ')'; sel.appendChild(o); });
          toast((j.data || []).length + ' événement(s) URTime');
        } catch (e) { fail(e); }
      });
      sel.addEventListener('change', () => { go.disabled = !sel.value; });
      go.addEventListener('click', async () => {
        out.textContent = 'Calcul en cours…';
        try {
          const j = await api(url, { action: 'sessions', event_id: Number(sel.value), start: $('#ur-start').value, end: $('#ur-end').value });
          render(j.cars || [], j.detections || 0);
        } catch (e) { out.textContent = ''; fail(e); }
      });
      const fmt = (s) => { if (s == null) return '—'; const m = Math.floor(s / 60), r = Math.round(s % 60); return m + ' min ' + String(r).padStart(2, '0') + ' s'; };
      function render(cars, n) {
        out.innerHTML = '';
        const h = document.createElement('p'); h.className = 'muted'; h.textContent = n + ' détection(s) · ' + cars.length + ' voiture(s)'; out.appendChild(h);
        const wrap = document.createElement('div'); wrap.className = 'scroll';
        const t = document.createElement('table'); t.className = 't';
        t.innerHTML = '<thead><tr><th>REF</th><th>Pilote</th><th>Véhicule</th><th>Puce</th><th>Sessions</th><th>Temps total</th><th>Statut</th></tr></thead>';
        const tb = document.createElement('tbody');
        cars.forEach((c) => {
          const tr = document.createElement('tr');
          [c.reference || '—', c.participant || '—', c.vehicle || '—', c.bib, String(c.session_count), fmt(c.total_seconds)].forEach((v) => { const td = document.createElement('td'); td.textContent = v; tr.appendChild(td); });
          const td = document.createElement('td'); const tag = document.createElement('span'); tag.className = 'tag ' + (c.on_track ? 'warn' : 'gr'); tag.textContent = c.on_track ? 'En piste depuis ' + (c.current_entry_at || '') : 'Au stand'; td.appendChild(tag); tr.appendChild(td);
          tb.appendChild(tr);
        });
        t.appendChild(tb); wrap.appendChild(t); out.appendChild(wrap);
      }
    }
  }

  /* ========================= BILAN ========================= */
  function initBilan() {
    $$('.edit-fin').forEach((x) => {
      if (x.readOnly) return;
      x.addEventListener('change', async () => {
        const tr = x.closest('tr');
        try { await api('/bilan/save', { event_id: Number(tr.dataset.event), field: x.dataset.field, value: x.value }); location.reload(); }
        catch (e) { fail(e); }
      });
    });
  }

  /* ========================= CONTRÔLE BILLETWEB ========================= */
  function initBilletwebControl() {
    const btn = $('#bwc-run'); if (!btn) return;
    const EVENT = boot.event;
    const out = $('#bwc-out');

    function rowsTable(items, extraHead) {
      const wrap = document.createElement('div'); wrap.className = 'scroll';
      const t = document.createElement('table'); t.className = 't';
      t.innerHTML = '<thead><tr><th>Nom</th><th>E-mail</th><th>' + extraHead + '</th></tr></thead>';
      const tb = document.createElement('tbody');
      items.forEach((it) => {
        const tr = document.createElement('tr');
        [it.name || '—', it.email || '—', it.ticket || '—'].forEach((v) => { const td = document.createElement('td'); td.textContent = v; tr.appendChild(td); });
        tb.appendChild(tr);
      });
      t.appendChild(tb); wrap.appendChild(t);
      return wrap;
    }
    function section(title, help, items, extraHead) {
      const wrap = document.createElement('div'); wrap.className = 'card';
      const h = document.createElement('h3'); h.textContent = title + ' (' + items.length + ')'; wrap.appendChild(h);
      const p = document.createElement('p'); p.className = 'muted'; p.textContent = help; wrap.appendChild(p);
      wrap.appendChild(items.length ? rowsTable(items, extraHead) : Object.assign(document.createElement('p'), { className: 'muted', textContent: 'Aucun écart.' }));
      return wrap;
    }
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      out.innerHTML = '';
      out.appendChild(Object.assign(document.createElement('p'), { className: 'muted', textContent: 'Interrogation de Billetweb en cours…' }));
      try {
        const j = await api('/billetweb-control/' + EVENT + '/live', {});
        out.innerHTML = '';
        if (j.attendees) {
          out.appendChild(Object.assign(document.createElement('p'), { innerHTML: '<b>Billetterie principale</b> — Billetweb (en direct) : ' + j.attendees.live_count + ' actif(s) et payé(s).' }));
          out.appendChild(section('Absents du portail', 'Trouvés chez Billetweb à l’instant, jamais synchronisés localement : utilisez « Synchroniser Billetweb » sur le Listing.', j.attendees.missing_locally, 'Billet'));
          out.appendChild(section('Absents chez Billetweb', 'Présents en local mais introuvables chez Billetweb maintenant (billet annulé/remboursé, ou remontée locale à corriger).', j.attendees.missing_live, 'Billet'));
          out.appendChild(section('Données différentes', 'Présents des deux côtés mais une information a changé chez Billetweb depuis la dernière synchro.', j.attendees.changed, 'Écart'));
        } else {
          out.appendChild(Object.assign(document.createElement('p'), { className: 'muted', textContent: 'Aucun identifiant Billetweb (billetterie principale) configuré pour cette journée.' }));
        }
        if (j.post) {
          out.appendChild(Object.assign(document.createElement('p'), { innerHTML: '<b>Options post-inscription</b> — Billetweb (en direct) : ' + j.post.live_count + ' actif(s) et payé(s) pour cette date.' }));
          out.appendChild(section('Absentes du portail', 'Options post-inscription trouvées chez Billetweb mais pas encore synchronisées.', j.post.missing_locally, 'Billet'));
          out.appendChild(section('Absentes chez Billetweb', 'Présentes en local mais introuvables chez Billetweb maintenant.', j.post.missing_live, 'Billet'));
        } else {
          out.appendChild(Object.assign(document.createElement('p'), { className: 'muted', textContent: 'Aucun événement post-inscription configuré.' }));
        }
        toast('Vérification terminée');
      } catch (e) { out.innerHTML = ''; fail(e); }
      btn.disabled = false;
    });
  }

  /* ========================= APERÇU DE DOCUMENT (dossiers) ========================= */
  function initDossierPreview() {
    const dlg = $('#dlg-preview'); if (!dlg) return;
    const img = $('#prev-img', dlg), canvas = $('#prev-canvas', dlg), pdf = $('#prev-pdf', dlg);
    const zoomOutBtn = $('#prev-zoom-out', dlg), zoomInBtn = $('#prev-zoom-in', dlg);
    let rotation = 0, zoom = 1;
    const ZOOM_MIN = 1, ZOOM_MAX = 3, ZOOM_STEP = 0.5;
    function currentEl() { return img.style.display !== 'none' ? img : (canvas.style.display !== 'none' ? canvas : pdf); }
    function applyRotation() {
      const el = currentEl();
      // Pivoté à 90/270°, un PDF plein écran déborderait du cadre : on le réduit un peu, comme le faisait
      // l'ancien portail (seul l'iframe a besoin de cet ajustement, image/canvas s'adaptent déjà via max-width/height).
      const scale = (el === pdf && (rotation === 90 || rotation === 270)) ? 0.78 : 1;
      el.style.transform = 'rotate(' + rotation + 'deg) scale(' + scale + ')';
    }
    // Zoom (image/permis, miniature PDF) : agrandit au-delà du cadre, la case défile alors pour se déplacer
    // dedans — le PDF plein cadre a son propre zoom natif (barre d'outils du lecteur du navigateur), inutile ici.
    function applyZoom() {
      const on = zoom > 1.001;
      [img, canvas].forEach((el) => {
        el.style.width = on ? (zoom * 100) + '%' : '';
        el.style.maxWidth = on ? 'none' : '100%';
      });
    }
    function setZoom(z) {
      zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
      if (zoomOutBtn) zoomOutBtn.disabled = zoom <= ZOOM_MIN + 0.001;
      if (zoomInBtn) zoomInBtn.disabled = zoom >= ZOOM_MAX - 0.001;
      applyZoom();
    }
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => setZoom(zoom - ZOOM_STEP));
    if (zoomInBtn) zoomInBtn.addEventListener('click', () => setZoom(zoom + ZOOM_STEP));
    $('#prev-rotate', dlg).addEventListener('click', () => { rotation = (rotation + 90) % 360; applyRotation(); });
    $$('.docprev').forEach((box) => {
      if (box.dataset.href === undefined) return;
      box.addEventListener('click', () => {
        rotation = 0;
        const href = box.dataset.href;
        const isPdf = box.dataset.pdf === '1';
        setZoom(1);
        if (zoomOutBtn) { zoomOutBtn.disabled = isPdf || zoomOutBtn.disabled; zoomOutBtn.title = isPdf ? 'Utilisez le zoom du lecteur PDF' : 'Réduire'; }
        if (zoomInBtn) { zoomInBtn.disabled = isPdf; zoomInBtn.title = isPdf ? 'Utilisez le zoom du lecteur PDF' : 'Agrandir'; }
        if (isPdf) {
          // Aperçu plein cadre : un simple <iframe> délégué au lecteur PDF natif du navigateur — plus simple
          // et plus fiable qu'un rendu pdf.js ici (pas de dépendance de version d'API à maintenir). La barre
          // d'outils du lecteur reste visible : c'est elle qui fournit le zoom pour un PDF.
          img.style.display = 'none'; canvas.style.display = 'none'; pdf.style.display = '';
          pdf.src = href + '#navpanes=0';
          applyRotation();
          dlg.showModal();
        } else if (box.querySelector('img')) {
          canvas.style.display = 'none'; pdf.style.display = 'none'; pdf.src = ''; img.style.display = '';
          img.src = href; applyRotation();
          dlg.showModal();
        }
      });
    });
    // Miniatures PDF dans la liste des documents (première page, petit format)
    const thumbs = $$('canvas.pdfthumb');
    if (thumbs.length) {
      (async () => {
        try {
          const pdfjs = await import('/assets/vendor/pdfjs/pdf.min.mjs');
          pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';
          for (const cv of thumbs) {
            const box = cv.closest('.docprev'); if (!box) continue;
            try {
              const doc = await pdfjs.getDocument({ url: box.dataset.href }).promise;
              const page = await doc.getPage(1);
              const vp0 = page.getViewport({ scale: 1 });
              const scale = Math.min(cv.width / vp0.width, cv.height / vp0.height);
              const vp = page.getViewport({ scale });
              cv.width = Math.floor(vp.width); cv.height = Math.floor(vp.height);
              await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
            } catch (e) { /* miniature indisponible : le clic pour agrandir réessaiera */ }
          }
        } catch (e) { /* pdf.js indisponible sur cette page */ }
      })();
    }
  }

  /* ========================= FILTRE SIMPLE (MONO, PHOTOGRAPHE) ========================= */
  function initSimpleFilter(tableId) {
    const t = $(tableId); if (!t) return;
    const q = $('#q');
    if (q) q.addEventListener('input', () => {
      const s = norm(q.value.trim());
      $$('tbody tr[data-search]', t).forEach((r) => { r.hidden = !!s && !norm(r.dataset.search).includes(s); });
    });
    const printBtn = $('#mono-print');
    if (printBtn) printBtn.addEventListener('click', () => window.print());
  }

  /* ========================= DOSSIERS : filtres instantanés ========================= */
  function initDossiersFilter() {
    // Ne JAMAIS sortir tôt ici même si la page affiche 0 dossier (filtre trop restrictif) : c'est justement
    // dans ce cas que l'utilisateur a besoin que les champs du formulaire (statut, valideur...) restent
    // fonctionnels pour pouvoir revenir en arrière, sans quoi le filtre semble « figé ».
    const form = $('#dossiers-filters');
    const rows = $$('.dossiers-table tbody tr[data-group]');
    const q = $('#df-q');
    const todoHead = $('#todo-count'), doneHead = $('#done-count');
    function apply() {
      const s = norm(q.value.trim());
      let todoShown = 0, doneShown = 0;
      rows.forEach((tr) => {
        const ok = !s || norm(tr.dataset.search).includes(s);
        tr.hidden = !ok;
        if (ok) { if (tr.dataset.group === 'todo') todoShown++; else doneShown++; }
      });
      if (todoHead) todoHead.textContent = todoShown + ' dossier(s) à traiter';
      if (doneHead) doneHead.textContent = doneShown + ' dossier(s) validé(s)';
    }
    if (q) q.addEventListener('input', apply);
    if (form) $$('select, input[type=checkbox]', form).forEach((el) => el.addEventListener('change', () => form.submit()));
  }

  const page = document.body.dataset.page;
  initDossierPreview();
  initDossiersFilter();
  initSimpleFilter('#monoTable');
  initSimpleFilter('#photoTable');
  if (page === 'listing') initListing();
  if (page === 'starter') initStarter();
  if (page === 'rfid') initRfid();
  if (page === 'bilan') initBilan();
  if (page === 'billetweb-control') initBilletwebControl();
})();
