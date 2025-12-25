<?php
// admin/ajax/export_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): never {
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
  $st = $pdo->prepare(
    "SELECT tf.field_name, fv.value_text
     FROM field_values fv
     JOIN template_fields tf ON tf.id=fv.template_field_id
     WHERE fv.report_instance_id=?
     ORDER BY fv.updated_at ASC, fv.id ASC"
  );
  $st->execute([$reportInstanceId]);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[(string)$r['field_name']] = $r['value_text'];
  }
  return $map;
}

function missing_fields_for_student(array $templateFields, array $values): array {
  $missing = [];
  foreach ($templateFields as $f) {
    if ((int)($f['is_required'] ?? 0) !== 1) continue;
    $name = (string)($f['field_name'] ?? '');
    if ($name === '') continue;
    $type = (string)($f['field_type'] ?? 'text');
    $val = $values[$name] ?? null;
    if ($type === 'checkbox') {
      if ((string)$val !== '1') $missing[] = $name;
    } else {
      $v = is_string($val) ? trim($val) : '';
      if ($v === '') $missing[] = $name;
    }
  }
  return $missing;
}

function load_students_for_export(PDO $pdo, int $classId, int $templateId, string $schoolYear, ?int $onlyStudentId, bool $onlySubmitted): array {
  $whereStudent = '';
  if ($onlyStudentId && $onlyStudentId > 0) $whereStudent = " AND s.id=? ";
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
  $execParams = [$templateId, $schoolYear, $classId];
  if ($onlyStudentId && $onlyStudentId > 0) $execParams[] = $onlyStudentId;
  $stmt->execute($execParams);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function compute_export_payload(PDO $pdo, int $classId, ?int $onlyStudentId, bool $includeValues, bool $onlySubmitted): array {
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
      apply_system_bindings($pdo, (int)$ri['id']);
      $values = load_values_for_report($pdo, (int)$ri['id']);
    }

    $missing = $includeValues ? missing_fields_for_student($tf, $values) : [];
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
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();

  $action = (string)($data['action'] ?? '');
  $classId = (int)($data['class_id'] ?? 0);
  if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

  $onlySubmitted = (int)($data['only_submitted'] ?? 0) === 1;

  if ($action === 'preview') {
    $payload = compute_export_payload($pdo, $classId, null, true, $onlySubmitted);
    json_out(['ok'=>true] + $payload);
  }

  if ($action === 'data') {
    $studentId = isset($data['student_id']) ? (int)$data['student_id'] : null;
    $payload = compute_export_payload($pdo, $classId, $studentId && $studentId>0 ? $studentId : null, true, $onlySubmitted);
    json_out(['ok'=>true] + $payload);
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
