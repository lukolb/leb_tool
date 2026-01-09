<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
  require $autoload;
}
require __DIR__ . '/../../shared/pdf_extract.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!isset($_POST['csrf_token'])) $_POST['csrf_token'] = (string)$csrf;
  if (function_exists('csrf_verify')) csrf_verify();

  $templateId = (int)($_POST['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  $pdo = db();
  $stmt = $pdo->prepare("SELECT id, pdf_storage_path FROM templates WHERE id = ? LIMIT 1");
  $stmt->execute([$templateId]);
  $t = $stmt->fetch();
  if (!$t) throw new RuntimeException('Template nicht gefunden.');

  $rel = (string)($t['pdf_storage_path'] ?? '');
  $abs = realpath(__DIR__ . '/..' . '/..' . '/' . ltrim($rel, '/'));
  if (!$abs || !is_file($abs)) throw new RuntimeException('PDF-Datei nicht gefunden.');

  $uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
  $uploadsAbs = realpath(__DIR__ . '/..' . '/..' . '/' . $uploadsDirRel);
  if (!$uploadsAbs || !str_starts_with($abs, $uploadsAbs)) throw new RuntimeException('Ungültiger PDF-Pfad.');

  $fields = extract_pdf_fields($abs);
  json_out(['ok' => true, 'fields' => $fields]);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
