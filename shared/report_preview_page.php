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
    "SELECT s.id, s.first_name, s.last_name, s.is_active, s.class_id,
            c.school_year AS class_school_year, c.period_label AS class_period_label,
            c.grade_level, c.label, c.name
     FROM students s
     JOIN classes c ON c.id=s.class_id
     ORDER BY s.last_name ASC, s.first_name ASC"
  );
} else {
  $stStudents = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.is_active, s.class_id,
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
foreach ($students as $s) {
  $studentsById[(int)$s['id']] = $s;
}

$selectedStudentId = (int)($_GET['student_id'] ?? 0);
if ($selectedStudentId <= 0 || !isset($studentsById[$selectedStudentId])) {
  $selectedStudentId = $students ? (int)($students[0]['id'] ?? 0) : 0;
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

if ($selectedStudentId > 0 && isset($studentsById[$selectedStudentId])) {
  $s = $studentsById[$selectedStudentId];
  $sy = trim((string)($s['class_school_year'] ?? ''));
  $pl = normalize_class_period_label((string)($s['class_period_label'] ?? 'Standard'));
  if ($sy !== '') {
    $k = $sy . '|' . $pl;
    if (!isset($periodOptions[$k])) $periodOptions[$k] = ['school_year' => $sy, 'period_label' => $pl];
  }
}

$selectedPeriodKey = (string)($_GET['period'] ?? '');
if ($selectedPeriodKey === '' || !isset($periodOptions[$selectedPeriodKey])) {
  if ($selectedStudentId > 0 && isset($studentsById[$selectedStudentId])) {
    $sy = trim((string)($studentsById[$selectedStudentId]['class_school_year'] ?? ''));
    $pl = normalize_class_period_label((string)($studentsById[$selectedStudentId]['class_period_label'] ?? 'Standard'));
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

$statusMap = [
  'draft' => t('teacher.report_preview.status_draft', 'In Bearbeitung'),
  'locked' => t('teacher.report_preview.status_locked', 'In Bearbeitung'),
  'submitted' => t('teacher.report_preview.status_submitted', 'Eingereicht'),
  'archived' => t('teacher.report_preview.status_archived', 'Archiviert'),
  'final' => t('teacher.report_preview.status_final', 'Final'),
];

if ($selectedStudentId > 0 && $selectedSchoolYear !== '') {
  $stRi = $pdo->prepare(
    "SELECT ri.id, ri.template_id, ri.status, ri.school_year, ri.period_label,
            s.first_name, s.last_name, s.class_id,
            c.grade_level, c.label, c.name
     FROM report_instances ri
     JOIN students s ON s.id=ri.student_id
     JOIN classes c ON c.id=s.class_id
     WHERE ri.student_id=? AND ri.school_year=? AND ri.period_label=?
     ORDER BY ri.updated_at DESC, ri.id DESC
     LIMIT 1"
  );
  $stRi->execute([$selectedStudentId, $selectedSchoolYear, $selectedPeriodLabel]);
  $ri = $stRi->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($ri) {
    $previewReportId = (int)$ri['id'];
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
  }
}
?>

<div class="card">
  <h1 style="margin-top:0;"><?=h(t('teacher.report_preview.title', 'Berichtsvorschau'))?></h1>
  <form method="get" class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div>
      <label class="label" for="rpStudent"><?=h(t('teacher.report_preview.student', 'Schüler'))?></label>
      <select id="rpStudent" name="student_id" class="input" onchange="this.form.submit()">
        <?php foreach ($students as $s):
          $sid = (int)($s['id'] ?? 0);
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

  <?php if ($previewReportId > 0): ?>
    <div class="muted" style="margin-top:10px;">
      <?=h($previewStudentName)?> · <?=h($selectedSchoolYear)?> · <?=h(report_preview_period_label_display($selectedPeriodLabel))?> ·
      <?=h(t('teacher.report_preview.status', 'Status'))?>: <strong><?=h($previewStatusLabel)?></strong>
      <?php if ($previewStatus !== ''): ?><span class="muted">(<?=h($previewStatus)?>)</span><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert" style="margin-top:10px;"><?=h(t('teacher.report_preview.not_found', 'Für diese Auswahl wurde kein Bericht gefunden.'))?></div>
  <?php endif; ?>
</div>

<div id="rpPreview" class="card" style="background:#f8f9fb; border:1px solid var(--border); min-height:120px;">
  <?php if ($previewReportId <= 0): ?>
    <div class="muted"><?=h(t('teacher.report_preview.no_preview', 'Keine Vorschau verfügbar.'))?></div>
  <?php else: ?>
    <div class="pdf-loader" role="status"><span class="spinner"></span> <span class="txt"><?=h(t('ui.loading'))?></span></div>
  <?php endif; ?>
</div>

<?php if ($previewReportId > 0): ?>
<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

  const preview = document.getElementById('rpPreview');
  const templateUrl = <?=json_encode($previewTemplateUrl)?>;
  const fieldValues = <?=json_encode($previewValues, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;

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
    const form = pdfDoc.getForm();
    const fields = form.getFields();
    fields.forEach((field) => {
      const name = field.getName();
      if (!Object.prototype.hasOwnProperty.call(values, name)) return;
      const value = String(values[name] ?? '');
      try {
        const type = field?.constructor?.name || '';
        if (type === 'PDFTextField') {
          field.setText(value);
        } else if (type === 'PDFCheckBox') {
          if (value === '1' || value.toLowerCase() === 'true' || value.toLowerCase() === 'yes' || value.toLowerCase() === 'on') {
            field.check();
          } else {
            field.uncheck();
          }
        } else if (type === 'PDFRadioGroup') {
          if (value !== '') field.select(value);
        } else if (type === 'PDFDropdown' || type === 'PDFOptionList') {
          if (value !== '') field.select(value);
        } else {
          field.setText?.(value);
        }
      } catch {
        // ignore unsupported field write errors
      }
    });
    try { form.updateFieldAppearances(); } catch {}
  }

  (async () => {
    try {
      const PDFLib = await loadPdfLib();
      const tplBytes = await loadTemplateBytes();
      const editable = await PDFLib.PDFDocument.load(tplBytes);
      fillPdfForm(editable, fieldValues);
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
