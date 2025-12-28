<?php
// admin/ajax/text_snippets_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/../../shared/text_snippets.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  csrf_verify();

  $pdo = db();
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);

  $action = (string)($data['action'] ?? '');
  if ($action === '') throw new RuntimeException('action fehlt.');

  if ($action === 'list') {
    json_out(['ok' => true, 'snippets' => text_snippets_list($pdo)]);
  }

  if ($action === 'save') {
    $title = (string)($data['title'] ?? '');
    $category = (string)($data['category'] ?? '');
    $content = (string)($data['content'] ?? '');
    $row = text_snippet_save($pdo, $userId, $title, $category, $content);
    json_out(['ok' => true, 'snippet' => $row]);
  }

  if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('id fehlt.');
    text_snippet_delete($pdo, $id);
    json_out(['ok' => true]);
  }

  if ($action === 'generate_base') {
    $res = text_snippet_generate_base($pdo, $userId);
    json_out(['ok' => true, 'result' => $res]);
  }

  throw new RuntimeException('Unbekannte action.');
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
