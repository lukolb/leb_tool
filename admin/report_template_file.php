<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

$pdo = db();
$reportId = (int)($_GET['report_id'] ?? 0);
if ($reportId <= 0) {
  http_response_code(400);
  echo 'report_id fehlt';
  exit;
}

$st = $pdo->prepare(
  "SELECT ri.id, t.pdf_storage_path, t.pdf_original_filename
   FROM report_instances ri
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
