<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$uid = current_user()['id'] ?? null;

$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"], (bool)$params["secure"], (bool)$params["httponly"]
  );
}
session_destroy();

if ($uid) audit('logout', (int)$uid);

redirect('login.php');
