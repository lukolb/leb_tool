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
   WHERE ri.id=? AND ri.student_id IS NOT NULL
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
$abs = realpath(__DIR__ . '/..' . '/' . ltrim($rel, '/'));
if (!$abs || !is_file($abs)) {
  http_response_code(404);
  echo 'PDF-Datei nicht gefunden';
  exit;
}

$uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
$uploadsAbs = realpath(__DIR__ . '/..' . '/' . $uploadsDirRel);
if (!$uploadsAbs || !str_starts_with($abs, $uploadsAbs)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$filename = (string)($row['pdf_original_filename'] ?? 'template.pdf');
if ($filename === '') $filename = 'template.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$size = filesize($abs);
if ($size !== false) header('Content-Length: ' . $size);
readfile($abs);
exit;
