<?php
// admin/ajax/icons_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();

  $rows = $pdo->query("
    SELECT id, filename, storage_path, mime_type, created_at
    FROM icon_library
    ORDER BY created_at DESC, id DESC
    LIMIT 2000
  ")->fetchAll();

  $icons = [];
  foreach ($rows as $r) {
    $path = (string)($r['storage_path'] ?? '');
    $icons[] = [
      'id' => (int)$r['id'],
      'filename' => (string)$r['filename'],
      'storage_path' => $path,
      'mime' => (string)($r['mime_type'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
      'url' => url('/' . ltrim($path, '/')),
    ];
  }

  echo json_encode(['ok'=>true, 'icons'=>$icons], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
