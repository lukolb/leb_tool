<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$authenticated = current_user() !== null || current_student() !== null;
if (!$authenticated) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'authenticated' => false,
    'error' => 'session_expired',
    'retryable' => false,
    'requires_login' => true,
    'csrf_token' => csrf_token(),
    'message' => t('session.expired.message', 'Die Sitzung ist abgelaufen. Bitte melde dich erneut an.'),
  ] + session_guard_idle_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
  csrf_verify();
  $_SESSION['_last_activity'] = time();
}

echo json_encode([
  'ok' => true,
  'authenticated' => true,
  'csrf_token' => csrf_token(),
  'reauth_token' => session_guard_reauth_token(),
  'role' => current_user()['role'] ?? 'student',
  'principal_kind' => current_user() !== null ? 'user' : 'student',
] + session_guard_idle_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
