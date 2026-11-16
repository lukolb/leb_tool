<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$u = current_user() ?: [];
$isAdmin = true;
$userId = (int)($u['id'] ?? 0);
$deleteOk = '';
$deleteError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'delete_request_')) {
  try {
    csrf_verify();
    $action = (string)$_POST['action'];
    $requestIds = $action === 'delete_request_approve_bulk'
      ? array_values(array_unique(array_filter(array_map('intval', (array)($_POST['request_ids'] ?? [])), fn($id) => $id > 0)))
      : [max(0, (int)($_POST['request_id'] ?? 0))];
    $requestIds = array_values(array_filter($requestIds, fn($id) => $id > 0));
    if (!$requestIds) throw new RuntimeException(t('report_requests.delete.none_selected', 'Bitte mindestens eine Löschanfrage auswählen.'));
    $approved = 0;
    $missing = 0;
    $rejected = 0;
    $pdo->beginTransaction();
    $stRequest = $pdo->prepare("SELECT * FROM report_deletion_requests WHERE id=? LIMIT 1 FOR UPDATE");
    foreach ($requestIds as $requestId) {
      $stRequest->execute([$requestId]);
      $request = $stRequest->fetch(PDO::FETCH_ASSOC);
      if (!$request || (string)$request['status'] !== 'pending') continue;
      if ($action === 'delete_request_reject') {
        $pdo->prepare("UPDATE report_deletion_requests SET status='rejected', reviewed_by_user_id=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
          ->execute([$userId, $requestId]);
        $rejected++;
        continue;
      }
      $reportId = (int)($request['report_instance_id'] ?? 0);
      $deleted = $reportId > 0 ? delete_student_report_permanently($pdo, $reportId, $userId) : null;
      if ($deleted) {
        $approved++;
      } else {
        $pdo->prepare("UPDATE report_deletion_requests SET status='missing', report_instance_id=NULL, reviewed_by_user_id=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
          ->execute([$userId, $requestId]);
        $missing++;
      }
    }
    $pdo->commit();
    audit('admin_report_delete_requests_review', $userId, [
      'request_ids'=>$requestIds,
      'approved'=>$approved,
      'missing'=>$missing,
      'rejected'=>$rejected,
    ]);
    $deleteOk = strtr(t('report_requests.delete.reviewed', 'Bearbeitet: {approved} gelöscht, {rejected} abgelehnt, {missing} bereits nicht mehr vorhanden.'), [
      '{approved}'=>(string)$approved,
      '{rejected}'=>(string)$rejected,
      '{missing}'=>(string)$missing,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $deleteError = $e->getMessage();
  }
}

$pendingDeleteRequests = $pdo->query(
  "SELECT rdr.*, u.display_name AS requester_name, u.email AS requester_email
   FROM report_deletion_requests rdr
   LEFT JOIN users u ON u.id=rdr.requested_by_user_id
   WHERE rdr.status='pending'
   ORDER BY rdr.created_at ASC, rdr.id ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$recentDeleteRequests = $pdo->query(
  "SELECT rdr.*, u.display_name AS requester_name, reviewer.display_name AS reviewer_name
   FROM report_deletion_requests rdr
   LEFT JOIN users u ON u.id=rdr.requested_by_user_id
   LEFT JOIN users reviewer ON reviewer.id=rdr.reviewed_by_user_id
   WHERE rdr.status<>'pending'
   ORDER BY COALESCE(rdr.reviewed_at, rdr.updated_at) DESC, rdr.id DESC
   LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_admin_header(t('report_requests.title', 'Berichtsanfragen'));
?>
<div class="container">
  <h1><?=h(t('report_requests.title', 'Berichtsanfragen'))?></h1>
  <?php if ($deleteError !== ''): ?><div class="alert danger"><?=h($deleteError)?></div><?php endif; ?>
  <?php if ($deleteOk !== ''): ?><div class="alert success"><?=h($deleteOk)?></div><?php endif; ?>
  <section class="card" id="deletion-requests">
    <h2 style="margin-top:0"><?=h(t('report_requests.delete.heading', 'Offene Löschanfragen'))?> (<?=count($pendingDeleteRequests)?>)</h2>
    <p class="muted"><?=h(t('report_requests.delete.intro', 'Prüfen Sie jede Anfrage sorgfältig. Die Zustimmung löscht den jeweiligen Bericht einschließlich Feldwerten und Freigabelinks endgültig; das Schülerprofil und andere Berichte bleiben erhalten.'))?></p>
    <?php if (!$pendingDeleteRequests): ?><p class="muted"><?=h(t('report_requests.delete.empty', 'Keine offenen Löschanfragen.'))?></p><?php else: ?>
      <form id="bulkDeleteApproval" method="post" onsubmit="return confirm(<?=h(json_encode(t('report_requests.delete.bulk_confirm', 'Alle ausgewählten Berichte endgültig löschen?')))?>)"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_request_approve_bulk"></form>
      <div class="table-wrap"><table><thead><tr><th><input type="checkbox" id="selectAllDeleteRequests" aria-label="<?=h(t('report_requests.select_all','Alle auswählen'))?>"></th><th><?=h(t('report_requests.mail.student','Schüler'))?></th><th><?=h(t('report_requests.mail.period','Zeitraum'))?></th><th><?=h(t('report_requests.requester','Antragsteller'))?></th><th><?=h(t('report_requests.reason.label','Begründung'))?></th><th><?=h(t('report_requests.actions','Aktionen'))?></th></tr></thead><tbody>
      <?php foreach ($pendingDeleteRequests as $request): ?><tr><td><input form="bulkDeleteApproval" class="delete-request-check" type="checkbox" name="request_ids[]" value="<?=h((string)$request['id'])?>"></td><td><?=h((string)$request['student_name'])?><br><small>#<?=h((string)$request['report_id_snapshot'])?> · <?=h((string)$request['template_name'])?></small></td><td><?=h((string)$request['school_year'] . ' · ' . (string)$request['period_label'])?></td><td><?=h((string)($request['requester_name'] ?: $request['requester_email']))?><br><small><?=render_local_datetime((string)$request['created_at'], ui_lang()==='en'?'m/d/Y g:i A':'d.m.Y H:i')?></small></td><td><?=nl2br(h((string)$request['reason']))?></td><td>
        <?php if ((int)($request['report_instance_id'] ?? 0) > 0): ?><a class="btn secondary" href="<?=h(url('admin/report_preview.php?report_id=' . (int)$request['report_instance_id']))?>"><?=h(t('report_requests.preview','Prüfen'))?></a><?php endif; ?>
        <form method="post" style="margin-top:6px" onsubmit="return confirm(<?=h(json_encode(t('report_requests.delete.single_confirm','Diesen Bericht endgültig löschen?')))?>)"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_request_approve_one"><input type="hidden" name="request_id" value="<?=h((string)$request['id'])?>"><button class="btn danger" type="submit"><?=h(t('report_requests.delete.approve','Löschung zustimmen'))?></button></form>
        <form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_request_reject"><input type="hidden" name="request_id" value="<?=h((string)$request['id'])?>"><button class="btn secondary" type="submit"><?=h(t('report_requests.reject','Ablehnen'))?></button></form>
      </td></tr><?php endforeach; ?></tbody></table></div>
      <button class="btn danger" form="bulkDeleteApproval" type="submit" style="margin-top:12px"><?=h(t('report_requests.delete.approve_selected','Ausgewählte Löschungen genehmigen'))?></button>
      <script>document.getElementById('selectAllDeleteRequests')?.addEventListener('change', event => document.querySelectorAll('.delete-request-check').forEach(box => { box.checked = event.target.checked; }));</script>
    <?php endif; ?>
  </section>

  <?php require __DIR__ . '/../shared/report_access_requests_page.php'; ?>

  <section class="card"><h2 style="margin-top:0"><?=h(t('report_requests.delete.history','Zuletzt bearbeitete Löschanfragen'))?></h2><?php if (!$recentDeleteRequests): ?><p class="muted">—</p><?php else: ?><div class="table-wrap"><table><thead><tr><th><?=h(t('report_requests.mail.student','Schüler'))?></th><th><?=h(t('report_requests.status','Status'))?></th><th><?=h(t('report_requests.requester','Antragsteller'))?></th><th><?=h(t('report_requests.reviewer','Bearbeitet von'))?></th></tr></thead><tbody><?php foreach ($recentDeleteRequests as $request): ?><tr><td><?=h((string)$request['student_name'])?> · #<?=h((string)$request['report_id_snapshot'])?></td><td><?=h(t('report_requests.status.' . (string)$request['status'], (string)$request['status']))?></td><td><?=h((string)$request['requester_name'])?></td><td><?=h((string)$request['reviewer_name'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php render_admin_footer(); ?>
