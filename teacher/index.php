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

render_teacher_header('Lehrkraft – Übersicht');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('teacher/classes.php'))?>">Meine Klassen</a>
    <a class="btn secondary" href="<?=h(url('teacher/delegations.php'))?>">Delegationen<?= $delegationCount>0 ? ' <span class="badge">'.h((string)$delegationCount).'</span>' : '' ?></a>
  </div>
</div>


<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
    <?php if (($u['role'] ?? '') === 'admin'): ?>
      <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">Admin</a>
    <?php endif; ?>
  </div>

  <h1 style="margin-top:0;">Hallo <?=h((string)($u['display_name'] ?? ''))?></h1>
  <p class="muted">Hier verwaltest du deine Klassen und Schüler. Danach können Berichte vorbereitet und ausgefüllt werden.</p>

  <div class="actions">
    <a class="btn primary" href="<?=h(url('teacher/classes.php'))?>">Meine Klassen</a>
    <a class="btn secondary" href="<?=h(url('teacher/entry.php'))?>">Eingaben ausfüllen</a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Deine Klassen</h2>

  <?php if (!$classes): ?>
    <div class="alert">Noch keine Klassen zugeordnet. Bitte wende dich an den Admin, damit dir Klassen zugeordnet werden.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Schuljahr</th>
          <th>Klasse</th>
          <th>Aktionen</th>
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
            <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id=' . (int)$c['id']))?>">Schüler</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
render_teacher_footer();
