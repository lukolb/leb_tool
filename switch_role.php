<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_login();

if (!is_actual_admin()) {
  http_response_code(403);
  echo "403 Forbidden";
  exit;
}

$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
  http_response_code(400);
  echo "CSRF Token ungültig.";
  exit;
}

$targetRole = (string)($_POST['role'] ?? $_GET['role'] ?? '');
if (!in_array($targetRole, ['admin', 'teacher'], true)) {
  http_response_code(400);
  echo "Ungültige Rolle.";
  exit;
}

$_SESSION['user']['role'] = $targetRole;
audit('role_switch', (int)($_SESSION['user']['id'] ?? null), ['role' => $targetRole]);

if ($targetRole === 'admin') {
  redirect('admin/index.php');
}
redirect('teacher/index.php');
