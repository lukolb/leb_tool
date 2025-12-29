<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

// Delegations inbox count (groups delegated to this teacher)
$delegationCount = 0;
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM class_group_delegations WHERE user_id=?");
  $st->execute([$userId]);
  $delegationCount = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  // ignore
}

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

render_teacher_header(t('teacher.title'));
?>

<div class="card">
    <h1><?=h(t('teacher.dashboard'))?></h1>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
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
