<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pdo = db();
$sent = 0;
$checked = 0;

$st = $pdo->query("SELECT id, email, display_name, role, is_active FROM users WHERE deleted_at IS NULL AND is_active=1 AND role='teacher'");
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $u) {
  $checked++;
  $result = send_teacher_dashboard_notice_mail($pdo, $u);
  if (($result['sent'] ?? false) === true) $sent++;
}

echo "checked={$checked} sent={$sent}\n";
