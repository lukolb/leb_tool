<?php
// teacher/parents.php
// Manage parent preview requests (teacher side)
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
$role = (string)($u['role'] ?? '');

function parent_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function latest_report_for_student(PDO $pdo, int $studentId): ?array {
  $st = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status\n" .
    "FROM report_instances\n" .
    "WHERE student_id=?\n" .
    "ORDER BY updated_at DESC, id DESC\n" .
    "LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

// --- classes for teacher/admin ---
if ($role === 'admin') {
  $classes = $pdo->query(
    "SELECT id, school_year, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name\n" .
    "FROM classes c\n" .
    "JOIN user_class_assignments uca ON uca.class_id=c.id\n" .
    "WHERE uca.user_id=? AND c.is_active=1\n" .
    "ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) {
  $classId = (int)($classes[0]['id'] ?? 0);
}

$alerts = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_all') {
      $targetClassId = (int)($_POST['class_id'] ?? 0);
      if ($targetClassId <= 0) throw new RuntimeException('Klasse fehlt.');
      if ($role !== 'admin' && !user_can_access_class($pdo, $userId, $targetClassId)) throw new RuntimeException('Keine Berechtigung.');

      $days = (int)($_POST['valid_days'] ?? 14);
      if ($days < 1) $days = 1;
      if ($days > 90) $days = 90;
      $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

      $stStudents = $pdo->prepare("SELECT id FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name ASC, first_name ASC");
      $stStudents->execute([$targetClassId]);
      $created = 0; $skippedReport = 0; $skippedActive = 0;
      $stLatestLink = $pdo->prepare("SELECT status, expires_at FROM parent_portal_links WHERE student_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
      $ins = $pdo->prepare(
        "INSERT INTO parent_portal_links (student_id, report_instance_id, token, status, requested_by_user_id, preferred_lang, expires_at, published_at, approved_by_user_id, approved_at)\n" .
        "VALUES (?, ?, ?, 'requested', ?, 'de', ?, NULL, NULL, NULL)"
      );
      foreach ($stStudents->fetchAll(PDO::FETCH_ASSOC) as $stuRow) {
        $sid = (int)$stuRow['id'];
        $stLatestLink->execute([$sid]);
        $existing = $stLatestLink->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
          $exStatus = (string)($existing['status'] ?? '');
          $exExpires = $existing['expires_at'] ?? null;
          $stillActive = ($exStatus === 'approved' && $exExpires && strtotime((string)$exExpires) > time());
          if ($stillActive) { $skippedActive++; continue; }
        }

        $report = latest_report_for_student($pdo, $sid);
        if (!$report) { $skippedReport++; continue; }

        $token = bin2hex(random_bytes(32));
        $ins->execute([$sid, (int)$report['id'], $token, $userId, $expiresAt]);
        $created++;
      }
      $alerts[] = 'Sammelanfrage erstellt: ' . $created . ' neu, ' . $skippedActive . ' bereits aktiv, ' . $skippedReport . ' ohne Bericht.';
    } else {
      $studentId = (int)($_POST['student_id'] ?? 0);

      if ($studentId <= 0) {
        throw new RuntimeException('Schüler:in fehlt.');
      }

      $stStudent = $pdo->prepare("SELECT id, class_id, first_name, last_name FROM students WHERE id=? LIMIT 1");
      $stStudent->execute([$studentId]);
      $studentRow = $stStudent->fetch(PDO::FETCH_ASSOC);
      if (!$studentRow) {
        throw new RuntimeException('Schüler:in nicht gefunden.');
      }

      $studentClassId = (int)($studentRow['class_id'] ?? 0);
      if ($role !== 'admin' && ($studentClassId <= 0 || !user_can_access_class($pdo, $userId, $studentClassId))) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      if ($action === 'request_link') {
        $report = latest_report_for_student($pdo, $studentId);
        if (!$report) throw new RuntimeException('Es gibt noch keinen Berichtseintrag für diese Person.');

        $days = (int)($_POST['valid_days'] ?? 14);
        if ($days < 1) $days = 1;
        if ($days > 90) $days = 90;
        $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

        $token = bin2hex(random_bytes(32));

        $ins = $pdo->prepare(
          "INSERT INTO parent_portal_links (student_id, report_instance_id, token, status, requested_by_user_id, preferred_lang, expires_at, published_at, approved_by_user_id, approved_at)\n" .
          "VALUES (?, ?, ?, 'requested', ?, 'de', ?, NULL, NULL, NULL)"
        );
        $ins->execute([$studentId, (int)$report['id'], $token, $userId, $expiresAt]);
        $alerts[] = 'Elternmodus angefragt. Admin-Bestätigung erforderlich.';
      }

    }

    if ($action === 'revoke_link') {
      $linkId = (int)($_POST['link_id'] ?? 0);
      if ($linkId > 0) {
        $upd = $pdo->prepare("UPDATE parent_portal_links SET status='revoked', updated_at=NOW() WHERE id=? LIMIT 1");
        $upd->execute([$linkId]);
        $alerts[] = 'Elternzugriff wurde beendet.';
      }
    }

    if ($action === 'mark_feedback_reviewed') {
      $feedbackId = (int)($_POST['feedback_id'] ?? 0);
      if ($feedbackId > 0) {
        $upd = $pdo->prepare(
          "UPDATE parent_feedback pf\n" .
          "JOIN parent_portal_links ppl ON ppl.id=pf.link_id\n" .
          "JOIN students s ON s.id=ppl.student_id\n" .
          "SET pf.is_reviewed=1, pf.reviewed_by_user_id=?, pf.reviewed_at=NOW()\n" .
          "WHERE pf.id=? AND s.class_id=?"
        );
        $upd->execute([$userId, $feedbackId, $studentClassId]);
        $alerts[] = 'Feedback wurde als geprüft markiert.';
      }
    }

  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

// Students in selected class
$students = [];
$linkIds = [];
if ($classId > 0) {
  $stStu = $pdo->prepare(
    "SELECT s.*, c.school_year, c.grade_level, c.label, c.name,\n" .
    "  (SELECT id FROM parent_portal_links ppl WHERE ppl.student_id=s.id ORDER BY ppl.updated_at DESC, ppl.id DESC LIMIT 1) AS parent_link_id\n" .
    "FROM students s\n" .
    "JOIN classes c ON c.id=s.class_id\n" .
    "WHERE s.class_id=? AND s.is_active=1\n" .
    "ORDER BY s.last_name ASC, s.first_name ASC"
  );
  $stStu->execute([$classId]);
  $students = $stStu->fetchAll(PDO::FETCH_ASSOC);
  foreach ($students as $row) {
    $lid = (int)($row['parent_link_id'] ?? 0);
    if ($lid > 0) $linkIds[] = $lid;
  }
}

$linkMap = [];
if ($linkIds) {
  $in = implode(',', array_fill(0, count($linkIds), '?'));
  $stLinks = $pdo->prepare(
    "SELECT ppl.*, req.display_name AS requested_by_name, appr.display_name AS approved_by_name\n" .
    "FROM parent_portal_links ppl\n" .
    "LEFT JOIN users req ON req.id=ppl.requested_by_user_id\n" .
    "LEFT JOIN users appr ON appr.id=ppl.approved_by_user_id\n" .
    "WHERE ppl.id IN ($in)"
  );
  $stLinks->execute($linkIds);
  foreach ($stLinks->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $linkMap[(int)$row['id']] = $row;
  }
}

$feedbackCounts = [];
if ($linkIds) {
  $in = implode(',', array_fill(0, count($linkIds), '?'));
  $stFbCount = $pdo->prepare(
    "SELECT link_id, SUM(CASE WHEN is_reviewed=0 THEN 1 ELSE 0 END) AS pending, COUNT(*) AS total\n" .
    "FROM parent_feedback WHERE link_id IN ($in) GROUP BY link_id"
  );
  $stFbCount->execute($linkIds);
  foreach ($stFbCount->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $feedbackCounts[(int)$row['link_id']] = [
      'pending' => (int)($row['pending'] ?? 0),
      'total' => (int)($row['total'] ?? 0),
    ];
  }
}

// Feedback moderation list for this class
$feedbackList = [];
if ($classId > 0) {
  $stFb = $pdo->prepare(
    "SELECT pf.*, s.first_name, s.last_name, ppl.status AS link_status, ppl.student_id\n" .
    "FROM parent_feedback pf\n" .
    "JOIN parent_portal_links ppl ON ppl.id=pf.link_id\n" .
    "JOIN students s ON s.id=ppl.student_id\n" .
    "WHERE s.class_id=?\n" .
    "ORDER BY pf.is_reviewed ASC, pf.created_at DESC\n" .
    "LIMIT 40"
  );
  $stFb->execute([$classId]);
  $feedbackList = $stFb->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = t('teacher.parents.title', 'Elternmodus');
render_teacher_header($pageTitle);
?>
<div class="card">
  <h1><?=h($pageTitle)?></h1>
  <p class="muted" style="max-width:760px;">
    <?=h(t('teacher.parents.intro', 'Elternmodus wird von dir angefragt, von der Admin bestätigt und ist zeitlich begrenzt. Eltern sehen den ausgefüllten Bericht als nicht herunterladbare PDF-Vorschau und können moderierte Rückfragen oder eine Lesebestätigung senden.'))?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($alerts): ?>
  <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
  <h2><?=h(t('teacher.parents.class_label', 'Klasse'))?></h2>
  <form method="get" class="row">
    <div>
      <select name="class_id" class="input" onchange="this.closest('form').submit();">
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$classId) ? 'selected' : '' ?>>
            <?=h((string)$c['school_year'])?> · <?=h(parent_class_display($c))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if ($classId > 0 && $students): ?>
<div class="card" style="margin-bottom:14px;">
    <h2>Klassen-Freischaltung</h2>
  <form method="post" class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="request_all">
    <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
    <div>
      <label class="muted" style="font-size:12px;"><?=h(t('teacher.parents.bulk_days', 'Freischalten für'))?></label>
    </div>
    <div>
      <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 20px;font-size: 13px;">Tage</span>
      <button class="btn primary" type="submit"><?=h(t('teacher.parents.bulk_request', 'Alle Zugänge dieser Klasse anfragen'))?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?=h(t('teacher.parents.table_title', 'Freigaben'))?></h2>
  <?php if (!$students): ?>
    <p class="muted"><?=h(t('teacher.parents.no_students', 'Keine Schüler:innendaten gefunden.'))?></p>
  <?php else: ?>
    <div class="responsive-table">
      <table>
        <thead>
          <tr>
            <th><?=h(t('teacher.parents.student', 'Schüler:in'))?></th>
            <th><?=h(t('teacher.parents.status', 'Status'))?></th>
            <th><?=h(t('teacher.parents.expires', 'Gültig bis'))?></th>
            <th><?=h(t('teacher.parents.feedback', 'Feedback'))?></th>
            <th><?=h(t('teacher.parents.actions', 'Aktionen'))?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s):
          $linkId = (int)($s['parent_link_id'] ?? 0);
          $link = $linkId > 0 ? ($linkMap[$linkId] ?? null) : null;
          $status = $link['status'] ?? '-';
          $statusLabel = $status;
          if ($status === 'requested') $statusLabel = t('teacher.parents.status.requested', 'Angefragt');
          if ($status === 'approved') $statusLabel = t('teacher.parents.status.approved', 'Freigeschaltet');
          if ($status === 'revoked') $statusLabel = t('teacher.parents.status.revoked', 'Beendet');
          if ($status === 'expired') $statusLabel = t('teacher.parents.status.expired', 'Abgelaufen');
            $statusColor = $status;
            if ($status === 'requested') $statusColor = 'blue';
            if ($status === 'approved') $statusColor = 'green';
            if ($status === 'revoked') $statusColor = 'red';
            if ($status === 'expired') $statusColor = 'red';
          $expiresAt = $link['expires_at'] ?? null;
          $pending = $linkId && isset($feedbackCounts[$linkId]) ? (int)$feedbackCounts[$linkId]['pending'] : 0;
          $totalFb = $linkId && isset($feedbackCounts[$linkId]) ? (int)$feedbackCounts[$linkId]['total'] : 0;
          $shareUrl = ($link && $status === 'approved') ? absolute_url('parent/portal.php?token=' . urlencode((string)$link['token'])) : '';
        ?>
          <tr>
            <td><strong><?=h((string)$s['first_name'] . ' ' . (string)$s['last_name'])?></strong></td>
            <td><span class="pill <?=h($statusColor)?>"><?=h($statusLabel)?></span></td>
            <td><?=h($expiresAt ? date_format(date_create($expiresAt),"d.m.Y H:i") : '–')?></td>
            <td>
              <?php if ($totalFb > 0): ?>
                <span class="pill" style="background:<?= $pending>0 ? '#fff3cd' : '#e6f4ea' ?>; border:1px solid var(--border);">
                  <?=h($pending . ' / ' . $totalFb)?> <?=h(t('teacher.parents.feedback.pending', 'offen/gesamt'))?>
                </span>
              <?php else: ?>
                <span class="muted">–</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($status === 'approved' && $shareUrl): ?>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                  <input type="text" readonly value="<?=h($shareUrl)?>" style="min-width:240px;">
                  <button class="btn secondary" type="button" onclick="copyToClipboard('<?=h($shareUrl)?>');">Kopieren</button>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="revoke_link">
                    <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="link_id" value="<?= (int)$linkId ?>">
                    <button class="btn danger" type="submit" onclick="return confirm('<?=h(t('teacher.parents.revoke_confirm', 'Zugriff wirklich beenden?'))?>');"><?=h(t('teacher.parents.revoke', 'Beenden'))?></button>
                  </form>
                </div>
                <div class="muted" style="font-size:12px; margin-top:4px;">
                  <?=h(t('teacher.parents.note_readonly', 'Nur Vorschau, kein Download. Rückmeldungen sind moderiert.'))?>
                </div>
              <?php elseif ($status === 'requested'): ?>
                <span class="pill" style="background:#fff3cd; border:1px solid #ffe08a;"><?=h(t('teacher.parents.pending_admin', 'Wartet auf Admin-Freigabe'))?></span>
              <?php else: ?>
                <form method="post" class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="request_link">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <div>
                    <label class="muted" style="font-size:12px;"><?=h(t('teacher.parents.valid_days', 'Freischalten für'))?></label>
                  </div>
                  <div>
                    <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 20px;font-size: 13px;">Tage</span>
                    <button class="btn primary" type="submit"><?=h(t('teacher.parents.request', 'Elternmodus anfragen'))?></button>
                  </div>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:14px;">
  <h2><?=h(t('teacher.parents.feedback_title', 'Eltern-Rückmeldung'))?></h2>
  <p class="muted" style="margin-top:0;"><?=h(t('teacher.parents.feedback_hint', 'Rückmeldungen werden hier gesammelt. Markiere sie nach Sichtung als geprüft.'))?></p>
  <?php if (!$feedbackList): ?>
    <p class="muted"><?=h(t('teacher.parents.feedback_none', 'Noch keine Rückmeldungen.'))?></p>
  <?php else: ?>
    <div class="responsive-table">
      <table>
        <thead>
          <tr>
            <th><?=h(t('teacher.parents.feedback_student', 'Schüler:in'))?></th>
            <th style="width: 30%;"><?=h(t('teacher.parents.feedback_msg', 'Nachricht'))?></th>
            <th><?=h(t('teacher.parents.feedback_msg_date', 'Datum'))?></th>
            <th><?=h(t('teacher.parents.feedback_state', 'Status'))?></th>
            <th><?=h(t('teacher.parents.feedback_actions', 'Aktionen'))?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($feedbackList as $fb): ?>
            <tr>
              <td><strong><?=h((string)$fb['first_name'] . ' ' . (string)$fb['last_name'])?></strong></td>
              <td>
                <?php if (trim((string)($fb['message'] ?? '')) === ''): ?>
                  <span class="muted">–</span>
                <?php else: ?>
                  <?= nl2br(h((string)$fb['message'])) ?>
                <?php endif; ?>
              </td>
              <td><?=h(date_format(date_create((string)$fb['created_at']),"d.m.Y H:i"))?></td>
              <td>
                <?php if ((int)($fb['is_reviewed'] ?? 0) === 1): ?>
                  <span class="pill green"><?=h(t('teacher.parents.reviewed', 'Geprüft'))?></span>
                <?php else: ?>
                  <span class="pill yellow"><?=h(t('teacher.parents.pending_review', 'Offen'))?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)($fb['is_reviewed'] ?? 0) === 0): ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="mark_feedback_reviewed">
                    <input type="hidden" name="feedback_id" value="<?= (int)$fb['id'] ?>">
                    <input type="hidden" name="student_id" value="<?= (int)($fb['student_id'] ?? 0) ?>">
                    <button class="btn secondary" type="submit"><?=h(t('teacher.parents.mark_reviewed', 'Als geprüft markieren'))?></button>
                  </form>
                <?php else: ?>
                  <span class="muted">–</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
  
  <script>

  async function copyToClipboard(text){
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
        const ok = copyHttp(text);
        if(ok) {
        } else {
        }
    }
  }
  
  function copyHttp(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);

    ta.focus();
    ta.select();

    try {
      document.execCommand('copy');
      return true;
    } catch {
      return false;
    } finally {
      document.body.removeChild(ta);
    }
  }
  </script>

<?php render_teacher_footer(); ?>
