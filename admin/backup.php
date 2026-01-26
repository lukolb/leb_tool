<?php
// admin/backup.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pageTitle = 'Datensicherung';
$csrf = csrf_token();
$backupApiUrl = url('admin/ajax/backup_api.php');

render_admin_header($pageTitle);
?>

<style>
.spin{ display:inline-block; animation:spin 1s linear infinite; }
@keyframes spin{ from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
</style>

<div class="card" style="margin-bottom:14px;">
  <h1>Datensicherung</h1>
  <p class="muted">Exportiere Datenbank-Tabellen, Einstellungen und Uploads als ZIP-Datei. Beim Import kannst du auswählen, was übernommen wird.</p>
</div>

<div class="card" style="margin-bottom:14px;">
  <h2 style="margin-top:0;">Export</h2>
  <div class="grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
    <div>
      <strong>Datenbank</strong>
      <div id="exportTables" class="muted" style="margin-top:6px;">Tabellen werden geladen …</div>
    </div>
    <div>
      <strong>Zusätzliche Daten</strong>
      <div style="margin-top:8px;">
        <label class="row" style="gap:8px;">
          <input type="checkbox" id="exportSettings" checked>
          Einstellungen (Branding, Mail, KI, Portal)
        </label>
        <label class="row" style="gap:8px; margin-top:6px;">
          <input type="checkbox" id="exportUploads" checked>
          Uploads (Templates, Logos, Intro-Datei)
        </label>
      </div>
    </div>
  </div>
  <div class="row" style="justify-content:flex-end; margin-top:16px;">
    <button class="btn primary" id="btnExport" type="button">Backup herunterladen</button>
  </div>
  <div id="exportStatus" class="muted" style="margin-top:10px;">Bereit.</div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Import</h2>
  <form id="importForm" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
    <div class="grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
      <div>
        <label for="importFile"><strong>ZIP-Datei</strong></label>
        <input id="importFile" class="input" type="file" name="backup_file" accept=".zip" required>
        <div class="muted" style="margin-top:6px;">Nur ZIP-Dateien, die über den Export erzeugt wurden.</div>
        <div id="importAnalysisStatus" class="muted" style="margin-top:10px;">Noch keine Datei ausgewählt.</div>
        <div id="importAnalysisProgress" style="display:none; margin-top:10px;">
          <div class="progress-wrap">
            <div class="progress-meta"><span><span class="spin">⚙️</span> Analyse läuft …</span><span class="muted" id="importAnalysisPct">0%</span></div>
            <div class="progress"><div class="progress-bar" id="importAnalysisBar" style="width:0%;"></div></div>
          </div>
        </div>
      </div>
      <div>
        <strong>Analyse</strong>
        <div id="importAnalysisSummary" class="muted" style="margin-top:8px;">Bitte Backup-Datei auswählen.</div>
        <div id="importAnalysisCompare" style="margin-top:8px;"></div>
      </div>
    </div>

    <div id="importConfirmWrap" style="display:none; margin-top:14px;">
      <label class="row" style="gap:8px;">
        <input type="checkbox" id="importConfirm">
        Backup weicht vom aktuellen Stand ab. Daten wirklich überschreiben?
      </label>
    </div>

    <div id="importOptions" style="display:none; margin-top:14px;">
      <div>
        <strong>Datenbank-Tabellen</strong>
        <div id="importTables" class="muted" style="margin-top:6px;">Tabellen werden geladen …</div>
      </div>

      <div style="margin-top:12px;">
        <strong>Daten übernehmen</strong>
        <div style="margin-top:8px;">
          <label class="row" style="gap:8px;">
            <input type="checkbox" id="importSettings" name="import_settings" checked>
            Einstellungen
          </label>
          <div id="importSettingsOptions" class="muted" style="margin:6px 0 0 24px;"></div>
          <div class="muted" style="margin:6px 0 0 24px;">Hinweis: DB-Zugangsdaten werden nie importiert.</div>
          <label class="row" style="gap:8px; margin-top:10px;">
            <input type="checkbox" id="importUploads" name="import_uploads" checked>
            Uploads
          </label>
          <div id="importUploadsOptions" class="muted" style="margin:6px 0 0 24px;"></div>
          <label class="row" style="gap:8px; margin-top:6px;">
            <input type="checkbox" id="importReplace" name="import_replace" checked>
            Datenbanktabellen ersetzen (vorher leeren)
          </label>
        </div>
      </div>

      <div class="row" style="justify-content:flex-end; margin-top:16px;">
        <button class="btn primary" id="btnImport" type="submit">Backup importieren</button>
      </div>
    </div>
    <div id="importStatus" class="muted" style="margin-top:10px;">Bereit.</div>
  </form>
</div>

<script>
const backupApiUrl = <?= json_encode($backupApiUrl) ?>;
const csrfToken = <?= json_encode($csrf) ?>;

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
const importSettingsOptions = document.getElementById('importSettingsOptions');
const importUploadsOptions = document.getElementById('importUploadsOptions');
const importSettings = document.getElementById('importSettings');
const importUploads = document.getElementById('importUploads');
let analyzeToken = null;
let analyzeCompare = [];

function renderTableList(tables, target, prefix){
  if (!tables.length) {
    target.textContent = 'Keine Tabellen gefunden.';
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
    exportTables.textContent = 'Tabellen konnten nicht geladen werden.';
    importTables.textContent = 'Tabellen konnten nicht geladen werden.';
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
    exportStatus.textContent = 'Bitte mindestens eine Option auswählen.';
    return;
  }
  exportStatus.textContent = 'Export wird erstellt …';

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
      throw new Error(err.error || 'Export fehlgeschlagen.');
    }
    const blob = await resp.blob();
    const filename = resp.headers.get('Content-Disposition')?.match(/filename=\"?([^"]+)\"?/i)?.[1] || 'lebtool-backup.zip';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    exportStatus.textContent = 'Export abgeschlossen.';
  } catch (e) {
    exportStatus.textContent = `Fehler: ${e.message}`;
  }
});

function resetImportFlow(message){
  importAnalysisSummary.textContent = message || 'Bitte Backup-Datei auswählen.';
  importAnalysisCompare.innerHTML = '';
  importConfirmWrap.style.display = 'none';
  importConfirm.checked = false;
  importOptions.style.display = 'none';
  importAnalysisProgress.style.display = 'none';
  importAnalysisPct.textContent = '0%';
  importAnalysisBar.style.width = '0%';
  analyzeToken = null;
  analyzeCompare = [];
  importSettingsOptions.textContent = 'Einstellungen werden nach der Analyse angezeigt.';
  importUploadsOptions.textContent = 'Uploads werden nach der Analyse angezeigt.';
  importSettings.disabled = false;
  importUploads.disabled = false;
  importSettings.checked = true;
  importUploads.checked = true;
}

function renderCompareTable(entries){
  if (!entries.length) return '';
  const rows = entries.map((row) => {
    const status = row.same ? '✅' : '⚠️';
    const backupDate = formatLocalDate(row.backup_latest) || '–';
    const currentDate = formatLocalDate(row.current_latest) || '–';
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
            <th>Tabelle</th>
            <th>Backup</th>
            <th>Aktuell</th>
            <th>Backup Datum</th>
            <th>Aktuell Datum</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function renderSettingsOptions(){
  const opts = [
    { key: 'app', label: 'Branding & Schuljahr' },
    { key: 'mail', label: 'Mail' },
    { key: 'ai', label: 'KI' },
    { key: 'student', label: 'Schüler' },
    { key: 'parent', label: 'Eltern' },
    { key: 'signature', label: 'Signatur' },
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
    importUploadsOptions.textContent = 'Keine Upload-Kategorien im Backup.';
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
    span.textContent = `${it.label} (${backupCount} vs ${currentCount})`;
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

async function analyzeBackup(file){
  importAnalysisStatus.textContent = 'Backup wird analysiert …';
  importAnalysisSummary.textContent = 'Analyse läuft …';
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
    if (!resp.ok || !data.ok) throw new Error(data.error || 'Analyse fehlgeschlagen.');
    analyzeToken = data.token;
    updateAnalyzeProgress(0);
    await pollAnalyze();
  } catch (e) {
    resetImportFlow('Analyse fehlgeschlagen.');
    importAnalysisStatus.textContent = `Fehler: ${e.message}`;
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
    if (!resp.ok || !data.ok) throw new Error(data.error || 'Analyse fehlgeschlagen.');

    if (Array.isArray(data.compare_chunk) && data.compare_chunk.length) {
      analyzeCompare = analyzeCompare.concat(data.compare_chunk);
      importAnalysisCompare.innerHTML = renderCompareTable(analyzeCompare);
    }

    if (typeof data.progress_pct === 'number') updateAnalyzeProgress(data.progress_pct);

    if (data.done) {
      const summaryBits = [];
      if (data.manifest?.created_at) summaryBits.push(`Erstellt: ${formatLocalDate(data.manifest.created_at)}`);
      if (typeof data.table_count === 'number') summaryBits.push(`Tabellen: ${data.table_count}`);
      if (data.manifest?.settings) {
        const settingsState = data.settings_same === true ? 'gleich' : (data.settings_same === false ? 'abweichend' : 'unbekannt');
        summaryBits.push(`Einstellungen: ${settingsState}`);
      }
      if (data.manifest?.uploads) {
        const uploadsState = data.uploads_same === true ? 'gleich' : (data.uploads_same === false ? 'abweichend' : 'unbekannt');
        const backupCount = typeof data.uploads_backup_count === 'number' ? data.uploads_backup_count : '–';
        const currentCount = typeof data.uploads_current_count === 'number' ? data.uploads_current_count : '–';
        summaryBits.push(`Uploads: ${uploadsState} (${backupCount} vs ${currentCount})`);
      }
      importAnalysisSummary.textContent = summaryBits.length ? summaryBits.join(' · ') : 'Backup analysiert.';

      importAnalysisStatus.textContent = data.is_same ? 'Backup entspricht dem aktuellen Stand.' : 'Backup unterscheidet sich vom aktuellen Stand.';

      if (data.manifest?.settings) {
        importSettings.disabled = false;
        if (!importSettings.checked) importSettings.checked = true;
        renderSettingsOptions();
      } else {
        importSettings.checked = false;
        importSettings.disabled = true;
        importSettingsOptions.textContent = 'Keine Einstellungen im Backup.';
      }

      if (data.manifest?.uploads) {
        importUploads.disabled = false;
        if (!importUploads.checked) importUploads.checked = true;
        importUploads.disabled = false;
        renderUploadsOptions(data.uploads_categories || []);
      } else {
        importUploads.checked = false;
        importUploads.disabled = true;
        importUploadsOptions.textContent = 'Keine Uploads im Backup.';
      }

      if (data.is_same) {
        importConfirmWrap.style.display = 'none';
        importOptions.style.display = 'none';
        importStatus.textContent = 'Import nicht erforderlich.';
      } else {
        importConfirmWrap.style.display = '';
        importStatus.textContent = 'Bitte bestätigen, bevor importiert wird.';
      }
      importAnalysisProgress.style.display = 'none';
      return;
    }

    setTimeout(pollAnalyze, 300);
  } catch (e) {
    resetImportFlow('Analyse fehlgeschlagen.');
    importAnalysisStatus.textContent = `Fehler: ${e.message}`;
  }
}

importFile.addEventListener('change', () => {
  const file = importFile.files && importFile.files[0];
  if (!file) {
    resetImportFlow('Noch keine Datei ausgewählt.');
    importAnalysisStatus.textContent = 'Noch keine Datei ausgewählt.';
    return;
  }
  importStatus.textContent = 'Bereit.';
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

document.getElementById('importForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!importConfirm.checked) {
    importStatus.textContent = 'Bitte erst bestätigen.';
    return;
  }
  const tables = selectedTables(importTables);
  const includeSettings = document.getElementById('importSettings').checked;
  const includeUploads = document.getElementById('importUploads').checked;
  if (!tables.length && !includeSettings && !includeUploads) {
    importStatus.textContent = 'Bitte mindestens eine Option auswählen.';
    return;
  }
  if (includeSettings) {
    const selectedSettings = Array.from(document.querySelectorAll('input[name="selected_settings[]"]:checked'));
    if (!selectedSettings.length) {
      importStatus.textContent = 'Bitte mindestens eine Einstellung auswählen.';
      return;
    }
  }
  if (includeUploads) {
    const selectedUploads = Array.from(document.querySelectorAll('input[name="selected_uploads[]"]:checked'));
    if (!selectedUploads.length) {
      importStatus.textContent = 'Bitte mindestens eine Upload-Kategorie auswählen.';
      return;
    }
  }
  importStatus.textContent = 'Import läuft …';

  const formData = new FormData(event.target);
  formData.append('action', 'import');
  tables.forEach((tbl) => formData.append('tables[]', tbl));

  try {
    const resp = await fetch(backupApiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || 'Import fehlgeschlagen.');
    importStatus.textContent = data.message || 'Import abgeschlossen.';
  } catch (e) {
    importStatus.textContent = `Fehler: ${e.message}`;
  }
});

loadTables();
</script>

<?php
render_admin_footer();
