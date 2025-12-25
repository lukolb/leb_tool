<?php
// teacher/entry.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classId = (int)($_GET['class_id'] ?? 0);

if (($u['role'] ?? '') === 'admin') {
  $st = $pdo->query("SELECT id, school_year, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name
     FROM classes c
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? AND c.is_active=1
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

if ($classId <= 0 && $classes) {
  $classId = (int)($classes[0]['id'] ?? 0);
}

if ($classId > 0 && ($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo '403 Forbidden';
  exit;
}

render_teacher_header('Eingaben');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('teacher/index.php'))?>">← Übersicht</a>
  </div>

  <h1 style="margin-top:0;">Eingaben ausfüllen</h1>
  <p class="muted" style="margin-top:-6px;">
    Tipps: <strong>Tab</strong> zum schnellen Springen · <strong>Shift+Tab</strong> zurück ·
    <strong>Alt+S</strong> Schülereingaben ein/aus · <strong>Alt+M</strong> Ansicht wechseln
  </p>

  <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div style="min-width:260px;">
      <label class="label">Klasse</label>
      <select class="input" id="classSelect" style="width:100%;">
        <?php foreach ($classes as $c): $id = (int)$c['id']; ?>
          <option value="<?=h((string)$id)?>" <?= $id===$classId ? 'selected' : '' ?>><?=h((string)$c['school_year'] . ' · ' . class_display($c))?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="min-width:260px;">
      <label class="label">Ansicht</label>
      <select class="input" id="viewSelect" style="width:100%;">
        <option value="grades">Notenübersicht</option>
        <option value="student">Nach Schüler:in</option>
        <option value="item">Nach Item/Fach</option>
      </select>
    </div>

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <label class="pill-mini" style="cursor:pointer; user-select:none;">
        <input type="checkbox" id="toggleChild" style="margin-right:8px;"> Schülereingaben anzeigen
      </label>
      <span class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> Speichern…</span>
    </div>
  </div>
</div>

<div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>

<div id="app" class="card" style="display:none;">
  <div id="metaTop" class="muted" style="margin-bottom:10px;">Lade…</div>

  <!-- Grades view -->
  <div id="viewGrades" style="display:none;">
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label class="label">Fach/Gruppe</label>
        <select class="input" id="gradeGroupSelect" style="width:100%;"></select>
      </div>

      <!-- NEW: orientation toggle -->
      <div style="min-width:260px;">
        <label class="label">Tabelle</label>
        <select class="input" id="gradeOrientation" style="width:100%;">
          <option value="students_rows">Schüler: Zeilen · Notenfelder: Spalten</option>
          <option value="students_cols">Notenfelder: Zeilen · Schüler: Spalten</option>
        </select>
      </div>

      <div style="min-width:220px;">
        <label class="label">Suche</label>
        <input class="input" id="gradeSearch" type="search" placeholder="Notenfeld…" style="width:100%;">
      </div>
      <div class="muted" style="padding-bottom:10px;">
        Nur <strong>Notenfelder</strong>. Tab springt durch die Zellen.
      </div>
    </div>

    <div style="overflow:auto; margin-top:12px; border:1px solid var(--border); border-radius:12px;">
      <table class="table" id="gradeTable" style="margin:0;">
        <thead id="gradeHead"></thead>
        <tbody id="gradeBody"></tbody>
      </table>
    </div>
  </div>

  <!-- Student view -->
  <div id="viewStudent" style="display:none;">
    <div style="display:grid; grid-template-columns: 300px 1fr; gap:12px; align-items:start;">
      <div style="position:sticky; top:14px; align-self:start;">
        <div style="display:flex; gap:8px; align-items:center;">
          <input class="input" id="studentSearch" type="search" placeholder="Schüler suchen…" style="width:100%;">
        </div>
        <div id="studentList" style="margin-top:10px; display:flex; flex-direction:column; gap:8px;"></div>
      </div>
      <div>
        <div class="row-actions" style="justify-content:space-between;">
          <div class="pill-mini" id="studentBadge">—</div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn secondary" type="button" id="btnPrevStudent">← Vorherige</button>
            <button class="btn secondary" type="button" id="btnNextStudent">Nächste →</button>
          </div>
        </div>

        <div id="studentForm"></div>
      </div>
    </div>
  </div>

  <!-- Item view -->
  <div id="viewItem" style="display:none;">
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label class="label">Fach/Gruppe</label>
        <select class="input" id="groupSelect" style="width:100%;"></select>
      </div>
      <div style="min-width:220px;">
        <label class="label">Suche</label>
        <input class="input" id="itemSearch" type="search" placeholder="Item / Feldname…" style="width:100%;">
      </div>
      <div class="muted" id="itemHint" style="padding-bottom:10px;">Tab springt durch die Zellen (Zeile → Spalten).</div>
    </div>

    <div style="overflow:auto; margin-top:12px; border:1px solid var(--border); border-radius:12px;">
      <table class="table" id="itemTable" style="margin:0;">
        <thead id="itemHead"></thead>
        <tbody id="itemBody"></tbody>
      </table>
    </div>
  </div>
</div>

<style>
  .spin{ width:16px; height:16px; border-radius:999px; border:2px solid rgba(0,0,0,0.15); border-top-color: rgba(0,0,0,0.65); display:inline-block; animation: s 0.8s linear infinite; }
  @keyframes s{ to{ transform: rotate(360deg); } }
  .srow{ border:1px solid var(--border); border-radius:14px; padding:10px; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .srow:hover{ background: rgba(0,0,0,0.02); }
  .srow.active{ outline:2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
  .smeta{ display:flex; flex-direction:column; gap:2px; min-width:0; }
  .smeta .n{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .smeta .sub{ color:var(--muted); font-size:12px; }

  .field{ border:1px solid var(--border); border-radius:14px; padding:12px; background:#fff; margin-bottom:10px; }
  .field .lbl{ font-weight:800; }
  .field .help{ color:var(--muted); font-size:12px; margin-top:6px; }
  .field .child{ display:none; margin-top:8px; border-top:1px dashed var(--border); padding-top:8px; color:var(--muted); font-size:12px; }
  .field .child strong{ color: rgba(0,0,0,0.75); }
  .field.show-child .child{ display:block; }

  /* === TABLES: compact, content-based sizing + sticky headers === */
  #itemTable, #gradeTable { table-layout: auto; width: max-content; }
  #itemTable th, #itemTable td, #gradeTable th, #gradeTable td { vertical-align: top; }

  /* sticky first column only as wide as needed (cap) */
  #itemTable th.sticky, #itemTable td.sticky,
  #gradeTable th.sticky, #gradeTable td.sticky{
    position:sticky; left:0; background:#fff; z-index:2;
    min-width: 220px; max-width: 320px;
  }

  /* sticky header */
  #itemTable thead th, #gradeTable thead th{ position:sticky; top:0; background:#fff; z-index:3; }
  #itemTable thead th.sticky, #gradeTable thead th.sticky{ z-index:4; }

  /* default non-sticky cells compact */
  #itemTable th:not(.sticky), #itemTable td:not(.sticky),
  #gradeTable th:not(.sticky), #gradeTable td:not(.sticky){
    /*width: 1px; /* allow shrink-to-fit with max-content table */
    max-width: 260px;
  }

  /* compact grade inputs */
  .gradeInput{
    width: 6ch;
    max-width: 8ch;
    padding: 6px 8px;
  }

  .cellWrap{ display:flex; flex-direction:column; gap:6px; }
  .cellChild{ display:none; padding:6px 8px; border:1px dashed var(--border); border-radius:10px; color:var(--muted); font-size:12px; background: rgba(0,0,0,0.02); }
  .show-child .cellChild{ display:block; }

  .missing{ outline:2px solid rgba(200,20,20,0.12); background: rgba(200,20,20,0.04); border-radius:12px; padding:4px; }
</style>

<script>
(function(){
  const apiUrl = <?=json_encode(url('teacher/ajax/entry_api.php'))?>;
  const csrf = <?=json_encode(csrf_token())?>;

  const elApp = document.getElementById('app');
  const elErrBox = document.getElementById('errBox');
  const elErrMsg = document.getElementById('errMsg');
  const elMetaTop = document.getElementById('metaTop');
  const elSavePill = document.getElementById('savePill');

  const classSelect = document.getElementById('classSelect');
  const viewSelect = document.getElementById('viewSelect');
  const toggleChild = document.getElementById('toggleChild');

  const viewGrades = document.getElementById('viewGrades');
  const viewStudent = document.getElementById('viewStudent');
  const viewItem = document.getElementById('viewItem');

  const gradeGroupSelect = document.getElementById('gradeGroupSelect');
  const gradeOrientation = document.getElementById('gradeOrientation'); // NEW
  const gradeSearch = document.getElementById('gradeSearch');
  const gradeHead = document.getElementById('gradeHead');
  const gradeBody = document.getElementById('gradeBody');

  const studentSearch = document.getElementById('studentSearch');
  const studentList = document.getElementById('studentList');
  const studentForm = document.getElementById('studentForm');
  const studentBadge = document.getElementById('studentBadge');
  const btnPrevStudent = document.getElementById('btnPrevStudent');
  const btnNextStudent = document.getElementById('btnNextStudent');

  const groupSelect = document.getElementById('groupSelect');
  const itemSearch = document.getElementById('itemSearch');
  const itemHead = document.getElementById('itemHead');
  const itemBody = document.getElementById('itemBody');

  let state = {
    class_id: 0,
    template: null,
    groups: [],
    students: [],
    values_teacher: {},
    values_child: {},
    fieldMap: {},
  };

  let ui = {
    view: 'grades',
    showChild: false,
    activeStudentIndex: 0,
    studentFilter: '',
    groupKey: 'ALL',
    itemFilter: '',
    gradeGroupKey: 'ALL',
    gradeFilter: '',
    gradeOrientation: localStorage.getItem('leb_grade_orientation') || 'students_rows', // NEW
    saveTimers: new Map(),
    saveInFlight: 0,
  };

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function normalize(s){ return String(s ?? '').toLowerCase().trim(); }

  function rebuildFieldMap(){
    const map = {};
    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => { map[String(f.id)] = f; });
    });
    state.fieldMap = map;
  }

  function ensureDatalistForField(fieldId){
    const f = state.fieldMap[String(fieldId)];
    if (!f) return;
    const type = String(f.field_type || '');
    if (!(type === 'radio' || type === 'select' || type === 'grade')) return;
    const dlId = `dl_${String(fieldId)}`;

    let dl = document.getElementById(dlId);
    if (!dl) {
      dl = document.createElement('datalist');
      dl.id = dlId;
      document.body.appendChild(dl);
    }

    const opts = Array.isArray(f.options) ? f.options : [];
    // Build suggestions for both VALUE and LABEL so users can type either.
    const items = [];
    opts.forEach(o => {
      const v = String(o?.value ?? '').trim();
      const l = String(o?.label ?? '').trim();
      if (v) items.push({ value: v, label: l || v });
      if (l && l !== v) items.push({ value: l, label: l });
    });

    dl.innerHTML = '';
    items.forEach(it => {
      const op = document.createElement('option');
      op.value = it.value;
      op.textContent = it.label;
      dl.appendChild(op);
    });
  }

  function resolveTypedToValue(f, typed){
    const t = String(typed ?? '').trim();
    if (!t) return '';
    const opts = Array.isArray(f?.options) ? f.options : [];
    const hitV = opts.find(o => String(o?.value ?? '') === t);
    if (hitV) return String(hitV.value ?? t);
    const low = t.toLowerCase();
    const hitL = opts.find(o => String(o?.label ?? '').toLowerCase() === low);
    if (hitL) return String(hitL.value ?? t);
    return t;
  }

  function setComboDisplayedValue(inp, f, actualValue){
    const v = String(actualValue ?? '');
    inp.dataset.actual = v;
    inp.value = teacherDisplay(f, v);
  }

  function wireTeacherInputs(rootEl){
    if (!rootEl) return;
    rootEl.querySelectorAll('[data-teacher-input="1"]').forEach(inp => {
      const reportId = Number(inp.getAttribute('data-report-id') || '0');
      const fieldId = Number(inp.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const f = state.fieldMap[String(fieldId)];

      // compact grade input
      if (f && String(f.field_type || '') === 'grade') {
        inp.classList.add('gradeInput');
      }

      if (inp.dataset.combo === '1') {
        ensureDatalistForField(fieldId);

        const actual = String(inp.dataset.actual ?? '');
        if (f) setComboDisplayedValue(inp, f, actual);

        const commit = () => {
          const typed = inp.value;
          const resolved = f ? resolveTypedToValue(f, typed) : String(typed ?? '').trim();
          scheduleSave(reportId, fieldId, resolved);
          if (f) setComboDisplayedValue(inp, f, resolved);
          else inp.dataset.actual = resolved;
        };

        inp.addEventListener('change', commit);
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') { ev.preventDefault(); commit(); inp.blur(); }
        });
        return;
      }

      if (inp.type === 'checkbox') {
        inp.addEventListener('change', () => scheduleSave(reportId, fieldId, inp.checked ? '1' : '0'));
      } else {
        inp.addEventListener('input', () => scheduleSave(reportId, fieldId, inp.value));
      }

      inp.addEventListener('focus', () => {
        const wrap = inp.closest('.field');
        if (wrap) wrap.scrollIntoView({block:'nearest'});
      });
    });
  }

  async function api(action, payload){
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, csrf_token: csrf, ...payload })
    });
    const j = await res.json().catch(()=>null);
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Fehler');
    return j;
  }

  function showErr(msg){
    elErrMsg.textContent = msg;
    elErrBox.style.display = 'block';
  }
  function clearErr(){
    elErrBox.style.display = 'none';
    elErrMsg.textContent = '';
  }
  function setSaving(on){
    elSavePill.style.display = on ? 'inline-flex' : 'none';
  }

  function teacherVal(reportId, fieldId){
    const r = state.values_teacher[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }
  function childVal(reportId, fieldId){
    const r = state.values_child[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }

  function optionLabel(options, value){
    const v = String(value ?? '');
    if (!v) return '';
    if (!Array.isArray(options)) return v;
    const hit = options.find(o => String(o?.value ?? '') === v);
    return hit ? String(hit.label ?? hit.value ?? v) : v;
  }

  function teacherDisplay(f, raw){
    const v = String(raw ?? '');
    if (!v) return '';
    const type = String(f?.field_type ?? '');
    if (type === 'select' || type === 'radio' || type === 'grade') {
      return optionLabel(Array.isArray(f.options) ? f.options : null, v);
    }
    return v;
  }

  function childDisplay(f, raw){
    const v = String(raw ?? '');
    if (!v) return '';
    const child = f && f.child ? f.child : null;
    const childType = String(child?.field_type ?? '');
    const opts = child && Array.isArray(child.options) ? child.options : null;

    if (childType === 'select' || childType === 'radio' || childType === 'grade') {
      return optionLabel(opts, v);
    }
    return v;
  }

  function currentStudents(){
    const f = normalize(ui.studentFilter);
    if (!f) return state.students;
    return state.students.filter(s => normalize(s.name).includes(f));
  }
  function activeStudent(){
    const list = currentStudents();
    if (!list.length) return null;
    if (ui.activeStudentIndex < 0) ui.activeStudentIndex = 0;
    if (ui.activeStudentIndex >= list.length) ui.activeStudentIndex = list.length - 1;
    return list[ui.activeStudentIndex];
  }

  function gradeFields(groups){
    const out = [];
    groups.forEach(g => {
      g.fields.forEach(f => {
        if (String(f.field_type) === 'grade') out.push({...f, _group_key:g.key, _group_title:g.title});
      });
    });
    return out;
  }

  function ensureSelect(selectEl){
    if (!selectEl.options.length) {
      selectEl.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = 'ALL';
      optAll.textContent = 'Alle';
      selectEl.appendChild(optAll);
      state.groups.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.key;
        opt.textContent = g.title;
        selectEl.appendChild(opt);
      });
    }
    if (!selectEl.value) selectEl.value = 'ALL';
  }

  function render(){
    elApp.style.display = 'block';
    elMetaTop.textContent = `${state.template?.name ?? 'Template'} · ${state.students.length} Schüler:innen · ${state.groups.reduce((a,g)=>a+g.fields.length,0)} Felder`;

    ui.view = (viewSelect.value === 'item') ? 'item' : (viewSelect.value === 'student' ? 'student' : 'grades');
    ui.showChild = !!toggleChild.checked;

    viewGrades.style.display = (ui.view === 'grades') ? 'block' : 'none';
    viewStudent.style.display = (ui.view === 'student') ? 'block' : 'none';
    viewItem.style.display = (ui.view === 'item') ? 'block' : 'none';

    if (ui.showChild) elApp.classList.add('show-child');
    else elApp.classList.remove('show-child');

    if (ui.view === 'grades') renderGradesView();
    else if (ui.view === 'student') renderStudentView();
    else renderItemView();
  }

  function renderInputHtml(f, reportId, value, locked){
    const dis = locked ? 'disabled' : '';
    const common = `class="input" data-teacher-input="1" data-report-id="${esc(reportId)}" data-field-id="${esc(f.id)}" ${dis}`;

    const type = String(f.field_type || 'text');

    if (type === 'checkbox') {
      const checked = (String(value) === '1') ? 'checked' : '';
      return `<label style="display:flex; align-items:center; gap:10px; margin-top:10px;"><input type="checkbox" ${common} value="1" ${checked} onchange="this.value=this.checked?'1':'0'"> <span class="muted">Ja / Nein</span></label>`;
    }

    if (type === 'multiline' || Number(f.is_multiline||0) === 1) {
      return `<textarea rows="4" ${common} style="width:100%; margin-top:10px;">${esc(value)}</textarea>`;
    }

    if (type === 'radio' || type === 'select' || type === 'grade') {
      const dlId = `dl_${String(f.id)}`;
      const shown = teacherDisplay(f, value);
      const actual = String(value ?? '');
      return `
        <input type="text" ${common}
          data-combo="1"
          data-dlid="${esc(dlId)}"
          data-actual="${esc(actual)}"
          list="${esc(dlId)}"
          autocomplete="off"
          style="width:100%; margin-top:10px;"
          value="${esc(shown)}"
        >
      `;
    }

    const inputType = (type === 'number') ? 'number' : ((type === 'date') ? 'date' : 'text');
    return `<input type="${esc(inputType)}" ${common} style="width:100%; margin-top:10px;" value="${esc(value)}">`;
  }

  function renderStudentView(){
    const list = currentStudents();

    studentList.innerHTML = '';
    list.forEach((s, idx) => {
      const div = document.createElement('div');
      div.className = 'srow' + (idx === ui.activeStudentIndex ? ' active' : '');
      const status = String(s.status || 'draft');
      const statusLbl = (status === 'locked') ? 'gesperrt' : (status === 'submitted' ? 'abgegeben' : 'Entwurf');
      div.innerHTML = `
        <div class="smeta">
          <div class="n">${esc(s.name)}</div>
          <div class="sub">Status: ${esc(statusLbl)}</div>
        </div>
        <span class="badge">${esc(statusLbl)}</span>
      `;
      div.addEventListener('click', () => {
        ui.activeStudentIndex = idx;
        renderStudentView();
      });
      studentList.appendChild(div);
    });

    const s = activeStudent();
    if (!s) {
      studentBadge.textContent = 'Keine Treffer';
      studentForm.innerHTML = '<div class="alert">Keine Schüler gefunden.</div>';
      return;
    }
    studentBadge.textContent = `${s.name} · Report #${s.report_instance_id}`;

    const reportId = s.report_instance_id;
    const status = String(s.status || 'draft');
    const locked = (status === 'locked');

    let html = '';
    if (locked) {
      html += `<div class="alert danger"><strong>Dieser Bericht ist gesperrt.</strong> Eingaben können nicht mehr geändert werden.</div>`;
    } else if (status === 'submitted') {
      html += `<div class="alert"><strong>Hinweis:</strong> Schülereingabe ist abgegeben. Lehrkraft kann weiterhin ergänzen, solange nicht gesperrt.</div>`;
    }

    state.groups.forEach(g => {
      html += `<div class="section-h" style="margin-top:10px;"><div class="t">${esc(g.title)}</div><div class="s">${g.fields.length} Felder</div></div>`;
      g.fields.forEach(f => {
        const v = teacherVal(reportId, f.id);
        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';
        const childInfo = (f.child && f.child.id) ? `<div class="child"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '<span class="muted">—</span>'}</div>` : '';
        html += `
          <div class="field" data-fieldwrap="1">
            <div class="lbl">${esc(f.label || f.field_name)}</div>
            ${f.help_text ? `<div class="help">${esc(f.help_text)}</div>` : ''}
            ${renderInputHtml(f, reportId, v, locked)}
            ${childInfo}
          </div>
        `;
      });
    });

    studentForm.innerHTML = html;

    studentForm.querySelectorAll('[data-fieldwrap="1"]').forEach(el => {
      if (ui.showChild) el.classList.add('show-child');
      else el.classList.remove('show-child');
    });

    wireTeacherInputs(studentForm);
  }

  // NEW: render grades in two orientations
  function renderGradesView(){
    ensureSelect(gradeGroupSelect);

    // apply persisted orientation to select
    if (gradeOrientation && gradeOrientation.value !== ui.gradeOrientation) {
      gradeOrientation.value = ui.gradeOrientation;
    }

    ui.gradeGroupKey = gradeGroupSelect.value || 'ALL';
    const filter = normalize(ui.gradeFilter);

    let fields = gradeFields(state.groups);
    if (ui.gradeGroupKey !== 'ALL') fields = fields.filter(f => f._group_key === ui.gradeGroupKey);
    if (filter) fields = fields.filter(f => normalize(f.label || f.field_name).includes(filter) || normalize(f.field_name).includes(filter));

    if (fields.length === 0) {
      gradeHead.innerHTML = '<tr><th class="sticky">—</th><th>Keine Notenfelder gefunden</th></tr>';
      gradeBody.innerHTML = '';
      return;
    }

    if (ui.gradeOrientation === 'students_cols') {
      // === ROTATED: rows = fields, cols = students ===
      const sCols = state.students;

      // head: sticky "Notenfeld", then students
      gradeHead.innerHTML = '';
      const tr = document.createElement('tr');
      const th0 = document.createElement('th');
      th0.className = 'sticky';
      th0.textContent = 'Notenfeld';
      tr.appendChild(th0);

      sCols.forEach(s => {
        const th = document.createElement('th');
        const status = String(s.status || 'draft');
        const statusLbl = (status === 'locked') ? 'gesperrt' : (status === 'submitted' ? 'abgegeben' : 'Entwurf');
        th.innerHTML = `<div style="font-weight:800;">${esc(s.name)}</div><div class="muted" style="font-size:12px;">${esc(statusLbl)}</div>`;
        tr.appendChild(th);
      });
      gradeHead.appendChild(tr);

      gradeBody.innerHTML = '';
      fields.forEach(f => {
        const row = document.createElement('tr');

        const tdLabel = document.createElement('td');
        tdLabel.className = 'sticky';
        tdLabel.innerHTML = `<div style="font-weight:800;">${esc(f.label || f.field_name)}</div><div class="muted" style="font-size:12px;">${esc(f._group_title || '')}</div>`;
        row.appendChild(tdLabel);

        sCols.forEach(s => {
          const td = document.createElement('td');
          const reportId = s.report_instance_id;
          const status = String(s.status||'draft');
          const locked = (status === 'locked');
          const v = teacherVal(reportId, f.id);

          const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
          const shownChild = rawChild ? childDisplay(f, rawChild) : '';

          const missingCls = (v === '') ? 'missing' : '';
          td.innerHTML = `
            <div class="cellWrap ${missingCls}">
              ${renderInputHtml(f, reportId, v, locked)}
              ${(f.child && f.child.id) ? `<div class="cellChild"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '—'}</div>` : ''}
            </div>
          `;
          row.appendChild(td);
        });

        gradeBody.appendChild(row);
      });

      wireTeacherInputs(gradeBody);
      return;
    }

    // === DEFAULT: rows = students, cols = fields ===
    const sCols = state.students;

    gradeHead.innerHTML = '';
    const tr1 = document.createElement('tr');
    const th0 = document.createElement('th');
    th0.className = 'sticky';
    th0.textContent = 'Schüler:in';
    tr1.appendChild(th0);

    const groupOrder = [];
    const groupCounts = {};
    fields.forEach(f => {
      const k = f._group_title || '—';
      if (!groupCounts[k]) { groupCounts[k] = 0; groupOrder.push(k); }
      groupCounts[k]++;
    });
    groupOrder.forEach(k => {
      const th = document.createElement('th');
      th.colSpan = groupCounts[k];
      th.style.textAlign = 'left';
      th.innerHTML = `<div style="font-weight:800;">${esc(k)}</div>`;
      tr1.appendChild(th);
    });
    gradeHead.appendChild(tr1);

    const tr2 = document.createElement('tr');
    const thS = document.createElement('th');
    thS.className = 'sticky';
    thS.innerHTML = `<span class="muted">Name</span>`;
    tr2.appendChild(thS);

    fields.forEach(f => {
      const th = document.createElement('th');
      th.innerHTML = `<div style="font-weight:800;">${esc(f.label || f.field_name)}</div>`;
      tr2.appendChild(th);
    });
    gradeHead.appendChild(tr2);

    gradeBody.innerHTML = '';
    sCols.forEach(s => {
      const tr = document.createElement('tr');

      const tdName = document.createElement('td');
      tdName.className = 'sticky';
      const status = String(s.status || 'draft');
      const statusLbl = (status === 'locked') ? 'gesperrt' : (status === 'submitted' ? 'abgegeben' : 'Entwurf');
      tdName.innerHTML = `<div style="font-weight:800;">${esc(s.name)}</div><div class="muted" style="font-size:12px;">${esc(statusLbl)}</div>`;
      tr.appendChild(tdName);

      fields.forEach(f => {
        const td = document.createElement('td');
        const reportId = s.report_instance_id;
        const locked = (status === 'locked');
        const v = teacherVal(reportId, f.id);

        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';

        const missingCls = (v === '') ? 'missing' : '';
        td.innerHTML = `
          <div class="cellWrap ${missingCls}">
            ${renderInputHtml(f, reportId, v, locked)}
            ${(f.child && f.child.id) ? `<div class="cellChild"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '—'}</div>` : ''}
          </div>
        `;

        tr.appendChild(td);
      });

      gradeBody.appendChild(tr);
    });

    wireTeacherInputs(gradeBody);
  }

  function ensureGroupsSelect(){
    if (!groupSelect.options.length) {
      groupSelect.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = 'ALL';
      optAll.textContent = 'Alle';
      groupSelect.appendChild(optAll);
      state.groups.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.key;
        opt.textContent = g.title;
        groupSelect.appendChild(opt);
      });
    }
    if (!groupSelect.value) groupSelect.value = 'ALL';
    ui.groupKey = groupSelect.value;
  }

  function renderItemView(){
    ensureGroupsSelect();
    ui.groupKey = groupSelect.value || 'ALL';

    const filter = normalize(ui.itemFilter);
    const groups = (ui.groupKey === 'ALL') ? state.groups : state.groups.filter(g => g.key === ui.groupKey);
    let fields = [];
    groups.forEach(g => fields.push(...g.fields.map(f => ({...f, _group_title:g.title, _group_key:g.key}))));

    if (filter) {
      fields = fields.filter(f => normalize(f.label || f.field_name).includes(filter) || normalize(f.field_name).includes(filter));
    }

    const sCols = state.students;

    itemHead.innerHTML = '';
    const tr = document.createElement('tr');
    const th0 = document.createElement('th');
    th0.className = 'sticky';
    th0.textContent = 'Item';
    tr.appendChild(th0);
    sCols.forEach(s => {
      const th = document.createElement('th');
      th.textContent = s.name;
      tr.appendChild(th);
    });
    itemHead.appendChild(tr);

    itemBody.innerHTML = '';
    fields.forEach(f => {
      const row = document.createElement('tr');
      const tdLabel = document.createElement('td');
      tdLabel.className = 'sticky';
      tdLabel.innerHTML = `<div style="font-weight:800;">${esc(f.label || f.field_name)}</div><div class="muted" style="font-size:12px;">${esc(f._group_title)}</div>`;
      row.appendChild(tdLabel);

      sCols.forEach(s => {
        const td = document.createElement('td');
        const reportId = s.report_instance_id;
        const status = String(s.status||'draft');
        const locked = (status === 'locked');
        const v = teacherVal(reportId, f.id);

        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';

        td.innerHTML = `
          <div class="cellWrap">
            ${renderInputHtml(f, reportId, v, locked)}
            ${(f.child && f.child.id) ? `<div class="cellChild"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '—'}</div>` : ''}
          </div>
        `;

        row.appendChild(td);
      });

      itemBody.appendChild(row);
    });

    wireTeacherInputs(itemBody);
  }

  function scheduleSave(reportId, fieldId, value){
    const key = `${reportId}:${fieldId}`;
    if (ui.saveTimers.has(key)) clearTimeout(ui.saveTimers.get(key));

    if (!state.values_teacher[String(reportId)]) state.values_teacher[String(reportId)] = {};
    state.values_teacher[String(reportId)][String(fieldId)] = value;

    ui.saveTimers.set(key, setTimeout(async () => {
      ui.saveTimers.delete(key);
      ui.saveInFlight++;
      setSaving(true);
      try {
        await api('save', { report_instance_id: reportId, template_field_id: fieldId, value_text: value });
      } catch (e) {
        showErr(e.message || String(e));
      } finally {
        ui.saveInFlight = Math.max(0, ui.saveInFlight - 1);
        if (ui.saveInFlight === 0) setSaving(false);
      }
    }, 350));
  }

  async function loadClass(classId){
    clearErr();
    elApp.style.display = 'none';
    const j = await api('load', { class_id: classId });

    state.class_id = classId;
    state.template = j.template;
    state.groups = j.groups;
    state.students = j.students;
    state.values_teacher = j.values_teacher || {};
    state.values_child = j.values_child || {};

    rebuildFieldMap();

    ui.activeStudentIndex = 0;
    groupSelect.innerHTML = '';
    gradeGroupSelect.innerHTML = '';
    gradeSearch.value = '';
    itemSearch.value = '';
    studentSearch.value = '';
    ui.studentFilter = '';
    ui.itemFilter = '';
    ui.gradeFilter = '';

    elApp.style.display = 'block';
    render();
  }

  classSelect.addEventListener('change', () => {
    const cid = Number(classSelect.value || '0');
    if (cid > 0) {
      history.replaceState(null, '', `?class_id=${encodeURIComponent(String(cid))}`);
      loadClass(cid).catch(e => showErr(e.message || String(e)));
    }
  });

  viewSelect.addEventListener('change', () => render());
  toggleChild.addEventListener('change', () => render());

  studentSearch.addEventListener('input', () => {
    ui.studentFilter = studentSearch.value;
    ui.activeStudentIndex = 0;
    renderStudentView();
  });

  btnPrevStudent.addEventListener('click', () => {
    ui.activeStudentIndex = Math.max(0, ui.activeStudentIndex - 1);
    renderStudentView();
  });
  btnNextStudent.addEventListener('click', () => {
    ui.activeStudentIndex = ui.activeStudentIndex + 1;
    renderStudentView();
  });

  groupSelect.addEventListener('change', () => renderItemView());
  itemSearch.addEventListener('input', () => { ui.itemFilter = itemSearch.value; renderItemView(); });

  gradeGroupSelect.addEventListener('change', () => renderGradesView());
  gradeSearch.addEventListener('input', () => { ui.gradeFilter = gradeSearch.value; renderGradesView(); });

  // NEW: orientation change persists
  gradeOrientation.value = ui.gradeOrientation;
  gradeOrientation.addEventListener('change', () => {
    ui.gradeOrientation = gradeOrientation.value || 'students_rows';
    localStorage.setItem('leb_grade_orientation', ui.gradeOrientation);
    renderGradesView();
  });

  window.addEventListener('keydown', (ev) => {
    if (ev.altKey && !ev.ctrlKey && !ev.metaKey) {
      const k = ev.key.toLowerCase();
      if (k === 's') { ev.preventDefault(); toggleChild.checked = !toggleChild.checked; render(); }
      if (k === 'm') {
        ev.preventDefault();
        const order = ['grades','student','item'];
        const cur = viewSelect.value || 'grades';
        const idx = order.indexOf(cur);
        viewSelect.value = order[(idx + 1) % order.length];
        render();
      }
    }
  });

  const initialClassId = Number(classSelect.value || <?=json_encode((int)$classId)?> || 0);
  if (initialClassId > 0) {
    loadClass(initialClassId).catch(e => showErr(e.message || String(e)));
  } else {
    showErr('Keine Klasse verfügbar.');
  }
})();
</script>

<?php
render_teacher_footer();
?>
