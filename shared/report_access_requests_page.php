<?php
declare(strict_types=1);

if (!isset($pdo, $u, $isAdmin)) throw new RuntimeException('report_access_requests_page.php: context missing');
require_once __DIR__ . '/report_request_notifications.php';

$accessUserId = (int)($u['id'] ?? 0);
$accessActualRole = (string)($u['actual_role'] ?? $u['role'] ?? '');
$isTeacherRequester = !$isAdmin && $accessActualRole === 'teacher';
$accessOk = '';
$accessError = '';
$accessActions = ['request_access', 'approve_access', 'reject_access', 'revoke_access', 'extend_access'];

function report_access_class_label(array $row): string {
  $grade = $row['grade_level'] !== null ? (int)$row['grade_level'] : null;
  $label = trim((string)($row['label'] ?? ''));
  $name = trim((string)($row['name'] ?? ''));
  $base = ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($row['class_id'] ?? $row['id'] ?? 0)));
  $period = normalize_class_period_label((string)($row['period_label'] ?? 'H1')) === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
  return $base . ' · ' . (string)($row['school_year'] ?? '') . ' · ' . $period;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), $accessActions, true)) {
  try {
    csrf_verify();
    $action = (string)$_POST['action'];
    if ($action === 'request_access') {
      if (!$isTeacherRequester) throw new RuntimeException(t('report_requests.error.teacher_only', 'Nur Lehrkraftkonten können Einsicht beantragen.'));
      $classId = max(0, (int)($_POST['class_id'] ?? 0));
      $scopeType = (string)($_POST['scope_type'] ?? 'student');
      $studentId = $scopeType === 'student' ? max(0, (int)($_POST['student_id'] ?? 0)) : 0;
      $reason = trim((string)($_POST['reason'] ?? ''));
      if ($classId <= 0 || !in_array($scopeType, ['student', 'class'], true) || $reason === '') {
        throw new RuntimeException(t('report_requests.error.incomplete', 'Bitte Klasse, Umfang und Begründung vollständig angeben.'));
      }
      $stClass = $pdo->prepare("SELECT id, school_year, period_label, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
      $stClass->execute([$classId]);
      $requestClass = $stClass->fetch(PDO::FETCH_ASSOC);
      if (!$requestClass) throw new RuntimeException(t('report_requests.error.class_not_found', 'Klasse nicht gefunden.'));
      $stOwn = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
      $stOwn->execute([$accessUserId, $classId]);
      if ($stOwn->fetchColumn()) throw new RuntimeException(t('report_requests.error.already_owner', 'Sie sind dieser Klasse bereits zugeordnet und benötigen keine zusätzliche Freigabe.'));
      if ($scopeType === 'student') {
        if ($studentId <= 0) throw new RuntimeException(t('report_requests.error.student_required', 'Bitte einen Schüler auswählen.'));
        $stStudent = $pdo->prepare(
          "SELECT s.id, s.first_name, s.last_name
           FROM students s
           INNER JOIN student_class_assignments sca ON sca.student_id=COALESCE(NULLIF(s.master_student_id, 0), s.id)
           WHERE s.id=? AND COALESCE(NULLIF(s.master_student_id, 0), s.id)=s.id AND sca.class_id=?
           LIMIT 1"
        );
        $stStudent->execute([$studentId, $classId]);
        $requestStudent = $stStudent->fetch(PDO::FETCH_ASSOC);
        if (!$requestStudent) throw new RuntimeException(t('report_requests.error.student_not_in_class', 'Der Schüler ist dieser Klasse nicht zugeordnet.'));
      } else {
        $requestStudent = null;
      }
      $pdo->beginTransaction();
      $stLockClass = $pdo->prepare("SELECT id FROM classes WHERE id=? LIMIT 1 FOR UPDATE");
      $stLockClass->execute([$classId]);
      if (!$stLockClass->fetchColumn()) throw new RuntimeException(t('report_requests.error.class_not_found', 'Klasse nicht gefunden.'));
      if ($scopeType === 'student') {
        $stLockStudent = $pdo->prepare("SELECT id FROM students WHERE id=? LIMIT 1 FOR UPDATE");
        $stLockStudent->execute([$studentId]);
        if (!$stLockStudent->fetchColumn()) throw new RuntimeException(t('report_requests.error.student_not_in_class', 'Der Schüler ist dieser Klasse nicht zugeordnet.'));
        $duplicateSql =
          "SELECT id FROM report_access_requests
           WHERE requester_user_id=? AND scope_type='student' AND student_id=?
             AND (status='pending' OR (status='approved' AND (expires_at IS NULL OR expires_at>=NOW())))
           LIMIT 1";
        $duplicateParams = [$accessUserId, $studentId];
      } else {
        $duplicateSql =
          "SELECT id FROM report_access_requests
           WHERE requester_user_id=? AND class_id=? AND scope_type='class' AND student_id IS NULL
             AND (status='pending' OR (status='approved' AND (expires_at IS NULL OR expires_at>=NOW())))
           LIMIT 1";
        $duplicateParams = [$accessUserId, $classId];
      }
      $stDuplicate = $pdo->prepare($duplicateSql);
      $stDuplicate->execute($duplicateParams);
      if ($stDuplicate->fetchColumn()) throw new RuntimeException(t('report_requests.error.duplicate', 'Für diesen Umfang besteht bereits eine offene oder aktive Freigabe.'));

      $pdo->prepare(
        "INSERT INTO report_access_requests
          (requester_user_id, class_id, student_id, scope_type, reason, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
      )->execute([$accessUserId, $classId, $scopeType === 'student' ? $studentId : null, $scopeType, $reason]);
      $requestId = (int)$pdo->lastInsertId();
      $pdo->commit();
      $scopeLabel = $scopeType === 'student'
        ? trim((string)($requestStudent['first_name'] ?? '') . ' ' . (string)($requestStudent['last_name'] ?? ''))
        : t('report_requests.scope.whole_class', 'Gesamte Klasse');
      notify_reviewers_about_report_access_request($pdo, [
        'request_id' => $requestId,
        'class_id' => $classId,
        'requester_name' => (string)($u['display_name'] ?? $u['email'] ?? ''),
        'scope_label' => $scopeLabel,
        'class_label' => report_access_class_label($requestClass),
        'reason' => $reason,
      ]);
      audit('report_access_request_create', $accessUserId, ['request_id'=>$requestId,'class_id'=>$classId,'student_id'=>$studentId,'scope_type'=>$scopeType]);
      $accessOk = t('report_requests.ok.requested', 'Die Einsicht wurde beantragt. Die berechtigten Personen wurden per E-Mail benachrichtigt.');
    } else {
      $requestId = max(0, (int)($_POST['request_id'] ?? 0));
      if ($requestId <= 0) throw new RuntimeException(t('report_requests.error.not_found', 'Antrag nicht gefunden.'));
      $pdo->beginTransaction();
      $stRequest = $pdo->prepare("SELECT * FROM report_access_requests WHERE id=? LIMIT 1 FOR UPDATE");
      $stRequest->execute([$requestId]);
      $requestRow = $stRequest->fetch(PDO::FETCH_ASSOC);
      if (!$requestRow) throw new RuntimeException(t('report_requests.error.not_found', 'Antrag nicht gefunden.'));
      $canReviewRequest = user_can_review_report_access_request($pdo, $accessUserId, (int)$requestRow['class_id']);
      $canReturnOwnGrant = $action === 'revoke_access' && (int)$requestRow['requester_user_id'] === $accessUserId;
      if (!$canReviewRequest && !$canReturnOwnGrant) {
        throw new RuntimeException(t('session.forbidden.message', 'Keine Berechtigung.'));
      }
      if ($action === 'approve_access') {
        if ((string)$requestRow['status'] !== 'pending') throw new RuntimeException(t('report_requests.error.already_decided', 'Über diesen Antrag wurde bereits entschieden.'));
        $days = max(0, min(3650, (int)($_POST['valid_days'] ?? 30)));
        $expiresAt = $days > 0 ? (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE report_access_requests SET status='approved', reviewed_by_user_id=?, reviewed_at=NOW(), expires_at=?, updated_at=NOW() WHERE id=?")
          ->execute([$accessUserId, $expiresAt, $requestId]);
        $accessOk = t('report_requests.ok.approved', 'Die Berichteinsicht wurde freigegeben.');
      } elseif ($action === 'reject_access') {
        if ((string)$requestRow['status'] !== 'pending') throw new RuntimeException(t('report_requests.error.already_decided', 'Über diesen Antrag wurde bereits entschieden.'));
        $pdo->prepare("UPDATE report_access_requests SET status='rejected', reviewed_by_user_id=?, reviewed_at=NOW(), expires_at=NULL, updated_at=NOW() WHERE id=?")
          ->execute([$accessUserId, $requestId]);
        $accessOk = t('report_requests.ok.rejected', 'Die Anfrage wurde abgelehnt.');
      } elseif ($action === 'revoke_access') {
        if ((string)$requestRow['status'] !== 'approved') throw new RuntimeException(t('report_requests.error.not_active', 'Diese Freigabe ist nicht aktiv.'));
        $pdo->prepare("UPDATE report_access_requests SET status='revoked', reviewed_by_user_id=?, reviewed_at=NOW(), expires_at=NOW(), updated_at=NOW() WHERE id=?")
          ->execute([$accessUserId, $requestId]);
        $accessOk = t('report_requests.ok.revoked', 'Die Berichteinsicht wurde zurückgezogen.');
      } elseif ($action === 'extend_access') {
        if ((string)$requestRow['status'] !== 'approved') throw new RuntimeException(t('report_requests.error.not_active', 'Diese Freigabe ist nicht aktiv.'));
        $days = max(1, min(3650, (int)($_POST['extend_days'] ?? 30)));
        $base = !empty($requestRow['expires_at']) && strtotime((string)$requestRow['expires_at']) > time()
          ? new DateTimeImmutable((string)$requestRow['expires_at'])
          : new DateTimeImmutable('now');
        $expiresAt = $base->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE report_access_requests SET expires_at=?, reviewed_by_user_id=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
          ->execute([$expiresAt, $accessUserId, $requestId]);
        $accessOk = t('report_requests.ok.extended', 'Die Freigabe wurde verlängert.');
      }
      if (in_array($action, ['approve_access','reject_access'], true)) {
        $pdo->prepare("UPDATE report_access_approval_tokens SET used_at=NOW() WHERE request_id=? AND used_at IS NULL")->execute([$requestId]);
      }
      $pdo->commit();
      if (in_array($action, ['approve_access','reject_access'], true)) {
        notify_requester_about_report_access_decision(
          $pdo,
          $requestId,
          $action === 'approve_access' ? 'approved' : 'rejected'
        );
      }
      audit('report_access_request_' . $action, $accessUserId, ['request_id'=>$requestId,'class_id'=>(int)$requestRow['class_id']]);
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $accessError = $e->getMessage();
  }
}

$requestableClasses = [];
$requestableStudents = [];
if ($isTeacherRequester) {
  $stClasses = $pdo->prepare(
    "SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name, c.is_active
     FROM classes c
     WHERE NOT EXISTS (SELECT 1 FROM user_class_assignments uca WHERE uca.class_id=c.id AND uca.user_id=?)
     ORDER BY c.is_active DESC, c.school_year DESC, CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC, c.grade_level, c.label, c.name"
  );
  $stClasses->execute([$accessUserId]);
  $requestableClasses = $stClasses->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($requestableClasses) {
    $classIds = array_map(static fn(array $row): int => (int)$row['id'], $requestableClasses);
    $in = implode(',', array_fill(0, count($classIds), '?'));
    $stStudents = $pdo->prepare(
      "SELECT DISTINCT sca.class_id, s.id, s.first_name, s.last_name
       FROM student_class_assignments sca
       INNER JOIN students s ON s.id=sca.student_id AND COALESCE(NULLIF(s.master_student_id, 0), s.id)=s.id
       WHERE sca.class_id IN ($in)
       ORDER BY s.last_name, s.first_name, s.id"
    );
    $stStudents->execute($classIds);
    $requestableStudents = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

$reviewWhere = $isAdmin
  ? '1=1'
  : 'EXISTS (SELECT 1 FROM user_class_assignments reviewer_uca WHERE reviewer_uca.class_id=rar.class_id AND reviewer_uca.user_id=' . (int)$accessUserId . ')';
$requestBaseSql =
  "SELECT rar.*, requester.display_name AS requester_name, reviewer.display_name AS reviewer_name,
          c.school_year, c.period_label, c.grade_level, c.label, c.name,
          s.first_name AS student_first_name, s.last_name AS student_last_name
   FROM report_access_requests rar
   INNER JOIN users requester ON requester.id=rar.requester_user_id
   LEFT JOIN users reviewer ON reviewer.id=rar.reviewed_by_user_id
   INNER JOIN classes c ON c.id=rar.class_id
   LEFT JOIN students s ON s.id=rar.student_id";
$visibleRequestWhere = $isAdmin
  ? $reviewWhere
  : "(($reviewWhere) OR rar.requester_user_id=" . (int)$accessUserId . ")";
$pendingAccessRequests = $pdo->query($requestBaseSql . " WHERE rar.status='pending' AND ($visibleRequestWhere) ORDER BY rar.created_at ASC, rar.id ASC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeAccessGrants = $pdo->query($requestBaseSql . " WHERE rar.status='approved' AND ($visibleRequestWhere) ORDER BY (rar.expires_at IS NULL) DESC, rar.expires_at DESC, rar.id DESC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ownAccessRequests = [];
if (!$isAdmin) {
  $stOwnRequests = $pdo->prepare($requestBaseSql . " WHERE rar.requester_user_id=? ORDER BY rar.created_at DESC, rar.id DESC LIMIT 100");
  $stOwnRequests->execute([$accessUserId]);
  $ownAccessRequests = $stOwnRequests->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function report_access_scope_label(array $row): string {
  if ((string)($row['scope_type'] ?? '') === 'class') return t('report_requests.scope.whole_class', 'Gesamte Klasse');
  return trim((string)($row['student_first_name'] ?? '') . ' ' . (string)($row['student_last_name'] ?? ''));
}
?>

<?php if ($accessError !== ''): ?><div class="alert danger"><?=h($accessError)?></div><?php endif; ?>
<?php if ($accessOk !== ''): ?><div class="alert success"><?=h($accessOk)?></div><?php endif; ?>

<?php if ($isTeacherRequester): ?>
<section class="card" id="request-access">
  <h2 style="margin-top:0"><?=h(t('report_requests.request.heading', 'Berichteinsicht beantragen'))?></h2>
  <p class="muted"><?=h(t('report_requests.request.intro', 'Beantragen Sie die Einsicht für einen einzelnen Schüler oder für alle Berichte einer Klasse. Admins und die zuständigen Klassenlehrkräfte können die Anfrage freigeben.'))?></p>
  <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="request_access">
    <label><span class="label"><?=h(t('report_requests.scope.label', 'Umfang'))?></span><select class="input" id="accessScope" name="scope_type"><option value="student"><?=h(t('report_requests.scope.student', 'Einzelner Schüler'))?></option><option value="class"><?=h(t('report_requests.scope.whole_class', 'Gesamte Klasse'))?></option></select></label>
    <label><span class="label"><?=h(t('report_requests.class.label', 'Klasse'))?></span><select class="input" id="accessClass" name="class_id" required><option value=""><?=h(t('report_requests.choose', '— auswählen —'))?></option><?php foreach ($requestableClasses as $class): ?><option value="<?=h((string)$class['id'])?>"><?=h(report_access_class_label($class))?></option><?php endforeach; ?></select></label>
    <label id="accessStudentWrap"><span class="label"><?=h(t('report_requests.student.label', 'Schüler'))?></span><select class="input" id="accessStudent" name="student_id" required><option value=""><?=h(t('report_requests.choose', '— auswählen —'))?></option><?php foreach ($requestableStudents as $student): ?><option value="<?=h((string)$student['id'])?>" data-class-id="<?=h((string)$student['class_id'])?>"><?=h(trim((string)$student['last_name'] . ', ' . (string)$student['first_name']))?></option><?php endforeach; ?></select></label>
    <label style="grid-column:1/-1"><span class="label"><?=h(t('report_requests.reason.label', 'Begründung'))?></span><textarea class="input" name="reason" rows="3" required maxlength="2000"></textarea></label>
    <div><button class="btn" type="submit"><?=h(t('report_requests.request.submit', 'Einsicht beantragen'))?></button></div>
  </form>
</section>
<script>
(() => {
  const scope = document.getElementById('accessScope');
  const classSelect = document.getElementById('accessClass');
  const studentWrap = document.getElementById('accessStudentWrap');
  const studentSelect = document.getElementById('accessStudent');
  if (!scope || !classSelect || !studentWrap || !studentSelect) return;
  const options = Array.from(studentSelect.querySelectorAll('option[data-class-id]'));
  function sync(){
    const classId = classSelect.value;
    const studentScope = scope.value === 'student';
    studentWrap.hidden = !studentScope;
    studentSelect.required = studentScope;
    options.forEach(option => { option.hidden = !classId || option.dataset.classId !== classId; });
    if (!studentScope || !studentSelect.selectedOptions[0] || studentSelect.selectedOptions[0].hidden) studentSelect.value = '';
  }
  scope.addEventListener('change', sync); classSelect.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>

<section class="card" id="access-requests">
  <h2 style="margin-top:0"><?=h(t('report_requests.pending.heading', 'Offene Einsichtsanfragen'))?> (<?=count($pendingAccessRequests)?>)</h2>
  <?php if (!$pendingAccessRequests): ?><p class="muted"><?=h(t('report_requests.pending.empty', 'Keine offenen Einsichtsanfragen.'))?></p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th><?=h(t('report_requests.requester', 'Antragsteller'))?></th><th><?=h(t('report_requests.scope.label', 'Umfang'))?></th><th><?=h(t('report_requests.class.label', 'Klasse'))?></th><th><?=h(t('report_requests.reason.label', 'Begründung'))?></th><th><?=h(t('report_requests.actions', 'Aktionen'))?></th></tr></thead><tbody>
  <?php foreach ($pendingAccessRequests as $request): $canReviewPending = user_can_review_report_access_request($pdo, $accessUserId, (int)$request['class_id']); ?><tr><td><?=h((string)$request['requester_name'])?></td><td><?=h(report_access_scope_label($request))?></td><td><?=h(report_access_class_label($request))?></td><td><?=nl2br(h((string)$request['reason']))?></td><td>
    <?php if ($canReviewPending): ?>
      <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="approve_access"><input type="hidden" name="request_id" value="<?=h((string)$request['id'])?>"><select class="input" name="valid_days" style="width:auto"><option value="7">7 <?=h(t('deadline.unit.day_plural','Tage'))?></option><option value="30" selected>30 <?=h(t('deadline.unit.day_plural','Tage'))?></option><option value="0"><?=h(t('report_requests.unlimited','Unbegrenzt'))?></option></select><button class="btn" type="submit"><?=h(t('report_requests.approve','Freigeben'))?></button></form>
      <form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="reject_access"><input type="hidden" name="request_id" value="<?=h((string)$request['id'])?>"><button class="btn secondary" type="submit"><?=h(t('report_requests.reject','Ablehnen'))?></button></form>
    <?php else: ?><span class="muted"><?=h(t('report_requests.awaiting_approval','Wartet auf Freigabe'))?></span><?php endif; ?>
  </td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>

<section class="card" id="active-access-grants">
  <h2 style="margin-top:0"><?=h(t('report_requests.active.heading', 'Freigegebene Berichteinsichten'))?> (<?=count($activeAccessGrants)?>)</h2>
  <?php if (!$activeAccessGrants): ?><p class="muted"><?=h(t('report_requests.active.empty', 'Keine erteilten Freigaben.'))?></p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th><?=h(t('report_requests.requester', 'Antragsteller'))?></th><th><?=h(t('report_requests.scope.label', 'Umfang'))?></th><th><?=h(t('report_requests.class.label', 'Klasse'))?></th><th><?=h(t('report_requests.expires', 'Gültig bis'))?></th><th><?=h(t('report_requests.actions', 'Aktionen'))?></th></tr></thead><tbody>
  <?php foreach ($activeAccessGrants as $grant):
    $expired = !empty($grant['expires_at']) && strtotime((string)$grant['expires_at']) < time();
    $canReviewGrant = user_can_review_report_access_request($pdo, $accessUserId, (int)$grant['class_id']);
    $isOwnGrant = (int)$grant['requester_user_id'] === $accessUserId;
    $previewParams = (string)$grant['scope_type'] === 'student'
      ? ['student_id' => (int)$grant['student_id']]
      : ['class_id' => (int)$grant['class_id']];
    $previewUrl = url(($isAdmin ? 'admin' : 'teacher') . '/report_preview.php?' . http_build_query($previewParams));
  ?><tr><td><?=h((string)$grant['requester_name'])?></td><td><?=h(report_access_scope_label($grant))?></td><td><?=h(report_access_class_label($grant))?></td><td><?= $grant['expires_at'] ? render_local_datetime((string)$grant['expires_at'], ui_lang()==='en'?'m/d/Y g:i A':'d.m.Y H:i') : h(t('report_requests.unlimited','Unbegrenzt')) ?><?= $expired ? ' · ' . h(t('report_requests.expired','abgelaufen')) : '' ?></td><td>
    <a class="btn secondary" href="<?=h($previewUrl)?>"><?=h(t('report_requests.open_reports','Berichte öffnen'))?></a>
    <?php if ($canReviewGrant): ?><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="extend_access"><input type="hidden" name="request_id" value="<?=h((string)$grant['id'])?>"><input class="input" type="number" name="extend_days" min="1" max="3650" value="30" style="width:90px"><button class="btn secondary" type="submit"><?=h(t('report_requests.extend','Verlängern'))?></button></form><?php endif; ?>
    <?php if ($canReviewGrant || $isOwnGrant): ?><form method="post" style="margin-top:6px" onsubmit="return confirm(<?=h(json_encode(t('report_requests.revoke_confirm','Freigabe wirklich zurückziehen?')))?>)"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="revoke_access"><input type="hidden" name="request_id" value="<?=h((string)$grant['id'])?>"><button class="btn danger" type="submit"><?=h($canReviewGrant ? t('report_requests.revoke','Zurückziehen') : t('report_requests.return_access','Freigabe zurückgeben'))?></button></form><?php endif; ?>
  </td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>

<?php if (!$isAdmin): ?>
<section class="card"><h2 style="margin-top:0"><?=h(t('report_requests.own.heading', 'Meine Einsichtsanfragen'))?></h2><div class="table-wrap"><table><thead><tr><th><?=h(t('report_requests.scope.label','Umfang'))?></th><th><?=h(t('report_requests.class.label','Klasse'))?></th><th><?=h(t('report_requests.status','Status'))?></th><th><?=h(t('report_requests.created','Erstellt'))?></th></tr></thead><tbody><?php foreach ($ownAccessRequests as $request): ?><tr><td><?=h(report_access_scope_label($request))?></td><td><?=h(report_access_class_label($request))?></td><td><?=h(t('report_requests.status.' . (string)$request['status'], (string)$request['status']))?></td><td><?=render_local_datetime((string)$request['created_at'], ui_lang()==='en'?'m/d/Y g:i A':'d.m.Y H:i')?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
