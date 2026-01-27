<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$cfg = app_config();
$fonts = $cfg['pdf']['fonts'] ?? [];
if (!is_array($fonts)) $fonts = [];

$fileParam = basename((string)($_GET['file'] ?? ''));
if ($fileParam === '') {
  http_response_code(400);
  echo 'Bad Request';
  exit;
}

$allowed = [];
foreach ($fonts as $font) {
  $path = (string)($font['file'] ?? '');
  if ($path === '') continue;
  $allowed[basename($path)] = $path;
}

if (!isset($allowed[$fileParam])) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}

$uploadsDirRel = $cfg['app']['uploads_dir'] ?? 'uploads';
$rootAbs = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$abs = realpath($rootAbs . '/' . ltrim($allowed[$fileParam], '/'));
$uploadsAbs = realpath($rootAbs . '/' . $uploadsDirRel);

if (!$abs || !$uploadsAbs || !str_starts_with($abs, $uploadsAbs) || !is_file($abs)) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}

$mime = mime_content_type($abs) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode(basename($abs)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$size = filesize($abs);
if ($size !== false) header('Content-Length: ' . $size);

readfile($abs);
exit;
