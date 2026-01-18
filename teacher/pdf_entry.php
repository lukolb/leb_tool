<?php
// teacher/pdf_entry.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classId = (int)($_GET['class_id'] ?? 0);
$studentId = (int)($_GET['student_id'] ?? 0);

if ($classId <= 0 || $studentId <= 0) {
  render_teacher_header('PDF-Formular');
  ?>
  <div class="card">
    <div class="alert danger"><strong>Fehlende Parameter.</strong></div>
  </div>
  <?php
  render_teacher_footer();
  exit;
}

if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo '403 Forbidden';
  exit;
}

$st = $pdo->prepare(
  "SELECT s.id, s.first_name, s.last_name, s.class_id,
          c.school_year, c.grade_level, c.label, c.name
   FROM students s
   JOIN classes c ON c.id=s.class_id
   WHERE s.id=?
   LIMIT 1"
);
$st->execute([$studentId]);
$student = $st->fetch(PDO::FETCH_ASSOC);
if (!$student || (int)($student['class_id'] ?? 0) !== $classId) {
  render_teacher_header('PDF-Formular');
  ?>
  <div class="card">
    <div class="alert danger"><strong>Schüler nicht gefunden oder falsche Klasse.</strong></div>
  </div>
  <?php
  render_teacher_footer();
  exit;
}

function pdf_entry_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  $id = (int)($c['class_id'] ?? ($c['id'] ?? 0));
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . $id));
}

$pageTitle = t('teacher.entry.title', 'Eingaben');
render_teacher_header($pageTitle);
$studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
?>

<style>
  .pdf-entry-wrap { max-width: 1200px; margin: 0 auto; }
  #pdfEntryPreview { position: relative; }
  .pdf-page {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    margin-bottom: 16px;
  }
  .pdf-page canvas { display: block; width: 100%; height: auto; }
  .pdf-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
  }
  .pdf-field {
    position: absolute;
    background: rgba(255,255,255,0.75);
    border: 1px solid rgba(0,0,0,0.15);
    border-radius: 4px;
    padding: 2px 4px;
    font: 500 12px/1.1 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    pointer-events: auto;
  }
  .pdf-field.is-readonly {
    background: rgba(245,245,245,0.8);
    color: rgba(0,0,0,0.5);
  }
  .pdf-field textarea {
    resize: none;
  }
  .pdf-field input,
  .pdf-field textarea,
  .pdf-field select {
    width: 100%;
    height: 100%;
    border: none;
    outline: none;
    background: transparent;
    font: inherit;
    line-height: 1.1;
  }
  .pdf-field select {
    background: rgba(255,255,255,0.65);
  }
  .pdf-field input[type="checkbox"] {
    width: 18px;
    height: 18px;
  }
  .save-status {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    background: #f1f3f7;
  }
</style>

<div class="pdf-entry-wrap">
  <div class="card">
    <div class="row-actions" style="float: right;">
      <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id=' . (int)$classId))?>">Zurück zur Klasse</a>
    </div>
    <h1 style="margin-bottom:4px;"><?=h(t('teacher.entry.heading_fill', 'Eingaben ausfüllen'))?></h1>
    <div class="muted">
      <?=h($studentName)?> · <?=h((string)($student['school_year'] ?? ''))?> · <?=h(pdf_entry_class_display($student))?>
    </div>
    <div class="muted" style="margin-top:6px;">Die Felder werden automatisch gespeichert.</div>
    <div style="margin-top:8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <span class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> Speichern…</span>
      <div class="save-status" id="saveStatus" aria-live="polite" style="display:none;"></div>
    </div>
  </div>

  <div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>

  <div id="pdfEntryPreview" class="card" style="background:#f8f9fb; border:1px solid var(--border); min-height:120px;">
    <div class="pdf-loader" aria-label="Lädt…" role="status">
      <span class="spinner"></span>
      <span class="txt">PDF wird geladen…</span>
    </div>
  </div>
</div>

<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

  const apiUrl = <?=json_encode(url('teacher/ajax/entry_api.php'))?>;
  const classId = <?=json_encode($classId)?>;
  const studentId = <?=json_encode($studentId)?>;
  const csrf = <?=json_encode(csrf_token())?>;

  const preview = document.getElementById('pdfEntryPreview');
  const errBox = document.getElementById('errBox');
  const errMsg = document.getElementById('errMsg');
  const savePill = document.getElementById('savePill');
  const saveStatus = document.getElementById('saveStatus');

  let pdfDoc = null;
  let state = null;
  let renderToken = 0;
  let saveTimer = null;
  let renderTimer = null;

  function showError(msg){
    if (!errBox || !errMsg) return;
    errMsg.textContent = msg;
    errBox.style.display = '';
  }

  function showSaveStatus(text, isError = false){
    if (!saveStatus) return;
    saveStatus.textContent = text;
    saveStatus.style.display = '';
    saveStatus.style.background = isError ? '#ffe5e5' : '#f1f3f7';
    saveStatus.style.color = isError ? '#a40000' : '#111';
  }

  function setSaving(active){
    if (!savePill) return;
    savePill.style.display = active ? '' : 'none';
  }

  async function api(action, payload){
    const body = { action, csrf_token: csrf, ...payload };
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  function groupFieldsByPage(fields){
    const map = new Map();
    fields.forEach((f) => {
      const p = Number(f.page || 0);
      if (!map.has(p)) map.set(p, []);
      map.get(p).push(f);
    });
    return map;
  }

  function createFieldInput(field, value){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-field';
    if (!field.can_edit) wrapper.classList.add('is-readonly');

    let el = null;
    const type = String(field.field_type || '');
    if (type === 'checkbox') {
      el = document.createElement('input');
      el.type = 'checkbox';
      el.checked = String(value || '').trim() === '1';
    } else if (type === 'select' || type === 'radio' || type === 'grade') {
      el = document.createElement('select');
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = '';
      el.appendChild(empty);
      (field.options || []).forEach((opt) => {
        const o = document.createElement('option');
        o.value = String(opt.value ?? '');
        o.textContent = String(opt.label_resolved ?? opt.label ?? opt.value ?? '');
        el.appendChild(o);
      });
      el.value = String(value ?? '');
    } else if (type === 'multiline' || Number(field.is_multiline || 0) === 1) {
      el = document.createElement('textarea');
      el.value = String(value ?? '');
    } else {
      el = document.createElement('input');
      el.type = 'text';
      el.value = String(value ?? '');
    }

    el.setAttribute('aria-label', String(field.label || field.field_name || ''));
    if (!field.can_edit) el.disabled = true;

    const handler = () => {
      if (!field.can_edit) return;
      const nextVal = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
      queueSave(field, nextVal);
    };

    if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
      el.addEventListener('blur', handler);
    } else {
      el.addEventListener('change', handler);
    }

    wrapper.appendChild(el);
    return wrapper;
  }

  function positionField(wrapper, rect){
    const [x1, y1, x2, y2] = rect;
    const left = Math.min(x1, x2);
    const top = Math.min(y1, y2);
    const width = Math.abs(x2 - x1);
    const height = Math.abs(y2 - y1);
    wrapper.style.left = `${left}px`;
    wrapper.style.top = `${top}px`;
    wrapper.style.width = `${width}px`;
    wrapper.style.height = `${height}px`;
    const size = Math.max(9, Math.min(14, Math.floor(height * 0.45)));
    wrapper.style.fontSize = `${size}px`;
  }

  async function renderPages(){
    if (!pdfDoc || !state || !preview) return;
    const token = ++renderToken;
    preview.innerHTML = '';

    const fieldsByPage = groupFieldsByPage(state.fields || []);
    const containerWidth = preview.clientWidth || 900;

    for (let p = 1; p <= pdfDoc.numPages; p++){
      const page = await pdfDoc.getPage(p);
      if (token !== renderToken) return;

      const baseViewport = page.getViewport({ scale: 1 });
      const scale = Math.min(1.6, Math.max(0.6, (containerWidth - 24) / baseViewport.width));
      const viewport = page.getViewport({ scale });

      const wrap = document.createElement('div');
      wrap.className = 'pdf-page';
      wrap.style.width = `${viewport.width}px`;
      wrap.style.height = `${viewport.height}px`;

      const canvas = document.createElement('canvas');
      const ratio = window.devicePixelRatio || 1;
      canvas.width = viewport.width * ratio;
      canvas.height = viewport.height * ratio;
      canvas.style.width = '100%';
      canvas.style.height = '100%';
      wrap.appendChild(canvas);

      const overlay = document.createElement('div');
      overlay.className = 'pdf-overlay';
      wrap.appendChild(overlay);

      preview.appendChild(wrap);

      const ctx = canvas.getContext('2d');
      const renderCtx = { canvasContext: ctx, viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined };
      await page.render(renderCtx).promise;

      const fields = fieldsByPage.get(p) || [];
      fields.forEach((field) => {
        const rect = Array.isArray(field.rect) ? field.rect : null;
        if (!rect || rect.length < 4) return;
        const viewRect = viewport.convertToViewportRectangle(rect);
        const value = (state.values && field.id in state.values) ? state.values[field.id] : '';
        const el = createFieldInput(field, value);
        positionField(el, viewRect);
        overlay.appendChild(el);
      });
    }
  }

  function queueSave(field, value){
    if (!state) return;
    state.values = state.values || {};
    state.values[field.id] = value;
    setSaving(true);
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveField(field, value), 250);
  }

  async function saveField(field, value){
    try {
      if (!state) return;
      if (field.scope === 'class') {
        await api('save_class', {
          class_id: classId,
          report_instance_id: state.class_report_instance_id,
          template_field_id: field.id,
          value_text: value
        });
      } else {
        await api('save', {
          report_instance_id: state.student.report_instance_id,
          template_field_id: field.id,
          value_text: value
        });
      }
      showSaveStatus('Gespeichert');
    } catch (e) {
      showSaveStatus(e?.message || 'Speichern fehlgeschlagen.', true);
    } finally {
      setSaving(false);
    }
  }

  async function load(){
    try {
      const data = await api('load_pdf', { class_id: classId, student_id: studentId });
      state = data;
      pdfDoc = await pdfjsLib.getDocument({ url: data.template.pdf_url, withCredentials: true }).promise;
      await renderPages();
      showSaveStatus('Bereit');
    } catch (e) {
      showError(e?.message || 'Laden fehlgeschlagen.');
    }
  }

  window.addEventListener('resize', () => {
    if (!pdfDoc) return;
    if (renderTimer) clearTimeout(renderTimer);
    renderTimer = setTimeout(() => renderPages(), 120);
  });

  load();
</script>

<?php render_history_replace_state_script(); ?>
</body>
</html>
