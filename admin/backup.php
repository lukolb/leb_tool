<?php
// admin/backup.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pageTitle = t('admin.backup.title');
$csrf = csrf_token();
$backupApiUrl = url('admin/ajax/backup_api.php');

render_admin_header($pageTitle);
?>

<style>
.spin{ display:inline-block; animation:spin 1s linear infinite; }
@keyframes spin{ from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
</style>

<div class="card" style="margin-bottom:14px;">
  <h1><?=h(t('admin.backup.title'))?></h1>
  <p class="muted"><?=h(t('admin.backup.intro'))?></p>
</div>

<div class="card" style="margin-bottom:14px;">
  <h2 style="margin-top:0;"><?=h(t('admin.backup.export_heading'))?></h2>
  <div class="grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
    <div>
      <strong><?=h(t('admin.backup.export_database'))?></strong>
      <div id="exportTables" class="muted" style="margin-top:6px;"><?=h(t('admin.backup.tables_loading'))?></div>
    </div>
    <div>
      <strong><?=h(t('admin.backup.export_extra'))?></strong>
      <div style="margin-top:8px;">
        <label class="row" style="gap:8px;">
          <input type="checkbox" id="exportSettings" checked>
          <?=h(t('admin.backup.export_settings'))?>
        </label>
        <label class="row" style="gap:8px; margin-top:6px;">
          <input type="checkbox" id="exportUploads" checked>
          <?=h(t('admin.backup.export_uploads'))?>
        </label>
      </div>
    </div>
  </div>
  <div class="row" style="justify-content:flex-end; margin-top:16px;">
    <button class="btn primary" id="btnExport" type="button"><?=h(t('admin.backup.export_button'))?></button>
  </div>
  <div id="exportStatus" class="muted" style="margin-top:10px;"><?=h(t('admin.backup.status_ready'))?></div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.backup.import_heading'))?></h2>
  <form id="importForm" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
    <div class="grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
      <div>
        <label for="importFile"><strong><?=h(t('admin.backup.import_zip_label'))?></strong></label>
        <input id="importFile" class="input" type="file" name="backup_file" accept=".zip" required>
        <div class="muted" style="margin-top:6px;"><?=h(t('admin.backup.import_zip_hint'))?></div>
        <div id="importAnalysisStatus" class="muted" style="margin-top:10px;"><?=h(t('admin.backup.import_no_file'))?></div>
        <div id="importAnalysisProgress" style="display:none; margin-top:10px;">
          <div class="progress-wrap">
            <div class="progress-meta"><span><span class="spin">⚙️</span> <span id="importAnalysisLabel"><?=h(t('admin.backup.analysis_running'))?></span></span><span class="muted" id="importAnalysisPct">0%</span></div>
            <div class="progress"><div class="progress-bar" id="importAnalysisBar" style="width:0%;"></div></div>
          </div>
        </div>
      </div>
      <div>
        <strong><?=h(t('admin.backup.analysis_heading'))?></strong>
        <div id="importAnalysisSummary" class="muted" style="margin-top:8px;"><?=h(t('admin.backup.analysis_select_file'))?></div>
        <div id="importAnalysisCompare" style="margin-top:8px;"></div>
      </div>
    </div>

    <div id="importConfirmWrap" style="display:none; margin-top:14px;">
      <label class="row" style="gap:8px;">
        <input type="checkbox" id="importConfirm">
        <?=h(t('admin.backup.confirm_overwrite'))?>
      </label>
    </div>

    <div id="importOptions" style="display:none; margin-top:14px;">
      <div>
        <strong><?=h(t('admin.backup.import_tables'))?></strong>
        <div id="importTables" class="muted" style="margin-top:6px;"><?=h(t('admin.backup.tables_loading'))?></div>
      </div>

      <div style="margin-top:12px;">
        <strong><?=h(t('admin.backup.import_data'))?></strong>
        <div style="margin-top:8px;">
          <label class="row" style="gap:8px;">
            <input type="checkbox" id="importSettings" name="import_settings" checked>
            <?=h(t('admin.backup.import_settings'))?>
          </label>
          <div id="importSettingsOptions" class="muted" style="margin:6px 0 0 24px;"></div>
          <div class="muted" style="margin:6px 0 0 24px;"><?=h(t('admin.backup.import_settings_hint'))?></div>
          <label class="row" style="gap:8px; margin-top:10px;">
            <input type="checkbox" id="importUploads" name="import_uploads" checked>
            <?=h(t('admin.backup.import_uploads'))?>
          </label>
          <div id="importUploadsOptions" class="muted" style="margin:6px 0 0 24px;"></div>
          <label class="row" style="gap:8px; margin-top:6px;">
            <input type="checkbox" id="importReplace" name="import_replace" checked>
            <?=h(t('admin.backup.import_replace'))?>
          </label>
          <div id="importConflictMode" style="margin:6px 0 0 24px; display:none;">
            <div class="muted"><?=h(t('admin.backup.conflict_intro'))?></div>
            <label class="row" style="gap:8px; margin-top:6px;">
              <input type="radio" name="conflict_mode" value="skip" checked>
              <?=h(t('admin.backup.conflict_keep'))?>
            </label>
            <label class="row" style="gap:8px; margin-top:6px;">
              <input type="radio" name="conflict_mode" value="overwrite">
              <?=h(t('admin.backup.conflict_overwrite'))?>
            </label>
          </div>
        </div>
      </div>

      <div class="row" style="justify-content:flex-end; margin-top:16px;">
        <button class="btn primary" id="btnImport" type="submit"><?=h(t('admin.backup.import_button'))?></button>
      </div>
    </div>
    <div id="importProgress" style="display:none; margin-top:10px;">
      <div class="progress-wrap">
        <div class="progress-meta"><span><span class="spin">⚙️</span> <span id="importLabel"><?=h(t('admin.backup.import_running'))?></span></span><span class="muted" id="importPct">0%</span></div>
        <div class="progress"><div class="progress-bar" id="importBar" style="width:0%;"></div></div>
      </div>
    </div>
    <div id="importStatus" class="muted" style="margin-top:10px;"><?=h(t('admin.backup.status_ready'))?></div>
  </form>
</div>

<script>
const backupApiUrl = <?= json_encode($backupApiUrl) ?>;
const csrfToken = <?= json_encode($csrf) ?>;
const I18N = <?=json_encode([
  'tables_none' => t('admin.backup.tables_none'),
  'tables_load_failed' => t('admin.backup.tables_load_failed'),
  'export_select_option' => t('admin.backup.export_select_option'),
  'export_creating' => t('admin.backup.export_creating'),
  'export_failed' => t('admin.backup.export_failed'),
  'export_done' => t('admin.backup.export_done'),
  'download_fallback' => t('admin.backup.download_fallback'),
  'analysis_select_file' => t('admin.backup.analysis_select_file'),
  'analysis_no_file' => t('admin.backup.import_no_file'),
  'analysis_failed' => t('admin.backup.analysis_failed'),
  'analysis_failed_prefix' => t('admin.backup.error_prefix'),
  'analysis_running' => t('admin.backup.analysis_running'),
  'analysis_preparing' => t('admin.backup.analysis_preparing'),
  'settings_after_analysis' => t('admin.backup.settings_after_analysis'),
  'uploads_after_analysis' => t('admin.backup.uploads_after_analysis'),
  'compare_table' => t('admin.backup.compare_table'),
  'compare_backup' => t('admin.backup.compare_backup'),
  'compare_current' => t('admin.backup.compare_current'),
  'compare_backup_date' => t('admin.backup.compare_backup_date'),
  'compare_current_date' => t('admin.backup.compare_current_date'),
  'compare_created' => t('admin.backup.compare_created'),
  'compare_tables' => t('admin.backup.compare_tables'),
  'compare_settings' => t('admin.backup.compare_settings'),
  'compare_uploads' => t('admin.backup.compare_uploads'),
  'compare_same' => t('admin.backup.compare_same'),
  'compare_diff' => t('admin.backup.compare_diff'),
  'compare_unknown' => t('admin.backup.compare_unknown'),
  'compare_analyzed' => t('admin.backup.compare_analyzed'),
  'compare_ok' => t('admin.backup.compare_ok'),
  'compare_diff_status' => t('admin.backup.compare_diff_status'),
  'settings_none' => t('admin.backup.settings_none'),
  'uploads_none' => t('admin.backup.uploads_none'),
  'import_not_required' => t('admin.backup.import_not_required'),
  'import_confirm_needed' => t('admin.backup.import_confirm_needed'),
  'import_status_ready' => t('admin.backup.status_ready'),
  'import_status_confirm' => t('admin.backup.import_confirm_first'),
  'import_select_option' => t('admin.backup.import_select_option'),
  'import_select_settings' => t('admin.backup.import_select_settings'),
  'import_select_uploads' => t('admin.backup.import_select_uploads'),
  'import_select_conflict' => t('admin.backup.import_select_conflict'),
  'import_starting' => t('admin.backup.import_starting'),
  'import_preparing' => t('admin.backup.import_preparing'),
  'import_failed' => t('admin.backup.import_failed'),
  'import_done' => t('admin.backup.import_done'),
  'error_prefix' => t('admin.backup.error_prefix')
], JSON_UNESCAPED_UNICODE)?>;
const tBackup = (key) => I18N[key] ?? key;
const tfmtBackup = (key, vars = {}) => {
  let base = tBackup(key);
  Object.entries(vars).forEach(([k, v]) => {
    base = base.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
  });
  return base;
};

const exportTables = document.getElementById('exportTables');
const importTables = document.getElementById('importTables');
const exportStatus = document.getElementById('exportStatus');
const importStatus = document.getElementById('importStatus');
const importAnalysisStatus = document.getElementById('importAnalysisStatus');
const importAnalysisSummary = document.getElementById('importAnalysisSummary');
const importAnalysisCompare = document.getElementById('importAnalysisCompare');
const importConfirmWrap = document.getElementById('importConfirmWrap');
const importConfirm = document.getElementById('importConfirm');
const importOptions = document.getElementById('importOptions');
const importFile = document.getElementById('importFile');
const importAnalysisProgress = document.getElementById('importAnalysisProgress');
const importAnalysisPct = document.getElementById('importAnalysisPct');
const importAnalysisBar = document.getElementById('importAnalysisBar');
const importAnalysisLabel = document.getElementById('importAnalysisLabel');
const importSettingsOptions = document.getElementById('importSettingsOptions');
const importUploadsOptions = document.getElementById('importUploadsOptions');
const importSettings = document.getElementById('importSettings');
const importUploads = document.getElementById('importUploads');
const importProgress = document.getElementById('importProgress');
const importPct = document.getElementById('importPct');
const importBar = document.getElementById('importBar');
const importLabel = document.getElementById('importLabel');
let analyzeToken = null;
let analyzeCompare = [];
let importToken = null;

function renderTableList(tables, target, prefix){
  if (!tables.length) {
    target.textContent = tBackup('tables_none');
    return;
  }
  const wrap = document.createElement('div');
  wrap.className = 'grid';
  wrap.style.gridTemplateColumns = 'repeat(auto-fit, minmax(180px, 1fr))';
  wrap.style.gap = '6px';
  tables.forEach((tbl) => {
    const id = `${prefix}_${tbl}`;
    const label = document.createElement('label');
    label.className = 'row';
    label.style.gap = '8px';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.value = tbl;
    input.checked = true;
    input.dataset.table = tbl;
    input.id = id;
    label.appendChild(input);
    const span = document.createElement('span');
    span.textContent = tbl;
    label.appendChild(span);
    wrap.appendChild(label);
  });
  target.innerHTML = '';
  target.appendChild(wrap);
}

async function loadTables(){
  try {
    const resp = await fetch(backupApiUrl + '?action=list_tables', { credentials: 'same-origin' });
    const data = await resp.json();
    const tables = Array.isArray(data.tables) ? data.tables : [];
    renderTableList(tables, exportTables, 'export');
    renderTableList(tables, importTables, 'import');
  } catch (e) {
    exportTables.textContent = tBackup('tables_load_failed');
    importTables.textContent = tBackup('tables_load_failed');
  }
}

function selectedTables(target){
  return Array.from(target.querySelectorAll('input[type="checkbox"][data-table]'))
    .filter((el) => el.checked)
    .map((el) => el.value);
}

document.getElementById('btnExport').addEventListener('click', async () => {
  const tables = selectedTables(exportTables);
  const includeSettings = document.getElementById('exportSettings').checked;
  const includeUploads = document.getElementById('exportUploads').checked;
  if (!tables.length && !includeSettings && !includeUploads) {
    exportStatus.textContent = tBackup('export_select_option');
    return;
  }
  exportStatus.textContent = tBackup('export_creating');

  const formData = new FormData();
  formData.append('action', 'export');
  formData.append('csrf_token', csrfToken);
  tables.forEach((tbl) => formData.append('tables[]', tbl));
  if (includeSettings) formData.append('include_settings', '1');
  if (includeUploads) formData.append('include_uploads', '1');

  try {
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.error || tBackup('export_failed'));
    }
    const blob = await resp.blob();
    const filename = resp.headers.get('Content-Disposition')?.match(/filename=\"?([^"]+)\"?/i)?.[1] || tBackup('download_fallback');
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    exportStatus.textContent = tBackup('export_done');
  } catch (e) {
    exportStatus.textContent = `${tBackup('error_prefix')}${e.message}`;
  }
});

function resetImportFlow(message){
  importAnalysisSummary.textContent = message || tBackup('analysis_select_file');
  importAnalysisCompare.innerHTML = '';
  importConfirmWrap.style.display = 'none';
  importConfirm.checked = false;
  importOptions.style.display = 'none';
  importAnalysisProgress.style.display = 'none';
  importAnalysisPct.textContent = '0%';
  importAnalysisBar.style.width = '0%';
  analyzeToken = null;
  analyzeCompare = [];
  importSettingsOptions.textContent = tBackup('settings_after_analysis');
  importUploadsOptions.textContent = tBackup('uploads_after_analysis');
  importSettings.disabled = false;
  importUploads.disabled = false;
  importSettings.checked = true;
  importUploads.checked = true;
}

function renderCompareTable(entries){
  if (!entries.length) return '';
  const rows = entries.map((row) => {
    const status = row.same ? '✅' : '⚠️';
    const backupDate = formatLocalDate(row.backup_latest_local || row.backup_latest) || '–';
    const currentDate = formatLocalDate(row.current_latest_local || row.current_latest) || '–';
    return `
      <tr>
        <td>${status}</td>
        <td>${row.table}</td>
        <td>${row.backup_count}</td>
        <td>${row.current_count}</td>
        <td>${backupDate}</td>
        <td>${currentDate}</td>
      </tr>
    `;
  }).join('');
  return `
    <div style="overflow:auto;">
      <table class="table" style="min-width:560px;">
        <thead>
          <tr>
            <th></th>
            <th>${tBackup('compare_table')}</th>
            <th>${tBackup('compare_backup')}</th>
            <th>${tBackup('compare_current')}</th>
            <th>${tBackup('compare_backup_date')}</th>
            <th>${tBackup('compare_current_date')}</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function renderSettingsOptions(){
  const opts = [
    { key: 'app', label: tBackup('settings_branding') },
    { key: 'mail', label: tBackup('settings_mail') },
    { key: 'ai', label: tBackup('settings_ai') },
    { key: 'student', label: tBackup('settings_student') },
    { key: 'parent', label: tBackup('settings_parent') },
    { key: 'signature', label: tBackup('settings_signature') },
  ];
  const wrap = document.createElement('div');
  wrap.className = 'grid';
  wrap.style.gridTemplateColumns = 'repeat(auto-fit, minmax(180px, 1fr))';
  wrap.style.gap = '6px';
  opts.forEach((opt) => {
    const label = document.createElement('label');
    label.className = 'row';
    label.style.gap = '8px';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.name = 'selected_settings[]';
    input.value = opt.key;
    input.checked = true;
    if (!importSettings.checked) input.disabled = true;
    label.appendChild(input);
    const span = document.createElement('span');
    span.textContent = opt.label;
    label.appendChild(span);
    wrap.appendChild(label);
  });
  importSettingsOptions.innerHTML = '';
  importSettingsOptions.appendChild(wrap);
  importSettingsOptions.style.display = importSettings.checked ? '' : 'none';
}

function renderUploadsOptions(items){
  if (!Array.isArray(items) || !items.length) {
    importUploadsOptions.textContent = tBackup('uploads_none');
    return;
  }
  const wrap = document.createElement('div');
  wrap.className = 'grid';
  wrap.style.gridTemplateColumns = 'repeat(auto-fit, minmax(180px, 1fr))';
  wrap.style.gap = '6px';
  items.forEach((it) => {
    const label = document.createElement('label');
    label.className = 'row';
    label.style.gap = '8px';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.name = 'selected_uploads[]';
    input.value = it.key;
    input.checked = true;
    if (!importUploads.checked) input.disabled = true;
    label.appendChild(input);
    const span = document.createElement('span');
    const backupCount = typeof it.backup_count === 'number' ? it.backup_count : '–';
    const currentCount = typeof it.current_count === 'number' ? it.current_count : '–';
    span.textContent = tfmtBackup('uploads_compare', { label: it.label, backup: backupCount, current: currentCount });
    label.appendChild(span);
    wrap.appendChild(label);
  });
  importUploadsOptions.innerHTML = '';
  importUploadsOptions.appendChild(wrap);
  importUploadsOptions.style.display = importUploads.checked ? '' : 'none';
}

function parseDateValue(value){
  if (!value) return null;
  if (value instanceof Date) return value;
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
      return new Date(`${trimmed}T00:00:00Z`);
    }
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(trimmed)) {
      return new Date(trimmed.replace(' ', 'T') + 'Z');
    }
    if (/^\d{4}-\d{2}-\d{2}T/.test(trimmed)) {
      return new Date(trimmed);
    }
  }
  return new Date(value);
}

function formatLocalDate(value){
  if (!value) return '';
  const date = parseDateValue(value);
  if (!date || Number.isNaN(date.getTime())) return value;
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  } catch (e) {
    return date.toLocaleString();
  }
}

function updateAnalyzeProgress(pct){
  const value = Math.max(0, Math.min(100, pct));
  importAnalysisPct.textContent = `${value}%`;
  importAnalysisBar.style.width = `${value}%`;
}

function updateAnalyzeLabel(text){
  if (importAnalysisLabel) importAnalysisLabel.textContent = text;
}

function updateImportProgress(pct){
  const value = Math.max(0, Math.min(100, pct));
  importPct.textContent = `${value}%`;
  importBar.style.width = `${value}%`;
}

function updateImportLabel(text){
  if (importLabel) importLabel.textContent = text;
}

async function analyzeBackup(file){
  importAnalysisStatus.textContent = tBackup('analysis_running');
  importAnalysisSummary.textContent = tBackup('analysis_running');
  updateAnalyzeLabel(tBackup('analysis_preparing'));
  importAnalysisCompare.innerHTML = '';
  importConfirmWrap.style.display = 'none';
  importOptions.style.display = 'none';
  importConfirm.checked = false;
  importAnalysisProgress.style.display = '';
  updateAnalyzeProgress(0);
  analyzeCompare = [];

  const formData = new FormData();
  formData.append('action', 'analyze_start');
  formData.append('csrf_token', csrfToken);
  formData.append('backup_file', file);

  try {
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || tBackup('analysis_failed'));
    analyzeToken = data.token;
    updateAnalyzeProgress(0);
    await pollAnalyze();
  } catch (e) {
    resetImportFlow(tBackup('analysis_failed'));
    importAnalysisStatus.textContent = `${tBackup('error_prefix')}${e.message}`;
  }
}

async function pollAnalyze(){
  if (!analyzeToken) return;
  try {
    const formData = new FormData();
    formData.append('action', 'analyze_step');
    formData.append('csrf_token', csrfToken);
    formData.append('token', analyzeToken);
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || tBackup('analysis_failed'));

    if (Array.isArray(data.compare_chunk) && data.compare_chunk.length) {
      analyzeCompare = analyzeCompare.concat(data.compare_chunk);
      importAnalysisCompare.innerHTML = renderCompareTable(analyzeCompare);
    }

    if (typeof data.progress_pct === 'number') updateAnalyzeProgress(data.progress_pct);
    if (data.progress_label) updateAnalyzeLabel(data.progress_label);

    if (data.done) {
      const summaryBits = [];
      if (data.manifest?.created_at) summaryBits.push(tfmtBackup('compare_created', { date: formatLocalDate(data.manifest.created_at) }));
      if (typeof data.table_count === 'number') summaryBits.push(tfmtBackup('compare_tables', { count: String(data.table_count) }));
      if (data.manifest?.settings) {
        const settingsState = data.settings_same === true ? tBackup('compare_same') : (data.settings_same === false ? tBackup('compare_diff') : tBackup('compare_unknown'));
        summaryBits.push(tfmtBackup('compare_settings', { status: settingsState }));
      }
      if (data.manifest?.uploads) {
        const uploadsState = data.uploads_same === true ? tBackup('compare_same') : (data.uploads_same === false ? tBackup('compare_diff') : tBackup('compare_unknown'));
        const backupCount = typeof data.uploads_backup_count === 'number' ? data.uploads_backup_count : '–';
        const currentCount = typeof data.uploads_current_count === 'number' ? data.uploads_current_count : '–';
        summaryBits.push(tfmtBackup('compare_uploads', { status: uploadsState, backup: backupCount, current: currentCount }));
      }
      importAnalysisSummary.textContent = summaryBits.length ? summaryBits.join(' · ') : tBackup('compare_analyzed');

      importAnalysisStatus.textContent = data.is_same ? tBackup('compare_ok') : tBackup('compare_diff_status');

      if (data.manifest?.settings) {
        importSettings.disabled = false;
        if (!importSettings.checked) importSettings.checked = true;
        renderSettingsOptions();
      } else {
        importSettings.checked = false;
        importSettings.disabled = true;
        importSettingsOptions.textContent = tBackup('settings_none');
      }

      if (data.manifest?.uploads) {
        importUploads.disabled = false;
        if (!importUploads.checked) importUploads.checked = true;
        importUploads.disabled = false;
        renderUploadsOptions(data.uploads_categories || []);
      } else {
        importUploads.checked = false;
        importUploads.disabled = true;
        importUploadsOptions.textContent = tBackup('uploads_none');
      }

      if (data.is_same) {
        importConfirmWrap.style.display = 'none';
        importOptions.style.display = 'none';
        importStatus.textContent = tBackup('import_not_required');
      } else {
        importConfirmWrap.style.display = '';
        importStatus.textContent = tBackup('import_confirm_needed');
      }
      importAnalysisProgress.style.display = 'none';
      return;
    }

    setTimeout(pollAnalyze, 300);
  } catch (e) {
    resetImportFlow(tBackup('analysis_failed'));
    importAnalysisStatus.textContent = `${tBackup('error_prefix')}${e.message}`;
  }
}

async function pollImport(){
  if (!importToken) return;
  try {
    const formData = new FormData();
    formData.append('action', 'import_step');
    formData.append('csrf_token', csrfToken);
    formData.append('token', importToken);
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || tBackup('import_failed'));

    if (typeof data.progress_pct === 'number') updateImportProgress(data.progress_pct);
    if (data.progress_label) updateImportLabel(data.progress_label);

    if (data.done) {
      importProgress.style.display = 'none';
      importStatus.textContent = data.message || tBackup('import_done');
      const importButton = document.getElementById('btnImport');
      if (importButton) importButton.style.display = '';
      importToken = null;
      return;
    }
    setTimeout(pollImport, 300);
  } catch (e) {
    importProgress.style.display = 'none';
    importStatus.textContent = `${tBackup('error_prefix')}${e.message}`;
  }
}

importFile.addEventListener('change', () => {
  const file = importFile.files && importFile.files[0];
  if (!file) {
    resetImportFlow(tBackup('analysis_no_file'));
    importAnalysisStatus.textContent = tBackup('analysis_no_file');
    return;
  }
  importStatus.textContent = tBackup('import_status_ready');
  analyzeBackup(file);
});

importConfirm.addEventListener('change', () => {
  importOptions.style.display = importConfirm.checked ? '' : 'none';
});

importSettings.addEventListener('change', () => {
  importSettingsOptions.style.display = importSettings.checked ? '' : 'none';
  importSettingsOptions.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    el.disabled = !importSettings.checked;
  });
});
importUploads.addEventListener('change', () => {
  importUploadsOptions.style.display = importUploads.checked ? '' : 'none';
  importUploadsOptions.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    el.disabled = !importUploads.checked;
  });
});
const importReplace = document.getElementById('importReplace');
const importConflictMode = document.getElementById('importConflictMode');
if (importReplace) {
  const toggleConflictMode = () => {
    if (importConflictMode) importConflictMode.style.display = importReplace.checked ? 'none' : '';
  };
  importReplace.addEventListener('change', toggleConflictMode);
  toggleConflictMode();
}

document.getElementById('importForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!importConfirm.checked) {
    importStatus.textContent = tBackup('import_status_confirm');
    return;
  }
  const tables = selectedTables(importTables);
  const includeSettings = document.getElementById('importSettings').checked;
  const includeUploads = document.getElementById('importUploads').checked;
  const replaceTables = document.getElementById('importReplace').checked;
  if (!tables.length && !includeSettings && !includeUploads) {
    importStatus.textContent = tBackup('import_select_option');
    return;
  }
  if (includeSettings) {
    const selectedSettings = Array.from(document.querySelectorAll('input[name="selected_settings[]"]:checked'));
    if (!selectedSettings.length) {
      importStatus.textContent = tBackup('import_select_settings');
      return;
    }
  }
  if (includeUploads) {
    const selectedUploads = Array.from(document.querySelectorAll('input[name="selected_uploads[]"]:checked'));
    if (!selectedUploads.length) {
      importStatus.textContent = tBackup('import_select_uploads');
      return;
    }
  }
  if (!replaceTables) {
    const selectedMode = document.querySelector('input[name="conflict_mode"]:checked');
    if (!selectedMode) {
      importStatus.textContent = tBackup('import_select_conflict');
      return;
    }
  }
  importStatus.textContent = tBackup('import_starting');
  importProgress.style.display = '';
  updateImportProgress(0);
  updateImportLabel(tBackup('import_preparing'));
  const importButton = document.getElementById('btnImport');
  if (importButton) importButton.style.display = 'none';

  const formData = new FormData(event.target);
  formData.append('action', 'import_start');
  tables.forEach((tbl) => formData.append('tables[]', tbl));

  try {
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || tBackup('import_failed'));
    importToken = data.token;
    updateImportProgress(0);
    await pollImport();
  } catch (e) {
    importProgress.style.display = 'none';
    if (importButton) importButton.style.display = '';
    importStatus.textContent = `${tBackup('error_prefix')}${e.message}`;
  }
});

loadTables();
</script>

<?php
render_admin_footer();
