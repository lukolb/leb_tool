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
      </div>
      <div>
        <strong>Daten übernehmen</strong>
        <div style="margin-top:8px;">
          <label class="row" style="gap:8px;">
            <input type="checkbox" id="importSettings" name="import_settings" checked>
            Einstellungen
          </label>
          <label class="row" style="gap:8px; margin-top:6px;">
            <input type="checkbox" id="importUploads" name="import_uploads" checked>
            Uploads
          </label>
          <label class="row" style="gap:8px; margin-top:6px;">
            <input type="checkbox" id="importReplace" name="import_replace" checked>
            Datenbanktabellen ersetzen (vorher leeren)
          </label>
        </div>
      </div>
    </div>

    <div style="margin-top:14px;">
      <strong>Datenbank-Tabellen</strong>
      <div id="importTables" class="muted" style="margin-top:6px;">Tabellen werden geladen …</div>
    </div>

    <div class="row" style="justify-content:flex-end; margin-top:16px;">
      <button class="btn primary" id="btnImport" type="submit">Backup importieren</button>
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

document.getElementById('importForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const tables = selectedTables(importTables);
  const includeSettings = document.getElementById('importSettings').checked;
  const includeUploads = document.getElementById('importUploads').checked;
  if (!tables.length && !includeSettings && !includeUploads) {
    importStatus.textContent = 'Bitte mindestens eine Option auswählen.';
    return;
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
