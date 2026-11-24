<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/bootstrap.php');
$guard = file_get_contents($root . '/assets/session_guard.js');
$login = file_get_contents($root . '/login.php');
$studentLogin = file_get_contents($root . '/student/login.php');

function session_fallback_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

session_fallback_assert(
  str_contains((string)$bootstrap, "'loginUrl' => \$kind === 'student' ? url('student/login.php') : url('login.php')")
    && str_contains((string)$guard, 'function redirectToFullLogin()')
    && str_contains((string)$guard, "loginUrl.searchParams.set('next', returnTarget)")
    && str_contains((string)$guard, "code === 'reauth_context_invalid'")
    && str_contains((string)$guard, "error.name === 'AbortError'")
    && str_contains((string)$guard, 'window.location.assign(loginUrl.toString())'),
  'an expired or timed-out inline login must use the correct full login and preserve the exact origin page'
);
session_fallback_assert(
  str_contains((string)$bootstrap, 'function safe_login_next_path')
    && str_contains((string)$bootstrap, 'rawurldecode($path)')
    && str_contains((string)$bootstrap, "('#' . \$fragment)")
    && str_contains((string)$login, "header('Location: ' . \$next)")
    && str_contains((string)$studentLogin, 'name="next"')
    && substr_count((string)$studentLogin, "header('Location: ' . \$next)") >= 2,
  'teacher/admin and student logins must return to the validated path, query, and fragment'
);

fwrite(STDOUT, "session_full_login_fallback_test: OK\n");
