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
    --radio-color: #475a70;
  }
  .pdf-field.is-readonly:not(.is-student) {
    background: #e7f2ff;
    border-color: #b6d1ff;
    color: #2b4a77;
  }
  .pdf-field.is-student {
    --radio-color: #2e7d32;
  }
  .pdf-field.is-system {
    background: #eef6ff;
    border-color: #9dbcf2;
    color: #2b4a77;
  }
  .pdf-field.has-delegate-other {
    display: flex;
    flex-direction: column;
  }
  .pdf-field.has-delegate-other input,
  .pdf-field.has-delegate-other textarea {
    height: calc(100% - var(--delegate-height, 0px));
  }
  .pdf-field__delegate-text {
    margin-top: 8px;
    color: #2b4a77;
    font-style: italic;
    white-space: pre-wrap;
    line-height: 1.2;
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
  .pdf-field--radio {
    padding: 4px 6px;
  }
  .pdf-field--widget {
    background: transparent;
    border: none;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .pdf-field--widget input[type="radio"] {
    width: 14px;
    height: 14px;
    margin: 0;
  }
  .pdf-radio-group {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    align-items: center;
  }
  .pdf-radio-item {
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .pdf-radio-item input[type="radio"],
  .pdf-field--widget input[type="radio"] {
    appearance: none;
    width: 14px;
    height: 14px;
    border: 2px solid var(--radio-color);
    border-radius: 2px;
    background: #fff;
    position: relative;
  }
  .pdf-radio-item input[type="radio"]::before,
  .pdf-field--widget input[type="radio"]::before {
    content: '';
    position: absolute;
    inset: 2px;
    background:
      linear-gradient(45deg, transparent 45%, var(--radio-color) 45%, var(--radio-color) 55%, transparent 55%),
      linear-gradient(-45deg, transparent 45%, var(--radio-color) 45%, var(--radio-color) 55%, transparent 55%);
    opacity: 0;
  }
  .pdf-radio-item input[type="radio"]:checked::before,
  .pdf-field--widget input[type="radio"]:checked::before {
    opacity: 1;
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
  .pdf-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
  }
  .pdf-toggle input {
    width: 14px;
    height: 14px;
  }
  .pdf-student-info {
    position: absolute;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #2e7d32;
    color: #2e7d32;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    pointer-events: auto;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
  }
  .pdf-student-info__tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translate(-50%, -6px);
    background: #2e7d32;
    color: #fff;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 11px;
    line-height: 1.2;
    white-space: pre-wrap;
    max-width: 240px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s ease-in-out;
    z-index: 5;
  }
  .pdf-student-info:hover .pdf-student-info__tooltip,
  .pdf-student-info:focus-within .pdf-student-info__tooltip {
    opacity: 1;
    pointer-events: auto;
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
    <div style="margin-top:8px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <label class="pdf-toggle" title="<?=h(t('teacher.entry.show_student_values_hint', 'Tastenkürzel: Strg+Shift+S'))?>">
        <input type="checkbox" id="toggleStudentValues" />
        <?=h(t('teacher.entry.show_student_values', 'Schülerwerte anzeigen'))?>
      </label>
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
  const toggleStudentValues = document.getElementById('toggleStudentValues');

  let pdfDoc = null;
  let state = null;
  let renderToken = 0;
  let saveTimer = null;
  let renderTimer = null;
  let showStudentValues = false;

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
      if (!p) return;
      if (!map.has(p)) map.set(p, []);
      map.get(p).push(f);
    });
    return map;
  }

  async function enrichFieldRectsFromPdf(){
    if (!pdfDoc || !state || typeof pdfDoc.getFieldObjects !== 'function') return;
    const fo = await pdfDoc.getFieldObjects();
    if (!fo || typeof fo !== 'object') return;

    const tmp = [];
    const widgetMap = new Map();
    let hasZero = false;
    for (const [name, arr] of Object.entries(fo)) {
      if (!Array.isArray(arr)) continue;
      arr.forEach((it, index) => {
        let pRaw = it?.page;
        if (pRaw === undefined || pRaw === null) pRaw = it?.pageIndex;
        const pNum = Number(pRaw);
        if (!Number.isFinite(pNum)) return;
        if (pNum === 0) hasZero = true;
        const rect = (Array.isArray(it?.rect) && it.rect.length >= 4) ? it.rect.slice(0, 4) : null;
        if (!rect) return;
        tmp.push({ name, pNum, rect });
        const exportValue = it?.exportValue ?? it?.value ?? it?.buttonValue ?? null;
        if (!widgetMap.has(name)) widgetMap.set(name, []);
        widgetMap.get(name).push({ pNum, rect, exportValue, index });
      });
    }

    const numPages = Number(pdfDoc?.numPages || 0);
    const normalizePage = (rawPage) => {
      let page = Number(rawPage || 0);
      if (hasZero) page = page + 1;
      if (page < 1) page = 1;
      if (numPages && page > numPages) page = numPages;
      return page;
    };
    const byName = new Map();
    tmp.forEach((t) => {
      const page = normalizePage(t.pNum);
      if (!byName.has(t.name)) byName.set(t.name, { page, rect: t.rect });
    });

    let updated = false;
    state.fields = (state.fields || []).map((f) => {
      const widgets = (widgetMap.get(f.field_name) || []).map((w) => ({
        page: normalizePage(w.pNum),
        rect: w.rect,
        exportValue: w.exportValue,
        index: w.index
      }));
      const withWidgets = widgets.length
        ? {
          widget_rects: widgets
        }
        : {};
      if (widgets.length) updated = true;
      if (f.page && f.rect) return { ...f, ...withWidgets };
      const hit = byName.get(f.field_name);
      if (!hit) return { ...f, ...withWidgets };
      updated = true;
      return { ...f, page: hit.page, rect: hit.rect, ...withWidgets };
    });

    if (updated) {
      await renderPages();
    }
  }

  function resolveOptionValue(field, rawValue){
    const options = Array.isArray(field.options) ? field.options : [];
    const valueText = String(rawValue ?? '');
    if (!options.length) return valueText;
    const asNumber = Number(valueText);
    if (Number.isFinite(asNumber)) {
      const byId = options.find((opt) => Number(opt.option_item_id || 0) === asNumber);
      if (byId) return String(byId.value ?? '');
    }
    const direct = options.find((opt) => String(opt.value ?? '') === valueText);
    if (direct) return String(direct.value ?? '');
    const byLabel = options.find((opt) => {
      const label = String(opt.label_resolved ?? opt.label ?? opt.value ?? '');
      return label === valueText;
    });
    return byLabel ? String(byLabel.value ?? '') : valueText;
  }

  function shouldUseWidgetRadios(field){
    const type = String(field.field_type || '');
    const options = Array.isArray(field.options) ? field.options : [];
    if (!options.length) return false;
    if (!Array.isArray(field.widget_rects) || !field.widget_rects.length) return false;
    if (type === 'grade') return false;
    return type === 'radio' || (type === 'select' && options.length <= 10);
  }

  function createRadioWidget(field, optionValue, optionLabel, currentValue, groupName){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-field pdf-field--widget';
    if (!field.can_edit) wrapper.classList.add('is-readonly');
    if (Number(field.child_only || 0) === 1) wrapper.classList.add('is-student');
    if (Number(field.system_bound || 0) === 1) wrapper.classList.add('is-system');

    const input = document.createElement('input');
    input.type = 'radio';
    input.name = groupName;
    input.value = String(optionValue ?? '');
    input.checked = String(currentValue ?? '') === String(optionValue ?? '');
    input.setAttribute('aria-label', String(optionLabel || field.label || field.field_name || ''));
    if (!field.can_edit) input.disabled = true;
    input.addEventListener('change', () => {
      if (!field.can_edit) return;
      queueSave(field, input.value);
    });

    wrapper.appendChild(input);
    return wrapper;
  }

  function createFieldInput(field, value){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-field';
    if (!field.can_edit) wrapper.classList.add('is-readonly');
    if (Number(field.child_only || 0) === 1) wrapper.classList.add('is-student');
    if (Number(field.system_bound || 0) === 1) wrapper.classList.add('is-system');

    let el = null;
    const type = String(field.field_type || '');
    const rawValue = String(value ?? '');
    const options = Array.isArray(field.options) ? field.options : [];
    const resolveOptionValueForField = () => resolveOptionValue(field, rawValue);
    const useRadioGroup = ['radio', 'select'].includes(type) && options.length > 0 && options.length <= 10;

    if (type === 'checkbox') {
      el = document.createElement('input');
      el.type = 'checkbox';
      el.checked = String(value || '').trim() === '1';
    } else if (useRadioGroup) {
      wrapper.classList.add('pdf-field--radio');
      el = document.createElement('div');
      el.className = 'pdf-radio-group';
      const name = `pdf-radio-${field.id}`;
      const resolvedValue = resolveOptionValueForField();
      const columns = Math.min(4, Math.max(1, options.length));
      const itemWidth = `calc(${100 / columns}% - 10px)`;
      options.forEach((opt) => {
        const item = document.createElement('label');
        item.className = 'pdf-radio-item';
        item.style.flex = `0 0 ${itemWidth}`;
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = name;
        input.value = String(opt.value ?? '');
        input.checked = resolvedValue === input.value;
        if (!field.can_edit) input.disabled = true;
        const text = document.createElement('span');
        text.textContent = String(opt.label_resolved ?? opt.label ?? opt.value ?? '');
        item.appendChild(input);
        item.appendChild(text);
        el.appendChild(item);
      });
    } else if (type === 'select' || type === 'grade') {
      el = document.createElement('select');
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = '';
      el.appendChild(empty);
      options.forEach((opt) => {
        const o = document.createElement('option');
        o.value = String(opt.value ?? '');
        o.textContent = String(opt.label_resolved ?? opt.label ?? opt.value ?? '');
        el.appendChild(o);
      });
      el.value = resolveOptionValueForField();
    } else if (type === 'multiline' || Number(field.is_multiline || 0) === 1) {
      el = document.createElement('textarea');
      el.value = String(value ?? '');
    } else {
      el = document.createElement('input');
      el.type = 'text';
      el.value = String(value ?? '');
    }

    if (el && el.tagName !== 'DIV') {
      el.setAttribute('aria-label', String(field.label || field.field_name || ''));
      if (!field.can_edit) el.disabled = true;
    }

    const handler = () => {
      if (!field.can_edit) return;
      let nextVal = '';
      if (type === 'checkbox') {
        nextVal = el.checked ? '1' : '0';
      } else if (useRadioGroup) {
        const checked = el.querySelector('input[type="radio"]:checked');
        nextVal = checked ? checked.value : '';
      } else {
        nextVal = el.value;
      }
      queueSave(field, nextVal);
    };

    if (useRadioGroup) {
      el.addEventListener('change', handler);
    } else if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
      el.addEventListener('blur', handler);
    } else {
      el.addEventListener('change', handler);
    }

    wrapper.appendChild(el);
    if (isTextField(field) && String(field.delegate_other || '').trim() !== '') {
      wrapper.classList.add('has-delegate-other');
      const delegate = document.createElement('div');
      delegate.className = 'pdf-field__delegate-text';
      delegate.textContent = String(field.delegate_other || '');
      wrapper.appendChild(delegate);
    }
    return wrapper;
  }

  function isTextField(field){
    const type = String(field.field_type || '');
    if (['checkbox', 'radio', 'select', 'grade'].includes(type)) return false;
    return true;
  }

  function createStudentInfoIcon(value){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-student-info';
    wrapper.setAttribute('tabindex', '0');
    wrapper.setAttribute('aria-label', 'Schülerwert anzeigen');
    wrapper.textContent = 'i';
    const tooltip = document.createElement('div');
    tooltip.className = 'pdf-student-info__tooltip';
    tooltip.textContent = String(value ?? '');
    wrapper.appendChild(tooltip);
    return wrapper;
  }

  function positionField(wrapper, rect, field){
    const [x1, y1, x2, y2] = rect;
    const left = Math.min(x1, x2);
    const top = Math.min(y1, y2);
    let width = Math.abs(x2 - x1);
    let height = Math.abs(y2 - y1);
    const isRadioLayout = field?.field_type === 'radio' || (['select'].includes(String(field?.field_type || '')) && Array.isArray(field?.options) && field.options.length > 0 && field.options.length <= 10);
    if (isRadioLayout) {
      const count = Array.isArray(field.options) ? field.options.length : 0;
      if (count > 1) {
        const columns = Math.min(4, Math.max(1, count));
        const rows = Math.ceil(count / columns);
        height = Math.max(height, 20 * rows + 12);
      }
    }
    if (['select', 'grade'].includes(String(field?.field_type || ''))) {
      width = Math.max(width, 140);
    }
    if (height < 18) height = 18;
    if (isTextField(field) && String(field.delegate_other || '').trim() !== '') {
      const lines = String(field.delegate_other || '').split(/\r?\n/).length || 1;
      const extra = Math.min(140, 14 * lines + 22);
      height += extra;
      wrapper.style.setProperty('--delegate-height', `${extra}px`);
    }
    wrapper.style.left = `${left}px`;
    wrapper.style.top = `${top}px`;
    wrapper.style.width = `${width}px`;
    wrapper.style.height = `${height}px`;
    const size = Math.max(9, Math.min(14, Math.floor(height * 0.45)));
    wrapper.style.fontSize = `${size}px`;
  }

  function positionWidget(wrapper, rect){
    const [x1, y1, x2, y2] = rect;
    const left = Math.min(x1, x2);
    const top = Math.min(y1, y2);
    const width = Math.abs(x2 - x1);
    const height = Math.abs(y2 - y1);
    wrapper.style.left = `${left}px`;
    wrapper.style.top = `${top}px`;
    wrapper.style.width = `${Math.max(width, 12)}px`;
    wrapper.style.height = `${Math.max(height, 12)}px`;
  }

  function positionInfoIcon(wrapper, rect){
    const [x1, y1, x2, y2] = rect;
    const left = Math.min(x1, x2);
    const top = Math.min(y1, y2);
    const width = Math.abs(x2 - x1);
    const size = 16;
    const offset = 2;
    wrapper.style.left = `${Math.max(left, left + width - size - offset)}px`;
    wrapper.style.top = `${Math.max(0, top - size * 0.4)}px`;
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
        const isChildOnly = Number(field.child_only || 0) === 1;
        const valuesSource = isChildOnly ? state?.values_child : state?.values;
        const value = (valuesSource && field.id in valuesSource) ? valuesSource[field.id] : '';
        const delegateOther = (!isChildOnly && state?.values_delegate_other && field.id in state.values_delegate_other)
          ? state.values_delegate_other[field.id]
          : '';
        const fieldForRender = isChildOnly
          ? { ...field, can_edit: 0, delegate_other: '' }
          : { ...field, delegate_other: delegateOther };
        if (!showStudentValues && isChildOnly) {
          if (isTextField(fieldForRender) && String(value || '').trim() !== '') {
            const rect = Array.isArray(fieldForRender.rect) ? fieldForRender.rect : null;
            if (!rect || rect.length < 4) return;
            const viewRect = viewport.convertToViewportRectangle(rect);
            const icon = createStudentInfoIcon(value);
            positionInfoIcon(icon, viewRect);
            overlay.appendChild(icon);
          }
          return;
        }
        if (!showStudentValues && !isChildOnly && isTextField(fieldForRender)) {
          const childFieldId = Number(field.child_field_id || 0);
          const childValue = childFieldId > 0 && state?.values_child && childFieldId in state.values_child
            ? state.values_child[childFieldId]
            : '';
          if (String(childValue || '').trim() !== '') {
            const rect = Array.isArray(fieldForRender.rect) ? fieldForRender.rect : null;
            if (rect && rect.length >= 4) {
              const viewRect = viewport.convertToViewportRectangle(rect);
              const icon = createStudentInfoIcon(childValue);
              positionInfoIcon(icon, viewRect);
              overlay.appendChild(icon);
            }
          }
        }
        if (shouldUseWidgetRadios(fieldForRender)) {
          const options = Array.isArray(fieldForRender.options) ? fieldForRender.options : [];
          const resolvedValue = resolveOptionValue(fieldForRender, value);
          const widgets = fieldForRender.widget_rects || [];
          const groupName = `pdf-radio-${fieldForRender.id}-${showStudentValues ? 'student' : 'teacher'}`;
          widgets.forEach((widget) => {
            let pageNum = Number(widget.page || 0);
            if (pageNum === 0) pageNum = 1;
            if (pageNum !== p) return;
            const idx = Number(widget.index || 0);
            let optValueRaw = widget.exportValue;
            if (optValueRaw === null || optValueRaw === undefined || String(optValueRaw) === '') {
              optValueRaw = options[idx] ? options[idx].value : '';
            }
            let optValue = resolveOptionValue(fieldForRender, optValueRaw);
            let matched = options.find((opt) => String(opt.value ?? '') === String(optValue ?? ''));
            if (!matched && options[idx]) {
              optValue = String(options[idx].value ?? '');
              matched = options[idx];
            }
            const label = matched ? (matched.label_resolved ?? matched.label ?? matched.value ?? '') : '';
            const rect = Array.isArray(widget.rect) ? widget.rect : null;
            if (!rect || rect.length < 4) return;
            const viewRect = viewport.convertToViewportRectangle(rect);
            const el = createRadioWidget(fieldForRender, optValue, label, resolvedValue, groupName);
            positionWidget(el, viewRect);
            overlay.appendChild(el);
          });
          return;
        }
        const rect = Array.isArray(fieldForRender.rect) ? fieldForRender.rect : null;
        if (!rect || rect.length < 4) return;
        const viewRect = viewport.convertToViewportRectangle(rect);
        const el = createFieldInput(fieldForRender, value);
        positionField(el, viewRect, fieldForRender);
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
      await enrichFieldRectsFromPdf();
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

  if (toggleStudentValues) {
    toggleStudentValues.addEventListener('change', () => {
      showStudentValues = toggleStudentValues.checked;
      renderPages();
    });
  }

  document.addEventListener('keydown', (event) => {
    const key = String(event.key || '').toLowerCase();
    if (!(event.ctrlKey || event.metaKey) || !event.shiftKey || key !== 's') return;
    if (!toggleStudentValues) return;
    event.preventDefault();
    toggleStudentValues.checked = !toggleStudentValues.checked;
    showStudentValues = toggleStudentValues.checked;
    renderPages();
  });

  load();
</script>

<?php render_history_replace_state_script(); ?>
</body>
</html>
