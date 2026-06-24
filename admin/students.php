<?php
// admin/students.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/absence_import.php';
require_admin();

$pdo = db();
$userId = (int)(current_user()['id'] ?? 0);

$cfg = app_config();
$defaultSchoolYear = (string)($cfg['app']['default_school_year'] ?? '');
$absencePreviewApiUrl = url('admin/ajax/absence_import_preview.php');

$err = '';
$ok = '';
$importSkippedDetails = [];
$importSummary = $_SESSION['admin_import_summary'] ?? null;
if ($importSummary && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  unset($_SESSION['admin_import_summary']);
}
$absenceImportSummary = $_SESSION['admin_absence_import_summary'] ?? null;
if ($absenceImportSummary && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  unset($_SESSION['admin_absence_import_summary']);
}

function period_label_display_admin(?string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}

function parse_school_scope_key(string $raw): array {
  $raw = trim($raw);
  if ($raw === '' || strpos($raw, '|') === false) return ['', 'H1'];
  [$year, $period] = array_pad(explode('|', $raw, 2), 2, '');
  $year = trim((string)$year);
  $period = normalize_class_period_label((string)$period);
  return [$year, $period];
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['class_name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : '—');
}

function normalize_label(string $s): string {
  $s = trim($s);
  $s = strtolower($s);
  $s = preg_replace('/\s+/', '', $s);
  return $s;
}

function computed_class_name(?int $grade, string $label): string {
  $label = normalize_label($label);
  if ($grade === null || $grade <= 0 || $label === '') return trim((string)$grade . $label);
  return (string)$grade . $label;
}

function normalize_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

function normalize_lookup_token(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = preg_replace('/[\s,;]+/u', '', $s);
  return (string)$s;
}

function parse_student_name_token(string $raw): array {
  $v = trim($raw);
  if ($v === '') return ['', ''];
  if (str_contains($v, ',')) {
    [$last, $first] = array_pad(array_map('trim', explode(',', $v, 2)), 2, '');
    return [$last, $first];
  }
  $parts = preg_split('/\s+/u', $v) ?: [];
  if (count($parts) < 2) return [$v, ''];
  $last = array_shift($parts);
  $first = implode(' ', $parts);
  return [trim((string)$last), trim((string)$first)];
}

function parse_optional_non_negative_int(string $raw, string $fieldLabel): ?int {
  $v = trim($raw);
  if ($v === '') return null;
  if (!preg_match('/^\d+$/', $v)) {
    throw new RuntimeException(str_replace('{field}', $fieldLabel, t('admin.students.import.absence.reason.invalid_number', 'Ungültige Zahl in {field}.')));
  }
  return (int)$v;
}

function read_tab_rows(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException(t('admin.students.error.csv_open_failed'));
  $rows = [];
  while (($row = fgetcsv($fh, 0, "	", '"')) !== false) {
    if (!is_array($row)) continue;
    $allEmpty = true;
    foreach ($row as $cell) {
      if (trim((string)$cell) !== '') { $allEmpty = false; break; }
    }
    if ($allEmpty) continue;
    $rows[] = array_map(static fn($v): string => trim((string)$v), $row);
  }
  fclose($fh);
  return $rows;
}

function sanitize_import_email(?string $value): ?string {
  $email = trim((string)$value);
  if ($email === '') return null;
  return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function parse_blackbaud_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '' || $s === '""') return null;

  // Blackbaud often exports as M/D/YYYY (e.g. 7/16/2019)
  $s = trim($s, "\" \t\n\r\0\x0B");
  if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $s)) {
    $dt = DateTimeImmutable::createFromFormat('n/j/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
    $dt = DateTimeImmutable::createFromFormat('m/d/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
  }
  // accept YYYY-MM-DD
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  // accept DD.MM.YYYY
  if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
    $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
    return $m[3] . '-' . $mo . '-' . $d;
  }
  return null;
}

function parse_grade_level(?string $value): ?int {
  $value = trim((string)$value);
  if ($value === '') return null;
  if (preg_match('/\d+/', $value, $m)) {
    $grade = (int)$m[0];
    return $grade > 0 ? $grade : null;
  }
  return null;
}

function read_csv_assoc(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException(t('admin.students.error.csv_open_failed'));

  // Read header line (handle UTF-8 BOM)
  $rawHeader = fgets($fh);
  if ($rawHeader === false) { fclose($fh); return []; }
  $rawHeader = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader);

  $delimiterCounts = [
    ',' => substr_count($rawHeader, ','),
    ';' => substr_count($rawHeader, ';'),
    "\t" => substr_count($rawHeader, "\t"),
  ];
  arsort($delimiterCounts);
  $delimiter = array_key_first($delimiterCounts);
  if ($delimiter === null || $delimiterCounts[$delimiter] === 0) {
    $delimiter = ',';
  }

  // Put header line back into a temp stream so we can use fgetcsv consistently
  $tmp = fopen('php://temp', 'wb+');
  fwrite($tmp, $rawHeader);
  while (($line = fgets($fh)) !== false) fwrite($tmp, $line);
  fclose($fh);
  rewind($tmp);

  $header = fgetcsv($tmp, 0, $delimiter, '"');
  if (!$header) { fclose($tmp); return []; }

  $header = array_map(static function($h) {
    $h = (string)$h;
    $h = trim($h);
    $h = trim($h, "\" \t\n\r\0\x0B");
    return $h;
  }, $header);

  $rows = [];
  while (($row = fgetcsv($tmp, 0, $delimiter, '"')) !== false) {
    if (!$row) continue;
    $assoc = [];
    foreach ($header as $i => $h) {
      $assoc[$h] = $row[$i] ?? '';
    }
    // skip empty lines
    $allEmpty = true;
    foreach ($assoc as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) continue;
    $rows[] = $assoc;
  }
  fclose($tmp);
  return $rows;
}

function find_master_student_id(PDO $pdo, string $first, string $last, ?string $dob): ?int {
  $first = trim($first);
  $last  = trim($last);
  if ($first === '' || $last === '') return null;
  if ($dob === null || $dob === '') return null;

  $q = $pdo->prepare(
    "SELECT id, master_student_id
     FROM students
     WHERE first_name=? AND last_name=? AND date_of_birth=?
     ORDER BY (master_student_id IS NULL) ASC, id ASC
     LIMIT 1"
  );
  $q->execute([$first, $last, $dob]);
  $row = $q->fetch();
  if (!$row) return null;

  $master = $row['master_student_id'] !== null ? (int)$row['master_student_id'] : 0;
  if ($master > 0) return $master;

  // If the found record has no master, set itself as master (future-proof)
  $sid = (int)$row['id'];
  if ($sid > 0) {
    $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?")->execute([$sid, $sid]);
    return $sid;
  }
  return null;
}

function delete_students_cascade(PDO $pdo, array $studentIds): array {
  $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($x)=>$x>0));
  if (!$studentIds) return ['students_deleted'=>0,'reports_deleted'=>0,'values_deleted'=>0];

  $pdo->beginTransaction();

  $in = implode(',', array_fill(0, count($studentIds), '?'));

  // collect report ids
  $st = $pdo->prepare("SELECT id FROM report_instances WHERE student_id IN ($in)");
  $st->execute($studentIds);
  $reportIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));

  $valuesDeleted = 0;
  $reportsDeleted = 0;

  if ($reportIds) {
    $in2 = implode(',', array_fill(0, count($reportIds), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) AS cnt FROM field_values WHERE report_instance_id IN ($in2)");
    $st->execute($reportIds);
    $valuesDeleted = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

    $pdo->prepare("DELETE FROM field_values WHERE report_instance_id IN ($in2)")->execute($reportIds);
    $pdo->prepare("DELETE FROM report_instances WHERE id IN ($in2)")->execute($reportIds);
    $reportsDeleted = count($reportIds);
  }

  $pdo->prepare("DELETE FROM students WHERE id IN ($in)")->execute($studentIds);

  $pdo->commit();

  return ['students_deleted'=>count($studentIds),'reports_deleted'=>$reportsDeleted,'values_deleted'=>$valuesDeleted];
}

function load_delete_impact(PDO $pdo, array $masterIds): array {
  $masterIds = array_values(array_unique(array_filter(array_map('intval', $masterIds), fn($x)=>$x>0)));
  if (!$masterIds) return [];

  $expr = "CASE WHEN s.master_student_id IS NULL OR s.master_student_id=0 THEN s.id ELSE s.master_student_id END";
  $in = implode(',', array_fill(0, count($masterIds), '?'));
  $st = $pdo->prepare(
    "SELECT $expr AS master_id,
            COUNT(DISTINCT s.id) AS students_total,
            COUNT(DISTINCT r.id) AS reports_total,
            COUNT(fv.id) AS values_total
     FROM students s
     LEFT JOIN report_instances r ON r.student_id = s.id
     LEFT JOIN field_values fv ON fv.report_instance_id = r.id
     WHERE $expr IN ($in)
     GROUP BY $expr"
  );
  $st->execute($masterIds);

  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mid = (int)($r['master_id'] ?? 0);
    if ($mid <= 0) continue;
    $map[$mid] = [
      'students' => (int)($r['students_total'] ?? 0),
      'reports' => (int)($r['reports_total'] ?? 0),
      'values' => (int)($r['values_total'] ?? 0),
    ];
  }
  return $map;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'delete_student') {
      $studentId = (int)($_POST['student_id'] ?? 0);
      if ($studentId <= 0) throw new RuntimeException(t('admin.students.error.student_id_missing'));

      $confirm = (string)($_POST['confirm_text'] ?? '');
      $must = (string)($_POST['must_match'] ?? '');
      if ($confirm === '' || $must === '' || $confirm !== $must) {
        throw new RuntimeException(t('admin.students.error.confirm_failed'));
      }

      $st = $pdo->prepare("SELECT id, master_student_id FROM students WHERE id=? LIMIT 1");
      $st->execute([$studentId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException(t('admin.students.error.not_found'));

      $master = (int)($row['master_student_id'] ?? 0);
      $ids = [$studentId];
      if ($master > 0) {
        $st = $pdo->prepare("SELECT id FROM students WHERE master_student_id=?");
        $st->execute([$master]);
        $ids = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
      }

      $stats = delete_students_cascade($pdo, $ids);
      audit('admin_student_delete', $userId, ['student_id'=>$studentId,'deleted_ids'=>$ids] + $stats);
      $ok = str_replace(
        ['{students}', '{reports}', '{values}'],
        [(string)$stats['students_deleted'], (string)$stats['reports_deleted'], (string)$stats['values_deleted']],
        t('admin.students.status.deleted')
      );
    }

    elseif ($action === 'update_import_templates') {
      $classTemplates = $_POST['class_template'] ?? [];
      if (!is_array($classTemplates)) $classTemplates = [];
      $summary = $_SESSION['admin_import_summary'] ?? null;
      if (!$summary || empty($summary['classes'])) {
        throw new RuntimeException(t('admin.students.error.import_summary_missing'));
      }
      $classIds = array_map(static fn($c)=>(int)($c['class_id'] ?? 0), $summary['classes']);
      $classIds = array_values(array_filter($classIds, fn($x)=>$x>0));
      if (!$classIds) {
        throw new RuntimeException(t('admin.students.error.no_classes_to_update'));
      }

      $pdo->beginTransaction();
      $updated = 0;
      foreach ($classTemplates as $cid => $tid) {
        $cid = (int)$cid;
        if (!in_array($cid, $classIds, true)) continue;
        $tid = (int)$tid;
        $tpl = $tid > 0 ? $tid : null;
        $pdo->prepare("UPDATE classes SET template_id=? WHERE id=?")->execute([$tpl, $cid]);
        $updated++;
      }
      $pdo->commit();
      audit('admin_students_import_templates', $userId, ['updated'=>$updated,'class_ids'=>$classIds]);
      $ok = str_replace(
        '{updated}',
        (string)$updated,
        t('admin.students.status.templates_updated')
      );
    }


    elseif ($action === 'import_absence_csv') {
      if (empty($_FILES['absence_csv_file']) || !isset($_FILES['absence_csv_file']['tmp_name'])) {
        throw new RuntimeException(t('admin.students.error.csv_required'));
      }
      $csvTmp = (string)($_FILES['absence_csv_file']['tmp_name'] ?? '');
      if ($csvTmp === '') throw new RuntimeException(t('admin.students.error.csv_required'));

      [$schoolYearAbs, $periodLabelAbs] = parse_school_scope_key((string)($_POST['absence_scope'] ?? ''));
      if ($schoolYearAbs === '') {
        $schoolYearAbs = trim((string)($_POST['absence_school_year'] ?? ''));
      }
      if ($schoolYearAbs === '') $schoolYearAbs = $defaultSchoolYear;
      if ($schoolYearAbs === '') throw new RuntimeException(t('admin.students.error.school_year_missing'));

      if (!isset($_POST['absence_scope']) || trim((string)$_POST['absence_scope']) === '') {
        $periodLabelAbs = normalize_class_period_label((string)($_POST['absence_period_label'] ?? $periodLabelAbs));
      }

      $colClass = max(1, (int)($_POST['absence_col_class'] ?? 1));
      $colStudent = max(1, (int)($_POST['absence_col_student'] ?? 2));
      $colTotal = max(1, (int)($_POST['absence_col_total'] ?? 15));
      $colUnexcused = max(1, (int)($_POST['absence_col_unexcused'] ?? 16));
      $manualStudentMap = $_POST['absence_manual_student_id'] ?? [];
      if (!is_array($manualStudentMap)) $manualStudentMap = [];

      $detectedDelimiter = null;
      $rows = read_absence_csv_rows($csvTmp, $detectedDelimiter);
      if (!$rows) {
        throw new RuntimeException(t('admin.students.import.reason.empty_csv'));
      }

      $classRows = $pdo->prepare(
        "SELECT id, school_year, period_label, grade_level, label, name
         FROM classes
         WHERE school_year=? AND period_label=?"
      );
      $classRows->execute([$schoolYearAbs, $periodLabelAbs]);
      $classMap = [];
      $classById = [];
      foreach ($classRows->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id <= 0) continue;
        foreach ([(string)($c['name'] ?? '')] as $className) {
          $name = absence_import_normalize_token($className);
          if ($name !== '') $classMap[$name] = $id;
        }
        $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
        $label = (string)($c['label'] ?? '');
        $displayName = computed_class_name($grade, $label);
        $display = absence_import_normalize_token($displayName);
        if ($display !== '') $classMap[$display] = $id;
        $classById[$id] = [
          'id' => $id,
          'name' => (string)($c['name'] ?? ''),
          'display' => $displayName !== '' ? $displayName : (string)($c['name'] ?? ''),
        ];
      }

      $studentIndex = absence_import_student_index($pdo, $schoolYearAbs, $periodLabelAbs);

      $existingAbsRows = $pdo->prepare(
        "SELECT student_id, absence_days_total, absence_days_unexcused
         FROM student_period_absences
         WHERE school_year=? AND period_label=?"
      );
      $existingAbsRows->execute([$schoolYearAbs, $periodLabelAbs]);
      $existingAbs = [];
      foreach ($existingAbsRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingAbs[(int)$r['student_id']] = [
          'total' => (int)($r['absence_days_total'] ?? 0),
          'unexcused' => (int)($r['absence_days_unexcused'] ?? 0),
        ];
      }

      $upAbs = $pdo->prepare(
        "INSERT INTO student_period_absences (student_id, class_id, school_year, period_label, absence_days_total, absence_days_unexcused)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE class_id=VALUES(class_id), absence_days_total=VALUES(absence_days_total), absence_days_unexcused=VALUES(absence_days_unexcused), updated_at=NOW()"
      );

      $processed = 0;
      $upserted = 0;
      $skipped = 0;
      $importedZero = 0;
      $notFound = 0;
      $ambiguous = 0;
      $invalidNames = 0;
      $invalidValues = 0;
      $manualRequired = 0;
      $manualAssigned = 0;
      $ignoredExternal = 0;
      $skipDetails = [];
      $classIdsInImport = [];

      $pdo->beginTransaction();
      foreach ($rows as $lineNo => $row) {
        $processed++;
        $classRaw = absence_import_clean_string($row[$colClass - 1] ?? '');
        $studentRaw = absence_import_clean_string($row[$colStudent - 1] ?? '');
        if ($classRaw === '' && $studentRaw === '') continue;

        $classKey = absence_import_normalize_token($classRaw);
        $classIdAbs = $classMap[$classKey] ?? 0;
        if ($classIdAbs > 0) $classIdsInImport[$classIdAbs] = true;
        if ($classIdAbs <= 0) {
          $skipped++;
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw !== '' ? $studentRaw : t('admin.students.import.unknown_name'), 'class' => $classRaw, 'value' => absence_import_clean_string($row[$colTotal - 1] ?? ''), 'reason' => t('admin.students.import.absence.reason.class_not_found', 'Klasse nicht gefunden:') . ' ' . $classRaw];
          continue;
        }

        $match = absence_import_match_student($studentIndex, $classIdAbs, $studentRaw);
        $studentIdAbs = (int)($match['student_id'] ?? 0);
        $manualStudentId = (int)($manualStudentMap[$lineNo] ?? 0);
        if ($studentIdAbs <= 0 && $manualStudentId > 0) {
          $manualCandidate = $studentIndex[$classIdAbs]['students'][$manualStudentId] ?? null;
          if (is_array($manualCandidate)) {
            $studentIdAbs = $manualStudentId;
            $manualAssigned++;
          }
        }
        if ($studentIdAbs <= 0) {
          $status = (string)($match['status'] ?? 'not_found');
          if ($status === 'not_found') {
            $ignoredExternal++;
            continue;
          }
          $skipped++;
          if ($status === 'ambiguous') $ambiguous++; else $notFound++;
          if (empty(($match['parsed'] ?? [])['ok'])) $invalidNames++;
          $manualRequired++;
          $suggestions = array_map(static fn($s) => (string)($s['name'] ?? ''), (array)($match['suggestions'] ?? []));
          $reason = (string)($match['warning'] ?? t('admin.students.import.absence.reason.student_not_found', 'Schüler nicht gefunden.'));
          if ($suggestions) $reason .= ' ' . strtr(t('admin.students.import.absence.suggestions', 'Vorschläge: {names}'), ['{names}' => implode('; ', array_slice($suggestions, 0, 5))]);
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw !== '' ? $studentRaw : t('admin.students.import.unknown_name'), 'class' => (string)($classById[$classIdAbs]['display'] ?? $classRaw), 'value' => absence_import_clean_string($row[$colTotal - 1] ?? ''), 'reason' => $reason];
          continue;
        }

        $totalParsed = parse_absence_value((string)($row[$colTotal - 1] ?? ''), t('admin.students.import.absence.col_total', 'Fehltage gesamt'));
        $unexcusedParsed = parse_absence_value((string)($row[$colUnexcused - 1] ?? ''), t('admin.students.import.absence.col_unexcused', 'Fehltage unentschuldigt'));
        $totalOk = !empty($totalParsed['ok']);
        $unexcusedOk = !empty($unexcusedParsed['ok']);
        if (!$totalOk && !$unexcusedOk) {
          $skipped++;
          $invalidValues++;
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw !== '' ? $studentRaw : t('admin.students.import.unknown_name'), 'class' => (string)($classById[$classIdAbs]['display'] ?? $classRaw), 'value' => absence_import_clean_string($row[$colTotal - 1] ?? ''), 'reason' => trim((string)($totalParsed['warning'] ?? '') . ' ' . (string)($unexcusedParsed['warning'] ?? ''))];
          continue;
        }

        $existing = $existingAbs[$studentIdAbs] ?? null;
        if (!$totalOk && !$existing) {
          $skipped++;
          $invalidValues++;
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw, 'class' => (string)($classById[$classIdAbs]['display'] ?? $classRaw), 'value' => absence_import_clean_string($row[$colTotal - 1] ?? ''), 'reason' => t('admin.students.import.absence.reason.total_required_for_new', 'Fehltage gesamt fehlt; ohne bestehenden Wert wird nichts importiert.')];
          continue;
        }
        if (!$unexcusedOk && !$existing) {
          $skipped++;
          $invalidValues++;
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw, 'class' => (string)($classById[$classIdAbs]['display'] ?? $classRaw), 'value' => absence_import_clean_string($row[$colUnexcused - 1] ?? ''), 'reason' => t('admin.students.import.absence.reason.unexcused_required_for_new', 'Unentschuldigte Fehltage fehlt; ohne bestehenden Wert wird nichts importiert.')];
          continue;
        }

        $totalVal = $totalOk ? (int)$totalParsed['value'] : (int)($existing['total'] ?? 0);
        $unexcusedVal = $unexcusedOk ? (int)$unexcusedParsed['value'] : (int)($existing['unexcused'] ?? 0);
        if ($unexcusedVal > $totalVal) {
          $skipped++;
          $invalidValues++;
          $skipDetails[] = ['line' => $lineNo + 1, 'name' => $studentRaw !== '' ? $studentRaw : t('admin.students.import.unknown_name'), 'class' => (string)($classById[$classIdAbs]['display'] ?? $classRaw), 'value' => absence_import_clean_string($row[$colTotal - 1] ?? ''), 'reason' => t('teacher.students.error.absence_unexcused_gt_total', 'Unentschuldigte Fehltage dürfen nicht größer als Gesamt-Fehltage sein.')];
          continue;
        }

        $upAbs->execute([$studentIdAbs, $classIdAbs, $schoolYearAbs, $periodLabelAbs, $totalVal, $unexcusedVal]);
        $existingAbs[$studentIdAbs] = ['total' => $totalVal, 'unexcused' => $unexcusedVal];
        if (($totalOk && (int)$totalParsed['value'] === 0) || ($unexcusedOk && (int)$unexcusedParsed['value'] === 0)) $importedZero++;
        $upserted++;
      }
      $pdo->commit();

      $foundClasses = [];
      foreach (array_keys($classIdsInImport) as $cid) {
        $c = $classById[(int)$cid] ?? null;
        if (is_array($c)) $foundClasses[] = (string)($c['display'] ?? $c['name'] ?? ('#'.$cid));
      }
      sort($foundClasses, SORT_NATURAL | SORT_FLAG_CASE);

      $missingClasses = [];
      foreach ($classById as $cid => $c) {
        if (isset($classIdsInImport[(int)$cid])) continue;
        $missingClasses[] = (string)($c['display'] ?? $c['name'] ?? ('#'.$cid));
      }
      sort($missingClasses, SORT_NATURAL | SORT_FLAG_CASE);

      $absenceImportSummary = [
        'school_year' => $schoolYearAbs,
        'period_label' => $periodLabelAbs,
        'processed' => $processed,
        'updated' => $upserted,
        'skipped' => $skipped,
        'imported_zero' => $importedZero,
        'not_found' => $notFound,
        'ambiguous' => $ambiguous,
        'invalid_names' => $invalidNames,
        'invalid_values' => $invalidValues,
        'manual_required' => $manualRequired,
        'manual_assigned' => $manualAssigned,
        'ignored_external' => $ignoredExternal,
        'delimiter' => $detectedDelimiter === "\t" ? 'Tab' : (string)$detectedDelimiter,
        'details' => $skipDetails,
        'found_classes' => $foundClasses,
        'missing_classes' => $missingClasses,
      ];
      $_SESSION['admin_absence_import_summary'] = $absenceImportSummary;

      audit('admin_students_import_absence_csv', $userId, [
        'school_year' => $schoolYearAbs,
        'period_label' => $periodLabelAbs,
        'processed' => $processed,
        'upserted' => $upserted,
        'skipped' => $skipped,
        'imported_zero' => $importedZero,
        'not_found' => $notFound,
        'ambiguous' => $ambiguous,
        'invalid_names' => $invalidNames,
        'invalid_values' => $invalidValues,
        'manual_assigned' => $manualAssigned,
        'ignored_external' => $ignoredExternal,
      ]);

      $ok = str_replace(
        ['{processed}', '{updated}', '{skipped}'],
        [(string)$processed, (string)$upserted, (string)$skipped],
        t('admin.students.import.absence.summary', 'Fehltage-Import abgeschlossen: verarbeitet {processed}, übernommen {updated}, übersprungen {skipped}.')
      );
    }

    elseif ($action === 'import_blackbaud_csv') {
      if (empty($_FILES['csv_file']) || !isset($_FILES['csv_file']['tmp_name'])) {
        throw new RuntimeException(t('admin.students.error.csv_required'));
      }
      $csvTmpNames = $_FILES['csv_file']['tmp_name'];
      $csvNames = $_FILES['csv_file']['name'] ?? [];
      if (!is_array($csvTmpNames)) {
        $csvTmpNames = [$csvTmpNames];
        $csvNames = is_array($csvNames) ? $csvNames : [$csvNames];
      }
      $csvTmpNames = array_values($csvTmpNames);
      $csvNames = array_values(is_array($csvNames) ? $csvNames : []);
      $csvCount = count($csvTmpNames);
      if ($csvCount === 0) {
        throw new RuntimeException(t('admin.students.error.csv_required'));
      }

      $schoolYear = trim((string)($_POST['school_year'] ?? ''));
      if ($schoolYear === '') $schoolYear = $defaultSchoolYear;
      if ($schoolYear === '') throw new RuntimeException(t('admin.students.error.school_year_missing'));

      $createdClasses = 0;
      $createdStudents = 0;
      $updatedStudents = 0;
      $skipped = 0;
      $importSkippedDetails = [];
      $importSummary = [
        'files' => [],
        'classes' => [],
        'students' => [],
        'skipped' => [],
        'stats' => [
          'files' => 0,
          'classes_created' => 0,
          'students_created' => 0,
          'students_updated' => 0,
          'skipped' => 0,
        ],
      ];

      $pdo->beginTransaction();

      $periodLabel = 'Standard';
      $stPeriod = $pdo->prepare("SELECT period_label FROM classes WHERE school_year=? AND is_active=1 ORDER BY id DESC LIMIT 1");
      $stPeriod->execute([$schoolYear]);
      $periodLabel = normalize_class_period_label((string)($stPeriod->fetchColumn() ?: 'Standard'));

      $classLookup = $pdo->prepare(
        "SELECT id FROM classes WHERE school_year=? AND period_label=? AND grade_level=? AND label=? LIMIT 1"
      );
      $classInsert = $pdo->prepare(
        "INSERT INTO classes (school_year, period_label, grade_level, label, name, template_id, student_wizard_display, is_active)
         VALUES (?, ?, ?, ?, ?, NULL, 'groups', 1)"
      );

      $checkStudent = $pdo->prepare(
        "SELECT id FROM students WHERE first_name=? AND last_name=? AND date_of_birth=? AND class_id=? LIMIT 1"
      );
      $insertStudent = $pdo->prepare(
        "INSERT INTO students (master_student_id, class_id, first_name, last_name, date_of_birth, is_active)
         VALUES (?, ?, ?, ?, ?, 1)"
      );
      $setSelfMaster = $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?");

      foreach ($csvTmpNames as $index => $tmpPathRaw) {
        $tmpPath = (string)$tmpPathRaw;
        $csvLabel = $csvNames[$index] ?? ('CSV ' . ($index + 1));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
          $detail = [
            'name' => $csvLabel,
            'reason' => t('admin.students.import.reason.upload_failed'),
          ];
          $importSkippedDetails[] = $detail;
          $importSummary['skipped'][] = $detail;
          $skipped++;
          continue;
        }

        $rows = read_csv_assoc($tmpPath);
        if (!$rows) {
          $detail = [
            'name' => $csvLabel,
            'reason' => t('admin.students.import.reason.empty_csv'),
          ];
          $importSkippedDetails[] = $detail;
          $importSummary['skipped'][] = $detail;
          $skipped++;
          continue;
        }

        $importSummary['files'][] = $csvLabel;

        foreach ($rows as $r) {
          $gradeRaw = $r['Grade'] ?? $r['Klasse'] ?? $r['Stufe'] ?? null;
          $labelRaw = $r['Parallelklasse'] ?? $r['Parallelklasse/Gruppe'] ?? $r['Class Section'] ?? $r['Parallel Class'] ?? null;
          $grade = parse_grade_level($gradeRaw);
          $label = normalize_label((string)$labelRaw);

          $first = normalize_name((string)($r['Student First Name'] ?? $r['First Name'] ?? $r['Student Firstname'] ?? ''));
          $last  = normalize_name((string)($r['Student Last Name'] ?? $r['Last Name'] ?? $r['Student Lastname'] ?? ''));
          $dob   = parse_blackbaud_date($r['Birth Date'] ?? $r['DOB'] ?? $r['Date of Birth'] ?? null);
          $emailStudent = sanitize_import_email($r['Student Email'] ?? $r['E-Mail Student'] ?? $r['E-Mail Schüler'] ?? $r['Email Student'] ?? null);
          $emailParent1 = sanitize_import_email($r['E-Mail Parent 1'] ?? $r['Parent 1 Email'] ?? $r['Parent Email 1'] ?? $r['Email Parent 1'] ?? null);
          $emailParent2 = sanitize_import_email($r['E-Mail Parent 2'] ?? $r['Parent 2 Email'] ?? $r['Parent Email 2'] ?? $r['Email Parent 2'] ?? null);

          if ($first === '' && $last === '') continue;
          if ($grade === null || $label === '') {
            $skipped++;
            $detail = [
              'name' => trim($first . ' ' . $last) ?: t('admin.students.import.unknown_name'),
              'reason' => t('admin.students.import.reason.class_missing'),
            ];
            $importSkippedDetails[] = $detail;
            $importSummary['skipped'][] = $detail;
            continue;
          }
          if ($first === '' || $last === '') {
            $skipped++;
            $detail = [
              'name' => trim($first . ' ' . $last) ?: t('admin.students.import.unknown_name'),
              'reason' => t('admin.students.import.reason.name_missing'),
            ];
            $importSkippedDetails[] = $detail;
            $importSummary['skipped'][] = $detail;
            continue;
          }
          if ($dob === null) {
            $skipped++;
            $detail = [
              'name' => trim($first . ' ' . $last),
              'reason' => t('admin.students.import.reason.dob_missing'),
            ];
            $importSkippedDetails[] = $detail;
            $importSummary['skipped'][] = $detail;
            continue;
          }

          $classLookup->execute([$schoolYear, $periodLabel, $grade, $label]);
          $classId = $classLookup->fetchColumn();
          if (!$classId) {
            $name = computed_class_name($grade, $label);
            $classInsert->execute([$schoolYear, $periodLabel, $grade, $label, $name]);
            $classId = (int)$pdo->lastInsertId();
            $createdClasses++;
            $importSummary['classes'][$classId] = [
              'class_id' => $classId,
              'school_year' => $schoolYear,
              'grade_level' => $grade,
              'label' => $label,
              'name' => $name,
              'students_created' => 0,
              'students_updated' => 0,
              'students_skipped' => 0,
            ];
          } else {
            $classId = (int)$classId;
            if (!isset($importSummary['classes'][$classId])) {
              $importSummary['classes'][$classId] = [
                'class_id' => $classId,
                'school_year' => $schoolYear,
                'grade_level' => $grade,
                'label' => $label,
                'name' => computed_class_name($grade, $label),
                'students_created' => 0,
                'students_updated' => 0,
                'students_skipped' => 0,
              ];
            }
          }

          $checkStudent->execute([$first, $last, $dob, $classId]);
          $existingId = $checkStudent->fetchColumn();
          if ($existingId) {
            $updates = [];
            $params = [];
            if ($emailStudent !== null) { $updates[] = "email_student=?"; $params[] = $emailStudent; }
            if ($emailParent1 !== null) { $updates[] = "email_parent1=?"; $params[] = $emailParent1; }
            if ($emailParent2 !== null) { $updates[] = "email_parent2=?"; $params[] = $emailParent2; }
            if ($updates) {
              $params[] = (int)$existingId;
              $pdo->prepare("UPDATE students SET " . implode(', ', $updates) . " WHERE id=?")->execute($params);
              $updatedStudents++;
              $importSummary['classes'][$classId]['students_updated']++;
              $importSummary['students'][] = [
                'class_id' => $classId,
                'name' => trim($first . ' ' . $last),
                'status' => t('admin.students.import.status.updated'),
              ];
            } else {
              $skipped++;
              $detail = [
                'name' => trim($first . ' ' . $last),
                'reason' => t('admin.students.import.reason.exists'),
              ];
              $importSkippedDetails[] = $detail;
              $importSummary['classes'][$classId]['students_skipped']++;
              $importSummary['skipped'][] = $detail;
            }
            continue;
          }

          $master = find_master_student_id($pdo, $first, $last, $dob);
          $insertStudent->execute([$master, $classId, $first, $last, $dob]);
          $newId = (int)$pdo->lastInsertId();
          if (!$master) {
            $setSelfMaster->execute([$newId, $newId]);
          }
          if ($emailStudent !== null || $emailParent1 !== null || $emailParent2 !== null) {
            $pdo->prepare(
              "UPDATE students SET email_student=?, email_parent1=?, email_parent2=? WHERE id=?"
            )->execute([$emailStudent, $emailParent1, $emailParent2, $newId]);
          }
          if ($master) {
            $copiedCustom = copy_student_custom_values($pdo, $master, $newId);
            if (!$copiedCustom) save_student_custom_values($pdo, $newId, [], true);
          } else {
            save_student_custom_values($pdo, $newId, [], true);
          }
          $createdStudents++;
          $importSummary['classes'][$classId]['students_created']++;
          $importSummary['students'][] = [
            'class_id' => $classId,
            'name' => trim($first . ' ' . $last),
            'status' => t('admin.students.import.status.created'),
          ];
        }
      }

      $pdo->commit();

      $importSummary['stats'] = [
        'files' => count($importSummary['files']),
        'classes_created' => $createdClasses,
        'students_created' => $createdStudents,
        'students_updated' => $updatedStudents,
        'skipped' => $skipped,
      ];
      $importSummary['classes'] = array_values($importSummary['classes']);
      $_SESSION['admin_import_summary'] = $importSummary;

      audit('admin_students_import_csv', $userId, [
        'school_year' => $schoolYear,
        'classes_created' => $createdClasses,
        'students_created' => $createdStudents,
        'students_updated' => $updatedStudents,
        'skipped' => $skipped
      ]);
      $ok = str_replace(
        ['{classes}', '{created}', '{updated}', '{skipped}'],
        [(string)$createdClasses, (string)$createdStudents, (string)$updatedStudents, (string)$skipped],
        t('admin.students.status.import_summary')
      );
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$schoolYear = trim((string)($_GET['school_year'] ?? ''));
$classId = (int)($_GET['class_id'] ?? 0);

$sort = (string)($_GET['sort'] ?? 'name');
$allowedSort = ['name','class','year','created'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';

$orderSql = match($sort) {
  'class' => "c.school_year DESC, c.grade_level DESC, c.label ASC, s.last_name ASC, s.first_name ASC",
  'year'  => "c.school_year DESC, s.last_name ASC, s.first_name ASC",
  'created' => "s.created_at DESC",
  default => "s.last_name ASC, s.first_name ASC, c.school_year DESC"
};

$params = [];
$where = "WHERE 1=1";
$subParams = [];
$whereSub = "WHERE 1=1";
if ($q !== '') {
  $where .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.external_ref LIKE ?)";
  $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%";
  $whereSub .= " AND (s2.first_name LIKE ? OR s2.last_name LIKE ? OR s2.external_ref LIKE ?)";
  $subParams[] = "%{$q}%"; $subParams[] = "%{$q}%"; $subParams[] = "%{$q}%";
}
if ($schoolYear !== '') {
  $where .= " AND c.school_year = ?";
  $params[] = $schoolYear;
  $whereSub .= " AND c2.school_year = ?";
  $subParams[] = $schoolYear;
}
if ($classId > 0) {
  $where .= " AND s.class_id = ?";
  $params[] = $classId;
  $whereSub .= " AND s2.class_id = ?";
  $subParams[] = $classId;
}

$paramsSql = array_merge($subParams, $params);

$st = $pdo->prepare(
  "SELECT s.id, s.master_student_id, s.first_name, s.last_name, s.date_of_birth, s.external_ref, s.is_active,
          s.created_at,
          c.id AS class_id, c.school_year, c.period_label, c.grade_level, c.label, c.name AS class_name, c.is_active AS class_active
   FROM students s
   INNER JOIN (
     SELECT MAX(s2.id) AS id
     FROM students s2
     LEFT JOIN classes c2 ON c2.id=s2.class_id
     $whereSub
     GROUP BY CASE WHEN s2.master_student_id IS NULL OR s2.master_student_id=0 THEN s2.id ELSE s2.master_student_id END
   ) sm ON sm.id = s.id
   LEFT JOIN classes c ON c.id=s.class_id
   $where
   ORDER BY $orderSql
   LIMIT 500"
);
$st->execute($paramsSql);
$students = $st->fetchAll(PDO::FETCH_ASSOC);

$masterIds = [];
foreach ($students as $s) {
  $mid = (int)($s['master_student_id'] ?? 0);
  if ($mid <= 0) $mid = (int)($s['id'] ?? 0);
  if ($mid > 0) $masterIds[] = $mid;
}
$deleteImpactMap = $masterIds ? load_delete_impact($pdo, $masterIds) : [];

// Filter dropdown data
$years = $pdo->query("SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$classes = $pdo->query("SELECT id, school_year, period_label, grade_level, label, name, is_active FROM classes ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$scopeRows = $pdo->query(
  "SELECT DISTINCT school_year, period_label
   FROM classes
   ORDER BY school_year ASC,
            CASE WHEN period_label='H2' THEN 2 ELSE 1 END ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$absenceScopeOptions = [];
foreach ($scopeRows as $row) {
  $sy = trim((string)($row['school_year'] ?? ''));
  if ($sy === '') continue;
  $pl = normalize_class_period_label((string)($row['period_label'] ?? 'H1'));
  $absenceScopeOptions[] = [
    'school_year' => $sy,
    'period_label' => $pl,
    'key' => $sy . '|' . $pl,
  ];
}

$activeAbsenceScope = null;
$stActiveScope = $pdo->query(
  "SELECT school_year, period_label
   FROM classes
   WHERE is_active=1
   GROUP BY school_year, period_label
   ORDER BY COUNT(*) DESC, school_year DESC,
            CASE period_label WHEN 'H2' THEN 2 ELSE 1 END ASC
   LIMIT 1"
);
if ($stActiveScope) {
  $activeAbsenceScope = $stActiveScope->fetch(PDO::FETCH_ASSOC) ?: null;
}
$activeAbsenceScopeKey = '';
if ($activeAbsenceScope) {
  $activeAbsenceScopeKey = trim((string)($activeAbsenceScope['school_year'] ?? '')) . '|' . normalize_class_period_label((string)($activeAbsenceScope['period_label'] ?? 'H1'));
}
if ($activeAbsenceScopeKey === '' && $defaultSchoolYear !== '') {
  $activeAbsenceScopeKey = $defaultSchoolYear . '|H1';
}
if ($activeAbsenceScopeKey !== '') {
  $exists = false;
  foreach ($absenceScopeOptions as $opt) {
    if ((string)($opt['key'] ?? '') === $activeAbsenceScopeKey) { $exists = true; break; }
  }
  if (!$exists) {
    [$syFallback, $plFallback] = parse_school_scope_key($activeAbsenceScopeKey);
    if ($syFallback !== '') {
      array_unshift($absenceScopeOptions, [
        'school_year' => $syFallback,
        'period_label' => $plFallback,
        'key' => $syFallback . '|' . $plFallback,
      ]);
    }
  }
}
$templates = $pdo->query(
  "SELECT id, name, template_version, is_active
   FROM templates
   ORDER BY is_active DESC, template_version DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$importClassTemplateMap = [];
if ($importSummary && !empty($importSummary['classes'])) {
  $importClassIds = array_values(array_filter(array_map(
    static fn($c)=> (int)($c['class_id'] ?? 0),
    $importSummary['classes']
  ), fn($x)=>$x>0));
  if ($importClassIds) {
    $in = implode(',', array_fill(0, count($importClassIds), '?'));
    $st = $pdo->prepare("SELECT id, template_id FROM classes WHERE id IN ($in)");
    $st->execute($importClassIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $importClassTemplateMap[(int)$row['id']] = (int)($row['template_id'] ?? 0);
    }
  }
}

render_admin_header(t('admin.students.title'));
?>

<div class="card">
  <h1><?=h(t('admin.students.heading'))?></h1>
</div>



<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>
<?php if ($absenceImportSummary): ?>
  <div class="alert success">
    <div><strong><?=h(t('admin.students.import.absence.summary_heading', 'Fehltage-Import Zusammenfassung'))?></strong></div>
    <div class="muted" style="margin-top:6px;">
      <?=h(strtr(t('admin.students.import.absence.report_stats', 'Verarbeitet: {processed} · Übernommen: {updated} · echte 0-Werte: {zero} · übersprungen: {skipped} · externe Schüler ignoriert: {ignored_external} · mehrdeutig: {ambiguous} · ungültige Werte: {invalid_values} · manuell zu prüfen: {manual} · manuell zugeordnet: {manual_assigned} · Trennzeichen: {delimiter}'), [
        '{processed}' => (string)($absenceImportSummary['processed'] ?? 0),
        '{updated}' => (string)($absenceImportSummary['updated'] ?? 0),
        '{zero}' => (string)($absenceImportSummary['imported_zero'] ?? 0),
        '{skipped}' => (string)($absenceImportSummary['skipped'] ?? 0),
        '{not_found}' => (string)($absenceImportSummary['not_found'] ?? 0),
        '{ambiguous}' => (string)($absenceImportSummary['ambiguous'] ?? 0),
        '{invalid_values}' => (string)($absenceImportSummary['invalid_values'] ?? 0),
        '{manual}' => (string)($absenceImportSummary['manual_required'] ?? 0),
        '{manual_assigned}' => (string)($absenceImportSummary['manual_assigned'] ?? 0),
        '{ignored_external}' => (string)($absenceImportSummary['ignored_external'] ?? 0),
        '{delimiter}' => (string)($absenceImportSummary['delimiter'] ?? ''),
      ]))?>
    </div>
    <?php $details = (array)($absenceImportSummary['details'] ?? []); if ($details): ?>
      <details style="margin-top:10px;">
        <summary class="btn secondary" style="display:inline-block; cursor:pointer;"><?=h(t('admin.students.import.absence.problem_rows', 'Problematische Zeilen anzeigen'))?></summary>
        <table class="table" style="margin-top:8px;">
          <thead><tr><th><?=h(t('admin.students.import.absence.line', 'Zeile'))?></th><th><?=h(t('admin.students.import.table.name', 'Name'))?></th><th><?=h(t('admin.students.import.table.class', 'Klasse'))?></th><th><?=h(t('admin.students.import.absence.raw_value', 'Rohwert'))?></th><th><?=h(t('admin.students.import.table.status', 'Status'))?></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($details, 0, 100) as $d): ?>
            <tr>
              <td><?=h((string)($d['line'] ?? ''))?></td>
              <td><?=h((string)($d['name'] ?? ''))?></td>
              <td><?=h((string)($d['class'] ?? ''))?></td>
              <td><?=h((string)($d['value'] ?? ''))?></td>
              <td><?=h((string)($d['reason'] ?? ''))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
    <div class="grid" style="grid-template-columns:1fr 1fr; gap:12px; margin-top:8px;">
      <div>
        <div><strong><?=h(t('admin.students.import.absence.found_classes', 'Klassen im Import gefunden'))?></strong></div>
        <?php $fc = (array)($absenceImportSummary['found_classes'] ?? []); ?>
        <?php if (!$fc): ?><div class="muted">—</div><?php else: ?><ul style="margin:4px 0 0 18px;"><?php foreach ($fc as $cn): ?><li><?=h((string)$cn)?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
      <div>
        <div><strong><?=h(t('admin.students.import.absence.missing_classes', 'Klassen ohne Importdaten'))?></strong></div>
        <?php $mc = (array)($absenceImportSummary['missing_classes'] ?? []); ?>
        <?php if (!$mc): ?><div class="muted">—</div><?php else: ?><ul style="margin:4px 0 0 18px;"><?php foreach ($mc as $cn): ?><li><?=h((string)$cn)?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($importSummary): ?>
  <?php
    $summaryStats = $importSummary['stats'] ?? [];
    $summaryClasses = $importSummary['classes'] ?? [];
    $summaryStudents = $importSummary['students'] ?? [];
    $summarySkipped = $importSummary['skipped'] ?? [];
  ?>
  <div class="card">
    <h2 style="margin-top:0;"><?=h(t('admin.students.import.summary_heading'))?></h2>
    <div class="muted" style="margin-bottom:10px;">
      <?=h(str_replace(
        ['{files}', '{classes}', '{created}', '{updated}', '{skipped}'],
        [
          (string)($summaryStats['files'] ?? 0),
          (string)($summaryStats['classes_created'] ?? 0),
          (string)($summaryStats['students_created'] ?? 0),
          (string)($summaryStats['students_updated'] ?? 0),
          (string)($summaryStats['skipped'] ?? 0),
        ],
        t('admin.students.import.summary_line')
      ))?>
    </div>

    <?php if ($summaryClasses): ?>
      <form method="post" class="stack" style="margin-bottom:16px;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="update_import_templates">
        <table class="table">
          <thead>
            <tr>
              <th><?=h(t('admin.students.import.table.class'))?></th>
              <th><?=h(t('admin.students.import.table.year'))?></th>
              <th><?=h(t('admin.students.import.table.created'))?></th>
              <th><?=h(t('admin.students.import.table.updated'))?></th>
              <th><?=h(t('admin.students.import.table.skipped'))?></th>
              <th><?=h(t('admin.students.import.table.template'))?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summaryClasses as $c): ?>
              <?php
                $cid = (int)($c['class_id'] ?? 0);
                $classLabel = ((int)($c['grade_level'] ?? 0)) . (string)($c['label'] ?? '');
                $currentTemplate = (int)($importClassTemplateMap[$cid] ?? 0);
              ?>
              <tr>
                <td><?=h($classLabel)?></td>
                <td><?=h((string)($c['school_year'] ?? ''))?></td>
                <td><?=h((string)($c['students_created'] ?? 0))?></td>
                <td><?=h((string)($c['students_updated'] ?? 0))?></td>
                <td><?=h((string)($c['students_skipped'] ?? 0))?></td>
                <td>
                  <select name="class_template[<?=h((string)$cid)?>]">
                    <option value="0"><?=h(t('admin.students.import.template_none'))?></option>
                    <?php foreach ($templates as $tpl): ?>
                      <?php $tplId = (int)($tpl['id'] ?? 0); ?>
                      <option value="<?=h((string)$tplId)?>" <?=($tplId === $currentTemplate) ? 'selected' : ''?>>
                        <?=h((string)($tpl['name'] ?? ''))?>
                        <?=((int)($tpl['template_version'] ?? 0) > 0) ? ' v' . h((string)$tpl['template_version']) : ''?>
                        <?=((int)($tpl['is_active'] ?? 0) === 0) ? h(t('admin.students.filter.inactive_suffix')) : ''?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="actions" style="justify-content:flex-start;">
          <button class="btn" type="submit"><?=h(t('admin.students.import.save_templates'))?></button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($summaryStudents): ?>
      <details>
        <summary class="btn secondary" style="display:inline-block; cursor:pointer;"><?=h(t('admin.students.import.show_imported'))?></summary>
        <div class="panel" style="margin-top:10px;">
          <table class="table">
            <thead>
              <tr>
                <th><?=h(t('admin.students.import.table.class'))?></th>
                <th><?=h(t('admin.students.import.table.name'))?></th>
                <th><?=h(t('admin.students.import.table.status'))?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summaryStudents as $s): ?>
                <?php
                  $cid = (int)($s['class_id'] ?? 0);
                  $classLabel = '';
                  foreach ($summaryClasses as $c) {
                    if ((int)($c['class_id'] ?? 0) === $cid) {
                      $classLabel = ((int)($c['grade_level'] ?? 0)) . (string)($c['label'] ?? '');
                      break;
                    }
                  }
                ?>
                <tr>
                  <td><?=h($classLabel)?></td>
                  <td><?=h((string)($s['name'] ?? ''))?></td>
                  <td><?=h((string)($s['status'] ?? ''))?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>

    <?php if ($summarySkipped): ?>
      <details style="margin-top:12px;">
        <summary class="btn secondary" style="display:inline-block; cursor:pointer;"><?=h(t('admin.students.import.show_skipped'))?></summary>
        <div class="panel" style="margin-top:10px;">
          <ul style="margin:8px 0 0 18px;">
            <?php foreach ($summarySkipped as $detail): ?>
              <li><strong><?=h((string)($detail['name'] ?? ''))?></strong>: <?=h((string)($detail['reason'] ?? ''))?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.students.import.absence_heading', 'Fehltage-Import (CSV, tab-getrennt)'))?></h2>
  <p class="muted"><?=h(t('admin.students.import.absence_hint', 'Importiert Fehltage für ein Schulhalbjahr für bestehende Klassen und Schüler. Spalten-Standard: Klasse=1, Schüler=2, Fehltage gesamt=15, unentschuldigt=16. Leere/ungültige Werte und nicht eindeutige Namen werden übersprungen; bestehende Werte bleiben erhalten.'))?></p>
  <form method="post" enctype="multipart/form-data" class="grid" id="absenceImportForm" style="grid-template-columns: 260px minmax(260px,1fr) auto; gap:10px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_absence_csv">

    <div>
      <label><?=h(t('admin.students.import.absence_scope', 'Schuljahr/Halbjahr'))?></label>
      <select name="absence_scope" id="absenceScopeSelect" required>
        <?php if (!$absenceScopeOptions): ?>
          <option value="" selected disabled><?=h(t('admin.students.import.select_year'))?></option>
        <?php else: ?>
          <?php foreach ($absenceScopeOptions as $scope): ?>
            <?php
              $scopeYear = (string)($scope['school_year'] ?? '');
              $scopePeriod = normalize_class_period_label((string)($scope['period_label'] ?? 'H1'));
              $scopeKey = (string)($scope['key'] ?? ($scopeYear . '|' . $scopePeriod));
              $scopeLabel = $scopeYear . ' · ' . period_label_display_admin($scopePeriod);
            ?>
            <option value="<?=h($scopeKey)?>" <?=$scopeKey === $activeAbsenceScopeKey ? 'selected' : ''?>><?=h($scopeLabel)?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>

    <div>
      <label><?=h(t('admin.students.import.csv_label'))?></label>
      <input type="file" id="absenceCsvFile" name="absence_csv_file" accept=".csv,text/csv,text/tab-separated-values" required>
    </div>

    <div class="actions" style="justify-content:flex-start;">
      <button class="btn" type="submit"><?=h(t('admin.students.import.start'))?></button>
    </div>

    <div style="grid-column:1 / -1;">
      <div class="grid" style="grid-template-columns:repeat(4, minmax(90px, 120px)); gap:10px; align-items:end;">
        <div>
          <label><?=h(t('admin.students.import.absence_col_class', 'Spalte Klasse'))?></label>
          <input class="input" type="number" min="1" step="1" name="absence_col_class" id="absenceColClass" style="max-width:84px;" value="1" required>
        </div>
        <div>
          <label><?=h(t('admin.students.import.absence_col_student', 'Spalte Schüler'))?></label>
          <input class="input" type="number" min="1" step="1" name="absence_col_student" id="absenceColStudent" style="max-width:84px;" value="2" required>
        </div>
        <div>
          <label><?=h(t('admin.students.import.absence_col_total', 'Spalte Fehltage gesamt'))?></label>
          <input class="input" type="number" min="1" step="1" name="absence_col_total" id="absenceColTotal" style="max-width:84px;" value="15" required>
        </div>
        <div>
          <label><?=h(t('admin.students.import.absence_col_unexcused', 'Spalte Fehltage unentschuldigt'))?></label>
          <input class="input" type="number" min="1" step="1" name="absence_col_unexcused" id="absenceColUnexcused" style="max-width:84px;" value="16" required>
        </div>
      </div>
    </div>
  </form>

  <div style="margin-top:10px;" id="absencePreviewWrap">
    <div class="muted" id="absencePreviewHint"><?=h(t('admin.students.import.absence_preview_hint', 'Beispiel-Extraktion aus den ersten Zeilen der Datei wird hier angezeigt.'))?></div>
    <div id="absencePreviewTable"></div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.students.import.blackbaud_heading'))?></h2>
  <p class="muted"><?=t('admin.students.import.blackbaud_hint')?></p>
  <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns: 220px 1fr auto; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_blackbaud_csv">
    <div>
      <label><?=h(t('admin.students.import.school_year'))?></label>
      <select name="school_year" required>
        <?php if ($defaultSchoolYear === ''): ?>
          <option value="" selected disabled><?=h(t('admin.students.import.select_year'))?></option>
        <?php else: ?>
          <option value="<?=h($defaultSchoolYear)?>" selected><?=h($defaultSchoolYear)?> <?=h(t('admin.students.import.default_year_suffix'))?></option>
        <?php endif; ?>
        <?php foreach ($years as $y): ?>
          <?php if ((string)$y === $defaultSchoolYear) continue; ?>
          <option value="<?=h((string)$y)?>"><?=h((string)$y)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label><?=h(t('admin.students.import.csv_label'))?></label>
      <input type="file" name="csv_file[]" accept=".csv,text/csv" multiple required>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn" type="submit"><?=h(t('admin.students.import.start'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <form method="get" class="grid" style="grid-template-columns: 1fr 160px 240px 160px auto; gap:12px; align-items:end;" id="student-filter-form">
    <div>
      <label><?=h(t('admin.students.filter.search_label'))?></label>
      <input name="q" type="text" value="<?=h($q)?>" placeholder="<?=h(t('admin.students.filter.search_placeholder'))?>">
    </div>
    <div>
      <label><?=h(t('admin.students.filter.school_year'))?></label>
      <select name="school_year">
        <option value=""><?=h(t('admin.students.filter.all_years'))?></option>
        <?php foreach ($years as $y): ?>
          <option value="<?=h((string)$y)?>" <?=($schoolYear===(string)$y)?'selected':''?>><?=h((string)$y)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label><?=h(t('admin.students.filter.class'))?></label>
      <select name="class_id">
        <option value="0"><?=h(t('admin.students.filter.all_classes'))?></option>
        <?php foreach ($classes as $c): ?>
          <option value="<?=h((string)$c['id'])?>" <?=($classId===(int)$c['id'])?'selected':''?>>
            <?=h((string)$c['school_year'])?> · <?=h(period_label_display_admin($c['period_label'] ?? 'Standard'))?> · <?=h(((int)$c['grade_level']).(string)$c['label'])?><?=((int)$c['is_active']===0)?h(t('admin.students.filter.inactive_suffix')):''?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label><?=h(t('admin.students.filter.sort'))?></label>
      <select name="sort">
        <option value="name" <?=($sort==='name')?'selected':''?>><?=h(t('admin.students.sort.name'))?></option>
        <option value="class" <?=($sort==='class')?'selected':''?>><?=h(t('admin.students.sort.class'))?></option>
        <option value="year" <?=($sort==='year')?'selected':''?>><?=h(t('admin.students.sort.year'))?></option>
        <option value="created" <?=($sort==='created')?'selected':''?>><?=h(t('admin.students.sort.created'))?></option>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start; align-items:center; gap:8px;">
      <a class="btn secondary" href="<?=h(url('admin/students.php'))?>"><?=h(t('admin.students.filter.reset'))?></a>
    </div>
  </form>

  <div class="muted" style="margin-top:10px;"><?=h(t('admin.students.filter.limit_hint'))?></div>
</div>

<script>
  (function() {
    const form = document.getElementById('student-filter-form');
    if (!form) return;

    const focusStorageKey = 'student_filter_focus';
    const saveFocus = () => {
      const active = document.activeElement;
      if (!active) return;
      const name = active.getAttribute('name');
      if (!name) return;
      try {
        sessionStorage.setItem(focusStorageKey, name);
      } catch (e) {
        // ignore storage issues
      }
    };

    const restoreFocus = () => {
      let name;
      try {
        name = sessionStorage.getItem(focusStorageKey);
        sessionStorage.removeItem(focusStorageKey);
      } catch (e) {
        return;
      }
      if (!name) return;
      const el = form.querySelector(`[name="${name}"]`);
      if (el && typeof el.focus === 'function') {
        el.focus({ preventScroll: true });
        
        if (typeof el.setSelectionRange === 'function') {
            const len = el.value?.length ?? 0;
            el.setSelectionRange(len, len);
          }
      }
    };

    restoreFocus();

    const submitForm = () => {
      saveFocus();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    };

    let debounceTimer;
    const qInput = form.querySelector('input[name="q"]');
    if (qInput) {
      qInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(submitForm, 300);
      });
    }

    form.querySelectorAll('select').forEach((sel) => {
      sel.addEventListener('change', submitForm);
    });
  })();
</script>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.students.list_heading'))?></h2>

  <?php if (!$students): ?>
    <div class="alert"><?=h(t('admin.students.list_empty'))?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?=h(t('admin.students.table.name'))?></th>
          <th><?=h(t('admin.students.table.dob'))?></th>
          <th><?=h(t('admin.students.table.class'))?></th>
          <th><?=h(t('admin.students.table.year'))?></th>
          <th><?=h(t('admin.students.table.active'))?></th>
          <th><?=h(t('admin.students.table.actions'))?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?=h((string)$s['last_name'])?>, <?=h((string)$s['first_name'])?></td>
          <td><?=h((string)($s['date_of_birth'] ?? ''))?></td>
          <td><?=h(class_display($s))?></td>
          <td><?=h((string)($s['school_year'] ?? t('admin.students.table.no_year')))?></td>
          <td>
            <?=((int)$s['is_active']===1) ? '<span class="badge">' . h(t('admin.students.badge.yes')) . '</span>' : '<span class="badge">' . h(t('admin.students.badge.no')) . '</span>'?>
            <?=((int)($s['class_active'] ?? 1)===0) ? ' <span class="badge">' . h(t('admin.students.badge.class_inactive')) . '</span>' : ''?>
          </td>
          <td>
            <details>
              <summary class="btn secondary" style="display:inline-block; cursor:pointer;"><?=h(t('admin.students.action.manage'))?></summary>
              <div class="panel" style="margin-top:10px;">
                <?php
                  $must = (string)$s['last_name'] . ', ' . (string)$s['first_name'];
                  $mid = (int)($s['master_student_id'] ?? 0);
                  if ($mid <= 0) $mid = (int)($s['id'] ?? 0);
                  $impact = $deleteImpactMap[$mid] ?? ['students'=>1,'reports'=>0,'values'=>0];
                  $studentNote = ((int)$impact['students'] > 1) ? t('admin.students.delete.related_suffix') : '';
                ?>
                <div class="muted">
                  <?=h(str_replace(
                    ['{students}', '{student_suffix}', '{reports}', '{values}'],
                    [(string)$impact['students'], $studentNote, (string)$impact['reports'], (string)$impact['values']],
                    t('admin.students.delete.summary')
                  ))?>
                </div>
                <div class="muted"><?=h(t('admin.students.delete.confirm_with'))?> <code><?=h($must)?></code></div>
                <form method="post" onsubmit="return confirm('<?=h(t('admin.students.delete.confirm_final'))?>');" class="grid" style="grid-template-columns: 1fr auto; gap:10px; align-items:end; margin-top:10px;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete_student">
                  <input type="hidden" name="student_id" value="<?=h((string)$s['id'])?>">
                  <input type="hidden" name="must_match" value="<?=h($must)?>">
                  <div>
                    <label><?=h(t('admin.students.delete.confirm_label'))?></label>
                    <input name="confirm_text" type="text" placeholder="<?=h($must)?>" required>
                  </div>
                  <div class="actions" style="justify-content:flex-start;">
                    <button class="btn danger" type="submit"><?=h(t('admin.students.delete.action'))?></button>
                  </div>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>


<script>
(function(){
  const fileInput = document.getElementById('absenceCsvFile');
  const wrap = document.getElementById('absencePreviewTable');
  const hint = document.getElementById('absencePreviewHint');
  const colClass = document.getElementById('absenceColClass');
  const colStudent = document.getElementById('absenceColStudent');
  const colTotal = document.getElementById('absenceColTotal');
  const colUnexcused = document.getElementById('absenceColUnexcused');
  const scopeSelect = document.getElementById('absenceScopeSelect');
  const importForm = document.getElementById('absenceImportForm');
  if (!fileInput || !wrap) return;

  const previewApiUrl = <?= json_encode($absencePreviewApiUrl) ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const labels = {
    previewUnavailable: <?= json_encode(t('admin.students.import.absence.preview_unavailable', 'Preview-API ist nicht verfügbar.')) ?>,
    class: <?= json_encode(t('admin.students.import.table.class', 'Klasse')) ?>,
    student: <?= json_encode(t('admin.students.import.table.name', 'Name')) ?>,
    total: <?= json_encode(t('admin.students.import.absence.col_total', 'Fehltage gesamt')) ?>,
    unexcused: <?= json_encode(t('admin.students.import.absence.col_unexcused', 'Fehltage unentschuldigt')) ?>,
    status: <?= json_encode(t('admin.students.import.table.status', 'Status')) ?>,
    noMatches: <?= json_encode(t('admin.students.import.absence.preview_no_matches', 'Keine passenden System-Schüler in den ersten Zeilen gefunden.')) ?>,
    suggestions: <?= json_encode(t('admin.students.import.absence.preview_suggestions', 'Vorschläge')) ?>,
    manualAssign: <?= json_encode(t('admin.students.import.absence.manual_assign', 'Manuell zuordnen')) ?>,
    previewFailed: <?= json_encode(t('admin.students.import.absence.preview_failed', 'Preview fehlgeschlagen')) ?>,
    stats: <?= json_encode(t('admin.students.import.absence.preview_stats', 'Gefundene System-Schüler: {matched} · Übersprungen: {skipped}')) ?>,
  };
  if (!previewApiUrl || typeof previewApiUrl !== 'string') {
    wrap.innerHTML = `<div class="muted" style="margin-top:8px;">${esc(labels.previewUnavailable)}</div>`;
    if (hint) hint.style.display = 'none';
    return;
  }
  let parsed = [];

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function idx(el, d){ const n = Number(el?.value || d); return Number.isFinite(n) && n > 0 ? Math.floor(n)-1 : d-1; }

  function selectedScope(){
    const raw = String(scopeSelect?.value || '');
    const parts = raw.split('|');
    return {
      schoolYear: (parts[0] || '').trim(),
      periodLabel: ((parts[1] || 'H1').trim() || 'H1')
    };
  }

  function extractRows(){
    const ci = idx(colClass,1), si = idx(colStudent,2), ti = idx(colTotal,15), ui = idx(colUnexcused,16);
    return parsed.slice(0,200).map((r, index) => ({
      line: index,
      class: r[ci] ?? '',
      student: r[si] ?? '',
      total: r[ti] ?? '',
      unexcused: r[ui] ?? '',
    }));
  }


  function selectedHeaders(){
    if (!parsed.length) return null;
    const header = Array.isArray(parsed[0]) ? parsed[0] : [];
    const ci = idx(colClass,1), si = idx(colStudent,2), ti = idx(colTotal,15), ui = idx(colUnexcused,16);
    return {
      class: String(header[ci] ?? '').trim(),
      student: String(header[si] ?? '').trim(),
      total: String(header[ti] ?? '').trim(),
      unexcused: String(header[ui] ?? '').trim(),
    };
  }

  function renderRows(rows, matched, skipped){
    const hdr = selectedHeaders();
    const csvHeaderRow = hdr
      ? `<tr><th class="muted">${esc(hdr.class || '—')}</th><th class="muted">${esc(hdr.student || '—')}</th><th class="muted">${esc(hdr.total || '—')}</th><th class="muted">${esc(hdr.unexcused || '—')}</th></tr>`
      : '';
    const thead = `<thead><tr><th>${esc(labels.class)}</th><th>${esc(labels.student)}</th><th>${esc(labels.total)}</th><th>${esc(labels.unexcused)}</th><th>${esc(labels.status)}</th></tr>${csvHeaderRow}</thead>`;
    if (!rows.length) {
      wrap.innerHTML = `<table class="table" style="margin-top:8px;">${thead}<tbody></tbody></table><div class="muted" style="margin-top:8px;">${esc(labels.noMatches)}</div>`;
      if (hint) hint.style.display = 'none';
      return;
    }
    const trs = rows.map(r => {
      const suggestions = Array.isArray(r.suggestions) ? r.suggestions.filter(s => Number(s?.id || 0) > 0) : [];
      const suggestionText = suggestions.length ? ` · ${esc(labels.suggestions + ': ' + suggestions.map(s => s.name || '').join('; '))}` : '';
      const manualSelect = suggestions.length && String(r.status || '') !== 'matched'
        ? `<div style="margin-top:4px;"><label class="muted">${esc(labels.manualAssign)}</label> <select class="absence-manual-map" data-line="${esc(r.line)}"><option value=""></option>${suggestions.map(s => `<option value="${esc(s.id)}">${esc(s.name || '')}</option>`).join('')}</select></div>`
        : '';
      const ok = String(r.status || '') === 'matched';
      return `<tr style="${ok ? '' : 'background:#fff7ed;'}"><td>${esc(r.class)}</td><td>${esc(r.student)}</td><td>${esc(r.total)}</td><td>${esc(r.unexcused)}</td><td>${esc(r.status || '')}${r.message ? ` – ${esc(r.message)}` : ''}${suggestionText}${manualSelect}</td></tr>`;
    }).join('');
    const stats = labels.stats.replace('{matched}', String(matched)).replace('{skipped}', String(skipped));
    wrap.innerHTML = `<div class="muted" style="margin:6px 0;">${esc(stats)}</div><table class="table" style="margin-top:8px;">${thead}<tbody>${trs}</tbody></table>`;
    wrap.querySelectorAll('.absence-manual-map').forEach(sel => sel.addEventListener('change', syncManualMap));
    if (hint) hint.style.display = 'none';
  }

  function syncManualMap(ev) {
    if (!importForm) return;
    const sel = ev.currentTarget;
    const line = String(sel?.dataset?.line ?? '');
    if (line === '') return;
    const inputName = `absence_manual_student_id[${line}]`;
    const hiddenId = `absenceManualMap_${line}`;
    let hidden = document.getElementById(hiddenId);
    if (!sel.value) {
      hidden?.remove();
      return;
    }
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.id = hiddenId;
      hidden.name = inputName;
      importForm.appendChild(hidden);
    }
    hidden.value = sel.value;
  }

  function clearManualMaps() {
    importForm?.querySelectorAll('input[id^="absenceManualMap_"]').forEach(input => input.remove());
  }

  function handlePreviewSourceChange() {
    clearManualMaps();
    refreshPreview();
  }

  async function refreshPreview(){
    if (!parsed.length) { wrap.innerHTML=''; if (hint) hint.style.display=''; return; }
    const payload = {
      csrf_token: csrf,
      school_year: selectedScope().schoolYear,
      period_label: selectedScope().periodLabel,
      rows: extractRows(),
    };
    try {
      const resp = await fetch(previewApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(payload)
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok || !data.ok) throw new Error(data.error || labels.previewFailed);
      renderRows(Array.isArray(data.preview) ? data.preview : [], Number(data.matched||0), Number(data.skipped||0));
    } catch (e) {
      wrap.innerHTML = `<div class="muted" style="margin-top:8px;">${esc(String(e?.message || e))}</div>`;
      if (hint) hint.style.display = 'none';
    }
  }

  async function parseFile(){
    const f = fileInput.files?.[0];
    parsed = [];
    clearManualMaps();
    if (!f) { wrap.innerHTML=''; if (hint) hint.style.display=''; return; }
    const txt = await f.text();
    const normalized = String(txt).split('\r').join('');
    const firstLine = normalized.split('\n').find(l => l.trim() !== '') || '';
    const delimiter = ['\t',';',','].sort((a,b) => firstLine.split(b).length - firstLine.split(a).length)[0] || '\t';
    parsed = normalized
      .split('\n')
      .map((l) => parseCsvLine(l, delimiter))
      .filter((r) => r.some((c) => String(c).trim() !== ''));
    await refreshPreview();
  }

  function parseCsvLine(line, delimiter) {
    const out = [];
    let cur = '';
    let quoted = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (ch === '"') {
        if (quoted && line[i + 1] === '"') { cur += '"'; i++; }
        else quoted = !quoted;
      } else if (ch === delimiter && !quoted) {
        out.push(cur);
        cur = '';
      } else {
        cur += ch;
      }
    }
    out.push(cur);
    return out;
  }

  fileInput.addEventListener('change', parseFile);
  [colClass,colStudent,colTotal,colUnexcused,scopeSelect].forEach(el => el?.addEventListener('input', handlePreviewSourceChange));
})();
</script>

<?php render_admin_footer(); ?>
