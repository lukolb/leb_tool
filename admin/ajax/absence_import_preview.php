<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../shared/absence_import.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function computed_class_name_preview(?int $grade, string $label): string {
  $label = trim(mb_strtolower($label, 'UTF-8'));
  $label = preg_replace('/\s+/', '', $label);
  if ($grade === null || $grade <= 0 || $label === '') return trim((string)$grade . $label);
  return (string)$grade . $label;
}

try {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();
  if (!$data) throw new RuntimeException('Ungültige Anfrage.');

  $schoolYear = trim((string)($data['school_year'] ?? ''));
  if ($schoolYear === '') throw new RuntimeException('school_year fehlt.');
  $periodLabel = normalize_class_period_label((string)($data['period_label'] ?? 'H1'));
  $rows = $data['rows'] ?? [];
  if (!is_array($rows)) $rows = [];
  $rows = array_slice($rows, 0, 500);

  $pdo = db();

  $classRows = $pdo->prepare(
    "SELECT id, grade_level, label, name FROM classes WHERE school_year=? AND period_label=?"
  );
  $classRows->execute([$schoolYear, $periodLabel]);
  $classMap = [];
  foreach ($classRows->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $id = (int)($c['id'] ?? 0);
    if ($id <= 0) continue;
    $name = absence_import_normalize_token((string)($c['name'] ?? ''));
    if ($name !== '') $classMap[$name] = $id;
    $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
    $label = (string)($c['label'] ?? '');
    $display = absence_import_normalize_token(computed_class_name_preview($grade, $label));
    if ($display !== '') $classMap[$display] = $id;
  }

  $studentIndex = absence_import_student_index($pdo, $schoolYear, $periodLabel);

  $preview = [];
  $matched = 0;
  $skipped = 0;
  foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $classRaw = absence_import_clean_string($r['class'] ?? '');
    $studentRaw = absence_import_clean_string($r['student'] ?? '');
    $totalRaw = absence_import_clean_string($r['total'] ?? '');
    $unexcusedRaw = absence_import_clean_string($r['unexcused'] ?? '');
    if ($classRaw === '' && $studentRaw === '') continue;

    $classId = (int)($classMap[absence_import_normalize_token($classRaw)] ?? 0);
    if ($classId <= 0) {
      continue;
    }
    $match = $classId > 0 ? absence_import_match_student($studentIndex, $classId, $studentRaw) : ['status'=>'class_not_found', 'student'=>null, 'suggestions'=>[]];
    if ((string)($match['status'] ?? '') === 'not_found') {
      continue;
    }
    $student = $match['student'] ?? null;
    $totalParsed = parse_absence_value($totalRaw, t('admin.students.import.absence.col_total', 'Fehltage gesamt'));
    $unexcusedParsed = parse_absence_value($unexcusedRaw, t('admin.students.import.absence.col_unexcused', 'Fehltage unentschuldigt'));
    $isMatched = $classId > 0 && is_array($student) && !empty($totalParsed['ok']) && !empty($unexcusedParsed['ok']);
    if ($isMatched) $matched++;
    else $skipped++;

    $preview[] = [
      'class' => $classRaw,
      'student' => is_array($student) ? (string)($student['name'] ?? $studentRaw) : $studentRaw,
      'line' => (int)($r['line'] ?? 0),
      'total' => $totalRaw,
      'unexcused' => $unexcusedRaw,
      'status' => ($classId <= 0 ? 'class_not_found' : (is_array($student) ? ((!$isMatched) ? 'invalid_value' : 'matched') : (string)($match['status'] ?? 'not_found'))),
      'message' => $classId <= 0
        ? t('admin.students.import.absence.reason.class_not_found', 'Klasse nicht gefunden:')
        : (is_array($student)
            ? trim((string)($totalParsed['warning'] ?? '') . ' ' . (string)($unexcusedParsed['warning'] ?? ''))
            : (string)($match['warning'] ?? t('admin.students.import.absence.reason.student_not_found', 'Schüler nicht gefunden.'))),
      'suggestions' => array_map(static fn($s) => ['id' => (int)($s['id'] ?? 0), 'name' => (string)($s['name'] ?? '')], (array)($match['suggestions'] ?? [])),
    ];
    if (count($preview) >= 10) break;
  }

  echo json_encode([
    'ok' => true,
    'preview' => $preview,
    'matched' => $matched,
    'skipped' => $skipped,
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
