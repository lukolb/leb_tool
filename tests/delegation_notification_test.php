<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/delegation_notifications.php';

function delegation_notification_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$entryApi = file_get_contents($root . '/teacher/ajax/entry_api.php');
$delegationsApi = file_get_contents($root . '/teacher/ajax/delegations_api.php');
delegation_notification_assert(is_string($entryApi) && is_string($delegationsApi), 'delegation endpoints must be readable');

delegation_notification_assert(
  delegation_new_user_ids([2, 3], [2, 3]) === [],
  'saving an unchanged delegation must not create duplicate assignment emails'
);
delegation_notification_assert(
  delegation_new_user_ids([2], [2, 3, 3]) === [3],
  'only newly assigned users must receive an assignment email'
);
delegation_notification_assert(
  delegation_status_became_done('open', 'done'),
  'the open-to-done transition must trigger a completion email'
);
delegation_notification_assert(
  !delegation_status_became_done('done', 'done') && !delegation_status_became_done('open', 'open'),
  'unchanged delegation states must not trigger completion emails'
);

$assignmentHtml = build_delegation_assignment_email('Ada', [[
  'group_label' => 'Deutsch',
  'class_label' => '1a',
  'school_year' => '2026/27',
  'period_label' => '1. Halbjahr',
  'note' => 'Bitte bis Freitag.',
]], 'https://leb.example/teacher/delegations.php', 'de');
delegation_notification_assert(
  str_contains($assignmentHtml, 'Neue Delegation')
    && str_contains($assignmentHtml, 'Deutsch')
    && str_contains($assignmentHtml, '1a')
    && str_contains($assignmentHtml, 'Bitte bis Freitag.'),
  'assignment email must contain the delegated area and its context'
);

$completionHtml = build_delegation_completion_email('Grace', [
  'group_label' => 'Mathematik',
  'class_label' => '2b',
  'school_year' => '2026/27',
  'period_label' => '2nd semester',
  'delegate_name' => 'Ada',
  'note' => 'Ready for review.',
], 'https://leb.example/teacher/delegations.php', 'en');
delegation_notification_assert(
  str_contains($completionHtml, 'Delegation completed')
    && str_contains($completionHtml, 'Completed by: Ada')
    && str_contains($completionHtml, 'Ready for review.'),
  'completion email must identify the delegate and include their note'
);

foreach ([$entryApi, $delegationsApi] as $source) {
  delegation_notification_assert(
    str_contains($source, 'notify_delegation_assignments')
      && str_contains($source, 'notify_delegation_completion')
      && str_contains($source, 'delegation_status_became_done'),
    'every delegation endpoint must send assignment and completion notifications without duplicates'
  );
}

fwrite(STDOUT, "delegation_notification_test: OK\n");
