<?php
declare(strict_types=1);

function report_request_send_notifications(array $recipients, string $subject, string $html): int {
  $sent = 0;
  $seen = [];
  foreach ($recipients as $recipient) {
    $email = trim((string)($recipient['email'] ?? $recipient));
    $key = mb_strtolower($email, 'UTF-8');
    if ($email === '' || isset($seen[$key]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $seen[$key] = true;
    if (@send_email($email, $subject, $html)) $sent++;
  }
  return $sent;
}

function report_request_mail_html(string $heading, array $rows, string $link, string $buttonLabel): string {
  $table = '';
  foreach ($rows as $label => $value) {
    $table .= '<tr><td style="padding:6px;font-weight:700;vertical-align:top">' . h((string)$label) . '</td>'
      . '<td style="padding:6px">' . h((string)$value) . '</td></tr>';
  }
  return '<div style="font-family:Arial,sans-serif;line-height:1.45">'
    . '<h2 style="margin:0 0 12px">' . h($heading) . '</h2>'
    . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0">' . $table . '</table>'
    . '<p style="margin-top:14px"><a href="' . h($link) . '" style="display:inline-block;background:#0b57d0;color:#fff;text-decoration:none;padding:11px 17px;border-radius:8px;font-weight:700">' . h($buttonLabel) . '</a></p>'
    . '<p style="font-size:12px;color:#64748b">' . h($link) . '</p>'
    . '</div>';
}

function notify_admins_about_report_deletion_request(PDO $pdo, array $request): void {
  try {
    $recipients = $pdo->query(
      "SELECT email FROM users
       WHERE role='admin' AND is_active=1 AND deleted_at IS NULL AND email IS NOT NULL AND email<>''"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $link = absolute_url('admin/report_requests.php#deletion-requests');
    $subject = t('report_requests.mail.delete_subject', 'LEB Tool – Neue Löschanfrage für einen Bericht');
    $html = report_request_mail_html($subject, [
      t('report_requests.mail.requester', 'Antragsteller') => (string)($request['requester_name'] ?? ''),
      t('report_requests.mail.student', 'Schüler') => (string)($request['student_name'] ?? ''),
      t('report_requests.mail.period', 'Zeitraum') => trim((string)($request['school_year'] ?? '') . ' · ' . (string)($request['period_label'] ?? '')),
      t('report_requests.mail.reason', 'Begründung') => (string)($request['reason'] ?? ''),
    ], $link, t('report_requests.mail.open', 'Anträge öffnen'));
    report_request_send_notifications($recipients, $subject, $html);
  } catch (Throwable $e) {
    error_log('[report deletion request mail] ' . $e->getMessage());
  }
}

function notify_reviewers_about_report_access_request(PDO $pdo, array $request): void {
  try {
    $classId = (int)($request['class_id'] ?? 0);
    $st = $pdo->prepare(
      "SELECT DISTINCT u.id, u.email, u.role
       FROM users u
       LEFT JOIN user_class_assignments uca ON uca.user_id=u.id AND uca.class_id=?
       WHERE u.is_active=1 AND u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email<>''
         AND (u.role='admin' OR (u.role='teacher' AND uca.id IS NOT NULL))"
    );
    $st->execute([$classId]);
    $recipients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $subject = t('report_requests.mail.access_subject', 'LEB Tool – Neue Anfrage auf Berichteinsicht');
    foreach ($recipients as $recipient) {
      $reviewerId = (int)($recipient['id'] ?? 0);
      if ($reviewerId <= 0) continue;
      $rawToken = bin2hex(random_bytes(32));
      $pdo->prepare(
        "INSERT INTO report_access_approval_tokens
          (request_id, reviewer_user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())"
      )->execute([(int)($request['request_id'] ?? 0), $reviewerId, hash('sha256', $rawToken)]);
      $base = absolute_url('report_access_approve.php?request_id=' . (int)($request['request_id'] ?? 0));
      $rows = [
        t('report_requests.mail.requester', 'Antragsteller') => (string)($request['requester_name'] ?? ''),
        t('report_requests.mail.scope', 'Umfang') => (string)($request['scope_label'] ?? ''),
        t('report_requests.mail.class', 'Klasse') => (string)($request['class_label'] ?? ''),
        t('report_requests.mail.reason', 'Begründung') => (string)($request['reason'] ?? ''),
      ];
      $table = '';
      foreach ($rows as $label => $value) {
        $table .= '<tr><td style="padding:6px;font-weight:700;vertical-align:top">' . h((string)$label) . '</td>'
          . '<td style="padding:6px">' . h((string)$value) . '</td></tr>';
      }
      $buttons = [];
      foreach ([7 => t('report_requests.mail.approve_7', '7 Tage freigeben'), 30 => t('report_requests.mail.approve_30', '30 Tage freigeben'), 0 => t('report_requests.mail.approve_unlimited', 'Unbegrenzt freigeben')] as $days => $label) {
        $actionLink = $base . '&duration=' . $days . '#token=' . rawurlencode($rawToken);
        $buttons[] = '<a href="' . h($actionLink) . '" style="display:inline-block;margin:4px;background:#0b57d0;color:#fff;text-decoration:none;padding:11px 15px;border-radius:8px;font-weight:700">' . h($label) . '</a>';
      }
      $html = '<div style="font-family:Arial,sans-serif;line-height:1.45">'
        . '<h2 style="margin:0 0 12px">' . h($subject) . '</h2>'
        . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0">' . $table . '</table>'
        . '<p style="margin-top:14px">' . implode('', $buttons) . '</p>'
        . '<p style="font-size:12px;color:#64748b">' . h(t('report_requests.mail.one_click_hint', 'Die Schaltflächen sind sieben Tage gültig und können nur einmal verwendet werden.')) . '</p>'
        . '</div>';
      report_request_send_notifications([$recipient], $subject, $html);
    }
  } catch (Throwable $e) {
    error_log('[report access request mail] ' . $e->getMessage());
  }
}
