<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
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

  $nodeBin = trim((string)@shell_exec('command -v node'));
  if ($nodeBin === '') $nodeBin = 'node';

  $scriptPath = realpath(__DIR__ . '/../tools/extract_pdf_fields.mjs');
  if (!$scriptPath || !is_file($scriptPath)) throw new RuntimeException('Extractor-Script fehlt.');

  $cmd = $nodeBin . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($abs) . ' 2>&1';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  $raw = trim(implode("\n", $output));

  if ($code !== 0) {
    throw new RuntimeException('PDF-Analyse fehlgeschlagen: ' . $raw);
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new RuntimeException('Ungültige Antwort vom PDF-Extractor.');
  }

  json_out(['ok' => true, 'fields' => $data]);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
