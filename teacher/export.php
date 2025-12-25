<?php
// teacher/export.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

$st = $pdo->prepare("SELECT c.* FROM classes c INNER JOIN user_class_assignments uca ON uca.class_id=c.id WHERE uca.user_id=? AND c.is_active=1 ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC");
$st->execute([$userId]);
$classes = $st->fetchAll(PDO::FETCH_ASSOC);

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);

$csrf = csrf_token();

render_teacher_header('PDF-Export');
?>

<div class="container" style="max-width:1100px;">
  <div class="card" style="margin-bottom:14px;">
    <div class="row-actions">
      <a class="btn secondary" href="<?=h(url('teacher/index.php'))?>">← Zurück</a>
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
        <div class="muted" style="margin-top:4px;">Tipp: Direktlink aus der Schülerliste funktioniert auch.</div>
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
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

const elClass = document.getElementById('classId');
const elStudentWrap = document.getElementById('singleStudentWrap');
const elStudent = document.getElementById('studentId');
const elOnlySubmitted = document.getElementById('onlySubmitted');
const elStatus = document.getElementById('statusLine');
const elWarnBox = document.getElementById('warnBox');
const btnCheck = document.getElementById('btnCheck');
const btnExport = document.getElementById('btnExport');

function setStatus(msg){ elStatus.textContent = msg; }
function escapeHtml(s){ return (s||'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

function currentMode(){
  const r = document.querySelector('input[name="mode"]:checked');
  return r ? r.value : 'zip';
}

function updateModeUI(){
  const m = currentMode();
  elStudentWrap.style.display = (m === 'single') ? '' : 'none';
}

document.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', updateModeUI));
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
  const resp = await fetch(<?= json_encode(url('teacher/ajax/export_api.php')) ?>, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  const data = await resp.json().catch(()=> ({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || ('HTTP ' + resp.status));
  return data;
}

function renderWarnings(summary){
  if (!summary) { elWarnBox.style.display = 'none'; elWarnBox.innerHTML=''; return; }
  const totalMissing = Number(summary.total_missing||0);
  const studentsWithMissing = Number(summary.students_with_missing||0);
  if (totalMissing <= 0) { elWarnBox.style.display = 'none'; elWarnBox.innerHTML=''; return; }

  const lines = [];
  lines.push(`<div class="alert" style="margin:0;">`);
  lines.push(`<strong>Warnung:</strong> ${studentsWithMissing} Schüler:in(en) haben fehlende Werte (${totalMissing} Feld(er)). Export ist trotzdem möglich.`);
  lines.push(`</div>`);

  if (Array.isArray(summary.by_student) && summary.by_student.length){
    lines.push('<div style="margin-top:8px; max-height:260px; overflow:auto; border:1px solid #e6e6e6; border-radius:12px; padding:10px;">');
    lines.push('<table class="table" style="margin:0;">');
    lines.push('<thead><tr><th style="width:240px;">Schüler:in</th><th>Fehlende Felder</th></tr></thead>');
    lines.push('<tbody>');
    for (const r of summary.by_student){
      const name = escapeHtml(r.student_name||('ID ' + r.student_id));
      const miss = Array.isArray(r.missing_fields) ? r.missing_fields.map(x=>escapeHtml(x)).join(', ') : '';
      lines.push(`<tr><td>${name}</td><td class="muted">${miss || '—'}</td></tr>`);
    }
    lines.push('</tbody></table></div>');
  }

  elWarnBox.style.display = '';
  elWarnBox.innerHTML = lines.join('');
}

function fillStudentSelect(students){
  elStudent.innerHTML = '';
  (students||[]).forEach(s => {
    const opt = document.createElement('option');
    opt.value = String(s.id);
    const status = (s.report_status||'').toString();
    opt.textContent = status ? `${s.name} (${status})` : s.name;
    elStudent.appendChild(opt);
  });
}

function onlySubmittedFlag(){ return elOnlySubmitted.checked ? 1 : 0; }

async function check(){
  setStatus('Prüfe Daten …');
  elWarnBox.style.display='none';
  const classId = Number(elClass.value||0);
  const data = await apiFetch({ action: 'preview', class_id: classId, only_submitted: onlySubmittedFlag() });
  fillStudentSelect(data.students || []);
  renderWarnings(data.warnings_summary || null);
  const cnt = data.students?.length||0;
  const ftxt = onlySubmittedFlag() ? ' (nur abgegebene)' : '';
  setStatus(`OK. ${cnt} Schüler:in(en) gefunden${ftxt}. Vorlage: ${data.template?.name||''}`);
}

btnCheck.addEventListener('click', async () => {
  try {
    btnCheck.disabled = true;
    await check();
  } catch (e) {
    setStatus('Fehler: ' + (e?.message||e));
  } finally {
    btnCheck.disabled = false;
  }
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
  setTimeout(() => {
    URL.revokeObjectURL(a.href);
    a.remove();
  }, 500);
}

async function fillPdfForStudent(templateBytes, student){
  const { PDFDocument } = window.PDFLib;
  const pdfDoc = await PDFDocument.load(templateBytes);
  const form = pdfDoc.getForm();
  const values = student.values || {};

  for (const [key, raw] of Object.entries(values)){
    const v = (raw ?? '').toString();
    try {
      const f = form.getField(key);
      const ctor = f.constructor?.name || '';

      if (ctor.includes('PDFCheckBox')){
        if (v === '1' || v.toLowerCase() === 'true' || v.toLowerCase() === 'on') f.check(); else f.uncheck();
      } else if (ctor.includes('PDFRadioGroup')){
        if (v !== '') f.select(v);
      } else if (ctor.includes('PDFDropdown')){
        if (v !== '') f.select(v);
      } else {
        if (typeof f.setText === 'function') f.setText(v);
      }
    } catch (e) {
      // ignore missing/incompatible fields
    }
  }

  try { form.flatten(); } catch (e) {}
  return await pdfDoc.save();
}

async function exportNow(){
  const mode = currentMode();
  const classId = Number(elClass.value||0);
  const onlyStudentId = (mode === 'single') ? Number(elStudent.value||0) : 0;

  setStatus('Lade Exportdaten …');
  const payload = { action: 'data', class_id: classId, only_submitted: onlySubmittedFlag() };
  if (onlyStudentId > 0) payload.student_id = onlyStudentId;
  const data = await apiFetch(payload);

  fillStudentSelect(data.students || []);
  renderWarnings(data.warnings_summary || null);

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

  // single
  setStatus('Erzeuge PDF …');
  const s = students[0];
  const out = await fillPdfForStudent(templateBytes, s);
  const fn = safeFilename(s.name) || ('Schueler-' + s.id);
  downloadBytes(out, fn + suffix + '.pdf', 'application/pdf');
  setStatus('Fertig. PDF wurde heruntergeladen.');
}

btnExport.addEventListener('click', async () => {
  try {
    btnExport.disabled = true;
    btnCheck.disabled = true;
    await check(); // keep warnings up-to-date
    await exportNow();
  } catch (e) {
    setStatus('Fehler: ' + (e?.message||e));
  } finally {
    btnExport.disabled = false;
    btnCheck.disabled = false;
  }
});

// Apply deep-link params (mode, student_id, only_submitted)
(function initFromQuery(){
  const q = new URLSearchParams(window.location.search);
  const mode = (q.get('mode') || '').toLowerCase();
  const studentId = Number(q.get('student_id') || 0);
  const onlySub = (q.get('only_submitted') === '1');

  if (onlySub) elOnlySubmitted.checked = true;

  if (mode === 'merged' || mode === 'zip' || mode === 'single') {
    const r = document.querySelector('input[name="mode"][value="' + mode + '"]');
    if (r) r.checked = true;
  }
  updateModeUI();

  check().then(() => {
    if (studentId > 0) {
      const opt = Array.from(elStudent.options).find(o => Number(o.value) === studentId);
      if (opt) elStudent.value = String(studentId);
    }
  }).catch(()=>{});
})();

elOnlySubmitted.addEventListener('change', () => {
  check().catch(()=>{});
});
</script>

<?php render_teacher_footer(); ?>
