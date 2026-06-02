<?php
// student/index.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_student();

if (!headers_sent()) {
  header('Cross-Origin-Opener-Policy: same-origin');
  header('Cross-Origin-Embedder-Policy: require-corp');
}

$pdo = db();
$studentId = (int)($_SESSION['student']['id'] ?? 0);

$st = $pdo->prepare(
  "SELECT s.id, s.first_name, s.last_name, s.class_id, s.is_active,
          c.school_year, c.period_label, c.grade_level, c.label, c.name AS class_name, c.template_id, c.tts_enabled
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
if ($schoolYear === '') {
  $cfg = app_config();
  $schoolYear = (string)($cfg['app']['default_school_year'] ?? '');
}

$cfg = app_config();
$brand = $cfg['app']['brand'] ?? [];
$studentCfg = $cfg['student'] ?? [];

$periodLabel = normalize_class_period_label($me['period_label'] ?? 'Standard');
$deadlineTypes = submission_deadline_types();
$deadlineRows = $schoolYear !== '' ? fetch_submission_deadlines($pdo, $schoolYear, $periodLabel) : [];
$studentDeadline = $deadlineRows['student'] ?? null;
$studentDeadlineInfo = deadline_remaining_info($studentDeadline['due_at'] ?? null);
$showStudentDeadline = (bool)($studentCfg['show_deadline'] ?? false);
$showStudentDeadlineInline = $showStudentDeadline && !empty($studentDeadline['due_at']);

$classTemplateId = (int)($me['template_id'] ?? 0);
$hasTemplate = ($classTemplateId > 0);
$ttsEnabled = (int)($me['tts_enabled'] ?? 0) === 1;
$isActive = (int)($me['is_active'] ?? 0) === 1;

$reportStatus = 'draft';
if ($hasTemplate && $schoolYear !== '') {
  $st = $pdo->prepare(
    "SELECT status\n" .
    " FROM report_instances\n" .
    " WHERE template_id=? AND student_id=? AND period_label=? AND school_year=?\n" .
    " ORDER BY updated_at DESC, id DESC\n" .
    " LIMIT 1"
  );
  $st->execute([$classTemplateId, $studentId, $periodLabel, $schoolYear]);
  $reportStatus = (string)($st->fetchColumn() ?: 'locked');
}

$canUseWizard = $hasTemplate && $isActive && $reportStatus === 'draft';

$lockedTitle = '';
$lockedText = '';
if (!$isActive) {
  $lockedTitle = t('student.locked.inactive_title', 'Zugang deaktiviert');
  $lockedText = t('student.locked.inactive_text', 'Dein Zugang ist derzeit deaktiviert. Bitte wende dich an deine Lehrkraft.');
} elseif (!$hasTemplate) {
  $lockedTitle = t('student.locked.none_title');
  $lockedText = t('student.locked.none_text');
} elseif ($reportStatus === 'locked') {
  $lockedTitle = t('student.js.locked_title', 'Eingabe gesperrt');
  $lockedText = t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.');
} elseif ($reportStatus === 'submitted') {
  $lockedTitle = t('student.js.already_submitted', 'Bereits abgegeben');
  $lockedText = t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.');
} else {
  $lockedTitle = t('student.locked.pending_title', 'Eingabe noch nicht freigegeben');
  $lockedText = t('student.locked.pending_text', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben. Bitte versuche es später noch einmal.');
}

$orgName = (string)($brand['org_name'] ?? 'LEB Tool');
$logoPath = (string)($brand['logo_path'] ?? '');
$primary = (string)($brand['primary'] ?? '#0b57d0');
$secondary = (string)($brand['secondary'] ?? '#111111');
$ttsRateDe = (float)($studentCfg['tts_rate_de'] ?? ($studentCfg['tts_rate'] ?? 0.95));
$ttsRateEn = (float)($studentCfg['tts_rate_en'] ?? ($studentCfg['tts_rate'] ?? 0.95));
if ($ttsRateDe <= 0) $ttsRateDe = 1.0;
if ($ttsRateEn <= 0) $ttsRateEn = 1.0;
$ttsRateDe = max(0.5, min(1.5, $ttsRateDe));
$ttsRateEn = max(0.5, min(1.5, $ttsRateEn));
$ttsVoicePrefDe = trim((string)($studentCfg['tts_voice_de'] ?? ($studentCfg['tts_voice'] ?? '')));
$ttsVoicePrefEn = trim((string)($studentCfg['tts_voice_en'] ?? ''));
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($orgName)?> – <?=h(t('student.html_title'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <link rel="stylesheet" href="<?=h(url('assets/font-awesome/font-awesome.css'))?>">
  <script type="importmap">
    {
      "imports": {
        "onnxruntime-web": "<?=h(url('assets/vits-web/dist/onnxruntime-web.js'))?>"
      }
    }
  </script>
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
    .nav.saving-disabled{ opacity:.65; pointer-events:none; }

    .nav .group{ border:1px solid var(--border); border-radius:14px; overflow:hidden; background: #fff; }
    .nav .group.wizard-nav-category{ margin-top:4px; }
    .nav .group-h{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 10px; cursor:pointer; user-select:none; }
    .nav .group-h:hover{ background: rgba(0,0,0,0.02); }
    .nav .group-h.active{ outline:2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
    .nav .group-h .left{ display:flex; flex-direction:column; gap:2px; min-width:0; }
    .nav .group-h .title{ font-weight:750; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 210px; }
    .nav .wizard-nav-category > .group-h .title{ font-weight:900; }
    .nav .group-h .sub{ color:var(--muted); font-size:12px; }

    .badge-mini{ display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 8px; border-radius:999px; font-size:12px; font-weight:750; border:1px solid var(--border); background: rgba(0,0,0,0.02); }
    .badge-mini.ok{ border-color: rgba(0,128,0,0.25); background: rgba(0,128,0,0.06); }
    .badge-mini.miss{ border-color: rgba(176,0,32,0.25); background: rgba(176,0,32,0.06); }

    .nav .items{ border-top:1px solid var(--border); padding:6px 6px 8px; display:none; }
    .nav .group.open .items{ display:block; }
    .nav .group.wizard-nav-category.open .items{ display:block; }

    /* compact sub-items */
    .nav a.item{
      display:flex; justify-content:space-between; gap:10px; align-items:center;
      padding:7px 8px; border-radius:10px; color:inherit; text-decoration:none; cursor:pointer;
    }
    .nav a.item.wizard-nav-subcategory{ margin-left:14px; padding-left:10px; border-left:2px solid rgba(11,87,208,0.18); }
    .nav a.item.wizard-nav-subcategory .lbl{ font-weight:750; }
    .nav a.item.wizard-nav-field{ margin-left:28px; }
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
    .nav a.item.section-step .lbl{ font-weight:750; }

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

    .deadline-inline{
      display:flex;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
      font-size:12px;
      margin:8px 0 0;
    }
    .deadline-inline .label{ font-weight:700; }
    .deadline-inline .date{ color:var(--muted); }

    .ai-help-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .ai-help-btn{ border:1px solid var(--border); background:#fff; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:700; cursor:pointer; }
    .ai-help-btn:hover{ background: rgba(11,87,208,0.06); }
    .ai-help-btn[disabled]{ opacity:0.6; cursor:wait; }

    .ai-modal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:50; background: rgba(0,0,0,0.4); padding:20px; }
    .ai-modal.open{ display:flex; }
    .ai-modal-card{ background:#fff; border-radius:16px; max-width:520px; width:100%; padding:16px; border:1px solid var(--border); box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
    .ai-modal-card h3{ margin:0 0 8px; font-size:16px; }
    .ai-modal-card .text{ font-size:14px; line-height:1.4; }
    .ai-modal-card .actions{ margin-top:12px; display:flex; justify-content:flex-end; }

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
    .subsection-h{
      margin: 0 0 12px;
      padding: 11px 13px;
      border-radius: 14px;
      border: 1px solid rgba(11,87,208,0.18);
      background: rgba(11,87,208,0.06);
    }
    .subsection-h .t{ font-weight:900; }
    .subsection-h .s{ color: var(--muted); font-size:12px; margin-top:2px; }

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

    /* Beginner mode (Leseanfänger) */
    body.beginner-mode .wiz{ grid-template-columns: 1fr; }
    body.beginner-mode .sidebar{ display:none; }
    body.beginner-mode #overallProgressWrap,
    body.beginner-mode #reqHint,
    body.beginner-mode #metaLine,
    body.beginner-mode .brand-sub{ display:none !important; }
    body.beginner-mode #stepBody{ padding-bottom: 150px; }
    body.beginner-mode .tts-bar{
      border: none;
      background: transparent;
      padding: 0;
      margin: 0 0 8px 0;
      justify-content: flex-end;
    }
    body.beginner-mode .tts-title,
    body.beginner-mode .tts-status{ display:none; }
    body.beginner-mode .deadline-inline{ display:none; }
    body.beginner-mode #ttsButton{
      width:72px;
      height:72px;
      font-size:30px;
      border-radius:18px;
      border-width:2px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.16);
    }
    body.beginner-mode .q{ padding:16px; }
    body.beginner-mode .q .lbl{ font-size:22px; line-height:1.25; }
    body.beginner-mode .q .help{ display:none; }
    body.beginner-mode .opts{ grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; }
    body.beginner-mode .opt{ padding:16px; font-size:18px; border-width:2px; }
    body.beginner-mode .opt img{ width:110px; height:110px; filter: drop-shadow(0 4px 10px rgba(0,0,0,0.08)); }
    body.beginner-mode .opt .lbl{ font-size:18px; }
    body.beginner-mode .wiz-actions{
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255,255,255,0.98);
      box-shadow: 0 -6px 22px rgba(0,0,0,0.12);
      padding: 12px 18px 16px;
      z-index: 20;
      flex-direction: column;
      align-items: stretch;
      gap: 12px;
    }
    body.beginner-mode .wiz-actions .left{
      width: 100%;
      justify-content: space-between;
    }
    body.beginner-mode .wiz-actions .btn{
      min-height: 64px;
      min-width: 64px;
      font-size: 30px;
      padding: 14px 18px;
      border-radius: 16px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight: 800;
    }
    body.beginner-mode #ttsActionsInline{
      flex: 1;
      display:flex;
      justify-content:center;
      align-items:center;
      gap: 12px;
    }
    body.beginner-mode .beginner-progress{
      display:none;
      width: 100%;
      height: 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: rgba(0,0,0,0.05);
      overflow:hidden;
    }
    body.beginner-mode .beginner-progress .progress-bar{
      height: 100%;
      background: var(--primary);
      transition: width .25s ease;
    }
    @keyframes pulse-glow {
      0% { box-shadow: 0 0 6px rgba(11,87,208,0.55); }
      50% { box-shadow: 0 0 32px rgba(11,87,208,0.75); }
      100% { box-shadow: 0 0 6px rgba(11,87,208,0.55); }
    }
    body.beginner-mode #btnNext.cta-ready{
      animation: pulse-glow 1.6s ease-in-out infinite;
      transform: translateY(-1px) scale(1.02);
    }
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

    <?php if ($showStudentDeadlineInline && $studentDeadlineInfo): ?>
      <div class="deadline-inline">
        <span class="label"><?=h(t('deadline.student.compact_label', 'Frist'))?></span>
        <span class="badge <?=h($studentDeadlineInfo['status'])?>"><?=h($studentDeadlineInfo['label'])?></span>
        <span class="date"><?=render_local_datetime($studentDeadline['due_at'] ?? null, 'd.m.Y H:i', t('deadline.none', '–'))?></span>
      </div>
    <?php endif; ?>

    <!-- Locked-only container -->
    <div id="lockedOnly" class="card" style="display:<?= $canUseWizard ? 'none' : 'block' ?>;">
      <div class="locked-only">
        <h2 id="lockedTitle"><?=h($lockedTitle)?></h2>
        <p class="muted" id="lockedText"><?=h($lockedText)?></p>
      </div>
    </div>

    <!-- Wizard shell -->
    <div id="wizShell" class="wiz" style="<?= $canUseWizard ? '' : 'display:none;' ?>">
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
              <button class="btn secondary" type="button" id="ttsButton" aria-label="<?=h(t('student.tts.start', 'Aktuellen Abschnitt vorlesen'))?>"><i class="fa fa-volume-up"></i></button>
              <button class="btn secondary" type="button" id="aiHelpButton" style="display:none;" aria-label="<?=h(t('student.ai.button', 'Kurze Erklärung'))?>">?</button>
            </div>
          </div>

          <h2 id="stepTitle">…</h2>
          <div class="step-meta" id="stepSub"></div>

          <div id="stepBody"></div>

          <div class="wiz-actions">
            <div id="beginnerProgressWrap" class="beginner-progress"><div id="beginnerProgressBar" class="progress-bar"></div></div>
            <div class="left">
              <button class="btn secondary" type="button" id="btnPrev" aria-label="<?=h(t('student.buttons.prev'))?>">
                <span aria-hidden="true"><?=h(t('student.buttons.prev'))?></span>
              </button>
              <div class="tts-actions" id="ttsActionsInline" style="display:none;"></div>
              <button class="btn primary" type="button" id="btnNext" aria-label="<?=h(t('student.buttons.next'))?>">
                <span aria-hidden="true"><?=h(t('student.buttons.next'))?></span>
              </button>
            </div>
            <div class="pill-mini" id="reqHint"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="muted" id="metaLine" style="text-align: center;"><?=h(t('student.meta.loading'))?></div>
  </div>

  <div class="ai-modal" id="aiModal" role="dialog" aria-modal="true" aria-labelledby="aiModalTitle">
    <div class="ai-modal-card">
      <h3 id="aiModalTitle"><?=h(t('student.ai.title', 'Kurze Erklärung'))?></h3>
      <div class="text" id="aiModalText"></div>
      <div class="actions">
        <button class="btn secondary" type="button" id="aiModalClose"><?=h(t('student.ai.close', 'Schließen'))?></button>
      </div>
    </div>
  </div>

<script>
(function(){
  const apiUrl = <?=json_encode(url('student/ajax/wizard_api.php'))?>;
  const aiApiUrl = <?=json_encode(url('student/ajax/ai_explain_api.php'))?>;
  const ORG_NAME = <?= json_encode($orgName) ?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const HAS_TEMPLATE = <?=json_encode($hasTemplate)?>;
  const STUDENT_ACTIVE = <?=json_encode($isActive)?>;
  const REPORT_STATUS = <?=json_encode($reportStatus)?>;
  const TTS_ALLOWED = <?=json_encode($ttsEnabled)?>;
  const TTS_RATE_DE = Number(<?=json_encode($ttsRateDe)?>) || 1;
  const TTS_RATE_EN = Number(<?=json_encode($ttsRateEn)?>) || 1;
  function ttsRateForLang(){
    return currentLang === 'en' ? TTS_RATE_EN : TTS_RATE_DE;
  }
  const TTS_VOICE_PREF_DE = <?=json_encode($ttsVoicePrefDe)?>;
  const TTS_VOICE_PREF_EN = <?=json_encode($ttsVoicePrefEn)?>;
  const VITS_MODULE_URL = <?=json_encode(url('assets/vits-web/dist/vits-web.js'))?>;
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

  const elBeginnerProgressWrap = document.getElementById('beginnerProgressWrap');
  const elBeginnerProgressBar = document.getElementById('beginnerProgressBar');

  const ttsBar = document.getElementById('ttsBar');
  const ttsButton = document.getElementById('ttsButton');
  const ttsStatus = document.getElementById('ttsStatus');
  const ttsActionsInline = document.getElementById('ttsActionsInline');
  const aiHelpButton = document.getElementById('aiHelpButton');
  const aiModal = document.getElementById('aiModal');
  const aiModalText = document.getElementById('aiModalText');
  const aiModalClose = document.getElementById('aiModalClose');

  let state = {
    ok: false,
    template: null,
    report_instance_id: 0,
    report_status: 'draft',
    child_can_edit: true,
    ui: { display_mode: 'groups', ai_enabled: false },
    steps: [],
  };

  let displayMode = 'groups';
  let isBeginnerMode = false;
  let flatSteps = [];
  let activeStep = 0;
  let suppressTtsOnce = false;
  let didAutoReadIntro = false;

  const pendingTimers = new Map();
  const dirtyFields = new Map();
  const fieldSaveChains = new Map();
  let saveInFlight = 0;
  let navigationSaveInFlight = false;
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

  function playPling(){
    if (!isBeginnerMode) return;
    try{
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      gain.gain.setValueAtTime(0.0001, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.2);
      osc.onended = () => { try { ctx.close(); } catch(e) {} };
    } catch(e) {}
  }

  function voicePrefForLang(){
    if (currentLang === 'en') return TTS_VOICE_PREF_EN || TTS_VOICE_PREF_DE || '';
    return TTS_VOICE_PREF_DE || TTS_VOICE_PREF_EN || '';
  }

  function openAiModal(text){
    if (!aiModal || !aiModalText) return;
    aiModalText.textContent = text || '';
    aiModal.classList.add('open');
  }

  function closeAiModal(){
    if (!aiModal) return;
    aiModal.classList.remove('open');
  }

  // -------- Vorlese-Funktion (vits-web + Web Speech API Fallback) --------
  const webSpeechSupported = typeof window !== 'undefined' && 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  let ttsSupported = webSpeechSupported;
  let ttsUtterance = null;
  let vitsModule = null;
  let vitsLoading = false;
  let vitsAudio = null;
  const vitsSessions = new Map();
  let vitsPrefetchPromise = null;

  function isTtsSpeaking(){
    if (vitsAudio && !vitsAudio.paused) return true;
    return !!(speechSynthesis && speechSynthesis.speaking);
  }

  function updateTtsUi(text){
    if (!ttsBar) return;
    if (!TTS_ALLOWED) {
      ttsBar.style.display = isBeginnerMode ? 'none' : 'flex';
      if (ttsButton) ttsButton.style.display = 'none';
      if (ttsStatus) ttsStatus.textContent = t('student.tts.disabled', 'Vorlesen wurde von deiner Lehrkraft deaktiviert.');
      return;
    }
    if (!ttsSupported && vitsLoading) {
      ttsBar.style.display = isBeginnerMode ? 'none' : 'flex';
      if (ttsButton) ttsButton.style.display = 'none';
      if (ttsStatus) ttsStatus.textContent = t('student.tts.loading', 'Vorlesen wird vorbereitet …');
      return;
    }
    if (!ttsSupported) {
      ttsBar.style.display = isBeginnerMode ? 'none' : 'flex';
      if (ttsButton) ttsButton.style.display = 'none';
      if (ttsStatus) ttsStatus.textContent = t('student.tts.unsupported', 'Vorlesen wird von diesem Gerät nicht unterstützt.');
      return;
    }
    ttsBar.style.display = isBeginnerMode ? 'none' : 'flex';
    if (ttsButton) {
      ttsButton.style.display = '';
      const isSpeaking = isTtsSpeaking();
      ttsButton.innerHTML = isSpeaking ? '<i class="fa fa-stop"></i>' : '<i class="fa fa-volume-up"></i>';
      ttsButton.setAttribute('aria-label', isSpeaking
        ? t('student.tts.stop', 'Stopp')
        : t('student.tts.start', 'Aktuellen Abschnitt vorlesen'));
    }
    if (ttsStatus) {
      if (text) {
        ttsStatus.textContent = text;
      } else {
        ttsStatus.textContent = isTtsSpeaking()
          ? t('student.tts.reading', 'Liest gerade …')
          : t('student.tts.ready', 'Bereit zum Vorlesen.');
      }
    }
  }

  function placeTtsButton(){
    if (!ttsButton) return;

    const inlineActive = isBeginnerMode && TTS_ALLOWED && ttsSupported;

    if (inlineActive && ttsActionsInline) {
      ttsActionsInline.style.display = '';
      ttsActionsInline.innerHTML = '';
      ttsActionsInline.appendChild(ttsButton);
      if (aiHelpButton) ttsActionsInline.appendChild(aiHelpButton);
    } else {
      if (ttsActionsInline) ttsActionsInline.style.display = 'none';
      const barActions = ttsBar ? ttsBar.querySelector('.tts-actions') : null;
      if (barActions && !barActions.contains(ttsButton)) {
        barActions.appendChild(ttsButton);
      }
      if (barActions && aiHelpButton && !barActions.contains(aiHelpButton)) {
        barActions.appendChild(aiHelpButton);
      }
    }
  }

  function stopTts(){
    if (!ttsSupported) return;
    if (vitsAudio) {
      try { vitsAudio.pause(); vitsAudio.currentTime = 0; } catch(e) {}
      vitsAudio = null;
    }
    if (speechSynthesis) {
      try { speechSynthesis.cancel(); } catch(e) {}
    }
    ttsUtterance = null;
    updateTtsUi();
  }

  async function enterFullscreenForBeginner(){
    if (!isBeginnerMode) return;
    const el = document.documentElement;
    if (!el || document.fullscreenElement || !el.requestFullscreen) return;
    try { await el.requestFullscreen(); } catch(e) {}
  }

  function exitFullscreenForBeginner(){
    if (!document.fullscreenElement || !document.exitFullscreen) return;
    try { document.exitFullscreen(); } catch(e) {}
  }

  function currentStepTextForTts(includeGroup = false){
    if (isBeginnerMode) {
      const cur = flatSteps[activeStep];
      if (cur && cur.kind === 'intro' && elBody) {
        return String(elBody.innerText || '').trim();
      }
      if (cur && cur.kind === 'group_intro') {
        const subgroupTitle = String(cur.subgroupTitle || '').trim();
        const groupLabel = (cur.introLevel === 'subgroup' && subgroupTitle) ? sectionTitle(cur.groupTitle || cur.group, { title: subgroupTitle }) : (cur.groupTitle || cur.group || t('student.js.section', 'Abschnitt'));
        const intro = t('student.js.group_intro_tts', 'Weiter geht es mit dem Thema');
        return `${intro} ${groupLabel}.`.trim();
      }
      if (cur && cur.kind === 'field') {
        const idx = buildFieldNameIndex();
        const fieldLabel = resolveTextTemplate(String(cur.field?.label || cur.field?.name || tfmt('student.js.question_label', 'Frage {index}', { index: 1 })), idx);
        if (includeGroup) {
          const groupLabel = cur.subgroupTitle ? sectionTitle(cur.groupTitle || cur.group, { title: cur.subgroupTitle }) : (cur.groupTitle || cur.group || t('student.js.section', 'Abschnitt'));
          return `${groupLabel}. ${fieldLabel}`.trim();
        }
        return String(fieldLabel || '').trim();
      }
      const heading = (elTitle && elTitle.textContent) ? elTitle.textContent : '';
      return String(heading || '').trim();
    }
    if (!elBody) return '';
    return String(elBody.innerText || '').trim();
  }

  function pickVoice(lang, preferredName){
    if (!ttsSupported) return null;
    const voices = speechSynthesis.getVoices ? speechSynthesis.getVoices() : [];
    if (!voices || voices.length === 0) return null;
    const pref = (preferredName || '').toLowerCase().trim();
    const isMatch = (voice, needle) => voice?.name && voice.name.toLowerCase().includes(needle);

    if (pref !== '') {
      const prefExact = voices.find(v => v?.name && v.name.toLowerCase() === pref && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
      if (prefExact) return prefExact;
      const prefLoose = voices.find(v => isMatch(v, pref));
      if (prefLoose) return prefLoose;
    }

    const premiumVendors = ['google', 'microsoft', 'natural', 'neural'];
    const premiumVoice = voices.find(v => v?.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()) && premiumVendors.some(p => isMatch(v, p)));
    if (premiumVoice) return premiumVoice;

    const exactLocal = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()) && v.localService);
    if (exactLocal) return exactLocal;
    const exact = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
    if (exact) return exact;
    return voices[0] || null;
  }

  function vitsVoiceIdForLang(){
    const pref = voicePrefForLang();
    if (pref) return pref;
    if (currentLang === 'en') return 'en_US-lessac-medium';
    return 'de_DE-thorsten-medium';
  }

  async function loadVitsModule(){
    if (vitsModule || vitsLoading) return vitsModule;
    vitsLoading = true;
    updateTtsUi();
    try {
      const rawModule = await import(VITS_MODULE_URL);
      if (rawModule && rawModule.predict) {
        vitsModule = rawModule;
      } else if (rawModule && rawModule.default && rawModule.default.predict) {
        vitsModule = rawModule.default;
      } else {
        vitsModule = null;
      }
      ttsSupported = !!vitsModule || webSpeechSupported;
    } catch (e) {
      console.warn('vits-web failed to load', e);
      vitsModule = null;
      ttsSupported = webSpeechSupported;
    } finally {
      vitsLoading = false;
      updateTtsUi();
    }
    return vitsModule;
  }

  async function prefetchVitsModel(voiceId){
    if (vitsPrefetchPromise) return vitsPrefetchPromise;
    const mod = await loadVitsModule();
    if (!mod || !mod.download) return null;
    updateTtsUi(t('student.tts.model_loading', 'Vorlesemodell wird geladen …'));
    vitsPrefetchPromise = mod.download(voiceId).catch((err) => {
      console.warn('vits-web download failed', err);
      vitsPrefetchPromise = null;
      return null;
    });
    return vitsPrefetchPromise;
  }

  async function ensureVitsSession(voiceId){
    const mod = await loadVitsModule();
    if (!mod || !mod.TtsSession) return null;
    if (vitsSessions.has(voiceId)) return vitsSessions.get(voiceId);
    updateTtsUi(t('student.tts.model_loading', 'Vorlesemodell wird geladen …'));
    try {
      const session = await mod.TtsSession.create({ voiceId });
      vitsSessions.set(voiceId, session);
      return session;
    } catch (err) {
      console.warn('vits-web session failed', err);
      return null;
    }
  }

  async function speakWithVits(text){
    const normalized = typeof text === 'string' ? text.trim() : '';
    if (!normalized) return false;
    const voiceId = vitsVoiceIdForLang();
    try {
      const session = await ensureVitsSession(voiceId);
      if (!session) return false;
      stopTts();
      updateTtsUi(t('student.tts.reading', 'Liest gerade …'));
      const blob = await session.predict(normalized);
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      audio.playbackRate = ttsRateForLang();
      audio.onended = () => {
        URL.revokeObjectURL(url);
        updateTtsUi(t('student.tts.ready', 'Bereit zum Vorlesen.'));
        vitsAudio = null;
      };
      audio.onerror = () => {
        URL.revokeObjectURL(url);
        updateTtsUi(t('student.tts.error', 'Vorlesen wurde gestoppt.'));
        vitsAudio = null;
      };
      vitsAudio = audio;
      updateTtsUi(t('student.tts.reading', 'Liest gerade …'));
      try {
        await audio.play();
        updateTtsUi();
        return true;
      } catch (e) {
        URL.revokeObjectURL(url);
        updateTtsUi(t('student.tts.error', 'Vorlesen wurde gestoppt.'));
        return false;
      }
    } catch (err) {
      console.warn('vits-web playback failed', err);
      updateTtsUi(t('student.tts.error', 'Vorlesen wurde gestoppt.'));
      vitsAudio = null;
      return false;
    }
  }

  function speakWithWebSpeech(text){
    if (!webSpeechSupported || !speechSynthesis) return false;
    const normalized = typeof text === 'string' ? text.trim() : '';
    if (!normalized) return false;
    stopTts();
    const utter = new SpeechSynthesisUtterance(normalized);
    utter.rate = ttsRateForLang();
    utter.pitch = 1;
    utter.lang = currentLang === 'en' ? 'en-US' : 'de-DE';
    const voice = pickVoice(utter.lang, voicePrefForLang());
    if (voice) utter.voice = voice;
    utter.onstart = () => { updateTtsUi(t('student.tts.reading', 'Liest gerade …')); };
    utter.onend = () => { updateTtsUi(t('student.tts.ready', 'Bereit zum Vorlesen.')); };
    utter.onerror = () => { updateTtsUi(t('student.tts.error', 'Vorlesen wurde gestoppt.')); };
    ttsUtterance = utter;
    speechSynthesis.speak(utter);
    updateTtsUi();
    return true;
  }

  async function speakCurrentStep(includeGroup = false){
    if (!ttsSupported) return;
    const text = currentStepTextForTts(includeGroup);
    const normalizedText = typeof text === 'string' ? text : '';
    if (!normalizedText || normalizedText.replace(/\s+/g, '').trim() === '') {
      updateTtsUi(t('student.tts.nothing', 'Nichts zum Vorlesen gefunden.'));
      return;
    }
    const ok = await speakWithVits(normalizedText);
    if (!ok) {
      speakWithWebSpeech(normalizedText);
    }
  }

  async function speakText(text){
    if (!ttsSupported) return false;
    const normalized = typeof text === 'string' ? text.trim() : '';
    if (!normalized) return false;
    const ok = await speakWithVits(normalized);
    if (!ok) {
      return speakWithWebSpeech(normalized);
    }
    return true;
  }

  function initTts(){
    updateTtsUi();
    if (!ttsButton) return;
    ttsButton.addEventListener('click', () => {
      if (isTtsSpeaking()) { stopTts(); }
      else {
        updateTtsUi(t('student.tts.reading', 'Liest gerade …'));
        void speakCurrentStep(true);
      }
    });
    if (speechSynthesis && typeof speechSynthesis.addEventListener === 'function') {
      speechSynthesis.addEventListener('voiceschanged', () => updateTtsUi());
    }
    if (TTS_ALLOWED) {
      const voiceId = vitsVoiceIdForLang();
      void loadVitsModule();
      void prefetchVitsModel(voiceId);
      void ensureVitsSession(voiceId);
    }
  }

  async function api(action, payload, options = {}){
    const keepalive = !!options.keepalive;
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, csrf_token: csrf, ...payload }),
      keepalive
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

  function updateAiBeginnerButton(){
    if (!aiHelpButton) return;
    const aiEnabled = !!(state && state.ui && state.ui.ai_enabled);
    const cur = flatSteps[activeStep];
    const show = aiEnabled && isBeginnerMode && cur && cur.kind === 'field' && cur.field && cur.field.id;
    if (show) {
      aiHelpButton.style.display = '';
      aiHelpButton.setAttribute('aria-label', t('student.ai.button', 'Kurze Erklärung'));
      aiHelpButton.setAttribute('data-field-id', String(cur.field.id));
    } else {
      aiHelpButton.style.display = 'none';
      aiHelpButton.removeAttribute('data-field-id');
    }
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

    const aiModalTitle = document.getElementById('aiModalTitle');
    if (aiModalTitle) aiModalTitle.textContent = t('student.ai.title', 'Kurze Erklärung');
    const aiClose = document.getElementById('aiModalClose');
    if (aiClose) aiClose.textContent = t('student.ai.close', 'Schließen');
    if (aiHelpButton) aiHelpButton.setAttribute('aria-label', t('student.ai.button', 'Kurze Erklärung'));

    if (!STUDENT_ACTIVE) {
      const lockedTitle = document.getElementById('lockedTitle');
      const lockedText = document.getElementById('lockedText');
      if (lockedTitle) lockedTitle.textContent = t('student.locked.inactive_title', 'Zugang deaktiviert');
      if (lockedText) lockedText.textContent = t('student.locked.inactive_text', 'Dein Zugang ist derzeit deaktiviert. Bitte wende dich an deine Lehrkraft.');
    }
    else if (!HAS_TEMPLATE) {
      const lockedTitle = document.getElementById('lockedTitle');
      const lockedText = document.getElementById('lockedText');
      if (lockedTitle) lockedTitle.textContent = t('student.locked.none_title');
      if (lockedText) lockedText.textContent = t('student.locked.none_text');
    }
    else if (REPORT_STATUS && REPORT_STATUS !== 'draft') {
      const lockedTitle = document.getElementById('lockedTitle');
      const lockedText = document.getElementById('lockedText');
      if (lockedTitle) {
        lockedTitle.textContent = REPORT_STATUS === 'submitted'
          ? t('student.js.already_submitted', 'Bereits abgegeben')
          : t('student.js.locked_title', 'Eingabe gesperrt');
      }
      if (lockedText) {
        lockedText.textContent = REPORT_STATUS === 'submitted'
          ? t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.')
          : t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.');
      }
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

  function subgroupLabelForField(f){
    const key = String(f?.subgroup || '').trim();
    if (!key) return '';
    if (currentLang === 'en') {
      const titleEn = String(f?.subgroup_title_en || '').trim();
      if (titleEn) return titleEn;
    }
    return key;
  }

  function sectionTitle(groupTitle, section){
    const group = String(groupTitle || t('student.js.section', 'Abschnitt'));
    const sub = String(section?.title || '').trim();
    return sub ? `${group} – ${sub}` : group;
  }

  function stableSectionHash(value){
    let h = 2166136261;
    const raw = String(value || '');
    for (let i = 0; i < raw.length; i += 1) {
      h ^= raw.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return (h >>> 0).toString(36);
  }

  function groupIntroKey(groupKey){
    return 'gi:group:' + stableSectionHash(groupKey);
  }

  function sectionIntroKey(sectionKey){
    return 'gi:section:' + String(sectionKey || '');
  }

  function groupSections(g){
    const gKey = String(g?.key || g?.title || t('student.js.section', 'Abschnitt'));
    const fields = Array.isArray(g?.fields) ? g.fields : [];
    const sections = [];
    const bySubgroup = new Map();
    for (const f of fields) {
      const rawSubgroup = String(f?.subgroup || '').trim();
      const mapKey = rawSubgroup === '' ? '__none__' : rawSubgroup;
      if (!bySubgroup.has(mapKey)) {
        const title = rawSubgroup === '' ? '' : subgroupLabelForField(f);
        const sectionKey = 'sec:' + stableSectionHash(`${gKey}\u001f${rawSubgroup}`) + ':' + sections.length;
        const section = {
          key: sectionKey,
          groupKey: gKey,
          subgroupKey: rawSubgroup,
          title,
          fields: []
        };
        bySubgroup.set(mapKey, section);
        sections.push(section);
      }
      bySubgroup.get(mapKey).fields.push(f);
    }
    return sections.filter(section => Array.isArray(section.fields) && section.fields.length > 0);
  }


  function buildFlatSteps(){
    const steps = Array.isArray(state.steps) ? state.steps : [];
    const intro = steps.find(s => s && s.is_intro);
    const groups = steps.filter(s => s && !s.is_intro);

    displayMode = (state.ui && state.ui.display_mode) ? state.ui.display_mode : 'groups';
    const allowed = ['groups', 'items', 'beginner'];
    if (!allowed.includes(displayMode)) displayMode = 'groups';
    isBeginnerMode = displayMode === 'beginner';

    const out = [];
    if (intro) {
      out.push({ kind:'intro', key: intro.key || 'intro', title: intro.title || 'Start', intro_html: intro.intro_html || '' });
    } else {
      out.push({ kind:'intro', key:'intro', title:'Start', intro_html:'' });
    }

    for (const g of groups) {
      const gKey = String(g.key || g.title || 'Abschnitt');
      const gTitle = String(g.title || g.key || 'Abschnitt');
      const sections = groupSections(g);
      if (!sections.length) continue;
      const allFields = sections.flatMap(section => section.fields);

      out.push({
        kind: 'group_intro',
        introLevel: 'group',
        key: groupIntroKey(gKey),
        title: gTitle,
        group: gKey,
        groupTitle: gTitle,
        subgroup: '',
        subgroupTitle: '',
        fields: allFields
      });

      for (const section of sections) {
        const title = sectionTitle(gTitle, section);
        if (section.title) {
          out.push({
            kind: 'group_intro',
            introLevel: 'subgroup',
            key: sectionIntroKey(section.key),
            title,
            group: gKey,
            groupTitle: gTitle,
            subgroup: section.subgroupKey,
            subgroupTitle: section.title,
            fields: section.fields
          });
        }

        if (displayMode === 'groups') {
          out.push({
            kind:'group',
            key: section.key,
            title,
            group: gKey,
            groupTitle: gTitle,
            subgroup: section.subgroupKey,
            subgroupTitle: section.title,
            fields: section.fields
          });
        } else {
          for (const f of section.fields) {
            out.push({
              kind:'field',
              key: section.key + ':' + String(f.id),
              title,
              group: gKey,
              groupTitle: gTitle,
              subgroup: section.subgroupKey,
              subgroupTitle: section.title,
              field: f
            });
          }
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
    if (isBeginnerMode) {
      const navCard = elNav ? elNav.closest('.card') : null;
      if (elNav) elNav.innerHTML = '';
      if (navCard) navCard.style.display = 'none';
      if (elOverallWrap) elOverallWrap.style.display = 'none';
      return;
    }

    const navCard = elNav ? elNav.closest('.card') : null;
    if (navCard) navCard.style.display = '';
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

    function stepIndexForGroup(gKey){
      return flatSteps.findIndex(s => s.kind === 'group_intro' && s.introLevel === 'group' && String(s.group) === String(gKey));
    }

    function stepIndexForSection(section){
      if (section && section.title) {
        return flatSteps.findIndex(s => s.kind === 'group_intro' && s.introLevel === 'subgroup' && String(s.key) === String(sectionIntroKey(section.key)));
      }
      if (displayMode === 'groups') {
        return flatSteps.findIndex(s => s.kind === 'group' && String(s.key) === String(section?.key || ''));
      }
      const firstField = Array.isArray(section?.fields) ? section.fields[0] : null;
      return firstField ? flatSteps.findIndex(s => s.kind === 'field' && String(s.key) === String(section.key + ':' + String(firstField.id))) : -1;
    }

    for (const g of groups) {
      const gKey = String(g.key || g.title || t('student.js.section', 'Abschnitt'));
      const gTitle = String(g.title || g.key || t('student.js.section', 'Abschnitt'));
      const fields = Array.isArray(g.fields) ? g.fields : [];
      const st = groupStats(fields);
      const badgeCls = (st.missing === 0) ? 'ok' : 'miss';
      const badgeTxt = (st.missing === 0) ? '✓' : String(st.missing);
      const sections = groupSections(g);
      if (!sections.length) continue;
      const groupIdx = stepIndexForGroup(gKey);
      const cur = flatSteps[activeStep];
      const hasNamedSubgroups = sections.some(section => String(section.title || '').trim() !== '');
      const groupActive = cur && String(cur.group || '') === gKey && (
        (cur.kind === 'group_intro' && cur.introLevel === 'group') || !hasNamedSubgroups
      );

      if (displayMode === 'groups') {
        if (!hasNamedSubgroups && sections.length === 1) {
          html.push(`<div class="group wizard-nav-category" data-group="${esc(gKey)}" data-kind="category">
            <div class="group-h ${groupActive ? 'active' : ''}" data-jump="${groupIdx}">
              <div class="left">
                <div class="title">${esc(gTitle)}</div>
                <div class="sub">${st.done}/${st.total}</div>
              </div>
              <span class="badge-mini ${badgeCls}">${esc(badgeTxt)}</span>
            </div>
          </div>`);
          continue;
        }

        html.push(`<div class="group wizard-nav-category open" data-group="${esc(gKey)}" data-kind="category">
          <div class="group-h ${groupActive ? 'active' : ''}" data-jump="${groupIdx}">
            <div class="left">
              <div class="title">${esc(gTitle)}</div>
              <div class="sub">${st.done}/${st.total}</div>
            </div>
            <span class="badge-mini ${badgeCls}">${esc(badgeTxt)}</span>
          </div>
          <div class="items">` +
            sections.map(section => {
              const idx = stepIndexForSection(section);
              const sectionStats = groupStats(section.fields);
              const sectionMissing = sectionStats.missing > 0;
              const sectionActive = cur && String(cur.group || '') === gKey && String(cur.subgroup || '') === String(section.subgroupKey || '') && !(cur.kind === 'group_intro' && cur.introLevel === 'group');
              const title = section.title || t('student.js.general_section', 'Allgemein');
              return `<a class="item wizard-nav-subcategory section-step ${sectionMissing?'missing':'ok'} ${sectionActive?'active':''}" data-jump="${idx}" title="${esc(sectionTitle(gTitle, { title }))}">
                <div class="txt">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="lbl">${esc(title)}</span>
                </div>
                <span class="badge-mini ${sectionMissing?'miss':'ok'}">${sectionMissing ? String(sectionStats.missing) : '✓'}</span>
              </a>`;
            }).join('') +
          `</div>
        </div>`);
      } else {
        html.push(`<div class="group wizard-nav-category" data-group="${esc(gKey)}">
          <div class="group-h ${groupActive ? 'active' : ''}" data-toggle="${esc(gKey)}" data-jump="${groupIdx}">
            <div class="left">
              <div class="title">${esc(gTitle)}</div>
              <div class="sub">${st.done}/${st.total}</div>
            </div>
            <span class="badge-mini ${badgeCls}">${esc(badgeTxt)}</span>
          </div>
          <div class="items">` +
            sections.map(section => {
              const sectionIdx = stepIndexForSection(section);
              const sectionStats = groupStats(section.fields);
              const sectionMissing = sectionStats.missing > 0;
              const sectionActive = cur && String(cur.group || '') === gKey && String(cur.subgroup || '') === String(section.subgroupKey || '') && !(cur.kind === 'group_intro' && cur.introLevel === 'group');
              const sectionLabel = section.title || (hasNamedSubgroups ? t('student.js.general_section', 'Allgemein') : '');
              const showSectionIntro = Boolean(sectionLabel);
              const sectionIntro = showSectionIntro ? `<a class="item wizard-nav-subcategory section-step ${sectionMissing?'missing':'ok'} ${sectionActive?'active':''}" data-jump="${sectionIdx}" title="${esc(sectionTitle(gTitle, { title: sectionLabel }))}">
                <div class="txt">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="lbl">${esc(sectionLabel)}</span>
                </div>
                <span class="badge-mini ${sectionMissing?'miss':'ok'}">${sectionMissing ? String(sectionStats.missing) : '✓'}</span>
              </a>` : '';
              const fieldItems = section.fields.map((f, i) => {
                const missing = fieldIsMissing(f);
                const stepIdx = flatSteps.findIndex(s => s.kind === 'field' && String(s.key) === String(section.key + ':' + String(f.id)));
                const active = stepIdx === activeStep;
                const fullLbl = String(f.label || f.name || tfmt('student.js.question_label', 'Frage {index}', { index: i + 1 }));
                return `<a class="item wizard-nav-field ${missing?'missing':'ok'} ${active?'active':''}" data-jump="${stepIdx}" title="${esc(fullLbl)}">
                  <div class="txt">
                    <span class="dot" aria-hidden="true"></span>
                    <span class="lbl">${esc(tfmt('student.js.question_label', 'Frage {index}', { index: i + 1 }))}</span>
                  </div>
                  <span class="badge-mini ${missing?'miss':'ok'}">${missing?'!':'✓'}</span>
                </a>`;
              }).join('');
              return sectionIntro + fieldItems;
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
        void navigateToStep(v);
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

  async function saveFieldValue(fieldId, valueText, options = {}){
    if (isLocked()) return;
    saveInFlight++;
    setSaving(true);
    setSaveStatus('saving', t('student.js.save_working', '⏳ speichert …'));
    try {
      await api('save_value', { template_field_id: Number(fieldId), value_text: String(valueText ?? '') }, options);
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

  function hasPendingSaves(){
    return dirtyFields.size > 0 || pendingTimers.size > 0 || fieldSaveChains.size > 0 || saveInFlight > 0;
  }

  function setNavigationSaving(on){
    navigationSaveInFlight = !!on;
    if (on) {
      if (btnPrev) btnPrev.disabled = true;
      if (btnNext) btnNext.disabled = true;
    }
    if (elNav) elNav.classList.toggle('saving-disabled', !!on);
  }

  function runQueuedFieldSave(fieldId){
    const key = String(fieldId);
    if (fieldSaveChains.has(key)) return fieldSaveChains.get(key);

    const chain = (async () => {
      try {
        while (!isLocked() && dirtyFields.has(key)) {
          const valueToSave = dirtyFields.get(key);
          const ok = await saveFieldValue(fieldId, valueToSave);
          if (!ok) return false;
          if (dirtyFields.get(key) === valueToSave) {
            dirtyFields.delete(key);
          }
        }
        return true;
      } finally {
        fieldSaveChains.delete(key);
      }
    })();

    fieldSaveChains.set(key, chain);
    return chain;
  }

  function queueFieldSave(fieldId, valueText, options = {}){
    if (isLocked()) return Promise.resolve(true);
    const key = String(fieldId);
    dirtyFields.set(key, String(valueText ?? ''));

    if (pendingTimers.has(key)) {
      clearTimeout(pendingTimers.get(key).timer);
      pendingTimers.delete(key);
    }

    if (options.immediate) return runQueuedFieldSave(fieldId);

    const delayMs = Number.isFinite(Number(options.delayMs)) ? Number(options.delayMs) : 700;
    const entry = { value: String(valueText ?? ''), timer: null };
    entry.timer = setTimeout(() => {
      pendingTimers.delete(key);
      void runQueuedFieldSave(fieldId);
    }, delayMs);
    pendingTimers.set(key, entry);
    return Promise.resolve(true);
  }

  function debounceSave(fieldId, valueText, delayMs=700){
    return queueFieldSave(fieldId, valueText, { delayMs });
  }

  async function flushPendingSavesBlocking(){
    if (isLocked()) return true;
    pendingTimers.forEach((entry) => {
      if (entry && entry.timer) clearTimeout(entry.timer);
    });
    pendingTimers.clear();

    const keys = new Set([...dirtyFields.keys(), ...fieldSaveChains.keys()]);
    if (!keys.size) return true;

    const results = await Promise.all([...keys].map((key) => runQueuedFieldSave(Number(key))));
    const ok = results.every(Boolean) && dirtyFields.size === 0;
    if (!ok) {
      setSaveStatus('error', t('student.js.save_error_block_nav', 'Bitte speichere zuerst erfolgreich.'));
    }
    return ok;
  }

  async function navigateToStep(nextStep){
    if (navigationSaveInFlight || isLocked()) return;
    const v = Number(nextStep);
    if (!Number.isFinite(v) || v < 0 || v >= flatSteps.length) return;
    if (v === activeStep) return;

    setNavigationSaving(true);
    let didNavigate = false;
    try {
      const ok = await flushPendingSavesBlocking();
      if (!ok) return;
      activeStep = v;
      didNavigate = true;
      render();
      window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    } finally {
      setNavigationSaving(false);
      if (!didNavigate) render();
    }
  }

  function fireAndForgetSave(fieldId, valueText){
    const payload = { action: 'save_value', csrf_token: csrf, template_field_id: Number(fieldId), value_text: String(valueText ?? '') };
    const body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
      const blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(apiUrl, blob);
      return;
    }
    fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
      keepalive: true,
    }).catch(()=>{});
  }

  function flushPendingSaves(){
    pendingTimers.forEach((entry) => {
      if (entry && entry.timer) clearTimeout(entry.timer);
    });
    pendingTimers.clear();
    dirtyFields.forEach((value, key) => {
      fireAndForgetSave(key, value);
    });
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

  function renderFieldBlock(f, opts = {}){
    const fid = Number(f.id);
    const type = String(f.type || 'text');
    const idx = buildFieldNameIndex();
    const label = resolveTextTemplate(String(f.label || f.name || 'Feld'), idx);
    const help = resolveTextTemplate(String(f.help || ''), idx);
    const multiline = !!f.multiline;
    const val = fieldValueText(f);
    const maxLenRaw = f && typeof f.max_length !== 'undefined' ? Number(f.max_length) : null;
    const maxLen = Number.isFinite(maxLenRaw) && maxLenRaw > 0 ? Math.floor(maxLenRaw) : null;
    const maxAttr = maxLen ? `maxlength="${esc(String(maxLen))}"` : '';

    const showLabel = opts.showLabel !== false;
    const allowHelp = opts.showHelp !== false;
    const showHelp = allowHelp && !!help;
    const helpStyle = showHelp ? '' : 'display:none;';

    const missing = fieldIsMissing(f);
    const wrapCls = 'q' + (missing ? ' missing' : '');
    const aiEnabled = !!(state && state.ui && state.ui.ai_enabled);
    const aiBtn = (showLabel && aiEnabled && !isBeginnerMode)
      ? `<button class="ai-help-btn" type="button" data-ai-help="1" data-field-id="${fid}" aria-label="${esc(t('student.ai.button', 'Kurze Erklärung'))}">?</button>`
      : '';
    const labelHtml = showLabel
      ? (aiBtn ? `<div class="ai-help-row"><div class="lbl" data-dyn="label">${esc(label)}</div>${aiBtn}</div>` : `<div class="lbl" data-dyn="label">${esc(label)}</div>`)
      : `<div class="lbl" data-dyn="label" style="display:none;">${esc(label)}</div>`;

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
        ${labelHtml}
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
        <div class="help" data-dyn="help" style="${helpStyle}">${esc(help)}</div>
      </div>`;
    }

    if (multiline || type === 'textarea') {
      return `<div class="${wrapCls}" data-field="${fid}">
        ${labelHtml}
        <textarea rows="4" class="input" data-input="1" ${maxAttr} style="width:100%;">${esc(val)}</textarea>
        <div class="help" data-dyn="help" style="${helpStyle}">${esc(help)}</div>
      </div>`;
    }

    return `<div class="${wrapCls}" data-field="${fid}">
      ${labelHtml}
      <input type="text" class="input" data-input="1" ${maxAttr} style="width:100%;" value="${esc(val)}">
      <div class="help" data-dyn="help" style="${helpStyle}">${esc(help)}</div>
    </div>`;
  }

  async function fetchAiExplanation(fieldId){
    const res = await fetch(aiApiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ template_field_id: Number(fieldId), lang: currentLang, csrf_token: csrf })
    });
    const j = await res.json().catch(()=>null);
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Fehler');
    return String(j.text || '');
  }

  async function handleAiHelpClick(btn){
    if (!btn || btn.disabled) return;
    const fid = Number(btn.getAttribute('data-field-id') || 0);
    if (!fid) return;
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = '…';
    try {
      const text = await fetchAiExplanation(fid);
      const ttsActive = TTS_ALLOWED && ttsSupported;
      if (ttsActive) {
        void speakText(text);
      } else {
        openAiModal(text);
      }
    } catch (e) {
      const msg = (e && e.message) ? e.message : t('student.ai.error', 'KI konnte keine Erklärung liefern.');
      openAiModal(msg);
    } finally {
      btn.disabled = false;
      btn.innerHTML = old || '<i class="fa fa-question" aria-hidden="true"></i>';
    }
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
        const key = String(fid);
        if (pendingTimers.has(key)) {
          clearTimeout(pendingTimers.get(key).timer);
          pendingTimers.delete(key);
        }
        void queueFieldSave(fid, v, { immediate: true });
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
        if (isBeginnerMode) {
          suppressTtsOnce = true;
          playPling();
        }
        render();
        await queueFieldSave(fid, v, { immediate: true });
      };
      card.addEventListener('click', click);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); click(); }
      });
    });

    container.querySelectorAll('[data-ai-help="1"]').forEach(btn => {
      btn.addEventListener('click', () => handleAiHelpClick(btn));
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

  function firstMissingStepIndex(){
    if (!Array.isArray(flatSteps)) return null;
    for (let i = 0; i < flatSteps.length; i += 1) {
      const step = flatSteps[i];
      if (!step || step.kind !== 'field') continue;
      if (fieldIsMissing(step.field)) return i;
    }
    return null;
  }

  function firstFieldIndexForSectionStep(matchStep){
    if (!Array.isArray(flatSteps) || !matchStep) return null;
    for (let i = 0; i < flatSteps.length; i += 1) {
      const step = flatSteps[i];
      if (!step || step.kind !== 'field') continue;
      if (String(step.group) !== String(matchStep.group)) continue;
      if (String(step.subgroup || '') !== String(matchStep.subgroup || '')) continue;
      return i;
    }
    return null;
  }

  function initialStepIndex(){
    const total = totalFieldCount();
    const missing = totalMissingCount();
    if (total > 0 && missing === total) {
      return 0;
    }
    const missingIdx = firstMissingStepIndex();
    if (typeof missingIdx !== 'number') return 0;
    const step = flatSteps[missingIdx];
    if (step && step.kind === 'field' && displayMode !== 'groups') {
      const firstIdx = firstFieldIndexForSectionStep(step);
      if (firstIdx === missingIdx) {
        const giIdx = flatSteps.findIndex(s => s.kind === 'group_intro' && String(s.group) === String(step.group) && String(s.subgroup || '') === String(step.subgroup || ''));
        if (giIdx >= 0) return giIdx;
      }
    }
    return missingIdx;
  }

  function updateReqHint(){
    if (isBeginnerMode) {
      if (elReqHint) elReqHint.textContent = '';
      return;
    }
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
    const total = totalFieldCount();
    const missing = totalMissingCount();
    const done = Math.max(0, total - missing);
    const pct = (total > 0) ? Math.round((done/total)*100) : 0;

    if (elOverallWrap && elOverallBar) {
      elOverallWrap.style.display = (!isBeginnerMode && total > 0) ? '' : 'none';
      if (elOverallText) elOverallText.textContent = (total > 0)
        ? tfmt('student.js.progress_text', 'Fortschritt: {done}/{total} (offen: {missing})', { done, total, missing })
        : t('student.js.progress_empty', '—');
      if (elOverallPct) elOverallPct.textContent = (total > 0) ? (pct + '%') : '';

      elOverallBar.style.width = (total > 0) ? (pct + '%') : '0%';
      elOverallBar.classList.toggle('ok', total > 0 && missing === 0);
    }

    if (elBeginnerProgressWrap && elBeginnerProgressBar) {
      elBeginnerProgressWrap.style.display = (isBeginnerMode && total > 0) ? 'block' : 'none';
      elBeginnerProgressBar.style.width = (total > 0) ? (pct + '%') : '0%';
      elBeginnerProgressBar.classList.toggle('ok', total > 0 && missing === 0);
    }
  }

  async function handleSubmit(){
    if (isLocked() || navigationSaveInFlight) return;

    const saved = await flushPendingSavesBlocking();
    if (!saved) {
      alert(t('student.js.save_error_block_nav', 'Bitte speichere zuerst erfolgreich.'));
      return;
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

      if (String(state.report_status || '') === 'submitted') {
        exitFullscreenForBeginner();
        alert(t('student.js.submit_thanks', 'Danke! Du hast abgegeben.'));
        showLockedOnly(
          t('student.js.already_submitted', 'Bereits abgegeben'),
          t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.')
        );
        return;
      }

      if (isLocked()) {
        showLockedOnly();
        return;
      }

      buildFlatSteps();
      activeStep = flatSteps.findIndex(s => s.kind==='submit');
      if (activeStep < 0) activeStep = flatSteps.length - 1;
      render();
      exitFullscreenForBeginner();
      alert(t('student.js.submit_thanks', 'Danke! Du hast abgegeben.'));
    } catch(e){
      alert(e?.message || t('student.js.submit_error', 'Fehler beim Abgeben.'));
    } finally {
      setSaving(false);
      if (!isLocked()) {
        btnPrev.disabled = false;
        btnNext.disabled = false;
      }
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

    document.body.classList.toggle('beginner-mode', isBeginnerMode);
    if (isBeginnerMode) enterFullscreenForBeginner();
    else exitFullscreenForBeginner();
    placeTtsButton();
    updateTtsUi();

    const tplName = state.template ? String(state.template.name || '') : '';
    const ver = state.template ? String(state.template.version || '') : '';
    elMeta.textContent = tplName ? (tplName + (ver ? (' · v' + ver) : '')) : t('student.js.form_label', 'Formular');
    elMeta.style.display = isBeginnerMode ? 'none' : '';

    setLockedUi();
    renderNav();
    if (elReqHint) elReqHint.style.display = isBeginnerMode ? 'none' : '';

    const cur = flatSteps[activeStep];
    if (!cur) return;

    btnPrev.style.visibility = (activeStep <= 0) ? 'hidden' : 'visible';
    btnNext.classList.remove('cta-ready');

    if (cur.kind === 'intro') {
      elTitle.textContent = t('student.js.start_title', 'Start');
      elSub.textContent = isBeginnerMode ? '' : t('student.js.start_sub', 'Bitte lies die Infos. Danach geht es los.');
      const html = (cur.intro_html || '').trim();
      elBody.innerHTML = `<div class="intro-box">${html ? html : `<p class="muted">${esc(t('student.js.no_intro', 'Keine Intro-Infos hinterlegt.'))}</p>`}</div>`;
      btnNext.textContent = t('student.js.cta_start', 'Los geht’s');
      btnPrev.disabled = (activeStep <= 0);
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
      if (isBeginnerMode && TTS_ALLOWED && ttsSupported && !didAutoReadIntro) {
        speakCurrentStep();
        didAutoReadIntro = true;
      }
    }

    else if (cur.kind === 'group') {
      const subgroupTitle = String(cur.subgroupTitle || '').trim();
      elTitle.textContent = cur.groupTitle || cur.title;
      elSub.textContent = isBeginnerMode ? '' : (subgroupTitle || t('student.js.group_sub', 'Du kannst weiterklicken und später zurückspringen, wenn etwas fehlt.'));
      const headerHtml = subgroupTitle
        ? `<div class="subsection-h"><div class="t">${esc(subgroupTitle)}</div><div class="s">${esc(cur.groupTitle || cur.group || '')}</div></div>`
        : '';
      elBody.innerHTML = headerHtml + (((cur.fields || []).map(f => renderFieldBlock(f)).join('')) || `<p class="muted">${esc(t('student.js.no_fields', 'Keine Felder.'))}</p>`);
      attachFieldHandlers(elBody);
      btnNext.textContent = t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
    }

    else if (cur.kind === 'group_intro') {
      const fields = Array.isArray(cur.fields) ? cur.fields : [];
      const subgroupTitle = String(cur.subgroupTitle || '').trim();
      const isSubgroupIntro = cur.introLevel === 'subgroup' && subgroupTitle !== '';
      const heading = isSubgroupIntro ? subgroupTitle : (cur.groupTitle || cur.title || t('student.js.section', 'Abschnitt'));
      const context = isSubgroupIntro ? (cur.groupTitle || cur.group || '') : '';
      elTitle.textContent = heading;
      elSub.textContent = isBeginnerMode ? context : (context || t('student.js.group_intro_sub', 'Bevor es losgeht: kurze Übersicht.'));
      const contextHtml = context ? `<div class="muted" style="margin-bottom:8px;">${esc(context)}</div>` : '';
      elBody.innerHTML = isBeginnerMode
        ? `<div class="group-intro">
            ${contextHtml}
            <h2 style="margin:0; font-weight:900; font-size:28px;">${esc(heading)}</h2>
          </div>`
        : `<div class="group-intro">
            <p class="kicker">${esc(t('student.js.group_intro_kicker', 'Neuer Abschnitt'))}</p>
            ${contextHtml}
            <h3>${esc(heading)}</h3>
            <div class="muted">${esc(tfmt('student.js.group_intro_hint', 'Hier kommen {count} Fragen. Du kannst jederzeit im Menü springen.', { count: fields.length }))}</div>
            <div style="margin-top:12px;"><button class="btn" type="button" id="btnStartGroup">${esc(t('student.js.cta_begin_group', 'Starten'))}</button></div>
          </div>`;
      const b = document.getElementById('btnStartGroup');
      if (b) {
        b.addEventListener('click', () => {
          const idx = flatSteps.findIndex(s => s.kind === 'field' && String(s.group) === String(cur.group) && String(s.subgroup || '') === String(cur.subgroup || ''));
          if (idx >= 0) { activeStep = idx; render(); }
          else { activeStep = Math.min(activeStep + 1, flatSteps.length - 1); render(); }
        });
      }
      btnNext.textContent = isBeginnerMode ? t('student.buttons.next', 'Weiter') : t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      btnNext.disabled = false;
      btnNext.style.visibility = 'visible';
      if (isBeginnerMode && TTS_ALLOWED && ttsSupported) {
        speakCurrentStep(false);
      }
    }

    else if (cur.kind === 'field') {
      const f = cur.field;
      const idx = buildFieldNameIndex();
      const fieldLabel = resolveTextTemplate(String(f.label || f.name || tfmt('student.js.question_label', 'Frage {index}', { index: 1 })), idx);
      const sectionLabel = cur.subgroupTitle ? sectionTitle(cur.groupTitle || cur.group, { title: cur.subgroupTitle }) : (cur.groupTitle || cur.group || t('student.js.section', 'Abschnitt'));
      elTitle.textContent = isBeginnerMode ? fieldLabel : sectionLabel;
      elSub.textContent = isBeginnerMode
        ? sectionLabel
        : t('student.js.field_sub', 'Eine Frage nach der anderen. Du kannst jederzeit zurückspringen.');
      elBody.innerHTML = renderFieldBlock(f, { showLabel: !isBeginnerMode, showHelp: !isBeginnerMode });
      attachFieldHandlers(elBody);
      btnNext.textContent = t('student.js.cta_next', t('student.buttons.next', 'Weiter'));
      const type = String(f.type || 'text');
      const isChoiceField = ['radio','select','grade','checkbox'].includes(type);
      const ready = isChoiceField && !fieldIsMissing(f);
      btnNext.disabled = isBeginnerMode ? !ready : false;
      btnNext.classList.toggle('cta-ready', isBeginnerMode && ready);
      btnNext.style.visibility = 'visible';

      if (isBeginnerMode && TTS_ALLOWED && ttsSupported) {
        if (!suppressTtsOnce) speakCurrentStep(false);
        suppressTtsOnce = false;
      }
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

    if (isBeginnerMode) {
      btnPrev.innerHTML = '<i class="fa fa-angle-double-left" aria-hidden="true"></i>';
  btnNext.innerHTML = '<i class="fa fa-angle-double-right" aria-hidden="true"></i>';
      btnPrev.setAttribute('aria-label', t('student.buttons.prev'));
      btnNext.setAttribute('aria-label', t('student.buttons.next'));
    }

    btnPrev.onclick = () => {
      if (activeStep > 0) void navigateToStep(activeStep - 1);
    };
    btnNext.onclick = () => {
      const cur = flatSteps[activeStep];
      if (!cur) return;
      if (cur.kind === 'submit') return;
      void navigateToStep(Math.min(activeStep + 1, flatSteps.length - 1));
    };

    updateReqHint();
    refreshDynamicTexts(elBody);
    updateOverallProgress();

    if (displayMode === 'items') {
      const cur = flatSteps[activeStep];
      const curGroup = cur && (cur.group || cur.groupTitle);
      elNav.querySelectorAll('.group').forEach(g => {
        const key = g.getAttribute('data-group');
        if (key && curGroup && String(key) === String(curGroup)) g.classList.add('open');
      });
    }

    updateAiBeginnerButton();
  }

  if (aiHelpButton) aiHelpButton.addEventListener('click', () => handleAiHelpClick(aiHelpButton));
  if (aiModalClose) aiModalClose.addEventListener('click', closeAiModal);
  if (aiModal) {
    aiModal.addEventListener('click', (e) => {
      if (e.target === aiModal) closeAiModal();
    });
  }
  window.addEventListener('beforeunload', (event) => {
    if (!hasPendingSaves()) return;
    flushPendingSaves();
    const message = t('student.js.unsaved_changes', 'Es gibt noch ungespeicherte Änderungen.');
    event.preventDefault();
    event.returnValue = message;
    return message;
  });
  window.addEventListener('pagehide', flushPendingSaves);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      flushPendingSaves();
    } else if (hasPendingSaves()) {
      void flushPendingSavesBlocking();
    }
  });

  (async function init(){
    try {
      initTts();
      if (!STUDENT_ACTIVE) {
        showLockedOnly(
          t('student.locked.inactive_title', 'Zugang deaktiviert'),
          t('student.locked.inactive_text', 'Dein Zugang ist derzeit deaktiviert. Bitte wende dich an deine Lehrkraft.')
        );
        elMeta.textContent = '';
        return;
      }
      if (!HAS_TEMPLATE) return;

      const initialStatus = String(REPORT_STATUS || 'draft');
      if (initialStatus !== 'draft') {
        if (initialStatus === 'submitted') {
          showLockedOnly(t('student.js.already_submitted', 'Bereits abgegeben'), t('student.js.already_submitted_text', 'Du hast deine Eingabe bereits abgegeben. Änderungen sind nicht mehr möglich.'));
        } else if (initialStatus === 'locked') {
          showLockedOnly(t('student.js.locked_title', 'Eingabe gesperrt'), t('student.js.locked_text', 'Deine Lehrkraft hat die Eingabe gerade gesperrt. Bitte versuche es später noch einmal.'));
        } else {
          showLockedOnly(t('student.js.not_ready_title', 'Eingabe noch nicht freigegeben oder bereits abgegeben'), t('student.js.not_ready_text', 'Deine Lehrkraft hat die Eingabe noch nicht freigegeben oder du hast deine Eingabe bereits abgegeben. Bitte versuche es später noch einmal.'));
        }
        elMeta.textContent = '';
        return;
      }

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
      activeStep = initialStepIndex();
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
<?php render_history_replace_state_script(); ?>
</body>
</html>
