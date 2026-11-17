<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$guard = file_get_contents($root . '/assets/session_guard.js');
$latexPage = file_get_contents($root . '/shared/latex_page.php');
$legacyLatex = file_get_contents($root . '/shared/latex.php');
if ($guard === false || $latexPage === false || $legacyLatex === false) {
  fwrite(STDERR, "Could not read session/download sources\n");
  exit(1);
}

function session_submit_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

foreach (['shared/latex_page.php' => $latexPage, 'shared/latex.php' => $legacyLatex] as $path => $source) {
  session_submit_assert(
    strpos($source, "addEventListener('submit'") !== false
      && strpos($source, 'event.preventDefault();') !== false
      && strpos($source, 'fetch(') !== false,
    "{$path} must remain a page-owned asynchronous PDF/ZIP submit"
  );
}

session_submit_assert(
  strpos($guard, 'if (event.defaultPrevented) return;') !== false,
  'the global guard must not redispatch a submit already handled by page JavaScript'
);
session_submit_assert(
  strpos($guard, "document.addEventListener('submit', function (event)") !== false
    && strpos($guard, "void guardForm(form, event.submitter || null);\n  }, true);") === false,
  'the global submit guard must run in the bubble phase after form-specific handlers'
);
session_submit_assert(
  strpos($guard, 'function submitFormNatively(form, submitter)') !== false
    && strpos($guard, 'nativeFormSubmit.call(form);') !== false
    && strpos($guard, 'form.requestSubmit(') === false,
  'ordinary forms must continue exactly once without emitting a second submit event'
);
session_submit_assert(
  strpos($guard, "['formaction', 'action']") !== false
    && strpos($guard, 'submitterField.name = submitter.name;') !== false,
  'native continuation must preserve submit-button overrides and name/value data'
);

fwrite(STDOUT, "session_guard_submit_ownership_test: OK\n");
