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
    <h1>Textbausteine</h1>
</div>

<div class="card">
    <h2>Neuer Textbaustein</h2>
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
    <div style="display:flex; gap:8px; align-items:center; margin-top: 10px;">
      <a class="btn" type="button" id="tsSave">Speichern</a>
      <a class="btn secondary" type="button" id="tsGenerate">Grundstock generieren</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
    <div>
      <h2>Vorhandene Textbausteine</h2>
      <div class="muted">Rechtsklick auf Eingabefelder in entry.php fügt diese Bausteine als Kontextmenü hinzu.</div>
      <div class="muted" id="tsStatus" style="display:none;"></div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
      <div style="display:flex; gap:6px; align-items:center;">
        <input class="input" id="tsNewGroup" type="text" placeholder="Neue Kategorie" style="width:190px;">
        <button class="btn secondary" type="button" id="tsAddGroup">Gruppe anlegen</button>
      </div>
    </div>
  </div>
  <div id="tsList" style="margin-top:12px; display:flex; flex-direction:column; gap:12px;"></div>
</div>

<script>
(function(){
  const apiUrl = <?= json_encode(url('admin/ajax/text_snippets_api.php')) ?>;
  const csrf = <?= json_encode($csrf) ?>;
  const tsList = document.getElementById('tsList');
  const tsStatus = document.getElementById('tsStatus');
  const tsNewGroup = document.getElementById('tsNewGroup');
  const tsAddGroup = document.getElementById('tsAddGroup');

  const state = { snippets: [], customGroups: new Set() };

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

  function displayEmpty(){
    const empty = document.createElement('div');
    empty.className = 'muted';
    empty.textContent = 'Keine Textbausteine vorhanden.';
    tsList.appendChild(empty);
  }

  function categoryLabel(cat){
    return cat && cat.trim() !== '' ? cat : 'Ohne Kategorie';
  }

  function createSnippetRow(snippet){
    const row = document.createElement('div');
    row.className = 'del-row';
    row.style.cursor = 'grab';
    row.draggable = true;
    row.innerHTML = `
      <div class="l">
        <div class="t">${snippet.title ? snippet.title : '(ohne Titel)'}</div>
        <div class="s">${snippet.created_by_name || '—'}${snippet.is_generated ? ' · automatisch' : ''}</div>
        <div class="s" style="white-space:pre-wrap;">${snippet.content}</div>
      </div>
      <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <button class="btn secondary" type="button">Bearbeiten</button>
        <button class="btn danger" type="button">Löschen</button>
      </div>
    `;

    const [editBtn, deleteBtn] = row.querySelectorAll('button');

    row.addEventListener('dragstart', (e) => {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(snippet.id));
      row.classList.add('dragging');
    });
    row.addEventListener('dragend', () => row.classList.remove('dragging'));

    deleteBtn?.addEventListener('click', async () => {
      if (!confirm('Diesen Textbaustein wirklich löschen?')) return;
      await api('delete', { id: snippet.id });
      showStatus('Gelöscht');
      load();
    });

    editBtn?.addEventListener('click', () => {
      if (row.querySelector('.edit-panel')) return;
      const panel = document.createElement('div');
      panel.className = 'card';
      panel.classList.add('edit-panel');
      panel.style.marginTop = '8px';
      panel.innerHTML = `
        <div class="row" style="gap:8px; align-items:flex-end; flex-wrap:wrap;">
          <div style="flex:1; min-width:180px;">
            <label class="label">Titel</label>
            <input class="input" type="text" value="${snippet.title.replace(/"/g, '&quot;')}">
          </div>
          <div style="flex:1; min-width:180px;">
            <label class="label">Kategorie</label>
            <input class="input" type="text" value="${snippet.category ? snippet.category.replace(/"/g, '&quot;') : ''}">
          </div>
          <div style="flex:2; min-width:240px;">
            <label class="label">Inhalt</label>
            <textarea class="input" rows="3">${snippet.content}</textarea>
          </div>
          <div style="display:flex; gap:6px; align-items:center;">
            <button class="btn" type="button">Speichern</button>
            <button class="btn secondary" type="button">Abbrechen</button>
          </div>
        </div>
      `;
      const [titleInput, categoryInput, contentInput] = panel.querySelectorAll('input, textarea');
      const [saveEditBtn, cancelBtn] = panel.querySelectorAll('button');

      saveEditBtn.addEventListener('click', async () => {
        try {
          await api('update', { id: snippet.id, title: titleInput.value.trim(), category: categoryInput.value.trim(), content: contentInput.value.trim() });
          showStatus('Aktualisiert');
          load();
        } catch (e) {
          alert(e.message || 'Fehler beim Speichern.');
        }
      });
      cancelBtn.addEventListener('click', () => panel.remove());

      row.appendChild(panel);
    });

    return row;
  }

  function render(list){
    state.snippets = list;
    tsList.innerHTML = '';

    if (!list.length && state.customGroups.size === 0) {
      displayEmpty();
      return;
    }

    const grouped = new Map();
    list.forEach(s => {
      const key = s.category || '';
      if (!grouped.has(key)) grouped.set(key, []);
      grouped.get(key).push(s);
    });
    state.customGroups.forEach(cat => {
      if (!grouped.has(cat)) grouped.set(cat, []);
    });

    const categories = Array.from(grouped.keys()).sort((a, b) => {
      const an = categoryLabel(a).toLowerCase();
      const bn = categoryLabel(b).toLowerCase();
      return an.localeCompare(bn, 'de');
    });

    categories.forEach(cat => {
      const box = document.createElement('div');
      box.className = 'card';
      box.dataset.category = cat;
      box.style.border = '1px dashed var(--border)';
      box.innerHTML = `
        <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
          <div style="display:flex; gap:8px; align-items:center;">
            <div style="font-weight:800;">${categoryLabel(cat)}</div>
            <div class="pill-mini">${(grouped.get(cat) || []).length} Baustein(e)</div>
          </div>
          <div class="muted" style="font-size:12px;">Per Drag & Drop zwischen Gruppen verschieben</div>
        </div>
        <div class="drop-zone" style="margin-top:10px; display:flex; flex-direction:column; gap:8px;"></div>
      `;

      const zone = box.querySelector('.drop-zone');

      box.addEventListener('dragover', (e) => {
        e.preventDefault();
        box.style.background = 'rgba(0,0,0,0.02)';
      });
      box.addEventListener('dragleave', () => {
        box.style.background = '';
      });
      box.addEventListener('drop', async (e) => {
        e.preventDefault();
        box.style.background = '';
        const id = parseInt(e.dataTransfer.getData('text/plain') || '0', 10);
        if (!id) return;
        try {
          await api('move', { id, category: cat });
          showStatus('Gruppe geändert');
          load();
        } catch (err) {
          alert(err.message || 'Konnte Gruppe nicht ändern.');
        }
      });

      const snips = grouped.get(cat) || [];
      if (!snips.length) {
        const muted = document.createElement('div');
        muted.className = 'muted';
        muted.textContent = 'Noch keine Textbausteine in dieser Gruppe.';
        zone.appendChild(muted);
      } else {
        snips.forEach(s => zone.appendChild(createSnippetRow(s)));
      }

      tsList.appendChild(box);
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
    try {
      await api('save', { title, category: cat, content });
      document.getElementById('tsTitle').value = '';
      document.getElementById('tsContent').value = '';
      showStatus('Gespeichert');
      load();
    } catch (e) {
      alert(e.message || 'Konnte Textbaustein nicht speichern.');
    }
  });

  document.getElementById('tsGenerate').addEventListener('click', async () => {
    try {
      await api('generate_base');
      showStatus('Grundstock generiert');
      load();
    } catch (e) {
      alert(e.message || 'Konnte Grundstock nicht erstellen.');
    }
  });

  tsAddGroup.addEventListener('click', () => {
    const name = tsNewGroup.value.trim();
    if (!name) return;
    state.customGroups.add(name);
    tsNewGroup.value = '';
    render(state.snippets);
    showStatus('Gruppe angelegt');
  });

  load();
})();
</script>

<?php render_admin_footer(); ?>
