<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();
require_once __DIR__ . '/../../shared/template_import.php';
header('Content-Type: application/json; charset=utf-8');

function fail_rcff(string $msg, int $code = 400): never { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
  csrf_verify();
  $templateId = (int)($_POST['template_id'] ?? 0);
  if ($templateId <= 0) fail_rcff('template_id fehlt.');
  if (!isset($_FILES['rcff']) || (int)($_FILES['rcff']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail_rcff('RCFF-Datei fehlt.');
  $file = $_FILES['rcff'];
  $name = (string)($file['name'] ?? '');
  if (!preg_match('/\.rcff$/i', $name)) fail_rcff('Nur .rcff-Dateien erlaubt.');
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) fail_rcff('Ungültige Upload-Datei.');
  if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) fail_rcff('RCFF-Datei zu groß (max. 2MB).');
  $json = (string)file_get_contents($tmp);
  $data = json_decode($json, true);
  if (!is_array($data)) fail_rcff('Ungültiges JSON.');

  $pdo = db();
  $pdo->beginTransaction();
  $stats = apply_rcff_to_template_fields($pdo, $templateId, $data);
  $pdo->commit();

  audit('template_fields_rcff_import', (int)current_user()['id'], ['template_id'=>$templateId,'stats'=>$stats]);
  echo json_encode(['ok'=>true,'stats'=>$stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  fail_rcff($e->getMessage());
}
