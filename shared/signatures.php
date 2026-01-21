<?php
// shared/signatures.php
declare(strict_types=1);

function signature_master_key(): string {
  $cfg = app_config();
  $raw = getenv('SIGNATURE_MASTER_KEY');
  if ($raw === false || $raw === '') {
    $raw = (string)($cfg['signature']['master_key'] ?? ($cfg['app']['signature_master_key'] ?? ''));
  }
  $raw = trim((string)$raw);
  if ($raw === '') {
    throw new RuntimeException('SIGNATURE_MASTER_KEY fehlt.');
  }

  if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
    $raw = (string)hex2bin($raw);
  } else {
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && strlen($decoded) === 32) {
      $raw = $decoded;
    }
  }

  if (strlen($raw) !== 32) {
    throw new RuntimeException('SIGNATURE_MASTER_KEY muss 32 Bytes haben.');
  }
  return $raw;
}

function signature_configured(): bool {
  try {
    signature_master_key();
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function signature_payload_version(): int {
  return 1;
}

function signature_aad(int $userId, string $purpose, int $version): string {
  return 'teacher:' . $userId . '|purpose:' . $purpose . '|v:' . $version;
}

function signature_aad_legacy(int $userId, string $purpose): string {
  return 'teacher:' . $userId . '|purpose:' . $purpose;
}

function signature_sanitize_payload($raw, int $maxStrokes = 120, int $maxPoints = 5000): array {
  if (is_string($raw)) {
    if (strlen($raw) > 200000) {
      throw new RuntimeException('Signaturdaten sind zu groß.');
    }
    $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  }
  if (!is_array($raw)) {
    throw new RuntimeException('Signaturdaten fehlen.');
  }
  $ratio = $raw['ratio'] ?? null;
  $ratioSource = null;
  if (is_numeric($ratio)) {
    $ratio = (float)$ratio;
    if (!is_finite($ratio) || $ratio <= 0) {
      throw new RuntimeException('Signatur-Seitenverhältnis ist ungültig.');
    }
    $ratioSource = 'client';
  } else {
    $ratio = null;
  }
  $strokes = $raw['strokes'] ?? null;
  if (!is_array($strokes)) {
    throw new RuntimeException('Signaturdaten sind ungültig.');
  }

  $clean = [];
  $totalPoints = 0;
  $minX = 1.0;
  $maxX = 0.0;
  $minY = 1.0;
  $maxY = 0.0;
  foreach ($strokes as $stroke) {
    if (!is_array($stroke)) continue;
    if (count($clean) >= $maxStrokes) break;
    $pts = [];
    foreach ($stroke as $pt) {
      if (!is_array($pt)) continue;
      $x = $pt['x'] ?? ($pt[0] ?? null);
      $y = $pt['y'] ?? ($pt[1] ?? null);
      if (!is_numeric($x) || !is_numeric($y)) continue;
      $x = (float)$x;
      $y = (float)$y;
      if (!is_finite($x) || !is_finite($y)) continue;
      $x = max(0.0, min(1.0, $x));
      $y = max(0.0, min(1.0, $y));
      $pts[] = ['x' => $x, 'y' => $y];
      $minX = min($minX, $x);
      $maxX = max($maxX, $x);
      $minY = min($minY, $y);
      $maxY = max($maxY, $y);
      $totalPoints++;
      if ($totalPoints >= $maxPoints) break 2;
    }
    if (count($pts) >= 2) {
      $clean[] = $pts;
    }
  }

  if (!$clean) {
    throw new RuntimeException('Signatur ist leer.');
  }

  $boundsRatio = null;
  $boundsWidth = $maxX - $minX;
  $boundsHeight = $maxY - $minY;
  if ($boundsWidth > 0 && $boundsHeight > 0) {
    $boundsRatio = $boundsWidth / $boundsHeight;
  }

  if ($ratio !== null && $ratioSource === 'client' && $boundsRatio !== null && is_finite($boundsRatio) && $boundsRatio > 0) {
    $logDiff = abs(log($ratio / $boundsRatio));
    $logDiffInv = abs(log((1 / $ratio) / $boundsRatio));
    if ($logDiff > 0.4 && $logDiffInv + 0.05 < $logDiff) {
      $ratio = 1 / $ratio;
      $ratioSource = 'client_inverted_fix';
    }
  }

  if ($ratio === null) {
    $width = $maxX - $minX;
    $height = $maxY - $minY;
    if ($width > 0 && $height > 0) {
      $ratio = $width / $height;
      if (!is_finite($ratio) || $ratio <= 0) {
        $ratio = null;
      } else {
        $ratioSource = 'bounds';
      }
    }
  }
  if ($ratio !== null) {
    $ratio = max(0.5, min(5.0, $ratio));
  }
  if ($ratio === null) {
    throw new RuntimeException('Signatur-Seitenverhältnis fehlt.');
  }

  return [
    'v' => signature_payload_version(),
    'ratio' => $ratio,
    'ratio_source' => $ratioSource,
    'strokes' => $clean,
  ];
}

function signature_encrypt_payload(array $payload, int $userId, string $purpose): array {
  $master = signature_master_key();
  $dataKey = random_bytes(32);
  $version = (int)($payload['v'] ?? signature_payload_version());
  $aad = signature_aad($userId, $purpose, $version);

  $iv = random_bytes(12);
  $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($plaintext === false) {
    throw new RuntimeException('Signaturdaten konnten nicht codiert werden.');
  }
  $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $iv, $tag, $aad);
  if ($ciphertext === false) {
    throw new RuntimeException('Signaturdaten konnten nicht verschlüsselt werden.');
  }

  $keyIv = random_bytes(12);
  $encKey = openssl_encrypt($dataKey, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $keyIv, $keyTag, $aad);
  if ($encKey === false) {
    throw new RuntimeException('Signatur-Schlüssel konnte nicht verschlüsselt werden.');
  }

  return [
    'ciphertext' => $ciphertext,
    'iv' => $iv,
    'tag' => $tag,
    'enc_key' => $encKey,
    'enc_key_iv' => $keyIv,
    'enc_key_tag' => $keyTag,
  ];
}

function signature_decrypt_payload(array $row): ?array {
  if (!isset($row['ciphertext'], $row['iv'], $row['tag'], $row['enc_key'], $row['enc_key_iv'], $row['enc_key_tag'])) {
    return null;
  }
  $userId = (int)($row['user_id'] ?? 0);
  $purpose = (string)($row['purpose'] ?? '');
  if ($userId <= 0 || $purpose === '') return null;

  $master = signature_master_key();
  $aad = signature_aad($userId, $purpose, signature_payload_version());

  $dataKey = openssl_decrypt($row['enc_key'], 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $row['enc_key_iv'], $row['enc_key_tag'], $aad);
  if ($dataKey === false) {
    $legacyAad = signature_aad_legacy($userId, $purpose);
    $dataKey = openssl_decrypt($row['enc_key'], 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $row['enc_key_iv'], $row['enc_key_tag'], $legacyAad);
    if ($dataKey === false) return null;
    $aad = $legacyAad;
  }

  $plaintext = openssl_decrypt($row['ciphertext'], 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $row['iv'], $row['tag'], $aad);
  if ($plaintext === false) return null;

  $decoded = json_decode($plaintext, true);
  if (!is_array($decoded)) return null;
  if (isset($decoded['version']) && !isset($decoded['v'])) {
    $decoded['v'] = (int)$decoded['version'];
  }
  return $decoded;
}

function signature_store_payload(PDO $pdo, int $userId, string $purpose, array $payload): void {
  $enc = signature_encrypt_payload($payload, $userId, $purpose);
  $stmt = $pdo->prepare(
    "INSERT INTO teacher_signatures (user_id, purpose, enc_key, enc_key_iv, enc_key_tag, iv, tag, ciphertext, is_active, created_at, updated_at)\n" .
    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())\n" .
    "ON DUPLICATE KEY UPDATE enc_key=VALUES(enc_key), enc_key_iv=VALUES(enc_key_iv), enc_key_tag=VALUES(enc_key_tag), iv=VALUES(iv), tag=VALUES(tag), ciphertext=VALUES(ciphertext), is_active=1, updated_at=NOW()"
  );
  $stmt->execute([
    $userId,
    $purpose,
    $enc['enc_key'],
    $enc['enc_key_iv'],
    $enc['enc_key_tag'],
    $enc['iv'],
    $enc['tag'],
    $enc['ciphertext'],
  ]);
}

function signature_get_active_payload(PDO $pdo, int $userId, string $purpose): ?array {
  $stmt = $pdo->prepare(
    "SELECT * FROM teacher_signatures WHERE user_id=? AND purpose=? AND is_active=1 LIMIT 1"
  );
  $stmt->execute([$userId, $purpose]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return signature_decrypt_payload($row);
}

function signature_has_active(PDO $pdo, int $userId, string $purpose): bool {
  $stmt = $pdo->prepare(
    "SELECT id FROM teacher_signatures WHERE user_id=? AND purpose=? AND is_active=1 LIMIT 1"
  );
  $stmt->execute([$userId, $purpose]);
  return (bool)$stmt->fetchColumn();
}

function signature_deactivate(PDO $pdo, int $userId, string $purpose): void {
  $stmt = $pdo->prepare(
    "UPDATE teacher_signatures SET is_active=0, updated_at=NOW() WHERE user_id=? AND purpose=?"
  );
  $stmt->execute([$userId, $purpose]);
}
