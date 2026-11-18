<?php
declare(strict_types=1);

require_once __DIR__ . '/group_keys.php';

function delegation_notification_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function delegation_notification_lang(?string $value): string {
  return strtolower(trim((string)$value)) === 'en' ? 'en' : 'de';
}

function delegation_new_user_ids(array $existingUserIds, array $requestedUserIds): array {
  $existing = array_values(array_unique(array_filter(array_map('intval', $existingUserIds), static fn(int $id): bool => $id > 0)));
  $requested = array_values(array_unique(array_filter(array_map('intval', $requestedUserIds), static fn(int $id): bool => $id > 0)));
  return array_values(array_diff($requested, $existing));
}

function delegation_status_became_done(?string $previousStatus, string $nextStatus): bool {
  return strtolower(trim((string)$previousStatus)) !== 'done'
    && strtolower(trim($nextStatus)) === 'done';
}

function delegation_notification_recipient_count(array $events): int {
  $ids = [];
  foreach ($events as $event) {
    if (!is_array($event)) continue;
    $id = (int)($event['user_id'] ?? 0);
    if ($id > 0) $ids[$id] = true;
  }
  return count($ids);
}

function delegation_notification_period_label(string $periodLabel, string $lang): string {
  $period = normalize_class_period_label($periodLabel);
  if ($lang === 'en') return $period === 'H2' ? '2nd semester' : '1st semester';
  return $period === 'H2' ? '2. Halbjahr' : '1. Halbjahr';
}

function delegation_notification_class_label(array $class): string {
  $grade = $class['grade_level'] !== null ? (int)$class['grade_level'] : null;
  $label = trim((string)($class['label'] ?? ''));
  $name = trim((string)($class['name'] ?? ''));
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  return $name !== '' ? $name : ('#' . (int)($class['id'] ?? 0));
}

function delegation_notification_group_label(string $groupKey, string $lang): string {
  $groupKey = trim($groupKey);
  $parts = app_group_scope_parts_from_key($groupKey);
  $mainKey = (string)$parts['group'];
  $subgroup = (string)$parts['subgroup'];
  $cfg = app_config();
  $bucket = $lang === 'en' ? 'group_titles_en' : 'group_titles';
  $map = $cfg['student'][$bucket] ?? [];
  $label = $mainKey;
  if (is_array($map)) {
    $mapped = trim((string)($map[$mainKey] ?? ''));
    if ($mapped !== '') $label = $mapped;
  }
  if ($subgroup !== '') $label .= ' › ' . $subgroup;
  return $label !== '' ? $label : $groupKey;
}

function delegation_notification_recipient(PDO $pdo, int $userId): ?array {
  if ($userId <= 0) return null;
  $st = $pdo->prepare(
    "SELECT u.id, u.display_name, u.email, u.preferred_lang,
            p.notification_lang
     FROM users u
     LEFT JOIN teacher_notification_preferences p ON p.user_id=u.id
     WHERE u.id=? AND u.is_active=1 AND u.deleted_at IS NULL
     LIMIT 1"
  );
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  $email = trim((string)($row['email'] ?? ''));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
  $lang = trim((string)($row['notification_lang'] ?? ''));
  if ($lang === '') $lang = (string)($row['preferred_lang'] ?? 'de');
  return [
    'id' => (int)$row['id'],
    'name' => trim((string)($row['display_name'] ?? '')),
    'email' => $email,
    'lang' => delegation_notification_lang($lang),
  ];
}

function delegation_notification_user_name(PDO $pdo, int $userId): string {
  if ($userId <= 0) return '';
  $st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  return trim((string)($st->fetchColumn() ?: ''));
}

function delegation_notification_class(PDO $pdo, int $classId): ?array {
  $st = $pdo->prepare("SELECT id, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
  $st->execute([$classId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function delegation_notification_email_frame(
  string $recipientName,
  string $heading,
  string $intro,
  string $detailsHtml,
  string $link,
  string $buttonLabel,
  string $lang
): string {
  $safeName = delegation_notification_escape($recipientName);
  $safeHeading = delegation_notification_escape($heading);
  $safeIntro = delegation_notification_escape($intro);
  $safeLink = delegation_notification_escape($link);
  $safeButton = delegation_notification_escape($buttonLabel);
  $hello = $lang === 'en' ? 'Hello' : 'Hallo';
  $footer = $lang === 'en'
    ? 'This message was generated automatically by LEB Tool.'
    : 'Diese Nachricht wurde automatisch vom LEB-Tool erzeugt.';

  return '<div style="font-family:Inter,Segoe UI,Arial,sans-serif;background:#f3f4f6;padding:20px;">'
    . '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">'
    . '<div style="background:#0b57d0;color:#fff;padding:16px 20px;"><h2 style="margin:0;font-size:20px;">' . $safeHeading . '</h2></div>'
    . '<div style="padding:18px 20px;color:#111827;line-height:1.5;">'
    . '<p style="margin:0 0 10px;">' . $hello . ' ' . $safeName . ',</p>'
    . '<p style="margin:0 0 16px;">' . $safeIntro . '</p>'
    . $detailsHtml
    . '<p style="margin:20px 0 12px;"><a href="' . $safeLink . '" style="display:inline-block;background:#0b57d0;color:#fff;text-decoration:none;border-radius:8px;padding:10px 14px;font-weight:600;">' . $safeButton . '</a></p>'
    . '<p style="margin:16px 0 0;color:#6b7280;font-size:12px;">' . delegation_notification_escape($footer) . '</p>'
    . '</div></div></div>';
}

function build_delegation_assignment_email(string $recipientName, array $assignments, string $link, string $lang = 'de'): string {
  $lang = delegation_notification_lang($lang);
  $rows = '';
  foreach ($assignments as $assignment) {
    $note = trim((string)($assignment['note'] ?? ''));
    $rows .= '<div style="border:1px solid #dbe3f0;border-radius:9px;padding:12px 14px;margin:0 0 10px;">'
      . '<strong>' . delegation_notification_escape((string)($assignment['group_label'] ?? '')) . '</strong><br>'
      . delegation_notification_escape(($lang === 'en' ? 'Class' : 'Klasse') . ': ' . (string)($assignment['class_label'] ?? '')) . '<br>'
      . delegation_notification_escape((string)($assignment['school_year'] ?? '') . ' · ' . (string)($assignment['period_label'] ?? ''))
      . ($note !== '' ? '<br><span style="color:#475569;">' . delegation_notification_escape(($lang === 'en' ? 'Note' : 'Notiz') . ': ' . $note) . '</span>' : '')
      . '</div>';
  }
  $count = count($assignments);
  $heading = $lang === 'en' ? 'New delegation' : 'Neue Delegation';
  $intro = $lang === 'en'
    ? ($count === 1 ? 'A report area has been delegated to you.' : 'Report areas have been delegated to you.')
    : ($count === 1 ? 'Dir wurde ein Bereich zur Bearbeitung delegiert.' : 'Dir wurden Bereiche zur Bearbeitung delegiert.');
  return delegation_notification_email_frame(
    $recipientName,
    $heading,
    $intro,
    $rows,
    $link,
    $lang === 'en' ? 'Open delegations' : 'Delegationen öffnen',
    $lang
  );
}

function build_delegation_completion_email(string $recipientName, array $completion, string $link, string $lang = 'de'): string {
  $lang = delegation_notification_lang($lang);
  $delegateName = trim((string)($completion['delegate_name'] ?? ''));
  $note = trim((string)($completion['note'] ?? ''));
  $details = '<div style="border:1px solid #cfe8d5;background:#f1fbf3;border-radius:9px;padding:12px 14px;">'
    . '<strong>' . delegation_notification_escape((string)($completion['group_label'] ?? '')) . '</strong><br>'
    . delegation_notification_escape(($lang === 'en' ? 'Class' : 'Klasse') . ': ' . (string)($completion['class_label'] ?? '')) . '<br>'
    . delegation_notification_escape((string)($completion['school_year'] ?? '') . ' · ' . (string)($completion['period_label'] ?? ''))
    . ($delegateName !== '' ? '<br>' . delegation_notification_escape(($lang === 'en' ? 'Completed by' : 'Fertig gemeldet von') . ': ' . $delegateName) : '')
    . ($note !== '' ? '<br><span style="color:#475569;">' . delegation_notification_escape(($lang === 'en' ? 'Note' : 'Notiz') . ': ' . $note) . '</span>' : '')
    . '</div>';
  return delegation_notification_email_frame(
    $recipientName,
    $lang === 'en' ? 'Delegation completed' : 'Delegation fertiggemeldet',
    $lang === 'en'
      ? 'A delegated report area has been marked as completed.'
      : 'Ein delegierter Bereich wurde als fertig markiert.',
    $details,
    $link,
    $lang === 'en' ? 'Review delegation' : 'Delegation prüfen',
    $lang
  );
}

function notify_delegation_assignments(PDO $pdo, array $events, int $actorUserId): int {
  $byRecipient = [];
  $seen = [];
  foreach ($events as $event) {
    $recipientId = (int)($event['user_id'] ?? 0);
    $key = implode('|', [
      $recipientId,
      (int)($event['class_id'] ?? 0),
      (string)($event['school_year'] ?? ''),
      (string)($event['period_label'] ?? ''),
      (string)($event['group_key'] ?? ''),
    ]);
    if ($recipientId <= 0 || isset($seen[$key])) continue;
    $seen[$key] = true;
    $byRecipient[$recipientId][] = $event;
  }

  $sent = 0;
  $classCache = [];
  foreach ($byRecipient as $recipientId => $recipientEvents) {
    try {
      $recipient = delegation_notification_recipient($pdo, (int)$recipientId);
      if (!$recipient) {
        audit('delegation_assignment_email', $actorUserId, [
          'recipient_user_id' => (int)$recipientId,
          'assignment_count' => count($recipientEvents),
          'sent' => false,
          'reason' => 'recipient_email_unavailable',
        ]);
        continue;
      }
      $lang = (string)$recipient['lang'];
      $assignments = [];
      foreach ($recipientEvents as $event) {
        $classId = (int)($event['class_id'] ?? 0);
        if (!array_key_exists($classId, $classCache)) {
          $classCache[$classId] = delegation_notification_class($pdo, $classId);
        }
        $class = $classCache[$classId];
        if (!$class) continue;
        $assignments[] = [
          'class_label' => delegation_notification_class_label($class),
          'group_label' => delegation_notification_group_label((string)($event['group_key'] ?? ''), $lang),
          'school_year' => (string)($event['school_year'] ?? ''),
          'period_label' => delegation_notification_period_label((string)($event['period_label'] ?? ''), $lang),
          'note' => (string)($event['note'] ?? ''),
        ];
      }
      if (!$assignments) continue;
      $subject = $lang === 'en' ? 'LEB Tool - New delegation' : 'LEB-Tool - Neue Delegation';
      $html = build_delegation_assignment_email(
        (string)$recipient['name'],
        $assignments,
        absolute_url('teacher/delegations.php'),
        $lang
      );
      $ok = send_email((string)$recipient['email'], $subject, $html);
      audit('delegation_assignment_email', $actorUserId, [
        'recipient_user_id' => (int)$recipientId,
        'assignment_count' => count($assignments),
        'sent' => $ok,
      ]);
      if ($ok) $sent++;
    } catch (Throwable $e) {
      error_log('[delegation assignment mail] ' . $e->getMessage());
      try {
        audit('delegation_assignment_email', $actorUserId, [
          'recipient_user_id' => (int)$recipientId,
          'assignment_count' => count($recipientEvents),
          'sent' => false,
          'reason' => 'mail_exception',
        ]);
      } catch (Throwable $ignored) {
      }
    }
  }
  return $sent;
}

function notify_delegation_completion(PDO $pdo, array $event, int $actorUserId): bool {
  $delegatorId = (int)($event['delegator_user_id'] ?? 0);
  $delegateId = (int)($event['delegate_user_id'] ?? $actorUserId);
  if ($delegatorId <= 0 || $delegatorId === $delegateId) return false;

  try {
    $recipient = delegation_notification_recipient($pdo, $delegatorId);
    $class = delegation_notification_class($pdo, (int)($event['class_id'] ?? 0));
    if (!$recipient || !$class) {
      audit('delegation_completion_email', $actorUserId, [
        'recipient_user_id' => $delegatorId,
        'delegate_user_id' => $delegateId,
        'class_id' => (int)($event['class_id'] ?? 0),
        'group_key' => (string)($event['group_key'] ?? ''),
        'sent' => false,
        'reason' => !$recipient ? 'recipient_email_unavailable' : 'class_unavailable',
      ]);
      return false;
    }
    $lang = (string)$recipient['lang'];
    $completion = [
      'class_label' => delegation_notification_class_label($class),
      'group_label' => delegation_notification_group_label((string)($event['group_key'] ?? ''), $lang),
      'school_year' => (string)($event['school_year'] ?? ''),
      'period_label' => delegation_notification_period_label((string)($event['period_label'] ?? ''), $lang),
      'delegate_name' => delegation_notification_user_name($pdo, $delegateId),
      'note' => (string)($event['note'] ?? ''),
    ];
    $subject = $lang === 'en' ? 'LEB Tool - Delegation completed' : 'LEB-Tool - Delegation fertiggemeldet';
    $html = build_delegation_completion_email(
      (string)$recipient['name'],
      $completion,
      absolute_url('teacher/delegations.php'),
      $lang
    );
    $ok = send_email((string)$recipient['email'], $subject, $html);
    audit('delegation_completion_email', $actorUserId, [
      'recipient_user_id' => $delegatorId,
      'delegate_user_id' => $delegateId,
      'class_id' => (int)($event['class_id'] ?? 0),
      'group_key' => (string)($event['group_key'] ?? ''),
      'sent' => $ok,
    ]);
    return $ok;
  } catch (Throwable $e) {
    error_log('[delegation completion mail] ' . $e->getMessage());
    try {
      audit('delegation_completion_email', $actorUserId, [
        'recipient_user_id' => $delegatorId,
        'delegate_user_id' => $delegateId,
        'class_id' => (int)($event['class_id'] ?? 0),
        'group_key' => (string)($event['group_key'] ?? ''),
        'sent' => false,
        'reason' => 'mail_exception',
      ]);
    } catch (Throwable $ignored) {
    }
    return false;
  }
}
