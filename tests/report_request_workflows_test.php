<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  'bootstrap' => 'bootstrap.php',
  'install' => 'install.php',
  'preview' => 'shared/report_preview_page.php',
  'access_page' => 'shared/report_access_requests_page.php',
  'notifications' => 'shared/report_request_notifications.php',
  'admin' => 'admin/report_requests.php',
  'email_approve' => 'report_access_approve.php',
  'layout' => 'shared/_layout.php',
];
$source = [];
foreach ($paths as $key => $path) {
  $source[$key] = file_get_contents($root . '/' . $path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function report_request_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

foreach (['report_deletion_requests', 'report_access_requests', 'report_access_approval_tokens'] as $table) {
  report_request_assert(strpos($source['install'], "CREATE TABLE IF NOT EXISTS `{$table}`") !== false, "fresh installs must create {$table}");
  report_request_assert(strpos($source['bootstrap'], "CREATE TABLE {$table}") !== false, "existing installs must create {$table}");
}
report_request_assert(strpos($source['preview'], "request_report_deletion") !== false, 'teachers must be able to request deletion of the exact report');
report_request_assert(strpos($source['preview'], 'notify_admins_about_report_deletion_request') !== false, 'deletion requests must notify administrators');
report_request_assert(strpos($source['admin'], 'delete_request_approve_bulk') !== false && strpos($source['admin'], 'request_ids[]') !== false, 'administrators need batch approval for deletion requests');
report_request_assert(strpos($source['admin'], 'delete_student_report_permanently') !== false, 'approved deletions must use the centralized exact-report deletion helper');
report_request_assert(strpos($source['access_page'], "['student', 'class']") !== false, 'report access requests must support student and class scope');
report_request_assert(strpos($source['access_page'], "\$accessActualRole") !== false && strpos($source['access_page'], "=== 'teacher'") !== false, 'only actual teacher accounts may create report access requests');
report_request_assert(strpos($source['access_page'], 'user_can_review_report_access_request') !== false, 'only administrators or assigned class teachers may review access requests');
report_request_assert(strpos($source['access_page'], "status='revoked'") !== false, 'approved access must be revocable');
report_request_assert(strpos($source['access_page'], 'extend_access') !== false && strpos($source['access_page'], 'expires_at') !== false, 'approved access must support optional expiry and extension');
report_request_assert(strpos($source['bootstrap'], "rar.expires_at IS NULL OR rar.expires_at>=NOW()") !== false, 'expired access grants must be rejected by authorization checks');
report_request_assert(strpos($source['bootstrap'], "scope_type='student'\n       AND student_id=?") !== false, 'a student grant must authorize the student independently of the report class period');
report_request_assert(strpos($source['preview'], 'student_grant.student_id=?') !== false && strpos($source['preview'], "class_grant.scope_type='class'") !== false, 'student grants must expose the full report history while class grants remain period-bound');
report_request_assert(strpos($source['access_page'], "requester_user_id=? AND scope_type='student' AND student_id=?") !== false, 'duplicate student grants must be detected across classes');
report_request_assert(strpos($source['access_page'], 'rar.requester_user_id=') !== false && strpos($source['access_page'], '$canReturnOwnGrant') !== false, 'requesters must see their own grants and be able to return them');
report_request_assert(strpos($source['notifications'], 'report_access_approval_tokens') !== false && strpos($source['notifications'], "hash('sha256', \$rawToken)") !== false, 'each email reviewer must receive a hashed one-time approval token');
report_request_assert(strpos($source['notifications'], 'approve_7') !== false && strpos($source['notifications'], 'approve_30') !== false && strpos($source['notifications'], 'approve_unlimited') !== false, 'approval emails must offer 7-day, 30-day, and unlimited grants');
report_request_assert(strpos($source['email_approve'], 'window.location.hash') !== false, 'the raw email token must stay in the URL fragment and out of server logs');
report_request_assert(strpos($source['email_approve'], "Cache-Control: no-store") !== false && strpos($source['email_approve'], "Referrer-Policy: no-referrer") !== false, 'email approvals must not be cached or leaked through referrers');
report_request_assert(strpos($source['email_approve'], 'used_at=NOW()') !== false, 'email approval tokens must be invalidated after use');
report_request_assert(strpos($source['notifications'], 'notify_requester_about_report_access_decision') !== false && strpos($source['notifications'], 'requester.email AS requester_email') !== false, 'the requester decision notification must resolve the original requester email');
report_request_assert(substr_count($source['access_page'], 'notify_requester_about_report_access_decision') >= 1, 'web approvals and rejections must notify the requester');
report_request_assert(strpos($source['email_approve'], "notify_requester_about_report_access_decision(\$pdo, \$requestId, 'approved')") !== false, 'one-click email approvals must also notify the requester');
report_request_assert(strpos($source['layout'], "nav.report_requests") !== false, 'request management must be reachable from teacher and admin navigation');
report_request_assert(strpos($source['access_page'], 'parent_portal_links') === false, 'teacher-to-teacher access grants must remain separate from parent access');

fwrite(STDOUT, "report_request_workflows_test: OK\n");
