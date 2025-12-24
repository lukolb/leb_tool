<?php
// admin/ajax/scales_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
  $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (function_exists('csrf_verify')) {
    if (!isset($_POST['csrf_token'])) $_POST['csrf_token'] = (string)$csrf;
    csrf_verify();
  }

  $pdo = db();
  $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

  if ($action === 'list') {
    $rows = $pdo->query("SELECT id, name, applies_to, options_json, is_active FROM option_scales WHERE is_active=1 ORDER BY name ASC")->fetchAll();
    echo json_encode(['ok'=>true,'scales'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('id fehlt.');
    $st = $pdo->prepare("SELECT id, name, applies_to, options_json, is_active FROM option_scales WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('Skala nicht gefunden.');
    echo json_encode(['ok'=>true,'scale'=>$r], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
