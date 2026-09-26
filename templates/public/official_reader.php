<?php /** @var int $eventId */ ?>
<div style="display:flex;flex-direction:column;height:100vh;height:100dvh">
  <div class="row" style="background:#fff;padding:10px 14px;border-bottom:1px solid var(--line);justify-content:space-between;flex-shrink:0">
    <div class="row" style="gap:6px">
      <button type="button" class="btn sec sm" id="pdf-zoom-out" aria-label="Réduire">A−</button>
      <button type="button" class="btn sec sm" id="pdf-zoom-in" aria-label="Agrandir">A+</button>
    </div>
    <span class="muted" id="reader-status">Chargement…</span>
  </div>
  <div id="pdf-host" class="pdfhost" style="flex:1;height:auto;border:0;border-radius:0"><div class="muted" id="pdf-msg">Chargement…</div></div>
  <div id="reader-done" class="flash ok hidden" style="margin:0;border-radius:0;text-align:center;flex-shrink:0">✓ Décharge lue jusqu’au bout — vous pouvez revenir à votre dossier (onglet précédent).</div>
</div>
