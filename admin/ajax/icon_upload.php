<?php
// admin/ajax/icon_upload.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function ensure_dir(string $p): void {
  if (!is_dir($p)) @mkdir($p, 0755, true);
}

try {
  csrf_verify();

  if (!isset($_FILES['icon']) || ($_FILES['icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Bitte eine Datei auswählen.');
  }

  $tmp = $_FILES['icon']['tmp_name'];
  $orig = (string)($_FILES['icon']['name'] ?? 'icon');
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

  $allowed = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
  ];
  if (!isset($allowed[$ext])) throw new RuntimeException('Nur png/jpg/webp/svg erlaubt.');

  $mime = $allowed[$ext];
  $safe = preg_replace('/[^a-z0-9._-]+/i', '_', pathinfo($orig, PATHINFO_FILENAME));
  if ($safe === '' || $safe === '_') $safe = 'icon';

  $cfg = app_config();
  $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';

  $rootAbs = realpath(__DIR__ . '/../..');
  if (!$rootAbs) throw new RuntimeException('Root-Pfad nicht gefunden.');

  $iconsAbs = $rootAbs . '/' . $uploadsRel . '/icons';
  ensure_dir($iconsAbs);

  $destAbs = $iconsAbs . '/' . $safe . '.' . $ext;

  $i = 1;
  while (file_exists($destAbs)) {
    $destAbs = $iconsAbs . '/' . $safe . '_' . $i . '.' . $ext;
    $i++;
    if ($i > 999) throw new RuntimeException('Zu viele gleichnamige Icons.');
  }

  if (!move_uploaded_file($tmp, $destAbs)) {
    throw new RuntimeException('Upload fehlgeschlagen (move_uploaded_file).');
  }

  $sha = hash_file('sha256', $destAbs) ?: null;

  $storageRel = $uploadsRel . '/icons/' . basename($destAbs);

  $pdo = db();
  $stmt = $pdo->prepare("
    INSERT INTO icon_library (filename, storage_path, file_ext, mime_type, sha256, created_by_user_id)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    basename($destAbs),
    $storageRel,
    $ext,
    $mime,
    $sha,
    (int)current_user()['id']
  ]);
  $iconId = (int)$pdo->lastInsertId();
  audit('icon_upload', (int)current_user()['id'], ['icon_id'=>$iconId,'file'=>basename($destAbs)]);

  echo json_encode(['ok'=>true, 'icon_id'=>$iconId, 'filename'=>basename($destAbs)], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
