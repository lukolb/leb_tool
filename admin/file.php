<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require_admin();

$templateId = (int)($_GET['template_id'] ?? 0);
if ($templateId <= 0) {
  http_response_code(400);
  echo "Bad Request";
  exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, pdf_storage_path, pdf_original_filename FROM templates WHERE id = ? LIMIT 1");
$stmt->execute([$templateId]);
$t = $stmt->fetch();

if (!$t) {
  http_response_code(404);
  echo "Not Found";
  exit;
}

$rel = (string)$t['pdf_storage_path'];
$abs = realpath(__DIR__ . '/..' . '/' . ltrim($rel, '/'));

if (!$abs || !is_file($abs)) {
  http_response_code(404);
  echo "File Not Found";
  exit;
}

// Sicherheitscheck: muss innerhalb uploads liegen
$uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
$uploadsAbs = realpath(__DIR__ . '/..' . '/' . $uploadsDirRel);
if (!$uploadsAbs || !str_starts_with($abs, $uploadsAbs)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$filename = (string)($t['pdf_original_filename'] ?? 'template.pdf');

// Caching aus (weil Session-geschützt)
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$size = filesize($abs);
if ($size !== false) header('Content-Length: ' . $size);

readfile($abs);
exit;

