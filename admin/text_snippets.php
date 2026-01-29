<?php
// admin/text_snippets.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../shared/text_snippets.php';
require_admin();

$csrf = csrf_token();
render_admin_header(t('admin.text_snippets.title'));
?>

<div class="card">
    <h1><?=h(t('admin.text_snippets.title'))?></h1>
</div>

<div class="card">
    <h2><?=h(t('admin.text_snippets.new_heading'))?></h2>
  <div class="row" style="align-items:flex-end; gap:10px; flex-wrap:wrap;">
    <div style="flex:1; min-width:220px;">
      <label class="label"><?=h(t('admin.text_snippets.title_label'))?></label>
      <input class="input" id="tsTitle" type="text" placeholder="<?=h(t('admin.text_snippets.title_placeholder'))?>" style="width:100%;">
    </div>
    <div style="flex:1; min-width:200px;">
      <label class="label"><?=h(t('admin.text_snippets.category_label'))?></label>
      <input class="input" id="tsCategory" type="text" placeholder="<?=h(t('admin.text_snippets.category_placeholder'))?>" style="width:100%;">
    </div>
    <div style="flex:2; min-width:260px;">
      <label class="label"><?=h(t('admin.text_snippets.content_label'))?></label>
      <textarea class="input" id="tsContent" rows="3" placeholder="<?=h(t('admin.text_snippets.content_placeholder'))?>" style="width:100%;"></textarea>
    </div>
    <div style="display:flex; gap:8px; align-items:center; margin-top: 10px;">
      <a class="btn" type="button" id="tsSave"><?=h(t('admin.text_snippets.save_button'))?></a>
      <a class="btn secondary" type="button" id="tsGenerate"><?=h(t('admin.text_snippets.generate_button'))?></a>
    </div>
  </div>
</div>

<div class="card">
  <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
    <div>
      <h2><?=h(t('admin.text_snippets.list_heading'))?></h2>
      <div class="muted"><?=h(t('admin.text_snippets.list_hint'))?></div>
      <div class="muted" id="tsStatus" style="display:none;"></div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
      <div style="display:flex; gap:6px; align-items:center;">
        <input class="input" id="tsNewGroup" type="text" placeholder="<?=h(t('admin.text_snippets.new_group_placeholder'))?>" style="width:190px;">
        <button class="btn secondary" type="button" id="tsAddGroup"><?=h(t('admin.text_snippets.new_group_button'))?></button>
      </div>
    </div>
  </div>
  <div id="tsList" style="margin-top:12px; display:flex; flex-direction:column; gap:12px;"></div>
</div>

<script>
(function(){
  const apiUrl = <?= json_encode(url('admin/ajax/text_snippets_api.php')) ?>;
  const csrf = <?= json_encode($csrf) ?>;
  const I18N = <?= json_encode([
    'error_generic' => t('admin.text_snippets.error_generic'),
    'empty_list' => t('admin.text_snippets.empty_list'),
    'no_category' => t('admin.text_snippets.no_category'),
    'untitled' => t('admin.text_snippets.untitled'),
    'created_by_fallback' => t('admin.text_snippets.created_by_fallback'),
    'generated_badge' => t('admin.text_snippets.generated_badge'),
    'edit_button' => t('admin.text_snippets.edit_button'),
    'delete_button' => t('admin.text_snippets.delete_button'),
    'delete_confirm' => t('admin.text_snippets.delete_confirm'),
    'status_deleted' => t('admin.text_snippets.status_deleted'),
    'edit_title_label' => t('admin.text_snippets.edit_title_label'),
    'edit_category_label' => t('admin.text_snippets.edit_category_label'),
    'edit_content_label' => t('admin.text_snippets.edit_content_label'),
    'edit_save_button' => t('admin.text_snippets.edit_save_button'),
    'edit_cancel_button' => t('admin.text_snippets.edit_cancel_button'),
    'status_updated' => t('admin.text_snippets.status_updated'),
    'save_error' => t('admin.text_snippets.save_error'),
    'status_group_changed' => t('admin.text_snippets.status_group_changed'),
    'group_change_failed' => t('admin.text_snippets.group_change_failed'),
    'empty_group' => t('admin.text_snippets.empty_group'),
    'pill_count' => t('admin.text_snippets.pill_count'),
    'drag_hint' => t('admin.text_snippets.drag_hint'),
    'required_fields' => t('admin.text_snippets.required_fields'),
    'status_saved' => t('admin.text_snippets.status_saved'),
    'save_failed' => t('admin.text_snippets.save_failed'),
    'status_generated' => t('admin.text_snippets.status_generated'),
    'generate_failed' => t('admin.text_snippets.generate_failed'),
    'status_group_created' => t('admin.text_snippets.status_group_created')
  ], JSON_UNESCAPED_UNICODE) ?>;
  const tSnippets = (key) => I18N[key] ?? key;
  const tfmtSnippets = (key, vars = {}) => {
    let base = tSnippets(key);
    Object.entries(vars).forEach(([k, v]) => {
      base = base.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
    });
    return base;
  };
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
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : tSnippets('error_generic'));
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
    empty.textContent = tSnippets('empty_list');
    tsList.appendChild(empty);
  }

  function categoryLabel(cat){
    return cat && cat.trim() !== '' ? cat : tSnippets('no_category');
  }

  function createSnippetRow(snippet){
    const row = document.createElement('div');
    row.className = 'del-row';
    row.style.cursor = 'grab';
    row.draggable = true;
    row.innerHTML = `
      <div class="l">
        <div class="t">${snippet.title ? snippet.title : tSnippets('untitled')}</div>
        <div class="s">${snippet.created_by_name || tSnippets('created_by_fallback')}${snippet.is_generated ? ' · ' + tSnippets('generated_badge') : ''}</div>
        <div class="s" style="white-space:pre-wrap;">${snippet.content}</div>
      </div>
      <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <button class="btn secondary" type="button">${tSnippets('edit_button')}</button>
        <button class="btn danger" type="button">${tSnippets('delete_button')}</button>
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
      if (!confirm(tSnippets('delete_confirm'))) return;
      await api('delete', { id: snippet.id });
      showStatus(tSnippets('status_deleted'));
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
            <label class="label">${tSnippets('edit_title_label')}</label>
            <input class="input" type="text" value="${snippet.title.replace(/"/g, '&quot;')}">
          </div>
          <div style="flex:1; min-width:180px;">
            <label class="label">${tSnippets('edit_category_label')}</label>
            <input class="input" type="text" value="${snippet.category ? snippet.category.replace(/"/g, '&quot;') : ''}">
          </div>
          <div style="flex:2; min-width:240px;">
            <label class="label">${tSnippets('edit_content_label')}</label>
            <textarea class="input" rows="3">${snippet.content}</textarea>
          </div>
          <div style="display:flex; gap:6px; align-items:center;">
            <button class="btn" type="button">${tSnippets('edit_save_button')}</button>
            <button class="btn secondary" type="button">${tSnippets('edit_cancel_button')}</button>
          </div>
        </div>
      `;
      const [titleInput, categoryInput, contentInput] = panel.querySelectorAll('input, textarea');
      const [saveEditBtn, cancelBtn] = panel.querySelectorAll('button');

      saveEditBtn.addEventListener('click', async () => {
        try {
          await api('update', { id: snippet.id, title: titleInput.value.trim(), category: categoryInput.value.trim(), content: contentInput.value.trim() });
          showStatus(tSnippets('status_updated'));
          load();
        } catch (e) {
          alert(e.message || tSnippets('save_error'));
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
            <div class="pill-mini">${tfmtSnippets('pill_count', { count: String((grouped.get(cat) || []).length) })}</div>
          </div>
          <div class="muted" style="font-size:12px;">${tSnippets('drag_hint')}</div>
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
          showStatus(tSnippets('status_group_changed'));
          load();
        } catch (err) {
          alert(err.message || tSnippets('group_change_failed'));
        }
      });

      const snips = grouped.get(cat) || [];
      if (!snips.length) {
        const muted = document.createElement('div');
        muted.className = 'muted';
        muted.textContent = tSnippets('empty_group');
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
    if (!title || !content) { alert(tSnippets('required_fields')); return; }
    try {
      await api('save', { title, category: cat, content });
      document.getElementById('tsTitle').value = '';
      document.getElementById('tsContent').value = '';
      showStatus(tSnippets('status_saved'));
      load();
    } catch (e) {
      alert(e.message || tSnippets('save_failed'));
    }
  });

  document.getElementById('tsGenerate').addEventListener('click', async () => {
    try {
      await api('generate_base');
      showStatus(tSnippets('status_generated'));
      load();
    } catch (e) {
      alert(e.message || tSnippets('generate_failed'));
    }
  });

  tsAddGroup.addEventListener('click', () => {
    const name = tsNewGroup.value.trim();
    if (!name) return;
    state.customGroups.add(name);
    tsNewGroup.value = '';
    render(state.snippets);
    showStatus(tSnippets('status_group_created'));
  });

  load();
})();
</script>

<?php render_admin_footer(); ?>
