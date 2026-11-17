<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function session_reauth_reply(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  session_reauth_reply([
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => t('session.auth_failed', 'Die Anmeldung war nicht erfolgreich.'),
  ], 405);
}

csrf_verify();
$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) $input = $_POST;

$claims = session_guard_verify_reauth_token(trim((string)($input['reauth_token'] ?? '')));
if ($claims === null) {
  session_reauth_reply([
    'ok' => false,
    'error' => 'reauth_context_invalid',
    'requires_login' => true,
    'csrf_token' => csrf_token(),
    'message' => t('session.reauth_context_invalid', 'Diese Seite ist für die erneute Anmeldung nicht mehr gültig. Bitte öffne die Anmeldung erneut.'),
  ], 403);
}

$pdo = db();
$kind = (string)$claims['kind'];
$principalId = (int)$claims['id'];

if ($kind === 'user') {
  $password = (string)($input['password'] ?? '');
  $stmt = $pdo->prepare("SELECT id, email, password_hash, display_name, role, is_active, deleted_at FROM users WHERE id=? LIMIT 1");
  $stmt->execute([$principalId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  $pepper = (string)(app_config()['app']['password_pepper'] ?? '');
  $hash = (string)($user['password_hash'] ?? '');
  $valid = $user
    && $user['deleted_at'] === null
    && (int)$user['is_active'] === 1
    && $pepper !== ''
    && $hash !== ''
    && password_verify($password . $pepper, $hash);
  if (!$valid) {
    session_reauth_reply([
      'ok' => false,
      'error' => 'invalid_credentials',
      'requires_login' => true,
      'csrf_token' => csrf_token(),
      'message' => t('session.auth_failed', 'Die Anmeldung war nicht erfolgreich.'),
    ], 401);
  }

  $actualRole = (string)($user['role'] ?? '');
  $requestedRole = (string)($claims['role'] ?? $actualRole);
  $activeRole = $actualRole === 'admin' && in_array($requestedRole, ['admin', 'teacher'], true)
    ? $requestedRole
    : $actualRole;
  session_regenerate_id(true);
  unset($_SESSION['student']);
  $_SESSION['user'] = [
    'id' => (int)$user['id'],
    'email' => (string)$user['email'],
    'display_name' => (string)$user['display_name'],
    'role' => $activeRole,
    'actual_role' => $actualRole,
  ];
  $_SESSION['_last_activity'] = time();
  unset($_SESSION['csrf_token']);
  audit('session_reauth', (int)$user['id']);
} else {
  $code = strtoupper(trim((string)($input['login_code'] ?? '')));
  $code = (string)preg_replace('/\s+/', '', $code);
  $stmt = $pdo->prepare(
    "SELECT DISTINCT s.id
     FROM students s
     INNER JOIN student_class_assignments sca ON sca.student_id=s.id AND sca.is_active=1
     INNER JOIN classes c ON c.id=sca.class_id AND c.is_active=1
     WHERE s.id=? AND s.login_code=? AND s.is_active=1
     LIMIT 1"
  );
  $stmt->execute([$principalId, $code]);
  $studentId = (int)($stmt->fetchColumn() ?: 0);
  if ($studentId !== $principalId) {
    session_reauth_reply([
      'ok' => false,
      'error' => 'invalid_credentials',
      'requires_login' => true,
      'csrf_token' => csrf_token(),
      'message' => t('session.auth_failed', 'Die Anmeldung war nicht erfolgreich.'),
    ], 401);
  }

  session_regenerate_id(true);
  unset($_SESSION['user']);
  $_SESSION['student'] = ['id' => $studentId];
  $_SESSION['_last_activity'] = time();
  unset($_SESSION['csrf_token']);
}

session_reauth_reply([
  'ok' => true,
  'authenticated' => true,
  'principal_kind' => $kind,
  'csrf_token' => csrf_token(),
  'reauth_token' => session_guard_reauth_token(),
  'role' => current_user()['role'] ?? 'student',
] + session_guard_idle_state());
