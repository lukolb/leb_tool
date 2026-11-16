<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/shared/report_request_notifications.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$pdo = db();
$requestId = max(0, (int)($_REQUEST['request_id'] ?? 0));
$duration = (int)($_REQUEST['duration'] ?? 30);
if (!in_array($duration, [0, 7, 30], true)) $duration = 30;
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $rawToken = trim((string)($_POST['token'] ?? ''));
    if ($requestId <= 0 || !preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
      throw new RuntimeException(t('report_requests.email_approval.invalid', 'Der Freigabelink ist ungültig oder unvollständig.'));
    }
    $pdo->beginTransaction();
    $st = $pdo->prepare(
      "SELECT tok.id AS token_id, tok.reviewer_user_id, rar.class_id, rar.status,
              u.role, u.is_active, u.deleted_at
       FROM report_access_approval_tokens tok
       INNER JOIN report_access_requests rar ON rar.id=tok.request_id
       INNER JOIN users u ON u.id=tok.reviewer_user_id
       WHERE tok.request_id=? AND tok.token_hash=? AND tok.used_at IS NULL AND tok.expires_at>=NOW()
       LIMIT 1 FOR UPDATE"
    );
    $st->execute([$requestId, hash('sha256', $rawToken)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)($row['is_active'] ?? 0) !== 1 || $row['deleted_at'] !== null) {
      throw new RuntimeException(t('report_requests.email_approval.expired', 'Dieser Freigabelink ist abgelaufen oder wurde bereits verwendet.'));
    }
    if ((string)($row['status'] ?? '') !== 'pending') {
      throw new RuntimeException(t('report_requests.email_approval.decided', 'Über diesen Antrag wurde bereits entschieden.'));
    }
    $reviewerId = (int)($row['reviewer_user_id'] ?? 0);
    $classId = (int)($row['class_id'] ?? 0);
    $authorized = (string)($row['role'] ?? '') === 'admin';
    if (!$authorized) {
      $stOwner = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
      $stOwner->execute([$reviewerId, $classId]);
      $authorized = (bool)$stOwner->fetchColumn();
    }
    if (!$authorized) throw new RuntimeException(t('session.forbidden.message', 'Keine Berechtigung.'));

    $expiresAt = $duration > 0 ? (new DateTimeImmutable('now'))->modify('+' . $duration . ' days')->format('Y-m-d H:i:s') : null;
    $pdo->prepare(
      "UPDATE report_access_requests
       SET status='approved', reviewed_by_user_id=?, reviewed_at=NOW(), expires_at=?, updated_at=NOW()
       WHERE id=? AND status='pending'"
    )->execute([$reviewerId, $expiresAt, $requestId]);
    $pdo->prepare("UPDATE report_access_approval_tokens SET used_at=NOW() WHERE request_id=? AND used_at IS NULL")
      ->execute([$requestId]);
    $pdo->commit();
    notify_requester_about_report_access_decision($pdo, $requestId, 'approved');
    audit('report_access_request_approve_email', $reviewerId, [
      'request_id' => $requestId,
      'class_id' => $classId,
      'duration_days' => $duration,
    ]);
    $success = true;
    $message = $duration > 0
      ? str_replace('{days}', (string)$duration, t('report_requests.email_approval.success_days', 'Die Berichteinsicht wurde für {days} Tage freigegeben.'))
      : t('report_requests.email_approval.success_unlimited', 'Die Berichteinsicht wurde ohne Ablaufdatum freigegeben.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(t('report_requests.email_approval.title', 'Berichteinsicht freigeben'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body class="page">
  <main class="card" style="max-width:680px;margin:48px auto;">
    <h1><?=h(t('report_requests.email_approval.title', 'Berichteinsicht freigeben'))?></h1>
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <div class="alert <?=$success ? 'success' : 'danger'?>"><?=h($message)?></div>
    <?php else: ?>
      <div class="alert" id="approvalStatus"><?=h(t('report_requests.email_approval.processing', 'Die Freigabe wird sicher verarbeitet…'))?></div>
      <form id="approvalForm" method="post">
        <input type="hidden" name="request_id" value="<?=h((string)$requestId)?>">
        <input type="hidden" name="duration" value="<?=h((string)$duration)?>">
        <input type="hidden" id="approvalToken" name="token" value="">
      </form>
      <noscript><div class="alert danger"><?=h(t('report_requests.email_approval.javascript', 'Bitte aktivieren Sie JavaScript, um den Ein-Klick-Link zu verwenden.'))?></div></noscript>
      <script>
        const token = new URLSearchParams(window.location.hash.slice(1)).get('token') || '';
        if (/^[a-f0-9]{64}$/i.test(token)) {
          document.getElementById('approvalToken').value = token;
          history.replaceState(null, document.title, location.pathname + location.search);
          document.getElementById('approvalForm').submit();
        } else {
          document.getElementById('approvalStatus').className = 'alert danger';
          document.getElementById('approvalStatus').textContent = <?=json_encode(t('report_requests.email_approval.invalid', 'Der Freigabelink ist ungültig oder unvollständig.'))?>;
        }
      </script>
    <?php endif; ?>
  </main>
</body>
</html>
