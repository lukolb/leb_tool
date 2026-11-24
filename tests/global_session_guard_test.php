<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  'bootstrap' => 'bootstrap.php',
  'status' => 'ajax/session_status.php',
  'reauth' => 'ajax/session_reauth.php',
  'script' => 'assets/session_guard.js',
  'translations' => 'shared/translations.php',
  'config' => 'config.sample.php',
  'template_fields' => 'admin/template_fields.php',
  'teacher_entry' => 'teacher/entry.php',
  'student_entry' => 'student/index.php',
];
$source = [];
foreach ($paths as $key => $path) {
  $source[$key] = file_get_contents($root . '/' . $path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function session_guard_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

session_guard_assert(
  strpos($source['bootstrap'], 'render_session_guard_markup()') !== false
    && strpos($source['bootstrap'], 'data-session-guard-config') !== false
    && strpos($source['bootstrap'], "url('assets/session_guard.js')") !== false
    && strpos($source['bootstrap'], "strripos(\$buffer, '</body>')") !== false
    && strpos($source['bootstrap'], 'substr($buffer, 0, $bodyClosePos)') !== false
    && strpos($source['teacher_entry'], '<\\/body><\\/html>') !== false,
  'authenticated HTML responses must receive the central session guard at the final body close without corrupting embedded documents'
);
session_guard_assert(
  strpos($source['bootstrap'], "str_ends_with(\$requestPath, '/ajax/session_status.php')") !== false
    && strpos($source['bootstrap'], "elseif (!\$isSessionStatusRequest)") !== false
    && strpos($source['status'], "strtoupper((string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'") !== false
    && strpos($source['status'], 'csrf_verify();') !== false
    && strpos($source['status'], "\$_SESSION['_last_activity'] = time();") !== false,
  'passive checks must not extend a session while the explicit keepalive action must do so after CSRF verification'
);
session_guard_assert(
  strpos($source['bootstrap'], "'csrf_token' => csrf_token()") !== false
    && strpos($source['bootstrap'], "'authenticated' => current_user() !== null || current_student() !== null") !== false,
  'every JSON authentication failure must provide the fresh CSRF state needed for inline reauthentication'
);
session_guard_assert(
  strpos($source['bootstrap'], "auth_json_error('csrf_failed'") !== false
    && strpos($source['bootstrap'], "403, true") !== false,
  'an expired AJAX CSRF token must always request reauthentication'
);
session_guard_assert(
  strpos($source['bootstrap'], "hash_hmac('sha256'") !== false
    && strpos($source['reauth'], "FROM users WHERE id=?") !== false
    && strpos($source['reauth'], "WHERE s.id=? AND s.login_code=?") !== false
    && strpos($source['status'], "'reauth_token' => session_guard_reauth_token()") !== false
    && strpos($source['reauth'], "'reauth_token' => session_guard_reauth_token()") !== false,
  'inline reauthentication must use a signed context and only restore the original principal'
);
session_guard_assert(
  strpos($source['script'], 'function requestReauthentication()') !== false
    && strpos($source['script'], 'function staySignedIn()') !== false
    && strpos($source['script'], 'window.fetch = async function') !== false
    && strpos($source['script'], 'redirectedToLogin') !== false
    && strpos($source['script'], "document.addEventListener('submit'") !== false
    && strpos($source['script'], 'if (event.defaultPrevented) return;') !== false
    && strpos($source['script'], 'function submitFormNatively(form, submitter)') !== false
    && strpos($source['script'], "window.dispatchEvent(new CustomEvent('leb:session-restored'") !== false,
  'the browser guard must warn, reauthenticate inline, retry fetch calls, respect page-owned submits and protect normal forms'
);
session_guard_assert(
  strpos($source['script'], 'window.open(') === false
    && strpos($source['script'], 'location.reload(') === false,
  'session recovery must not open a new window or reload the page'
);
foreach (['template_fields', 'teacher_entry', 'student_entry'] as $page) {
  session_guard_assert(
    strpos($source[$page], 'window.LEBSessionGuard || null') !== false
      && strpos($source[$page], 'guard.requireReauthentication()') !== false,
    "{$paths[$page]} must delegate expired sessions to the central inline guard"
  );
}
session_guard_assert(
  substr_count($source['translations'], "'session.stay_signed_in'") === 2
    && substr_count($source['translations'], "'session.inline_reauth.message'") === 2
    && strpos($source['config'], "'session_warning_seconds' => 300") !== false,
  'warning and inline sign-in controls must be configurable and translated in German and English'
);

fwrite(STDOUT, "global_session_guard_test: OK\n");
