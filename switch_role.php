<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$targetRole = (string)($_POST['role'] ?? $_GET['role'] ?? '');
if (!in_array($targetRole, ['admin', 'teacher'], true)) {
  http_response_code(400);
  echo "Ungültige Rolle.";
  exit;
}

$targetPath = $targetRole === 'admin' ? url('admin/index.php') : url('teacher/index.php');
if (!current_user()) {
  redirect_to_full_login($targetPath, $targetRole, false);
}

if (!is_actual_admin()) {
  http_response_code(403);
  echo "403 Forbidden";
  exit;
}

$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
  redirect_to_full_login($targetPath, $targetRole, false);
}

$_SESSION['user']['role'] = $targetRole;
audit('role_switch', (int)($_SESSION['user']['id'] ?? null), ['role' => $targetRole]);

if ($targetRole === 'admin') {
  redirect('admin/index.php');
}
redirect('teacher/index.php');
