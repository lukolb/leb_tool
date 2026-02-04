<?php
declare(strict_types=1);
// parent/portal.php
require __DIR__ . '/../bootstrap.php';

$pdo = db();
$token = (string)($_GET['token'] ?? '');
$alerts = [];
$errors = [];
$cfg = app_config();
$parentCfg = $cfg['parent'] ?? [];
$signaturePurpose = 'parent_export';
$signatureEnabled = (bool)($parentCfg['signature_enabled'] ?? false);
$signatureConfigured = $signatureEnabled && signature_configured();
$meetingFeedbackEnabled = (bool)($parentCfg['meeting_feedback_enabled'] ?? false);
$meetingFeedbackRequired = (bool)($parentCfg['meeting_feedback_required'] ?? false);
$meetingFeedbackAnonymous = (bool)($parentCfg['meeting_feedback_anonymous'] ?? false);

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

function parent_feedback_ack_exists(PDO $pdo, int $linkId): bool {
  $st = $pdo->prepare("SELECT 1 FROM parent_feedback WHERE link_id=? AND feedback_type='ack' LIMIT 1");
  $st->execute([$linkId]);
  return (bool)$st->fetchColumn();
}

function parent_feedback_insert_ack(PDO $pdo, int $linkId, string $lang): bool {
  if (parent_feedback_ack_exists($pdo, $linkId)) return false;
  $ins = $pdo->prepare(
    "INSERT INTO parent_feedback (link_id, feedback_type, message, language, auto_translated, created_at)\n" .
    "VALUES (?, 'ack', NULL, ?, 0, NOW())"
  );
  $ins->execute([$linkId, $lang]);
  return true;
}

function parent_meeting_feedback_exists(PDO $pdo, int $studentId): bool {
  $st = $pdo->prepare("SELECT 1 FROM parent_meeting_feedback WHERE student_id=? LIMIT 1");
  $st->execute([$studentId]);
  return (bool)$st->fetchColumn();
}

function parent_meeting_feedback_insert(PDO $pdo, array $payload): void {
  $ins = $pdo->prepare(
    "INSERT INTO parent_meeting_feedback (student_id, class_id, school_year, grade_level, link_id, q1, q2, q3, is_anonymous, message, created_at)\n" .
    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
  );
  $ins->execute([
    (int)$payload['student_id'],
    (int)$payload['class_id'],
    (string)$payload['school_year'],
    $payload['grade_level'] === null ? null : (int)$payload['grade_level'],
    (int)$payload['link_id'],
    (int)$payload['q1'],
    (int)$payload['q2'],
    (int)$payload['q3'],
    (int)$payload['is_anonymous'],
    $payload['message'],
  ]);
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
 * ✅ NEW: find class report instance (student_id IS NULL, period_label=class_report_period_label(class_id))
 */
function parent_find_class_report_instance_id(PDO $pdo, int $templateId, int $classId, string $schoolYear): ?int {
  $periodLabel = class_report_period_label($classId);
  $st = $pdo->prepare(
    "SELECT id
     FROM report_instances
     WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear, $periodLabel]);
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
    if ($lang === 'en') {
      $labelEn = trim((string)($row['label_en'] ?? ''));
      if ($labelEn !== '') $label = $labelEn;
    }
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
    if ($val === '') $val = t('parent.portal.empty');
    return [
      'label' => (string)($row['label'] ?? ''),
      'value' => $val,
      'source' => (string)($row['source'] ?? ''),
    ];
  }, array_values($map));
}

if ($token === '') {
  http_response_code(400);
  echo h(t('parent.portal.token_missing'));
  exit;
}

$st = $pdo->prepare(
  "SELECT ppl.*, s.first_name, s.last_name, c.id AS class_id, c.school_year, c.grade_level, c.label, c.name,\n" .
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
  echo h(t('parent.portal.link_not_found'));
  exit;
}

$lang = ui_lang();

$expiresAt = $link['expires_at'] ?? null;
$isExpired = false;
if ($expiresAt) {
  $expiresAtLocal = db_datetime_to_user_datetime((string)$expiresAt);
  if ($expiresAtLocal) {
    $isExpired = $expiresAtLocal < new DateTimeImmutable('now', user_timezone());
  } else {
    $isExpired = (strtotime((string)$expiresAt) < time());
  }
  if ($isExpired && ($link['status'] ?? '') !== 'expired') {
    $pdo->prepare("UPDATE parent_portal_links SET status='expired', updated_at=NOW() WHERE id=? LIMIT 1")->execute([(int)$link['id']]);
    $link['status'] = 'expired';
  }
}

$status = (string)($link['status'] ?? '');
$allowResponses = ($status === 'approved' && !$isExpired);
$meetingFeedbackRequired = $meetingFeedbackEnabled && $meetingFeedbackRequired;
$meetingFeedbackCompleted = $meetingFeedbackEnabled
  ? parent_meeting_feedback_exists($pdo, (int)($link['student_id'] ?? 0))
  : false;
$meetingFeedbackBlocking = $meetingFeedbackRequired && !$meetingFeedbackCompleted;
$canPreview = ($status === 'approved') && !$meetingFeedbackBlocking;
$hasAck = $allowResponses ? parent_feedback_ack_exists($pdo, (int)$link['id']) : false;
$meetingForm = [
  'q1' => '',
  'q2' => '',
  'q3' => '',
  'message' => '',
];

if ($canPreview) {
  apply_system_bindings($pdo, (int)$link['report_instance_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    csrf_verify();
    $action = (string)$_POST['action'];
    if (!$allowResponses) throw new RuntimeException(t('parent.portal.responses_unavailable'));

    if ($action === 'send_feedback') {
      $message = trim((string)($_POST['message'] ?? ''));
      if ($message === '') {
        parent_feedback_insert_ack($pdo, (int)$link['id'], $lang);
        $hasAck = true;
      } else {
        $ins = $pdo->prepare(
          "INSERT INTO parent_feedback (link_id, feedback_type, message, language, auto_translated, created_at)\n" .
          "VALUES (?, 'question', ?, ?, 0, NOW())"
        );
        $ins->execute([(int)$link['id'], $message, $lang]);
        if (parent_feedback_insert_ack($pdo, (int)$link['id'], $lang)) {
          $hasAck = true;
        }
      }
      $alerts[] = t('parent.portal.feedback_ok');
    }

    if ($action === 'submit_meeting_feedback') {
      if (!$meetingFeedbackEnabled) {
        throw new RuntimeException(t('parent.portal.meeting_feedback_unavailable'));
      }
      if (!$allowResponses) {
        throw new RuntimeException(t('parent.portal.responses_unavailable'));
      }
      if ($meetingFeedbackCompleted) {
        throw new RuntimeException(t('parent.portal.meeting_feedback_already'));
      }
      $meetingForm = [
        'q1' => (string)($_POST['q1'] ?? ''),
        'q2' => (string)($_POST['q2'] ?? ''),
        'q3' => (string)($_POST['q3'] ?? ''),
        'message' => trim((string)($_POST['message'] ?? '')),
      ];

      $q1 = (int)$meetingForm['q1'];
      $q2 = (int)$meetingForm['q2'];
      $q3 = (int)$meetingForm['q3'];
      $valid = [1, 2, 3, 4];
      if (!in_array($q1, $valid, true) || !in_array($q2, $valid, true) || !in_array($q3, $valid, true)) {
        throw new RuntimeException(t('parent.portal.meeting_feedback_missing'));
      }

      parent_meeting_feedback_insert($pdo, [
        'student_id' => (int)$link['student_id'],
        'class_id' => (int)($link['class_id'] ?? 0),
        'school_year' => (string)($link['school_year'] ?? ''),
        'grade_level' => $link['grade_level'] !== null ? (int)$link['grade_level'] : null,
        'link_id' => (int)$link['id'],
        'q1' => $q1,
        'q2' => $q2,
        'q3' => $q3,
        'is_anonymous' => $meetingFeedbackAnonymous ? 1 : 0,
        'message' => $meetingForm['message'],
      ]);

      $meetingFeedbackCompleted = true;
      $meetingFeedbackBlocking = $meetingFeedbackRequired && !$meetingFeedbackCompleted;
      $canPreview = ($status === 'approved') && !$meetingFeedbackBlocking;
      $meetingForm = [
        'q1' => '',
        'q2' => '',
        'q3' => '',
        'message' => '',
      ];
      $alerts[] = t('parent.portal.meeting_feedback_ok');
    }

    if ($action === 'confirm_receipt') {
      parent_feedback_insert_ack($pdo, (int)$link['id'], $lang);
      $hasAck = true;
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
    $classRiId = parent_find_class_report_instance_id($pdo, $templateId, (int)($link['class_id'] ?? 0), $reportSchoolYear);
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

  if ($signatureConfigured) {
    $sigPayload = signature_get_active_payload($pdo, (int)($link['requested_by_user_id'] ?? 0), $signaturePurpose);
    if ($sigPayload) {
      $previewPayload['teacher_signature'] = $sigPayload;
    }
  }
}

$b = brand();
$org = $b['org_name'] ?? 'LEG Tool';
$logo = $b['logo_path'] ?? '';
$primary = $b['primary'] ?? '#0b57d0';
$secondary = $b['secondary'] ?? '#111111';
$allowDownload = (bool)($parentCfg['download_enabled'] ?? false);
$showDownload = $allowDownload && $canPreview;
$downloadFilename = t('parent.portal.download_filename_prefix') . '_' .
  preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($link['last_name'] ?? '')) . '_' .
  preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($link['first_name'] ?? '')) . '.pdf';
?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t('parent.portal.title'))?></title>
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

    .meeting-feedback-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }
    .meeting-feedback-table th,
    .meeting-feedback-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      text-align: center;
      font-size: 14px;
    }
    .meeting-feedback-table th:first-child,
    .meeting-feedback-table td:first-child {
      text-align: left;
      width: 45%;
    }
    .meeting-feedback-table tr:last-child td {
      border-bottom: none;
    }
    .meeting-feedback-table .muted {
      font-size: 12px;
    }
    @media (max-width: 720px) {
      .meeting-feedback-table,
      .meeting-feedback-table thead {
        display: none;
      }
      .meeting-feedback-table tbody,
      .meeting-feedback-table tr,
      .meeting-feedback-table td {
        display: block;
        width: 100%;
      }
      .meeting-feedback-table tr {
        border-bottom: 1px solid var(--border);
      }
      .meeting-feedback-table td {
        text-align: left;
      }
      .meeting-feedback-table td[data-label]::before {
        content: attr(data-label) ': ';
        font-weight: 600;
        display: inline-block;
        min-width: 160px;
      }
    }
  </style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle"><?=h(t('parent.portal.subtitle'))?></div>
      </div>
      <div class="lang-switch" aria-label="<?=h(t('student.lang_switch_aria'))?>">
        <a class="lang <?= $lang==='de' ? 'active' : '' ?>" data-lang="de" href="<?=h(url_with_lang('de'))?>" title="<?=h(t('student.lang_de'))?>">🇩🇪</a>
        <a class="lang <?= $lang==='en' ? 'active' : '' ?>" data-lang="en" href="<?=h(url_with_lang('en'))?>" title="<?=h(t('student.lang_en'))?>">🇬🇧</a>
      </div>
    </div>
  </div>

  <div class="container" style="max-width:960px;">
    <div class="card">
        <?php if ($showDownload): ?>
        <div class="row-actions" style="float: right;">
          <button class="btn primary" type="button" id="downloadPdfBtn">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.1.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path fill="#fff" d="M352 96C352 78.3 337.7 64 320 64C302.3 64 288 78.3 288 96L288 306.7L246.6 265.3C234.1 252.8 213.8 252.8 201.3 265.3C188.8 277.8 188.8 298.1 201.3 310.6L297.3 406.6C309.8 419.1 330.1 419.1 342.6 406.6L438.6 310.6C451.1 298.1 451.1 277.8 438.6 265.3C426.1 252.8 405.8 252.8 393.3 265.3L352 306.7L352 96zM160 384C124.7 384 96 412.7 96 448L96 480C96 515.3 124.7 544 160 544L480 544C515.3 544 544 515.3 544 480L544 448C544 412.7 515.3 384 480 384L433.1 384L376.5 440.6C345.3 471.8 294.6 471.8 263.4 440.6L206.9 384L160 384zM464 440C477.3 440 488 450.7 488 464C488 477.3 477.3 488 464 488C450.7 488 440 477.3 440 464C440 450.7 450.7 440 464 440z"/></svg> <span id="downloadPdfBtnText"><?=h(t('parent.portal.download'))?></span>
          </button>
        </div>
        <?php endif; ?>
      <h1><?=h(t('parent.portal.heading'))?></h1>
      <?php if ($hasAck): ?>
        <div class="pill green" style="margin-top:8px; width:fit-content;">
          <?=h(t('parent.portal.feedback_ack_sent'))?>
        </div>
      <?php endif; ?>
      <p class="muted" style="max-width:820px;">
        <?=h(t('parent.portal.readonly_hint'))?>
      </p>
      <div class="pill"><?=h((string)$link['first_name'] . ' ' . (string)$link['last_name'])?></div>
      <div class="muted" style="margin-top:4px;">
        <?=h(t('parent.portal.class'))?>: <?=h((string)$link['school_year'])?> · <?=h(parent_portal_class_display($link))?>
      </div>
      <div class="muted" style="margin-top:12px;">
        <?=h(t('parent.portal.valid_until'))?>: <?= $expiresAt ? render_local_datetime($expiresAt, 'd.m.Y H:i') : h(t('parent.portal.no_expiry')) ?>
      </div>
      <?php if ($status === 'requested'): ?>
        <div class="alert warn" style="margin-top:10px;"><?=h(t('parent.portal.waiting'))?></div>
      <?php elseif ($status === 'revoked'): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.revoked'))?></div>
      <?php elseif ($isExpired): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.expired'))?></div>
      <?php endif; ?>
    </div>

    <div id="parentAlerts">
      <?php if ($errors): ?>
        <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($alerts): ?>
        <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <?php if ($meetingFeedbackEnabled): ?>
      <div class="card" id="meetingFeedbackCard">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
          <div>
            <h2 style="margin-top:0;"><?=h(t('parent.portal.meeting_feedback_title'))?></h2>
            <div class="muted"><?=h(t('parent.portal.meeting_feedback_subtitle'))?></div>
          </div>
          <?php if (!$meetingFeedbackCompleted && !$meetingFeedbackRequired && $allowResponses): ?>
            <span class="muted"><?=h(t('parent.portal.meeting_feedback_optional_hint'))?></span>
          <?php endif; ?>
        </div>
        <?php if ($meetingFeedbackCompleted): ?>
          <div class="pill green" style="margin-top:8px; width:fit-content;">
            <?=h(t('parent.portal.meeting_feedback_thanks'))?>
          </div>
        <?php elseif (!$allowResponses): ?>
          <p class="muted" style="margin-top:8px;"><?=h(t('parent.portal.meeting_feedback_closed'))?></p>
        <?php else: ?>
          <?php if ($meetingFeedbackRequired): ?>
            <div class="alert warn" style="margin-top:10px;"><?=h(t('parent.portal.meeting_feedback_required_hint'))?></div>
          <?php endif; ?>
          <?php if ($meetingFeedbackAnonymous): ?>
            <p class="muted" style="margin-top:6px;"><?=h(t('parent.portal.meeting_feedback_anonymous_hint'))?></p>
          <?php endif; ?>
          <form method="post" id="meetingFeedbackForm" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="submit_meeting_feedback">
            <table class="meeting-feedback-table">
              <thead>
                <tr>
                  <th><?=h(t('parent.portal.meeting_feedback_title'))?></th>
                  <?php foreach ([1,2,3,4] as $opt): ?>
                    <?php
                      $label = t('parent.portal.meeting_feedback_option_' . $opt);
                      $parts = array_map('trim', explode('/', $label, 2));
                      $primary = $parts[0] ?? $label;
                      $secondary = $parts[1] ?? '';
                    ?>
                    <th>
                      <div><?=h($primary)?></div>
                      <?php if ($secondary !== ''): ?>
                        <div class="muted"><?=h($secondary)?></div>
                      <?php endif; ?>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <?php
                      $q1 = t('parent.portal.meeting_feedback_q1');
                      $q1En = t('parent.portal.meeting_feedback_q1_en');
                    ?>
                    <strong>1. <?=h($q1)?></strong>
                    <?php if ($q1En !== '' && $q1En !== $q1): ?>
                      <div class="muted"><?=h($q1En)?></div>
                    <?php endif; ?>
                  </td>
                  <?php foreach ([1,2,3,4] as $opt): ?>
                    <td data-label="<?=h(t('parent.portal.meeting_feedback_option_' . $opt))?>">
                      <input type="radio" name="q1" value="<?= $opt ?>" <?= ($meetingForm['q1'] ?? '') === (string)$opt ? 'checked' : '' ?> required>
                    </td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td>
                    <?php
                      $q2 = t('parent.portal.meeting_feedback_q2');
                      $q2En = t('parent.portal.meeting_feedback_q2_en');
                    ?>
                    <strong>2. <?=h($q2)?></strong>
                    <?php if ($q2En !== '' && $q2En !== $q2): ?>
                      <div class="muted"><?=h($q2En)?></div>
                    <?php endif; ?>
                  </td>
                  <?php foreach ([1,2,3,4] as $opt): ?>
                    <td data-label="<?=h(t('parent.portal.meeting_feedback_option_' . $opt))?>">
                      <input type="radio" name="q2" value="<?= $opt ?>" <?= ($meetingForm['q2'] ?? '') === (string)$opt ? 'checked' : '' ?> required>
                    </td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td>
                    <?php
                      $q3 = t('parent.portal.meeting_feedback_q3');
                      $q3En = t('parent.portal.meeting_feedback_q3_en');
                    ?>
                    <strong>3. <?=h($q3)?></strong>
                    <?php if ($q3En !== '' && $q3En !== $q3): ?>
                      <div class="muted"><?=h($q3En)?></div>
                    <?php endif; ?>
                  </td>
                  <?php foreach ([1,2,3,4] as $opt): ?>
                    <td data-label="<?=h(t('parent.portal.meeting_feedback_option_' . $opt))?>">
                      <input type="radio" name="q3" value="<?= $opt ?>" <?= ($meetingForm['q3'] ?? '') === (string)$opt ? 'checked' : '' ?> required>
                    </td>
                  <?php endforeach; ?>
                </tr>
              </tbody>
            </table>
            <label style="margin-top:12px; display:block;">
              <?php
                $messageLabel = t('parent.portal.meeting_feedback_message_label');
                $messageLabelEn = t('parent.portal.meeting_feedback_message_label_en');
              ?>
              <strong><?=h($messageLabel)?></strong>
              <?php if ($messageLabelEn !== '' && $messageLabelEn !== $messageLabel): ?>
                <div class="muted"><?=h($messageLabelEn)?></div>
              <?php endif; ?>
              <textarea name="message" rows="4" style="margin-top:6px; width:100%;"><?=h((string)$meetingForm['message'])?></textarea>
            </label>
            <div class="actions" style="margin-top:12px;">
              <button class="btn primary" type="submit"><?=h(t('parent.portal.meeting_feedback_submit'))?></button>
            </div>
            <div class="muted" style="margin-top:8px;"><?=h(t('parent.portal.meeting_feedback_thanks_note'))?></div>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$canPreview): ?>
      <div class="alert warn" style="margin-top:10px;">
        <?=h($meetingFeedbackBlocking ? t('parent.portal.preview_blocked_feedback') : t('parent.portal.preview_blocked'))?>
      </div>
    <?php else: ?>
      <div id="pdfPreview" class="card"
           style="background:#f8f9fb; border:1px solid var(--border); min-height:120px; user-select:none;-webkit-user-select:none; padding-bottom:6px;"
           oncontextmenu="return false;">
        <div class="pdf-loader" aria-label="<?=h(t('parent.portal.loader_aria'))?>" role="status">
          <span class="spinner"></span>
          <span class="txt"><?=h(t('parent.portal.loader_text'))?></span>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 style="margin-top:0;"><?=h(t('parent.portal.feedback_title'))?></h2>
      <p class="muted" style="margin-top:0;"><?=h(t('parent.portal.feedback_hint'))?></p>

      <?php if (!$allowResponses): ?>
        <p class="muted"><?=h(t('parent.portal.responses_closed'))?></p>
      <?php else: ?>
        <form method="post" id="parentFeedbackForm" style="margin:0; display:flex; flex-direction:column; gap:8px;">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="send_feedback">
          <textarea name="message" rows="4" placeholder="<?=h(t('parent.portal.feedback_placeholder'))?>"></textarea>
          <div class="actions" style="margin-top:8px;">
            <button class="btn primary" type="submit">
              <?=h($hasAck ? t('parent.portal.feedback_send_msg') : t('parent.portal.feedback_send'))?>
            </button>
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
    const FONT_MANIFEST_URL = <?= json_encode(url('shared/font_manifest.php')) ?>;
    const FONTKIT_URL = 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js';
    const preview = document.getElementById('pdfPreview');
    const downloadBtn = document.getElementById('downloadPdfBtn');
    const downloadBtnText = document.getElementById('downloadPdfBtnText');
    const downloadName = <?= json_encode($downloadFilename, JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    let receiptConfirmed = <?= $hasAck ? 'true' : 'false' ?>;
    const feedbackForm = document.getElementById('parentFeedbackForm');
    const alertsWrap = document.getElementById('parentAlerts');
    const feedbackButton = feedbackForm?.querySelector('.btn.primary');
    const meetingForm = document.getElementById('meetingFeedbackForm');

    if (preview) {
      preview.addEventListener('contextmenu', (e) => e.preventDefault());
      preview.addEventListener('dragstart', (e) => e.preventDefault());
    }

    function showError(msg){
      if (!preview) return;
      preview.innerHTML = `<div class="alert danger">${msg}</div>`;
    }

    function pushAlert(type, text){
      if (!alertsWrap) return;
      const alert = document.createElement('div');
      alert.className = `alert ${type}`;
      alert.textContent = text;
      alertsWrap.innerHTML = '';
      alertsWrap.appendChild(alert);
      alertsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function ensureAckPill(){
      if (receiptConfirmed) return;
      receiptConfirmed = true;
      const headerCard = document.querySelector('.container .card');
      if (!headerCard || headerCard.querySelector('.pill.green')) return;
      const pill = document.createElement('div');
      pill.className = 'pill green';
      pill.style.marginTop = '8px';
      pill.style.width = 'fit-content';
      pill.textContent = <?= json_encode(t('parent.portal.feedback_ack_sent')) ?>;
      const heading = headerCard.querySelector('h1');
      heading?.insertAdjacentElement('afterend', pill);
      if (feedbackButton) {
        feedbackButton.textContent = <?= json_encode(t('parent.portal.feedback_send_msg')) ?>;
      }
    }

    async function confirmReceipt(){
      if (receiptConfirmed) return;
      receiptConfirmed = true;
      if (!csrfToken) return;
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('action', 'confirm_receipt');
      try {
        const resp = await fetch(window.location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          keepalive: true,
        });
        if (!resp.ok) receiptConfirmed = false;
      } catch (e) {
        receiptConfirmed = false;
      }
    }

    if (feedbackForm) {
      feedbackForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const formData = new FormData(feedbackForm);
        const message = String(formData.get('message') || '').trim();
        try {
          const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
          });
          if (!resp.ok) throw new Error('request_failed');
          pushAlert('success', <?= json_encode(t('parent.portal.feedback_ok')) ?>);
          ensureAckPill();
          feedbackForm.reset();
        } catch (e) {
          pushAlert('danger', <?= json_encode(t('parent.portal.feedback_send_error')) ?>);
        }
      });
    }


    async function ensurePdfLib(){
      if (window.PDFLib) return;
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
        s.onload = resolve;
        s.onerror = () => reject(new Error(<?= json_encode(t('parent.portal.pdf_lib_load_error')) ?>));
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
      if (!resp.ok) throw new Error(<?= json_encode(t('parent.portal.pdf_template_load_error')) ?>);
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
      const lang = <?= json_encode($lang) ?>;

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

    function pdfNameToString(name){
      if (!name) return '';
      if (typeof name === 'string') return name.replace(/^\//, '');
      if (typeof name?.decodeText === 'function') return name.decodeText().replace(/^\//, '');
      if (typeof name?.asString === 'function') return name.asString().replace(/^\//, '');
      if (typeof name?.key === 'string') return name.key.replace(/^\//, '');
      return String(name).replace(/^\//, '');
    }

    let __fontManifest = null;
    let __embeddedFonts = new Map();

    function normalizeFontName(raw){
      if (!raw) return '';
      let name = String(raw).trim();
      name = name.replace(/^\//, '');
      name = name.replace(/^[A-Z]{6}\+/, '');
      name = name.replace(/\s+/g, ' ');
      name = name.toLowerCase().trim();
      name = name.replace(/[^a-z0-9._-]+/g, '_').replace(/^[_\-.]+|[_\-.]+$/g, '');
      return name;
    }

    function expandFontKeys(base){
      if (!base) return [];
      const keys = new Set([base]);
      keys.add(base.replace(/-/g, '_'));
      keys.add(base.replace(/_/g, '-'));
      return Array.from(keys);
    }

    function pdfStringToText(val){
      if (!val) return '';
      if (typeof val.decodeText === 'function') return val.decodeText();
      if (typeof val.asString === 'function') return val.asString();
      if (typeof val.value === 'string') return val.value;
      return String(val);
    }

    function parseDaFontKey(daText){
      if (!daText) return '';
      const m = /\/([^\s]+)\s+[\d.]+\s+Tf/.exec(daText);
      return m ? m[1] : '';
    }

    function parseDaFontSize(daText){
      if (!daText) return 0;
      const m = /\/[^\s]+\s+([\d.]+)\s+Tf/.exec(daText);
      return m ? Number(m[1]) : 0;
    }

    function applyLineHeightToDa(daText, lineHeight){
      if (!daText) return daText;
      const da = String(daText);
      const cleaned = da.replace(/[\d.]+\s+TL\b/g, '').trim();
      return `${cleaned} ${lineHeight} TL`.trim();
    }

    function applyDaToField(field, PDFName, PDFString, da){
      if (!da || !PDFName || !PDFString || !field?.acroField?.dict) return;
      field.acroField.dict.set(PDFName.of('DA'), PDFString.of(da));
      if (typeof field.acroField.setDefaultAppearance === 'function') {
        field.acroField.setDefaultAppearance(da);
      }
      const widgets = field?.acroField?.getWidgets?.() || [];
      for (const w of widgets) {
        w?.dict?.set?.(PDFName.of('DA'), PDFString.of(da));
      }
    }

    function getFieldDefaultAppearance(field, PDFName){
      try {
        const da = field?.acroField?.dict?.lookup?.(PDFName.of('DA'));
        if (da) return pdfStringToText(da);
      } catch (e) {}
      try {
        const widgets = field?.acroField?.getWidgets?.() || [];
        for (const w of widgets) {
          const da = w?.dict?.lookup?.(PDFName.of('DA'));
          if (da) return pdfStringToText(da);
        }
      } catch (e) {}
      return '';
    }

    function resolveBaseFontName(field, fontKey, PDFName, form){
      if (!fontKey) return '';
      try {
        const dr = field?.acroField?.dict?.lookup?.(PDFName.of('DR'))
          || form?.acroForm?.dict?.lookup?.(PDFName.of('DR'));
        const fonts = dr?.lookup?.(PDFName.of('Font'));
        const font = fonts?.lookup?.(PDFName.of(fontKey));
        const base = font?.lookup?.(PDFName.of('BaseFont')) || font?.dict?.lookup?.(PDFName.of('BaseFont'));
        if (base) return pdfNameToString(base);
      } catch (e) {}
      return fontKey;
    }

    async function loadFontManifest(){
      if (__fontManifest) return __fontManifest;
      const resp = await fetch(FONT_MANIFEST_URL, { credentials: 'same-origin' });
      if (!resp.ok) {
        __fontManifest = new Map();
        return __fontManifest;
      }
      const data = await resp.json();
      const map = new Map();
      (data.fonts || []).forEach((f) => {
        const key = normalizeFontName(f.name || f.key || '');
        if (!key) return;
        expandFontKeys(key).forEach((k) => map.set(k, f));
      });
      __fontManifest = map;
      return map;
    }

    async function ensureFontkit(){
      if (window.fontkit || window.PDFLib?.fontkit) return window.fontkit;
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = FONTKIT_URL;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('fontkit load failed'));
        document.head.appendChild(s);
      });
      if (window.PDFLib?.registerFontkit && window.fontkit) {
        try { window.PDFLib.registerFontkit(window.fontkit); } catch (e) {}
      }
      return window.fontkit;
    }

    function standardFontNameMap(PDFLib){
      if (!PDFLib?.StandardFonts) return {};
      return {
        'helvetica': PDFLib.StandardFonts.Helvetica,
        'helvetica-bold': PDFLib.StandardFonts.HelveticaBold,
        'helvetica-oblique': PDFLib.StandardFonts.HelveticaOblique,
        'helvetica-boldoblique': PDFLib.StandardFonts.HelveticaBoldOblique,
        'times-roman': PDFLib.StandardFonts.TimesRoman,
        'times-bold': PDFLib.StandardFonts.TimesBold,
        'times-italic': PDFLib.StandardFonts.TimesItalic,
        'times-bolditalic': PDFLib.StandardFonts.TimesBoldItalic,
        'courier': PDFLib.StandardFonts.Courier,
        'courier-bold': PDFLib.StandardFonts.CourierBold,
        'courier-oblique': PDFLib.StandardFonts.CourierOblique,
        'courier-boldoblique': PDFLib.StandardFonts.CourierBoldOblique,
        'symbol': PDFLib.StandardFonts.Symbol,
        'zapfdingbats': PDFLib.StandardFonts.ZapfDingbats,
      };
    }

    async function getEmbeddedFont(pdfDoc, fontName, manifest){
      const baseKey = normalizeFontName(fontName);
      if (!baseKey) return null;
      const lookupKeys = expandFontKeys(baseKey);
      for (const key of lookupKeys) {
        if (__embeddedFonts.has(key)) return __embeddedFonts.get(key);
      }

      const PDFLib = window.PDFLib;
      const standardMap = standardFontNameMap(PDFLib);
      for (const key of lookupKeys) {
        if (standardMap[key] && typeof pdfDoc.embedFont === 'function') {
          const font = await pdfDoc.embedFont(standardMap[key]);
          __embeddedFonts.set(key, font);
          return font;
        }
      }

      for (const key of lookupKeys) {
        const custom = manifest.get(key);
        if (custom?.url && typeof pdfDoc.embedFont === 'function') {
          await ensureFontkit();
          try {
            if (typeof pdfDoc.registerFontkit === 'function' && window.fontkit) {
              pdfDoc.registerFontkit(window.fontkit);
            }
          } catch (e) {}
          const res = await fetch(custom.url, { credentials: 'same-origin' });
          if (!res.ok) return null;
          const bytes = await res.arrayBuffer();
          const font = await pdfDoc.embedFont(bytes);
          __embeddedFonts.set(key, font);
          return font;
        }
      }
      return null;
    }

    async function updateFieldAppearancesWithFonts(form, pdfDoc, fallbackFont){
      const PDFLib = window.PDFLib;
      const { PDFName, PDFTextField, PDFDropdown, PDFOptionList, PDFString } = PDFLib;
      const fontManifest = await loadFontManifest();

      const fields = form.getFields();
      for (const field of fields) {
        const isText = PDFTextField && field instanceof PDFTextField;
        const isDropdown = PDFDropdown && field instanceof PDFDropdown;
        const isOptionList = PDFOptionList && field instanceof PDFOptionList;
        if (!isText && !isDropdown && !isOptionList) continue;

        const da = getFieldDefaultAppearance(field, PDFName);
        const fontKey = parseDaFontKey(da);
        const base = resolveBaseFontName(field, fontKey, PDFName, form);
        let font = null;
        if (base) {
          font = await getEmbeddedFont(pdfDoc, base, fontManifest);
        }
        if (!font && fontKey) {
          font = await getEmbeddedFont(pdfDoc, fontKey, fontManifest);
        }
        if (!font) font = fallbackFont;

        try {
          if (PDFTextField && field instanceof PDFTextField) {
            const fontSize = parseDaFontSize(da) || Number(field.getFontSize?.() || 0) || 12;
            if (font && typeof font.heightAtSize === 'function') {
              const fontHeight = font.heightAtSize(fontSize);
              const desired = Math.max(fontSize * 1.1, fontHeight + fontSize * 0.1);
              const nextDa = applyLineHeightToDa(da, desired);
              applyDaToField(field, PDFName, PDFString, nextDa);
            }
            if (typeof field.setFontSize === 'function') {
              field.setFontSize(fontSize);
            }
          }
          if (font && typeof field.updateAppearances === 'function') {
            field.updateAppearances(font);
          } else if (typeof field.updateAppearances === 'function') {
            field.updateAppearances();
          }
        } catch (e) {}
      }
    }

    function pdfArrayToNumbers(arr, PDFArray, PDFNumber){
      if (!arr) return null;
      const isPdfArray = PDFArray && arr instanceof PDFArray;
      const size = isPdfArray && typeof arr.size === 'function' ? arr.size() : null;
      const out = [];
      if (size !== null) {
        for (let i = 0; i < size; i++) {
          const obj = arr.get(i);
          if (PDFNumber && obj instanceof PDFNumber) out.push(obj.asNumber());
          else if (typeof obj?.asNumber === 'function') out.push(obj.asNumber());
          else {
            const n = Number(obj);
            if (Number.isFinite(n)) out.push(n);
          }
        }
        return out.length ? out : null;
      }
      if (Array.isArray(arr)) {
        for (const obj of arr) {
          const n = Number(obj);
          if (Number.isFinite(n)) out.push(n);
        }
        return out.length ? out : null;
      }
      return null;
    }

    function parseDaColor(da){
      const s = (da ?? '').toString();
      if (!s) return null;
      const rg = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+RG\b/);
      if (rg) return { model: 'rgb', values: rg.slice(1, 4).map(Number) };
      const rgFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+rg\b/);
      if (rgFill) return { model: 'rgb', values: rgFill.slice(1, 4).map(Number) };
      const g = s.match(/([\d.]+)\s+G\b/);
      if (g) return { model: 'gray', values: [Number(g[1])] };
      const gFill = s.match(/([\d.]+)\s+g\b/);
      if (gFill) return { model: 'gray', values: [Number(gFill[1])] };
      const k = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+K\b/);
      if (k) return { model: 'cmyk', values: k.slice(1, 5).map(Number) };
      const kFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+k\b/);
      if (kFill) return { model: 'cmyk', values: kFill.slice(1, 5).map(Number) };
      return null;
    }

    function colorOperators(color){
      if (!color || !Array.isArray(color.values)) return '';
      const vals = color.values.map(v => (Number.isFinite(v) ? String(v) : '0'));
      if (color.model === 'rgb' && vals.length >= 3) return `${vals[0]} ${vals[1]} ${vals[2]} RG`;
      if (color.model === 'gray' && vals.length >= 1) return `${vals[0]} G`;
      if (color.model === 'cmyk' && vals.length >= 4) return `${vals[0]} ${vals[1]} ${vals[2]} ${vals[3]} K`;
      return '';
    }

    function getWidgetBorderColor(widget, radioField, PDFArray, PDFNumber, PDFName){
      try {
        const mk = widget?.dict?.lookup?.(PDFName.of('MK'));
        if (mk && mk.lookup) {
          const bc = mk.lookup(PDFName.of('BC'));
          const nums = pdfArrayToNumbers(bc, PDFArray, PDFNumber);
          if (nums && nums.length) {
            if (nums.length === 1) return { model: 'gray', values: nums };
            if (nums.length === 3) return { model: 'rgb', values: nums };
            if (nums.length === 4) return { model: 'cmyk', values: nums };
          }
        }
      } catch (e) {}
      try {
        const da = widget?.dict?.lookup?.(PDFName.of('DA'));
        if (da) {
          const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
          if (color) return color;
        }
      } catch (e) {}
      try {
        const da = radioField?.acroField?.dict?.lookup?.(PDFName.of('DA'));
        if (da) {
          const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
          if (color) return color;
        }
      } catch (e) {}
      return null;
    }

    function buildCrossAppearanceStream(pdfDoc, rect, color){
      const { context } = pdfDoc;
      const w = Math.max(1, rect.width || 1);
      const h = Math.max(1, rect.height || 1);
      const minDim = Math.min(w, h);
      const inset = minDim * 0.18;
      const lw = Math.min(2.5, Math.max(0.8, minDim * 0.1));
      const x1 = inset;
      const y1 = inset;
      const x2 = w - inset;
      const y2 = h - inset;
      const colorOp = colorOperators(color);
      const content = [
        'q',
        colorOp ? `${colorOp}` : '',
        `${lw} w`,
        `${x1} ${y1} m ${x2} ${y2} l`,
        `${x1} ${y2} m ${x2} ${y1} l`,
        'S',
        'Q',
      ].filter(Boolean).join('\n');
      const stream = context.flateStream(content, {
        Type: 'XObject',
        Subtype: 'Form',
        BBox: [0, 0, w, h],
        Resources: {},
      });
      return context.register(stream);
    }

    function buildOffAppearanceStream(pdfDoc, rect){
      const { context } = pdfDoc;
      const w = Math.max(1, rect.width || 1);
      const h = Math.max(1, rect.height || 1);
      const stream = context.flateStream('', {
        Type: 'XObject',
        Subtype: 'Form',
        BBox: [0, 0, w, h],
        Resources: {},
      });
      return context.register(stream);
    }

    function getWidgetOnName(widget, PDFName, PDFDict){
      try {
        const ap = widget?.dict?.lookup?.(PDFName.of('AP'));
        if (ap && (!PDFDict || ap instanceof PDFDict) && ap.lookup) {
          const n = ap.lookup(PDFName.of('N'));
          if (n && (!PDFDict || n instanceof PDFDict) && typeof n.keys === 'function') {
            const keys = n.keys();
            for (const k of keys) {
              const name = pdfNameToString(k);
              if (name && name.toLowerCase() !== 'off') return name;
            }
          }
        }
      } catch (e) {}
      return '';
    }

    function getRadioGroupValue(radioField, PDFName){
      try {
        const selected = radioField?.getSelected?.();
        if (selected) return pdfNameToString(selected);
      } catch (e) {}
      try {
        const v = radioField?.acroField?.dict?.lookup?.(PDFName.of('V'));
        if (v) return pdfNameToString(v);
      } catch (e) {}
      return '';
    }

    function getRadioGroupOptions(radioField, PDFName){
      try {
        const opts = radioField?.getOptions?.();
        if (Array.isArray(opts) && opts.length) return opts.map(o => pdfNameToString(o));
      } catch (e) {}
      try {
        const opt = radioField?.acroField?.dict?.lookup?.(PDFName.of('Opt'));
        const arr = opt?.asArray?.() || opt;
        if (Array.isArray(arr)) {
          return arr.map(o => pdfNameToString(o)).filter(Boolean);
        }
      } catch (e) {}
      return [];
    }

    function getWidgetAppearanceState(widget, PDFName){
      try {
        const as = widget?.dict?.lookup?.(PDFName.of('AS'));
        const name = pdfNameToString(as);
        return name && name.toLowerCase() !== 'off' ? name : '';
      } catch (e) {}
      return '';
    }

    function applyRadioCrossAppearances(pdfDoc, form, { debug } = {}){
      const PDFLib = window.PDFLib;
      const { PDFName, PDFDict, PDFArray, PDFNumber } = PDFLib;
      const fields = form.getFields();
      let debugCount = 0;

      for (const field of fields) {
        if (!(field instanceof PDFLib.PDFRadioGroup)) continue;

        let selectedValue = getRadioGroupValue(field, PDFName);
        const widgets = field?.acroField?.getWidgets?.() || [];
        const options = getRadioGroupOptions(field, PDFName);
        if (!selectedValue) {
          for (const widget of widgets) {
            const widgetSelected = getWidgetAppearanceState(widget, PDFName);
            if (widgetSelected) {
              selectedValue = widgetSelected;
              break;
            }
          }
        }
        for (let i = 0; i < widgets.length; i++) {
          const widget = widgets[i];
          const rect = widget.getRectangle();
          let onName = getWidgetOnName(widget, PDFName, PDFDict);
          if (!onName) {
            if (options[i]) onName = options[i];
          }
          if (!onName && selectedValue) onName = selectedValue;
          if (!onName) continue;

          const normalizedOn = pdfNameToString(onName);
          const isSelected = selectedValue && pdfNameToString(selectedValue).toLowerCase() === normalizedOn.toLowerCase();

          const color = getWidgetBorderColor(widget, field, PDFArray, PDFNumber, PDFName);
          const onRef = buildCrossAppearanceStream(pdfDoc, rect, color);
          const offRef = buildOffAppearanceStream(pdfDoc, rect);

          const apN = pdfDoc.context.obj({
            Off: offRef,
            [normalizedOn]: onRef,
          });
          const apDict = pdfDoc.context.obj({ N: apN });
          widget.dict.set(PDFName.of('AP'), apDict);
          widget.dict.set(PDFName.of('AS'), PDFName.of(isSelected ? normalizedOn : 'Off'));
        }

        if (selectedValue) {
          try {
            field.acroField?.setValue?.(PDFName.of(pdfNameToString(selectedValue)));
            field.acroField?.dict?.set?.(PDFName.of('V'), PDFName.of(pdfNameToString(selectedValue)));
            field.acroField?.dict?.set?.(PDFName.of('DV'), PDFName.of(pdfNameToString(selectedValue)));
          } catch (e) {}
        }

        if (debug && debugCount < 3) {
          debugCount++;
          const onNames = widgets.map(w => getWidgetOnName(w, PDFName, PDFDict)).filter(Boolean);
          console.log('[PARENT PDF] Radio appearance', {
            field: field.getName?.() || '(unknown)',
            selectedValue,
            onNames,
            widgets: widgets.length,
          });
        }
      }
    }

    function collectSignatureFields(fieldMeta){
      if (!fieldMeta || typeof fieldMeta !== 'object') return [];
      return Object.entries(fieldMeta)
        .filter(([, meta]) => (meta?.field_type || '').toString().toLowerCase() === 'signature')
        .map(([name]) => name);
    }

    function signatureBounds(strokes, version, ratio){
      let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
      let has = false;
      for (const stroke of strokes) {
        if (!Array.isArray(stroke)) continue;
        for (const pt of stroke) {
          if (!pt || typeof pt.x !== 'number' || typeof pt.y !== 'number') continue;
          const x = pt.x;
          const y = version >= 2 ? ((1 / ratio) - pt.y) : (1 - pt.y);
          minX = Math.min(minX, x);
          maxX = Math.max(maxX, x);
          minY = Math.min(minY, y);
          maxY = Math.max(maxY, y);
          has = true;
        }
      }
      if (!has) return null;
      if (version >= 2 && ratio && ratio > 0) {
        minY = Math.max(0, minY);
        maxY = Math.min(1 / ratio, maxY);
      } else {
        minY = Math.max(0, minY);
        maxY = Math.min(1, maxY);
      }
      minX = Math.max(0, minX);
      maxX = Math.min(1, maxX);
      return { minX, maxX, minY, maxY };
    }

    function drawSignatureOverlay(pdfDoc, form, signature, fieldMeta){
      if (!signature || !Array.isArray(signature.strokes) || !signature.strokes.length) return;
      const fields = collectSignatureFields(fieldMeta);
      if (!fields.length) return;
      const ratio = Number.isFinite(signature?.ratio) && signature.ratio > 0 ? signature.ratio : null;
      if (!ratio) return;
      const version = Number.isFinite(signature?.v) ? signature.v : (Number.isFinite(signature?.version) ? signature.version : 1);
      const bounds = signatureBounds(signature.strokes, version, ratio);
      if (!bounds) return;

      const PDFLib = window.PDFLib;
      const { rgb } = PDFLib;
      const color = rgb(0.06, 0.18, 0.45);
      const pages = pdfDoc.getPages();

      const boundsW = Math.max(0.0001, bounds.maxX - bounds.minX);
      const boundsH = Math.max(0.0001, bounds.maxY - bounds.minY);
      const boundsRatio = boundsW / boundsH;
      const boxHeightRatio = boundsRatio * ratio;

      for (const name of fields) {
        let field;
        try { field = form.getField(name); } catch (e) { field = null; }
        if (!field) continue;
        const widgets = field?.acroField?.getWidgets?.() || [];
        for (const widget of widgets) {
          const rect = widget.getRectangle();
          if (!rect || !rect.width || !rect.height) continue;
          const page = widget.getPage?.() || pages[0];
          if (!page) continue;

          const margin = 2;

            // Constraints:
            const maxW = rect.width;         // max so breit wie Textfeld
            let maxH = rect.height * 2;      // max doppelt so hoch wie Textfeld

            // Position: über dem Textfeld
            let boxY = rect.y + rect.height + margin;

            // Wenn Seitenrand oben knapp ist: Höhe proportional begrenzen (statt verzerren/verschieben)
            const pageHeight = page.getHeight?.() || 0;
            if (pageHeight) {
              const availableH = Math.max(0, (pageHeight - 4) - boxY);
              maxH = Math.min(maxH, availableH);
            }

            if (maxW <= 0 || maxH <= 0) continue;

            // Proportionaler Fit-in-Box Scale (W + H gleichzeitig beachten)
            let scale = Math.min(maxW / boundsW, maxH / boundsH);
            scale = Math.max(0.0001, scale);

            const drawW = boundsW * scale;
            const drawH = boundsH * scale;

            // Horizontal mittig über dem Feld (Anforderung)
            const offsetX = rect.x + (rect.width - drawW) / 2;

            const offsetY = boxY;

            // Linienbreite wie bisher
            const lineWidth = Math.max(0.9, rect.height * 0.08);

          for (const stroke of signature.strokes) {
            if (!Array.isArray(stroke) || stroke.length < 2) continue;
            for (let i = 1; i < stroke.length; i++) {
              const prev = stroke[i - 1];
              const curr = stroke[i];
              if (!prev || !curr) continue;
              const prevY = version >= 2 ? ((1 / ratio) - prev.y) : (1 - prev.y);
              const currY = version >= 2 ? ((1 / ratio) - curr.y) : (1 - curr.y);
              const x0 = offsetX + (prev.x - bounds.minX) * scale;
              const y0 = offsetY + (prevY - bounds.minY) * scale;
              const x1 = offsetX + (curr.x - bounds.minX) * scale;
              const y1 = offsetY + (currY - bounds.minY) * scale;
              page.drawLine({
                start: { x: x0, y: y0 },
                end: { x: x1, y: y1 },
                thickness: lineWidth,
                color,
              });
            }
          }
        }
      }
    }

    async function buildPdfBytes({ flatten } = { flatten: false }){
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

      let defaultFont = null;
      try {
        if (typeof form.getDefaultFont === 'function') {
          defaultFont = form.getDefaultFont();
        }
        if (!defaultFont && PDFLib?.StandardFonts && typeof pdfDoc.embedFont === 'function') {
          defaultFont = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);
        }
      } catch (e) {}

      try {
        await updateFieldAppearancesWithFonts(form, pdfDoc, defaultFont || undefined);
      } catch (e) {}

      try {
        applyRadioCrossAppearances(pdfDoc, form, { debug: false });
      } catch (e) {}

      try {
        drawSignatureOverlay(pdfDoc, form, payload.teacher_signature || null, fieldMeta);
      } catch (e) {}

      try {
        const acro = form.acroForm;
        if (acro && acro.dict && PDFName) {
          const key = PDFName.of('NeedAppearances');
          try { acro.dict.delete(key); } catch (e) {}
          if (PDFBool) acro.dict.set(key, PDFBool.False);
        }
      } catch (e) {}

      if (flatten) {
        try { form.flatten(); } catch (e) {}
      }

      return await pdfDoc.save();
    }

    async function fillPdf(){
      const bytes = await buildPdfBytes({ flatten: false });
      renderPages(bytes);
    }

    if (downloadBtn) {
      downloadBtn.addEventListener('click', async () => {
        if (!downloadBtn) return;
        const label = downloadBtn.textContent;
        downloadBtn.disabled = true;
        downloadBtnText.textContent = <?= json_encode(t('parent.portal.download_creating')) ?>;
        try {
          const bytes = await buildPdfBytes({ flatten: true });
          const blob = new Blob([bytes], { type: 'application/pdf' });
          const url = URL.createObjectURL(blob);
          confirmReceipt();
          const a = document.createElement('a');
          a.href = url;
          a.download = downloadName || <?= json_encode(t('parent.portal.download_fallback_name')) ?>;
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) {
          showError(e?.message || String(e));
        } finally {
          downloadBtn.disabled = false;
          downloadBtnText.textContent = label;
        }
      });
    }

    fillPdf().catch(e => showError(e?.message || String(e)));
  </script>
  <?php endif; ?>
  <?php // Client-side datetime formatting disabled; server renders school-local time. ?>
<?php render_history_replace_state_script(); ?>
</body>
</html>
