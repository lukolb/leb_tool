<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_teacher();

$pdo = db();
$u = current_user() ?: [];
$userId = (int)($u['id'] ?? 0);
$role = (string)($u['role'] ?? '');
$reportId = (int)($_GET['report_id'] ?? 0);
if ($reportId <= 0) {
  http_response_code(400);
  echo 'report_id fehlt';
  exit;
}

$st = $pdo->prepare(
  "SELECT ri.id, ri.template_id, s.class_id, t.pdf_storage_path, t.pdf_original_filename
   FROM report_instances ri
   JOIN students s ON s.id=ri.student_id
   JOIN templates t ON t.id=ri.template_id
   WHERE ri.id=?
   LIMIT 1"
);
$st->execute([$reportId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  echo 'Bericht nicht gefunden';
  exit;
}

$classId = (int)($row['class_id'] ?? 0);
if ($role !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo 'Keine Berechtigung';
  exit;
}

$rel = (string)($row['pdf_storage_path'] ?? '');
$abs = storage_path($rel);
if ($rel === '' || !is_file($abs)) {
  http_response_code(404);
  echo 'PDF-Datei nicht gefunden';
  exit;
}

$filename = (string)($row['pdf_original_filename'] ?? 'template.pdf');
if ($filename === '') $filename = 'template.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($abs));
readfile($abs);
