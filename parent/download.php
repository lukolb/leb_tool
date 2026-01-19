<?php
declare(strict_types=1);
// parent/download.php
// Encrypts a filled PDF (posted from parent portal) with admin-defined password.

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../shared/pdf_finalize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method not allowed.';
  exit;
}

$token = (string)($_GET['token'] ?? '');
if ($token === '') {
  http_response_code(400);
  echo 'Token fehlt.';
  exit;
}

try {
  csrf_verify();
} catch (Throwable $e) {
  http_response_code(403);
  echo 'Ungültiges CSRF-Token.';
  exit;
}

$cfg = app_config();
$parentCfg = $cfg['parent'] ?? [];
$downloadEnabled = (bool)($parentCfg['download_enabled'] ?? false);
if (!$downloadEnabled) {
  http_response_code(403);
  echo 'Download ist nicht verfügbar.';
  exit;
}

$pdo = db();
$st = $pdo->prepare(
  "SELECT ppl.id, ppl.status, ppl.expires_at, s.first_name, s.last_name\n" .
  "FROM parent_portal_links ppl\n" .
  "JOIN students s ON s.id=ppl.student_id\n" .
  "WHERE ppl.token=?\n" .
  "LIMIT 1"
);
$st->execute([$token]);
$link = $st->fetch(PDO::FETCH_ASSOC);

if (!$link) {
  http_response_code(404);
  echo 'Freigabe nicht gefunden.';
  exit;
}

$expiresAt = $link['expires_at'] ?? null;
$isExpired = false;
if ($expiresAt) {
  $isExpired = (strtotime((string)$expiresAt) < time());
}

$status = (string)($link['status'] ?? '');
if ($status !== 'approved' || $isExpired) {
  http_response_code(403);
  echo 'Download ist nicht freigegeben.';
  exit;
}

if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo 'PDF fehlt.';
  exit;
}

$inputPath = (string)($_FILES['pdf']['tmp_name'] ?? '');
if ($inputPath === '' || !is_file($inputPath)) {
  http_response_code(400);
  echo 'PDF ungültig.';
  exit;
}

$outputPath = tempnam(sys_get_temp_dir(), 'leb_pdf_');
if ($outputPath === false) {
  http_response_code(500);
  echo 'Konnte temporäre Datei nicht erstellen.';
  exit;
}

$encryptEnabled = (bool)($parentCfg['encrypt_enabled'] ?? true);
$userPassword = trim((string)($parentCfg['encrypt_user_password'] ?? ''));
$ownerPassword = trim((string)($parentCfg['encrypt_owner_password'] ?? ''));
if ($ownerPassword === '') {
  $ownerPassword = bin2hex(random_bytes(16));
}
$permissions = [
  'modify' => (bool)($parentCfg['perm_modify'] ?? false),
  'copy' => (bool)($parentCfg['perm_copy'] ?? false),
  'annotate' => (bool)($parentCfg['perm_annotate'] ?? false),
  'fill' => (bool)($parentCfg['perm_fill'] ?? false),
  'print' => (string)($parentCfg['perm_print'] ?? 'high'),
];
$signEnabled = (bool)($parentCfg['sign_enabled'] ?? false);
$signVisible = (bool)($parentCfg['sign_visible'] ?? false);
$signerName = trim((string)($parentCfg['signer_name'] ?? ''));
$signReason = trim((string)($parentCfg['sign_reason'] ?? ''));
$signLocation = trim((string)($parentCfg['sign_location'] ?? ''));
$signPosition = (string)($parentCfg['sign_position'] ?? 'bottom-right');
$signMargin = (int)($parentCfg['sign_margin'] ?? 12);

$pdfBytes = (string)file_get_contents($inputPath);
if ($pdfBytes === '') {
  @unlink($outputPath);
  http_response_code(500);
  echo 'PDF konnte nicht gelesen werden.';
  exit;
}

try {
  $finalBytes = finalize_pdf($pdfBytes, [
    'encrypt' => $encryptEnabled,
    'user_password' => $userPassword,
    'owner_password' => $ownerPassword,
    'permissions' => $permissions,
    'sign' => $signEnabled,
    'sign_visible' => $signVisible,
    'signer_name' => $signerName,
    'sign_reason' => $signReason,
    'sign_location' => $signLocation,
    'sign_position' => $signPosition,
    'sign_margin' => $signMargin,
  ]);
} catch (Throwable $e) {
  @unlink($outputPath);
  http_response_code(500);
  echo 'PDF konnte nicht geschützt werden: ' . htmlspecialchars($e->getMessage());
  exit;
}

if (file_put_contents($outputPath, $finalBytes, LOCK_EX) === false) {
  @unlink($outputPath);
  http_response_code(500);
  echo 'PDF konnte nicht gespeichert werden.';
  exit;
}

$filename = 'Lernentwicklungsbericht_' .
  preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($link['last_name'] ?? '')) . '_' .
  preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($link['first_name'] ?? '')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

readfile($outputPath);
@unlink($outputPath);
exit;
