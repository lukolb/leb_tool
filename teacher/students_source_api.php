<?php
// teacher/students_source_api.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_teacher();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $a): never {
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // CSRF via header
  $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    throw new RuntimeException('CSRF ungültig.');
  }

  $pdo = db();
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);

  $sourceClassId = (int)($_GET['source_class_id'] ?? 0);
  if ($sourceClassId <= 0) throw new RuntimeException('source_class_id fehlt.');

  if (!user_can_access_class($pdo, $userId, $sourceClassId)) {
    throw new RuntimeException('Keine Berechtigung für die Quellklasse.');
  }

  $st = $pdo->prepare(
    "SELECT id, first_name, last_name, date_of_birth
     FROM students
     WHERE class_id=? AND is_active=1
     ORDER BY last_name ASC, first_name ASC"
  );
  $st->execute([$sourceClassId]);
  $students = $st->fetchAll(PDO::FETCH_ASSOC);

  json_out(['ok' => true, 'students' => $students]);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()]);
}
