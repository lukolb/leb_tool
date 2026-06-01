<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();
require_once __DIR__ . '/../../shared/template_import.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_input_data(): array {
  $raw = file_get_contents('php://input');
  if (is_string($raw) && trim($raw) !== '') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
      $d = json_decode($raw, true);
      if (is_array($d)) return $d;
    }
  }

  $data = $_POST;
  if (isset($data['fields']) && is_string($data['fields'])) {
    $decoded = json_decode($data['fields'], true);
    if (is_array($decoded)) $data['fields'] = $decoded;
  }
  return is_array($data) ? $data : [];
}

try {
  $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!isset($_POST['csrf_token'])) $_POST['csrf_token'] = (string)$csrf;
  csrf_verify();

  $data = read_input_data();
  $templateId = (int)($data['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');
  $fields = $data['fields'] ?? null;
  if (!is_array($fields)) throw new RuntimeException('fields fehlt/ungültig.');

  $pdo = db();
  $pdo->beginTransaction();
  $count = import_pdf_fields_to_template($pdo, $templateId, $fields);
  $pdo->commit();

  audit('template_fields_import', (int)current_user()['id'], ['template_id' => $templateId, 'count' => $count]);
  json_out(['ok' => true, 'imported' => $count]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
