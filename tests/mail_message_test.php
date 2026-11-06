<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/mail.php';

function assert_true(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$message = leb_build_mail_message(
  [
    'from_email' => 'no-reply@leb.schultool.com',
    'from_name' => "LEB Tool\r\nBcc: attacker@example.org",
  ],
  'teacher@example.org',
  'Zugang für Übungsgruppe',
  '<h1>Hallo &amp; willkommen</h1><p>Öffne <a href="https://example.org">diesen Link</a>.</p>'
);

assert_true($message['envelope_sender'] === 'no-reply@leb.schultool.com', 'envelope sender must default to From address');
assert_true(strpos($message['headers'], 'From: ') !== false, 'From header must exist');
assert_true(strpos($message['headers'], "\r\nBcc:") === false, 'configured sender name must not inject headers');
assert_true(strpos($message['headers'], 'Reply-To: no-reply@leb.schultool.com') !== false, 'Reply-To must default to From address');
assert_true(strpos($message['headers'], 'Message-ID: <') !== false, 'Message-ID header must exist');
assert_true(strpos($message['headers'], 'multipart/alternative') !== false, 'message must be multipart/alternative');
assert_true(substr_count($message['body'], 'Content-Type: text/plain; charset=UTF-8') === 1, 'plain-text part must exist once');
assert_true(substr_count($message['body'], 'Content-Type: text/html; charset=UTF-8') === 1, 'HTML part must exist once');
assert_true(strpos(quoted_printable_decode($message['body']), 'Hallo & willkommen') !== false, 'plain-text content must decode correctly');
assert_true(strpos($message['subject'], '=?UTF-8?B?') !== false, 'non-ASCII subject must be MIME encoded');

$rejected = false;
try {
  leb_build_mail_message(
    ['from_email' => "no-reply@example.org\r\nBcc: attacker@example.org", 'from_name' => 'LEB Tool'],
    'teacher@example.org',
    'Test',
    '<p>Test</p>'
  );
} catch (InvalidArgumentException $e) {
  $rejected = true;
}
assert_true($rejected, 'invalid sender address must be rejected');

fwrite(STDOUT, "mail_message_test: OK\n");
