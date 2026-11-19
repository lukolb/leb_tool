<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../shared/tts_audio_cache.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function tts_admin_json(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException(t('tts_audio.error.method', 'Ungültige Anfrage.'));
  csrf_verify();
  $pdo = db();
  $classId = (int)($_POST['class_id'] ?? 0);
  if ($classId <= 0) throw new RuntimeException(t('tts_audio.error.class', 'Ungültige Klasse.'));

  $action = trim((string)($_POST['action'] ?? ''));
  if ($action === 'plan') {
    $items = tts_audio_class_plan($pdo, $classId, true);
    $cached = 0;
    foreach ($items as &$item) {
      $audio = tts_audio_find($pdo, (string)$item['cache_key']);
      $item['cached'] = (bool)$audio;
      if ($audio) {
        tts_audio_link_to_class(
          $pdo,
          $classId,
          (int)$audio['id'],
          (string)$item['source_type'],
          (string)$item['source_key'],
          (string)$item['source_label']
        );
        $cached++;
      }
    }
    unset($item);
    tts_admin_json([
      'ok' => true,
      'items' => $items,
      'total' => count($items),
      'cached' => $cached,
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($action === 'upload') {
    $stored = tts_audio_store_upload($pdo, $classId, $_FILES['audio'] ?? [], $_POST, true);
    tts_admin_json([
      'ok' => true,
      'cache_key' => (string)$stored['cache_key'],
      'reused' => (bool)($stored['reused'] ?? false),
      'csrf_token' => csrf_token(),
    ]);
  }

  throw new RuntimeException(t('tts_audio.error.action', 'Unbekannte Audio-Aktion.'));
} catch (Throwable $e) {
  tts_admin_json(['ok' => false, 'error' => 'tts_audio_error', 'message' => $e->getMessage()], 400);
}
