<?php
// admin/ajax/icon_upload.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function ensure_dir(string $p): void {
  if (!is_dir($p)) @mkdir($p, 0755, true);
}

function normalize_uploads(): array {
  if (!isset($_FILES['icon'])) return [];

  $f = $_FILES['icon'];
  if (is_array($f['name'])) {
    $out = [];
    foreach ($f['name'] as $idx => $name) {
      $err = $f['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
      if ($err === UPLOAD_ERR_NO_FILE) continue;
      if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload-Fehler bei "' . ($name ?: 'Datei') . '" (Code ' . $err . ').');
      $out[] = [
        'tmp_name' => $f['tmp_name'][$idx] ?? null,
        'name' => $name,
      ];
    }
    return $out;
  }

  $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($err === UPLOAD_ERR_NO_FILE) return [];
  if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload-Fehler (Code ' . $err . ').');

  return [[
    'tmp_name' => $f['tmp_name'] ?? null,
    'name' => $f['name'] ?? 'icon',
  ]];
}

function handle_icon_upload(array $file, PDO $pdo, string $rootAbs, string $uploadsRel, int $userId): array {
  $tmp = (string)($file['tmp_name'] ?? '');
  $orig = (string)($file['name'] ?? 'icon');
  if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Temporäre Datei fehlt.');

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
    $userId
  ]);
  $iconId = (int)$pdo->lastInsertId();
  audit('icon_upload', $userId, ['icon_id'=>$iconId,'file'=>basename($destAbs)]);

  return [
    'icon_id' => $iconId,
    'filename' => basename($destAbs),
    'dest_abs' => $destAbs,
  ];
}

try {
  csrf_verify();

  $files = normalize_uploads();
  if (!$files) throw new RuntimeException('Bitte mindestens eine Datei auswählen.');

  $cfg = app_config();
  $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';

  $rootAbs = realpath(__DIR__ . '/../..');
  if (!$rootAbs) throw new RuntimeException('Root-Pfad nicht gefunden.');

  $pdo = db();
  $pdo->beginTransaction();

  $userId = (int)current_user()['id'];
  $uploaded = [];
  $savedPaths = [];

  foreach ($files as $f) {
    $result = handle_icon_upload($f, $pdo, $rootAbs, $uploadsRel, $userId);
    $uploaded[] = [
      'icon_id' => $result['icon_id'],
      'filename' => $result['filename'],
    ];
    $savedPaths[] = $result['dest_abs'];
  }

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'uploaded'=>$uploaded,
    'filename'=>$uploaded[0]['filename'] ?? null,
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  if (isset($savedPaths)) {
    foreach ($savedPaths as $p) {
      if (is_string($p) && is_file($p)) @unlink($p);
    }
  }
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
