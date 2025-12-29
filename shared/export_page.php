<?php
// shared/export_page.php
declare(strict_types=1);

/**
 * Shared PDF export page (UI + JS).
 *
 * Expected variables (set by wrapper):
 *   - $exportApiUrl (string)
 *   - $backUrl (string)
 *   - $pageTitle (string)
 *   - $classId (int)
 *   - $classes (array)
 *   - $csrf (string)
 *   - $debugPdf (bool)
 */

if (!isset($exportApiUrl, $backUrl, $pageTitle, $classId, $classes, $csrf, $debugPdf)) {
  throw new RuntimeException('shared/export_page.php missing required variables.');
}

$tx = [
  'no_classes' => t('teacher.export.no_classes', 'Keine Klassen gefunden.'),
  'no_classes_hint' => t('teacher.export.no_classes_hint', 'Für Lehrkräfte heißt das meistens: Es sind noch keine Klassen zugeordnet (<code>user_class_assignments</code>).'),
  'class_label' => t('teacher.export.class_label', 'Klasse'),
  'class_hint' => t('teacher.export.class_hint', 'Exportiert die der Klasse zugeordnete Vorlage.'),
  'mode_label' => t('teacher.export.mode_label', 'Export-Variante'),
  'mode.zip' => t('teacher.export.mode.zip', 'ZIP-Export'),
  'mode.zip_sub' => t('teacher.export.mode.zip_sub', 'eine PDF pro Schüler:in'),
  'mode.merged' => t('teacher.export.mode.merged', 'Gesamt-PDF'),
  'mode.merged_sub' => t('teacher.export.mode.merged_sub', 'alle Schüler:innen in einer Datei'),
  'mode.single' => t('teacher.export.mode.single', 'Einzel-Export'),
  'mode.single_sub' => t('teacher.export.mode.single_sub', 'nur eine ausgewählte Person'),
  'filter_label' => t('teacher.export.filter_label', 'Filter'),
  'filter_only_submitted' => t('teacher.export.filter_only_submitted', 'Nur abgegebene (submitted)'),
  'student_label' => t('teacher.export.student_label', 'Schüler:in'),
  'check' => t('teacher.export.check', 'Prüfen'),
  'start' => t('teacher.export.start', 'Export starten'),
  'warn_note' => t('teacher.export.warn_note', 'Warnungen blockieren den Export nicht.'),
  'status' => t('teacher.export.status', 'Status'),
  'ready' => t('teacher.export.ready', 'Bereit.'),
  'info_label' => t('teacher.export.info_label', 'Hinweis:'),
  'warn_label' => t('teacher.export.warn_label', 'Achtung:'),
  'warn_hint' => t('teacher.export.warn_hint', 'Beim Export kannst du die Warnung ignorieren oder abbrechen.'),
  'details' => t('teacher.export.details', 'Details'),
  'speed_hint' => t('teacher.export.speed_hint', 'Bei großen Klassen kann „Eine PDF (alle)“ etwas dauern'),
  'debug_active' => t('teacher.export.debug_active', 'Debug aktiv (debug_pdf=1) – siehe Browser-Konsole'),
  'missing_title' => t('teacher.export.missing_title', 'Fehlende Einträge gefunden'),
  'missing_search' => t('teacher.export.missing_search', 'Suchen (Schüler:in oder Feld) …'),
  'expand_all' => t('teacher.export.expand_all', 'Alle ausklappen'),
  'collapse_all' => t('teacher.export.collapse_all', 'Alle einklappen'),
  'cancel' => t('teacher.export.cancel', 'Abbrechen'),
  'ignore' => t('teacher.export.ignore', 'Ignorieren & exportieren'),
];

function export_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}
?>

<style>
    .export-mode {
  min-width: 320px;
}

.export-title {
  font-weight: 600;
  display: block;
  margin-bottom: 6px;
}

.export-list {
  border: 1px solid #ddd;
  border-radius: 8px;
  overflow: hidden;
}

.export-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 8px 12px;
  cursor: pointer;
  transition: background 0.12s ease;
}

.export-row + .export-row {
  border-top: 1px solid #eee;
}

/* Radio-Buttons komplett ausblenden */
.export-row input {
  display: none;
}

/* Hover */
.export-row:hover {
  background: #f7f7f7;
}

/* Aktiv */
.export-row:has(input:checked) {
  background: #eef4ff;
}

/* feiner Indikator links */
.export-row:has(input:checked)::before {
  content: '';
  width: 3px;
  height: 100%;
  background: #3b82f6;
  margin-right: 6px;
  border-radius: 2px;
}

.export-main {
  font-weight: 500;
}

.export-sub {
  font-size: 0.85em;
  color: #666;
}
</style>

  <div class="card">
    <h1><?=h($pageTitle)?></h1>
  </div>

  <?php if (!is_array($classes) || count($classes) === 0): ?>
    <div class="card" style="border:1px solid #ffe08a; background:#fff7db; margin-bottom:14px;">
      <strong><?=h($tx['no_classes'])?></strong>
      <div class="muted" style="margin-top:6px;"><?=$tx['no_classes_hint']?></div>
    </div>
  <?php else: ?>

  <div class="card" style="margin-bottom:14px;">
    <div class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
          <label for="classId" class="export-title"><?=h($tx['class_label'])?></label>
        <select id="classId" class="input" style="width:100%;">
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$classId) ? 'selected' : '' ?>>
              <?=h((string)$c['school_year'])?> · <?=h(export_class_display($c))?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="muted" style="margin-top:4px;"><?=h($tx['class_hint'])?></div>
      </div>

      <div class="export-mode">
        <label class="export-title"><?=h($tx['mode_label'])?></label>

        <div class="export-list">
          <label class="export-row">
            <input type="radio" name="mode" value="zip" checked>
            <span class="export-main"><?=h($tx['mode.zip'])?></span>
            <span class="export-sub"><?=h($tx['mode.zip_sub'])?></span>
          </label>

          <label class="export-row">
            <input type="radio" name="mode" value="merged">
            <span class="export-main"><?=h($tx['mode.merged'])?></span>
            <span class="export-sub"><?=h($tx['mode.merged_sub'])?></span>
          </label>

          <label class="export-row">
            <input type="radio" name="mode" value="single">
            <span class="export-main"><?=h($tx['mode.single'])?></span>
            <span class="export-sub"><?=h($tx['mode.single_sub'])?></span>
          </label>
        </div>
      </div>

      <div style="min-width:220px;">
        <label class="export-title"><?=h($tx['filter_label'])?></label>
        <label class="row" style="gap:8px; margin-top:6px;">
          <input type="checkbox" id="onlySubmitted">
          <?=h($tx['filter_only_submitted'])?>
        </label>
      </div>

      <div id="singleStudentWrap" style="min-width:260px; display:none;">
        <label class="export-title" for="studentId"><?=h($tx['student_label'])?></label>
        <select id="studentId" class="input" style="width:100%;"></select>
      </div>

      <div style="flex:1; min-width:240px;">
        <label><strong>&nbsp;</strong></label>
        <div class="row" style="gap:10px; justify-content:flex-end;">
          <a class="btn secondary" id="btnCheck" type="button"><?=h($tx['check'])?></a>
          <a class="btn primary" id="btnExport" type="button" style="margin-left: 10px;"><?=h($tx['start'])?></a>
        </div>
        <div class="muted" style="margin-top:4px; text-align:right;"><?=h($tx['warn_note'])?></div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <div>
        <strong><?=h($tx['status'])?></strong>
        <div class="muted" id="statusLine" style="padding-top: 10px"><?=h($tx['ready'])?></div>
      </div>
    </div>

    <div id="exportProgressWrap" class="progress-wrap" style="display:none; margin-top:10px;">
      <div class="progress-meta"><span id="exportProgressText">—</span><span id="exportProgressPct"></span></div>
      <div class="progress"><div id="exportProgressBar" class="progress-bar"></div></div>
    </div>

    <div id="infoBox" style="display:none; margin-top:10px; padding:10px; border-radius:10px; border:1px solid #b9dbff; background:#eaf4ff;">
      <strong><?=h($tx['info_label'])?></strong>
      <span id="infoText"></span>
    </div>

    <div id="warnBox" class="alert info">
      <div class="row" style="justify-content:space-between; align-items:flex-start; gap:12px;">
          <div style="float: left;">
          <strong><?=h($tx['warn_label'])?></strong>
          <span id="warnText"></span>
          <div class="muted" style="margin-top:6px;"><?=h($tx['warn_hint'])?></div>
        </div>
        <div style="white-space:nowrap; text-align: end">
          <button class="btn secondary" id="btnWarnDetails" type="button" style="display:none;"><?=h($tx['details'])?></button>
        </div>
      </div>
    </div>
      <div class="muted" style="max-width:520px; padding-top: 10px">
        <?=h($tx['speed_hint'])?>
      </div>
  </div>

  <div class="muted" style="font-size:13px;">
    <?php if ($debugPdf): ?>
      <span style="margin-left:10px; padding:2px 8px; border-radius:999px; background:#fff7d6; border:1px solid #ffe59a;">
        <?=h($tx['debug_active'])?>
      </span>
    <?php endif; ?>
  </div>

  <?php endif; ?>

<!-- modal -->
<div id="missingModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div style="max-width:920px; margin:6vh auto; background:#fff; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;">
    <div style="padding:16px 18px; border-bottom:1px solid #eee;">
      <div style="font-size:18px; font-weight:700;"><?=h($tx['missing_title'])?></div>
      <div class="muted" id="missingModalSummary" style="margin-top:4px;"></div>

      <div class="row" style="gap:10px; margin-top:12px; flex-wrap:wrap; align-items:center;">
        <input id="missingSearch" class="input" style="flex:1; min-width:260px;" placeholder="<?=h($tx['missing_search'])?>">
        <button class="btn secondary" id="btnExpandAll" type="button"><?=h($tx['expand_all'])?></button>
        <button class="btn secondary" id="btnCollapseAll" type="button"><?=h($tx['collapse_all'])?></button>
      </div>
    </div>

    <div style="padding:14px 18px; max-height:58vh; overflow:auto;">
      <div id="missingModalList"></div>
    </div>

    <div style="padding:14px 18px; border-top:1px solid #eee; display:flex; gap:10px; justify-content:flex-end;">
      <button class="btn secondary" id="btnMissingCancel" type="button"><?=h($tx['cancel'])?></button>
      <button class="btn" id="btnMissingIgnore" type="button"><?=h($tx['ignore'])?></button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const DEBUG_PDF = <?= $debugPdf ? 'true' : 'false' ?>;

const EXPORT_API_URL = <?= json_encode($exportApiUrl) ?>;

const elClass = document.getElementById('classId');
const elStudentWrap = document.getElementById('singleStudentWrap');
const elStudent = document.getElementById('studentId');
const elOnlySubmitted = document.getElementById('onlySubmitted');
const elStatus = document.getElementById('statusLine');
const btnCheck = document.getElementById('btnCheck');
const btnExport = document.getElementById('btnExport');

const infoBox = document.getElementById('infoBox');
const infoText = document.getElementById('infoText');

const warnBox = document.getElementById('warnBox');

const progWrap = document.getElementById('exportProgressWrap');
const progBar  = document.getElementById('exportProgressBar');
const progText = document.getElementById('exportProgressText');
const progPct  = document.getElementById('exportProgressPct');
const warnText = document.getElementById('warnText');
const btnWarnDetails = document.getElementById('btnWarnDetails');

const modal = document.getElementById('missingModal');
const modalSummary = document.getElementById('missingModalSummary');
const modalList = document.getElementById('missingModalList');
const btnMissingCancel = document.getElementById('btnMissingCancel');
const btnMissingIgnore = document.getElementById('btnMissingIgnore');
const elMissingSearch = document.getElementById('missingSearch');
const btnExpandAll = document.getElementById('btnExpandAll');
const btnCollapseAll = document.getElementById('btnCollapseAll');

let lastPreview = null;
let __missingRenderSource = null;

// ✅ Cache der kompletten Schülerliste (damit single-export sie nicht überschreibt)
let __fullStudentList = [];

function setStatus(msg){ if (elStatus) elStatus.textContent = msg; }

function showProgress(label, done, total){
  if (!progWrap || !progBar) return;
  const t = Math.max(0, Number(total||0));
  const d = Math.max(0, Number(done||0));
  const pct = (t>0) ? Math.max(0, Math.min(100, Math.round((d/t)*100))) : 0;
  progWrap.style.display = '';
  if (progText) progText.textContent = label || '';
  if (progPct) progPct.textContent = (t>0) ? (pct + '%') : '';
  progBar.style.width = (t>0) ? (pct + '%') : '0%';
  progBar.classList.toggle('ok', t>0 && d>=t);
}

function hideProgress(){
  if (!progWrap) return;
  progWrap.style.display = 'none';
  if (progBar) { progBar.style.width = '0%'; progBar.classList.remove('ok'); }
  if (progText) progText.textContent = '';
  if (progPct) progPct.textContent = '';
}

function setInfo(msg){
  if (!infoBox || !infoText) return;
  if (msg) { infoText.textContent = msg; infoBox.style.display = ''; }
  else infoBox.style.display = 'none';
}

function currentMode(){
  const r = document.querySelector('input[name="mode"]:checked');
  return r ? r.value : 'zip';
}
function updateModeUI(){
  if (!elStudentWrap) return;
  elStudentWrap.style.display = (currentMode() === 'single') ? '' : 'none';
}
document.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', () => {
  updateModeUI();
  if (currentMode() === 'single') check().catch(()=>{});
}));
updateModeUI();

if (elClass) {
  elClass.addEventListener('change', () => {
    const id = Number(elClass.value||0);
    const url = new URL(window.location.href);
    url.searchParams.set('class_id', String(id));
    window.location.href = url.toString();
  });
}

async function loadLibsIfNeeded(needZip){
  if (!window.PDFLib){
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error('pdf-lib konnte nicht geladen werden.'));
      document.head.appendChild(s);
    });
  }
  if (needZip && !window.JSZip){
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error('JSZip konnte nicht geladen werden.'));
      document.head.appendChild(s);
    });
  }
}

function isNonFatalBusinessError(msg){
  const m = (msg||'').toLowerCase();
  return m.includes('keine vorlage zugeordnet')
      || m.includes('vorlage zugeordnet')
      || m.includes('vorlage wurde keine')
      || m.includes('keine schüler')
      || m.includes('keine schueler');
}

async function apiFetch(payload){
  if (!EXPORT_API_URL) throw new Error('EXPORT_API_URL ist leer (Wrapper setzt $exportApiUrl nicht).');

  let resp;
  try {
    resp = await fetch(EXPORT_API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });
  } catch (e) {
    throw new Error('Netzwerkfehler beim API-Request: ' + (e?.message || e));
  }

  const raw = await resp.text();
  let data = null;
  try { data = JSON.parse(raw); } catch (e) { data = null; }

  if (!resp.ok) {
    const msg = data?.error ? String(data.error) : raw.slice(0, 300);
    const err = new Error(msg || ('HTTP ' + resp.status));
    err._httpStatus = resp.status;
    err._raw = raw;
    err._isJson = !!data;
    throw err;
  }

  if (!data || !data.ok) {
    const msg = data?.error ? String(data.error) : raw.slice(0, 300);
    const err = new Error(msg || 'Ungültige API-Antwort.');
    err._httpStatus = resp.status;
    err._raw = raw;
    err._isJson = !!data;
    throw err;
  }

  return data;
}

function fillStudentSelect(students, keepId){
  if (!elStudent) return;
  const keep = (keepId !== undefined && keepId !== null && String(keepId) !== '') ? String(keepId) : '';
  elStudent.innerHTML = '';

  let firstId = '';
  let foundKeep = false;

  (students||[]).forEach((s, idx) => {
    const opt = document.createElement('option');
    const id = String(s.id);
    if (idx === 0) firstId = id;
    opt.value = id;
    opt.textContent = s.name || ('ID ' + id);
    if (keep && id === keep) foundKeep = true;
    elStudent.appendChild(opt);
  });

  if (!students || !students.length) return;

  if (keep && foundKeep) elStudent.value = keep;
  else elStudent.value = firstId;
}

function onlySubmittedFlag(){ return (elOnlySubmitted && elOnlySubmitted.checked) ? 1 : 0; }

function updateWarnBoxFromPreview(preview){
  const sum = preview?.warnings_summary;
  const total = Number(sum?.total_missing || 0);
  const studentsWith = Number(sum?.students_with_missing || 0);

  if (!warnBox || !warnText) return;

  if (total > 0) {
    warnText.textContent = `Insgesamt ${total} fehlende Einträge bei ${studentsWith} Schüler:in(en).`;
    warnBox.style.display = '';
    if (btnWarnDetails) btnWarnDetails.style.display = '';
  } else {
    warnBox.style.display = 'none';
    if (btnWarnDetails) btnWarnDetails.style.display = 'none';
  }
}

let __refiningSinglePreview = false;

async function check(){
  if (!elClass) return null;
  const classId = Number(elClass.value||0);
  const keepStudentId = elStudent?.value;

  setInfo('');
  setStatus('Prüfe Daten …');
  showProgress('Prüfe Daten …', 1, 3);

  try {
    const mode = currentMode();

    // 1) FULL PREVIEW (immer ohne student_id) -> füllt Dropdown korrekt
    const full = await apiFetch({ action: 'preview', class_id: classId, only_submitted: onlySubmittedFlag() });
    showProgress('Prüfe Daten …', 2, 3);

    // ✅ cache complete list
    __fullStudentList = Array.isArray(full.students) ? full.students : [];

    fillStudentSelect(__fullStudentList, keepStudentId);

    // 2) Single: warnings_summary für ausgewählten Schüler nachziehen, ohne Liste zu zerstören
    let merged = full;

    if (mode === 'single' && elStudent && elStudent.value && !__refiningSinglePreview) {
      const sid = Number(elStudent.value || 0);
      if (sid > 0) {
        __refiningSinglePreview = true;
        try {
          const single = await apiFetch({ action: 'preview', class_id: classId, student_id: sid, only_submitted: onlySubmittedFlag() });
          merged = Object.assign({}, full, { warnings_summary: single.warnings_summary });
        } finally {
          __refiningSinglePreview = false;
        }
      }
    }

    lastPreview = merged;
    updateWarnBoxFromPreview(merged);
    const cnt = __fullStudentList.length || 0;
    setStatus(`OK. ${cnt} Schüler:in(en) gefunden.`);
    showProgress('Prüfen fertig', 3, 3);
    return merged;

  } catch (e) {
    const msg = (e?.message || String(e));

    if (isNonFatalBusinessError(msg)) {
      lastPreview = null;
      __fullStudentList = [];
      if (elStudent) elStudent.innerHTML = '';
      if (warnBox) warnBox.style.display = 'none';
      if (btnWarnDetails) btnWarnDetails.style.display = 'none';
      hideProgress();
      setStatus('Hinweis.');
      setInfo(msg + ' (Admin: bitte der Klasse eine Vorlage zuweisen.)');
      return null;
    }

    hideProgress();
    setStatus('Fehler: ' + msg);
    throw e;
  }
}

if (btnCheck) {
  btnCheck.addEventListener('click', async () => {
    try { btnCheck.disabled = true; await check(); }
    finally { btnCheck.disabled = false; }
  });
}
if (elOnlySubmitted) elOnlySubmitted.addEventListener('change', () => { check().catch(()=>{}); });

if (elStudent) {
  elStudent.addEventListener('change', () => {
    if (currentMode() === 'single') check().catch(()=>{});
  });
}

function safeFilename(s){
  return (s||'export').toString()
    .replace(/\s+/g,' ')
    .replace(/[\\/:*?"<>|]/g,'-')
    .trim();
}

function downloadBytes(bytes, filename, mime){
  const blob = new Blob([bytes], { type: mime });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

function escapeHtml(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

// ---------- missing modal rendering (grouped by student) ----------
function buildMissingHtml(preview, q){
  const sum = preview?.warnings_summary || {};
  const byStudent = Array.isArray(sum.by_student) ? sum.by_student : [];
  const query = (q||'').toString().trim().toLowerCase();

  const parts = [];
  for (const s of byStudent) {
    const studentName = (s.student_name || ('ID ' + s.student_id)).toString();
    const fields = Array.isArray(s.missing_fields) ? s.missing_fields : [];
    if (!fields.length) continue;

    const filteredFields = !query ? fields : fields.filter(f => {
      const label = ((f.label || f.field_name || '') + '').toLowerCase();
      return studentName.toLowerCase().includes(query) || label.includes(query);
    });
    if (!filteredFields.length) continue;

    const showN = 60;
    const items = filteredFields.slice(0, showN).map(f => {
      const label = (f.label || f.field_name || '').toString();
      const req = Number(f.is_required || 0) === 1 ? ' (Pflicht)' : '';
      return `<li style="margin:2px 0;">${escapeHtml(label)}${req}</li>`;
    }).join('');

    const moreCount = filteredFields.length - showN;
    const more = moreCount > 0
      ? `<div class="muted" style="margin-top:6px;">… und ${moreCount} weitere</div>`
      : '';

    parts.push(`
      <details data-student="${escapeHtml(studentName)}" open>
        <summary style="cursor:pointer; padding:8px 10px; border:1px solid #eee; border-radius:10px; margin:8px 0; background:#fafafa;">
          <strong>${escapeHtml(studentName)}</strong>
          <span class="muted"> – ${filteredFields.length} fehlend</span>
        </summary>
        <div style="padding:4px 10px 10px 10px;">
          <ul style="margin:6px 0 0 18px; padding:0;">${items}</ul>
          ${more}
        </div>
      </details>
    `);
  }
  return parts.join('') || '<div class="muted">Keine passenden Treffer.</div>';
}

function openMissingModal(preview){
  return new Promise((resolve) => {
    const sum = preview?.warnings_summary || {};
    const total = Number(sum.total_missing || 0);
    const studentsWith = Number(sum.students_with_missing || 0);

    __missingRenderSource = preview;

    if (modalSummary) modalSummary.textContent = `Insgesamt ${total} fehlende Einträge bei ${studentsWith} Schüler:in(en).`;

    if (elMissingSearch) elMissingSearch.value = '';
    if (modalList) modalList.innerHTML = buildMissingHtml(preview, '');

    function cleanup(){
      if (btnMissingCancel) btnMissingCancel.onclick = null;
      if (btnMissingIgnore) btnMissingIgnore.onclick = null;
      if (modal) modal.style.display = 'none';
    }

    if (btnMissingCancel) btnMissingCancel.onclick = () => { cleanup(); resolve(false); };
    if (btnMissingIgnore) btnMissingIgnore.onclick = () => { cleanup(); resolve(true); };

    if (modal) modal.style.display = '';
  });
}

if (btnWarnDetails) {
  btnWarnDetails.addEventListener('click', async () => {
    if (!lastPreview) return;
    await openMissingModal(lastPreview);
  });
}

if (elMissingSearch) {
  elMissingSearch.addEventListener('input', () => {
    if (!__missingRenderSource || !modalList) return;
    modalList.innerHTML = buildMissingHtml(__missingRenderSource, elMissingSearch.value);
  });
}
if (btnExpandAll) {
  btnExpandAll.addEventListener('click', () => {
    document.querySelectorAll('#missingModalList details').forEach(d => d.open = true);
  });
}
if (btnCollapseAll) {
  btnCollapseAll.addEventListener('click', () => {
    document.querySelectorAll('#missingModalList details').forEach(d => d.open = false);
  });
}

// --------- PDF fill: keep form editable + render X via viewer (NeedAppearances) ----------
let __didDump = false;

async function fillPdfForStudent(templateBytes, student){
  const PDFLib = window.PDFLib;
  const { PDFDocument, PDFCheckBox, PDFRadioGroup, PDFDropdown, PDFOptionList, PDFName, PDFBool } = PDFLib;

  const pdfDoc = await PDFDocument.load(templateBytes);
  const form = pdfDoc.getForm();
  const values = student.values || {};

  const norm = (s) => (s ?? '').toString().trim();
  const normLoose = (s) => norm(s).toLowerCase().replace(/\s+/g,'');

  const fieldsByName = new Map();
  const fieldsByLoose = new Map();

  const addToMap = (map, key, field) => {
    if (!key) return;
    const arr = map.get(key);
    if (arr) arr.push(field); else map.set(key, [field]);
  };

  const allFields = form.getFields();
  for (const f of allFields){
    let name = '';
    try { name = f.getName(); } catch (e) { name = ''; }
    name = norm(name);
    if (!name) continue;

    addToMap(fieldsByName, name, f);
    addToMap(fieldsByLoose, normLoose(name), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\.+$/,'')), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\[0\]$/,'')), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\s+/g,'')), f);
  }

  const getFieldList = (key) => {
    const k = norm(key);
    if (!k) return [];
    const exact = fieldsByName.get(k);
    if (exact && exact.length) return exact;
    const loose = fieldsByLoose.get(normLoose(k));
    if (loose && loose.length) return loose;
    return [];
  };

  const isCheckBox = (f) => (PDFCheckBox && f instanceof PDFCheckBox) || (typeof f?.check === 'function' && typeof f?.uncheck === 'function');
  const isRadioGroup = (f) => (PDFRadioGroup && f instanceof PDFRadioGroup) || (typeof f?.select === 'function' && typeof f?.getOptions === 'function' && !isCheckBox(f));
  const isDropdown = (f) => (PDFDropdown && f instanceof PDFDropdown) || (typeof f?.select === 'function' && typeof f?.getOptions === 'function' && !isRadioGroup(f) && !isCheckBox(f));
  const isOptionList = (f) => (PDFOptionList && f instanceof PDFOptionList);

  const getOnValue = (cb) => {
    try {
      const af = cb?.acroField;
      if (af && typeof af.getOnValue === 'function') {
        const on = af.getOnValue();
        if (on && typeof on.key === 'string') return on.key;
        const s = String(on);
        return s.startsWith('/') ? s.slice(1) : s;
      }
    } catch (e) {}
    try {
      const af = cb?.acroField;
      if (af && typeof af.getWidgets === 'function') {
        const ws = af.getWidgets();
        if (ws && ws.length && typeof ws[0].getOnValue === 'function') {
          const on = ws[0].getOnValue();
          if (on && typeof on.key === 'string') return on.key;
          const s = String(on);
          return s.startsWith('/') ? s.slice(1) : s;
        }
      }
    } catch (e) {}
    return '';
  };

  const pickOption = (field, value) => {
    const v = norm(value);
    if (!v || typeof field?.getOptions !== 'function') return v;
    try {
      const opts = field.getOptions() || [];
      const vL = normLoose(v);
      const found = opts.find(o => normLoose(o) === vL);
      return found || v;
    } catch (e) {
      return v;
    }
  };

  const setText = (f, v) => { try { if (typeof f?.setText === 'function') f.setText(norm(v)); } catch(e) {} };
  const setSelect = (f, v) => { try { if (v !== '') f.select(pickOption(f, v)); } catch(e) {} };

  const setCheckGroupByOnValue = (checkboxes, desired) => {
    const d = norm(desired);
    const dL = normLoose(d);
    for (const cb of checkboxes){
      const on = getOnValue(cb);
      const onL = normLoose(on);
      try {
        if (on && onL === dL) cb.check();
        else cb.uncheck();
      } catch(e) {}
    }
  };

  if (DEBUG_PDF && !__didDump) {
    __didDump = true;
    try {
      console.log('[PDF DEBUG] Field inventory:', allFields.map(f => {
        let n = ''; try { n = f.getName(); } catch(e) {}
        return {
          name: n,
          isCheckBox: isCheckBox(f),
          isRadioGroup: isRadioGroup(f),
          isDropdown: isDropdown(f),
          ctor: (f?.constructor?.name || ''),
          onValue: isCheckBox(f) ? getOnValue(f) : null,
          options: (typeof f?.getOptions === 'function') ? (f.getOptions?.() || null) : null
        };
      }));
    } catch(e) {}
  }

  for (const [key, raw] of Object.entries(values)){
    const v = norm(raw);
    const list = getFieldList(key);
    if (!list.length) continue;

    const checkboxes = list.filter(isCheckBox);
    if (checkboxes.length) {
      setCheckGroupByOnValue(checkboxes, v);
      continue;
    }

    const f = list[0];
    if (isRadioGroup(f)) setSelect(f, v);
    else if (isDropdown(f) || isOptionList(f)) setSelect(f, v);
    else if (typeof f.setText === 'function') setText(f, v);
  }

  try {
    const acro = form.acroForm;
    if (acro && acro.dict && PDFName && PDFBool) {
      acro.dict.set(PDFName.of('NeedAppearances'), PDFBool.True);
    }
  } catch (e) {}
  try { form.updateFieldAppearances(); } catch (e) {}

  return await pdfDoc.save();
}

async function exportNow(){
  hideProgress();
  const mode = currentMode();
  const classId = Number(elClass.value||0);
  const selectedStudentId = elStudent?.value;

  setInfo('');
  setStatus('Lade Exportdaten …');
  showProgress('Lade Exportdaten …', 0, 1);

  let data;
  try {
    const payload = { action: 'data', class_id: classId, only_submitted: onlySubmittedFlag() };
    if (mode === 'single' && selectedStudentId) payload.student_id = Number(selectedStudentId);
    data = await apiFetch(payload);
  } catch (e) {
    const msg = (e?.message || String(e));
    if (isNonFatalBusinessError(msg)) {
      hideProgress();
      setStatus('Hinweis.');
      setInfo(msg + ' (Admin: bitte der Klasse eine Vorlage zuweisen.)');
      return;
    }
    throw e;
  }

  // ✅ FIX: Dropdown nicht mit single-response überschreiben
  if (mode === 'single') {
    fillStudentSelect(__fullStudentList, selectedStudentId);
  } else {
    fillStudentSelect(data.students || [], selectedStudentId);
  }

  const students = data.students || [];
  if (!students.length) throw new Error('Keine Schüler:innen gefunden (Filter?).');

  const needZip = (mode === 'zip');
  setStatus('Lade Bibliotheken …');
  showProgress('Lade Bibliotheken …', 0, 1);
  await loadLibsIfNeeded(needZip);
  showProgress('Bibliotheken geladen', 1, 1);

  setStatus('Lade PDF-Vorlage …');
  showProgress('Lade PDF-Vorlage …', 0, 1);
  const tplResp = await fetch(data.pdf_url, { credentials: 'same-origin' });
  if (!tplResp.ok) throw new Error('PDF-Vorlage konnte nicht geladen werden.');
  const templateBytes = new Uint8Array(await tplResp.arrayBuffer());
  showProgress('PDF-Vorlage geladen', 1, 1);

  const baseName = safeFilename((data.class?.display || 'Klasse') + ' ' + (data.class?.school_year || ''));
  const suffix = onlySubmittedFlag() ? ' - nur abgegebene' : '';

  if (mode === 'zip') {
    setStatus('Erzeuge PDFs …');
    showProgress('Erzeuge PDFs …', 0, students.length);
    const zip = new window.JSZip();
    let done = 0;
    for (const s of students){
      const bytes = await fillPdfForStudent(templateBytes, s);
      const fn = safeFilename(s.name) || ('Schueler-' + s.id);
      zip.file(fn + '.pdf', bytes);
      done++;
      setStatus(`Erzeuge PDFs … ${done}/${students.length}`);
      showProgress('Erzeuge PDFs …', done, students.length);
    }
    setStatus('ZIP packen …');
    showProgress('ZIP packen …', students.length, students.length);
    const out = await zip.generateAsync({ type: 'uint8array' });
    downloadBytes(out, baseName + suffix + '.zip', 'application/zip');
    setStatus('Fertig. ZIP wurde heruntergeladen.');
    showProgress('Fertig', students.length, students.length);
    return;
  }

  if (mode === 'merged') {
    setStatus('Erzeuge eine zusammengeführte PDF …');
    showProgress('Zusammenführen …', 0, students.length);
    const { PDFDocument } = window.PDFLib;
    const merged = await PDFDocument.create();
    let done = 0;
    for (const s of students){
      const filledBytes = await fillPdfForStudent(templateBytes, s);
      const src = await PDFDocument.load(filledBytes);
      const pages = await merged.copyPages(src, src.getPageIndices());
      pages.forEach(p => merged.addPage(p));
      done++;
      setStatus(`Zusammenführen … ${done}/${students.length}`);
      showProgress('Zusammenführen …', done, students.length);
    }
    const out = await merged.save();
    downloadBytes(out, baseName + suffix + '.pdf', 'application/pdf');
    setStatus('Fertig. PDF wurde heruntergeladen.');
    showProgress('Fertig', students.length, students.length);
    return;
  }

  setStatus('Erzeuge PDF …');
  showProgress('Erzeuge PDF …', 0, 1);
  const chosenId = elStudent?.value;
  let s = students[0];
  if (chosenId) {
    const found = students.find(x => String(x.id) === String(chosenId));
    if (found) s = found;
  }
  const out = await fillPdfForStudent(templateBytes, s);
  const fn = safeFilename(s.name) || ('Schueler-' + s.id);
  downloadBytes(out, fn + suffix + '.pdf', 'application/pdf');
  setStatus('Fertig. PDF wurde heruntergeladen.');
  showProgress('Fertig', 1, 1);
}

if (btnExport) {
  btnExport.addEventListener('click', async () => {
    try {
      btnExport.disabled = true;
      if (btnCheck) btnCheck.disabled = true;

      const preview = await check();

      const sum = preview?.warnings_summary || {};
      const totalMissing = Number(sum.total_missing || 0);

      if (preview && totalMissing > 0) {
        const proceed = await openMissingModal(preview);
        if (!proceed) {
          setStatus('Export abgebrochen.');
          return;
        }
      }

      await exportNow();

    } catch (e) {
      showProgress('Fehler', 0, 1);
      setStatus('Fehler: ' + (e?.message || e));
    } finally {
      btnExport.disabled = false;
      if (btnCheck) btnCheck.disabled = false;
    }
  });
}

// init
(function initFromQuery(){
  const q = new URLSearchParams(window.location.search);
  const mode = (q.get('mode') || '').toLowerCase();
  const studentId = q.get('student_id') ? String(q.get('student_id')) : '';
  const onlySub = (q.get('only_submitted') === '1');

  if (elOnlySubmitted && onlySub) elOnlySubmitted.checked = true;

  if (mode === 'merged' || mode === 'zip' || mode === 'single') {
    const r = document.querySelector('input[name="mode"][value="' + mode + '"]');
    if (r) r.checked = true;
  }
  updateModeUI();

  if (!elClass) return;

  check().then(() => {
    if (studentId && elStudent) {
      const opt = Array.from(elStudent.options).find(o => String(o.value) === studentId);
      if (opt) elStudent.value = studentId;
      if (currentMode() === 'single') check().catch(()=>{});
    }
  }).catch(()=>{});
})();
</script>
