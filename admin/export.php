<?php
// admin/export.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

$st = $pdo->query("SELECT c.* FROM classes c WHERE c.is_active=1 ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC");
$classes = $st->fetchAll(PDO::FETCH_ASSOC);

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);

$csrf = csrf_token();
$debugPdf = (int)($_GET['debug_pdf'] ?? 0) === 1;

render_admin_header('PDF-Export');
?>

<div class="container" style="max-width:1100px;">
  <div class="card" style="margin-bottom:14px;">
    <div class="row-actions">
      <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Zurück</a>
    </div>
    <h1 style="margin-top:0;">PDF-Export</h1>
    <p class="muted" style="margin:0;">PDFs werden im Browser erzeugt und <strong>nicht</strong> auf dem Server gespeichert.</p>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <div class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label for="classId"><strong>Klasse</strong></label>
        <select id="classId" class="input" style="width:100%;">
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $classId) ? 'selected' : '' ?>>
              <?=h((string)$c['school_year'])?> · <?=h(class_display($c))?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="muted" style="margin-top:4px;">Exportiert die der Klasse zugeordnete Vorlage (Admin: Klassen → Vorlage).</div>
      </div>

      <div style="min-width:340px;">
        <label><strong>Export-Variante</strong></label>
        <div class="row" style="gap:14px; flex-wrap:wrap;">
          <label class="row" style="gap:6px;"><input type="radio" name="mode" value="zip" checked> ZIP (eine PDF pro Schüler:in)</label>
          <label class="row" style="gap:6px;"><input type="radio" name="mode" value="merged"> Eine PDF (alle Schüler:innen)</label>
          <label class="row" style="gap:6px;"><input type="radio" name="mode" value="single"> Einzelne:r Schüler:in</label>
        </div>
      </div>

      <div style="min-width:220px;">
        <label><strong>Filter</strong></label>
        <label class="row" style="gap:8px; margin-top:6px;">
          <input type="checkbox" id="onlySubmitted">
          Nur abgegebene (submitted)
        </label>
      </div>

      <div id="singleStudentWrap" style="min-width:260px; display:none;">
        <label for="studentId"><strong>Schüler:in</strong></label>
        <select id="studentId" class="input" style="width:100%;"></select>
      </div>

      <div style="flex:1; min-width:240px;">
        <label><strong>&nbsp;</strong></label>
        <div class="row" style="gap:10px; justify-content:flex-end;">
          <button class="btn secondary" id="btnCheck" type="button">Prüfen</button>
          <button class="btn" id="btnExport" type="button">Export starten</button>
        </div>
        <div class="muted" style="margin-top:4px; text-align:right;">Warnungen blockieren den Export nicht.</div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <div>
        <strong>Status</strong>
        <div class="muted" id="statusLine">Bereit.</div>
      </div>
      <div class="muted" style="text-align:right; max-width:520px;">
        Bei großen Klassen kann „Eine PDF (alle)“ etwas dauern – es läuft komplett im Browser.
      </div>
    </div>
    <div id="warnBox" style="display:none; margin-top:10px;"></div>
  </div>

  <div class="muted" style="font-size:13px;">
    Hinweis: Für die PDF-Befüllung wird <code>pdf-lib</code> und für ZIP <code>JSZip</code> geladen (nur für diese Seite).
    <?php if ($debugPdf): ?>
      <span style="margin-left:10px; padding:2px 8px; border-radius:999px; background:#fff7d6; border:1px solid #ffe59a;">
        Debug aktiv (debug_pdf=1) – siehe Browser-Konsole
      </span>
    <?php endif; ?>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const DEBUG_PDF = <?= $debugPdf ? 'true' : 'false' ?>;

const elClass = document.getElementById('classId');
const elStudentWrap = document.getElementById('singleStudentWrap');
const elStudent = document.getElementById('studentId');
const elOnlySubmitted = document.getElementById('onlySubmitted');
const elStatus = document.getElementById('statusLine');
const btnCheck = document.getElementById('btnCheck');
const btnExport = document.getElementById('btnExport');

function setStatus(msg){ elStatus.textContent = msg; }

function currentMode(){
  const r = document.querySelector('input[name="mode"]:checked');
  return r ? r.value : 'zip';
}
function updateModeUI(){
  elStudentWrap.style.display = (currentMode() === 'single') ? '' : 'none';
}
document.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', () => {
  updateModeUI();
  // Optional: refresh list when switching to single so selection list is up to date,
  // but preserve selection if possible.
  if (currentMode() === 'single') {
    check().catch(()=>{});
  }
}));
updateModeUI();

elClass.addEventListener('change', () => {
  const id = Number(elClass.value||0);
  const url = new URL(window.location.href);
  url.searchParams.set('class_id', String(id));
  window.location.href = url.toString();
});

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

async function apiFetch(payload){
  const resp = await fetch(<?= json_encode(url('admin/ajax/export_api.php')) ?>, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  const data = await resp.json().catch(()=> ({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || ('HTTP ' + resp.status));
  return data;
}

/**
 * Fill student select and preserve selection.
 * keepId: preferred id (string/number). If present in list, it stays selected.
 */
function fillStudentSelect(students, keepId){
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

  // restore selection if possible
  if (keep && foundKeep) elStudent.value = keep;
  else elStudent.value = firstId;
}

function onlySubmittedFlag(){ return elOnlySubmitted.checked ? 1 : 0; }

async function check(){
  const classId = Number(elClass.value||0);

  // IMPORTANT: preserve selection BEFORE refilling select
  const keepStudentId = elStudent.value;

  setStatus('Prüfe Daten …');
  const data = await apiFetch({ action: 'preview', class_id: classId, only_submitted: onlySubmittedFlag() });

  fillStudentSelect(data.students || [], keepStudentId);

  const cnt = data.students?.length||0;
  setStatus(`OK. ${cnt} Schüler:in(en) gefunden.`);
}

btnCheck.addEventListener('click', async () => {
  try { btnCheck.disabled = true; await check(); }
  catch (e) { setStatus('Fehler: ' + (e?.message||e)); }
  finally { btnCheck.disabled = false; }
});

elOnlySubmitted.addEventListener('change', () => {
  check().catch(()=>{});
});

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
    else if (typeof f?.setText === 'function') setText(f, v);
  }

  // Keep form editable & let viewer render the original X appearance
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
  const mode = currentMode();
  const classId = Number(elClass.value||0);

  // Preserve current selection through API refresh.
  const selectedStudentId = elStudent.value;

  setStatus('Lade Exportdaten …');
  const payload = { action: 'data', class_id: classId, only_submitted: onlySubmittedFlag() };

  // CRITICAL: in single mode request only this student from API (if your export_api supports it)
  if (mode === 'single' && selectedStudentId) payload.student_id = Number(selectedStudentId);

  const data = await apiFetch(payload);

  // Refresh select with preservation (important: don't jump back!)
  fillStudentSelect(data.students || [], selectedStudentId);

  const students = data.students || [];
  if (!students.length) throw new Error('Keine Schüler:innen gefunden (Filter?).');

  const needZip = (mode === 'zip');
  await loadLibsIfNeeded(needZip);

  setStatus('Lade PDF-Vorlage …');
  const tplResp = await fetch(data.pdf_url, { credentials: 'same-origin' });
  if (!tplResp.ok) throw new Error('PDF-Vorlage konnte nicht geladen werden.');
  const templateBytes = new Uint8Array(await tplResp.arrayBuffer());

  const baseName = safeFilename((data.class?.display || 'Klasse') + ' ' + (data.class?.school_year || ''));
  const suffix = onlySubmittedFlag() ? ' - nur abgegebene' : '';

  if (mode === 'zip') {
    setStatus('Erzeuge PDFs …');
    const zip = new window.JSZip();
    let done = 0;
    for (const s of students){
      const bytes = await fillPdfForStudent(templateBytes, s);
      const fn = safeFilename(s.name) || ('Schueler-' + s.id);
      zip.file(fn + '.pdf', bytes);
      done++;
      setStatus(`Erzeuge PDFs … ${done}/${students.length}`);
    }
    setStatus('ZIP packen …');
    const out = await zip.generateAsync({ type: 'uint8array' });
    downloadBytes(out, baseName + suffix + '.zip', 'application/zip');
    setStatus('Fertig. ZIP wurde heruntergeladen.');
    return;
  }

  if (mode === 'merged') {
    setStatus('Erzeuge eine zusammengeführte PDF …');
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
    }
    const out = await merged.save();
    downloadBytes(out, baseName + suffix + '.pdf', 'application/pdf');
    setStatus('Fertig. PDF wurde heruntergeladen.');
    return;
  }

  // single: export the selected student, not always students[0]
  setStatus('Erzeuge PDF …');
  const chosenId = elStudent.value;
  let s = students[0];

  if (chosenId) {
    const found = students.find(x => String(x.id) === String(chosenId));
    if (found) s = found;
  }

  const out = await fillPdfForStudent(templateBytes, s);
  const fn = safeFilename(s.name) || ('Schueler-' + s.id);
  downloadBytes(out, fn + suffix + '.pdf', 'application/pdf');
  setStatus('Fertig. PDF wurde heruntergeladen.');
}

btnExport.addEventListener('click', async () => {
  try {
    btnExport.disabled = true;
    btnCheck.disabled = true;
    await check();      // refresh list BUT keep selection now
    await exportNow();  // export selected student in single mode
  } catch (e) {
    setStatus('Fehler: ' + (e?.message||e));
  } finally {
    btnExport.disabled = false;
    btnCheck.disabled = false;
  }
});

(function initFromQuery(){
  const q = new URLSearchParams(window.location.search);
  const mode = (q.get('mode') || '').toLowerCase();
  const studentId = q.get('student_id') ? String(q.get('student_id')) : '';
  const onlySub = (q.get('only_submitted') === '1');

  if (onlySub) elOnlySubmitted.checked = true;

  if (mode === 'merged' || mode === 'zip' || mode === 'single') {
    const r = document.querySelector('input[name="mode"][value="' + mode + '"]');
    if (r) r.checked = true;
  }
  updateModeUI();

  // First check loads list. After list exists, restore student selection from query.
  check().then(() => {
    if (studentId) {
      // If the student exists (under current filter), keep it; otherwise keep current.
      const opt = Array.from(elStudent.options).find(o => String(o.value) === studentId);
      if (opt) elStudent.value = studentId;
    }
  }).catch(()=>{});
})();
</script>

<?php render_admin_footer(); ?>
