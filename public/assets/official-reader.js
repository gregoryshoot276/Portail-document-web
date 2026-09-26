/* Lecture plein écran de la décharge officielle, ouverte dans un nouvel onglet depuis le formulaire.
   Signale la fin de lecture à l'onglet d'origine par postMessage (aucun script en ligne : CSP script-src self). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const boot = (() => { try { return JSON.parse(($('#boot') || {}).textContent || 'null') || {}; } catch (e) { return {}; } })();
  const eventId = boot.event;
  const host = $('#pdf-host'), msg = $('#pdf-msg'), status = $('#reader-status');
  if (!host || !eventId) return;

  let pdfDoc = null, pdfZoom = 1, done = false;
  const ZOOM_MIN = 1, ZOOM_MAX = 2.5, ZOOM_STEP = 0.25;
  const show = (el, on) => { if (el) el.classList.toggle('hidden', !on); };

  function updateZoomButtons() {
    const out = $('#pdf-zoom-out'), inn = $('#pdf-zoom-in');
    if (out) out.disabled = pdfZoom <= ZOOM_MIN + 0.001;
    if (inn) inn.disabled = pdfZoom >= ZOOM_MAX - 0.001;
  }

  async function renderPages() {
    host.innerHTML = '';
    for (let n = 1; n <= pdfDoc.numPages; n++) {
      const page = await pdfDoc.getPage(n);
      const vp0 = page.getViewport({ scale: 1 });
      const fit = Math.min(2.4, Math.max(1, (host.clientWidth - 32) / vp0.width));
      const vp = page.getViewport({ scale: fit * pdfZoom });
      const cv = document.createElement('canvas'); cv.width = Math.floor(vp.width); cv.height = Math.floor(vp.height);
      host.appendChild(cv);
      await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
    }
    const sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    host.appendChild(sentinel);
    if (!done && 'IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        if (entries.some((x) => x.isIntersecting)) { markDone(); io.disconnect(); }
      }, { root: host, threshold: 0 });
      io.observe(sentinel);
    }
  }

  function markDone() {
    if (done) return;
    done = true;
    if (status) status.textContent = 'Lu jusqu’au bout';
    show($('#reader-done'), true);
    if (window.opener && !window.opener.closed) {
      try { window.opener.postMessage({ type: 'official-waiver-read', eventId }, window.location.origin); } catch (e) { /* origine différente ou fenêtre fermée : rien à faire */ }
    }
  }

  function setZoom(z) {
    pdfZoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
    updateZoomButtons();
    renderPages();
  }
  const zoomOutBtn = $('#pdf-zoom-out'), zoomInBtn = $('#pdf-zoom-in');
  if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => setZoom(pdfZoom - ZOOM_STEP));
  if (zoomInBtn) zoomInBtn.addEventListener('click', () => setZoom(pdfZoom + ZOOM_STEP));

  (async () => {
    const src = '/participer/officiel/' + eventId;
    try {
      const pdfjs = await import('/assets/vendor/pdfjs/pdf.min.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';
      pdfDoc = await pdfjs.getDocument({ url: src }).promise;
      await renderPages();
      updateZoomButtons();
      if (status) status.textContent = 'Faites défiler jusqu’en bas';
      host.addEventListener('scroll', () => {
        if (host.scrollTop + host.clientHeight >= host.scrollHeight - 40) markDone();
      }, { passive: true });
    } catch (e) {
      host.innerHTML = '';
      if (status) status.textContent = '';
      const p = document.createElement('p');
      p.className = 'muted';
      p.style.cssText = 'padding:20px;text-align:center';
      p.textContent = 'Impossible d’afficher le document ici.';
      const a = document.createElement('a');
      a.href = src; a.target = '_blank'; a.rel = 'noopener'; a.className = 'btn sec';
      a.textContent = 'Ouvrir le fichier PDF directement';
      host.style.display = 'flex'; host.style.alignItems = 'center'; host.style.justifyContent = 'center'; host.style.flexDirection = 'column';
      host.appendChild(p); host.appendChild(a);
    }
  })();
})();
