<?php
declare(strict_types=1);

function template_package_notification_title(array $pkgOrMeta): string {
    $title = trim((string)($pkgOrMeta['title'] ?? ''));
    return $title !== '' ? $title : t('template_packages.untitled', 'Untitled package');
}

function template_package_notification_send(string $to, string $subject, string $htmlBody, array $context = []): bool {
    $to = trim($to);
    if ($to === '') return false;
    try {
        $ok = send_email($to, $subject, $htmlBody);
        if (!$ok) {
            error_log('template package notification failed: ' . json_encode(['to' => $to, 'subject' => $subject, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('template package notification exception: ' . json_encode(['to' => $to, 'subject' => $subject, 'error' => $e->getMessage(), 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return false;
    }
}

function template_package_admin_recipients(PDO $pdo): array {
    try {
        $st = $pdo->query("SELECT email, display_name FROM users WHERE role='admin' AND is_active=1 AND deleted_at IS NULL AND email<>'' ORDER BY id ASC");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('template package admin recipient lookup failed: ' . $e->getMessage());
        return [];
    }
}

function template_package_notify_admin_submission(PDO $pdo, array $packageResult, array $metadata): bool {
    $recipients = template_package_admin_recipients($pdo);
    if (!$recipients) {
        error_log('template package submission notification skipped: no admin recipients');
        return false;
    }
    $packageId = (int)($packageResult['id'] ?? 0);
    $title = template_package_notification_title($metadata);
    $teacherName = trim((string)($metadata['submitted_by_name'] ?? $metadata['created_by_name'] ?? ''));
    $teacherEmail = trim((string)($metadata['submitted_by_email'] ?? ''));
    $grade = (string)($metadata['grade_level'] ?? '');
    $source = (string)($metadata['template_source'] ?? '');
    $st = !empty($metadata['student_teacher_ratings']) ? t('ui.yes', 'Yes') : t('ui.no', 'No');
    $link = absolute_url('admin/template_packages.php?action=view&id=' . $packageId);
    $subject = t('notifications.template_submitted.subject', 'New PDF template submitted');
    $body = '<p>' . h(sprintf(t('notifications.template_submitted.body_intro', 'A new PDF template was submitted by %s: %s.'), $teacherName !== '' ? $teacherName : ('#' . (string)($metadata['submitted_by_user_id'] ?? '')), $title)) . '</p>'
        . '<ul>'
        . '<li>' . h(t('notifications.field.teacher_email', 'Teacher email')) . ': ' . h($teacherEmail !== '' ? $teacherEmail : '—') . '</li>'
        . '<li>' . h(t('notifications.field.grade', 'Grade')) . ': ' . h($grade !== '' ? $grade : '—') . '</li>'
        . '<li>' . h(t('notifications.field.template_source', 'Template source')) . ': ' . h($source !== '' ? $source : '—') . '</li>'
        . '<li>' . h(t('notifications.field.student_teacher', 'Student/teacher ratings')) . ': ' . h($st) . '</li>'
        . '</ul><p><a href="' . h($link) . '">' . h(t('notifications.template_submitted.link', 'Open template package management')) . '</a></p>';
    $sent = false;
    foreach ($recipients as $recipient) {
        $sent = template_package_notification_send((string)($recipient['email'] ?? ''), $subject, $body, ['event' => 'submitted', 'package_id' => $packageId]) || $sent;
    }
    return $sent;
}

function template_package_submitter_email(array $pkg, array $metadata): string {
    return trim((string)($metadata['submitted_by_email'] ?? $pkg['created_by_email'] ?? ''));
}

function template_package_notify_teacher_imported(array $pkg, string $templateName): bool {
    $metadata = json_decode((string)($pkg['metadata_json'] ?? '{}'), true);
    if (!is_array($metadata)) $metadata = [];
    if ((string)($pkg['created_by_role'] ?? '') !== 'teacher' && empty($metadata['submitted_by_user_id'])) return false;
    $to = template_package_submitter_email($pkg, $metadata);
    if ($to === '') return false;
    $title = template_package_notification_title($metadata + $pkg);
    $subject = t('notifications.template_imported.subject', 'Your PDF template was imported');
    $body = '<p>' . h(sprintf(t('notifications.template_imported.body', 'Your submitted PDF template "%s" was imported as template "%s".'), $title, $templateName)) . '</p>';
    return template_package_notification_send($to, $subject, $body, ['event' => 'imported', 'package_id' => (int)($pkg['id'] ?? 0)]);
}

function template_package_notify_teacher_deleted(array $pkg): bool {
    $metadata = json_decode((string)($pkg['metadata_json'] ?? '{}'), true);
    if (!is_array($metadata)) $metadata = [];
    if ((string)($pkg['status'] ?? '') === 'imported') return false;
    if ((string)($pkg['created_by_role'] ?? '') !== 'teacher' && empty($metadata['submitted_by_user_id'])) return false;
    $to = template_package_submitter_email($pkg, $metadata);
    if ($to === '') return false;
    $title = template_package_notification_title($metadata + $pkg);
    $subject = t('notifications.template_deleted.subject', 'Your submitted PDF template was removed');
    $body = '<p>' . h(sprintf(t('notifications.template_deleted.body', 'Your submitted PDF template "%s" is no longer listed in template package management.'), $title)) . '</p>';
    return template_package_notification_send($to, $subject, $body, ['event' => 'deleted', 'package_id' => (int)($pkg['id'] ?? 0)]);
}
