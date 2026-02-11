<?php
declare(strict_types=1);

if (!isset($pdo, $u, $isAdmin)) {
  throw new RuntimeException('report_preview_page.php: context missing');
}

function report_preview_period_label_display(string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}

function report_preview_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  $id = (int)($c['class_id'] ?? ($c['id'] ?? 0));
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . $id));
}

function report_preview_meta_read(?string $json): array {
  if (!$json) return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function report_preview_extract_date_format(array $meta): string {
  $mode = isset($meta['date_format_mode']) ? strtolower(trim((string)$meta['date_format_mode'])) : '';
  $preset = trim((string)($meta['date_format_preset'] ?? ''));
  $custom = trim((string)($meta['date_format_custom'] ?? ''));
  return $mode === 'custom' ? $custom : $preset;
}

function report_preview_normalize_rect($rect): ?array {
  if (!is_array($rect) || count($rect) !== 4) return null;
  $nums = array_map('floatval', $rect);
  foreach ($nums as $n) {
    if (!is_finite($n)) return null;
  }
  return $nums;
}

function report_preview_resolve_value(PDO $pdo, int $fieldId, ?string $valueText, ?string $valueJson): string {
  $txt = (string)($valueText ?? '');
  if (!$valueJson) return $txt;
  $j = json_decode($valueJson, true);
  if (!is_array($j)) return $txt;
  $optId = isset($j['option_item_id']) ? (int)$j['option_item_id'] : 0;
  if ($optId > 0) {
    $st = $pdo->prepare('SELECT value FROM option_list_items WHERE id=? LIMIT 1');
    $st->execute([$optId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }
  return $txt;
}

$role = (string)($u['role'] ?? '');
$userId = (int)($u['id'] ?? 0);

if ($isAdmin) {
  $stStudents = $pdo->query(
    "SELECT s.id, s.master_student_id, s.first_name, s.last_name, s.is_active, s.class_id,
            c.school_year AS class_school_year, c.period_label AS class_period_label,
            c.grade_level, c.label, c.name
     FROM students s
     JOIN classes c ON c.id=s.class_id
     ORDER BY s.last_name ASC, s.first_name ASC"
  );
} else {
  $stStudents = $pdo->prepare(
    "SELECT DISTINCT s.id, s.master_student_id, s.first_name, s.last_name, s.is_active, s.class_id,
            c.school_year AS class_school_year, c.period_label AS class_period_label,
            c.grade_level, c.label, c.name
     FROM students s
     JOIN classes c ON c.id=s.class_id
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=?
     ORDER BY s.last_name ASC, s.first_name ASC"
  );
  $stStudents->execute([$userId]);
}
$students = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];

$studentsById = [];
$studentsByCanonical = [];
$studentAliasIdsByCanonical = [];
foreach ($students as $s) {
  $sid = (int)($s['id'] ?? 0);
  if ($sid <= 0) continue;
  $studentsById[$sid] = $s;
  $canonicalId = (int)($s['master_student_id'] ?? 0);
  if ($canonicalId <= 0) $canonicalId = $sid;
  if (!isset($studentAliasIdsByCanonical[$canonicalId])) $studentAliasIdsByCanonical[$canonicalId] = [];
  $studentAliasIdsByCanonical[$canonicalId][$sid] = $sid;
  if (!isset($studentsByCanonical[$canonicalId])) {
    $studentsByCanonical[$canonicalId] = $s;
    $studentsByCanonical[$canonicalId]['canonical_student_id'] = $canonicalId;
  } else {
    $existingActive = (int)($studentsByCanonical[$canonicalId]['is_active'] ?? 0) === 1;
    $candidateActive = (int)($s['is_active'] ?? 0) === 1;
    if (!$existingActive && $candidateActive) {
      $studentsByCanonical[$canonicalId] = $s;
      $studentsByCanonical[$canonicalId]['canonical_student_id'] = $canonicalId;
    }
  }
}
$studentChoices = array_values($studentsByCanonical);
usort($studentChoices, static function(array $a, array $b): int {
  $ca = mb_strtolower(report_preview_class_display($a), 'UTF-8');
  $cb = mb_strtolower(report_preview_class_display($b), 'UTF-8');
  if ($ca !== $cb) return $ca <=> $cb;

  $la = mb_strtolower(trim((string)($a['last_name'] ?? '')), 'UTF-8');
  $lb = mb_strtolower(trim((string)($b['last_name'] ?? '')), 'UTF-8');
  if ($la !== $lb) return $la <=> $lb;
  $fa = mb_strtolower(trim((string)($a['first_name'] ?? '')), 'UTF-8');
  $fb = mb_strtolower(trim((string)($b['first_name'] ?? '')), 'UTF-8');
  if ($fa !== $fb) return $fa <=> $fb;
  return ((int)($a['canonical_student_id'] ?? 0)) <=> ((int)($b['canonical_student_id'] ?? 0));
});

$selectedStudentId = (int)($_GET['student_id'] ?? 0);
if ($selectedStudentId <= 0 || !isset($studentsByCanonical[$selectedStudentId])) {
  $selectedStudentId = $studentChoices ? (int)($studentChoices[0]['canonical_student_id'] ?? 0) : 0;
}

$periodOptions = [];
if ($isAdmin) {
  $stPeriods = $pdo->query(
    "SELECT DISTINCT school_year, period_label
     FROM report_instances
     WHERE student_id IS NOT NULL
     ORDER BY school_year DESC, period_label DESC"
  );
} else {
  $stPeriods = $pdo->prepare(
    "SELECT DISTINCT ri.school_year, ri.period_label
     FROM report_instances ri
     JOIN students s ON s.id=ri.student_id
     JOIN user_class_assignments uca ON uca.class_id=s.class_id
     WHERE uca.user_id=?
     ORDER BY ri.school_year DESC, ri.period_label DESC"
  );
  $stPeriods->execute([$userId]);
}
foreach (($stPeriods->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
  $sy = trim((string)($row['school_year'] ?? ''));
  if ($sy === '') continue;
  $pl = normalize_class_period_label((string)($row['period_label'] ?? 'Standard'));
  $key = $sy . '|' . $pl;
  $periodOptions[$key] = ['school_year' => $sy, 'period_label' => $pl];
}

if ($selectedStudentId > 0 && isset($studentsByCanonical[$selectedStudentId])) {
  $s = $studentsByCanonical[$selectedStudentId];
  $sy = trim((string)($s['class_school_year'] ?? ''));
  $pl = normalize_class_period_label((string)($s['class_period_label'] ?? 'Standard'));
  if ($sy !== '') {
    $k = $sy . '|' . $pl;
    if (!isset($periodOptions[$k])) $periodOptions[$k] = ['school_year' => $sy, 'period_label' => $pl];
  }
}

$selectedPeriodKey = (string)($_GET['period'] ?? '');
if ($selectedPeriodKey === '' || !isset($periodOptions[$selectedPeriodKey])) {
  if ($selectedStudentId > 0 && isset($studentsByCanonical[$selectedStudentId])) {
    $sy = trim((string)($studentsByCanonical[$selectedStudentId]['class_school_year'] ?? ''));
    $pl = normalize_class_period_label((string)($studentsByCanonical[$selectedStudentId]['class_period_label'] ?? 'Standard'));
    $studentDefaultKey = $sy !== '' ? ($sy . '|' . $pl) : '';
    if ($studentDefaultKey !== '' && isset($periodOptions[$studentDefaultKey])) {
      $selectedPeriodKey = $studentDefaultKey;
    }
  }
  if ($selectedPeriodKey === '' && $periodOptions) {
    $selectedPeriodKey = (string)array_key_first($periodOptions);
  }
}

$selectedSchoolYear = '';
$selectedPeriodLabel = 'H1';
if ($selectedPeriodKey !== '' && isset($periodOptions[$selectedPeriodKey])) {
  $selectedSchoolYear = (string)$periodOptions[$selectedPeriodKey]['school_year'];
  $selectedPeriodLabel = (string)$periodOptions[$selectedPeriodKey]['period_label'];
}

$previewStatus = '';
$previewStatusLabel = '';
$previewReportId = 0;
$previewTemplateUrl = '';
$previewStudentName = '';
$previewValues = [];
$previewFieldMeta = [];
$previewWarnings = [];
$debugReportSelectSql = '';
$debugReportSelectParams = [];

$statusMap = [
  'draft' => t('teacher.report_preview.status_draft', 'In Bearbeitung'),
  'locked' => t('teacher.report_preview.status_locked', 'In Bearbeitung'),
  'submitted' => t('teacher.report_preview.status_submitted', 'Eingereicht'),
  'archived' => t('teacher.report_preview.status_archived', 'Archiviert'),
  'final' => t('teacher.report_preview.status_final', 'Final'),
];

if ($selectedStudentId > 0 && $selectedSchoolYear !== '') {
  $aliasIds = array_values(array_map('intval', $studentAliasIdsByCanonical[$selectedStudentId] ?? []));
  $ri = null;
  if ($aliasIds) {
    $in = implode(',', array_fill(0, count($aliasIds), '?'));
    $params = array_merge($aliasIds, [$selectedSchoolYear, $selectedPeriodLabel]);
    $debugReportSelectSql = "SELECT ri.id, ri.template_id, ri.status, ri.school_year, ri.period_label,
              s.first_name, s.last_name, s.class_id,
              c.grade_level, c.label, c.name,
              ri.updated_at
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
       JOIN classes c ON c.id=s.class_id
       WHERE ri.student_id IN ($in) AND ri.school_year=? AND ri.period_label=?
       ORDER BY ri.updated_at DESC, ri.id DESC";
    $debugReportSelectParams = $params;
    $stRi = $pdo->prepare($debugReportSelectSql);
    $stRi->execute($debugReportSelectParams);
    $riRows = $stRi->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($riRows) {
      $ri = $riRows[0];
      if (count($riRows) > 1) {
        $previewWarnings[] = str_replace(
          ['{count}', '{student_id}', '{period}'],
          [
            (string)count($riRows),
            (string)$selectedStudentId,
            $selectedSchoolYear . ' · ' . report_preview_period_label_display($selectedPeriodLabel),
          ],
          t('teacher.report_preview.warning.multiple_reports', 'Mehrere Berichte ({count}) für Schüler {student_id} und Zeitraum {period} gefunden. Der neueste Bericht wird angezeigt.')
        );
      }
    }
  }
  if ($ri) {
    $previewReportId = (int)$ri['id'];
    if ($previewReportId > 0) {
      try {
        apply_system_bindings($pdo, $previewReportId);
      } catch (Throwable $e) {
        // ignore preview-only binding refresh failures
      }
    }
    $previewStatus = (string)($ri['status'] ?? '');
    $previewStatusLabel = $statusMap[$previewStatus] ?? ($previewStatus !== '' ? strtoupper($previewStatus) : '—');
    $previewStudentName = trim((string)($ri['first_name'] ?? '') . ' ' . (string)($ri['last_name'] ?? ''));
    $previewTemplateUrl = url(($isAdmin ? 'admin' : 'teacher') . '/report_template_file.php?report_id=' . $previewReportId);

    $stVals = $pdo->prepare(
      "SELECT fv.template_field_id, fv.value_text, fv.value_json, tf.field_name
       FROM field_values fv
       JOIN template_fields tf ON tf.id=fv.template_field_id
       WHERE fv.report_instance_id=?"
    );
    $stVals->execute([$previewReportId]);
    $valuesByField = [];
    $valuesByName = [];
    foreach (($stVals->fetchAll(PDO::FETCH_ASSOC) ?: []) as $vRow) {
      $fid = (int)($vRow['template_field_id'] ?? 0);
      if ($fid <= 0) continue;
      $resolved = report_preview_resolve_value(
        $pdo,
        $fid,
        $vRow['value_text'] !== null ? (string)$vRow['value_text'] : '',
        $vRow['value_json'] !== null ? (string)$vRow['value_json'] : null
      );
      $valuesByField[$fid] = $resolved;
      $fieldName = trim((string)($vRow['field_name'] ?? ''));
      if ($fieldName !== '') $valuesByName[$fieldName] = $resolved;
    }

    $stFields = $pdo->prepare(
      "SELECT id, field_name, label, field_type, meta_json
       FROM template_fields
       WHERE template_id=?
       ORDER BY sort_order ASC, id ASC"
    );
    $stFields->execute([(int)$ri['template_id']]);
    $classFieldNames = [];
    $fieldsRows = $stFields->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fieldsRows as $fRow) {
      $meta = report_preview_meta_read($fRow['meta_json'] ?? null);
      $fname = trim((string)($fRow['field_name'] ?? ''));
      if ($fname !== '') {
        $entry = ['field_type' => (string)($fRow['field_type'] ?? 'text')];
        $df = report_preview_extract_date_format($meta);
        if ($df !== '') $entry['date_format'] = $df;
        $previewFieldMeta[$fname] = $entry;
      }
      if ((string)($meta['scope'] ?? '') === 'class') {
        $classFieldNames[(string)($fRow['field_name'] ?? '')] = true;
      }
    }

    if ($classFieldNames) {
      $stClassRi = $pdo->prepare(
        "SELECT id
         FROM report_instances
         WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
      );
      $stClassRi->execute([(int)$ri['template_id'], $selectedSchoolYear, class_report_period_label((int)$ri['class_id'], $selectedPeriodLabel)]);
      $classRiId = (int)($stClassRi->fetchColumn() ?: 0);
      if ($classRiId > 0) {
        try {
          apply_system_bindings($pdo, $classRiId);
        } catch (Throwable $e) {
          // ignore preview-only binding refresh failures
        }
        $stClassVals = $pdo->prepare(
          "SELECT fv.template_field_id, fv.value_text, fv.value_json, tf.field_name
           FROM field_values fv
           JOIN template_fields tf ON tf.id=fv.template_field_id
           WHERE fv.report_instance_id=?"
        );
        $stClassVals->execute([$classRiId]);
        foreach (($stClassVals->fetchAll(PDO::FETCH_ASSOC) ?: []) as $cv) {
          $fname = (string)($cv['field_name'] ?? '');
          if (!isset($classFieldNames[$fname])) continue;
          $fid = (int)($cv['template_field_id'] ?? 0);
          if ($fid <= 0) continue;
          $valuesByField[$fid] = report_preview_resolve_value(
            $pdo,
            $fid,
            $cv['value_text'] !== null ? (string)$cv['value_text'] : '',
            $cv['value_json'] !== null ? (string)$cv['value_json'] : null
          );
          if ($fname !== '') $valuesByName[$fname] = (string)$valuesByField[$fid];
        }
      }
    }
    $previewValues = $valuesByName;
  } else {
    $aliasIds = array_values(array_map('intval', $studentAliasIdsByCanonical[$selectedStudentId] ?? []));
    $sc = null;
    if ($aliasIds) {
      $in = implode(',', array_fill(0, count($aliasIds), '?'));
      $params = array_merge($aliasIds, [$selectedSchoolYear, $selectedPeriodLabel]);
      $stBase = $pdo->prepare(
        "SELECT s.*, c.*, c.id AS class_id
         FROM students s
         JOIN classes c ON c.id=s.class_id
         WHERE s.id IN ($in)
         ORDER BY
           (CASE WHEN c.school_year=? AND c.period_label=? THEN 0 ELSE 1 END) ASC,
           s.is_active DESC,
           s.updated_at DESC,
           s.id DESC
         LIMIT 1"
      );
      $stBase->execute($params);
      $sc = $stBase->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($sc) {
      $previewStudentName = trim((string)($sc['first_name'] ?? '') . ' ' . (string)($sc['last_name'] ?? ''));
      $previewStatusLabel = t('teacher.report_preview.status_missing', 'Noch nicht erstellt');
      $previewStatus = 'missing';
      $classIdForTemplate = (int)($sc['class_id'] ?? 0);
      if ($classIdForTemplate > 0) {
        $previewTemplateUrl = url('template_file.php?class_id=' . $classIdForTemplate);
      }

      $templateId = (int)($sc['template_id'] ?? 0);
      if ($templateId > 0) {
        $stFields = $pdo->prepare(
          "SELECT field_name, field_type, meta_json
           FROM template_fields
           WHERE template_id=?
           ORDER BY sort_order ASC, id ASC"
        );
        $stFields->execute([$templateId]);
        $valuesByName = [];
        foreach (($stFields->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
          $fname = trim((string)($row['field_name'] ?? ''));
          if ($fname === '') continue;
          $meta = report_preview_meta_read($row['meta_json'] ?? null);
          $fieldType = (string)($row['field_type'] ?? 'text');
          $df = report_preview_extract_date_format($meta);
          $entry = ['field_type' => $fieldType];
          if ($df !== '') $entry['date_format'] = $df;
          $previewFieldMeta[$fname] = $entry;

          $tpl = trim((string)($meta['system_binding_tpl'] ?? ($meta['system_binding_template'] ?? '')));
          $binding = trim((string)($meta['system_binding'] ?? ''));
          $val = null;
          if ($tpl !== '') {
            $val = resolve_system_binding_template($tpl, $sc, $sc, $meta, $fieldType);
          } elseif ($binding !== '') {
            $val = resolve_system_binding_value($binding, $sc, $sc);
          }
          if ($val !== null && trim((string)$val) !== '') {
            $valuesByName[$fname] = (string)$val;
          }
        }
        $previewValues = $valuesByName;
      }
    }
  }
}
?>

<div class="card">
  <h1 style="margin-top:0;"><?=h(t('teacher.report_preview.title', 'Berichtsvorschau'))?></h1>
  <form method="get" class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div>
      <label class="label" for="rpStudent"><?=h(t('teacher.report_preview.student', 'Schüler'))?></label>
      <select id="rpStudent" name="student_id" class="input" onchange="this.form.submit()">
        <?php foreach ($studentChoices as $s):
          $sid = (int)($s['canonical_student_id'] ?? 0);
          $nm = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
          $cls = report_preview_class_display($s);
          $active = (int)($s['is_active'] ?? 1) === 1;
          ?>
          <option value="<?=h((string)$sid)?>" <?= $sid === $selectedStudentId ? 'selected' : '' ?>>
            <?=h($nm . ' · ' . $cls . ($active ? '' : ' (' . t('teacher.report_preview.student_archived', 'archiviert') . ')'))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="rpPeriod"><?=h(t('teacher.report_preview.school_period', 'Schuljahr/Halbjahr'))?></label>
      <select id="rpPeriod" name="period" class="input" onchange="this.form.submit()">
        <?php foreach ($periodOptions as $key => $opt): ?>
          <option value="<?=h($key)?>" <?= $key === $selectedPeriodKey ? 'selected' : '' ?>>
            <?=h((string)$opt['school_year'] . ' · ' . report_preview_period_label_display((string)$opt['period_label']))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn" type="submit"><?=h(t('ui.save', 'Anzeigen'))?></button></noscript>
  </form>

  <?php if ($previewTemplateUrl !== ''): ?>
    <div class="muted" style="margin-top:10px;">
      <?=h($previewStudentName)?> · <?=h($selectedSchoolYear)?> · <?=h(report_preview_period_label_display($selectedPeriodLabel))?> ·
      <?=h(t('teacher.report_preview.status', 'Status'))?>: <strong><?=h($previewStatusLabel)?></strong>
      <?php if ($previewStatus !== ''): ?><span class="muted">(<?=h($previewStatus)?>)</span><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert" style="margin-top:10px;"><?=h(t('teacher.report_preview.not_found', 'Für diese Auswahl wurde kein Bericht gefunden.'))?></div>
  <?php endif; ?>

  <?php if ($previewWarnings): ?>
    <div class="alert warn" style="margin-top:10px;">
      <ul style="margin:0 0 0 18px;">
        <?php foreach ($previewWarnings as $warn): ?>
          <li><?=h($warn)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if ($debugReportSelectSql !== ''): ?>
    <div class="card" style="margin-top:10px; background:#fff8e1; border:1px dashed #d8b03f;">
      <strong>DEBUG SQL</strong>
      <pre style="white-space:pre-wrap; margin:8px 0 0; font-size:12px;"><?=h($debugReportSelectSql)?></pre>
      <strong>DEBUG PARAMS</strong>
      <pre style="white-space:pre-wrap; margin:8px 0 0; font-size:12px;"><?=h(json_encode($debugReportSelectParams, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre>
    </div>
  <?php endif; ?>

</div>

<div id="rpPreview" class="card" style="background:#f8f9fb; border:1px solid var(--border); min-height:120px;">
  <?php if ($previewTemplateUrl === ''): ?>
    <div class="muted"><?=h(t('teacher.report_preview.no_preview', 'Keine Vorschau verfügbar.'))?></div>
  <?php else: ?>
    <div class="pdf-loader" role="status"><span class="spinner"></span> <span class="txt"><?=h(t('ui.loading'))?></span></div>
  <?php endif; ?>
</div>

<?php if ($previewTemplateUrl !== ''): ?>
<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";
  const FONT_MANIFEST_URL = <?= json_encode(url('shared/font_manifest.php')) ?>;
  const FONTKIT_URL = 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js';

  const preview = document.getElementById('rpPreview');
  const templateUrl = <?=json_encode($previewTemplateUrl)?>;
  const fieldValues = <?=json_encode($previewValues, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const fieldMeta = <?=json_encode($previewFieldMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;

  const monthNames = {
    de: {
      full: ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
      short: ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez']
    },
    en: {
      full: ['January','February','March','April','May','June','July','August','September','October','November','December'],
      short: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
    }
  };

  function pad2(n){ return String(n).padStart(2, '0'); }
  function numberToMonthName(m, lang='de', mode='full'){
    const l = monthNames[lang] ? lang : 'de';
    const arr = monthNames[l][mode] || monthNames[l].full;
    const idx = Math.max(1, Math.min(12, Number(m))) - 1;
    return arr[idx] || '';
  }
  function parseFlexibleDate(raw){
    const s = (raw ?? '').toString().trim();
    if (!s) return null;
    let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (m) return { y:+m[1], m:+m[2], d:+m[3] };
    m = s.match(/^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})$/);
    if (m) {
      let y = +m[3];
      if (y < 100) y += 2000;
      return { y, m:+m[2], d:+m[1] };
    }
    return null;
  }
  function matchesFormat(raw, fmt){
    const p = parseFlexibleDate(raw);
    if (!p) return false;
    const f = formatDate(p, fmt);
    return f === raw;
  }
  function formatDate(parts, fmt){
    if (!parts || !fmt) return '';
    const y = parts.y, m = parts.m, d = parts.d;
    const yy = String(y).slice(-2);
    const lang = <?= json_encode(ui_lang()) ?>;
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
    return formatDate(parsed, fmt) || raw;
  }

  let __fontManifest = null;
  let __embeddedFonts = new Map();
  function normalizeFontName(raw){
    if (!raw) return '';
    let name = String(raw).trim().replace(/^\//, '').replace(/^[A-Z]{6}\+/, '');
    name = name.replace(/\s+/g, ' ').toLowerCase().trim();
    return name.replace(/[^a-z0-9._-]+/g, '_').replace(/^[_\-.]+|[_\-.]+$/g, '');
  }
  function expandFontKeys(base){
    if (!base) return [];
    const keys = new Set([base, base.replace(/-/g, '_'), base.replace(/_/g, '-')]);
    return Array.from(keys);
  }
  function pdfNameToString(name){
    if (!name) return '';
    if (typeof name === 'string') return name.replace(/^\//, '');
    if (typeof name?.decodeText === 'function') return name.decodeText().replace(/^\//, '');
    if (typeof name?.asString === 'function') return name.asString().replace(/^\//, '');
    if (typeof name?.key === 'string') return name.key.replace(/^\//, '');
    return String(name).replace(/^\//, '');
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
  function getFieldDefaultAppearance(field, PDFName){
    try {
      const da = field?.acroField?.dict?.lookup?.(PDFName.of('DA'));
      if (da) return pdfStringToText(da);
    } catch {}
    return '';
  }
  function resolveBaseFontName(field, fontKey, PDFName, form){
    if (!fontKey) return '';
    try {
      const dr = field?.acroField?.dict?.lookup?.(PDFName.of('DR')) || form?.acroForm?.dict?.lookup?.(PDFName.of('DR'));
      const fonts = dr?.lookup?.(PDFName.of('Font'));
      const font = fonts?.lookup?.(PDFName.of(fontKey));
      const base = font?.lookup?.(PDFName.of('BaseFont')) || font?.dict?.lookup?.(PDFName.of('BaseFont'));
      if (base) return pdfNameToString(base);
    } catch {}
    return fontKey;
  }
  async function loadFontManifest(){
    if (__fontManifest) return __fontManifest;
    const resp = await fetch(FONT_MANIFEST_URL, { credentials: 'same-origin' });
    if (!resp.ok) { __fontManifest = new Map(); return __fontManifest; }
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
      s.onload = resolve;
      s.onerror = () => reject(new Error('fontkit load failed'));
      document.head.appendChild(s);
    });
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
    for (const key of lookupKeys) if (__embeddedFonts.has(key)) return __embeddedFonts.get(key);
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
      if (!custom?.url || typeof pdfDoc.embedFont !== 'function') continue;
      await ensureFontkit();
      try { if (typeof pdfDoc.registerFontkit === 'function' && window.fontkit) pdfDoc.registerFontkit(window.fontkit); } catch {}
      const res = await fetch(custom.url, { credentials: 'same-origin' });
      if (!res.ok) return null;
      const bytes = await res.arrayBuffer();
      const font = await pdfDoc.embedFont(bytes);
      __embeddedFonts.set(key, font);
      return font;
    }
    return null;
  }
  async function updateFieldAppearancesWithFonts(form, pdfDoc, fallbackFont){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFTextField, PDFDropdown, PDFOptionList } = PDFLib;
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
      if (base) font = await getEmbeddedFont(pdfDoc, base, fontManifest);
      if (!font && fontKey) font = await getEmbeddedFont(pdfDoc, fontKey, fontManifest);
      if (!font) font = fallbackFont;
      try {
        if (font && typeof field.updateAppearances === 'function') field.updateAppearances(font);
        else if (typeof field.updateAppearances === 'function') field.updateAppearances();
      } catch {}
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
    } catch {}
    try {
      const da = widget?.dict?.lookup?.(PDFName.of('DA'));
      if (da) {
        const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
        if (color) return color;
      }
    } catch {}
    try {
      const da = radioField?.acroField?.dict?.lookup?.(PDFName.of('DA'));
      if (da) {
        const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
        if (color) return color;
      }
    } catch {}
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
    const content = ['q', colorOp ? `${colorOp}` : '', `${lw} w`, `${x1} ${y1} m ${x2} ${y2} l`, `${x1} ${y2} m ${x2} ${y1} l`, 'S', 'Q']
      .filter(Boolean).join('\n');
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
    } catch {}
    return '';
  }

  function getRadioGroupValue(radioField, PDFName){
    try {
      const selected = radioField?.getSelected?.();
      if (selected) return pdfNameToString(selected);
    } catch {}
    try {
      const v = radioField?.acroField?.dict?.lookup?.(PDFName.of('V'));
      if (v) return pdfNameToString(v);
    } catch {}
    return '';
  }

  function getRadioGroupOptions(radioField, PDFName){
    try {
      const opts = radioField?.getOptions?.();
      if (Array.isArray(opts) && opts.length) return opts.map(o => pdfNameToString(o));
    } catch {}
    try {
      const opt = radioField?.acroField?.dict?.lookup?.(PDFName.of('Opt'));
      const arr = opt?.asArray?.() || opt;
      if (Array.isArray(arr)) return arr.map(o => pdfNameToString(o)).filter(Boolean);
    } catch {}
    return [];
  }

  function getWidgetAppearanceState(widget, PDFName){
    try {
      const as = widget?.dict?.lookup?.(PDFName.of('AS'));
      const name = pdfNameToString(as);
      return name && name.toLowerCase() !== 'off' ? name : '';
    } catch {}
    return '';
  }

  function applyRadioCrossAppearances(pdfDoc, form){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFDict, PDFArray, PDFNumber } = PDFLib;
    const fields = form.getFields();
    for (const field of fields) {
      if (!(field instanceof PDFLib.PDFRadioGroup)) continue;

      let selectedValue = getRadioGroupValue(field, PDFName);
      const widgets = field?.acroField?.getWidgets?.() || [];
      const options = getRadioGroupOptions(field, PDFName);
      if (!selectedValue) {
        for (const widget of widgets) {
          const widgetSelected = getWidgetAppearanceState(widget, PDFName);
          if (widgetSelected) { selectedValue = widgetSelected; break; }
        }
      }

      for (let i = 0; i < widgets.length; i++) {
        const widget = widgets[i];
        const rect = widget.getRectangle();
        let onName = getWidgetOnName(widget, PDFName, PDFDict);
        if (!onName && options[i]) onName = options[i];
        if (!onName && selectedValue) onName = selectedValue;
        if (!onName) continue;

        const normalizedOn = pdfNameToString(onName);
        const isSelected = selectedValue && pdfNameToString(selectedValue).toLowerCase() === normalizedOn.toLowerCase();
        const color = getWidgetBorderColor(widget, field, PDFArray, PDFNumber, PDFName);
        const onRef = buildCrossAppearanceStream(pdfDoc, rect, color);
        const offRef = buildOffAppearanceStream(pdfDoc, rect);

        const apN = pdfDoc.context.obj({ Off: offRef, [normalizedOn]: onRef });
        const apDict = pdfDoc.context.obj({ N: apN });
        widget.dict.set(PDFName.of('AP'), apDict);
        widget.dict.set(PDFName.of('AS'), PDFName.of(isSelected ? normalizedOn : 'Off'));
      }

      if (selectedValue) {
        try {
          field.acroField?.setValue?.(PDFName.of(pdfNameToString(selectedValue)));
          field.acroField?.dict?.set?.(PDFName.of('V'), PDFName.of(pdfNameToString(selectedValue)));
          field.acroField?.dict?.set?.(PDFName.of('DV'), PDFName.of(pdfNameToString(selectedValue)));
        } catch {}
      }
    }
  }

  function loadPdfLib(){
    return new Promise((resolve, reject) => {
      if (window.PDFLib) return resolve(window.PDFLib);
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      s.onload = () => resolve(window.PDFLib);
      s.onerror = () => reject(new Error('pdf-lib konnte nicht geladen werden.'));
      document.head.appendChild(s);
    });
  }

  async function loadTemplateBytes(){
    const resp = await fetch(templateUrl, { credentials: 'same-origin' });
    if (!resp.ok) throw new Error(`Template-Download fehlgeschlagen (HTTP ${resp.status})`);
    return new Uint8Array(await resp.arrayBuffer());
  }

  function fillPdfForm(pdfDoc, values){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFBool } = PDFLib || {};
    const form = pdfDoc.getForm();
    const fields = form.getFields();
    fields.forEach((field) => {
      const name = field.getName();
      if (!Object.prototype.hasOwnProperty.call(values, name)) return;
      const meta = fieldMeta?.[name] || null;
      const expectedFmt = (meta?.date_format || '').toString().trim();
      let value = String(values[name] ?? '');
      if ((meta?.field_type || '').toString().toLowerCase() === 'date') {
        value = normalizeDateIfNeeded(value, expectedFmt);
      }
      try {
        if (typeof field.setText === 'function') {
          field.setText(value);
        } else if (typeof field.check === 'function') {
          const vv = value.trim().toLowerCase();
          if (['1', 'ja', 'yes', 'true', 'x', 'on'].includes(vv)) {
            field.check();
          } else {
            if (typeof field.uncheck === 'function') field.uncheck();
          }
        } else if (typeof field.select === 'function') {
          if (value !== '') field.select(value);
        } else {
          field.setText?.(value);
        }
      } catch {
        // ignore unsupported field write errors
      }
    });

    try {
      const acro = form.acroForm;
      if (acro && acro.dict && PDFName) {
        const key = PDFName.of('NeedAppearances');
        try { acro.dict.delete(key); } catch {}
        if (PDFBool) acro.dict.set(key, PDFBool.False);
      }
    } catch {}

    return form;
  }

  (async () => {
    try {
      const PDFLib = await loadPdfLib();
      const tplBytes = await loadTemplateBytes();
      const editable = await PDFLib.PDFDocument.load(tplBytes);
      const form = fillPdfForm(editable, fieldValues);
      let defaultFont = null;
      try {
        if (!defaultFont && PDFLib?.StandardFonts && typeof editable.embedFont === 'function') {
          defaultFont = await editable.embedFont(PDFLib.StandardFonts.Helvetica);
        }
      } catch {}
      await updateFieldAppearancesWithFonts(form, editable, defaultFont || undefined);
      applyRadioCrossAppearances(editable, form);
      const renderedBytes = await editable.save();

      const pdf = await pdfjsLib.getDocument({ data: renderedBytes }).promise;
      preview.innerHTML = '';
      const width = preview.clientWidth || 900;

      for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p);
        const base = page.getViewport({ scale: 1 });
        const scale = Math.min(1.6, Math.max(0.6, (width - 24) / base.width));
        const viewport = page.getViewport({ scale });

        const wrap = document.createElement('div');
        wrap.style.position = 'relative';
        wrap.style.width = `${viewport.width}px`;
        wrap.style.height = `${viewport.height}px`;
        wrap.style.marginBottom = '16px';
        wrap.style.border = '1px solid var(--border)';
        wrap.style.background = '#fff';

        const canvas = document.createElement('canvas');
        const ratio = window.devicePixelRatio || 1;
        canvas.width = viewport.width * ratio;
        canvas.height = viewport.height * ratio;
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        wrap.appendChild(canvas);

        preview.appendChild(wrap);
        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport, transform: ratio !== 1 ? [ratio,0,0,ratio,0,0] : undefined }).promise;
      }
    } catch (e) {
      preview.innerHTML = `<div class="alert danger"><strong>${String(e?.message || 'Preview failed')}</strong></div>`;
    }
  })();
</script>
<?php endif; ?>
