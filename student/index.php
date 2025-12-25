<?php
// student/index.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_student();

$pdo = db();
$studentId = (int)($_SESSION['student']['id'] ?? 0);

$st = $pdo->prepare(
  "SELECT s.id, s.first_name, s.last_name, s.class_id,
          c.school_year, c.grade_level, c.label, c.name AS class_name, c.template_id
   FROM students s
   LEFT JOIN classes c ON c.id=s.class_id
   WHERE s.id=? LIMIT 1"
);
$st->execute([$studentId]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) {
  // session invalid
  redirect('student/logout.php');
}

function class_display(array $row): string {
  $label = (string)($row['label'] ?? '');
  $grade = isset($row['grade_level']) ? (int)$row['grade_level'] : null;
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  $name = (string)($row['class_name'] ?? '');
  return $name !== '' ? $name : '—';
}

$studentName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));
$classDisp = class_display($me);
$schoolYear = (string)($me['school_year'] ?? '');

$classTemplateId = (int)($me['template_id'] ?? 0);
$hasTemplate = ($classTemplateId > 0);

$cfg = app_config();
$brand = $cfg['app']['brand'] ?? [];
$orgName = (string)($brand['org_name'] ?? 'LEB Tool');
$logoPath = (string)($brand['logo_path'] ?? '');
$primary = (string)($brand['primary'] ?? '#0b57d0');
$secondary = (string)($brand['secondary'] ?? '#111111');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($orgName)?> – Schülerbereich</title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>
      body.page{
        font-family: "Druckschrift";
      }
      
    :root{ --primary: <?=h($primary)?>; --secondary: <?=h($secondary)?>; }

    .page-shell{ max-width: 1200px; margin: 0 auto; }
    .brand-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .brand-left{ display:flex; align-items:center; gap:12px; }
    .brand-logo{ height:40px; width:auto; display:block; }
    .brand-text{ display:flex; flex-direction:column; gap:2px; }
    .brand-title{ font-weight:850; letter-spacing:.2px; }
    .brand-sub{ color:var(--muted); font-size:12px; }

    .student-chip{ display:flex; flex-direction:column; align-items:flex-end; gap:2px; }
    .student-chip .n{ font-weight:800; }
    .student-chip .c{ color:var(--muted); font-size:12px; }

    .wiz{ display:grid; grid-template-columns: 300px 1fr; gap:14px; align-items:start; }
    @media (max-width: 980px){
      .wiz{ grid-template-columns: 1fr; }
      .sidebar{ position:static; top:auto; }
    }

    .sidebar{ position:sticky; top:14px; }
    .nav{ display:flex; flex-direction:column; gap:8px; }

    .nav .group{ border:1px solid var(--border); border-radius:14px; overflow:hidden; background: #fff; }
    .nav .group-h{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 10px; cursor:pointer; user-select:none; }
    .nav .group-h:hover{ background: rgba(0,0,0,0.02); }
    .nav .group-h .left{ display:flex; flex-direction:column; gap:2px; min-width:0; }
    .nav .group-h .title{ font-weight:750; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 210px; }
    .nav .group-h .sub{ color:var(--muted); font-size:12px; }

    .badge-mini{ display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 8px; border-radius:999px; font-size:12px; font-weight:750; border:1px solid var(--border); background: rgba(0,0,0,0.02); }
    .badge-mini.ok{ border-color: rgba(0,128,0,0.25); background: rgba(0,128,0,0.06); }
    .badge-mini.miss{ border-color: rgba(176,0,32,0.25); background: rgba(176,0,32,0.06); }

    .nav .items{ border-top:1px solid var(--border); padding:6px 6px 8px; display:none; }
    .nav .group.open .items{ display:block; }

    /* compact sub-items */
    .nav a.item{
      display:flex; justify-content:space-between; gap:10px; align-items:center;
      padding:7px 8px; border-radius:10px; color:inherit; text-decoration:none; cursor:pointer;
    }
    .nav a.item:hover{ background: rgba(0,0,0,0.03); }
    .nav a.item.active{ outline:2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
    .nav a.item.missing .lbl{ color: rgba(176,0,32,0.95); font-weight:650; }

    .nav a.item .txt{
      display:flex; align-items:center; gap:8px;
      min-width:0;
    }
    .nav a.item .dot{
      width:10px; height:10px; border-radius:999px; border:1px solid var(--border);
      background: rgba(0,0,0,0.04);
      flex: 0 0 auto;
    }
    .nav a.item.missing .dot{ border-color: rgba(176,0,32,0.45); background: rgba(176,0,32,0.12); }
    .nav a.item.ok .dot{ border-color: rgba(0,128,0,0.35); background: rgba(0,128,0,0.10); }
    .nav a.item .lbl{
      font-size:12px;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      max-width: 190px;
    }

    .content h2{ margin-top:0; }
    .step-meta{ color:var(--muted); font-size:12px; margin: -4px 0 10px; }

    .intro-box{ border:1px dashed var(--border); border-radius:14px; padding:14px; background: rgba(0,0,0,0.02); }
    .intro-box :first-child{ margin-top:0; }
    .intro-box :last-child{ margin-bottom:0; }

    .section-h{
      margin: 2px 0 10px;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: rgba(11,87,208,0.04);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .section-h .t{ font-weight:850; }
    .section-h .s{ color: var(--muted); font-size:12px; }

    .group-intro{
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 14px;
      background: #fff;
    }
    .group-intro .kicker{ color: var(--muted); font-size:12px; margin: 0 0 6px; }
    .group-intro h3{ margin: 0 0 8px; font-weight: 900; letter-spacing:.2px; }
    .group-intro .meta{
      display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;
    }
    .gi-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 10px;
      border-radius:999px;
      border:1px solid var(--border);
      background: rgba(0,0,0,0.02);
      color: var(--muted);
      font-size: 12px;
    }

    .q{ border:1px solid var(--border); border-radius:14px; padding:12px; background:#fff; margin-bottom:10px; }
    .q.missing{ border-color: rgba(176,0,32,0.25); background: rgba(176,0,32,0.03); }
    .q .lbl{ font-weight:800; margin-bottom:6px; }
    .q .help{ color:var(--muted); font-size:12px; margin-top:6px; }

    .opts{ display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-top:8px; }
    .opt{ display:flex; gap:10px; align-items:center; padding:10px; border-radius:14px; border:1px solid var(--border); background: #fff; cursor:pointer; user-select:none; }
    .opt:hover{ background: rgba(0,0,0,0.02); }
    .opt.selected{ outline: 2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
    .opt img{ width:38px; height:38px; object-fit: contain; border-radius:10px; border:1px solid var(--border); background: rgba(0,0,0,0.02); }
    .opt .lbl{ font-weight:750; }

    .wiz-actions{ display:flex; gap:10px; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-top:10px; }
    .wiz-actions .left{ display:flex; gap:10px; flex-wrap:wrap; }
    .pill-mini{ display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:999px; border:1px solid var(--border); color: var(--muted); font-size: 12px; background: rgba(0,0,0,0.02); }
    .spin{ width:16px; height:16px; border-radius:999px; border:2px solid rgba(0,0,0,0.15); border-top-color: rgba(0,0,0,0.65); display:inline-block; animation: s 0.8s linear infinite; }
    @keyframes s{ to{ transform: rotate(360deg); } }

    .locked-overlay{ border:1px solid rgba(176,0,32,0.25); background: rgba(176,0,32,0.05); padding:12px; border-radius:14px; margin-bottom: 10px; }
    .locked-overlay strong{ color: rgba(176,0,32,0.95); }

    .submit-box{ border:1px solid var(--border); border-radius:14px; padding:12px; background: rgba(0,0,0,0.02); }

    /* NEW: locked-only view */
    .locked-only{
      border:1px solid rgba(176,0,32,0.25);
      background: rgba(176,0,32,0.05);
      border-radius:14px;
      padding:16px;
    }
    .locked-only h2{ margin:0 0 6px; }
    .locked-only p{ margin: 0; }
  </style>
</head>
<body class="page">
  <div class="container page-shell">

    <div class="card">
      <div class="brand-top">
        <div class="brand-left">
          <?php if ($logoPath): ?>
            <img class="brand-logo" src="<?=h(url($logoPath))?>" alt="<?=h($orgName)?>">
          <?php endif; ?>
          <div class="brand-text">
            <div class="brand-title"><?=h($orgName)?></div>
            <div class="brand-sub">Schülerbereich – Lernentwicklungsbericht</div>
          </div>
        </div>

        <div class="brand-left" style="justify-content:flex-end; flex:1;">
          <div class="student-chip">
            <div class="n"><?=h($studentName ?: 'Schüler')?></div>
            <div class="c">Klasse <?=h($classDisp)?><?= $schoolYear ? ' · ' . h($schoolYear) : '' ?></div>
          </div>
          <div class="actions" style="justify-content:flex-end;">
            <a class="btn secondary" href="<?=h(url('student/logout.php'))?>">Logout</a>
          </div>
        </div>
      </div>
    </div>

    <!-- NEW: Locked-only container (shown when the teacher hasn't released input yet) -->
    <div id="lockedOnly" class="card" style="display:<?= $hasTemplate ? 'none' : 'block' ?>;">
      <div class="locked-only">
        <h2 id="lockedTitle"><?= $hasTemplate ? 'Eingabe noch nicht freigegeben' : 'Keine Vorlage zugeordnet' ?></h2>
        <p class="muted" id="lockedText">
          <?php if ($hasTemplate): ?>
            Deine Lehrkraft hat die Eingabe noch nicht freigegeben. Bitte versuche es später noch einmal.
          <?php else: ?>
            Für deine Klasse wurde noch keine Vorlage zugeordnet. Bitte wende dich an deine Lehrkraft.
          <?php endif; ?>
        </p>
      </div>
    </div>

    <!-- Wizard shell (hidden completely when locked) -->
    <div id="wizShell" class="wiz" style="<?= $hasTemplate ? '' : 'display:none;' ?>">
      <div class="sidebar">
        <div class="card">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div>
              <div style="font-weight:800;">Dein Bericht</div>
              <div class="muted" id="metaLine">Lade…</div>
            </div>
            <div class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> Speichern…</div>
          </div>

          <div style="margin-top:10px;" class="nav" id="nav"></div>
        </div>
      </div>

      <div class="content">
        <div class="card">
          <div id="lockBanner" style="display:none;" class="locked-overlay"></div>

          <h2 id="stepTitle">…</h2>
          <div class="step-meta" id="stepSub"></div>

          <div id="stepBody"></div>

          <div class="wiz-actions">
            <div class="left">
              <button class="btn secondary" type="button" id="btnPrev">Zurück</button>
              <button class="btn primary" type="button" id="btnNext">Weiter</button>
            </div>
            <div class="pill-mini" id="reqHint"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
(function(){
  const apiUrl = <?=json_encode(url('student/ajax/wizard_api.php'))?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const HAS_TEMPLATE = <?=json_encode($hasTemplate)?>;
  const placeholderIcon = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="12" fill="#f3f4f6"/><path d="M18 40c6-10 12-14 18-12s10 8 10 8" fill="none" stroke="#9ca3af" stroke-width="4" stroke-linecap="round"/><circle cx="24" cy="26" r="4" fill="#9ca3af"/></svg>');

  const elMeta = document.getElementById('metaLine');
  const elNav = document.getElementById('nav');
  const elTitle = document.getElementById('stepTitle');
  const elSub = document.getElementById('stepSub');
  const elBody = document.getElementById('stepBody');
  const elReqHint = document.getElementById('reqHint');
  const lockBanner = document.getElementById('lockBanner');

  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const savePill = document.getElementById('savePill');

  // NEW: containers for hard lock mode
  const elLockedOnly = document.getElementById('lockedOnly');
  const elWizShell = document.getElementById('wizShell');

  let state = {
    ok: false,
    template: null,
    report_instance_id: 0,
    report_status: 'draft',
    child_can_edit: true,
    ui: { display_mode: 'groups' },
    steps: [],
  };

  // Derived
  let displayMode = 'groups'; // 'groups' | 'items'
  let flatSteps = [];
  let activeStep = 0;

  // save debounce map
  const pendingTimers = new Map();
  let saveInFlight = 0;

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

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

  function isLocked(){
    // NEW: if locked, student sees ONLY the locked message (no wizard contents)
    return (!state.child_can_edit) || String(state.report_status) !== 'draft';
  }

  function showLockedOnly(title, text){
    if (elWizShell) elWizShell.style.display = 'none';
    if (elLockedOnly) elLockedOnly.style.display = 'block';

    const tEl = document.getElementById('lockedTitle');
    const pEl = document.getElementById('lockedText');
    if (tEl && title) tEl.textContent = title;
    if (pEl && text) pEl.textContent = text;

    if (btnPrev) btnPrev.disabled = true;
    if (btnNext) btnNext.disabled = true;
  }

  function fieldValueText(f){
    const t = f?.value?.text;
    return (t === null || typeof t === 'undefined') ? '' : String(t);
  }

  function fieldIsMissing(f){
    // Kids: everything required
    return fieldValueText(f).trim() === '';
  }

  function groupStats(groupFields){
    let total = 0, missing = 0;
    for (const f of (groupFields || [])) {
      total++;
      if (fieldIsMissing(f)) missing++;
    }
    return { total, missing, done: total - missing };
  }

  function getGroupsList(){
    return (Array.isArray(state.steps) ? state.steps : []).filter(s => s && !s.is_intro);
  }

  function groupFieldsByKey(gKey){
    const groups = getGroupsList();
    for (const g of groups) {
      const k = String(g.key || g.title || '');
      const t = String(g.title || g.key || '');
      if (String(k) === String(gKey) || String(t) === String(gKey)) return Array.isArray(g.fields) ? g.fields : [];
    }
    return [];
  }

  function buildFlatSteps(){
    const steps = Array.isArray(state.steps) ? state.steps : [];
    const intro = steps.find(s => s && s.is_intro);
    const groups = steps.filter(s => s && !s.is_intro);

    displayMode = (state.ui && state.ui.display_mode) ? state.ui.display_mode : 'groups';
    if (displayMode !== 'items') displayMode = 'groups';

    const out = [];
    if (intro) {
      out.push({ kind:'intro', key: intro.key || 'intro', title: intro.title || 'Start', intro_html: intro.intro_html || '' });
    } else {
      out.push({ kind:'intro', key:'intro', title:'Start', intro_html:'' });
    }

    if (displayMode === 'groups') {
      for (const g of groups) {
        out.push({
          kind:'group',
          key: String(g.key || g.title || 'Abschnitt'),
          title: String(g.title || g.key || 'Abschnitt'),
          group: String(g.key || g.title || 'Abschnitt'),
          fields: Array.isArray(g.fields) ? g.fields : []
        });
      }
    } else {
      // items: NEW "group intro" page before first item of each group
      for (const g of groups) {
        const gKey = String(g.key || g.title || 'Abschnitt');
        const gTitle = String(g.title || g.key || 'Abschnitt');
        const fields = Array.isArray(g.fields) ? g.fields : [];

        out.push({
          kind: 'group_intro',
          key: 'gi:' + gKey,
          title: gTitle,
          group: gKey,
          groupTitle: gTitle,
          fields: fields
        });

        for (const f of fields) {
          out.push({
            kind:'field',
            key: gKey + ':' + String(f.id),
            title: gTitle,
            group: gKey,
            groupTitle: gTitle,
            field: f
          });
        }
      }
    }

    out.push({ kind:'submit', key:'submit', title:'Fertig', group:'Fertig' });

    flatSteps = out;
    if (activeStep < 0) activeStep = 0;
    if (activeStep >= flatSteps.length) activeStep = flatSteps.length - 1;
  }

  function setLockedUi(){
    const locked = isLocked();
    if (locked) {
      lockBanner.style.display = 'block';
      lockBanner.innerHTML = '<strong>Gerade gesperrt.</strong> Deine Lehrkraft hat die Eingabe im Moment gesperrt oder du hast bereits abgegeben.';
    } else {
      lockBanner.style.display = 'none';
      lockBanner.textContent = '';
    }
    btnNext.disabled = locked;
    btnPrev.disabled = locked && activeStep === 0;
  }

  function renderNav(){
    const groups = getGroupsList();

    const html = [];
    html.push(`<div class="group" data-kind="intro">
      <div class="group-h" data-jump="0">
        <div class="left">
          <div class="title">Start</div>
          <div class="sub">Info</div>
        </div>
        <span class="badge-mini ok">✓</span>
      </div>
    </div>`);

    function stepIndexForGroupKey(gKey){
      if (displayMode === 'groups') {
        return flatSteps.findIndex(s => s.kind==='group' && String(s.group)===String(gKey));
      }
      // items: jump to group intro
      return flatSteps.findIndex(s => s.kind==='group_intro' && String(s.group)===String(gKey));
    }

    for (const g of groups) {
      const gKey = String(g.key || g.title || 'Abschnitt');
      const gTitle = String(g.title || g.key || 'Abschnitt');
      const fields = Array.isArray(g.fields) ? g.fields : [];
      const st = groupStats(fields);
      const badgeCls = (st.missing === 0) ? 'ok' : 'miss';
      const badgeTxt = (st.missing === 0) ? '✓' : String(st.missing);

      if (displayMode === 'groups') {
        const idx = stepIndexForGroupKey(gKey);
        html.push(`<div class="group" data-group="${esc(gKey)}">
          <div class="group-h" data-jump="${idx}">
            <div class="left">
              <div class="title">${esc(gTitle)}</div>
              <div class="sub">${st.done}/${st.total}</div>
            </div>
            <span class="badge-mini ${badgeCls}">${esc(badgeTxt)}</span>
          </div>
        </div>`);
      } else {
        // items: nested list (compact), but group jump goes to group intro page
        const idx = stepIndexForGroupKey(gKey);

        html.push(`<div class="group" data-group="${esc(gKey)}">
          <div class="group-h" data-toggle="${esc(gKey)}" data-jump="${idx}">
            <div class="left">
              <div class="title">${esc(gTitle)}</div>
              <div class="sub">${st.done}/${st.total}</div>
            </div>
            <span class="badge-mini ${badgeCls}">${esc(badgeTxt)}</span>
          </div>
          <div class="items">` +
            fields.map((f, i) => {
              const missing = fieldIsMissing(f);
              const stepIdx = flatSteps.findIndex(s => s.kind==='field' && String(s.group)===String(gKey) && String(s.field?.id)===String(f.id));
              const active = stepIdx === activeStep;
              const fullLbl = String(f.label || f.name || ('Frage ' + (i+1)));
              return `<a class="item ${missing?'missing':'ok'} ${active?'active':''}" data-jump="${stepIdx}" title="${esc(fullLbl)}">
                <div class="txt">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="lbl">${esc('Frage ' + (i+1))}</span>
                </div>
                <span class="badge-mini ${missing?'miss':'ok'}">${missing?'!':'✓'}</span>
              </a>`;
            }).join('') +
          `</div>
        </div>`);
      }
    }

    const submitIdx = flatSteps.findIndex(s => s.kind==='submit');
    html.push(`<div class="group" data-kind="submit">
      <div class="group-h" data-jump="${submitIdx}">
        <div class="left">
          <div class="title">Fertig</div>
          <div class="sub">Abgeben</div>
        </div>
        <span class="badge-mini">→</span>
      </div>
    </div>`);

    elNav.innerHTML = html.join('');

    elNav.querySelectorAll('[data-jump]').forEach(el => {
      el.addEventListener('click', () => {
        const v = Number(el.getAttribute('data-jump'));
        if (!Number.isFinite(v) || v < 0) return;
        activeStep = v;
        render();
      });
    });

    if (displayMode === 'items') {
      const cur = flatSteps[activeStep];
      const curGroup = cur && (cur.group || cur.groupTitle);
      elNav.querySelectorAll('.group').forEach(g => {
        const key = g.getAttribute('data-group');
        if (key && curGroup && String(key) === String(curGroup)) g.classList.add('open');
      });
      elNav.querySelectorAll('[data-toggle]').forEach(h => {
        h.addEventListener('click', () => {
          const parent = h.closest('.group');
          if (!parent) return;
          parent.classList.toggle('open');
        });
      });
    }
  }

  function setSaving(on){
    savePill.style.display = on ? 'inline-flex' : 'none';
  }

  async function saveFieldValue(fieldId, valueText){
    // safety: never save if locked (even if someone forces UI visible)
    if (isLocked()) return;
    saveInFlight++;
    setSaving(true);
    try {
      await api('save_value', { template_field_id: Number(fieldId), value_text: String(valueText ?? '') });
    } finally {
      saveInFlight--;
      if (saveInFlight <= 0) setSaving(false);
    }
  }

  function debounceSave(fieldId, valueText, delayMs=450){
    if (isLocked()) return;
    const key = String(fieldId);
    if (pendingTimers.has(key)) {
      clearTimeout(pendingTimers.get(key));
      pendingTimers.delete(key);
    }
    pendingTimers.set(key, setTimeout(async () => {
      pendingTimers.delete(key);
      try { await saveFieldValue(fieldId, valueText); } catch(e){ /* quiet */ }
    }, delayMs));
  }

  function optionLabel(o){
    const lbl = (o && (o.label ?? o.title ?? o.name)) ? String(o.label ?? o.title ?? o.name) : '';
    if (lbl) return lbl;
    const val = (o && (o.value ?? o.key ?? o.id)) ? String(o.value ?? o.key ?? o.id) : '';
    return val || 'Option';
  }
  function optionValue(o){
    if (!o) return '';
    if (typeof o.value !== 'undefined') return String(o.value);
    if (typeof o.key !== 'undefined') return String(o.key);
    if (typeof o.id !== 'undefined') return String(o.id);
    return optionLabel(o);
  }

  function renderSectionHeader(groupTitle, fields){
    const st = groupStats(fields || []);
    const miss = st.missing;
    const right = (st.total > 0)
      ? `<span class="badge-mini ${miss===0?'ok':'miss'}">${miss===0?'✓':esc(String(miss))}</span>`
      : '';
    const sub = (st.total > 0) ? `${st.done}/${st.total} erledigt` : '';
    return `<div class="section-h">
      <div>
        <div class="t">${esc(groupTitle)}</div>
        <div class="s">${esc(sub)}</div>
      </div>
      ${right}
    </div>`;
  }

  function renderGroupIntro(groupTitle, fields){
    const st = groupStats(fields || []);
    const miss = st.missing;
    const total = st.total;
    const done = st.done;

    return `
      <div class="group-intro">
        <p class="kicker">Neuer Abschnitt</p>
        <h3>${esc(groupTitle)}</h3>
        <div class="muted">Hier kommen ${esc(String(total))} Fragen. Du kannst jederzeit im Menü springen.</div>

        <div class="meta">
          <span class="gi-pill">✅ Erledigt: <strong style="color:inherit;">${esc(String(done))}</strong></span>
          <span class="gi-pill">⏳ Offen: <strong style="color:inherit;">${esc(String(miss))}</strong></span>
        </div>

        <div class="actions" style="justify-content:flex-start; margin-top:12px;">
          <button class="btn primary" type="button" id="btnStartGroup">Abschnitt starten</button>
        </div>
      </div>
    `;
  }

  function renderFieldBlock(f){
    const fid = Number(f.id);
    const type = String(f.type || 'text');
    const label = String(f.label || f.name || 'Feld');
    const help = String(f.help || '');
    const multiline = !!f.multiline;
    const val = fieldValueText(f);

    const missing = fieldIsMissing(f);
    const wrapCls = 'q' + (missing ? ' missing' : '');

    if (['radio','select','grade'].includes(type) || type === 'checkbox') {
      let opts = [];
      if (type === 'checkbox') {
        opts = [
          { value: '1', label: 'Ja' },
          { value: '0', label: 'Nein' },
        ];
      } else {
        opts = Array.isArray(f.options) ? f.options : [];
      }

      return `<div class="${wrapCls}" data-field="${fid}">
        <div class="lbl">${esc(label)}</div>
        <div class="opts">` +
          opts.map(o => {
            const oVal = optionValue(o);
            const oLbl = optionLabel(o);
            const selected = String(val) === String(oVal);
            const iconUrl = o && o.icon_url ? String(o.icon_url) : '';
            return `<div class="opt ${selected?'selected':''}" data-opt="${esc(oVal)}" role="button" tabindex="0">
              ${iconUrl ? `<img src="${esc(iconUrl)}" alt="">` : `<img src="${esc(placeholderIcon)}" alt="" style="opacity:.35;">`}
              <div><div class="lbl">${esc(oLbl)}</div></div>
            </div>`;
          }).join('') +
        `</div>
        ${help ? `<div class="help">${esc(help)}</div>` : ``}
      </div>`;
    }

    if (multiline || type === 'textarea') {
      return `<div class="${wrapCls}" data-field="${fid}">
        <div class="lbl">${esc(label)}</div>
        <textarea rows="4" class="input" data-input="1" style="width:100%;">${esc(val)}</textarea>
        ${help ? `<div class="help">${esc(help)}</div>` : ``}
      </div>`;
    }

    return `<div class="${wrapCls}" data-field="${fid}">
      <div class="lbl">${esc(label)}</div>
      <input type="text" class="input" data-input="1" style="width:100%;" value="${esc(val)}">
      ${help ? `<div class="help">${esc(help)}</div>` : ``}
    </div>`;
  }

  function attachFieldHandlers(container){
    container.querySelectorAll('[data-field] [data-input="1"]').forEach(inp => {
      const wrap = inp.closest('[data-field]');
      const fid = Number(wrap.getAttribute('data-field'));
      inp.addEventListener('input', () => {
        if (isLocked()) return;
        const v = inp.value;
        updateFieldLocal(fid, v);
        debounceSave(fid, v);
        renderNav();
        updateReqHint();
        markMissingBlocks(container);
      });
      inp.addEventListener('blur', () => {
        if (isLocked()) return;
        const v = inp.value;
        updateFieldLocal(fid, v);
        saveFieldValue(fid, v).catch(()=>{});
      });
    });

    container.querySelectorAll('[data-field] .opt').forEach(card => {
      const wrap = card.closest('[data-field]');
      const fid = Number(wrap.getAttribute('data-field'));
      const v = card.getAttribute('data-opt') || '';
      const click = async () => {
        if (isLocked()) return;
        updateFieldLocal(fid, v);
        render();
        try { await saveFieldValue(fid, v); } catch(e){}
      };
      card.addEventListener('click', click);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); click(); }
      });
    });
  }

  function updateFieldLocal(fieldId, valueText){
    const steps = Array.isArray(state.steps) ? state.steps : [];
    for (const s of steps) {
      if (!s || s.is_intro) continue;
      const fields = Array.isArray(s.fields) ? s.fields : [];
      for (const f of fields) {
        if (Number(f.id) === Number(fieldId)) {
          if (!f.value) f.value = { text: null, json: null };
          f.value.text = String(valueText ?? '');
          f.value.json = null;
          return;
        }
      }
    }
  }

  function markMissingBlocks(container){
    container.querySelectorAll('.q[data-field]').forEach(q => {
      const fid = Number(q.getAttribute('data-field'));
      const f = findField(fid);
      if (!f) return;
      q.classList.toggle('missing', fieldIsMissing(f));
    });
  }

  function findField(fid){
    const steps = Array.isArray(state.steps) ? state.steps : [];
    for (const s of steps) {
      if (!s || s.is_intro) continue;
      const fields = Array.isArray(s.fields) ? s.fields : [];
      for (const f of fields) if (Number(f.id) === Number(fid)) return f;
    }
    return null;
  }

  function currentStepMissingCount(){
    const cur = flatSteps[activeStep];
    if (!cur) return 0;
    if (cur.kind === 'group') return groupStats(cur.fields).missing;
    if (cur.kind === 'group_intro') return groupStats(cur.fields).missing;
    if (cur.kind === 'field') return fieldIsMissing(cur.field) ? 1 : 0;
    return 0;
  }

  function updateReqHint(){
    const cur = flatSteps[activeStep];
    if (!cur) { elReqHint.textContent = ''; return; }

    if (cur.kind === 'intro') {
      elReqHint.textContent = 'Klicke „Los geht\'s“, um zu starten.';
      return;
    }
    if (cur.kind === 'group_intro') {
      const st = groupStats(cur.fields || []);
      elReqHint.textContent = (st.missing === 0) ? 'Abschnitt ist schon komplett ✓' : `${st.missing} fehlen in diesem Abschnitt (du kannst starten).`;
      return;
    }
    if (cur.kind === 'submit') {
      const allMissing = totalMissingCount();
      elReqHint.textContent = allMissing === 0 ? 'Alles erledigt – du kannst abgeben.' : `${allMissing} Felder fehlen noch.`;
      return;
    }

    const miss = currentStepMissingCount();
    elReqHint.textContent = (miss === 0) ? 'Alles ausgefüllt ✓' : `${miss} fehlen noch (du kannst trotzdem weiter).`;
  }

  function totalMissingCount(){
    const groups = getGroupsList();
    let missing = 0;
    for (const g of groups) {
      const fields = Array.isArray(g.fields) ? g.fields : [];
      for (const f of fields) if (fieldIsMissing(f)) missing++;
    }
    return missing;
  }

  async function handleSubmit(){
    if (isLocked()) return;

    // flush debounces
    for (const [k,t] of pendingTimers.entries()) {
      clearTimeout(t);
      pendingTimers.delete(k);
    }

    const missing = totalMissingCount();
    if (missing > 0) {
      alert('Es fehlen noch ' + missing + ' Felder. Bitte fülle alles aus.');
      return;
    }

    if (!confirm('Möchtest du jetzt abgeben? Danach kannst du nichts mehr ändern.')) return;

    try {
      btnNext.disabled = true;
      btnPrev.disabled = true;
      setSaving(true);
      await api('submit', {});
      const j = await api('bootstrap', {});
      state = j;

      // If submit locks the report, switch to locked-only
      if (isLocked()) {
        showLockedOnly();
        return;
      }

      buildFlatSteps();
      activeStep = flatSteps.findIndex(s => s.kind==='submit');
      if (activeStep < 0) activeStep = flatSteps.length - 1;
      render();
      alert('Danke! Du hast abgegeben.');
    } catch(e){
      alert(e?.message || 'Fehler beim Abgeben.');
    } finally {
      setSaving(false);
      btnPrev.disabled = false;
      btnNext.disabled = false;
    }
  }

  function render(){
    // NEW: if locked, do not render any wizard content at all
    if (isLocked()) {
        const st = String(state.report_status || '');
        if (st === 'submitted') {
          showLockedOnly('Bereits abgegeben', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.');
        } else if (st === 'locked') {
          showLockedOnly('Eingabe gesperrt', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.');
        } else {
          showLockedOnly('Eingabe noch nicht freigegeben', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben. Bitte versuche es später noch einmal.');
        }
        return;
      }

    buildFlatSteps();

    const tplName = state.template ? String(state.template.name || '') : '';
    const ver = state.template ? String(state.template.version || '') : '';
    elMeta.textContent = tplName ? (tplName + (ver ? (' · v' + ver) : '')) : 'Formular';

    setLockedUi();
    renderNav();

    const cur = flatSteps[activeStep];
    if (!cur) return;

    btnPrev.style.visibility = (activeStep <= 0) ? 'hidden' : 'visible';

    if (cur.kind === 'intro') {
      elTitle.textContent = 'Start';
      elSub.textContent = 'Bitte lies die Infos. Danach geht es los.';
      const html = (cur.intro_html || '').trim();
      elBody.innerHTML = `<div class="intro-box">${html ? html : '<p class="muted">Keine Intro-Infos hinterlegt.</p>'}</div>`;
      btnNext.textContent = 'Los geht’s';
      btnPrev.disabled = (activeStep <= 0);
      btnNext.disabled = false;
    }

    else if (cur.kind === 'group') {
      elTitle.textContent = cur.title;
      elSub.textContent = 'Du kannst weiterklicken und später zurückspringen, wenn etwas fehlt.';
      const section = renderSectionHeader(cur.title, cur.fields || []);
      elBody.innerHTML = section + ((cur.fields || []).map(f => renderFieldBlock(f)).join('') || '<p class="muted">Keine Felder.</p>');
      attachFieldHandlers(elBody);

      btnNext.textContent = 'Weiter';
      btnNext.disabled = false;
    }

    else if (cur.kind === 'group_intro') {
      const fields = Array.isArray(cur.fields) ? cur.fields : [];
      elTitle.textContent = cur.groupTitle || cur.title || 'Abschnitt';
      elSub.textContent = 'Bevor es losgeht: kurze Übersicht.';
      elBody.innerHTML = renderGroupIntro(cur.groupTitle || cur.title || 'Abschnitt', fields);

      const b = document.getElementById('btnStartGroup');
      if (b) {
        b.addEventListener('click', () => {
          // jump to first field of this group
          const idx = flatSteps.findIndex(s => s.kind === 'field' && String(s.group) === String(cur.group));
          if (idx >= 0) { activeStep = idx; render(); }
          else { // no fields? just move on
            activeStep = Math.min(activeStep + 1, flatSteps.length - 1);
            render();
          }
        });
      }

      btnNext.textContent = 'Weiter';
      btnNext.disabled = false;
    }

    else if (cur.kind === 'field') {
      const f = cur.field;
      elTitle.textContent = cur.groupTitle;
      elSub.textContent = 'Eine Frage nach der anderen. Du kannst jederzeit zurückspringen.';
      elBody.innerHTML = renderFieldBlock(f);
      attachFieldHandlers(elBody);

      btnNext.textContent = 'Weiter';
      btnNext.disabled = false;
    }

    else { // submit
      elTitle.textContent = 'Fertig';
      const missing = totalMissingCount();
      elSub.textContent = missing === 0 ? 'Alles ist ausgefüllt.' : 'Es fehlen noch Felder.';
      elBody.innerHTML = `
        <div class="submit-box">
          <p style="margin-top:0;">Wenn alles ausgefüllt ist, kannst du abgeben.</p>
          <p class="muted">Fehlende Felder: <strong>${missing}</strong></p>
          <div class="actions" style="justify-content:flex-start;">
            <button class="btn primary" type="button" id="btnSubmit" ${missing>0 ? 'disabled' : ''}>Abgeben</button>
          </div>
        </div>
      `;
      const b = document.getElementById('btnSubmit');
      if (b) b.addEventListener('click', handleSubmit);

      btnNext.textContent = '—';
      btnNext.disabled = true;
    }

    // Prev/Next behavior
    btnPrev.onclick = () => {
      if (activeStep > 0) { activeStep--; render(); }
    };
    btnNext.onclick = () => {
      const cur = flatSteps[activeStep];
      if (!cur) return;
      if (cur.kind === 'submit') return;
      activeStep = Math.min(activeStep + 1, flatSteps.length - 1);
      render();
    };

    updateReqHint();

    // Keep current group open in items mode
    if (displayMode === 'items') {
      const cur = flatSteps[activeStep];
      const curGroup = cur && (cur.group || cur.groupTitle);
      elNav.querySelectorAll('.group').forEach(g => {
        const key = g.getAttribute('data-group');
        if (key && curGroup && String(key) === String(curGroup)) g.classList.add('open');
      });
    }
  }

  (async function init(){
    try {
        if (!HAS_TEMPLATE) {
            // Server hat bereits die "Keine Vorlage" Meldung angezeigt.
            // Wizard bleibt komplett aus.
            return;
          }
    
      const j = await api('bootstrap', {});
      state = j;

      // NEW: If locked, show ONLY the locked message and stop here.
      if (isLocked()) {
        const st = String(state.report_status || '');
        if (st === 'submitted') {
          showLockedOnly('Bereits abgegeben', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.');
        } else if (st === 'locked') {
          showLockedOnly('Eingabe gesperrt', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.');
        } else {
          showLockedOnly('Eingabe noch nicht freigegeben', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben. Bitte versuche es später noch einmal.');
        }
        return;
      }

      buildFlatSteps();
      activeStep = 0;
      render();
    } catch (e) {
        const msg = String(e?.message || 'Fehler');

        // Wenn API "keine Vorlage" meldet, zeigen wir locked-only statt Wizard-Fehler.
        if (msg.toLowerCase().includes('keine vorlage') || msg.toLowerCase().includes('vorlage zugeordnet')) {
          showLockedOnly('Keine Vorlage zugeordnet', 'Für deine Klasse wurde noch keine Vorlage zugeordnet. Bitte wende dich an deine Lehrkraft.');
          return;
        }

        elMeta.textContent = 'Fehler beim Laden.';
        elBody.innerHTML = `<div class="alert danger"><strong>${esc(msg)}</strong></div>`;
        btnPrev.disabled = true;
        btnNext.disabled = true;
      }
  })();
})();
</script>
</body>
</html>
