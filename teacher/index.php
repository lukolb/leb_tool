<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

function format_minutes_short(?float $minutes): string {
  if ($minutes === null) return (string)t('teacher.progress.time_unknown', '–');
  $m = (int)round($minutes);
  if ($m <= 0) return '<1 min';
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) {
    return $h . 'h' . ($r > 0 ? ' ' . $r . 'min' : '');
  }
  return $m . ' min';
}

// Delegations inbox count (groups delegated to this teacher)
$delegationCount = 0;
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM class_group_delegations WHERE user_id=?");
  $st->execute([$userId]);
  $delegationCount = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  // ignore
}

$progress = [
  'submitted' => 0,
  'locked' => 0,
  'total' => 0,
  'avg_minutes' => null,
  'recent_delegations' => 0,
];

// Load classes assigned to teacher (admins see all)
if (($u['role'] ?? '') === 'admin') {
  $st = $pdo->query("SELECT id, school_year, grade_level, label, name FROM classes ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $classes = $st->fetchAll();
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name
     FROM classes c
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? AND is_active = 1
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll();
}

if ($classes) {
  $classIds = array_map(fn($c) => (int)($c['id'] ?? 0), $classes);
  $in = implode(',', array_fill(0, count($classIds), '?'));

  try {
    $st = $pdo->prepare(
      "SELECT ri.status, COUNT(*) AS c
         FROM report_instances ri
         JOIN students s ON s.id=ri.student_id
        WHERE s.class_id IN ($in)
          AND ri.period_label='Standard'
        GROUP BY ri.status"
    );
    $st->execute($classIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $status = (string)($r['status'] ?? '');
      $count = (int)($r['c'] ?? 0);
      if ($status === 'submitted') $progress['submitted'] = $count;
      if ($status === 'locked') $progress['locked'] = $count;
      $progress['total'] += $count;
    }

    $avg = $pdo->prepare(
      "SELECT AVG(TIMESTAMPDIFF(MINUTE, ri.created_at, ri.updated_at)) AS avg_minutes
         FROM report_instances ri
         JOIN students s ON s.id=ri.student_id
        WHERE s.class_id IN ($in)
          AND ri.period_label='Standard'"
    );
    $avg->execute($classIds);
    $avgVal = $avg->fetchColumn();
    $progress['avg_minutes'] = ($avgVal !== false) ? (float)$avgVal : null;
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $d = $pdo->prepare(
      "SELECT COUNT(*)
         FROM class_group_delegations
        WHERE user_id=?
          AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $d->execute([$userId]);
    $progress['recent_delegations'] = (int)($d->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    // ignore
  }
}

render_teacher_header(t('teacher.title'));
?>

<div class="card">
    <h1><?=h(t('teacher.dashboard'))?></h1>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
</div>

<div class="card">
  <h2><?=h(t('teacher.progress.headline', 'Aktueller Bearbeitungsstand'))?></h2>
  <p class="muted"><?=h(t('teacher.progress.description', 'Statusüberblick für deine Klassen.'))?></p>

  <?php if ($progress['total'] === 0): ?>
    <div class="alert"><?=h(t('teacher.progress.empty', 'Keine Daten verfügbar.'))?></div>
  <?php else: ?>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['submitted'])?></div>
        <div class="stat-label"><?=h(t('teacher.progress.students_done', 'fertige Schülereingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['locked'])?></div>
        <div class="stat-label"><?=h(t('teacher.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h(format_minutes_short($progress['avg_minutes']))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.avg_time', 'Ø Bearbeitungszeit'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['recent_delegations'])?></div>
        <div class="stat-label"><?=h(t('teacher.progress.delegation_feedback', 'neue Rückmeldungen zu Delegationen'))?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?=h(t('teacher.management'))?></h2>
  <p class="muted"><?=h(t('teacher.management_hint'))?></p>

  <div class="nav-grid">
    <a class="nav-tile primary" href="<?=h(url('teacher/classes.php'))?>">
      <div class="nav-title"><?=h(t('teacher.my_classes'))?></div>
      <p class="nav-desc"><?=h(t('teacher.my_classes_desc'))?></p>
    </a>
    <a class="nav-tile primary" href="<?=h(url('teacher/entry.php'))?>">
      <div class="nav-title"><?=h(t('teacher.fill_entries'))?></div>
      <p class="nav-desc"><?=h(t('teacher.fill_entries_desc'))?></p>
    </a>
    <a class="nav-tile" href="<?=h(url('teacher/delegations.php'))?>">
      <div class="nav-title"><?=h(t('teacher.delegations'))?></div>
      <p class="nav-desc"><?=h(t('teacher.delegations_desc'))?></p>
      <div class="nav-meta">
        <?php if ($delegationCount>0): ?>
          <span class="badge"><?=h((string)$delegationCount)?></span>
          <span class="small"><?=h(t('teacher.delegations_open'))?></span>
        <?php else: ?>
          <span class="small muted"><?=h(t('teacher.delegations_none'))?></span>
        <?php endif; ?>
      </div>
    </a>
    <a class="nav-tile" href="<?=h(url('teacher/export.php'))?>">
      <div class="nav-title"><?=h(t('teacher.pdf_export'))?></div>
      <p class="nav-desc"><?=h(t('teacher.pdf_export_desc'))?></p>
    </a>
  </div>
</div>

<div class="card">
  <h2><?=h(t('teacher.class_list'))?></h2>

  <?php if (!$classes): ?>
    <div class="alert"><?=h(t('teacher.class_none'))?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?=h(t('teacher.table.school_year'))?></th>
          <th><?=h(t('teacher.table.class'))?></th>
          <th><?=h(t('teacher.table.actions'))?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($classes as $c):
        $label = (string)($c['label'] ?? '');
        $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
        $name = (string)($c['name'] ?? '');
        $display = ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
      ?>
        <tr>
          <td><?=h((string)$c['school_year'])?></td>
          <td><?=h($display)?></td>
          <td>
            <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.table.students'))?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
render_teacher_footer();
