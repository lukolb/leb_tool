<?php
declare(strict_types=1);
// parent/portal.php
require __DIR__ . '/../bootstrap.php';

$pdo = db();
$token = (string)($_GET['token'] ?? '');
$alerts = [];
$errors = [];

function parent_meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function parent_option_list_id(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function parent_resolve_option_value(PDO $pdo, array $meta, ?string $valueJson, ?string $valueText): string {
  $listId = parent_option_list_id($meta);
  if ($listId <= 0) return (string)($valueText ?? '');

  $optId = 0;
  if ($valueJson) {
    $j = json_decode($valueJson, true);
    if (is_array($j) && isset($j['option_item_id'])) $optId = (int)$j['option_item_id'];
  }
  if ($optId > 0) {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }

  // fallback: by value_text (legacy)
  $vt = trim((string)($valueText ?? ''));
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }

  return (string)($valueText ?? '');
}

function parent_portal_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

/**
 * Extract expected date format from meta_json
 */
function parent_extract_date_format_from_meta(array $meta): string {
  $mode = isset($meta['date_format_mode']) ? (string)$meta['date_format_mode'] : '';
  $mode = strtolower(trim($mode));

  $preset = isset($meta['date_format_preset']) ? trim((string)$meta['date_format_preset']) : '';
  $custom = isset($meta['date_format_custom']) ? trim((string)$meta['date_format_custom']) : '';

  if ($mode === 'custom') return $custom;
  return $preset;
}

/**
 * ✅ NEW: class-field detection (matches teacher/export logic)
 */
function parent_is_class_field(array $meta): bool {
  if (isset($meta['scope']) && is_string($meta['scope']) && strtolower(trim($meta['scope'])) === 'class') return true;
  if (isset($meta['is_class_field']) && (int)$meta['is_class_field'] === 1) return true;
  return false;
}

/**
 * ✅ NEW: find class report instance (student_id=0, period_label='__class__')
 */
function parent_find_class_report_instance_id(PDO $pdo, int $templateId, string $schoolYear): ?int {
  $st = $pdo->prepare(
    "SELECT id
     FROM report_instances
     WHERE template_id=? AND student_id=0 AND school_year=? AND period_label='__class__'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear]);
  $id = (int)($st->fetchColumn() ?: 0);
  return $id > 0 ? $id : null;
}

/**
 * ✅ NEW: load resolved values for a report instance (option lists stable via option_item_id)
 * Returns: field_name => resolved string
 */
function parent_load_values_for_report(PDO $pdo, int $reportInstanceId): array {
  $st = $pdo->prepare(
    "SELECT tf.field_name, tf.meta_json, fv.value_text, fv.value_json, fv.source, fv.updated_at
     FROM field_values fv
     JOIN template_fields tf ON tf.id=fv.template_field_id
     WHERE fv.report_instance_id=?
     ORDER BY fv.updated_at ASC, fv.id ASC"
  );
  $st->execute([$reportInstanceId]);

  $priority = ['child' => 1, 'system' => 2, 'teacher' => 3];
  $map = [];

  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $field = (string)($r['field_name'] ?? '');
    if ($field === '') continue;

    $src = (string)($r['source'] ?? 'teacher');
    $meta = parent_meta_read($r['meta_json'] ?? null);

    $valueText = $r['value_text'] !== null ? (string)$r['value_text'] : null;
    $valueJson = $r['value_json'] !== null ? (string)$r['value_json'] : null;
    $resolved = parent_resolve_option_value($pdo, $meta, $valueJson, $valueText);

    $current = $map[$field] ?? null;
    $currentScore = $current ? ($priority[$current['source']] ?? 0) : -1;
    $newScore = $priority[$src] ?? 0;

    $useNew = false;
    if ($newScore > $currentScore) {
      $useNew = true;
    } elseif ($newScore === $currentScore && $current) {
      $curTs = strtotime((string)($current['updated_at'] ?? '')) ?: 0;
      $newTs = strtotime((string)($r['updated_at'] ?? '')) ?: 0;
      if ($newTs >= $curTs) $useNew = true;
    }

    if ($useNew || !$current) {
      $map[$field] = [
        'value' => $resolved,
        'source' => $src,
        'updated_at' => (string)($r['updated_at'] ?? ''),
      ];
    }
  }

  return array_map(static fn($row) => (string)($row['value'] ?? ''), $map);
}

/**
 * Build field meta mapping for JS (date normalization)
 * Returns: field_name => ['field_type' => 'date', 'date_format' => 'DD. MMMM YYYY']
 */
function parent_build_field_meta_map(PDO $pdo, int $reportId): array {
  $st = $pdo->prepare(
    "SELECT tf.field_name, tf.field_type, tf.meta_json
     FROM template_fields tf
     WHERE tf.template_id=(SELECT template_id FROM report_instances WHERE id=? LIMIT 1)
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId]);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = (string)($row['field_name'] ?? '');
    if ($name === '') continue;

    $type = (string)($row['field_type'] ?? 'text');
    $meta = parent_meta_read($row['meta_json'] ?? null);
    $df = parent_extract_date_format_from_meta($meta);

    $entry = ['field_type' => $type];
    if (trim($df) !== '') $entry['date_format'] = $df;

    $out[$name] = $entry;
  }
  return $out;
}

function parent_collect_preview_fields(PDO $pdo, int $reportId, string $lang, bool $autoTranslate): array {
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.meta_json, tf.field_type, tf.sort_order,\n" .
    "       fv.value_text, fv.value_json, fv.source, fv.updated_at\n" .
    "FROM template_fields tf\n" .
    "LEFT JOIN field_values fv ON fv.template_field_id=tf.id AND fv.report_instance_id=?\n" .
    "WHERE tf.template_id=(SELECT template_id FROM report_instances WHERE id=? LIMIT 1)\n" .
    "ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId, $reportId]);

  $priority = ['child' => 1, 'system' => 2, 'teacher' => 3];
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)($row['field_name'] ?? '');
    if ($key === '') continue;
    $src = (string)($row['source'] ?? 'teacher');
    $meta = parent_meta_read($row['meta_json'] ?? null);
    $label = (string)($row['label'] ?? $key);
    $resolved = parent_resolve_option_value($pdo, $meta, $row['value_json'] ?? null, $row['value_text'] ?? '');

    $existing = $map[$key] ?? null;
    $curScore = $existing ? ($priority[$existing['source']] ?? 0) : -1;
    $newScore = $priority[$src] ?? 0;
    if ($newScore > $curScore || !$existing) {
      $map[$key] = [
        'label' => $label,
        'value' => $resolved,
        'source' => $src,
      ];
    }
  }

  return array_map(function($row) {
    $val = (string)($row['value'] ?? '');
    if ($val === '') $val = t('parent.portal.empty', '–');
    return [
      'label' => (string)($row['label'] ?? ''),
      'value' => $val,
      'source' => (string)($row['source'] ?? ''),
    ];
  }, array_values($map));
}

if ($token === '') {
  http_response_code(400);
  echo 'Token fehlt.';
  exit;
}

$st = $pdo->prepare(
  "SELECT ppl.*, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name,\n" .
  "       ri.template_id, ri.period_label, ri.school_year AS report_school_year\n" .
  "FROM parent_portal_links ppl\n" .
  "JOIN students s ON s.id=ppl.student_id\n" .
  "JOIN report_instances ri ON ri.id=ppl.report_instance_id\n" .
  "JOIN classes c ON c.id=s.class_id\n" .
  "WHERE ppl.token=?\n" .
  "LIMIT 1"
);
$st->execute([$token]);
$link = $st->fetch(PDO::FETCH_ASSOC);

if (!$link) {
  http_response_code(404);
  echo 'Freigabe nicht gefunden.';
  exit;
}

$lang = 'de';

$expiresAt = $link['expires_at'] ?? null;
$isExpired = false;
if ($expiresAt) {
  $isExpired = (strtotime((string)$expiresAt) < time());
  if ($isExpired && ($link['status'] ?? '') !== 'expired') {
    $pdo->prepare("UPDATE parent_portal_links SET status='expired', updated_at=NOW() WHERE id=? LIMIT 1")->execute([(int)$link['id']]);
    $link['status'] = 'expired';
  }
}

$status = (string)($link['status'] ?? '');
$allowResponses = ($status === 'approved' && !$isExpired);
$canPreview = ($status === 'approved');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    csrf_verify();
    $action = (string)$_POST['action'];
    if (!$allowResponses) throw new RuntimeException('Rückmeldungen sind aktuell nicht möglich.');

    if ($action === 'send_feedback') {
      $message = trim((string)($_POST['message'] ?? ''));
      $type = 'question';
      $ins = $pdo->prepare(
        "INSERT INTO parent_feedback (link_id, feedback_type, message, language, auto_translated, created_at)\n" .
        "VALUES (?, ?, ?, ?, 0, NOW())"
      );
      $ins->execute([(int)$link['id'], $type, $message, 'de']);
      $alerts[] = t('parent.portal.feedback_ok', 'Danke für Ihre Rückmeldung! Wir werden diese baldmöglichst bearbeiten.');
    }
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$fields = $canPreview ? parent_collect_preview_fields($pdo, (int)$link['report_instance_id'], $lang, false) : [];
$previewPayload = [];

if ($canPreview) {
  // ✅ meta map for date normalization in JS
  $fieldMeta = parent_build_field_meta_map($pdo, (int)$link['report_instance_id']);

  $previewPayload = [
    'template_url' => url('parent/template_file.php?token=' . urlencode($token)),
    'student' => [
      'id' => (int)$link['student_id'],
      'values' => [],
    ],
    'field_meta' => $fieldMeta,
  ];

  // ✅ NEW: determine class-wide field names for this template
  $templateId = (int)($link['template_id'] ?? 0);
  $reportSchoolYear = (string)($link['report_school_year'] ?? '');
  $classFieldNames = [];

  if ($templateId > 0) {
    $stClassFields = $pdo->prepare(
      "SELECT field_name, meta_json
       FROM template_fields
       WHERE template_id=?
       ORDER BY sort_order ASC, id ASC"
    );
    $stClassFields->execute([$templateId]);
    foreach ($stClassFields->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $fn = (string)($row['field_name'] ?? '');
      if ($fn === '') continue;
      $m = parent_meta_read($row['meta_json'] ?? null);
      if (parent_is_class_field($m)) $classFieldNames[$fn] = true;
    }
  }

  // ✅ Load student values (resolved)
  $values = parent_load_values_for_report($pdo, (int)$link['report_instance_id']);

  // ✅ NEW: merge class-wide values on top (override for class fields)
  if ($templateId > 0 && $reportSchoolYear !== '' && $classFieldNames) {
    $classRiId = parent_find_class_report_instance_id($pdo, $templateId, $reportSchoolYear);
    if ($classRiId) {
      $classValues = parent_load_values_for_report($pdo, (int)$classRiId);
      foreach ($classFieldNames as $fname => $_) {
        if (array_key_exists($fname, $classValues)) {
          $values[$fname] = (string)$classValues[$fname];
        }
      }
    }
  }


  // ✅ NEW: If template contains signature fields, prefill them with the requesting teacher's name (read-only default).
  // Teacher is determined via parent_portal_links.requested_by_user_id -> users.display_name
  if ($templateId > 0) {
    $sigSt = $pdo->prepare("SELECT field_name FROM template_fields WHERE template_id=? AND field_type='signature'");
    $sigSt->execute([$templateId]);
    $sigFields = $sigSt->fetchAll(PDO::FETCH_COLUMN);

    if ($sigFields) {
      $teacherName = '';
      $tst = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
      $tst->execute([(int)($link['requested_by_user_id'] ?? 0)]);
      $teacherName = trim((string)$tst->fetchColumn());

      if ($teacherName !== '') {
        // Vorname abkürzen: "Max Mustermann" → "M. Mustermann"
        $parts = preg_split('/\s+/', $teacherName, 2);

        if (count($parts) === 2) {
          $firstInitial = mb_substr($parts[0], 0, 1, 'UTF-8');
          $teacherName  = $firstInitial . '. ' . $parts[1];
        }
        foreach ($sigFields as $sf) {
          $sf = (string)$sf;
          if ($sf === '') continue;
          if (!isset($values[$sf]) || trim((string)$values[$sf]) === '') {
            $values[$sf] = $teacherName;
          }
        }
      }
    }
  }

  $previewPayload['student']['values'] = $values;
}

$b = brand();
$org = $b['org_name'] ?? 'LEG Tool';
$logo = $b['logo_path'] ?? '';
$primary = $b['primary'] ?? '#0b57d0';
$secondary = $b['secondary'] ?? '#111111';
?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t('parent.portal.title', 'Elternmodus – Vorschau'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($primary)?>;--secondary:<?=h($secondary)?>;}</style>
  <style>
    #pdfPreview { position: relative; }

    /* Loader Overlay */
    #pdfPreview .pdf-loader{
      margin: 30px;
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      gap: 10px;
      background: rgba(248,249,251,.85);
      backdrop-filter: blur(2px);
      border-radius: inherit;
      z-index: 5;
      pointer-events: none;
      opacity: 1;
      transition: opacity .15s ease;
    }

    /* sobald ein Canvas vorhanden ist → Loader aus */
    #pdfPreview:has(canvas) .pdf-loader { opacity: 0; }

    /* Spinner */
    #pdfPreview .spinner{
      width: 34px;
      height: 34px;
      border-radius: 50%;
      border: 3px solid rgba(0,0,0,.15);
      border-top-color: rgba(0,0,0,.55);
      animation: spin .9s linear infinite;
    }

    #pdfPreview .txt { font-size: 13px; color: rgba(0,0,0,.65); }

    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle"><?=h(t('parent.portal.subtitle', 'Elternmodus – nur Lesen'))?></div>
      </div>
    </div>
  </div>

  <div class="container" style="max-width:960px;">
    <div class="card">
      <h1><?=h(t('parent.portal.heading', 'Lernentwicklungsbericht'))?></h1>
      <p class="muted" style="max-width:820px;">
        <?=h(t('parent.portal.readonly_hint', 'Der Abruf ist zeitlich begrenzt.'))?>
      </p>
      <div class="pill"><?=h((string)$link['first_name'] . ' ' . (string)$link['last_name'])?></div>
      <div class="muted" style="margin-top:4px;">
        <?=h(t('parent.portal.class', 'Klasse'))?>: <?=h((string)$link['school_year'])?> · <?=h(parent_portal_class_display($link))?>
      </div>
      <div class="muted" style="margin-top:12px;">
        <?=h(t('parent.portal.valid_until', 'Gültig bis'))?>: <?=h($expiresAt ? date_format(date_create($expiresAt),"d.m.Y H:i") : t('parent.portal.no_expiry', 'ohne Enddatum'))?>
      </div>
      <?php if ($status === 'requested'): ?>
        <div class="alert warn" style="margin-top:10px;"><?=h(t('parent.portal.waiting', 'Freigabe wird noch durch die Schule bestätigt.'))?></div>
      <?php elseif ($status === 'revoked'): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.revoked', 'Dieser Zugang wurde deaktiviert.'))?></div>
      <?php elseif ($isExpired): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.expired', 'Dieser Zugang ist abgelaufen.'))?></div>
      <?php endif; ?>
    </div>

    <?php if (!$canPreview): ?>
      <div class="alert warn" style="margin-top:10px;">
        <?=h(t('parent.portal.preview_blocked', 'Die Freigabe ist noch nicht aktiv oder bereits beendet.'))?>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($alerts): ?>
        <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <div id="pdfPreview" class="card"
           style="background:#f8f9fb; border:1px solid var(--border); min-height:120px; user-select:none;-webkit-user-select:none; padding-bottom:6px;"
           oncontextmenu="return false;">
        <div class="pdf-loader" aria-label="Lädt…" role="status">
          <span class="spinner"></span>
          <span class="txt">PDF wird geladen…</span>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 style="margin-top:0;"><?=h(t('parent.portal.feedback_title', 'Rückmeldung'))?></h2>
      <p class="muted" style="margin-top:0;"><?=h(t('parent.portal.feedback_hint', 'Bitte bestätigen Sie den Empfang des Dokuments. Sie können zusätzlich eine Rückmeldung / Frage hinterlassen.'))?></p>

      <?php if (!$allowResponses): ?>
        <p class="muted"><?=h(t('parent.portal.responses_closed', 'Rückmeldungen sind derzeit nicht möglich.'))?></p>
      <?php else: ?>
        <form method="post" style="margin:0; display:flex; flex-direction:column; gap:8px;">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="send_feedback">
          <textarea name="message" rows="4" placeholder="<?=h(t('parent.portal.feedback_placeholder', 'Ihre Rückmeldung ...'))?>"></textarea>
          <div class="actions" style="margin-top:8px;">
            <a class="btn primary" type="submit" onclick="this.closest('form').submit();"><?=h(t('parent.portal.feedback_send', 'Empfang bestätigen'))?></a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canPreview): ?>
  <script type="module">
    import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
    pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

    const payload = <?= json_encode($previewPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const preview = document.getElementById('pdfPreview');

    if (preview) {
      preview.addEventListener('contextmenu', (e) => e.preventDefault());
      preview.addEventListener('dragstart', (e) => e.preventDefault());
    }

    function showError(msg){
      if (!preview) return;
      preview.innerHTML = `<div class="alert danger">${msg}</div>`;
    }

    async function ensurePdfLib(){
      if (window.PDFLib) return;
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
        s.onload = resolve;
        s.onerror = () => reject(new Error('PDF-Bibliothek konnte nicht geladen werden.'));
        document.head.appendChild(s);
      });
    }

    function renderPages(bytes){
      if (!preview) return;
      preview.innerHTML = '';
      const loadingTask = pdfjsLib.getDocument({ data: bytes });
      loadingTask.promise.then(async (doc) => {
        for (let p = 1; p <= doc.numPages; p++){
          const page = await doc.getPage(p);
          const viewport = page.getViewport({ scale: 1.6 });
          const ratio = window.devicePixelRatio || 1;

          const canvas = document.createElement('canvas');
          canvas.width = viewport.width * ratio;
          canvas.height = viewport.height * ratio;
          canvas.style.width = `100%`;
          canvas.style.display = 'block';
          canvas.style.marginBottom = '12px';
          canvas.draggable = false;
          canvas.oncontextmenu = (e) => e.preventDefault();
          preview.appendChild(canvas);

          const ctx = canvas.getContext('2d');
          const renderCtx = { canvasContext: ctx, viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined };
          await page.render(renderCtx).promise;
        }
      }).catch(e => showError(e?.message || String(e)));
    }

    async function loadTemplate(){
      const resp = await fetch(payload.template_url, { credentials:'same-origin' });
      if (!resp.ok) throw new Error('PDF-Vorlage konnte nicht geladen werden.');
      return new Uint8Array(await resp.arrayBuffer());
    }

    // ---------- Date normalization helpers (supports MMM/MMMM) ----------
    function escapeRegex(s){ return (s||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    const MONTHS_DE = [
      'januar','februar','märz','maerz','april','mai','juni','juli','august','september','oktober','november','dezember'
    ];
    const MONTHS_DE_SHORT = [
      'jan','feb','mär','mae','mrz','apr','mai','jun','jul','aug','sep','okt','nov','dez'
    ];
    const MONTHS_EN = [
      'january','february','march','april','may','june','july','august','september','october','november','december'
    ];
    const MONTHS_EN_SHORT = [
      'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'
    ];

    function monthNameToNumber(nameRaw){
      const s0 = (nameRaw ?? '').toString().trim().toLowerCase()
        .replace(/\.+$/,'')
        .replace('ä','ae').replace('ö','oe').replace('ü','ue').replace('ß','ss');

      const deFull = MONTHS_DE.map(x => x.replace('ä','ae'));
      const deShort = MONTHS_DE_SHORT.map(x => x.replace('ä','ae'));
      const enFull = MONTHS_EN;
      const enShort = MONTHS_EN_SHORT;

      let idx = deFull.indexOf(s0);
      if (idx >= 0) return idx+1;
      idx = deShort.indexOf(s0);
      if (idx >= 0) return idx+1;
      idx = enFull.indexOf(s0);
      if (idx >= 0) return idx+1;
      idx = enShort.indexOf(s0);
      if (idx >= 0) return idx+1;

      return 0;
    }

    function numberToMonthName(m, lang, style){
      const mm = Number(m);
      if (!(mm>=1 && mm<=12)) return '';
      const useDe = (lang || 'de').toLowerCase().startsWith('de');

      const fullDe = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
      const shortDe = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];

      const fullEn = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      const shortEn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

      const arr = useDe
        ? (style === 'short' ? shortDe : fullDe)
        : (style === 'short' ? shortEn : fullEn);

      return arr[mm-1];
    }

    function buildRegexForFormat(fmt){
      let f = (fmt||'').trim();
      if (!f) return null;

      f = f.replaceAll('yyyy','YYYY').replaceAll('yy','YY').replaceAll('dd','DD').replaceAll('mm','MM');

      const tokenMap = {
        'YYYY': '(\\d{4})',
        'YY': '(\\d{2})',
        'DD': '(\\d{2})',
        'D': '(\\d{1,2})',
        'MMMM': '([A-Za-zÄÖÜäöüß\\.]+)',
        'MMM': '([A-Za-zÄÖÜäöüß\\.]+)',
        'MM': '(\\d{2})',
        'M': '(\\d{1,2})'
      };
      const tokens = ['YYYY','YY','MMMM','MMM','DD','D','MM','M'];

      let re = '';
      for (let i=0; i<f.length; ){
        let matched = null;
        for (const t of tokens){
          if (f.slice(i, i+t.length) === t){ matched = t; break; }
        }
        if (matched){
          re += tokenMap[matched];
          i += matched.length;
        } else {
          re += escapeRegex(f[i]);
          i++;
        }
      }
      return new RegExp('^' + re + '$', 'i');
    }

    function matchesFormat(value, expectedFmt){
      const v = (value ?? '').toString().trim();
      const fmt = (expectedFmt ?? '').toString().trim();
      if (!v || !fmt) return false;
      const re = buildRegexForFormat(fmt);
      if (!re) return false;
      return re.test(v);
    }

    function parseFlexibleDate(raw){
      const s = (raw ?? '').toString().trim();
      if (!s) return null;

      const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/);
      if (iso){
        const y = Number(iso[1]), m = Number(iso[2]), d = Number(iso[3]);
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      const de = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/);
      if (de){
        let d = Number(de[1]), m = Number(de[2]), y = Number(de[3]);
        if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      const named = s.match(/^(\d{1,2})\.\s*([A-Za-zÄÖÜäöüß\.]+)\s+(\d{2}|\d{4})$/);
      if (named){
        let d = Number(named[1]);
        const m = monthNameToNumber(named[2]);
        let y = Number(named[3]);
        if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      const us = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/);
      if (us){
        let m = Number(us[1]), d = Number(us[2]), y = Number(us[3]);
        if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      const hy = s.match(/^(\d{1,2})-(\d{1,2})-(\d{2}|\d{4})$/);
      if (hy){
        let d = Number(hy[1]), m = Number(hy[2]), y = Number(hy[3]);
        if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      const t = Date.parse(s);
      if (!Number.isNaN(t)){
        const dt = new Date(t);
        const y = dt.getFullYear();
        const m = dt.getMonth()+1;
        const d = dt.getDate();
        if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
      }

      return null;
    }

    function pad2(n){ return String(n).padStart(2,'0'); }

    function formatDate(parts, expectedFmt){
      const fmt0 = (expectedFmt ?? '').toString().trim();
      if (!fmt0) return null;

      const fmt = fmt0
        .replaceAll('yyyy','YYYY')
        .replaceAll('yy','YY')
        .replaceAll('dd','DD')
        .replaceAll('mm','MM');

      const y = parts.y, m = parts.m, d = parts.d;
      const yy = String(y).slice(-2);
      const lang = 'de';

      return fmt
        .replaceAll('YYYY', String(y))
        .replaceAll('YY', yy)
        .replaceAll('DD', pad2(d))
        .replaceAll('D', String(d))
        .replaceAll('MMMM', numberToMonthName(m, lang, 'full'))
        .replaceAll('MMM', numberToMonthName(m, lang, 'short'))
        .replaceAll('MM', pad2(m))
        .replaceAll('M', String(m));
    }

    function normalizeDateIfNeeded(rawValue, expectedFmt){
      const raw = (rawValue ?? '').toString().trim();
      const fmt = (expectedFmt ?? '').toString().trim();
      if (!raw || !fmt) return raw;

      if (matchesFormat(raw, fmt)) return raw;

      const parsed = parseFlexibleDate(raw);
      if (!parsed) return raw;

      const out = formatDate(parsed, fmt);
      return out || raw;
    }

    async function fillPdf(){
      await ensurePdfLib();
      const tpl = await loadTemplate();

      const PDFLib = window.PDFLib;
      const { PDFDocument, PDFName, PDFBool } = PDFLib;

      const pdfDoc = await PDFDocument.load(tpl);
      const form = pdfDoc.getForm();

      const values = payload.student?.values || {};
      const fieldMeta = (payload.field_meta && typeof payload.field_meta === 'object') ? payload.field_meta : {};

      Object.entries(values).forEach(([name, val]) => {
        try {
          const field = form.getField(name);
          if (!field) return;

          const meta = fieldMeta[name] || null;
          const fieldType = (meta?.field_type || '').toString().toLowerCase();
          const expectedFmt = (meta?.date_format || '').toString().trim();

          let v = (val ?? '').toString();

          if (v && (fieldType === 'date' || expectedFmt)) {
            v = normalizeDateIfNeeded(v, expectedFmt);
          }

          if (typeof field.setText === 'function') {
            field.setText(v);
          } else if (typeof field.check === 'function') {
            const vv = v.trim().toLowerCase();
            if (['1','ja','yes','true','x'].includes(vv)) field.check();
          } else if (typeof field.select === 'function') {
            field.select(v);
          }
        } catch (e) {}
      });

      try {
        const acro = form.acroForm;
        if (acro && acro.dict && PDFName && PDFBool) {
          acro.dict.set(PDFName.of('NeedAppearances'), PDFBool.True);
        }
      } catch (e) {}

      try { form.updateFieldAppearances(); } catch(e) {}

      const bytes = await pdfDoc.save();
      renderPages(bytes);
    }

    fillPdf().catch(e => showError(e?.message || String(e)));
  </script>
  <?php endif; ?>
</body>
</html>
