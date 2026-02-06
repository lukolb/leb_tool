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
$delegatedMode = ((int)($_GET['delegated'] ?? 0) === 1);
$cfg = app_config();
$delegationCfg = $cfg['delegation'] ?? [];
$delegationShowOtherFieldsReadonly = (bool)($delegationCfg['show_other_fields_readonly'] ?? false);

if ($classId <= 0 || $studentId <= 0) {
  render_teacher_header(t('teacher.pdf_entry.title'));
  ?>
  <div class="card">
    <div class="alert danger"><strong><?=h(t('teacher.pdf_entry.missing_params'))?></strong></div>
  </div>
  <?php
  render_teacher_footer();
  exit;
}

if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo h(t('teacher.entry.forbidden'));
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
  render_teacher_header(t('teacher.pdf_entry.title'));
  ?>
  <div class="card">
    <div class="alert danger"><strong><?=h(t('teacher.pdf_entry.student_not_found'))?></strong></div>
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

$pageTitle = t('teacher.entry.title');
render_teacher_header($pageTitle);
$studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/de.js" defer></script>

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
    font: 500 12px/1.1 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    pointer-events: auto;
    --radio-color: royalblue;
  }
  .pdf-field.is-readonly:not(.is-student) {
    color: #2b4a77;
    border: none;
    background-color: transparent;
  }
  .pdf-field.is-student {
    --radio-color: forestgreen;
  }
  .pdf-field.is-readonly input {
      background-color: transparent !important;
  }
  .pdf-field.is-readonly:not(.is-student) {
    --radio-color: lightsteelblue;
  }
  .pdf-field.is-system {
    color: #2b4a77;
    border: none;
    background-color: transparent;
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
    margin-top: 0;
    color: #2b4a77;
    font-style: italic;
    white-space: pre-wrap;
    line-height: 1.1;
    font-size: 0.9em;
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
    padding: 1px;
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
    width: 18px;
    height: 18px;
    border-radius: 2px;
    background: aliceblue;
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
    color: forestgreen;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    pointer-events: auto;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    z-index: 100;
  }
  .pdf-student-info__tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translate(-50%, -6px);
    background: forestgreen;
    color: #fff;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 11px;
    line-height: 1.2;
    white-space: pre-wrap;
    max-width: 400px;
    min-width: 200px;
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
  .pdf-student-nav {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
  }
  .pdf-student-nav.left { left: 12px; }
  .pdf-student-nav.right { right: 12px; align-items: end; }
  .pdf-student-nav button {
    pointer-events: auto;
    width: 40px;
    height: 40px;
    border-radius: 999px;
    border: 1px solid #cbd4e1;
    background: #fff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
  }
  .pdf-student-nav button:disabled {
    opacity: 0.4;
    cursor: default;
  }
  .pdf-student-nav-label {
    font-size: 11px;
    color: #2b4a77;
    text-align: center;
  }
</style>

<div class="pdf-entry-wrap">
  <div class="pdf-student-nav left">
    <button type="button" id="prevStudentBtn" title="<?=h(t('teacher.pdf_entry.prev_student'))?>" disabled>‹</button>
    <div class="pdf-student-nav-label" id="prevStudentLabel"></div>
  </div>
  <div class="pdf-student-nav right">
    <button type="button" id="nextStudentBtn" title="<?=h(t('teacher.pdf_entry.next_student'))?>" disabled>›</button>
    <div class="pdf-student-nav-label" id="nextStudentLabel"></div>
  </div>
  <div class="card">
    <div class="row-actions" style="float: right;">
      <a class="btn secondary" href="<?=h(url('teacher/entry.php?class_id=' . (int)$classId . ($delegatedMode ? '&delegated=1' : '')))?>"><?=h(t('teacher.pdf_entry.back_to_entry'))?></a>
    </div>
    <h1 style="margin-bottom:4px;"><?=h(t('teacher.entry.heading_fill'))?></h1>
    <div class="muted">
      <?=h($studentName)?> · <?=h((string)($student['school_year'] ?? ''))?> · <?=h(pdf_entry_class_display($student))?>
    </div>
    <div class="muted" style="margin-top:6px;"><?=h(t('teacher.pdf_entry.autosave_hint'))?></div>
    <div style="margin-top:8px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <label class="toggle-switch" title="<?=h(t('teacher.entry.show_student_values_hint'))?>">
        <input type="checkbox" id="toggleStudentValues" />
        <span class="toggle-slider" aria-hidden="true"></span>
        <span class="toggle-label"><?=h(t('teacher.entry.show_student_values'))?></span>
      </label>
      <label class="toggle-switch">
        <input type="checkbox" id="toggleStudentEdit" />
        <span class="toggle-slider" aria-hidden="true"></span>
        <span class="toggle-label"><?=h(t('teacher.entry.edit_student_values'))?></span>
      </label>
      <?php if ($delegatedMode && $delegationShowOtherFieldsReadonly): ?>
        <label class="toggle-switch">
          <input type="checkbox" id="toggleDelegationOtherFields" />
          <span class="toggle-slider" aria-hidden="true"></span>
          <span class="toggle-label"><?=h(t('teacher.entry.delegation_show_other_fields'))?></span>
        </label>
      <?php endif; ?>
      <span class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> <?=h(t('teacher.entry.save_status_saving'))?></span>
      <div class="save-status" id="saveStatus" aria-live="polite" style="display:none;"></div>
    </div>
    <div id="studentEditWarning" class="alert danger" style="display:none; margin-top:10px;">
      <?=h(t('teacher.entry.edit_student_values_warning'))?>
    </div>
  </div>

  <div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>

  <div id="pdfEntryPreview" class="card" style="background:#f8f9fb; border:1px solid var(--border); min-height:120px;">
    <div class="pdf-loader" aria-label="<?=h(t('ui.loading'))?>" role="status">
      <span class="spinner"></span>
      <span class="txt"><?=h(t('teacher.pdf_entry.loading'))?></span>
    </div>
  </div>
</div>

<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

  const apiUrl = <?=json_encode(url('teacher/ajax/entry_api.php'))?>;
  const classId = <?=json_encode($classId)?>;
  const studentId = <?=json_encode($studentId)?>;
  const DELEGATED_MODE = <?= $delegatedMode ? 'true' : 'false' ?>;
  const DELEGATED_READONLY_VISIBLE = <?= $delegationShowOtherFieldsReadonly ? 'true' : 'false' ?>;
  const DELEGATION_OTHER_FIELDS_KEY = 'delegation_show_other_fields';
  const csrf = <?=json_encode(csrf_token())?>;
  const UI_LANG = <?=json_encode(ui_lang())?>;
  const I18N = <?=json_encode([
    'prev_student' => t('teacher.pdf_entry.prev_student'),
    'next_student' => t('teacher.pdf_entry.next_student'),
    'prev_student_with_name' => t('teacher.pdf_entry.prev_student_with_name'),
    'next_student_with_name' => t('teacher.pdf_entry.next_student_with_name'),
    'student_value_aria' => t('teacher.pdf_entry.student_value_aria'),
    'save_success' => t('teacher.pdf_entry.save_success'),
    'save_failed' => t('teacher.pdf_entry.save_failed'),
    'ready' => t('teacher.pdf_entry.ready'),
    'load_failed' => t('teacher.pdf_entry.load_failed'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
  const tPdf = (key) => I18N[key] ?? key;
  const tfmtPdf = (key, vars = {}) => {
    const base = tPdf(key);
    return base.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
  };

  const preview = document.getElementById('pdfEntryPreview');
  const errBox = document.getElementById('errBox');
  const errMsg = document.getElementById('errMsg');
  const savePill = document.getElementById('savePill');
  const saveStatus = document.getElementById('saveStatus');
  const toggleStudentValues = document.getElementById('toggleStudentValues');
  const toggleStudentEdit = document.getElementById('toggleStudentEdit');
  const toggleDelegationOtherFields = document.getElementById('toggleDelegationOtherFields');
  const toggleStudentEditWrap = toggleStudentEdit ? toggleStudentEdit.closest('label') : null;
  const studentEditWarning = document.getElementById('studentEditWarning');
  const studentEditConfirmText = <?=json_encode(t('teacher.entry.edit_student_values_confirm'))?>;
  const prevStudentBtn = document.getElementById('prevStudentBtn');
  const nextStudentBtn = document.getElementById('nextStudentBtn');
  const prevStudentLabel = document.getElementById('prevStudentLabel');
  const nextStudentLabel = document.getElementById('nextStudentLabel');
  const prefStorageKey = 'pdf_entry_prefs';

  let pdfDoc = null;
  let state = null;
  let renderToken = 0;
  const saveTimers = new Map();
  const pendingSaves = new Map();
  let activeSaves = 0;
  let renderTimer = null;
  let showStudentValues = false;
  let allowStudentEdit = false;
  let isDelegatedView = false;
  let delegatedShowOtherFields = DELEGATED_READONLY_VISIBLE;

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

  function loadPrefs(){
    try {
      const raw = window.localStorage.getItem(prefStorageKey);
      if (!raw) return { showStudentValues: false, allowStudentEdit: false };
      const parsed = JSON.parse(raw);
      return {
        showStudentValues: Boolean(parsed?.showStudentValues),
        allowStudentEdit: Boolean(parsed?.allowStudentEdit),
      };
    } catch {
      return { showStudentValues: false, allowStudentEdit: false };
    }
  }

  function savePrefs(){
    try {
      window.localStorage.setItem(prefStorageKey, JSON.stringify({
        showStudentValues,
        allowStudentEdit,
      }));
    } catch {
      // ignore storage errors
    }
  }

  function initDelegationFieldToggle(){
    if (!DELEGATED_MODE || !DELEGATED_READONLY_VISIBLE || !toggleDelegationOtherFields) return;
    const stored = window.localStorage.getItem(DELEGATION_OTHER_FIELDS_KEY);
    if (stored !== null) delegatedShowOtherFields = stored === '1';
    toggleDelegationOtherFields.checked = delegatedShowOtherFields;
    toggleDelegationOtherFields.addEventListener('change', () => {
      delegatedShowOtherFields = toggleDelegationOtherFields.checked;
      window.localStorage.setItem(DELEGATION_OTHER_FIELDS_KEY, delegatedShowOtherFields ? '1' : '0');
      applyDelegationFieldVisibility();
      renderPages({ preserveScroll: true });
    });
  }

  function applyDelegationFieldVisibility(){
    if (!state) return;
    if (!DELEGATED_MODE || !DELEGATED_READONLY_VISIBLE) {
      state.fields = state.all_fields || state.fields || [];
      return;
    }
    const fields = state.all_fields || state.fields || [];
    if (delegatedShowOtherFields) {
      state.fields = fields;
      return;
    }
    state.fields = fields.filter((field) => {
      if (Number(field.child_only || 0) === 1) return true;
      return Number(field.can_edit || 0) === 1;
    });
  }

  function setSaving(active){
    if (!savePill) return;
    savePill.style.display = active ? '' : 'none';
  }

  function goToStudent(nextId){
    if (!nextId) return;
    const url = new URL(window.location.href);
    url.searchParams.set('student_id', String(nextId));
    url.searchParams.set('class_id', String(classId));
    window.location.href = url.toString();
  }

  function updateStudentNav(){
    const nav = state?.student_nav || {};
    if (prevStudentBtn) {
      const hasPrev = Boolean(nav.prev_id);
      prevStudentBtn.disabled = !hasPrev;
      prevStudentBtn.title = hasPrev
        ? tfmtPdf('prev_student_with_name', { name: nav.prev_name || '' })
        : tPdf('prev_student');
      if (prevStudentLabel) prevStudentLabel.textContent = hasPrev ? (nav.prev_name || '') : '';
      prevStudentBtn.onclick = () => goToStudent(nav.prev_id);
    }
    if (nextStudentBtn) {
      const hasNext = Boolean(nav.next_id);
      nextStudentBtn.disabled = !hasNext;
      nextStudentBtn.title = hasNext
        ? tfmtPdf('next_student_with_name', { name: nav.next_name || '' })
        : tPdf('next_student');
      if (nextStudentLabel) nextStudentLabel.textContent = hasNext ? (nav.next_name || '') : '';
      nextStudentBtn.onclick = () => goToStudent(nav.next_id);
    }
  }

  async function api(action, payload, options = {}){
    const delegated = DELEGATED_MODE ? { delegated: 1 } : {};
    const body = { action, csrf_token: csrf, ...delegated, ...payload };
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
      ...options
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  function queryRadioGroupInputs(name){
    if (!name) return [];
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
      return Array.from(document.querySelectorAll(`input[name="${CSS.escape(name)}"]`));
    }
    const safe = String(name).replace(/"/g, '\\"');
    return Array.from(document.querySelectorAll(`input[name="${safe}"]`));
  }

  function flatpickrFormatFromPattern(pattern){
    if (!pattern) return '';
    const map = {
      'MMMM': 'F',
      'MMM': 'M',
      'YYYY': 'Y',
      'YY': 'y',
      'DD': 'd',
      'D': 'j',
      'MM': 'm',
      'M': 'n'
    };
    return pattern.replace(/MMMM|MMM|YYYY|YY|DD|MM|D|M/g, (tok) => map[tok] || tok);
  }

  function initDatePicker(el, field){
    if (!el || !field || !field.can_edit) return;
    if (typeof window.flatpickr !== 'function') return;
    const pattern = String(field.date_format || '').trim();
    const altFormat = pattern ? flatpickrFormatFromPattern(pattern) : '';
    const locale = (UI_LANG === 'de' && window.flatpickr?.l10ns?.de)
      ? window.flatpickr.l10ns.de
      : undefined;
    window.flatpickr(el, {
      dateFormat: 'Y-m-d',
      altInput: altFormat !== '' && altFormat !== 'Y-m-d',
      altFormat: altFormat || 'Y-m-d',
      locale,
      allowInput: true,
      onChange: (selectedDates, dateStr, instance) => {
        const next = selectedDates && selectedDates.length
          ? instance.formatDate(selectedDates[0], 'Y-m-d')
          : '';
        queueSave(field, next);
      },
      onClose: (selectedDates, dateStr, instance) => {
        const next = selectedDates && selectedDates.length
          ? instance.formatDate(selectedDates[0], 'Y-m-d')
          : (dateStr || '');
        queueSave(field, next);
      }
    });
  }

  function updateRadioWasChecked(groupName, active){
    const inputs = queryRadioGroupInputs(groupName);
    inputs.forEach((el) => {
      el.dataset.waschecked = (active && el === active && el.checked) ? '1' : '0';
    });
  }

  function resetRadioWasChecked(groupName){
    const inputs = queryRadioGroupInputs(groupName);
    inputs.forEach((el) => {
      el.dataset.waschecked = '0';
    });
  }

  function bindRadioToggle(input, field, groupName){
    input.dataset.waschecked = input.checked ? '1' : '0';
    input.addEventListener('click', () => {
      if (!field.can_edit) return;
      if (input.checked && input.dataset.waschecked === '1') {
        input.checked = false;
        input.dataset.waschecked = '0';
        resetRadioWasChecked(groupName);
        queueSave(field, '');
        return;
      }
      if (input.checked) {
        updateRadioWasChecked(groupName, input);
        queueSave(field, input.value);
      }
    });
    input.addEventListener('change', () => {
      if (!field.can_edit) return;
      if (input.checked) {
        updateRadioWasChecked(groupName, input);
        queueSave(field, input.value);
      }
    });
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
    const baseFields = state.all_fields || state.fields || [];
    const enriched = baseFields.map((f) => {
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
    state.all_fields = enriched;
    applyDelegationFieldVisibility();

    if (updated) {
      await renderPages();
    }
  }

  function resolveOptionValue(field, rawValue){
    const options = Array.isArray(field.options) ? field.options : [];
    const valueText = String(rawValue ?? '');
    if (valueText.trim().toLowerCase() === 'off') return '';
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

  function canEditPdfField(field){
    const isChildOnly = Number(field.child_only || 0) === 1;
    if (DELEGATED_MODE && isChildOnly) return false;
    return !!field.can_edit;
  }

  function createRadioWidget(field, optionValue, optionLabel, currentValue, groupName){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-field pdf-field--widget';
    const canEdit = canEditPdfField(field);
    if (!canEdit) wrapper.classList.add('is-readonly');
    if (Number(field.child_only || 0) === 1) wrapper.classList.add('is-student');
    if (Number(field.system_bound || 0) === 1) wrapper.classList.add('is-system');

    const input = document.createElement('input');
    input.type = 'radio';
    input.name = groupName;
    input.value = String(optionValue ?? '');
    input.checked = String(currentValue ?? '') === String(optionValue ?? '');
    input.setAttribute('aria-label', String(optionLabel || field.label || field.field_name || ''));
    if (!canEdit) input.disabled = true;
    bindRadioToggle(input, field, groupName);

    wrapper.appendChild(input);
    return wrapper;
  }

  function createFieldInput(field, value){
    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-field';
    const canEdit = canEditPdfField(field);
    if (!canEdit) wrapper.classList.add('is-readonly');
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
        if (!canEdit) input.disabled = true;
        bindRadioToggle(input, field, name);
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
    } else if (type === 'date') {
      el = document.createElement('input');
      if (field.can_edit) {
        el.type = 'text';
        el.value = String(value ?? '');
      } else {
        el.type = 'text';
        el.value = String(field.date_display ?? value ?? '');
      }
    } else {
      el = document.createElement('input');
      el.type = 'text';
      el.value = String(value ?? '');
    }

    if (el && el.tagName !== 'DIV') {
      el.setAttribute('aria-label', String(field.label || field.field_name || ''));
      if (!canEdit) el.disabled = true;
    }

    const handler = () => {
      if (!canEdit) return;
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

    if (!useRadioGroup && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') && type !== 'checkbox') {
      el.addEventListener('input', handler);
    } else if (!useRadioGroup) {
      el.addEventListener('change', handler);
    }

    wrapper.appendChild(el);
    if (type === 'date' && canEdit) {
      initDatePicker(el, field);
    }
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
    wrapper.setAttribute('aria-label', tPdf('student_value_aria'));
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
      width = Math.max(width, 45);
    }
    if (height < 18) height = 18;
    if (isTextField(field) && String(field.delegate_other || '').trim() !== '') {
      const lines = String(field.delegate_other || '').split(/\r?\n/).length || 1;
      const extra = Math.min(28, 9 * lines + 2);
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

  async function renderPages(opts = {}){
    if (!pdfDoc || !state || !preview) return;
    const preserveScroll = opts?.preserveScroll === true;
    const scrollY = preserveScroll ? window.scrollY : null;
    const scrollX = preserveScroll ? window.scrollX : null;
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
        const displaySource = isChildOnly ? state?.values_child_display : state?.values_display;
        const value = (valuesSource && field.id in valuesSource) ? valuesSource[field.id] : '';
        const displayValue = (displaySource && field.id in displaySource) ? displaySource[field.id] : value;
        const delegateOther = (!isChildOnly && state?.values_delegate_other && field.id in state.values_delegate_other)
          ? state.values_delegate_other[field.id]
          : '';
        const delegateDisplay = (!isChildOnly && state?.values_delegate_other_display && field.id in state.values_delegate_other_display)
          ? state.values_delegate_other_display[field.id]
          : delegateOther;
        const fieldForRender = isChildOnly
          ? {
            ...field,
            can_edit: allowStudentEdit ? 1 : 0,
            delegate_other: '',
            date_display: displayValue,
            value
          }
          : { ...field, delegate_other: delegateDisplay, date_display: displayValue, value };
        if (!showStudentValues && isChildOnly) {
          if (isTextField(fieldForRender) && String(displayValue || '').trim() !== '') {
            const rect = Array.isArray(fieldForRender.rect) ? fieldForRender.rect : null;
            if (!rect || rect.length < 4) return;
            const viewRect = viewport.convertToViewportRectangle(rect);
            const icon = createStudentInfoIcon(displayValue);
            positionInfoIcon(icon, viewRect);
            overlay.appendChild(icon);
          }
          return;
        }
        if (showStudentValues && !isChildOnly && isTextField(fieldForRender)) {
          const childFieldId = Number(field.child_field_id || 0);
          const childValue = childFieldId > 0 && state?.values_child_display && childFieldId in state.values_child_display
            ? state.values_child_display[childFieldId]
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
          const widgets = (fieldForRender.widget_rects || [])
            .map((widget) => {
              let pageNum = Number(widget.page || 0);
              if (pageNum === 0) pageNum = 1;
              return { ...widget, pageNum };
            })
            .filter((widget) => widget.pageNum === p)
            .sort((a, b) => {
              const rectA = Array.isArray(a.rect) ? a.rect : [0, 0, 0, 0];
              const rectB = Array.isArray(b.rect) ? b.rect : [0, 0, 0, 0];
              const yA = Math.min(rectA[1] ?? 0, rectA[3] ?? 0);
              const yB = Math.min(rectB[1] ?? 0, rectB[3] ?? 0);
              if (yA !== yB) return yA - yB;
              const xA = Math.min(rectA[0] ?? 0, rectA[2] ?? 0);
              const xB = Math.min(rectB[0] ?? 0, rectB[2] ?? 0);
              return xA - xB;
            });
          const groupName = `pdf-radio-${fieldForRender.id}-${showStudentValues ? 'student' : 'teacher'}`;
          widgets.forEach((widget, idx) => {
            let optValueRaw = widget.exportValue;
            if (
              optValueRaw === null
              || optValueRaw === undefined
              || String(optValueRaw).trim() === ''
              || String(optValueRaw).trim().toLowerCase() === 'off'
            ) {
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
    if (preserveScroll && token === renderToken) {
      requestAnimationFrame(() => window.scrollTo(scrollX ?? 0, scrollY ?? 0));
    }
  }

  function saveKeyForField(field){
    const isChildOnly = Number(field.child_only || 0) === 1;
    const scope = String(field.scope || 'student');
    return `${field.id}:${isChildOnly ? 'child' : scope}`;
  }

  function updateSavingIndicator(){
    setSaving(pendingSaves.size > 0 || activeSaves > 0);
  }

  function queueSave(field, value){
    if (!state) return;
    if (!canEditPdfField(field)) return;
    const isChildOnly = Number(field.child_only || 0) === 1;
    const target = isChildOnly ? (state.values_child = state.values_child || {}) : (state.values = state.values || {});
    target[field.id] = value;
    const key = saveKeyForField(field);
    pendingSaves.set(key, { field, value });
    updateSavingIndicator();
    if (saveTimers.has(key)) {
      clearTimeout(saveTimers.get(key));
      saveTimers.delete(key);
    }
    saveTimers.set(key, setTimeout(() => flushSave(key), 250));
  }

  function flushSave(key, options = {}){
    if (!pendingSaves.has(key)) return;
    const item = pendingSaves.get(key);
    pendingSaves.delete(key);
    if (saveTimers.has(key)) {
      clearTimeout(saveTimers.get(key));
      saveTimers.delete(key);
    }
    if (!item) return;
    saveField(item.field, item.value, options);
  }

  async function saveField(field, value, options = {}){
    activeSaves += 1;
    updateSavingIndicator();
    try {
      if (!state) return;
      if (!canEditPdfField(field)) {
        showSaveStatus(tPdf('save_failed'), true);
        return;
      }
      if (Number(field.child_only || 0) === 1) {
        const res = await api('child_value_update', {
          report_instance_id: state.student.report_instance_id,
          child_field_id: field.id,
          value_text: value
        }, options);
        state.values_child = state.values_child || {};
        state.values_child[field.id] = res?.raw_value_text ?? value;
        state.values_child_display = state.values_child_display || {};
        if (Object.prototype.hasOwnProperty.call(res || {}, 'value_text')) {
          state.values_child_display[field.id] = res.value_text;
        }
      } else if (field.scope === 'class') {
        await api('save_class', {
          class_id: classId,
          report_instance_id: state.class_report_instance_id,
          template_field_id: field.id,
          value_text: value
        }, options);
      } else {
        await api('save', {
          report_instance_id: state.student.report_instance_id,
          template_field_id: field.id,
          value_text: value
        }, options);
      }
      showSaveStatus(tPdf('save_success'));
    } catch (e) {
      showSaveStatus(e?.message || tPdf('save_failed'), true);
    } finally {
      activeSaves = Math.max(0, activeSaves - 1);
      updateSavingIndicator();
    }
  }

  async function load(){
    try {
      const data = await api('load_pdf', { class_id: classId, student_id: studentId });
      state = data;
      isDelegatedView = Boolean(data?.delegated_view);
      if (state && Array.isArray(state.fields)) {
        state.all_fields = state.fields.slice();
      }
      applyDelegationFieldVisibility();
      if (isDelegatedView) {
        allowStudentEdit = false;
        if (toggleStudentEdit) {
          toggleStudentEdit.checked = false;
          toggleStudentEdit.disabled = true;
        }
        if (toggleStudentEditWrap) toggleStudentEditWrap.style.display = 'none';
        if (studentEditWarning) studentEditWarning.style.display = 'none';
      }
      pdfDoc = await pdfjsLib.getDocument({ url: data.template.pdf_url, withCredentials: true }).promise;
      await renderPages();
      await enrichFieldRectsFromPdf();
      updateStudentNav();
      showSaveStatus(tPdf('ready'));
    } catch (e) {
      showError(e?.message || tPdf('load_failed'));
    }
  }

  window.addEventListener('resize', () => {
    if (!pdfDoc) return;
    if (renderTimer) clearTimeout(renderTimer);
    renderTimer = setTimeout(() => renderPages(), 120);
  });

  function flushPendingSaves(options = {}){
    if (!pendingSaves.size) return;
    const keys = Array.from(pendingSaves.keys());
    keys.forEach((key) => flushSave(key, options));
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      flushPendingSaves();
    }
  });

  window.addEventListener('beforeunload', () => {
    flushPendingSaves({ keepalive: true });
  });

  if (toggleStudentValues) {
    toggleStudentValues.addEventListener('change', () => {
      showStudentValues = toggleStudentValues.checked;
      if (!showStudentValues) {
        allowStudentEdit = false;
        if (toggleStudentEdit) toggleStudentEdit.checked = false;
        if (studentEditWarning) studentEditWarning.style.display = 'none';
      }
      savePrefs();
      renderPages({ preserveScroll: true });
    });
  }

  if (toggleStudentEdit) {
    toggleStudentEdit.addEventListener('change', () => {
      if (isDelegatedView) {
        toggleStudentEdit.checked = false;
        allowStudentEdit = false;
        if (studentEditWarning) studentEditWarning.style.display = 'none';
        return;
      }
      const wasShowStudentValues = showStudentValues;
      if (toggleStudentEdit.checked) {
        if (toggleStudentValues && !toggleStudentValues.checked) {
          toggleStudentValues.checked = true;
          showStudentValues = true;
        }
        const ok = window.confirm(studentEditConfirmText);
        if (!ok) {
          toggleStudentEdit.checked = false;
          showStudentValues = wasShowStudentValues;
          if (toggleStudentValues) toggleStudentValues.checked = showStudentValues;
          if (studentEditWarning) studentEditWarning.style.display = 'none';
          savePrefs();
          renderPages({ preserveScroll: true });
          return;
        }
        allowStudentEdit = true;
        if (studentEditWarning) studentEditWarning.style.display = '';
      } else {
        allowStudentEdit = false;
        if (studentEditWarning) studentEditWarning.style.display = 'none';
      }
      savePrefs();
      renderPages({ preserveScroll: true });
    });
  }

  const prefs = loadPrefs();
  if (prefs.allowStudentEdit) {
    showStudentValues = true;
    allowStudentEdit = true;
    if (toggleStudentValues) toggleStudentValues.checked = true;
    if (toggleStudentEdit) toggleStudentEdit.checked = true;
    if (studentEditWarning) studentEditWarning.style.display = '';
  } else if (prefs.showStudentValues) {
    showStudentValues = true;
    if (toggleStudentValues) toggleStudentValues.checked = true;
  }

  initDelegationFieldToggle();

  document.addEventListener('keydown', (event) => {
  if (!event.altKey) return;

  const active = document.activeElement;
  if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) return;

  if (event.key === 'ArrowLeft') {
    event.preventDefault();
    goToStudent(state?.student_nav?.prev_id);
  } else if (event.key === 'ArrowRight') {
    event.preventDefault();
    goToStudent(state?.student_nav?.next_id);
  } else if (event.code === 'KeyS') {
    event.preventDefault();
    if (!toggleStudentValues) return;
    toggleStudentValues.checked = !toggleStudentValues.checked;
    showStudentValues = toggleStudentValues.checked;
    if (!showStudentValues) {
      allowStudentEdit = false;
      if (toggleStudentEdit) toggleStudentEdit.checked = false;
      if (studentEditWarning) studentEditWarning.style.display = 'none';
    }
    savePrefs();
    renderPages({ preserveScroll: true });
  }
}, { capture: true });


  load();
</script>

<?php render_history_replace_state_script(); ?>
</body>
</html>
