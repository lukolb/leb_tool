<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/bootstrap.php');
$guard = file_get_contents($root . '/assets/session_guard.js');
$login = file_get_contents($root . '/login.php');
$studentLogin = file_get_contents($root . '/student/login.php');
$roleSwitch = file_get_contents($root . '/switch_role.php');
$layout = file_get_contents($root . '/shared/_layout.php');

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
session_fallback_assert(
  str_contains((string)$bootstrap, 'function redirect_to_full_login')
    && str_contains((string)$bootstrap, "auth_json_error('csrf_failed'")
    && str_contains((string)$bootstrap, 'redirect_to_full_login();')
    && str_contains((string)$login, "\$_GET['csrf_expired']")
    && str_contains((string)$studentLogin, "\$_GET['csrf_expired']"),
  'expired CSRF checks must lead to the appropriate full login instead of a technical error page'
);
session_fallback_assert(
  !str_contains((string)$roleSwitch, 'echo "CSRF Token ungültig."')
    && substr_count((string)$roleSwitch, 'redirect_to_full_login($targetPath, $targetRole, false);') >= 2
    && str_contains((string)$login, "'role' => \$activeRole")
    && str_contains((string)$login, 'name="role"')
    && str_contains((string)$layout, 'class="role-switch-form" method="post"')
    && str_contains((string)$layout, 'action="<?=h(url(\'switch_role.php\'))?>"')
    && !str_contains((string)$layout, "switch_role.php?role="),
  'role switching must use a refreshable POST token and continue in the requested role after full login'
);

fwrite(STDOUT, "session_full_login_fallback_test: OK\n");
