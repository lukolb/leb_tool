<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/font_utils.php';

$key = isset($_GET['name']) ? (string)$_GET['name'] : '';
$key = sanitize_font_key($key);
if ($key === '') {
  http_response_code(400);
  echo 'Invalid font name.';
  exit;
}

$manifest = load_font_manifest();
$row = $manifest[$key] ?? null;
if (!$row || !is_array($row)) {
  http_response_code(404);
  echo 'Font not found.';
  exit;
}

$file = (string)($row['file'] ?? '');
if ($file === '') {
  http_response_code(404);
  echo 'Font file missing.';
  exit;
}

$path = font_storage_root() . '/' . basename($file);
if (!is_file($path)) {
  http_response_code(404);
  echo 'Font file missing.';
  exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$contentType = $ext === 'otf' ? 'font/otf' : 'font/ttf';

header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
