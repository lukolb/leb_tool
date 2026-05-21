<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$pdo = db();
if (!db_has_table($pdo, 'teacher_notification_preferences')) {
  fwrite(STDOUT, "preferences table missing\n");
  exit(0);
}
$st = $pdo->query("SELECT p.user_id, p.confirmation_pending, u.email, u.display_name FROM teacher_notification_preferences p JOIN users u ON u.id=p.user_id WHERE p.wants_email=1 AND u.is_active=1 AND u.deleted_at IS NULL");
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sent = 0;
foreach ($rows as $r) {
  $userId = (int)$r['user_id'];
  $email = trim((string)($r['email'] ?? ''));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
  $summary = teacher_notification_summary($pdo, $userId);
  if ((int)($summary['tasks_open'] ?? 0) <= 0 && (int)($r['confirmation_pending'] ?? 0) !== 1) continue;
  $subject = ((int)($r['confirmation_pending'] ?? 0) === 1)
    ? 'Bestätigung: E-Mail-Benachrichtigungen aktiviert'
    : 'LEB-Tool: Neue Hinweise und offene Aufgaben';
  $body = build_teacher_notification_email((string)($r['display_name'] ?? 'Lehrkraft'), $summary);
  if (send_email($email, $subject, $body)) {
    $upd = $pdo->prepare("UPDATE teacher_notification_preferences SET confirmation_pending=0, last_email_sent_at=NOW() WHERE user_id=?");
    $upd->execute([$userId]);
    $sent++;
  }
}
fwrite(STDOUT, "sent={$sent}\n");
