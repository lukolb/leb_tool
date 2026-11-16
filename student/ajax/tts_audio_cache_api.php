<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../shared/tts_audio_cache.php';
require_student();

function tts_student_json(array $payload, int $status = 200): never {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function tts_student_class(PDO $pdo, int $studentId): ?array {
  $st = $pdo->prepare(
    'SELECT c.id, c.tts_enabled
     FROM student_class_assignments sca
     INNER JOIN classes c ON c.id=sca.class_id
     WHERE sca.student_id=?
     ORDER BY sca.is_active DESC, c.is_active DESC, c.school_year DESC,
              CASE c.period_label WHEN \'H2\' THEN 2 ELSE 1 END DESC, c.id DESC
     LIMIT 1'
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

try {
  $pdo = db();
  $studentId = (int)($_SESSION['student']['id'] ?? 0);
  $class = tts_student_class($pdo, $studentId);
  if (!$class || (int)($class['tts_enabled'] ?? 0) !== 1) {
    tts_student_json(['ok' => false, 'error' => 'tts_disabled'], 403);
  }
  $classId = (int)$class['id'];

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cacheKey = strtolower(trim((string)($_GET['key'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $cacheKey)) {
      tts_student_json(['ok' => false, 'error' => 'invalid_key'], 400);
    }
    $audio = tts_audio_find($pdo, $cacheKey);
    if (!$audio) tts_student_json(['ok' => false, 'error' => 'cache_miss'], 404);
    tts_audio_link_to_class($pdo, $classId, (int)$audio['id'], 'runtime', 'runtime:' . $cacheKey, '');

    $etag = '"' . $cacheKey . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
      http_response_code(304);
      exit;
    }
    $path = (string)$audio['_absolute_path'];
    session_write_close();
    header('Content-Type: ' . (string)$audio['mime_type']);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="' . $cacheKey . '.audio"');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stored = tts_audio_store_upload($pdo, $classId, $_FILES['audio'] ?? [], [
      'text' => (string)($_POST['text'] ?? ''),
      'language' => (string)($_POST['language'] ?? 'de'),
      'voice_id' => (string)($_POST['voice_id'] ?? ''),
      'cache_key' => (string)($_POST['cache_key'] ?? ''),
      'source_type' => 'runtime',
      'source_key' => 'runtime:' . (string)($_POST['cache_key'] ?? ''),
      'source_label' => '',
    ]);
    tts_student_json([
      'ok' => true,
      'cache_key' => (string)$stored['cache_key'],
      'reused' => (bool)($stored['reused'] ?? false),
      'csrf_token' => csrf_token(),
    ]);
  }

  tts_student_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
} catch (Throwable $e) {
  tts_student_json(['ok' => false, 'error' => 'tts_audio_error', 'message' => $e->getMessage()], 400);
}
