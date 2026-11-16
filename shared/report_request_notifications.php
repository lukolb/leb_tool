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

function notify_requester_about_report_access_decision(PDO $pdo, int $requestId, string $decision): void {
  if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) return;
  try {
    $st = $pdo->prepare(
      "SELECT rar.scope_type, rar.reason, rar.expires_at,
              requester.email AS requester_email, requester.display_name AS requester_name,
              c.school_year, c.period_label, c.grade_level, c.label, c.name,
              s.first_name AS student_first_name, s.last_name AS student_last_name
       FROM report_access_requests rar
       INNER JOIN users requester ON requester.id=rar.requester_user_id
       INNER JOIN classes c ON c.id=rar.class_id
       LEFT JOIN students s ON s.id=rar.student_id
       WHERE rar.id=? AND rar.status=?
       LIMIT 1"
    );
    $st->execute([$requestId, $decision]);
    $request = $st->fetch(PDO::FETCH_ASSOC);
    if (!$request) return;

    $grade = $request['grade_level'] !== null ? (int)$request['grade_level'] : null;
    $classLabel = trim((string)($request['label'] ?? ''));
    $className = trim((string)($request['name'] ?? ''));
    $classBase = ($grade !== null && $classLabel !== '')
      ? ($grade . $classLabel)
      : ($className !== '' ? $className : t('report_requests.mail.class', 'Klasse'));
    $period = normalize_class_period_label((string)($request['period_label'] ?? 'H1')) === 'H2'
      ? t('admin.classes.period.h2', '2. Halbjahr')
      : t('admin.classes.period.h1', '1. Halbjahr');
    $fullClassLabel = $classBase . ' · ' . (string)($request['school_year'] ?? '') . ' · ' . $period;
    $scopeLabel = (string)($request['scope_type'] ?? '') === 'class'
      ? t('report_requests.scope.whole_class', 'Gesamte Klasse')
      : trim((string)($request['student_first_name'] ?? '') . ' ' . (string)($request['student_last_name'] ?? ''));
    $approved = $decision === 'approved';
    $subject = $approved
      ? t('report_requests.mail.decision_approved_subject', 'LEB Tool – Berichteinsicht freigegeben')
      : t('report_requests.mail.decision_rejected_subject', 'LEB Tool – Anfrage auf Berichteinsicht abgelehnt');
    $statusLabel = $approved
      ? t('report_requests.status.approved', 'freigegeben')
      : t('report_requests.status.rejected', 'abgelehnt');
    $rows = [
      t('report_requests.mail.scope', 'Umfang') => $scopeLabel,
      t('report_requests.mail.class', 'Klasse') => $fullClassLabel,
      t('report_requests.status', 'Status') => $statusLabel,
    ];
    if ($approved) {
      $rows[t('report_requests.expires', 'Gültig bis')] = !empty($request['expires_at'])
        ? (db_datetime_to_user_local((string)$request['expires_at'], null, ui_lang() === 'en' ? 'm/d/Y g:i A' : 'd.m.Y H:i') ?? (string)$request['expires_at'])
        : t('report_requests.unlimited', 'Unbegrenzt');
    }
    $rows[t('report_requests.mail.reason', 'Begründung')] = (string)($request['reason'] ?? '');
    $html = report_request_mail_html(
      $subject,
      $rows,
      absolute_url('teacher/report_requests.php'),
      t('report_requests.mail.open_own_requests', 'Eigene Anfragen öffnen')
    );
    $sent = report_request_send_notifications([['email' => (string)($request['requester_email'] ?? '')]], $subject, $html);
    if ($sent < 1) error_log('[report access decision mail] No message sent for request ' . $requestId);
  } catch (Throwable $e) {
    error_log('[report access decision mail] ' . $e->getMessage());
  }
}
