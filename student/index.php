<?php
// student/index.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_student();

$pdo = db();
$studentId = (int)($_SESSION['student']['id'] ?? 0);

$st = $pdo->prepare(
  "SELECT s.id, s.first_name, s.last_name, s.class_id,
          c.school_year, c.grade_level, c.label, c.name AS class_name, c.template_id, c.tts_enabled
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
$ttsEnabled = (int)($me['tts_enabled'] ?? 0) === 1;

$cfg = app_config();
$brand = $cfg['app']['brand'] ?? [];
$studentCfg = $cfg['student'] ?? [];
$orgName = (string)($brand['org_name'] ?? 'LEB Tool');
$logoPath = (string)($brand['logo_path'] ?? '');
$primary = (string)($brand['primary'] ?? '#0b57d0');
$secondary = (string)($brand['secondary'] ?? '#111111');
$ttsRate = (float)($studentCfg['tts_rate'] ?? 0.95);
if ($ttsRate <= 0) $ttsRate = 1.0;
$ttsRate = max(0.5, min(1.5, $ttsRate));
$ttsVoicePref = trim((string)($studentCfg['tts_voice'] ?? ''));
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($orgName)?> – <?=h(t('student.html_title'))?></title>
  <?php render_favicons(); ?>
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

    .save-status{
      margin-top:4px;
      font-size:12px;
      color: var(--muted);
      display:flex;
      align-items:center;
      gap:6px;
      min-height:18px;
    }
    .save-status[data-state="saving"]{ color: #0b57d0; font-weight:750; }
    .save-status[data-state="ok"]{ color: #0b7a0b; font-weight:750; }
    .save-status[data-state="error"]{ color: #b00020; font-weight:800; }

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
    .q .lbl{ font-weight:800; }
    .q .help{ color:var(--muted); font-size:12px; margin-top:6px; }

    .opts{ display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-top:8px; }
    .opt{ display:flex; gap:10px; align-items:center; padding:10px; border-radius:14px; border:1px solid var(--border); background: #fff; cursor:pointer; user-select:none; }
    .opt:hover{ background: rgba(0,0,0,0.02); }
    .opt.selected{ outline: 2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
    .opt img{ width:38px; height:38px; object-fit: contain; }
    .opt .lbl{ font-weight:750; }

    .wiz-actions{ display:flex; gap:10px; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-top:10px; }
    .wiz-actions .left{ display:flex; gap:10px; flex-wrap:wrap; }
    .pill-mini{ display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:999px; border:1px solid var(--border); color: var(--muted); font-size: 12px; background: rgba(0,0,0,0.02); }
    .spin{ width:16px; height:16px; border-radius:999px; border:2px solid rgba(0,0,0,0.15); border-top-color: rgba(0,0,0,0.65); display:inline-block; animation: s 0.8s linear infinite; }
    @keyframes s{ to{ transform: rotate(360deg); } }

    .tts-bar{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; border:1px dashed var(--border); border-radius:12px; margin-bottom:10px; background: rgba(0,0,0,0.02); }
    .tts-title{ font-weight:800; }
    .tts-status{ color: var(--muted); font-size:12px; }
    .tts-reading{ background: #fff7c2; transition: background .15s ease; }

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

    /* progress bars */
    .progress-wrap{ }
    .progress-meta{ display:flex; justify-content:space-between; gap:10px; font-size:12px; color:var(--muted); margin-bottom:6px; }
    .progress{ height:10px; border-radius:999px; border:1px solid var(--border); background: rgba(0,0,0,0.02); overflow:hidden; }
    .progress-bar{ height:100%; width:0%; background: var(--primary); border-radius:999px; transition: width .2s ease; }
    .progress.sm{ height:8px; }
    .progress-bar.ok{ background: rgba(0,128,0,0.65); }
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
            <div class="brand-sub" id="brandSubtitle" data-i18n="student.subtitle">
              <?=h(t('student.subtitle'))?>
            </div>
          </div>
        </div>

        <div class="brand-left" style="justify-content:flex-end; flex:1;">
          <div class="student-chip">
            <div class="n"><?=h($studentName ?: t('student.fallback_name'))?></div>
            <div class="c"><span id="classLabelText" data-i18n="student.class_label"><?=h(t('student.class_label'))?></span> <?=h($classDisp)?><?= $schoolYear ? ' · ' . h($schoolYear) : '' ?></div>
          </div>
          <div class="actions" style="justify-content:flex-end;">
            <?php $lang = ui_lang(); ?>
            <div class="lang-switch" aria-label="<?=h(t('student.lang_switch_aria', 'Sprache wechseln'))?>" style="margin-right:8px;">
              <a class="lang <?= $lang==='de' ? 'active' : '' ?>" data-lang="de" href="<?=h(url_with_lang('de'))?>" title="<?=h(t('student.lang_de', 'Deutsch'))?>">🇩🇪</a>
              <a class="lang <?= $lang==='en' ? 'active' : '' ?>" data-lang="en" href="<?=h(url_with_lang('en'))?>" title="<?=h(t('student.lang_en', 'English'))?>">🇬🇧</a>
              </div>
            <a class="btn secondary" id="logoutBtn" href="<?=h(url('student/logout.php'))?>"><?=h(t('student.logout', 'Logout'))?></a>
          </div>
        </div>
      </div>
    </div>

    <!-- Locked-only container -->
    <div id="lockedOnly" class="card" style="display:<?= $hasTemplate ? 'none' : 'block' ?>;">
      <div class="locked-only">
        <h2 id="lockedTitle"><?= $hasTemplate ? h(t('student.locked.pending_title')) : h(t('student.locked.none_title')) ?></h2>
        <p class="muted" id="lockedText">
          <?php if ($hasTemplate): ?>
            <?=h(t('student.locked.pending_text'))?>
          <?php else: ?>
            <?=h(t('student.locked.none_text'))?>
          <?php endif; ?>
        </p>
      </div>
    </div>

    <!-- Wizard shell -->
    <div id="wizShell" class="wiz" style="<?= $hasTemplate ? '' : 'display:none;' ?>">
      <div class="sidebar">
        <div class="card">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div>
              <div style="font-weight:800;"><?=h(t('student.sidebar.report'))?></div>
              <div class="save-status" id="saveStatus" aria-live="polite" style="display:none;"></div>

              <div id="overallProgressWrap" class="progress-wrap" style="margin-top:10px;">
                <div class="progress-meta"><span id="overallProgressText">—</span><span id="overallProgressPct"></span></div>
                <div class="progress"><div id="overallProgressBar" class="progress-bar"></div></div>
              </div>
            </div>
            <div class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> <?=h(t('student.sidebar.saving'))?></div>
          </div>

          <div style="margin-top:10px;" class="nav" id="nav"></div>
        </div>
      </div>

      <div class="content">
        <div class="card">
          <div id="lockBanner" style="display:none;" class="locked-overlay"></div>

          <div id="ttsBar" class="tts-bar" style="display:none;">
            <div>
              <div class="tts-title" data-i18n="student.tts.title"><?=h(t('student.tts.title', 'Vorlesen'))?></div>
              <div class="tts-status" id="ttsStatus"><?=h(t('student.tts.ready', 'Bereit zum Vorlesen.'))?></div>
            </div>
            <div class="tts-actions">
              <button class="btn secondary" type="button" id="ttsButton" aria-label="<?=h(t('student.tts.start', 'Aktuellen Abschnitt vorlesen'))?>">🔈</button>
            </div>
          </div>

          <h2 id="stepTitle">…</h2>
          <div class="step-meta" id="stepSub"></div>

          <div id="stepBody"></div>

          <div class="wiz-actions">
            <div class="left">
              <button class="btn secondary" type="button" id="btnPrev"><?=h(t('student.buttons.prev'))?></button>
              <button class="btn primary" type="button" id="btnNext"><?=h(t('student.buttons.next'))?></button>
            </div>
            <div class="pill-mini" id="reqHint"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="muted" id="metaLine" style="text-align: center;"><?=h(t('student.meta.loading'))?></div>
  </div>

<script>
(function(){
  const apiUrl = <?=json_encode(url('student/ajax/wizard_api.php'))?>;
  const ORG_NAME = <?= json_encode($orgName) ?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const HAS_TEMPLATE = <?=json_encode($hasTemplate)?>;
  const TTS_ALLOWED = <?=json_encode($ttsEnabled)?>;
  const TTS_RATE = Number(<?=json_encode($ttsRate)?>) || 1;
  const TTS_VOICE_PREF = <?=json_encode($ttsVoicePref)?>;
  const placeholderIcon = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="12" fill="#f3f4f6"/><path d="M18 40c6-10 12-14 18-12s10 8 10 8" fill="none" stroke="#9ca3af" stroke-width="4" stroke-linecap="round"/><circle cx="24" cy="26" r="4" fill="#9ca3af"/></svg>');

  const elMeta = document.getElementById('metaLine');
  const elOverallWrap = document.getElementById('overallProgressWrap');
  const elOverallBar = document.getElementById('overallProgressBar');
  const elOverallText = document.getElementById('overallProgressText');
  const elOverallPct = document.getElementById('overallProgressPct');
  const elNav = document.getElementById('nav');
  const elTitle = document.getElementById('stepTitle');
  const elSub = document.getElementById('stepSub');
  const elBody = document.getElementById('stepBody');
  const elReqHint = document.getElementById('reqHint');
  const lockBanner = document.getElementById('lockBanner');

  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const savePill = document.getElementById('savePill');
  const saveStatus = document.getElementById('saveStatus');

  const elLockedOnly = document.getElementById('lockedOnly');
  const elWizShell = document.getElementById('wizShell');

  const ttsBar = document.getElementById('ttsBar');
  const ttsButton = document.getElementById('ttsButton');
  const ttsStatus = document.getElementById('ttsStatus');

  let state = {
    ok: false,
    template: null,
    report_instance_id: 0,
    report_status: 'draft',
    child_can_edit: true,
    ui: { display_mode: 'groups' },
    steps: [],
  };

  let displayMode = 'groups';
  let flatSteps = [];
  let activeStep = 0;

  const pendingTimers = new Map();
  let saveInFlight = 0;
  let lastSaveAt = null;

  let T = <?= json_encode(ui_translations(), JSON_UNESCAPED_UNICODE) ?>;
  const t = (key, fallback = '') => (T && Object.prototype.hasOwnProperty.call(T, key)) ? T[key] : (fallback ?? key);
  const tfmt = (key, fallback = '', repl = {}) => {
    let s = t(key, fallback);
    Object.entries(repl || {}).forEach(([k, v]) => {
      s = s.replace(new RegExp('{' + k + '}', 'g'), String(v));
    });
    return s;
  };

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  // -------- Vorlese-Funktion (Web Speech API) --------
  const ttsSupported = typeof window !== 'undefined' && 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  let ttsUtterance = null;

  function setTtsHighlight(on){
    if (!elBody) return;
    elBody.classList.toggle('tts-reading', !!on);
  }

  function updateTtsUi(text){
    if (!ttsBar) return;
    if (!TTS_ALLOWED) {
      ttsBar.style.display = 'flex';
      if (ttsButton) ttsButton.style.display = 'none';
      if (ttsStatus) ttsStatus.textContent = t('student.tts.disabled', 'Vorlesen wurde von deiner Lehrkraft deaktiviert.');
      return;
    }
    if (!ttsSupported) {
      ttsBar.style.display = 'flex';
      if (ttsButton) ttsButton.style.display = 'none';
      if (ttsStatus) ttsStatus.textContent = t('student.tts.unsupported', 'Vorlesen wird von diesem Gerät nicht unterstützt.');
      return;
    }

    ttsBar.style.display = 'flex';
    if (ttsButton) {
      ttsButton.style.display = '';
      const isSpeaking = speechSynthesis.speaking;
      ttsButton.innerHTML = isSpeaking ? '⏹' : '🔈';
      ttsButton.setAttribute('aria-label', isSpeaking
        ? t('student.tts.stop', 'Stopp')
        : t('student.tts.start', 'Aktuellen Abschnitt vorlesen'));
    }
    if (ttsStatus) {
      if (text) {
        ttsStatus.textContent = text;
      } else {
        ttsStatus.textContent = speechSynthesis.speaking
          ? t('student.tts.reading', 'Liest gerade …')
          : t('student.tts.ready', 'Bereit zum Vorlesen.');
      }
    }
  }

  function stopTts(){
    if (!ttsSupported || !speechSynthesis) return;
    try { speechSynthesis.cancel(); } catch(e) {}
    ttsUtterance = null;
    setTtsHighlight(false);
    updateTtsUi();
  }

  function currentStepTextForTts(){
    if (!elBody) return '';
    return String(elBody.innerText || '').trim();
  }

  function pickVoice(lang, preferredName){
    if (!ttsSupported) return null;
    const voices = speechSynthesis.getVoices ? speechSynthesis.getVoices() : [];
    if (!voices || voices.length === 0) return null;
    const pref = (preferredName || '').toLowerCase().trim();
    if (pref !== '') {
      const prefExact = voices.find(v => v?.name && v.name.toLowerCase() === pref && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
      if (prefExact) return prefExact;
      const prefLoose = voices.find(v => v?.name && v.name.toLowerCase().includes(pref));
      if (prefLoose) return prefLoose;
    }
    const exactLocal = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()) && v.localService);
    if (exactLocal) return exactLocal;
    const exact = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
    if (exact) return exact;
    return voices[0] || null;
  }

  function speakCurrentStep(){
    if (!ttsSupported) return;
    const text = currentStepTextForTts();
    if (!text) { updateTtsUi(t('student.tts.nothing', 'Nichts zum Vorlesen gefunden.')); return; }

    stopTts();
    const utter = new SpeechSynthesisUtterance(text);
    utter.rate = TTS_RATE;
    utter.pitch = 1;
    utter.lang = currentLang === 'en' ? 'en-US' : 'de-DE';
    const voice = pickVoice(utter.lang, TTS_VOICE_PREF);
    if (voice) utter.voice = voice;
    utter.onstart = () => { setTtsHighlight(true); updateTtsUi(t('student.tts.reading', 'Liest gerade …')); };
    utter.onend = () => { setTtsHighlight(false); updateTtsUi(t('student.tts.ready', 'Bereit zum Vorlesen.')); };
    utter.onerror = () => { setTtsHighlight(false); updateTtsUi(t('student.tts.error', 'Vorlesen konnte nicht gestartet werden.')); };
    ttsUtterance = utter;
    speechSynthesis.speak(utter);
    updateTtsUi();
  }

  function initTts(){
    updateTtsUi();
    if (!ttsSupported || !ttsButton) return;
    ttsButton.addEventListener('click', () => {
      if (speechSynthesis.speaking) { stopTts(); }
      else { speakCurrentStep(); }
    });
    if (speechSynthesis && typeof speechSynthesis.addEventListener === 'function') {
      speechSynthesis.addEventListener('voiceschanged', () => updateTtsUi());
    }
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

  // ---------- Language switch without page reload ----------
  const langLinks = document.querySelectorAll('.lang-switch a.lang');
  let currentLang = <?= json_encode(ui_lang()) ?>;

  function setActiveLangUI(next){
    document.querySelectorAll('.lang-switch a.lang').forEach(a=>{
      a.classList.toggle('active', (a.dataset.lang || '') === next);
    });
    currentLang = next;
  }

  function rememberFocus(){
    const ae = document.activeElement;
    if (!ae) return null;
    const wrap = ae.closest?.('[data-field]');
    if (!wrap) return null;
    const fid = wrap.getAttribute('data-field');
    const role = ae.matches('input,textarea') ? (ae.tagName.toLowerCase()) : null;

    let selStart = null, selEnd = null;
    try {
      if (role && typeof ae.selectionStart === 'number') {
        selStart = ae.selectionStart;
        selEnd = ae.selectionEnd;
      }
    } catch(e){}
    return { fid, role, selStart, selEnd };
  }

  function restoreFocus(info){
    if (!info || !info.fid) return;
    const el = document.querySelector(`[data-field="${CSS.escape(String(info.fid))}"] ${info.role || 'input,textarea'}`);
    if (!el) return;
    el.focus({ preventScroll:true });
    try{
      if (typeof info.selStart === 'number' && typeof el.setSelectionRange === 'function') {
        el.setSelectionRange(info.selStart, info.selEnd ?? info.selStart);
      }
    }catch(e){}
  }

  function applyBootstrapResponse(j){
    state = j;

    if (j && j.translations) {
      T = j.translations;
    }

    if (j && j.ui_lang) {
      currentLang = j.ui_lang;
      document.documentElement.lang = currentLang;
    }

    refreshStaticLabels();
  }

  function refreshStaticLabels(){
    const elSub = document.getElementById('brandSubtitle');
    if (elSub) elSub.textContent = t('student.subtitle');

    const elClassLabel = document.getElementById('classLabelText');
    if (elClassLabel) elClassLabel.textContent = t('student.class_label');

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) logoutBtn.textContent = t('student.logout', 'Logout');

    const langSwitch = document.querySelector('.lang-switch');
    if (langSwitch) langSwitch.setAttribute('aria-label', t('student.lang_switch_aria', 'Sprache wechseln'));

    document.querySelectorAll('.lang[data-lang="de"]').forEach(el => el.setAttribute('title', t('student.lang_de', 'Deutsch')));
    document.querySelectorAll('.lang[data-lang="en"]').forEach(el => el.setAttribute('title', t('student.lang_en', 'English')));

    document.title = `${ORG_NAME} – ${t('student.html_title')}`;

    if (!HAS_TEMPLATE) {
      const lockedTitle = document.getElementById('lockedTitle');
      const lockedText = document.getElementById('lockedText');
      if (lockedTitle) lockedTitle.textContent = t('student.locked.none_title');
      if (lockedText) lockedText.textContent = t('student.locked.none_text');
    }
  }

  async function switchLangNoReload(href, nextLang){
    const scrollY = window.scrollY;
    const focusInfo = rememberFocus();
    const keepStep = activeStep;

    await fetch(href, { method:'GET', credentials:'same-origin', cache:'no-store' });

    const j = await api('bootstrap', {});
    applyBootstrapResponse(j);

    buildFlatSteps();
    activeStep = Math.max(0, Math.min(keepStep, flatSteps.length - 1));

    setActiveLangUI(nextLang);
    render();

    window.scrollTo({ top: scrollY, left: 0, behavior: 'instant' });
    restoreFocus(focusInfo);
  }

  langLinks.forEach(a=>{
    a.addEventListener('click', async (e)=>{
      e.preventDefault();
      const nextLang = (a.dataset.lang || '').trim();
      if (!nextLang || nextLang === currentLang) return;

      try{
        a.style.pointerEvents = 'none';
        await switchLangNoReload(a.href, nextLang);
      } catch(err){
        window.location.href = a.href;
      } finally {
        a.style.pointerEvents = '';
      }
    });
  });

  function isLocked(){
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

  // -------------------------
  // Dynamic label/help placeholders
  // -------------------------
  function buildFieldNameIndex(){
    const idx = new Map();
    const steps = Array.isArray(state.steps) ? state.steps : [];
    for (const s of steps) {
      if (!s || s.is_intro) continue;
      const fields = Array.isArray(s.fields) ? s.fields : [];
      for (const f of fields) {
        if (!f) continue;
        const name = String(f.name || '').trim();
        if (!name) continue;
        idx.set(name, f);
      }
    }
    const lookup = (state && state.field_lookup && typeof state.field_lookup === 'object') ? state.field_lookup : null;
    if (lookup) {
      for (const [k, v] of Object.entries(lookup)) {
        if (!k) continue;
        if (idx.has(k)) continue;
        idx.set(k, {
          name: String(v.name || k),
          label: String(v.label || v.name || k),
          help: String(v.help || ''),
          value: { text: String(v.value ?? '') }
        });
      }
    }
    return idx;
  }

  function resolveTextTemplate(tpl, nameIndex){
    const s = String(tpl ?? '');
    if (!s.includes('{{')) return s;
    return s.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_m, rawKey) => {
      const token = String(rawKey || '').trim();
      if (!token) return '';
      let kind = 'field';
      let key = token;
      const p = token.indexOf(':');
      if (p > 0) {
        kind = token.slice(0, p).trim().toLowerCase();
        key = token.slice(p + 1).trim();
      }
      if (!key) return '';
      const ref = nameIndex.get(key);
      if (!ref) return '';
      if (kind === 'label') return String(ref.label || ref.name || '');
      if (kind === 'help') return String(ref.help || '');
      return fieldValueText(ref);
    });
  }

  function refreshDynamicTexts(container){
    const root = container || document;
    const idx = buildFieldNameIndex();
    root.querySelectorAll('[data-field]').forEach(wrap => {
      const fid = Number(wrap.getAttribute('data-field'));
      const f = findFieldById(fid);
      if (!f) return;
      const lbl = resolveTextTemplate(String(f.label || f.name || 'Feld'), idx);
      const help = resolveTextTemplate(String(f.help || ''), idx);

      const lblEl = wrap.querySelector('[data-dyn="label"]');
      if (lblEl) lblEl.textContent = lbl;

      const helpEl = wrap.querySelector('[data-dyn="help"]');
      if (helpEl) {
        helpEl.textContent = help;
        helpEl.style.display = help.trim() ? '' : 'none';
      }
    });
  }

  function findFieldById(fid){
    const steps = Array.isArray(state.steps) ? state.steps : [];
    for (const s of steps) {
      if (!s || s.is_intro) continue;
      const fields = Array.isArray(s.fields) ? s.fields : [];
      for (const f of fields) {
        if (Number(f.id) === Number(fid)) return f;
      }
    }
    return null;
  }

  function fieldIsMissing(f){
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
      lockBanner.innerHTML = `<strong>${esc(t('student.js.locked_title', 'Eingabe gesperrt'))}</strong> ${esc(t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.'))}`;
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
          <div class="title">${esc(t('student.js.nav_start_title', 'Start'))}</div>
          <div class="sub">${esc(t('student.js.nav_start_sub', 'Info'))}</div>
        </div>
        <span class="badge-mini ok">✓</span>
      </div>
    </div>`);

    function stepIndexForGroupKey(gKey){
      if (displayMode === 'groups') {
        return flatSteps.findIndex(s => s.kind==='group' && String(s.group)===String(gKey));
      }
      return flatSteps.findIndex(s => s.kind==='group_intro' && String(s.group)===String(gKey));
    }

    for (const g of groups) {
      const gKey = String(g.key || g.title || t('student.js.section', 'Abschnitt'));
      const gTitle = String(g.title || g.key || t('student.js.section', 'Abschnitt'));
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
              const fullLbl = String(f.label || f.name || tfmt('student.js.question_label', 'Frage {index}', { index: i + 1 }));
              return `<a class="item ${missing?'missing':'ok'} ${active?'active':''}" data-jump="${stepIdx}" title="${esc(fullLbl)}">
                <div class="txt">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="lbl">${esc(tfmt('student.js.question_label', 'Frage {index}', { index: i + 1 }))}</span>
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
            <div class="title">${esc(t('student.js.submit_title', 'Fertig'))}</div>
            <div class="sub">${esc(t('student.js.submit_sub', 'Abgeben'))}</div>
          </div>
          <span class="badge-mini">→</span>
        </div>
      </div>`);

    elNav.innerHTML = html.join('');

    updateOverallProgress();

    elNav.querySelectorAll('[data-jump]').forEach(el => {
      el.addEventListener('click', () => {
        const v = Number(el.getAttribute('data-jump'));
        if (!Number.isFinite(v) || v < 0) return;
        activeStep = v;
        render();
        window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
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

  function formatTime(ts){
    const d = ts instanceof Date ? ts : new Date(ts ?? Date.now());
    const locale = (currentLang === 'en') ? 'en-GB' : 'de-DE';
    return d.toLocaleTimeString(locale, { hour:'2-digit', minute:'2-digit' });
  }

  function setSaveStatus(state, text){
    if (!saveStatus) return;
    saveStatus.textContent = text || '';
    saveStatus.dataset.state = state || 'idle';
    saveStatus.style.display = text ? 'flex' : 'none';
  }

  async function saveFieldValue(fieldId, valueText){
    if (isLocked()) return;
    saveInFlight++;
    setSaving(true);
    setSaveStatus('saving', t('student.js.save_working', '⏳ speichert …'));
    try {
      await api('save_value', { template_field_id: Number(fieldId), value_text: String(valueText ?? '') });
      lastSaveAt = new Date();
      setSaveStatus('ok', tfmt('student.js.save_ok', '✔ gespeichert um {time}', { time: formatTime(lastSaveAt) }));
      return true;
    } catch(err){
      const msg = String(err?.message || t('student.js.save_error_generic', 'Fehler beim Speichern'));
      const offline = (navigator.onLine === false) || msg.toLowerCase().includes('failed to fetch');
      setSaveStatus('error', offline ? t('student.js.save_error_offline', '❌ Fehler (offline)') : tfmt('student.js.save_error', '❌ Fehler: {message}', { message: msg }));
      return false;
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

  // ===== CHANGED: option labels now support bilingual labels from option_list_items (label / label_en) =====
  function optionLabel(o){
    if (!o) return t('student.js.option_placeholder', 'Option');

    // Preferred: language-specific label if present
    if (currentLang === 'en') {
      const le = (typeof o.label_en !== 'undefined') ? String(o.label_en || '').trim() : '';
      if (le) return le;
    }

    const ld = (typeof o.label !== 'undefined') ? String(o.label || '').trim() : '';
    if (ld) return ld;

    const val = (typeof o.value !== 'undefined') ? String(o.value ?? '').trim() : '';
    if (val) return val;

    const key = (typeof o.key !== 'undefined') ? String(o.key ?? '').trim() : '';
    if (key) return key;

    const id = (typeof o.id !== 'undefined') ? String(o.id ?? '').trim() : '';
    if (id) return id;

    return t('student.js.option_placeholder', 'Option');
  }

  function optionValue(o){
    if (!o) return '';
    if (typeof o.value !== 'undefined') return String(o.value);
    if (typeof o.key !== 'undefined') return String(o.key);
    if (typeof o.id !== 'undefined') return String(o.id);
    return optionLabel(o);
  }

  function renderFieldBlock(f){
    const fid = Number(f.id);
    const type = String(f.type || 'text');
    const idx = buildFieldNameIndex();
    const label = resolveTextTemplate(String(f.label || f.name || 'Feld'), idx);
    const help = resolveTextTemplate(String(f.help || ''), idx);
    const multiline = !!f.multiline;
    const val = fieldValueText(f);

    const missing = fieldIsMissing(f);
    const wrapCls = 'q' + (missing ? ' missing' : '');

    if (['radio','select','grade'].includes(type) || type === 'checkbox') {
      let opts = [];
      if (type === 'checkbox') {
        // CHANGED: localize built-in Yes/No
        opts = (currentLang === 'en')
          ? [{ value:'1', label:'Yes' }, { value:'0', label:'No' }]
          : [{ value:'1', label:'Ja'  }, { value:'0', label:'Nein' }];
      } else {
        opts = Array.isArray(f.options) ? f.options : [];
      }

      return `<div class="${wrapCls}" data-field="${fid}">
        <div class="lbl" data-dyn="label">${esc(label)}</div>
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
        <div class="help" data-dyn="help" style="${help ? '' : 'display:none;'}">${esc(help)}</div>
      </div>`;
    }

    if (multiline || type === 'textarea') {
      return `<div class="${wrapCls}" data-field="${fid}">
        <div class="lbl" data-dyn="label">${esc(label)}</div>
        <textarea rows="4" class="input" data-input="1" style="width:100%;">${esc(val)}</textarea>
        <div class="help" data-dyn="help" style="${help ? '' : 'display:none;'}">${esc(help)}</div>
      </div>`;
    }

    return `<div class="${wrapCls}" data-field="${fid}">
      <div class="lbl" data-dyn="label">${esc(label)}</div>
      <input type="text" class="input" data-input="1" style="width:100%;" value="${esc(val)}">
      <div class="help" data-dyn="help" style="${help ? '' : 'display:none;'}">${esc(help)}</div>
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
        refreshDynamicTexts(container);
      });
      inp.addEventListener('blur', () => {
        if (isLocked()) return;
        const v = inp.value;
        updateFieldLocal(fid, v);
        saveFieldValue(fid, v).catch(()=>{});
        refreshDynamicTexts(container);
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
      elReqHint.textContent = t('student.js.req_hint_intro', 'Klicke „Los geht\'s“, um zu starten.');
      return;
    }
    if (cur.kind === 'group_intro') {
      const st = groupStats(cur.fields || []);
      elReqHint.textContent = (st.missing === 0)
        ? t('student.js.req_hint_group_done', 'Abschnitt ist schon komplett ✓')
        : tfmt('student.js.req_hint_group_missing', '{count} fehlen in diesem Abschnitt (du kannst starten).', { count: st.missing });
      return;
    }
    if (cur.kind === 'submit') {
      const allMissing = totalMissingCount();
      elReqHint.textContent = allMissing === 0
        ? t('student.js.req_hint_submit_ok', 'Alles erledigt – du kannst abgeben.')
        : tfmt('student.js.req_hint_submit_missing', '{count} Felder fehlen noch.', { count: allMissing });
      return;
    }

    const miss = currentStepMissingCount();
    elReqHint.textContent = (miss === 0)
      ? t('student.js.req_hint_step_ok', 'Alles ausgefüllt ✓')
      : tfmt('student.js.req_hint_step_missing', '{count} fehlen noch (du kannst trotzdem weiter).', { count: miss });
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

  function totalFieldCount(){
    const groups = getGroupsList();
    let total = 0;
    for (const g of groups) {
      const fields = Array.isArray(g.fields) ? g.fields : [];
      total += fields.length;
    }
    return total;
  }

  function updateOverallProgress(){
    if (!elOverallWrap || !elOverallBar) return;
    const total = totalFieldCount();
    const missing = totalMissingCount();
    const done = Math.max(0, total - missing);
    const pct = (total > 0) ? Math.round((done/total)*100) : 0;

    elOverallWrap.style.display = (total > 0) ? '' : 'none';
    if (elOverallText) elOverallText.textContent = (total > 0)
      ? tfmt('student.js.progress_text', 'Fortschritt: {done}/{total} (offen: {missing})', { done, total, missing })
      : t('student.js.progress_empty', '—');
    if (elOverallPct) elOverallPct.textContent = (total > 0) ? (pct + '%') : '';

    elOverallBar.style.width = (total > 0) ? (pct + '%') : '0%';
    elOverallBar.classList.toggle('ok', total > 0 && missing === 0);
  }

  async function handleSubmit(){
    if (isLocked()) return;

    for (const [k,t] of pendingTimers.entries()) {
      clearTimeout(t);
      pendingTimers.delete(k);
    }

    const missing = totalMissingCount();
    if (missing > 0) {
      alert(tfmt('student.js.submit_missing_alert', 'Es fehlen noch {count} Felder. Bitte fülle alles aus.', { count: missing }));
      return;
    }

    if (!confirm(t('student.js.submit_confirm', 'Möchtest du jetzt abgeben? Danach kannst du nichts mehr ändern.'))) return;

    try {
      btnNext.disabled = true;
      btnPrev.disabled = true;
      setSaving(true);
      await api('submit', {});
      const j = await api('bootstrap', {});
      applyBootstrapResponse(j);

      if (isLocked()) {
        showLockedOnly();
        return;
      }

      buildFlatSteps();
      activeStep = flatSteps.findIndex(s => s.kind==='submit');
      if (activeStep < 0) activeStep = flatSteps.length - 1;
      render();
      alert(t('student.js.submit_thanks', 'Danke! Du hast abgegeben.'));
    } catch(e){
      alert(e?.message || t('student.js.submit_error', 'Fehler beim Abgeben.'));
    } finally {
      setSaving(false);
      btnPrev.disabled = false;
      btnNext.disabled = false;
    }
  }

  function render(){
    if (ttsSupported && TTS_ALLOWED) stopTts();
    if (isLocked()) {
      const st = String(state.report_status || '');
      if (st === 'submitted') {
        showLockedOnly(t('student.js.already_submitted', 'Bereits abgegeben'), t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.'));
      } else if (st === 'locked') {
        showLockedOnly(t('student.js.locked_title', 'Eingabe gesperrt'), t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.'));
      } else {
        showLockedOnly(t('student.js.not_ready_title', 'Eingabe noch nicht freigegeben'), t('student.js.not_ready_text', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben. Bitte versuche es später noch einmal.'));
      }
      return;
    }

    buildFlatSteps();

    const tplName = state.template ? String(state.template.name || '') : '';
    const ver = state.template ? String(state.template.version || '') : '';
    elMeta.textContent = tplName ? (tplName + (ver ? (' · v' + ver) : '')) : t('student.js.form_label', 'Formular');

    setLockedUi();
    renderNav();

    const cur = flatSteps[activeStep];
    if (!cur) return;

    btnPrev.style.visibility = (activeStep <= 0) ? 'hidden' : 'visible';

    if (cur.kind === 'intro') {
      elTitle.textContent = t('student.js.start_title', 'Start');
      elSub.textContent = t('student.js.start_sub', 'Bitte lies die Infos. Danach geht es los.');
      const html = (cur.intro_html || '').trim();
      elBody.innerHTML = `<div class="intro-box">${html ? html : `<p class="muted">${esc(t('student.js.no_intro', 'Keine Intro-Infos hinterlegt.'))}</p>`}</div>`;
      btnNext.textContent = t('student.js.cta_start', 'Los geht’s');
      btnPrev.disabled = (activeStep <= 0);
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
    }

    else if (cur.kind === 'group') {
      elTitle.textContent = cur.title;
      elSub.textContent = t('student.js.group_sub', 'Du kannst weiterklicken und später zurückspringen, wenn etwas fehlt.');
      elBody.innerHTML = ((cur.fields || []).map(f => renderFieldBlock(f)).join('') || `<p class="muted">${esc(t('student.js.no_fields', 'Keine Felder.'))}</p>`);
      attachFieldHandlers(elBody);
      btnNext.textContent = t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
    }

    else if (cur.kind === 'group_intro') {
      const fields = Array.isArray(cur.fields) ? cur.fields : [];
      elTitle.textContent = cur.groupTitle || cur.title || t('student.js.section', 'Abschnitt');
      elSub.textContent = t('student.js.group_intro_sub', 'Bevor es losgeht: kurze Übersicht.');
      elBody.innerHTML = `
        <div class="group-intro">
          <p class="kicker">${esc(t('student.js.group_intro_kicker', 'Neuer Abschnitt'))}</p>
          <h3>${esc(cur.groupTitle || cur.title || t('student.js.section', 'Abschnitt'))}</h3>
          <div class="muted">${esc(tfmt('student.js.group_intro_hint', 'Hier kommen {count} Fragen. Du kannst jederzeit im Menü springen.', { count: fields.length }))}</div>
          <div style="margin-top:12px;"><button class="btn" type="button" id="btnStartGroup">${esc(t('student.js.cta_begin_group', 'Starten'))}</button></div>
        </div>
      `;
      const b = document.getElementById('btnStartGroup');
      if (b) {
        b.addEventListener('click', () => {
          const idx = flatSteps.findIndex(s => s.kind === 'field' && String(s.group) === String(cur.group));
          if (idx >= 0) { activeStep = idx; render(); }
          else { activeStep = Math.min(activeStep + 1, flatSteps.length - 1); render(); }
        });
      }
      btnNext.textContent = t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
    }

    else if (cur.kind === 'field') {
      const f = cur.field;
      elTitle.textContent = cur.groupTitle;
      elSub.textContent = t('student.js.field_sub', 'Eine Frage nach der anderen. Du kannst jederzeit zurückspringen.');
      elBody.innerHTML = renderFieldBlock(f);
      attachFieldHandlers(elBody);
      btnNext.textContent = t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
    }

    else { // submit
      elTitle.textContent = t('student.js.finish_title', 'Fertig');
      const missing = totalMissingCount();
      elSub.textContent = missing === 0 ? t('student.js.finish_all', 'Alles ist ausgefüllt.') : t('student.js.finish_missing', 'Es fehlen noch Felder.');
      elBody.innerHTML = `
        <div class="submit-box">
          <p style="margin-top:0;">${esc(t('student.js.finish_text', 'Wenn alles ausgefüllt ist, kannst du abgeben.'))}</p>
          <p class="muted">${esc(tfmt('student.js.finish_missing_label', 'Fehlende Felder: {count}', { count: missing }))}</p>
          <div class="actions" style="justify-content:flex-start;">
            <button class="btn primary" type="button" id="btnSubmit" ${missing>0 ? 'disabled' : ''}>${esc(t('student.js.submit_btn', 'Abgeben'))}</button>
          </div>
        </div>
      `;
      const b = document.getElementById('btnSubmit');
      if (b) b.addEventListener('click', handleSubmit);

      btnNext.textContent = '—';
      btnNext.disabled = true;
      btnNext.style.visibility = 'hidden';
    }

    btnPrev.onclick = () => {
      if (activeStep > 0) { activeStep--; render(); }
      window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    };
    btnNext.onclick = () => {
      const cur = flatSteps[activeStep];
      if (!cur) return;
      if (cur.kind === 'submit') return;
      activeStep = Math.min(activeStep + 1, flatSteps.length - 1);
      render();
      window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    };

    updateReqHint();
    refreshDynamicTexts(elBody);

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
      initTts();
      if (!HAS_TEMPLATE) return;

      const j = await api('bootstrap', {});
      applyBootstrapResponse(j);
      setSaveStatus('idle', t('student.js.auto_save', 'Automatisches Speichern ist aktiv.'));

      if (isLocked()) {
        const st = String(state.report_status || '');
        if (st === 'submitted') {
          showLockedOnly(t('student.js.already_submitted', 'Bereits abgegeben'), t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.'));
        } else if (st === 'locked') {
          showLockedOnly(t('student.js.locked_title', 'Eingabe gesperrt'), t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.'));
        } else {
          showLockedOnly(t('student.js.not_ready_title', 'Eingabe noch nicht freigegeben oder bereits abgegeben'), t('student.js.not_ready_text', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben oder du hast deine Eingabe bereits abgegeben. Bitte versuche es später noch einmal.'));
        }
        return;
      }

      buildFlatSteps();
      activeStep = 0;
      render();
    } catch (e) {
      const msg = String(e?.message || t('student.js.load_error', 'Fehler'));
      if (msg.toLowerCase().includes('keine vorlage') || msg.toLowerCase().includes('vorlage zugeordnet')) {
        showLockedOnly(t('student.js.no_template_title', 'Keine Vorlage zugeordnet'), t('student.js.no_template_text', 'Für deine Klasse wurde noch keine Vorlage zugeordnet. Bitte wende dich an deine Lehrkraft.'));
        return;
      }
      elMeta.textContent = t('student.js.load_error', 'Fehler beim Laden.');
      elBody.innerHTML = `<div class="alert danger"><strong>${esc(msg)}</strong></div>`;
      btnPrev.disabled = true;
      btnNext.disabled = true;
    }
  })();
})();
</script>
</body>
</html>
