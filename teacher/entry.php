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
$delegatedMode = ((int)($_GET['delegated'] ?? 0) === 1);

$jsDelegatedMode = $delegatedMode ? 1 : 0;
$jsUserId = $userId;
$jsCanDelegate = $delegatedMode ? 0 : 1;

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

// Delegations must be accessed ONLY via inbox (separate from own classes).
$ownClassIds = array_map(fn($r) => (int)($r['id'] ?? 0), $classes);
$hasOwnClass = ($classId > 0 && in_array($classId, $ownClassIds, true));

if (($u['role'] ?? '') !== 'admin') {
  if (!$delegatedMode) {
    // Own work: allow only own classes
    if ($classId <= 0 && $classes) {
      $classId = (int)($classes[0]['id'] ?? 0);
    }
    $hasOwnClass = ($classId > 0 && in_array($classId, $ownClassIds, true));

    if ($classId > 0 && !$hasOwnClass) {
      render_teacher_header('Eingaben');
      ?>
      <div class="card">
        <div class="row-actions">
          <a class="btn secondary" href="<?=h(url('teacher/index.php'))?>">← Übersicht</a>
    <a class="btn secondary" href="<?=h(url('teacher/delegations.php'))?>">Delegationen</a>
          <a class="btn" href="<?=h(url('teacher/delegations.php'))?>">Delegationen</a>
        </div>
        <h1 style="margin-top:0;">Delegationen sind getrennt</h1>
        <p class="muted">Diese Seite zeigt <strong>nur deine eigenen Klassen</strong>. Delegierte Fachbereiche findest du in der <a href="<?=h(url('teacher/delegations.php'))?>">Delegations‑Inbox</a>.</p>
      </div>
      <?php
      render_teacher_footer();
      exit;
    }
  } else {
    // Delegated work: class_id must be provided and must be accessible via delegation.
    if ($classId <= 0) {
      render_teacher_header('Delegation');
      ?>
      <div class="card">
        <div class="row-actions">
          <a class="btn secondary" href="<?=h(url('teacher/delegations.php'))?>">← Delegationen</a>
        </div>
        <div class="alert danger"><strong>Keine Klasse ausgewählt.</strong></div>
      </div>
      <?php
      render_teacher_footer();
      exit;
    }

    if (!user_can_access_class($pdo, $userId, $classId)) {
      http_response_code(403);
      echo '403 Forbidden';
      exit;
    }

    // IMPORTANT: Do not show other classes here.
    $stc = $pdo->prepare("SELECT id, school_year, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
    $stc->execute([$classId]);
    $only = $stc->fetch(PDO::FETCH_ASSOC);
    $classes = $only ? [$only] : [];
  }
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}


if ($classId > 0 && ($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo '403 Forbidden';
  exit;
}

render_teacher_header('Eingaben');
?>

<div class="card">
  <div class="row-actions" style="justify-content:space-between; align-items:center;">
    <a class="btn secondary" href="<?=h(url('teacher/index.php'))?>">← Übersicht</a>
    <a class="btn secondary" href="<?=h(url('teacher/delegations.php'))?>">Delegationen</a>
    <?php if (!$delegatedMode): ?>
        <button class="btn" type="button" id="btnDelegationsTop">Delegieren…</button>
      <?php endif; ?>
  </div>

  <h1 style="margin-top:0;"><?= $delegatedMode ? 'Delegation bearbeiten' : 'Eingaben ausfüllen' ?></h1>
  <?php if ($delegatedMode): ?>
    <div class="alert" style="margin-top:10px;"><strong>Delegation:</strong> Du siehst hier nur die an dich delegierten Fachbereiche. Andere Bereiche sind schreibgeschützt.</div>
  <?php endif; ?>
  <p class="muted" style="margin-top:-6px;">
    Tipps: <strong>Tab</strong> zum schnellen Springen · <strong>Shift+Tab</strong> zurück ·
    <strong>Alt+S</strong> Schülereingaben ein/aus · <strong>Alt+M</strong> Ansicht wechseln
  </p>

  <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div style="min-width:260px;">
      <label class="label">Klasse</label>
      <select class="input" id="classSelect" style="width:100%;" <?= $delegatedMode ? 'disabled' : '' ?>>
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

<?php if (!$delegatedMode): ?>
    <div id="dlgDelegations" class="modal" style="display:none;">
      <div class="modal-backdrop" data-close="1"></div>
      <div class="modal-card">
        <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
          <h3 style="margin:0;">Fachbereiche delegieren</h3>
          <button class="btn secondary" type="button" data-close="1">Schließen</button>
        </div>
        <div class="muted" style="margin-top:6px;">
          Hier kannst du pro <strong>Fach/Gruppe</strong> eine Kollegin/einen Kollegen als Bearbeiter:in festlegen.
          Delegierte Gruppen sind für andere Lehrkräfte <strong>schreibgeschützt</strong> (Admin darf immer).
        </div>

        <div class="row" style="gap:10px; margin-top:12px; align-items:flex-end; flex-wrap:wrap;">
          <div style="min-width:240px;">
            <label class="label">Fach/Gruppe</label>
            <select class="input" id="dlgGroup" style="width:100%;"></select>
          </div>
          <div style="min-width:280px;">
            <label class="label">Kolleg:in</label>
            <select class="input" id="dlgUser" style="width:100%;"></select>
            <div class="muted" style="font-size:12px; margin-top:4px;">Leer = Delegation aufheben</div>
          </div>
          <div style="min-width:160px;">
            <label class="label">Status</label>
            <select class="input" id="dlgStatus" style="width:100%;">
              <option value="open">offen</option>
              <option value="done">fertig</option>
            </select>
          </div>
          <div style="flex:1; min-width:240px;">
            <label class="label">Notiz</label>
            <input class="input" id="dlgNote" type="text" placeholder="z.B. Deutsch fertig, Mathe offen…" style="width:100%;">
          </div>
          <div>
            <button class="btn" type="button" id="dlgSave">Speichern</button>
          </div>
        </div>

        <div style="margin-top:14px; border-top:1px solid var(--border); padding-top:12px;">
          <div class="muted" style="margin-bottom:8px;">Aktuelle Delegationen</div>
          <div id="dlgList" style="display:flex; flex-direction:column; gap:8px;"></div>
        </div>
      </div>
    </div>
<?php endif; ?>

<div id="app" class="card" style="display:none;">
  <div id="metaTop" class="muted" style="margin-bottom:10px;">Lade…</div>

  <div id="formsProgressWrap" class="progress-wrap" style="display:none; margin-bottom:14px;">
    <div class="progress-meta"><span id="formsProgressText">—</span><span id="formsProgressPct"></span></div>
    <div class="progress"><div id="formsProgressBar" class="progress-bar"></div></div>
  </div>

  <div id="classFieldsBox" class="card" style="margin:12px 0; display:none;">
    <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
      <div>
        <h3 style="margin:0; font-size:16px;">Klassenfelder (für alle Schüler:innen)</h3>
        <div style="opacity:.85; font-size:13px;">Diese Werte gelten für die gesamte Klasse und können in Labels/Hilfetexten per <code>{{Feldname}}</code> referenziert werden.</div>
      </div>
      <div class="pill-mini" id="classFieldsStatus"></div>
    </div>

    <div id="classFieldsProgressWrap" class="progress-wrap" style="display:none; margin-top:10px;">
      <div class="progress-meta"><span id="classFieldsProgressText">—</span><span id="classFieldsProgressPct"></span></div>
      <div class="progress"><div id="classFieldsProgressBar" class="progress-bar"></div></div>
    </div>

    <div id="classFieldsForm" style="margin-top:10px;"></div>
  </div>

  <!-- Grades view -->
  <div id="viewGrades" style="display:none;">
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label class="label">Fach/Gruppe</label>
        <select class="input" id="gradeGroupSelect" style="width:100%;"></select>
      </div>

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

  #itemTable, #gradeTable { table-layout: auto; width: max-content; }
  #itemTable th, #itemTable td, #gradeTable th, #gradeTable td { vertical-align: top; }

  #itemTable th.sticky, #itemTable td.sticky,
  #gradeTable th.sticky, #gradeTable td.sticky{
    position:sticky; left:0; background:#fff; z-index:2;
    min-width: 220px; max-width: 320px;
  }

  #itemTable thead th, #gradeTable thead th{ position:sticky; top:0; background:#fff; z-index:3; }
  #itemTable thead th.sticky, #gradeTable thead th.sticky{ z-index:4; }

  #itemTable th:not(.sticky), #itemTable td:not(.sticky),
  #gradeTable th:not(.sticky), #gradeTable td:not(.sticky){
    max-width: 260px;
  }

  .gradeInput{ width: 6ch; max-width: 8ch; padding: 6px 8px; }

  .cellWrap{ display:flex; flex-direction:column; gap:6px; }
  .cellChild{ display:none; padding:6px 8px; border:1px dashed var(--border); border-radius:10px; color:var(--muted); font-size:12px; background: rgba(0,0,0,0.02); }
  .show-child .cellChild{ display:block; }

  .missing{ outline:2px solid rgba(200,20,20,0.12); background: rgba(200,20,20,0.04); border-radius:12px; padding:4px; }

.modal{ position:fixed; inset:0; z-index:9999; }
.modal-backdrop{ position:absolute; inset:0; background: rgba(0,0,0,0.35); }
.modal-card{ position:relative; width:min(980px, calc(100vw - 24px)); max-height: calc(100vh - 24px); overflow:auto; margin:12px auto; background:#fff; border-radius:16px; padding:14px; box-shadow: 0 12px 40px rgba(0,0,0,0.22); border:1px solid rgba(0,0,0,0.08); }
.del-row{ border:1px solid var(--border); border-radius:12px; padding:10px; display:flex; justify-content:space-between; gap:10px; align-items:center; background:#fff; }
.del-row .l{ min-width:0; }
.del-row .l .t{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.del-row .l .s{ color:var(--muted); font-size:12px; margin-top:2px; }
.badge-del{ display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; border:1px solid rgba(11,87,208,0.22); background: rgba(11,87,208,0.08); font-size:12px; color: rgba(11,87,208,0.9); }

</style>

<script>
(function(){
  const DELEGATED_MODE = (<?= (int)$jsDelegatedMode ?> === 1);
  const CURRENT_USER_ID = Number(<?= (int)$jsUserId ?>);
  const CAN_DELEGATE = (<?= (int)$jsCanDelegate ?> === 1);

  const btnDelegationsTop = document.getElementById('btnDelegationsTop');
  const apiUrl = <?=json_encode(url('teacher/ajax/entry_api.php'))?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const DEBUG = (new URLSearchParams(location.search).get('debug') === '1');

  const elApp = document.getElementById('app');
  const classFieldsBox = document.getElementById('classFieldsBox');
  const classFieldsForm = document.getElementById('classFieldsForm');
  const classFieldsStatus = document.getElementById('classFieldsStatus');
  const elErrBox = document.getElementById('errBox');
  const elErrMsg = document.getElementById('errMsg');
  const elMetaTop = document.getElementById('metaTop');
  const formsProgressWrap = document.getElementById('formsProgressWrap');
  const formsProgressBar = document.getElementById('formsProgressBar');
  const formsProgressText = document.getElementById('formsProgressText');
  const formsProgressPct = document.getElementById('formsProgressPct');

  const classFieldsProgressWrap = document.getElementById('classFieldsProgressWrap');
  const classFieldsProgressBar = document.getElementById('classFieldsProgressBar');
  const classFieldsProgressText = document.getElementById('classFieldsProgressText');
  const classFieldsProgressPct = document.getElementById('classFieldsProgressPct');

  const elSavePill = document.getElementById('savePill');
  const btnDelegations = document.getElementById('btnDelegations');
  const dlg = document.getElementById('dlgDelegations');
  const dlgGroup = document.getElementById('dlgGroup');
  const dlgUser = document.getElementById('dlgUser');
  const dlgStatus = document.getElementById('dlgStatus');
  const dlgNote = document.getElementById('dlgNote');
  const dlgSave = document.getElementById('dlgSave');
  const dlgList = document.getElementById('dlgList');


  const classSelect = document.getElementById('classSelect');
  const viewSelect = document.getElementById('viewSelect');
  const toggleChild = document.getElementById('toggleChild');

  const viewGrades = document.getElementById('viewGrades');
  const viewStudent = document.getElementById('viewStudent');
  const viewItem = document.getElementById('viewItem');

  const gradeGroupSelect = document.getElementById('gradeGroupSelect');
  const gradeOrientation = document.getElementById('gradeOrientation');
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
    delegation_users: [],
    delegations: [],
    period_label: 'Standard',
    students: [],
    values_teacher: {},
    values_child: {},
    class_report_instance_id: 0,
    class_fields: null,
    progress_summary: null,
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
    gradeOrientation: localStorage.getItem('leb_grade_orientation') || 'students_rows',
    saveTimers: new Map(),
    saveInFlight: 0,
  };

  function dbg(...args){ if (DEBUG) console.log('[LEB entry]', ...args); }

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function normalize(s){ return String(s ?? '').toLowerCase().trim(); }
  
  function buildFieldNameIndex(){
    const idx = new Map();

    // teacher fields (in groups)
    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => {
        const n = String(f.field_name || '').trim();
        if (!n) return;
        idx.set(n, f);
        idx.set(n.toLowerCase(), f);
      });
    });

    // class fields (not in groups)
    if (state.class_fields && Array.isArray(state.class_fields.fields)) {
      state.class_fields.fields.forEach(f => {
        const n = String(f.field_name || '').trim();
        if (!n) return;
        idx.set(n, f);
        idx.set(n.toLowerCase(), f);
      });
    }

    return idx;
  }

  function resolveLabelTemplate(tpl){
    const s = String(tpl ?? '');
    if (!s || s.indexOf('{{') === -1) return s;

    const idx = buildFieldNameIndex();

    // values come from class report instance (only class-wide interpolation!)
    const rid = classReportId();
    const classValueByName = (state.class_fields && state.class_fields.value_by_name) ? state.class_fields.value_by_name : {};

    return s.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, rawTok) => {
      const token = String(rawTok || '').trim();
      if (!token) return '';

      let kind = 'field';
      let key = token;
      const p = token.indexOf(':');
      if (p !== -1) {
        kind = token.slice(0, p).trim().toLowerCase();
        key = token.slice(p + 1).trim();
      }
      if (!key) return '';

      // 1) fastest: value_by_name from API (exact field_name)
      if (kind === 'field' || kind === 'value') {
        if (classValueByName && Object.prototype.hasOwnProperty.call(classValueByName, key)) {
          return String(classValueByName[key] ?? '');
        }

        // 2) try case-insensitive field lookup -> then read value via teacherVal (will use class report for class fields)
        const ref = idx.get(key) || idx.get(key.toLowerCase());
        if (ref && ref.id) {
          const raw = teacherVal(rid, Number(ref.id));
          return teacherDisplay(ref, raw);
        }
      }

      return '';
    });
  }

  function rebuildFieldMap(){
    const map = {};
    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => { map[String(f.id)] = f; });
    });
    // class fields are NOT in groups (by design), so add them too:
    if (state.class_fields && Array.isArray(state.class_fields.fields)) {
      state.class_fields.fields.forEach(f => { map[String(f.id)] = f; });
    }
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

  function isClassFieldId(fieldId){
    const cf = state.class_fields;
    if (!cf || !Array.isArray(cf.field_ids)) return false;
    return cf.field_ids.includes(Number(fieldId));
  }

  function classReportId(){
    return Number(state.class_report_instance_id || 0);
  }

  function teacherVal(reportId, fieldId){
    if (isClassFieldId(fieldId)) {
      const rid = classReportId();
      const r = state.values_teacher[String(rid)] || {};
      const v = r[String(fieldId)];
      return (v === null || typeof v === 'undefined') ? '' : String(v);
    }
    const r = state.values_teacher[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }

  function childVal(reportId, fieldId){
    const r = state.values_child[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }

  // --- progress helpers ---
  function teacherProgressFieldIds(){
    const ids = [];
    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => { ids.push(Number(f.id)); });
    });
    return ids;
  }

  function computeDoneFromTeacherValues(reportId, fieldIds){
    const ridKey = String(reportId);
    const row = state.values_teacher[ridKey] || {};
    let done = 0;
    for (const fid of fieldIds) {
      const v = row[String(fid)];
      if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') done++;
    }
    return done;
  }

  function findStudentByReportId(reportId){
    return (state.students || []).find(s => Number(s.report_instance_id) === Number(reportId)) || null;
  }

  function recomputeStudentProgress(student){
    if (!student) return;
    const tIds = teacherProgressFieldIds();
    const tTotal = tIds.length;
    const tDone = computeDoneFromTeacherValues(Number(student.report_instance_id || 0), tIds);

    const cTotal = Number(student.progress_child_total || 0);
    const cDone = Number(student.progress_child_done || 0);

    const overallTotal = tTotal + cTotal;
    const overallDone = tDone + cDone;
    const overallMissing = Math.max(0, overallTotal - overallDone);

    student.progress_teacher_total = tTotal;
    student.progress_teacher_done = tDone;
    student.progress_teacher_missing = Math.max(0, tTotal - tDone);

    student.progress_overall_total = overallTotal;
    student.progress_overall_done = overallDone;
    student.progress_overall_missing = overallMissing;
    student.progress_is_complete = (overallTotal > 0 && overallMissing === 0);
  }

  function recomputeFormsSummary(){
    const total = (state.students || []).length;
    let complete = 0;
    (state.students || []).forEach(s => { if (s.progress_is_complete) complete++; });

    if (!state.progress_summary) state.progress_summary = {};
    state.progress_summary.students_total = total;
    state.progress_summary.forms_complete = complete;
    state.progress_summary.forms_incomplete = Math.max(0, total - complete);
    state.progress_summary.teacher_fields_total = teacherProgressFieldIds().length;
  }

  function updateFormsProgressUI(){
    if (!formsProgressWrap || !formsProgressBar) return;

    const total = Number(state.progress_summary?.students_total ?? (state.students || []).length);
    const complete = Number(state.progress_summary?.forms_complete ?? 0);

    if (!total) {
      formsProgressWrap.style.display = 'none';
      return;
    }

    const pct = Math.round((complete / total) * 100);
    formsProgressWrap.style.display = '';
    if (formsProgressText) formsProgressText.textContent = `Formulare vollständig: ${complete}/${total}`;
    if (formsProgressPct) formsProgressPct.textContent = `${pct}%`;
    formsProgressBar.style.width = `${pct}%`;
    formsProgressBar.classList.toggle('ok', complete === total);
  }

  function updateClassFieldsProgressUI(){
    if (!classFieldsProgressWrap || !classFieldsProgressBar || !classFieldsStatus) return;

    const cf = state.class_fields;
    const ids = (cf && Array.isArray(cf.field_ids)) ? cf.field_ids : [];
    const rid = classReportId();

    if (!ids.length || !rid) {
      classFieldsProgressWrap.style.display = 'none';
      classFieldsStatus.textContent = '';
      return;
    }

    let done = 0;
    ids.forEach(fid => {
      const v = teacherVal(rid, Number(fid));
      if (String(v).trim() !== '') done++;
    });

    const total = ids.length;
    const missing = Math.max(0, total - done);
    const pct = Math.round((done / total) * 100);

    classFieldsProgressWrap.style.display = '';
    classFieldsStatus.textContent = `${done}/${total}`;
    if (classFieldsProgressText) classFieldsProgressText.textContent = `Klassenfelder: ${done}/${total} (offen: ${missing})`;
    if (classFieldsProgressPct) classFieldsProgressPct.textContent = `${pct}%`;
    classFieldsProgressBar.style.width = `${pct}%`;
    classFieldsProgressBar.classList.toggle('ok', missing === 0);
  }

  function updateStudentRowUI(student){
    if (!student) return;
    const row = document.getElementById(`srow-${student.id}`);
    if (!row) return;

    const overallTotal = Number(student.progress_overall_total || 0);
    const overallDone = Number(student.progress_overall_done || 0);
    const overallMissing = Number(student.progress_overall_missing || 0);
    const teacherMissing = Number(student.progress_teacher_missing || 0);

    const pct = overallTotal > 0 ? Math.round((overallDone / overallTotal) * 100) : 0;

    const sub = row.querySelector('.js-srow-sub');
    if (sub) {
      const statusLbl = String(sub.getAttribute('data-statuslbl') || '');
      sub.textContent = `Status: ${statusLbl} · offen: ${overallMissing} · Lehrer offen: ${teacherMissing}`;
    }

    const bar = row.querySelector('.js-prog-bar');
    if (bar) {
      bar.style.width = `${pct}%`;
      bar.classList.toggle('ok', !!student.progress_is_complete);
    }

    const badge = row.querySelector('.js-prog-badge');
    if (badge) {
      badge.textContent = student.progress_is_complete ? '✓' : `offen: ${overallMissing}`;
      badge.classList.toggle('ok', !!student.progress_is_complete);
    }
  }

  function updateActiveStudentBadge(){
    const s = activeStudent();
    if (!s || !studentBadge) return;
    const tDone = Number(s.progress_teacher_done || 0);
    const tTotal = Number(s.progress_teacher_total || 0);
    const oDone = Number(s.progress_overall_done || 0);
    const oTotal = Number(s.progress_overall_total || 0);
    const oMissing = Number(s.progress_overall_missing || 0);
    const chk = s.progress_is_complete ? '✓' : '';
    studentBadge.textContent = `${s.name} · Lehrer: ${tDone}/${tTotal} · Gesamt: ${oDone}/${oTotal} · offen: ${oMissing} ${chk}`.trim();
  }

  function onTeacherValueChanged(reportId, fieldId){
    if (isClassFieldId(fieldId)) {
      updateClassFieldsProgressUI();
      return;
    }

    const st = findStudentByReportId(reportId);
    if (!st) return;
    recomputeStudentProgress(st);
    recomputeFormsSummary();
    updateFormsProgressUI();
    updateStudentRowUI(st);
    updateActiveStudentBadge();
  }

  function scheduleSave(reportId, fieldId, value){
    const key = `${reportId}:${fieldId}`;
    if (ui.saveTimers.has(key)) clearTimeout(ui.saveTimers.get(key));

    if (!state.values_teacher[String(reportId)]) state.values_teacher[String(reportId)] = {};
    state.values_teacher[String(reportId)][String(fieldId)] = value;
    onTeacherValueChanged(reportId, fieldId);

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

  function scheduleSaveClass(fieldId, value){
    const rid = classReportId();
    const key = `class:${rid}:${fieldId}`;
    if (ui.saveTimers.has(key)) clearTimeout(ui.saveTimers.get(key));

    if (!state.values_teacher[String(rid)]) state.values_teacher[String(rid)] = {};
    state.values_teacher[String(rid)][String(fieldId)] = value;
    onTeacherValueChanged(rid, fieldId);

    ui.saveTimers.set(key, setTimeout(async () => {
      ui.saveTimers.delete(key);
      ui.saveInFlight++;
      setSaving(true);
      try {
        await api('save_class', { class_id: state.class_id, report_instance_id: rid, template_field_id: fieldId, value_text: value });
      } catch (e) {
        showErr(e.message || String(e));
      } finally {
        ui.saveInFlight = Math.max(0, ui.saveInFlight - 1);
        if (ui.saveInFlight === 0) setSaving(false);
      }
    }, 350));
  }

  function wireTeacherInputs(rootEl){
    if (!rootEl) return;

    rootEl.querySelectorAll('[data-teacher-input="1"]').forEach(inp => {
      const reportId = Number(inp.getAttribute('data-report-id') || '0');
      const fieldId = Number(inp.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const f = state.fieldMap[String(fieldId)];

      if (f && String(f.field_type || '') === 'grade') {
        inp.classList.add('gradeInput');
      }

      const isClass = isClassFieldId(fieldId);
      const doSave = (val) => {
        if (isClass) scheduleSaveClass(fieldId, val);
        else scheduleSave(reportId, fieldId, val);
      };

      if (inp.dataset.combo === '1') {
        ensureDatalistForField(fieldId);

        const actual = String(inp.dataset.actual ?? '');
        inp.dataset.actual = actual;
        if (f) inp.value = teacherDisplay(f, actual);

        const commit = () => {
          const typed = inp.value;
          const resolved = f ? resolveTypedToValue(f, typed) : String(typed ?? '').trim();
          doSave(resolved);
          inp.dataset.actual = resolved;
          if (f) inp.value = teacherDisplay(f, resolved);
        };

        inp.addEventListener('change', commit);
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') { ev.preventDefault(); commit(); inp.blur(); }
        });
        return;
      }

      if (inp.type === 'checkbox') {
        inp.addEventListener('change', () => doSave(inp.checked ? '1' : '0'));
      } else {
        inp.addEventListener('input', () => doSave(inp.value));
      }

      inp.addEventListener('focus', () => {
        const wrap = inp.closest('.field');
        if (wrap) wrap.scrollIntoView({block:'nearest'});
      });
    });
  }

  // --- rendering helpers

  function renderInputHtml(f, reportId, value, locked, canEdit=true){
    const dis = (locked || !canEdit) ? 'disabled' : '';
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
        const del = g.delegation;
        const delTxt = del && del.user_id ? ` → ${del.user_name || ('#'+del.user_id)}` : '';
        const lockTxt = (g.can_edit === 0) ? ' 🔒' : '';
        opt.textContent = (g.title || g.key) + delTxt + lockTxt;
        selectEl.appendChild(opt);
      });
    }
    if (!selectEl.value) selectEl.value = 'ALL';
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

  function renderClassFields(){
    const cf = state.class_fields;
    dbg('class_fields', cf);

    if (!cf || !Array.isArray(cf.fields) || !cf.fields.length || !classReportId()) {
      if (classFieldsBox) classFieldsBox.style.display = 'none';
      if (classFieldsForm) classFieldsForm.innerHTML = '';
      if (classFieldsStatus) classFieldsStatus.textContent = '';
      return;
    }

    classFieldsBox.style.display = 'block';
    // status/progress handled by updateClassFieldsProgressUI()
    classFieldsStatus.textContent = '';
    updateClassFieldsProgressUI();

    const rid = classReportId();
    const locked = false;

    const html = cf.fields.map(f => {
      const fid = Number(f.id);
      const v = teacherVal(rid, fid);
      const lbl = String(f.label_resolved || f.label || f.field_name || '');
      const help = String(f.help_text_resolved || f.help_text || '');
      return `
        <div class="field" data-fieldwrap="1" data-field-id="${esc(fid)}">
          <div class="lbl" data-dyn="label">${esc(lbl)}</div>
          <div class="help" data-dyn="help" style="${help.trim() ? '' : 'display:none;'}">${esc(help)}</div>
          ${renderInputHtml(f, rid, v, locked)}
        </div>
      `;
    }).join('');

    classFieldsForm.innerHTML = html;
    wireTeacherInputs(classFieldsForm);
  }

  function render(){
    elApp.style.display = 'block';
    elMetaTop.textContent = `${state.template?.name ?? 'Template'} · ${state.students.length} Schüler:innen · ${state.groups.reduce((a,g)=>a+g.fields.length,0)} Felder`;

    // ✅ always render class fields (independent from view)
    renderClassFields();
    updateClassFieldsProgressUI();

    // ✅ progress: how many forms are complete
    updateFormsProgressUI();

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

  function renderStudentView(){
    const list = currentStudents();

    studentList.innerHTML = '';
    list.forEach((s, idx) => {
      const div = document.createElement('div');
      div.className = 'srow' + (idx === ui.activeStudentIndex ? ' active' : '');
      const status = String(s.status || 'draft');
      const statusLbl = (status === 'locked') ? 'gesperrt' : (status === 'submitted' ? 'abgegeben' : 'Entwurf');
      const overallTotal = Number(s.progress_overall_total || 0);
      const overallDone = Number(s.progress_overall_done || 0);
      const overallMissing = Number(s.progress_overall_missing || 0);
      const teacherMissing = Number(s.progress_teacher_missing || 0);
      const pct = overallTotal > 0 ? Math.round((overallDone / overallTotal) * 100) : 0;
      const complete = !!s.progress_is_complete;

      div.id = `srow-${s.id}`;
      div.innerHTML = `
        <div class="smeta">
          <div class="n">${esc(s.name)}</div>
          <div class="sub js-srow-sub" data-statuslbl="${esc(statusLbl)}">Status: ${esc(statusLbl)} · offen: ${esc(overallMissing)} · Lehrer offen: ${esc(teacherMissing)}</div>
          <div style="margin-top:6px;">
            <div class="progress sm"><div class="progress-bar js-prog-bar${complete ? ' ok' : ''}" style="width:${pct}%;"></div></div>
          </div>
        </div>
        <span class="badge js-prog-badge${complete ? ' ok' : ''}">${complete ? '✓' : ('offen: ' + overallMissing)}</span>
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
    updateActiveStudentBadge();

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
      const _gtTotal = (g.fields||[]).length;
      let _gtDone = 0;
      (g.fields||[]).forEach(_f => { const _v = teacherVal(reportId, _f.id); if (String(_v).trim() !== '') _gtDone++; });
      const _gtMiss = Math.max(0, _gtTotal - _gtDone);
      const _gtPct = _gtTotal > 0 ? Math.round((_gtDone / _gtTotal) * 100) : 0;
      const canEditGroup = (Number(g.can_edit||0) === 1);
      const del = g.delegation;
      const delBadge = (del && del.user_id) ? `<span class="badge-del">Delegiert: ${esc(del.user_name || ('#'+del.user_id))}${del.status==='done' ? ' · fertig' : ''}</span>` : '';
      const lockBadge = (!canEditGroup && !locked) ? `<span class="badge-del">🔒 schreibgeschützt</span>` : '';
      const delegBtn = CAN_DELEGATE
  ? `<button class="btn secondary" type="button" data-open-deleg="${esc(g.key)}" style="padding:6px 10px; font-size:12px;">Delegieren</button>`
  : '';
      html += `
          <div class="section-h" style="margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
            <div class="t">${esc(g.title)} ${delBadge} ${lockBadge}</div>
            <div style="display:flex; gap:10px; align-items:center;">
              <div class="s">${_gtDone}/${_gtTotal} (offen: ${_gtMiss})</div>
              ${delegBtn}
            </div>
          </div>
        `;
      html += `<div class="progress sm" style="margin:6px 0 10px;"><div class="progress-bar${_gtMiss === 0 ? ' ok' : ''}" style="width:${_gtPct}%;"></div></div>`;
      g.fields.forEach(f => {
        const v = teacherVal(reportId, f.id);
        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';
        const childInfo = (f.child && f.child.id) ? `<div class="child"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '<span class="muted">—</span>'}</div>` : '';
        const lbl = resolveLabelTemplate(String(f.label || f.field_name || 'Feld'));
        const help = resolveLabelTemplate(String(f.help_text || ''));
        html += `
          <div class="field" data-fieldwrap="1" data-field-id="${esc(f.id)}">
            <div class="lbl">${esc(lbl)}</div>
            <div class="help" style="${help.trim() ? '' : 'display:none;'}">${esc(help)}</div>
            ${renderInputHtml(f, reportId, v, locked, canEditGroup)}
            ${childInfo}
          </div>
        `;
      });
    });

    studentForm.innerHTML = html;
    
    studentForm.querySelectorAll('[data-open-deleg]').forEach(b => {
        b.addEventListener('click', (ev) => {
          ev.preventDefault();
          const gk = String(b.getAttribute('data-open-deleg') || '');
          if (gk) openDelegations(gk);
        });
      });

    studentForm.querySelectorAll('[data-fieldwrap="1"]').forEach(el => {
      if (ui.showChild) el.classList.add('show-child');
      else el.classList.remove('show-child');
    });

    wireTeacherInputs(studentForm);
  }

  function renderGradesView(){
    ensureSelect(gradeGroupSelect);

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
      const sCols = state.students;

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
        const lbl = resolveLabelTemplate(String(f.label || f.field_name));
        tdLabel.innerHTML = `<div style="font-weight:800;">${esc(lbl)}</div><div class="muted" style="font-size:12px;">${esc(f._group_title || '')}</div>`;
        row.appendChild(tdLabel);

        sCols.forEach(s => {
          const td = document.createElement('td');
          const reportId = s.report_instance_id;
          const status = String(s.status||'draft');
          const locked = (status === 'locked');
          const v = teacherVal(reportId, f.id);
          const gObj = (state.groups||[]).find(x => String(x.key) === String(f._group_key));
          const canEditGroup = gObj ? (Number(gObj.can_edit||0) === 1) : true;

          const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
          const shownChild = rawChild ? childDisplay(f, rawChild) : '';

          const missingCls = (v === '') ? 'missing' : '';
          td.innerHTML = `
            <div class="cellWrap ${missingCls}">
              ${renderInputHtml(f, reportId, v, locked, canEditGroup)}
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
      const lbl = resolveLabelTemplate(String(f.label || f.field_name));
      th.innerHTML = `<div style="font-weight:800;">${esc(lbl)}</div>`;
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
        const gObj = (state.groups||[]).find(x => String(x.key) === String(f._group_key));
        const canEditGroup = gObj ? (Number(gObj.can_edit||0) === 1) : true;

        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';

        const missingCls = (v === '') ? 'missing' : '';
        td.innerHTML = `
          <div class="cellWrap ${missingCls}">
            ${renderInputHtml(f, reportId, v, locked, canEditGroup)}
            ${(f.child && f.child.id) ? `<div class="cellChild"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '—'}</div>` : ''}
          </div>
        `;
        tr.appendChild(td);
      });

      gradeBody.appendChild(tr);
    });

    wireTeacherInputs(gradeBody);
  }

  function renderItemView(){
    ensureGroupsSelect();
    ui.groupKey = groupSelect.value || 'ALL';

    const filter = normalize(ui.itemFilter);
    const groups = (ui.groupKey === 'ALL') ? state.groups : state.groups.filter(g => g.key === ui.groupKey);
    let fields = [];
    groups.forEach(g => fields.push(...g.fields.map(f => ({...f, _group_title:g.title, _group_key:g.key}))));

    if (filter) fields = fields.filter(f => normalize(f.label || f.field_name).includes(filter) || normalize(f.field_name).includes(filter));

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
      const lbl = resolveLabelTemplate(String(f.label || f.field_name));
      tdLabel.innerHTML = `<div style="font-weight:800;">${esc(lbl)}</div><div class="muted" style="font-size:12px;">${esc(f._group_title)}</div>`;
      row.appendChild(tdLabel);

      sCols.forEach(s => {
        const td = document.createElement('td');
        const reportId = s.report_instance_id;
        const status = String(s.status||'draft');
        const locked = (status === 'locked');
        const v = teacherVal(reportId, f.id);
        const gObj = (state.groups||[]).find(x => String(x.key) === String(f._group_key));
        const canEditGroup = gObj ? (Number(gObj.can_edit||0) === 1) : true;

        const rawChild = (f.child && f.child.id) ? childVal(reportId, f.child.id) : '';
        const shownChild = rawChild ? childDisplay(f, rawChild) : '';

        td.innerHTML = `
          <div class="cellWrap">
            ${renderInputHtml(f, reportId, v, locked, canEditGroup)}
            ${(f.child && f.child.id) ? `<div class="cellChild"><strong>Schüler:</strong> ${shownChild ? esc(shownChild) : '—'}</div>` : ''}
          </div>
        `;
        row.appendChild(td);
      });

      itemBody.appendChild(row);
    });

    wireTeacherInputs(itemBody);
  }

  async function loadClass(classId){
    clearErr();
    elApp.style.display = 'none';
    const j = await api('load', { class_id: classId });

    state.class_id = classId;
    state.template = j.template;
    state.groups = j.groups;
    
    // In delegated mode: show ONLY groups delegated to current user (hide everything else completely)
    if (DELEGATED_MODE) {
      const uid = CURRENT_USER_ID;
      state.groups = (state.groups || []).filter(g => {
        const delUid = Number(g?.delegation?.user_id || 0);
        return (delUid > 0 && delUid === uid);
      });
    }
    
    state.delegation_users = j.delegation_users || [];
    state.delegations = j.delegations || [];
    state.period_label = j.period_label || 'Standard';

    // reset group selects (delegation badges etc.)
    groupSelect.innerHTML = '';
    gradeGroupSelect.innerHTML = '';
    state.students = j.students;
    state.values_teacher = j.values_teacher || {};
    state.values_child = j.values_child || {};
    state.class_report_instance_id = j.class_report_instance_id || 0;
    state.class_fields = j.class_fields || null;
    state.progress_summary = j.progress_summary || null;
    
    // In delegated mode: class fields should not be visible/editable here
    if (DELEGATED_MODE) {
      state.class_fields = null;
    }

    rebuildFieldMap();
    
    if (DELEGATED_MODE && (!state.groups || state.groups.length === 0)) {
      elApp.style.display = 'block';
      if (classFieldsBox) classFieldsBox.style.display = 'none';
      elMetaTop.textContent = 'Keine Delegationen vorhanden.';
      viewGrades.style.display = 'none';
      viewStudent.style.display = 'none';
      viewItem.style.display = 'none';
      showErr('Für dich sind in dieser Klasse keine delegierten Fachbereiche vorhanden.');
      return;
    }
    
    // keep client-side progress consistent (teacher edits update live)
    (state.students||[]).forEach(recomputeStudentProgress);
    recomputeFormsSummary();
    dbg('loaded', { class_id: state.class_id, class_report_instance_id: state.class_report_instance_id, class_fields_count: (state.class_fields?.fields||[]).length });

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



// --- delegations modal ---
function openDelegations(preselectGroupKey){
  if (!dlg) return;
  if (DELEGATED_MODE) return; // delegierter darf hier nicht delegieren
  dlg.style.display = 'block';

  // groups dropdown
  dlgGroup.innerHTML = '';
  (state.groups||[]).forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.key;
    opt.textContent = g.title || g.key;
    dlgGroup.appendChild(opt);
  });

  if (preselectGroupKey) {
    dlgGroup.value = String(preselectGroupKey);
  }
  if (!dlgGroup.value && dlgGroup.options.length) dlgGroup.value = dlgGroup.options[0].value;

  // users dropdown
  dlgUser.innerHTML = '';
  const optNone = document.createElement('option');
  optNone.value = '';
  optNone.textContent = '— (Delegation aufheben) —';
  dlgUser.appendChild(optNone);
  (state.delegation_users||[]).forEach(u => {
    const opt = document.createElement('option');
    opt.value = String(u.id);
    opt.textContent = `${u.name}${u.role==='admin' ? ' (Admin)' : ''}`;
    dlgUser.appendChild(opt);
  });

  // sync form with selected group
  syncDelegationForm();
  renderDelegationsList();
}

function closeDelegations(){
  if (!dlg) return;
  dlg.style.display = 'none';
}

function syncDelegationForm(){
  const gk = String(dlgGroup.value || '');
  const g = (state.groups||[]).find(x => String(x.key) === gk);
  const del = g && g.delegation ? g.delegation : null;

  dlgUser.value = (del && del.user_id) ? String(del.user_id) : '';
  dlgStatus.value = (del && del.status) ? String(del.status) : 'open';
  dlgNote.value = (del && del.note) ? String(del.note) : '';
}

function renderDelegationsList(){
  if (!dlgList) return;
  const rows = [];
  (state.groups||[]).forEach(g => {
    const del = g.delegation;
    if (!del || !del.user_id) return;
    const statusLbl = (del.status === 'done') ? 'fertig' : 'offen';
    const note = String(del.note || '').trim();
    rows.push(`
      <div class="del-row">
        <div class="l">
          <div class="t">${esc(g.title || g.key)}</div>
          <div class="s">→ ${esc(del.user_name || ('#'+del.user_id))} · ${esc(statusLbl)}${note ? ' · ' + esc(note) : ''}</div>
        </div>
        <button class="btn secondary" type="button" data-clear-deleg="${esc(g.key)}">Aufheben</button>
      </div>
    `);
  });
  dlgList.innerHTML = rows.length ? rows.join('') : `<div class="muted">Keine Delegationen gesetzt.</div>`;

  dlgList.querySelectorAll('[data-clear-deleg]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const gk = String(btn.getAttribute('data-clear-deleg') || '');
      if (!gk) return;
      await api('delegations_save', { class_id: state.class_id, period_label: state.period_label, delegations: [{ group_key: gk, user_id: 0 }] });
      await loadClass(state.class_id);
      openDelegations();
    });
  });
}

if (btnDelegationsTop) btnDelegationsTop.addEventListener('click', () => openDelegations());

if (dlg) {
  dlg.querySelectorAll('[data-close="1"]').forEach(el => el.addEventListener('click', () => closeDelegations()));
}

if (dlgGroup) {
  dlgGroup.addEventListener('change', () => syncDelegationForm());
}

if (dlgSave) {
  dlgSave.addEventListener('click', async () => {
    const gk = String(dlgGroup.value || '').trim();
    if (!gk) return;
    const uid = dlgUser.value ? Number(dlgUser.value) : 0;
    const status = String(dlgStatus.value || 'open');
    const note = String(dlgNote.value || '');

    await api('delegations_save', {
      class_id: state.class_id,
      period_label: state.period_label,
      delegations: [{ group_key: gk, user_id: uid, status, note }]
    });

    await loadClass(state.class_id);
    openDelegations();
  });
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
