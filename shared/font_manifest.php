<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/font_utils.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$manifest = load_font_manifest();
$fonts = [];
foreach ($manifest as $key => $row) {
  if (!is_array($row)) continue;
  $fonts[] = [
    'key' => (string)$key,
    'name' => (string)($row['name'] ?? $key),
    'normalized' => normalize_font_name((string)($row['name'] ?? $key)),
    'family' => (string)($row['family'] ?? ''),
    'file' => (string)($row['file'] ?? ''),
    'url' => url('shared/font_file.php?name=' . rawurlencode((string)$key)),
  ];
}

echo json_encode(['fonts' => $fonts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
