<?php
declare(strict_types=1);
// admin/parent_requests.php

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
$cfg = app_config();
$parentCfg = $cfg['parent'] ?? [];
$meetingFeedbackAnonymous = (bool)($parentCfg['meeting_feedback_anonymous'] ?? false);

$classes = $pdo->query("SELECT id, school_year, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

function parent_admin_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function admin_feedback_query_url(array $overrides): string {
  $params = $_GET;
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    } else {
      $params[$key] = $value;
    }
  }
  $query = http_build_query($params);
  return url('admin/parent_requests.php' . ($query ? ('?' . $query) : ''));
}

$availableGrades = [];
$availableYears = [];
foreach ($classes as $c) {
  if ($c['grade_level'] !== null) $availableGrades[] = (int)$c['grade_level'];
  if (!empty($c['school_year'])) $availableYears[] = (string)$c['school_year'];
}
$availableGrades = array_values(array_unique($availableGrades));
sort($availableGrades);
$availableYears = array_values(array_unique($availableYears));
rsort($availableYears);

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
      $expiresAt = end_of_day_after_days($days);
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
      $expiresAt = end_of_day_after_days($days);

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
      $start = $base ? new DateTimeImmutable((string)$base) : new DateTimeImmutable('today');
      $newExpiry = end_of_day_after_days($days, $start);
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

$meetingFeedbackScope = (string)($_GET['meeting_feedback_scope'] ?? 'all');
$meetingFeedbackGrade = isset($_GET['meeting_feedback_grade']) && $_GET['meeting_feedback_grade'] !== ''
  ? (int)$_GET['meeting_feedback_grade']
  : null;
$meetingFeedbackClass = isset($_GET['meeting_feedback_class']) && $_GET['meeting_feedback_class'] !== ''
  ? (int)$_GET['meeting_feedback_class']
  : 0;
$meetingFeedbackYear = isset($_GET['meeting_feedback_year']) && $_GET['meeting_feedback_year'] !== ''
  ? (string)$_GET['meeting_feedback_year']
  : '';
$meetingFeedbackId = isset($_GET['meeting_feedback_id']) && $_GET['meeting_feedback_id'] !== ''
  ? (int)$_GET['meeting_feedback_id']
  : 0;
$meetingFeedbackWhere = [];
$meetingFeedbackParams = [];
if ($meetingFeedbackYear !== '') {
  $meetingFeedbackWhere[] = 'pmf.school_year=?';
  $meetingFeedbackParams[] = $meetingFeedbackYear;
}
if ($meetingFeedbackScope === 'grade') {
  if ($meetingFeedbackGrade !== null) {
    $meetingFeedbackWhere[] = 'pmf.grade_level=?';
    $meetingFeedbackParams[] = $meetingFeedbackGrade;
  }
} elseif ($meetingFeedbackScope === 'all') {
  // no filter
} elseif ($meetingFeedbackScope === 'class') {
  if ($meetingFeedbackClass > 0) {
    $meetingFeedbackWhere[] = 'pmf.class_id=?';
    $meetingFeedbackParams[] = $meetingFeedbackClass;
  }
} else {
  $meetingFeedbackScope = 'all';
}
if ($meetingFeedbackId > 0) {
  $meetingFeedbackWhere[] = 'pmf.id=?';
  $meetingFeedbackParams[] = $meetingFeedbackId;
}
$meetingStatsSql =
  "SELECT COUNT(*) AS total,\n" .
  "  SUM(q1=4) AS q1_4, SUM(q1=3) AS q1_3, SUM(q1=2) AS q1_2, SUM(q1=1) AS q1_1,\n" .
  "  SUM(q2=4) AS q2_4, SUM(q2=3) AS q2_3, SUM(q2=2) AS q2_2, SUM(q2=1) AS q2_1,\n" .
  "  SUM(q3=4) AS q3_4, SUM(q3=3) AS q3_3, SUM(q3=2) AS q3_2, SUM(q3=1) AS q3_1\n" .
  "FROM parent_meeting_feedback pmf\n" .
  ($meetingFeedbackWhere ? ('WHERE ' . implode(' AND ', $meetingFeedbackWhere) . "\n") : '');
$stMeetingStats = $pdo->prepare($meetingStatsSql);
$stMeetingStats->execute($meetingFeedbackParams);
$meetingStats = $stMeetingStats->fetch(PDO::FETCH_ASSOC) ?: [];
foreach ($meetingStats as $k => $v) {
  $meetingStats[$k] = (int)$v;
}

$meetingFeedbackWhereTexts = $meetingFeedbackWhere;
$meetingFeedbackParamsTexts = $meetingFeedbackParams;
$meetingFeedbackWhereTexts[] = "pmf.message IS NOT NULL AND TRIM(pmf.message)<>''";
$meetingFeedbackSql =
  "SELECT pmf.id, pmf.message, pmf.created_at, pmf.is_anonymous, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name\n" .
  "FROM parent_meeting_feedback pmf\n" .
  "JOIN students s ON s.id=pmf.student_id\n" .
  "JOIN classes c ON c.id=pmf.class_id\n" .
  ($meetingFeedbackWhereTexts ? ('WHERE ' . implode(' AND ', $meetingFeedbackWhereTexts) . "\n") : '') .
  "ORDER BY pmf.created_at DESC\n" .
  "LIMIT 200";
$stMeeting = $pdo->prepare($meetingFeedbackSql);
$stMeeting->execute($meetingFeedbackParamsTexts);
$meetingFeedbackTexts = $stMeeting->fetchAll(PDO::FETCH_ASSOC);

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
            <th><?=h(t('admin.parent_requests.student', 'Schüler'))?></th>
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
            <td><?= render_local_datetime((string)$r['expires_at'], 'd.m.Y H:i') ?></td>
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

<div class="card" style="margin-top:14px;" id="meetingFeedbackSection">
  <h2>Feedback zum Lernentwicklungsgespräch</h2>
  <p class="muted" style="margin-top:0;">Freitexte aus dem Eltern-Feedbackbogen. Bei anonymen Rückmeldungen werden keine Namen angezeigt.</p>
  <form method="get" class="row" style="gap:12px; align-items:center; flex-wrap:wrap; padding:12px; border:1px solid var(--border); border-radius:10px; background:#f7f9fc;">
    <input type="hidden" name="status" value="<?=h($statusFilter)?>">
    <input type="hidden" name="class_id" value="<?= (int)$filterClassId ?>">
    <?php if ($meetingFeedbackId > 0): ?>
      <input type="hidden" name="meeting_feedback_id" value="<?= (int)$meetingFeedbackId ?>">
    <?php endif; ?>
    <label class="muted" style="font-size:12px;">Schuljahr</label>
    <select name="meeting_feedback_year" class="input" onchange="this.form.submit();">
      <option value="">Alle</option>
      <?php foreach ($availableYears as $year): ?>
        <option value="<?=h($year)?>" <?= $meetingFeedbackYear === $year ? 'selected' : '' ?>><?=h($year)?></option>
      <?php endforeach; ?>
    </select>
    <label class="muted" style="font-size:12px;">Auswertungsebene</label>
    <select name="meeting_feedback_scope" class="input" onchange="this.form.submit();">
      <option value="all" <?= $meetingFeedbackScope === 'all' ? 'selected' : '' ?>>Alle</option>
      <option value="grade" <?= $meetingFeedbackScope === 'grade' ? 'selected' : '' ?>>Klassenstufe</option>
      <option value="class" <?= $meetingFeedbackScope === 'class' ? 'selected' : '' ?>>Klasse</option>
    </select>
    <?php if ($meetingFeedbackScope === 'grade'): ?>
      <label class="muted" style="font-size:12px;">Klassenstufe</label>
      <select name="meeting_feedback_grade" class="input" onchange="this.form.submit();">
        <option value="">—</option>
        <?php foreach ($availableGrades as $g): ?>
          <option value="<?= (int)$g ?>" <?= ($meetingFeedbackGrade !== null && (int)$meetingFeedbackGrade === (int)$g) ? 'selected' : '' ?>><?=h((string)$g)?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($meetingFeedbackScope === 'class'): ?>
      <label class="muted" style="font-size:12px;">Klasse</label>
      <select name="meeting_feedback_class" class="input" onchange="this.form.submit();">
        <option value="">—</option>
        <?php foreach ($classes as $c): ?>
          <?php if ($meetingFeedbackYear !== '' && (string)($c['school_year'] ?? '') !== $meetingFeedbackYear) continue; ?>
          <option value="<?= (int)$c['id'] ?>" <?= $meetingFeedbackClass === (int)$c['id'] ? 'selected' : '' ?>><?=h((string)$c['school_year'])?> · <?=h(parent_admin_class_display($c))?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </form>
  <?php if ($meetingFeedbackId > 0): ?>
    <div style="margin-top:8px;">
      <a class="btn secondary" href="<?=h(admin_feedback_query_url(['meeting_feedback_id' => null]))?>">Filter zurücksetzen</a>
    </div>
  <?php endif; ?>
  <?php
    $meetingTotal = (int)($meetingStats['total'] ?? 0);
    $renderPie = function(string $key, string $title) use ($meetingStats, $meetingTotal) {
      $segments = [
        1 => ['label' => 'Stimme nicht zu', 'color' => '#d32f2f'],
        2 => ['label' => 'Stimme eher nicht zu', 'color' => '#f57c00'],
        3 => ['label' => 'Stimme eher zu', 'color' => '#558dfc'],
        4 => ['label' => 'Stimme völlig zu', 'color' => '#16bc00'],
      ];
      if ($meetingTotal <= 0) {
        echo '<div class="muted">Noch keine Rückmeldungen vorhanden.</div>';
        return;
      }
      $offset = 0;
      $slices = [];
      foreach ($segments as $score => $seg) {
        $count = (int)($meetingStats[$key . '_' . $score] ?? 0);
        $pct = $meetingTotal > 0 ? ($count / $meetingTotal) * 100 : 0;
        $start = $offset;
        $end = $offset + $pct;
        $offset = $end;
        $slices[] = $seg['color'] . ' ' . $start . '% ' . $end . '%';
      }
      $gradient = $slices ? 'conic-gradient(' . implode(', ', $slices) . ')' : 'conic-gradient(#e0e0e0 0 100%)';
      echo '<div style="flex:1; min-width:220px; padding:12px; border:1px solid var(--border); border-radius:8px; background:#fff;">';
      echo '<div style="font-weight:600; margin-bottom:10px;">' . h($title) . '</div>';
      echo '<div style="display:flex; gap:12px; align-items:center;">';
      echo '<div style="width:120px; height:120px; border-radius:50%; background:' . h($gradient) . ';"></div>';
      echo '<div style="display:flex; flex-direction:column; gap:6px;">';
      foreach ($segments as $score => $seg) {
        $count = (int)($meetingStats[$key . '_' . $score] ?? 0);
        $pct = $meetingTotal > 0 ? round(($count / $meetingTotal) * 100, 1) : 0;
        echo '<div style="display:flex; align-items:center; gap:6px; font-size:13px;">';
        echo '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' . h($seg['color']) . ';"></span>';
        echo '<span>' . h($seg['label']) . ' · ' . h((string)$pct) . '% (' . h((string)$count) . ')</span>';
        echo '</div>';
      }
      echo '</div>';
      echo '</div>';
      echo '</div>';
    };
  ?>
  <?php
    echo '<div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;">';
    $renderPie('q1', '1. Das Gespräch war verständlich und informativ.');
    $renderPie('q2', '2. Ich weiß jetzt, wie ich mein Kind zuhause weiter unterstützen kann.');
    $renderPie('q3', '3. Die besprochenen nächsten Schritte sind für mich nachvollziehbar.');
    echo '</div>';
  ?>
  <?php if (!$meetingFeedbackTexts): ?>
    <p class="muted">Keine Freitext-Rückmeldungen vorhanden.</p>
  <?php else: ?>
    <div class="responsive-table">
      <table>
        <thead>
          <tr>
            <th>Schüler</th>
            <th>Klasse</th>
            <th>Nachricht</th>
            <th>Datum</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($meetingFeedbackTexts as $row): ?>
            <?php
              $isAnonymous = $meetingFeedbackAnonymous || ((int)($row['is_anonymous'] ?? 0) === 1);
              $studentName = $isAnonymous
                ? 'Anonym'
                : trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
              $classLabel = parent_admin_class_display($row);
            ?>
            <tr>
              <td><strong><?=h($studentName)?></strong></td>
              <td><?=h((string)($row['school_year'] ?? ''))?> · <?=h($classLabel)?></td>
              <td>
                <a href="<?=h(admin_feedback_query_url(['meeting_feedback_id' => (int)($row['id'] ?? 0)]))?>" style="color:inherit; text-decoration:underline;">
                  <?= nl2br(h((string)($row['message'] ?? ''))) ?>
                </a>
              </td>
              <td><?= render_local_datetime((string)($row['created_at'] ?? ''), 'd.m.Y H:i') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
  (function(){
    const params = new URLSearchParams(window.location.search);
    if (params.has('meeting_feedback_scope') || params.has('meeting_feedback_year') || params.has('meeting_feedback_grade') || params.has('meeting_feedback_class')) {
      const section = document.getElementById('meetingFeedbackSection');
      if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  })();
</script>

<?php render_admin_footer(); ?>
