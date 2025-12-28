<?php
// admin/text_snippets.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../shared/text_snippets.php';
require_admin();

$csrf = csrf_token();
render_admin_header('Textbausteine');
?>

<div class="card">
  <div class="row" style="align-items:flex-end; gap:10px; flex-wrap:wrap;">
    <div style="flex:1; min-width:220px;">
      <label class="label">Titel</label>
      <input class="input" id="tsTitle" type="text" placeholder="z.B. Lernziel" style="width:100%;">
    </div>
    <div style="flex:1; min-width:200px;">
      <label class="label">Kategorie (optional)</label>
      <input class="input" id="tsCategory" type="text" placeholder="z.B. Schülerziele" style="width:100%;">
    </div>
    <div style="flex:2; min-width:260px;">
      <label class="label">Inhalt</label>
      <textarea class="input" id="tsContent" rows="3" placeholder="Textbaustein..." style="width:100%;"></textarea>
    </div>
    <div style="display:flex; gap:8px; align-items:center;">
      <button class="btn" type="button" id="tsSave">Speichern</button>
      <button class="btn secondary" type="button" id="tsGenerate">Grundstock generieren</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
    <div>
      <h2 style="margin:0;">Vorhandene Textbausteine</h2>
      <div class="muted">Rechtsklick auf Eingabefelder in entry.php fügt diese Bausteine als Kontextmenü hinzu.</div>
    </div>
    <div class="pill-mini" id="tsStatus" style="display:none;"></div>
  </div>
  <div id="tsList" style="margin-top:12px; display:flex; flex-direction:column; gap:8px;"></div>
</div>

<script>
(function(){
  const apiUrl = <?= json_encode(url('admin/ajax/text_snippets_api.php')) ?>;
  const csrf = <?= json_encode($csrf) ?>;
  const tsList = document.getElementById('tsList');
  const tsStatus = document.getElementById('tsStatus');

  async function api(action, payload={}){
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, csrf_token: csrf, ...payload })
    });
    const j = await res.json().catch(()=>null);
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Fehler');
    return j;
  }

  function showStatus(msg){
    tsStatus.textContent = msg;
    tsStatus.style.display = 'inline-flex';
    setTimeout(() => { tsStatus.style.display = 'none'; }, 2500);
  }

  function render(list){
    tsList.innerHTML = '';
    if (!list.length) {
      tsList.innerHTML = '<div class="muted">Keine Textbausteine vorhanden.</div>';
      return;
    }
    list.forEach(s => {
      const row = document.createElement('div');
      row.className = 'del-row';
      row.innerHTML = `
        <div class="l">
          <div class="t">${s.title ? s.title : '(ohne Titel)'}</div>
          <div class="s">${s.category ? s.category + ' · ' : ''}${s.created_by_name || '—'}${s.is_generated ? ' · automatisch' : ''}</div>
          <div class="s" style="white-space:pre-wrap;">${s.content}</div>
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
          <button class="btn danger" type="button" data-id="${s.id}">Löschen</button>
        </div>
      `;
      row.querySelector('button')?.addEventListener('click', async () => {
        if (!confirm('Diesen Textbaustein wirklich löschen?')) return;
        await api('delete', { id: s.id });
        showStatus('Gelöscht');
        load();
      });
      tsList.appendChild(row);
    });
  }

  async function load(){
    const j = await api('list');
    render(j.snippets || []);
  }

  document.getElementById('tsSave').addEventListener('click', async () => {
    const title = document.getElementById('tsTitle').value.trim();
    const cat = document.getElementById('tsCategory').value.trim();
    const content = document.getElementById('tsContent').value.trim();
    if (!title || !content) { alert('Titel und Inhalt erforderlich.'); return; }
    await api('save', { title, category: cat, content });
    document.getElementById('tsTitle').value = '';
    document.getElementById('tsContent').value = '';
    showStatus('Gespeichert');
    load();
  });

  document.getElementById('tsGenerate').addEventListener('click', async () => {
    await api('generate_base');
    showStatus('Grundstock generiert');
    load();
  });

  load();
})();
</script>

<?php render_admin_footer(); ?>
