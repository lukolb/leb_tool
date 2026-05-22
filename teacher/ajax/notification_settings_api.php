<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_teacher();
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false]);
  exit;
}
$raw = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($raw)) $raw = [];
if (!isset($_POST['csrf_token']) && isset($raw['csrf_token'])) $_POST['csrf_token'] = (string)$raw['csrf_token'];
if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
csrf_verify();
$enabled = !empty($raw['enabled']) ? 1 : 0;
$st = $pdo->prepare("INSERT INTO teacher_notification_preferences (user_id,wants_email,confirmation_pending,last_email_sent_at) VALUES (?,?,?,NULL)
ON DUPLICATE KEY UPDATE wants_email=VALUES(wants_email), confirmation_pending=VALUES(confirmation_pending)");
$st->execute([$userId, $enabled, $enabled]);
audit('teacher_notification_pref_update', $userId, ['wants_email'=>$enabled]);
echo json_encode(['ok'=>true,'enabled'=>(bool)$enabled]);
