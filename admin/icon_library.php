<?php
// admin/icon_library.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

render_admin_header(t('admin.icon_library.title'));
?>

<style>
.tabs { display:flex; gap:8px; flex-wrap:wrap; }
.tabbtn {
  border:1px solid var(--border);
  background: var(--card, #fff);
  border-radius: 999px;
  padding: 8px 12px;
  cursor:pointer;
}
.tabbtn.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(0,0,0,0.04); }
.tabpanel { display:none; }
.tabpanel.active { display:block; }

.grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
@media (max-width: 900px) { .grid2 { grid-template-columns: 1fr; } }

.table-scroll{
  max-height: 62vh;
  overflow: auto;
  border: 1px solid var(--border);
  border-radius: 12px;
}
#itemsTbl{
  width:100%;
  min-width: 800px;
  border-collapse: separate;
  border-spacing:0;
}
#itemsTbl th, #itemsTbl td{
  border-bottom:1px solid var(--border);
  padding:10px;
  vertical-align: top;
}
#itemsTbl thead th{
  position: sticky;
  top: 0;
  z-index: 3;
  background: var(--card, #fff);
}

#itemsTbl th.col-sort, #itemsTbl td.col-sort { width: 90px; min-width: 90px; }
#itemsTbl th.col-ico,  #itemsTbl td.col-ico  { width: 220px; min-width: 220px; }
#itemsTbl input, #itemsTbl select { width:100%; box-sizing:border-box; }

.iconcard {
  padding:10px;
  margin:0;
}
.iconthumb {
  display:flex; align-items:center; justify-content:center; height:72px;
  border:1px dashed var(--border); border-radius: 12px;
}
.iconthumb img { max-width:64px; max-height:64px; }
</style>

<div class="card">
    <h1><?=h(t('admin.icon_library.heading'))?></h1>
</div>

<div class="card">
  <div class="tabs">
    <button class="tabbtn active" data-tab="lists"><?=h(t('admin.icon_library.tab_lists'))?></button>
    <button class="tabbtn" data-tab="icons"><?=h(t('admin.icon_library.tab_icons'))?></button>
  </div>
</div>

<div class="card">

  <div id="tab-icons" class="tabpanel">
    <h2 style="margin-top:0;"><?=h(t('admin.icon_library.icons_heading'))?></h2>
    <p class="muted"><?=h(t('admin.icon_library.icons_intro'))?></p>

    <div class="grid2" style="align-items:start;">
      <div class="card" style="margin:0;">
        <h3 style="margin-top:0;"><?=h(t('admin.icon_library.upload_heading'))?></h3>
        <form id="iconUploadForm" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="file" name="icon[]" accept=".png,.jpg,.jpeg,.webp,.svg" multiple required>
          <div class="actions" style="margin-top:12px;">
            <button class="btn primary" type="submit"><?=h(t('admin.icon_library.upload_button'))?></button>
          </div>
        </form>
        <div class="muted small" style="margin-top:10px;">
          <?=h(t('admin.icon_library.upload_hint'))?>
        </div>
        <div class="muted small" id="iconUploadMsg" style="margin-top:8px;"></div>
      </div>

      <div class="card" style="margin:0;">
        <div class="grid" style="grid-template-columns:1fr 160px; gap:12px; align-items:end;">
          <div>
            <label><?=h(t('admin.icon_library.filter_label'))?></label>
            <input id="iconFilter" placeholder="<?=h(t('admin.icon_library.icon_filter_placeholder'))?>">
          </div>
          <div class="actions" style="justify-content:flex-start;">
            <button class="btn secondary" id="btnReloadIcons" type="button"><?=h(t('admin.icon_library.reload_button'))?></button>
          </div>
        </div>

        <h3 style="margin:14px 0 8px;"><?=h(t('admin.icon_library.icons_list_heading'))?></h3>
        <div id="iconMsg" class="muted small" style="margin-bottom:8px;"></div>
        <div id="iconGrid" class="grid" style="grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap:12px;"></div>
      </div>
    </div>
  </div>

  <div id="tab-lists" class="tabpanel active">
    <h2 style="margin-top:0;"><?=h(t('admin.icon_library.lists_heading'))?></h2>
    <p class="muted"><?=h(t('admin.icon_library.lists_intro'))?></p>

    <div class="grid2">
      <div class="card" style="margin:0;">
        <h3 style="margin-top:0;"><?=h(t('admin.icon_library.templates_heading'))?></h3>

        <div class="grid" style="gap:12px; align-items:end;">
          <div>
            <label><?=h(t('admin.icon_library.new_template_label'))?></label>
            <input id="newListName" placeholder="<?=h(t('admin.icon_library.new_template_placeholder'))?>">
          </div>
          <div class="actions" style="justify-content:flex-start;">
            <button class="btn primary" id="btnCreateList" type="button"><?=h(t('admin.icon_library.create_button'))?></button>
          </div>
        </div>

        <div style="margin-top:12px;">
          <label><?=h(t('admin.icon_library.filter_label'))?></label>
          <input id="listFilter" placeholder="<?=h(t('admin.icon_library.list_filter_placeholder'))?>">
        </div>

        <div class="table-scroll" style="margin-top:10px;">
          <table style="width:100%; border-collapse:separate; border-spacing:0;">
            <thead>
              <tr>
                <th style="top:0; z-index:2; background:var(--card,#fff); padding:10px; border-bottom:1px solid var(--border);"><?=h(t('admin.icon_library.table_id'))?></th>
                <th style="top:0; z-index:2; background:var(--card,#fff); padding:10px; border-bottom:1px solid var(--border);"><?=h(t('admin.icon_library.table_name'))?></th>
              </tr>
            </thead>
            <tbody id="listsTbody"></tbody>
          </table>
        </div>

        <div class="muted small" style="margin-top:10px;" id="listMsg"></div>
      </div>

      <div class="card" style="margin:0;">
        <h3 style="margin-top:0;"><?=h(t('admin.icon_library.template_options_heading'))?></h3>

        <div id="noListSelected" class="muted"><?=h(t('admin.icon_library.template_select_hint'))?></div>

        <div id="listEditor" style="display:none;">
          <div class="grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
            <div>
              <label><?=h(t('admin.icon_library.template_name_label'))?></label>
              <input id="editListName">
            </div>
            <div>
              <label><?=h(t('admin.icon_library.template_description_label'))?></label>
              <input id="editListDesc" placeholder="<?=h(t('admin.icon_library.template_description_placeholder'))?>">
            </div>
          </div>

          <div class="actions" style="margin-top:12px; flex-wrap:wrap;">
            <button class="btn secondary" id="btnAddItem" type="button"><?=h(t('admin.icon_library.add_item_button'))?></button>
            <button class="btn primary" id="btnSaveList" type="button"><?=h(t('admin.icon_library.save_button'))?></button>
            <button class="btn secondary" id="btnDuplicateList" type="button"><?=h(t('admin.icon_library.duplicate_button'))?></button>
            <button class="btn secondary" id="btnDeleteList" type="button" style="border-color:#b00020; color:#b00020;"><?=h(t('admin.icon_library.delete_button'))?></button>
          </div>

          <div class="table-scroll" style="margin-top:10px;">
            <table id="itemsTbl">
              <thead>
                <tr>
                  <th class="col-sort"><?=h(t('admin.icon_library.col_sort'))?></th>
                  <th class="col-sort"><?=h(t('admin.icon_library.col_value'))?></th>
                  <th><?=h(t('admin.icon_library.col_label_de'))?></th>
                  <th><?=h(t('admin.icon_library.col_label_en'))?></th>
                <th class="col-ico"><?=h(t('admin.icon_library.col_icon'))?></th>
                <th style="width:150px; min-width:150px;"><?=h(t('admin.icon_library.col_lock_child'))?></th>
                <th style="width:90px; min-width:90px;"><?=h(t('admin.icon_library.col_action'))?></th>
                </tr>
              </thead>
              <tbody id="itemsTbody"></tbody>
            </table>
          </div>

          <div class="muted small" style="margin-top:10px;">
            <?=t('admin.icon_library.items_hint')?>
          </div>

          <div class="muted small" id="itemsMsg" style="margin-top:8px;"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  const csrf = <?=json_encode(csrf_token())?>;
  const I18N = <?=json_encode([
    'tab_lists' => t('admin.icon_library.tab_lists'),
    'tab_icons' => t('admin.icon_library.tab_icons'),
    'icons_empty' => t('admin.icon_library.icons_empty'),
    'icon_delete_button' => t('admin.icon_library.icon_delete_button'),
    'icon_delete_confirm' => t('admin.icon_library.icon_delete_confirm'),
    'icon_deleting' => t('admin.icon_library.icon_deleting'),
    'icon_deleted' => t('admin.icon_library.icon_deleted'),
    'error_prefix' => t('admin.icon_library.error_prefix'),
    'upload_select_files' => t('admin.icon_library.upload_select_files'),
    'upload_in_progress' => t('admin.icon_library.upload_in_progress'),
    'upload_ok_many' => t('admin.icon_library.upload_ok_many'),
    'upload_ok_single' => t('admin.icon_library.upload_ok_single'),
    'upload_file_fallback' => t('admin.icon_library.upload_file_fallback'),
    'templates_empty' => t('admin.icon_library.templates_empty'),
    'icon_none' => t('admin.icon_library.icon_none'),
    'icon_preview_label' => t('admin.icon_library.icon_preview_label'),
    'lock_label' => t('admin.icon_library.lock_label'),
    'remove_button' => t('admin.icon_library.remove_button'),
    'item_value_placeholder' => t('admin.icon_library.item_value_placeholder'),
    'item_label_de_placeholder' => t('admin.icon_library.item_label_de_placeholder'),
    'item_label_en_placeholder' => t('admin.icon_library.item_label_en_placeholder'),
    'list_empty_name' => t('admin.icon_library.list_empty_name'),
    'list_create_progress' => t('admin.icon_library.list_create_progress'),
    'list_create_ok' => t('admin.icon_library.list_create_ok'),
    'save_progress' => t('admin.icon_library.save_progress'),
    'save_missing_name' => t('admin.icon_library.save_missing_name'),
    'save_ok' => t('admin.icon_library.save_ok'),
    'save_ok_updated' => t('admin.icon_library.save_ok_updated'),
    'duplicate_progress' => t('admin.icon_library.duplicate_progress'),
    'duplicate_ok' => t('admin.icon_library.duplicate_ok'),
    'delete_confirm' => t('admin.icon_library.delete_confirm'),
    'delete_progress' => t('admin.icon_library.delete_progress'),
    'delete_ok' => t('admin.icon_library.delete_ok')
  ], JSON_UNESCAPED_UNICODE) ?>;
  const tIcon = (key) => I18N[key] ?? key;
  const tfmtIcon = (key, vars = {}) => {
    let base = tIcon(key);
    Object.entries(vars).forEach(([k, v]) => {
      base = base.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
    });
    return base;
  };

  // Tabs
  document.querySelectorAll('.tabbtn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tabbtn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tabpanel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
  });

  // ---------------- Icons (DB-backed)
  const iconGrid = document.getElementById('iconGrid');
  const iconMsg = document.getElementById('iconMsg');
  const iconFilter = document.getElementById('iconFilter');
  const btnReloadIcons = document.getElementById('btnReloadIcons');
  const iconUploadForm = document.getElementById('iconUploadForm');
  const iconUploadMsg = document.getElementById('iconUploadMsg');
  const iconFileInput = iconUploadForm.querySelector('input[type="file"]');

  let iconsCache = [];

  function renderIcons(){
    const ft = (iconFilter.value || '').trim().toLowerCase();
    const icons = !ft ? iconsCache : iconsCache.filter(ic =>
      String(ic.filename||'').toLowerCase().includes(ft) ||
      String(ic.storage_path||'').toLowerCase().includes(ft)
    );

    if (!icons.length){
      iconGrid.innerHTML = `<div class="muted">${tIcon('icons_empty')}</div>`;
      return;
    }

    iconGrid.innerHTML = icons.map(ic => `
      <div class="card iconcard">
        <div class="iconthumb">
          <img src="${ic.url}" alt="">
        </div>
        <div class="muted" style="font-size:12px; word-break:break-all; margin-top:8px;">#${ic.id} · ${escapeHtml(ic.filename)}</div>
        <div style="margin-top:6px;">
          <input readonly value="${escapeAttr(ic.storage_path)}" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:10px;">
        </div>
        <div class="actions" style="margin-top:10px; justify-content:flex-end;">
          <button class="btn secondary js-del-icon" data-id="${ic.id}" type="button" style="border-color:#b00020; color:#b00020;">${tIcon('icon_delete_button')}</button>
        </div>
      </div>
    `).join('');

    iconGrid.querySelectorAll('.js-del-icon').forEach(btn => {
      btn.addEventListener('click', () => deleteIcon(btn.dataset.id));
    });
  }

  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }

  async function loadIcons(){
    const resp = await fetch(<?=json_encode(url('admin/ajax/icons_api.php'))?>, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const j = await resp.json().catch(()=>({}));
    if (!resp.ok || !j.ok) throw new Error(j.error || ('HTTP '+resp.status));
    iconsCache = j.icons || [];
    renderIcons();
  }

  iconFilter.addEventListener('input', renderIcons);
  btnReloadIcons.addEventListener('click', loadIcons);

  function setIconMsg(txt, isError=false){
    iconMsg.textContent = txt || '';
    iconMsg.style.color = isError ? '#b00020' : '';
  }

  async function deleteIcon(id){
    if (!id) return;
    if (!confirm(tIcon('icon_delete_confirm'))) return;
    setIconMsg(tIcon('icon_deleting'));

    const resp = await fetch(<?=json_encode(url('admin/ajax/icon_delete.php'))?>, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ icon_id: Number(id) })
    });
    const j = await resp.json().catch(()=>({}));
    if (!resp.ok || !j.ok){
      setIconMsg(tIcon('error_prefix') + (j.error || ('HTTP ' + resp.status)), true);
      return;
    }
    setIconMsg(tIcon('icon_deleted'));
    await loadIcons();
  }

  iconUploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!iconFileInput.files || iconFileInput.files.length === 0) {
      iconUploadMsg.textContent = tIcon('upload_select_files');
      return;
    }
    iconUploadMsg.textContent = tIcon('upload_in_progress');

    const fd = new FormData(iconUploadForm);
    const resp = await fetch(<?=json_encode(url('admin/ajax/icon_upload.php'))?>, {
      method: 'POST',
      body: fd
    });
    const j = await resp.json().catch(()=>({}));
    if (!resp.ok || !j.ok){
      iconUploadMsg.textContent = tIcon('error_prefix') + (j.error || ('HTTP '+resp.status));
      return;
    }
    const uploaded = Array.isArray(j.uploaded) ? j.uploaded : [];
    if (uploaded.length > 0) {
      const names = uploaded.map(u => u.filename || tIcon('upload_file_fallback')).join(', ');
      iconUploadMsg.textContent = tfmtIcon('upload_ok_many', { count: String(uploaded.length), names });
    } else {
      iconUploadMsg.textContent = tfmtIcon('upload_ok_single', { name: j.filename || tIcon('upload_file_fallback') });
    }
    iconUploadForm.reset();
    await loadIcons();
  });

  // ---------------- Option Lists
  const listsTbody = document.getElementById('listsTbody');
  const listFilter = document.getElementById('listFilter');
  const newListName = document.getElementById('newListName');
  const btnCreateList = document.getElementById('btnCreateList');
  const listMsg = document.getElementById('listMsg');

  const noListSelected = document.getElementById('noListSelected');
  const listEditor = document.getElementById('listEditor');
  const editListName = document.getElementById('editListName');
  const editListDesc = document.getElementById('editListDesc');
  const btnAddItem = document.getElementById('btnAddItem');
  const btnSaveList = document.getElementById('btnSaveList');
  const btnDuplicateList = document.getElementById('btnDuplicateList');
  const btnDeleteList = document.getElementById('btnDeleteList');
  const itemsTbody = document.getElementById('itemsTbody');
  const itemsMsg = document.getElementById('itemsMsg');

  let listsCache = [];
  let activeListId = 0;
  let activeItems = []; // [{id?, sort_order, value,label,label_en,icon_id,lock_child}]
  let iconsForPicker = []; // from iconsCache

  function renderLists(){
    const ft = (listFilter.value || '').trim().toLowerCase();
    const arr = !ft ? listsCache : listsCache.filter(x => String(x.name||'').toLowerCase().includes(ft));

    if (!arr.length){
      listsTbody.innerHTML = `<tr><td colspan="2" class="muted" style="padding:10px;">${tIcon('templates_empty')}</td></tr>`;
      return;
    }

    listsTbody.innerHTML = arr.map(l => `
      <tr data-id="${l.id}" style="cursor:pointer; background:${Number(l.id)===Number(activeListId)?'rgba(0,0,0,0.03)':'transparent'};">
        <td style="padding:10px; border-bottom:1px solid var(--border);">${l.id}</td>
        <td style="padding:10px; border-bottom:1px solid var(--border);">${escapeHtml(l.name)}</td>
      </tr>
    `).join('');
  }

  function iconOptionsHtml(selectedId){
    const opts = [`<option value="">${tIcon('icon_none')}</option>`];
    (iconsForPicker || []).forEach(ic => {
      const sel = (String(ic.id) === String(selectedId)) ? 'selected' : '';
      opts.push(`<option value="${ic.id}" ${sel}>#${ic.id} · ${escapeHtml(ic.filename)}</option>`);
    });
    return opts.join('');
  }

  function renderItems(){
    itemsTbody.innerHTML = '';
    activeItems.forEach((it, idx) => {
      const tr = document.createElement('tr');

      tr.innerHTML = `
        <td class="col-sort"><input type="number" value="${Number(it.sort_order ?? idx)}" min="0" step="1"></td>
        <td><input type="text" value="${escapeAttr(it.value || '')}" placeholder="${tIcon('item_value_placeholder')}"></td>
        <td><input type="text" value="${escapeAttr(it.label || '')}" placeholder="${tIcon('item_label_de_placeholder')}"></td>
        <td><input type="text" value="${escapeAttr(it.label_en || '')}" placeholder="${tIcon('item_label_en_placeholder')}"></td>
        <td class="col-ico">
          <select>${iconOptionsHtml(it.icon_id || '')}</select>
          <div class="muted small" style="margin-top:6px; display:flex; gap:8px; align-items:center;">
            <span class="js-icon-preview"></span>
            <span class="muted small">${tIcon('icon_preview_label')}</span>
          </div>
        </td>
        <td style="text-align:center;">
          <label style="display:inline-flex; align-items:center; gap:6px;">
            <input type="checkbox" ${it.lock_child ? 'checked' : ''}>
            <span class="muted small">${tIcon('lock_label')}</span>
          </label>
        </td>
        <td>
          <button class="btn secondary js-del" type="button">${tIcon('remove_button')}</button>
        </td>
      `;

      // events
      const inpSort = tr.querySelector('td.col-sort input');
      const inpValue = tr.querySelectorAll('td input[type="text"]')[0];
      const inpLabel = tr.querySelectorAll('td input[type="text"]')[1];
      const inpLabelEn = tr.querySelectorAll('td input[type="text"]')[2];
      const selIcon = tr.querySelector('select');
      const lockToggle = tr.querySelector('input[type="checkbox"]');
      const delBtn = tr.querySelector('.js-del');
      const prevSpan = tr.querySelector('.js-icon-preview');

      function updatePreview(){
        const id = selIcon.value;
        const ic = (iconsForPicker||[]).find(x => String(x.id) === String(id));
        prevSpan.innerHTML = ic ? `<img src="${ic.url}" style="width:22px; height:22px; object-fit:contain; vertical-align:middle;">` : '';
      }
      updatePreview();

      inpSort.addEventListener('input', ()=> { activeItems[idx].sort_order = parseInt(inpSort.value || '0', 10); });
      inpValue.addEventListener('input', ()=> { activeItems[idx].value = inpValue.value; });
      inpLabel.addEventListener('input', ()=> { activeItems[idx].label = inpLabel.value; });
      inpLabelEn.addEventListener('input', ()=> { activeItems[idx].label_en = inpLabelEn.value; });
      selIcon.addEventListener('change', ()=> { activeItems[idx].icon_id = selIcon.value ? parseInt(selIcon.value,10) : null; updatePreview(); });
      lockToggle.addEventListener('change', ()=> { activeItems[idx].lock_child = lockToggle.checked; });

      delBtn.addEventListener('click', ()=> {
        activeItems.splice(idx, 1);
        renderItems();
      });

      itemsTbody.appendChild(tr);
    });
  }

  async function api(payload){
    const resp = await fetch(<?=json_encode(url('admin/ajax/option_lists_api.php'))?>, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload)
    });
    const j = await resp.json().catch(()=>({}));
    if (!resp.ok || !j.ok) throw new Error(j.error || ('HTTP '+resp.status));
    return j;
  }

  async function loadLists(){
    const j = await api({ action: 'list_templates' });
    listsCache = j.templates || [];
    renderLists();
  }

  async function selectList(id){
    activeListId = Number(id);
    renderLists();

    const j = await api({ action: 'get_template', list_id: activeListId });
    const t = j.template;
    activeItems = (j.items || []).map(x => ({
      id: x.id,
      sort_order: x.sort_order,
      value: x.value,
      label: x.label,
      label_en: x.label_en, // NEW
      icon_id: x.icon_id,
      lock_child: (() => {
        try {
          const meta = x.meta_json ? JSON.parse(x.meta_json) : null;
          return !!(meta && meta.lock_child);
        } catch(e) {
          return false;
        }
      })()
    }));

    noListSelected.style.display = 'none';
    listEditor.style.display = 'block';
    editListName.value = t.name || '';
    editListDesc.value = t.description || '';
    itemsMsg.textContent = '';
    renderItems();
  }

  listsTbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    selectList(tr.dataset.id);
  });

  listFilter.addEventListener('input', renderLists);

  btnCreateList.addEventListener('click', async () => {
    const name = (newListName.value || '').trim();
    if (!name){ listMsg.textContent = tIcon('list_empty_name'); return; }
    try{
      listMsg.textContent = tIcon('list_create_progress');
      const j = await api({ action:'create_template', name });
      newListName.value = '';
      listMsg.textContent = tfmtIcon('list_create_ok', { id: String(j.list_id) });
      await loadLists();
      await selectList(j.list_id);
    } catch(err){
      listMsg.textContent = tIcon('error_prefix') + (err && err.message ? err.message : err);
    }
  });

  btnAddItem.addEventListener('click', () => {
    activeItems.push({ sort_order: activeItems.length, value:'', label:'', label_en:'', icon_id:null, lock_child:false }); // NEW
    renderItems();
  });

  btnSaveList.addEventListener('click', async () => {
    try{
      itemsMsg.textContent = tIcon('save_progress');
      const name = (editListName.value || '').trim();
      if (!name) throw new Error(tIcon('save_missing_name'));

      // Clean items: require value + label (DE); EN optional
      const cleaned = activeItems
        .map((x, idx) => ({
          id: x.id || null,
          sort_order: Number.isFinite(Number(x.sort_order)) ? Number(x.sort_order) : idx,
          value: String(x.value || '').trim(),
          label: String(x.label || '').trim(),
          label_en: String(x.label_en || '').trim(), // NEW
          icon_id: x.icon_id ? Number(x.icon_id) : null,
          lock_child: x.lock_child ? 1 : 0
        }))
        .filter(x => x.value !== '' && x.label !== '');

      const j = await api({
        action: 'save_template',
        list_id: activeListId,
        name,
        description: (editListDesc.value || '').trim(),
        items: cleaned
      });

      const upd = (j && typeof j.template_fields_updated !== 'undefined') ? Number(j.template_fields_updated) : null;
      itemsMsg.textContent = upd === null
        ? tIcon('save_ok')
        : tfmtIcon('save_ok_updated', { count: String(upd) });
      await loadLists();
      await selectList(activeListId);
    } catch(err){
      itemsMsg.textContent = tIcon('error_prefix') + (err && err.message ? err.message : err);
    }
  });

  btnDuplicateList.addEventListener('click', async () => {
    try{
      itemsMsg.textContent = tIcon('duplicate_progress');
      const j = await api({ action: 'duplicate_template', list_id: activeListId });
      itemsMsg.textContent = tfmtIcon('duplicate_ok', { id: String(j.new_list_id) });
      await loadLists();
      await selectList(j.new_list_id);
    } catch(err){
      itemsMsg.textContent = tIcon('error_prefix') + (err && err.message ? err.message : err);
    }
  });

  btnDeleteList.addEventListener('click', async () => {
    if (!activeListId) return;
    if (!confirm(tIcon('delete_confirm'))) return;
    try{
      itemsMsg.textContent = tIcon('delete_progress');
      await api({ action:'delete_template', list_id: activeListId });
      itemsMsg.textContent = tIcon('delete_ok');
      activeListId = 0;
      activeItems = [];
      listEditor.style.display = 'none';
      noListSelected.style.display = 'block';
      await loadLists();
    } catch(err){
      itemsMsg.textContent = tIcon('error_prefix') + (err && err.message ? err.message : err);
    }
  });

  // init
  (async function init(){
    await loadIcons();            // iconsCache
    iconsForPicker = iconsCache;  // for selects
    await loadLists();
  })();
})();
</script>

<?php render_admin_footer(); ?>
