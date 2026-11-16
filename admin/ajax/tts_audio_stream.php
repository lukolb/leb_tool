<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../shared/tts_audio_cache.php';
require_admin();

$audioId = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM tts_audio_files WHERE id=? LIMIT 1');
$st->execute([$audioId]);
$audio = $st->fetch(PDO::FETCH_ASSOC);
$path = $audio ? tts_audio_absolute_path((string)$audio['storage_path']) : null;
if (!$audio || $path === null || !is_file($path)) {
  http_response_code(404);
  exit;
}

$size = (int)filesize($path);
$start = 0;
$end = max(0, $size - 1);
header('Content-Type: ' . (string)$audio['mime_type']);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
  if ($m[1] !== '') $start = min($end, (int)$m[1]);
  if ($m[2] !== '') $end = min($end, (int)$m[2]);
  if ($end < $start) { http_response_code(416); exit; }
  http_response_code(206);
  header("Content-Range: bytes $start-$end/$size");
}
$length = $end - $start + 1;
header('Content-Length: ' . $length);
session_write_close();
$fh = fopen($path, 'rb');
if (!$fh) { http_response_code(404); exit; }
fseek($fh, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
  $chunk = fread($fh, min(8192, $remaining));
  if ($chunk === false || $chunk === '') break;
  echo $chunk;
  $remaining -= strlen($chunk);
}
fclose($fh);
exit;
