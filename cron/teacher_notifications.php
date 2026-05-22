<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$pdo = db();

function cron_log_line(string $msg): void {
  if (PHP_SAPI === 'cli' && defined('STDOUT')) {
    fwrite(STDOUT, $msg . "\n");
    return;
  }
  echo $msg . "\n";
}

if (!db_has_table($pdo, 'teacher_notification_preferences')) {
  cron_log_line('preferences table missing');
  exit(0);
}
$st = $pdo->query("SELECT p.user_id, p.confirmation_pending, p.notification_lang, p.last_summary_hash, p.last_email_sent_at, u.email, u.display_name FROM teacher_notification_preferences p JOIN users u ON u.id=p.user_id WHERE p.wants_email=1 AND u.is_active=1 AND u.deleted_at IS NULL");
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sent = 0;
foreach ($rows as $r) {
  $userId = (int)$r['user_id'];
  $email = trim((string)($r['email'] ?? ''));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
  $summary = teacher_notification_summary($pdo, $userId);
  $hasDeadlines = !empty($summary['deadlines'] ?? []);
  if ((int)($summary['tasks_open'] ?? 0) <= 0 && (int)($summary['delegations_open'] ?? 0) <= 0 && !$hasDeadlines && (int)($r['confirmation_pending'] ?? 0) !== 1) continue;
  $lang = in_array((string)($r['notification_lang'] ?? 'de'), ['de','en'], true) ? (string)$r['notification_lang'] : 'de';
  $summaryHash = hash('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $lang);
  $lastHash = (string)($r['last_summary_hash'] ?? '');
  $lastSentAt = trim((string)($r['last_email_sent_at'] ?? ''));
  $isConfirm = ((int)($r['confirmation_pending'] ?? 0) === 1);
  if (!$isConfirm && $lastHash !== '' && hash_equals($lastHash, $summaryHash) && $lastSentAt !== '') {
    try {
      $lastDt = new DateTimeImmutable($lastSentAt);
      $nextAllowed = $lastDt->modify('+7 days');
      if ($nextAllowed > new DateTimeImmutable('now')) continue;
    } catch (Throwable $e) {
      // ignore parse errors and continue sending
    }
  }
  $subject = $isConfirm
    ? ($lang === 'en' ? 'Confirmation: email notifications enabled' : 'Bestätigung: E-Mail-Benachrichtigungen aktiviert')
    : ($lang === 'en' ? 'LEB Tool: new hints and open tasks' : 'LEB-Tool: Neue Hinweise und offene Aufgaben');
  $body = build_teacher_notification_email((string)($r['display_name'] ?? 'Lehrkraft'), $summary, $lang);
  if (send_email($email, $subject, $body)) {
    $upd = $pdo->prepare("UPDATE teacher_notification_preferences SET confirmation_pending=0, last_summary_hash=?, last_email_sent_at=NOW() WHERE user_id=?");
    $upd->execute([$summaryHash, $userId]);
    $sent++;
  }
}
cron_log_line("sent={$sent}");
