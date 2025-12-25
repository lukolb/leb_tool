<?php
// shared/export_api_core.php
declare(strict_types=1);

/**
 * Shared Export API core.
 *
 * Wrappers must define:
 *   $EXPORT_ENFORCE_CLASS_ACCESS (bool)  // teacher: true, admin: false
 *   $EXPORT_USER_ID (int)               // teacher: current user id, admin: 0
 */

if (!isset($EXPORT_ENFORCE_CLASS_ACCESS)) $EXPORT_ENFORCE_CLASS_ACCESS = false;
if (!isset($EXPORT_USER_ID)) $EXPORT_USER_ID = 0;

header('Content-Type: application/json; charset=utf-8');

$DEBUG = (int)($_GET['debug'] ?? 0) === 1;

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ((string)$grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function enforce_class_access(PDO $pdo, int $classId, bool $enforce, int $userId): void {
  if (!$enforce) return;
  $u = current_user();
  if (($u['role'] ?? '') === 'admin') return;
  if ($userId <= 0) throw new RuntimeException('Keine Berechtigung.');
  if (!user_can_access_class($pdo, $userId, $classId)) throw new RuntimeException('Keine Berechtigung.');
}

function template_for_class(PDO $pdo, int $classId): array {
  $st = $pdo->prepare(
    "SELECT c.id AS class_id, c.school_year, c.grade_level, c.label, c.name AS class_name,
            t.id AS template_id, t.name AS template_name, t.template_version
     FROM classes c
     LEFT JOIN templates t ON t.id=c.template_id
     WHERE c.id=?
     LIMIT 1"
  );
  $st->execute([$classId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Klasse nicht gefunden.');
  $tplId = (int)($row['template_id'] ?? 0);
  if ($tplId <= 0) throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');

  $st2 = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
  $st2->execute([$tplId]);
  if ((int)$st2->fetchColumn() !== 1) throw new RuntimeException('Die zugeordnete Vorlage ist inaktiv.');

  return $row;
}

function find_report_instance(PDO $pdo, int $templateId, int $studentId, string $schoolYear): ?array {
  $st = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status
     FROM report_instances
     WHERE template_id=? AND student_id=? AND school_year=? AND period_label='Standard'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId, $schoolYear]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);
  if ($ri) return $ri;

  // fallback: newest instance regardless of period/year (just in case)
  $st2 = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status
     FROM report_instances
     WHERE template_id=? AND student_id=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st2->execute([$templateId, $studentId]);
  $ri2 = $st2->fetch(PDO::FETCH_ASSOC);
  return $ri2 ?: null;
}

function load_template_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, is_required, meta_json
     FROM template_fields
     WHERE template_id=?
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_values_for_report(PDO $pdo, int $reportInstanceId): array {
  // Resolve option-list values by stable option_item_id (stored in value_json) so exports survive option value changes.
  $st = $pdo->prepare(
    "SELECT tf.field_name, tf.meta_json, fv.value_text, fv.value_json
     FROM field_values fv
     JOIN template_fields tf ON tf.id=fv.template_field_id
     WHERE fv.report_instance_id=?
     ORDER BY fv.updated_at ASC, fv.id ASC"
  );
  $st->execute([$reportInstanceId]);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $meta = meta_read_export($r['meta_json'] ?? null);
    $valueText = $r['value_text'] !== null ? (string)$r['value_text'] : null;
    $valueJson = $r['value_json'] !== null ? (string)$r['value_json'] : null;
    $map[(string)$r['field_name']] = resolve_option_value_text_export($pdo, $meta, $valueJson, $valueText);
  }
  return $map;
}


function meta_read_export(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function option_list_id_from_meta_export(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function resolve_option_value_text_export(PDO $pdo, array $meta, ?string $valueJson, ?string $valueText): string {
  $listId = option_list_id_from_meta_export($meta);
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

  // fallback: by old value_text
  $vt = trim((string)($valueText ?? ''));
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }

  return (string)($valueText ?? '');
}

function is_class_field_export(array $meta): bool {
  // Admin marks class-wide fields via meta_json: {"scope":"class"}
  // (We also accept a legacy boolean flag if ever introduced.)
  if (isset($meta['scope']) && is_string($meta['scope']) && strtolower(trim($meta['scope'])) === 'class') return true;
  if (isset($meta['is_class_field']) && (int)$meta['is_class_field'] === 1) return true;
  return false;
}

function find_class_report_instance(PDO $pdo, int $templateId, string $schoolYear): ?int {
  // Class-wide values are stored in a dedicated report instance:
  // student_id = 0, period_label = '__class__', school_year = class school year.
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

function load_class_values(PDO $pdo, int $classReportInstanceId): array {
  // Returns field_name => value_text for the class report instance.
  return load_values_for_report($pdo, $classReportInstanceId);
}

function missing_fields_for_student(array $templateFields, array $values): array {
  // IMPORTANT: warn on ALL fields (not only required)
  $missing = [];
  foreach ($templateFields as $f) {
    $name = (string)($f['field_name'] ?? '');
    if ($name === '') continue;

    $type = (string)($f['field_type'] ?? 'text');
    $label = (string)($f['label'] ?? $name);
    $required = (int)($f['is_required'] ?? 0) === 1;

    $val = $values[$name] ?? null;

    $isEmpty = false;
    if ($type === 'checkbox') {
      $isEmpty = ((string)$val !== '1');
    } else {
      $v = is_string($val) ? trim($val) : '';
      $isEmpty = ($v === '');
    }

    if ($isEmpty) {
      $missing[] = [
        'field_name' => $name,
        'label' => $label,
        'field_type' => $type,
        'is_required' => $required ? 1 : 0,
      ];
    }
  }
  return $missing;
}

function load_students_for_export(PDO $pdo, int $classId, int $templateId, string $schoolYear, ?int $onlyStudentId, bool $onlySubmitted): array {
  $whereStudent = '';
  if ($onlyStudentId && $onlyStudentId > 0) $whereStudent = " AND s.id=? ";

  // onlySubmitted filter based on latest status join (LEFT JOIN report_instances)
  $whereSubmitted = $onlySubmitted ? " AND ri.status='submitted' " : "";

  $sql =
    "SELECT s.id, s.first_name, s.last_name, ri.status AS report_status
     FROM students s
     LEFT JOIN report_instances ri
       ON ri.student_id=s.id
      AND ri.template_id=?
      AND ri.school_year=?
      AND ri.period_label='Standard'
     WHERE s.class_id=? AND s.is_active=1
     $whereStudent
     $whereSubmitted
     ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC";

  $stmt = $pdo->prepare($sql);
  $params = [$templateId, $schoolYear, $classId];
  if ($onlyStudentId && $onlyStudentId > 0) $params[] = $onlyStudentId;
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function compute_export_payload(PDO $pdo, int $classId, ?int $onlyStudentId, bool $includeValues, bool $onlySubmitted, bool $enforceAccess, int $userId): array {
  enforce_class_access($pdo, $classId, $enforceAccess, $userId);

  $row = template_for_class($pdo, $classId);
  $templateId = (int)$row['template_id'];
  $schoolYear = (string)($row['school_year'] ?? '');

  $class = [
    'id' => (int)$row['class_id'],
    'school_year' => $schoolYear,
    'grade_level' => $row['grade_level'],
    'label' => $row['label'],
    'name' => $row['class_name'],
    'display' => class_display([
      'id'=>$row['class_id'],
      'school_year'=>$schoolYear,
      'grade_level'=>$row['grade_level'],
      'label'=>$row['label'],
      'name'=>$row['class_name']
    ]),
  ];

  $template = [
    'id' => $templateId,
    'name' => (string)($row['template_name'] ?? ''),
    'version' => (string)($row['template_version'] ?? ''),
  ];

  $students = load_students_for_export($pdo, $classId, $templateId, $schoolYear, $onlyStudentId, $onlySubmitted);
  $tf = load_template_fields($pdo, $templateId);

  // Determine which fields are class-wide
  $classFieldNames = [];
  foreach ($tf as $f) {
    $meta = meta_read_export($f['meta_json'] ?? null);
    if (is_class_field_export($meta)) {
      $n = (string)($f['field_name'] ?? '');
      if ($n !== '') $classFieldNames[$n] = true;
    }
  }

  // Load class-wide values once (and merge them into each student's values for export)
  $classValues = [];
  if ($includeValues && $classFieldNames) {
    $classRiId = find_class_report_instance($pdo, $templateId, $schoolYear);
    if ($classRiId) {
      $classValues = load_class_values($pdo, (int)$classRiId);
    }
  }

  $outStudents = [];
  $warnByStudent = [];
  $studentsWithMissing = 0;
  $totalMissing = 0;

  foreach ($students as $s) {
    $sid = (int)$s['id'];
    $name = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
    if ($name === '') $name = 'Schüler ' . $sid;

    $ri = find_report_instance($pdo, $templateId, $sid, $schoolYear);
    $values = [];
    $status = $ri ? (string)($ri['status'] ?? '') : ((string)($s['report_status'] ?? ''));

    if ($includeValues && $ri) {
      // Keep existing system bindings behavior
      if (function_exists('apply_system_bindings')) {
        apply_system_bindings($pdo, (int)$ri['id']);
      }
      $values = load_values_for_report($pdo, (int)$ri['id']);

      // Merge class-wide values (override per-student values for those fields)
      if ($classValues && $classFieldNames) {
        foreach ($classFieldNames as $fname => $_) {
          if (array_key_exists($fname, $classValues)) {
            $values[$fname] = (string)$classValues[$fname];
          }
        }
      }
    }

    // If no report instance exists, we still warn (everything missing), but label it compactly:
    $missing = [];
    if ($includeValues) {
      if ($ri) $missing = missing_fields_for_student($tf, $values);
      else {
        // show a single pseudo-entry instead of 200 fields
        $missing = [[
          'field_name' => '__no_report__',
          'label' => 'Noch keine Einträge vorhanden (Report existiert nicht)',
          'field_type' => 'info',
          'is_required' => 0
        ]];
      }
    }

    if ($missing) {
      $studentsWithMissing++;
      $totalMissing += count($missing);
      $warnByStudent[] = ['student_id'=>$sid, 'student_name'=>$name, 'missing_fields'=>$missing];
    }

    $entry = ['id'=>$sid, 'name'=>$name, 'report_status'=>$status];
    if ($includeValues) $entry['values'] = $values;
    $outStudents[] = $entry;
  }

  return [
    'class' => $class,
    'template' => $template,
    'pdf_url' => url('template_file.php?class_id=' . (int)$classId),
    'students' => $outStudents,
    'only_submitted' => $onlySubmitted,
    'warnings_summary' => [
      'students_with_missing' => $studentsWithMissing,
      'total_missing' => $totalMissing,
      'by_student' => $warnByStudent,
    ],
  ];
}

try {
  $data = read_json_body();

  // CSRF: Header oder JSON-field
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();

  $action = (string)($data['action'] ?? '');
  $classId = (int)($data['class_id'] ?? 0);
  if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

  $onlySubmitted = (int)($data['only_submitted'] ?? 0) === 1;

  if ($action === 'preview') {
    // ✅ NEW: allow preview for a single student when UI is in "single" mode
    $studentId = isset($data['student_id']) ? (int)$data['student_id'] : null;

    $payload = compute_export_payload(
      $pdo,
      $classId,
      ($studentId && $studentId > 0) ? $studentId : null,
      true,
      $onlySubmitted,
      (bool)$GLOBALS['EXPORT_ENFORCE_CLASS_ACCESS'],
      (int)$GLOBALS['EXPORT_USER_ID']
    );
    json_out(['ok'=>true] + $payload, 200);
  }

  if ($action === 'data') {
    $studentId = isset($data['student_id']) ? (int)$data['student_id'] : null;
    $payload = compute_export_payload(
      $pdo,
      $classId,
      ($studentId && $studentId > 0) ? $studentId : null,
      true,
      $onlySubmitted,
      (bool)$GLOBALS['EXPORT_ENFORCE_CLASS_ACCESS'],
      (int)$GLOBALS['EXPORT_USER_ID']
    );
    json_out(['ok'=>true] + $payload, 200);
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  $out = ['ok' => false, 'error' => $e->getMessage()];
  if ($DEBUG) {
    $out['type'] = get_class($e);
    $out['file'] = $e->getFile();
    $out['line'] = $e->getLine();
    $out['trace'] = explode("\n", $e->getTraceAsString());
  }
  json_out($out, 400);
}
