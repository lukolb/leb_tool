<?php
// admin/students.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$userId = (int)(current_user()['id'] ?? 0);

$err = '';
$ok = '';

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['class_name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : '—');
}

function delete_students_cascade(PDO $pdo, array $studentIds): array {
  $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($x)=>$x>0));
  if (!$studentIds) return ['students_deleted'=>0,'reports_deleted'=>0,'values_deleted'=>0];

  $pdo->beginTransaction();

  $in = implode(',', array_fill(0, count($studentIds), '?'));

  // collect report ids
  $st = $pdo->prepare("SELECT id FROM report_instances WHERE student_id IN ($in)");
  $st->execute($studentIds);
  $reportIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));

  $valuesDeleted = 0;
  $reportsDeleted = 0;

  if ($reportIds) {
    $in2 = implode(',', array_fill(0, count($reportIds), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) AS cnt FROM field_values WHERE report_instance_id IN ($in2)");
    $st->execute($reportIds);
    $valuesDeleted = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

    $pdo->prepare("DELETE FROM field_values WHERE report_instance_id IN ($in2)")->execute($reportIds);
    $pdo->prepare("DELETE FROM report_instances WHERE id IN ($in2)")->execute($reportIds);
    $reportsDeleted = count($reportIds);
  }

  $pdo->prepare("DELETE FROM students WHERE id IN ($in)")->execute($studentIds);

  $pdo->commit();

  return ['students_deleted'=>count($studentIds),'reports_deleted'=>$reportsDeleted,'values_deleted'=>$valuesDeleted];
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'delete_student') {
      $studentId = (int)($_POST['student_id'] ?? 0);
      if ($studentId <= 0) throw new RuntimeException('student_id fehlt.');

      $confirm = (string)($_POST['confirm_text'] ?? '');
      $must = (string)($_POST['must_match'] ?? '');
      if ($confirm === '' || $must === '' || $confirm !== $must) {
        throw new RuntimeException('Sicherheitsabfrage fehlgeschlagen. Bitte exakt den angezeigten Text eingeben.');
      }

      $st = $pdo->prepare("SELECT id, master_student_id FROM students WHERE id=? LIMIT 1");
      $st->execute([$studentId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Schüler nicht gefunden.');

      $master = (int)($row['master_student_id'] ?? 0);
      $ids = [$studentId];
      if ($master > 0) {
        $st = $pdo->prepare("SELECT id FROM students WHERE master_student_id=?");
        $st->execute([$master]);
        $ids = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
      }

      $stats = delete_students_cascade($pdo, $ids);
      audit('admin_student_delete', $userId, ['student_id'=>$studentId,'deleted_ids'=>$ids] + $stats);
      $ok = "Schüler gelöscht (Einträge: {$stats['students_deleted']}, Berichte: {$stats['reports_deleted']}, Feldwerte: {$stats['values_deleted']}).";
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$schoolYear = trim((string)($_GET['school_year'] ?? ''));
$classId = (int)($_GET['class_id'] ?? 0);

$sort = (string)($_GET['sort'] ?? 'name');
$allowedSort = ['name','class','year','created'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';

$orderSql = match($sort) {
  'class' => "c.school_year DESC, c.grade_level DESC, c.label ASC, s.last_name ASC, s.first_name ASC",
  'year'  => "c.school_year DESC, s.last_name ASC, s.first_name ASC",
  'created' => "s.created_at DESC",
  default => "s.last_name ASC, s.first_name ASC, c.school_year DESC"
};

$params = [];
$where = "WHERE 1=1";
if ($q !== '') {
  $where .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.external_ref LIKE ?)";
  $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%";
}
if ($schoolYear !== '') {
  $where .= " AND c.school_year = ?";
  $params[] = $schoolYear;
}
if ($classId > 0) {
  $where .= " AND s.class_id = ?";
  $params[] = $classId;
}

$st = $pdo->prepare(
  "SELECT s.id, s.master_student_id, s.first_name, s.last_name, s.date_of_birth, s.external_ref, s.is_active,
          s.created_at,
          c.id AS class_id, c.school_year, c.grade_level, c.label, c.name AS class_name, c.is_active AS class_active
   FROM students s
   LEFT JOIN classes c ON c.id=s.class_id
   $where
   ORDER BY $orderSql
   LIMIT 500"
);
$st->execute($params);
$students = $st->fetchAll(PDO::FETCH_ASSOC);

// Filter dropdown data
$years = $pdo->query("SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$classes = $pdo->query("SELECT id, school_year, grade_level, label, name, is_active FROM classes ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

render_admin_header('Schüler');
?>

<div class="card">
  <h1>Schüler (Admin)</h1>
</div>

<div class="card">
  <form method="get" class="grid" style="grid-template-columns: 1fr 160px 240px 160px auto; gap:12px; align-items:end;">
    <div>
      <label>Suche</label>
      <input name="q" type="text" value="<?=h($q)?>" placeholder="Name oder ID">
    </div>
    <div>
      <label>Schuljahr</label>
      <select name="school_year">
        <option value="">— alle —</option>
        <?php foreach ($years as $y): ?>
          <option value="<?=h((string)$y)?>" <?=($schoolYear===(string)$y)?'selected':''?>><?=h((string)$y)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Klasse</label>
      <select name="class_id">
        <option value="0">— alle —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?=h((string)$c['id'])?>" <?=($classId===(int)$c['id'])?'selected':''?>>
            <?=h((string)$c['school_year'])?> · <?=h(((int)$c['grade_level']).(string)$c['label'])?><?=((int)$c['is_active']===0)?' (inaktiv)':''?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Sortierung</label>
      <select name="sort">
        <option value="name" <?=($sort==='name')?'selected':''?>>Name</option>
        <option value="class" <?=($sort==='class')?'selected':''?>>Klasse</option>
        <option value="year" <?=($sort==='year')?'selected':''?>>Schuljahr</option>
        <option value="created" <?=($sort==='created')?'selected':''?>>Neueste</option>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn primary" type="submit">Filtern</button>
      <a class="btn secondary" href="<?=h(url('admin/students.php'))?>">Reset</a>
    </div>
  </form>

  <div class="muted" style="margin-top:10px;">Maximal 500 Treffer (Performance).</div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Liste</h2>

  <?php if (!$students): ?>
    <div class="alert">Keine Schüler gefunden.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Geburtsdatum</th>
          <th>Klasse</th>
          <th>Schuljahr</th>
          <th>Aktiv</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?=h((string)$s['last_name'])?>, <?=h((string)$s['first_name'])?></td>
          <td><?=h((string)($s['date_of_birth'] ?? ''))?></td>
          <td><?=h(class_display($s))?></td>
          <td><?=h((string)($s['school_year'] ?? '—'))?></td>
          <td>
            <?=((int)$s['is_active']===1) ? '<span class="badge">ja</span>' : '<span class="badge">nein</span>'?>
            <?=((int)($s['class_active'] ?? 1)===0) ? ' <span class="badge">Klasse inaktiv</span>' : ''?>
          </td>
          <td>
            <details>
              <summary class="btn secondary" style="display:inline-block; cursor:pointer;">Verwalten</summary>
              <div class="panel" style="margin-top:10px;">
                <?php
                  $must = (string)$s['last_name'] . ', ' . (string)$s['first_name'];
                ?>
                <div class="muted">Löschen entfernt auch alle zugehörigen Berichte/Feldwerte. Bestätige mit: <code><?=h($must)?></code></div>
                <form method="post" onsubmit="return confirm('Wirklich endgültig löschen?');" class="grid" style="grid-template-columns: 1fr auto; gap:10px; align-items:end; margin-top:10px;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete_student">
                  <input type="hidden" name="student_id" value="<?=h((string)$s['id'])?>">
                  <input type="hidden" name="must_match" value="<?=h($must)?>">
                  <div>
                    <label>Bestätigung</label>
                    <input name="confirm_text" type="text" placeholder="<?=h($must)?>" required>
                  </div>
                  <div class="actions" style="justify-content:flex-start;">
                    <button class="btn danger" type="submit">Schüler löschen</button>
                  </div>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
