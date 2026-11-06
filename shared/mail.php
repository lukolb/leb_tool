<?php
declare(strict_types=1);

/**
 * Normalize user-configurable values before placing them in a mail header.
 */
function leb_mail_header_value(string $value): string {
  $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '';
  return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function leb_mail_plain_text(string $html): string {
  $text = preg_replace(
    ['/<br\s*\/?\s*>/i', '/<\/p\s*>/i', '/<\/div\s*>/i', '/<\/li\s*>/i'],
    ["\n", "\n\n", "\n", "\n"],
    $html
  ) ?? $html;
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
  $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
  return trim($text) . "\n";
}

/**
 * Build a standards-compliant multipart message. Keeping this separate from
 * mail() makes the message structure testable without sending an email.
 *
 * @return array{to:string,subject:string,body:string,headers:string,envelope_sender:string}
 */
function leb_build_mail_message(array $mailConfig, string $to, string $subject, string $htmlBody): array {
  $to = trim($to);
  $fromEmail = trim((string)($mailConfig['from_email'] ?? 'no-reply@example.org'));
  $fromName = leb_mail_header_value((string)($mailConfig['from_name'] ?? 'LEB Tool'));
  $replyTo = trim((string)($mailConfig['reply_to'] ?? $fromEmail));
  $envelopeSender = $fromEmail;

  foreach (['recipient' => $to, 'sender' => $fromEmail, 'reply-to' => $replyTo] as $label => $address) {
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
      throw new InvalidArgumentException("Invalid {$label} email address.");
    }
  }
  if ($fromName === '') {
    throw new InvalidArgumentException('Sender name must not be empty.');
  }

  $subject = leb_mail_header_value($subject);
  if ($subject === '') {
    throw new InvalidArgumentException('Email subject must not be empty.');
  }

  $fromDomain = strtolower((string)substr(strrchr($fromEmail, '@') ?: '@localhost', 1));
  if (!preg_match('/^[a-z0-9.-]+$/', $fromDomain)) $fromDomain = 'localhost';

  $boundary = '=_LEB_' . bin2hex(random_bytes(18));
  $messageId = bin2hex(random_bytes(16)) . '.' . time() . '@' . $fromDomain;
  $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n");
  $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

  $headers = [
    'Date: ' . date(DATE_RFC2822),
    'From: ' . $encodedFromName . " <{$fromEmail}>",
    'Reply-To: ' . $replyTo,
    'Message-ID: <' . $messageId . '>',
    'MIME-Version: 1.0',
    'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    'Auto-Submitted: auto-generated',
  ];

  $plainBody = leb_mail_plain_text($htmlBody);
  $lines = [
    '--' . $boundary,
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    quoted_printable_encode($plainBody),
    '--' . $boundary,
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    quoted_printable_encode($htmlBody),
    '--' . $boundary . '--',
    '',
  ];

  return [
    'to' => $to,
    'subject' => $encodedSubject,
    'body' => implode("\r\n", $lines),
    'headers' => implode("\r\n", $headers),
    'envelope_sender' => $envelopeSender,
  ];
}
