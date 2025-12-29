<?php
// parent/template_file.php
// Serves template PDF for parent portal token (read-only preview).
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pdo = db();
$token = (string)($_GET['token'] ?? '');
if ($token === '') {
  http_response_code(400);
  echo 'Token fehlt.';
  exit;
}

$st = $pdo->prepare(
  "SELECT ppl.id, ppl.token, s.class_id, c.template_id, t.pdf_storage_path, t.pdf_original_filename\n" .
  "FROM parent_portal_links ppl\n" .
  "JOIN students s ON s.id=ppl.student_id\n" .
  "JOIN classes c ON c.id=s.class_id\n" .
  "JOIN templates t ON t.id=c.template_id\n" .
  "WHERE ppl.token=? AND ppl.status='approved'\n" .
  "LIMIT 1"
);
$st->execute([$token]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}

$rel = (string)($row['pdf_storage_path'] ?? '');
$abs = realpath(__DIR__ . '/../' . ltrim($rel, '/'));
$uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
$uploadsAbs = realpath(__DIR__ . '/../' . $uploadsDirRel);
if (!$abs || !$uploadsAbs || !str_starts_with($abs, $uploadsAbs) || !is_file($abs)) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}

$filename = (string)($row['pdf_original_filename'] ?? 'template.pdf');
if ($filename === '') $filename = 'template.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

readfile($abs);
exit;
