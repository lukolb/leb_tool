<?php
// admin/ajax/icon_delete.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
  // CSRF: Header oder JSON-field
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $iconId = isset($data['icon_id']) ? (int)$data['icon_id'] : 0;
  if ($iconId <= 0) throw new RuntimeException('icon_id fehlt.');

  $pdo = db();

  $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM option_list_items WHERE icon_id = ?");
  $usedStmt->execute([$iconId]);
  $usedCount = (int)$usedStmt->fetchColumn();
  if ($usedCount > 0) {
    throw new RuntimeException('Icon wird noch in Option-Listen verwendet.');
  }

  $st = $pdo->prepare("SELECT storage_path FROM icon_library WHERE id=?");
  $st->execute([$iconId]);
  $row = $st->fetch();
  if (!$row) throw new RuntimeException('Icon nicht gefunden.');

  $storagePath = (string)($row['storage_path'] ?? '');
  $rootAbs = realpath(__DIR__ . '/../..');
  if ($rootAbs && $storagePath !== '') {
    $abs = $rootAbs . '/' . ltrim($storagePath, '/');
    if (is_file($abs)) {
      @unlink($abs);
    }
  }

  $del = $pdo->prepare("DELETE FROM icon_library WHERE id=?");
  $del->execute([$iconId]);

  audit('icon_delete', (int)current_user()['id'], ['icon_id' => $iconId]);

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
