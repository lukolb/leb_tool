<?php
declare(strict_types=1);
// parent/download.php
// Encrypts a filled PDF (posted from parent portal) with admin-defined password.

require __DIR__ . '/../bootstrap.php';

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
$downloadPassword = trim((string)($parentCfg['download_password'] ?? ''));

if (!$downloadEnabled || $downloadPassword === '') {
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

$script = __DIR__ . '/../scripts/encrypt_pdf.py';
if (!is_file($script)) {
  @unlink($outputPath);
  http_response_code(500);
  echo 'Encrypt-Skript fehlt.';
  exit;
}

$cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($inputPath) . ' ' .
  escapeshellarg($outputPath) . ' ' . escapeshellarg($downloadPassword);

$output = [];
$code = 0;
@exec($cmd . ' 2>&1', $output, $code);
if ($code !== 0 || !is_file($outputPath)) {
  @unlink($outputPath);
  http_response_code(500);
  echo 'PDF konnte nicht geschützt werden: ' . htmlspecialchars(implode("\n", $output));
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
