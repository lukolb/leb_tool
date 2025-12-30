<?php
declare(strict_types=1);
// admin/parent_requests.php

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classes = $pdo->query("SELECT id, school_year, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

function parent_admin_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

$alerts = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'approve_all') {
      $targetClassId = (int)($_POST['class_id'] ?? 0);
      $days = (int)($_POST['valid_days'] ?? 14);
      if ($days < 1) $days = 1;
      if ($days > 120) $days = 120;
      $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
      $sql = "UPDATE parent_portal_links ppl\n"
        . "JOIN students s ON s.id=ppl.student_id\n"
        . "SET ppl.status='approved', ppl.approved_by_user_id=?, ppl.approved_at=NOW(), ppl.published_at=NOW(), ppl.expires_at=?, ppl.updated_at=NOW()\n"
        . "WHERE ppl.status='requested'";
      $params = [$userId, $expiresAt];
      if ($targetClassId > 0) {
        $sql .= " AND s.class_id=?";
        $params[] = $targetClassId;
      }
      $upd = $pdo->prepare($sql);
      $upd->execute($params);
      $alerts[] = 'Sammelfreigabe durchgeführt: ' . $upd->rowCount() . ' Links aktiviert.';
      goto done_post;
    }
    $linkId = (int)($_POST['link_id'] ?? 0);
    if ($linkId <= 0) throw new RuntimeException('Link-ID fehlt.');

    $stLink = $pdo->prepare(
      "SELECT ppl.*, s.class_id\n" .
      "FROM parent_portal_links ppl\n" .
      "JOIN students s ON s.id=ppl.student_id\n" .
      "WHERE ppl.id=? LIMIT 1"
    );
    $stLink->execute([$linkId]);
    $link = $stLink->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new RuntimeException('Freigabe nicht gefunden.');

    if ($action === 'approve') {
      $days = (int)($_POST['valid_days'] ?? 14);
      if ($days < 1) $days = 1;
      if ($days > 120) $days = 120;
      $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

      $upd = $pdo->prepare(
        "UPDATE parent_portal_links\n" .
        "SET status='approved', approved_by_user_id=?, approved_at=NOW(), published_at=NOW(), expires_at=?, updated_at=NOW()\n" .
        "WHERE id=?"
      );
      $upd->execute([$userId, $expiresAt, $linkId]);
      $alerts[] = 'Freigabe aktiviert.';
    }

    if ($action === 'revoke') {
      $upd = $pdo->prepare("UPDATE parent_portal_links SET status='revoked', updated_at=NOW() WHERE id=?");
      $upd->execute([$linkId]);
      $alerts[] = 'Freigabe wurde beendet.';
    }

    if ($action === 'expire') {
      $upd = $pdo->prepare("UPDATE parent_portal_links SET status='expired', updated_at=NOW() WHERE id=?");
      $upd->execute([$linkId]);
      $alerts[] = 'Freigabe wurde abgelaufen markiert.';
    }

    if ($action === 'extend') {
      $days = (int)($_POST['extend_days'] ?? 7);
      if ($days < 1) $days = 1;
      if ($days > 120) $days = 120;
      $base = $link['expires_at'] ?? null;
      $start = $base ? new DateTimeImmutable((string)$base) : new DateTimeImmutable('now');
      $newExpiry = $start->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
      $upd = $pdo->prepare("UPDATE parent_portal_links SET expires_at=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$newExpiry, $linkId]);
      $alerts[] = 'Gültigkeit wurde verlängert.';
    }

    done_post:
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$statusFilter = (string)($_GET['status'] ?? 'open');
$filterClassId = (int)($_GET['class_id'] ?? 0);
$whereParts = [];
if ($statusFilter === 'open') {
  $whereParts[] = "ppl.status='requested'";
} elseif ($statusFilter === 'approved') {
  $whereParts[] = "ppl.status='approved'";
}
if ($filterClassId > 0) {
  $whereParts[] = 'c.id=' . (int)$filterClassId;
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$sql =
  "SELECT ppl.*, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name,\n" .
  "       req.display_name AS requested_by_name, appr.display_name AS approved_by_name,\n" .
  "       (SELECT COUNT(*) FROM parent_feedback pf WHERE pf.link_id=ppl.id AND pf.is_reviewed=0) AS pending_feedback\n" .
  "FROM parent_portal_links ppl\n" .
  "JOIN students s ON s.id=ppl.student_id\n" .
  "JOIN classes c ON c.id=s.class_id\n" .
  "LEFT JOIN users req ON req.id=ppl.requested_by_user_id\n" .
  "LEFT JOIN users appr ON appr.id=ppl.approved_by_user_id\n" .
  ($where ? $where . "\n" : '') .
  "ORDER BY ppl.created_at DESC\n" .
  "LIMIT 120";

$requests = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = t('admin.parent_requests.title', 'Elternfreigaben');
render_admin_header($pageTitle);
?>
<div class="card">
  <h1><?=h($pageTitle)?></h1>
  <p class="muted" style="max-width:820px;">
    <?=h(t('admin.parent_requests.intro', 'Lehrkräfte beantragen hier einen Elternmodus. Nach deiner Bestätigung erhalten Eltern einen reinen Lesezugang zur PDF-Vorschau und können nur moderierte Reaktionen hinterlassen.'))?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($alerts): ?>
  <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card">
    <h2>Filtern</h2>
  <div class="row" style="gap:10px;">
    <a class="btn <?= $statusFilter==='open'?'primary':'secondary' ?>" href="<?=h(url('admin/parent_requests.php?status=open'))?>"><?=h(t('admin.parent_requests.filter_open', 'Ausstehend'))?></a>
    <a class="btn <?= $statusFilter==='approved'?'primary':'secondary' ?>" href="<?=h(url('admin/parent_requests.php?status=approved'))?>"><?=h(t('admin.parent_requests.filter_approved', 'Aktiv'))?></a>
    <a class="btn <?= $statusFilter==='all'?'primary':'secondary' ?>" href="<?=h(url('admin/parent_requests.php?status=all'))?>"><?=h(t('admin.parent_requests.filter_all', 'Alle'))?></a>
    <form method="get" style="display:flex; gap:8px; align-items:center;margin-top: 20px;">
      <input type="hidden" name="status" value="<?=h($statusFilter)?>">
      <label class="muted" style="font-size:12px;">Klasse</label>
      <select name="class_id" class="input">
        <option value="0">Alle</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $filterClassId===(int)$c['id'] ? 'selected' : '' ?>><?=h((string)$c['school_year'])?> · <?=h(parent_admin_class_display($c))?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn secondary" type="submit">Filtern</button>
    </form>
  </div>
</div>

<div class="card">
    <h2>Freischaltung</h2>
  <form method="post" class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="approve_all">
    <input type="hidden" name="class_id" value="<?= (int)$filterClassId ?>">
    <div>
      <label class="muted" style="font-size:12px;">Gültig für</label>
    </div>
    <div>
      <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 20px;font-size: 13px;">Tage</span>
      <button class="btn primary" type="submit">Alle angezeigten Anfragen freigeben</button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.parent_requests.table_title', 'Übersicht'))?></h2>
  <?php if (!$requests): ?>
    <p class="muted"><?=h(t('admin.parent_requests.none', 'Keine Einträge gefunden.'))?></p>
  <?php else: ?>
    <div class="responsive-table">
      <table>
        <thead>
          <tr>
            <th><?=h(t('admin.parent_requests.student', 'Schüler:in'))?></th>
            <th><?=h(t('admin.parent_requests.class', 'Klasse'))?></th>
            <th><?=h(t('admin.parent_requests.status', 'Status'))?></th>
            <th><?=h(t('admin.parent_requests.expires', 'Gültig bis'))?></th>
            <th><?=h(t('admin.parent_requests.requested_by', 'Angefragt von'))?></th>
            <th><?=h(t('admin.parent_requests.feedback', 'Feedback'))?></th>
            <th><?=h(t('admin.parent_requests.actions', 'Aktionen'))?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r):
            $status = (string)($r['status'] ?? '');
            $statusLabel = $status;
            if ($status === 'requested') $statusLabel = t('admin.parent_requests.status.requested', 'Wartet auf Freigabe');
            if ($status === 'approved') $statusLabel = t('admin.parent_requests.status.approved', 'Freigeschaltet');
            if ($status === 'revoked') $statusLabel = t('admin.parent_requests.status.revoked', 'Beendet');
            if ($status === 'expired') $statusLabel = t('admin.parent_requests.status.expired', 'Abgelaufen');
            $statusColor = $status;
            if ($status === 'requested') $statusColor = 'blue';
            if ($status === 'approved') $statusColor = 'green';
            if ($status === 'revoked') $statusColor = 'red';
            if ($status === 'expired') $statusColor = 'red';
            $pendingFb = (int)($r['pending_feedback'] ?? 0);
          ?>
          <tr>
            <td><strong><?=h((string)$r['first_name'] . ' ' . (string)$r['last_name'])?></strong></td>
            <td><?=h((string)$r['school_year'])?> · <?=h(parent_admin_class_display($r))?></td>
            <td><span class="pill <?=h($statusColor)?>"><?=h($statusLabel)?></span></td>
            <td><?=h(date_format(date_create($r['expires_at']),"d.m.Y H:i") ?? '–')?></td>
            <td><?=h($r['requested_by_name'] ?? t('admin.parent_requests.unknown', 'unbekannt'))?></td>
            <td>
              <?php if ($pendingFb > 0): ?>
                <span class="pill yellow"><?=$pendingFb?> <?=h(t('admin.parent_requests.pending_fb', 'offen'))?></span>
              <?php else: ?>
                <span class="muted">–</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex; gap:6px; flex-wrap:wrap;width: min-content;">
                <?php if ($status === 'requested'): ?>
                  <form method="post" style="margin:0; display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="link_id" value="<?= (int)$r['id'] ?>">
                    <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 10px;font-size: 13px;">Tage</span>
                    <button class="btn primary" type="submit"><?=h(t('admin.parent_requests.approve', 'Freigeben'))?></button>
                  </form>
                <?php endif; ?>
                <?php if ($status === 'approved'): ?>
                  <form method="post" style="margin:0; display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="extend">
                    <input type="hidden" name="link_id" value="<?= (int)$r['id'] ?>">
                    <input type="number" name="extend_days" value="7" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 10px;font-size: 13px;">Tage</span>
                    <button class="btn secondary" type="submit"><?=h(t('admin.parent_requests.extend', 'Verlängern'))?></button>
                  </form>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="link_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn danger" type="submit" onclick="return confirm('<?=h(t('admin.parent_requests.revoke_confirm', 'Freigabe wirklich beenden?'))?>');"><?=h(t('admin.parent_requests.revoke', 'Beenden'))?></button>
                  </form>
                <?php endif; ?>
                <?php if ($status === 'expired'): ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="link_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="valid_days" value="7">
                    <button class="btn secondary" type="submit"><?=h(t('admin.parent_requests.reactivate', 'Reaktivieren'))?></button>
                  </form>
                <?php endif; ?>
                <?php if (in_array($status, ['requested','approved'], true)): ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="expire">
                    <input type="hidden" name="link_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn ghost" type="submit"><?=h(t('admin.parent_requests.force_expire', 'Als abgelaufen setzen'))?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
