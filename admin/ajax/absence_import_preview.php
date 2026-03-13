<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function normalize_lookup_token_preview(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = preg_replace('/[\s,;]+/u', '', $s);
  return (string)$s;
}

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
    $name = normalize_lookup_token_preview((string)($c['name'] ?? ''));
    if ($name !== '') $classMap[$name] = $id;
    $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
    $label = (string)($c['label'] ?? '');
    $display = normalize_lookup_token_preview(computed_class_name_preview($grade, $label));
    if ($display !== '') $classMap[$display] = $id;
  }

  $studentRows = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.class_id
     FROM students s
     INNER JOIN classes c ON c.id=s.class_id
     WHERE c.school_year=? AND c.period_label=?"
  );
  $studentRows->execute([$schoolYear, $periodLabel]);
  $studentMap = [];
  foreach ($studentRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)($r['id'] ?? 0);
    $cid = (int)($r['class_id'] ?? 0);
    if ($sid <= 0 || $cid <= 0) continue;
    $first = (string)($r['first_name'] ?? '');
    $last = (string)($r['last_name'] ?? '');
    $k1 = normalize_lookup_token_preview($last . ',' . $first);
    $k2 = normalize_lookup_token_preview($last . ' ' . $first);
    if ($k1 !== '') $studentMap[$cid][$k1] = ['id'=>$sid,'name'=>trim($last.', '.$first)];
    if ($k2 !== '') $studentMap[$cid][$k2] = ['id'=>$sid,'name'=>trim($last.', '.$first)];
  }

  $preview = [];
  $skipped = 0;
  foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $classRaw = trim((string)($r['class'] ?? ''));
    $studentRaw = trim((string)($r['student'] ?? ''));
    $totalRaw = trim((string)($r['total'] ?? ''));
    $unexcusedRaw = trim((string)($r['unexcused'] ?? ''));
    if ($classRaw === '' && $studentRaw === '') continue;

    $classId = (int)($classMap[normalize_lookup_token_preview($classRaw)] ?? 0);
    if ($classId <= 0) { $skipped++; continue; }
    $studentKey = normalize_lookup_token_preview($studentRaw);
    $student = $studentMap[$classId][$studentKey] ?? null;
    if (!is_array($student)) { $skipped++; continue; }

    $preview[] = [
      'class' => $classRaw,
      'student' => (string)($student['name'] ?? $studentRaw),
      'total' => $totalRaw,
      'unexcused' => $unexcusedRaw,
    ];
    if (count($preview) >= 10) break;
  }

  echo json_encode([
    'ok' => true,
    'preview' => $preview,
    'matched' => count($preview),
    'skipped' => $skipped,
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
