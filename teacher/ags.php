<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$isAdmin = get_role() === 'admin';

if ($isAdmin) {
  $classStmt = $pdo->query("SELECT id, school_year, period_label, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level ASC, label ASC");
} else {
  $classStmt = $pdo->prepare("SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name FROM classes c JOIN class_teachers ct ON ct.class_id=c.id WHERE ct.user_id=? AND c.is_active=1 ORDER BY c.school_year DESC, c.grade_level ASC, c.label ASC");
  $classStmt->execute([$userId]);
}
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$defaultClassId = (int)($classes[0]['id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? $defaultClassId);

$selectedClass = null;
foreach ($classes as $c) { if ((int)$c['id'] === $classId) { $selectedClass = $c; break; } }
if (!$selectedClass && $defaultClassId > 0) {
  $classId = $defaultClassId;
  foreach ($classes as $c) { if ((int)$c['id'] === $classId) { $selectedClass = $c; break; } }
}

$ok = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedClass) {
  try {
    csrf_verify();
    $schoolYear = (string)$selectedClass['school_year'];
    $periodLabel = normalize_class_period_label((string)$selectedClass['period_label']);
    $posted = $_POST['ag'] ?? [];
    if (!is_array($posted)) $posted = [];

    $students = $pdo->prepare("SELECT id FROM students WHERE class_id=? ORDER BY last_name, first_name");
    $students->execute([$classId]);
    $studentIds = array_map('intval', $students->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM student_ag_assignments WHERE class_id=? AND school_year=? AND period_label=?");
    $del->execute([$classId, $schoolYear, $periodLabel]);
    $ins = $pdo->prepare("INSERT INTO student_ag_assignments (student_id, class_id, ag_id, school_year, period_label, created_by_user_id) VALUES (?,?,?,?,?,?)");
    $count = 0;
    foreach ($posted as $studentIdRaw => $agIdsRaw) {
      $sid = (int)$studentIdRaw;
      if (!in_array($sid, $studentIds, true)) continue;
      $agIds = is_array($agIdsRaw) ? $agIdsRaw : [];
      foreach ($agIds as $aidRaw) {
        $aid = (int)$aidRaw;
        if ($aid <= 0) continue;
        $ins->execute([$sid, $classId, $aid, $schoolYear, $periodLabel, $userId]);
        $count++;
      }
    }
    $pdo->commit();
    audit('teacher_ag_assignment_save', $userId, ['class_id' => $classId, 'count' => $count]);
    $ok = 'AG-Zuordnungen gespeichert.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$students = [];
$ags = [];
$selectedMap = [];
if ($selectedClass) {
  $schoolYear = (string)$selectedClass['school_year'];
  $periodLabel = normalize_class_period_label((string)$selectedClass['period_label']);

  $stStudents = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE class_id=? ORDER BY last_name ASC, first_name ASC");
  $stStudents->execute([$classId]);
  $students = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stAg = $pdo->prepare("SELECT id, ag_name FROM ag_catalog WHERE school_year=? AND period_label=? AND is_active=1 ORDER BY sort_order ASC, ag_name ASC");
  $stAg->execute([$schoolYear, $periodLabel]);
  $ags = $stAg->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stSel = $pdo->prepare("SELECT student_id, ag_id FROM student_ag_assignments WHERE class_id=? AND school_year=? AND period_label=?");
  $stSel->execute([$classId, $schoolYear, $periodLabel]);
  foreach ($stSel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $sid = (int)$r['student_id'];
    $aid = (int)$r['ag_id'];
    $selectedMap[$sid][$aid] = true;
  }
}

render_teacher_header('AG-Eingaben');
?>
<div class="card">
  <h1>AG-Eingaben</h1>
  <?php if ($ok): ?><div class="alert success"><?=h($ok)?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert danger"><?=h($err)?></div><?php endif; ?>

  <form method="get" class="row-actions">
    <label>Klasse</label>
    <select class="input" name="class_id">
      <?php foreach ($classes as $c): ?>
        <option value="<?=h((string)$c['id'])?>" <?=((int)$c['id']===$classId)?'selected':''?>><?=h((string)$c['school_year'].' · '.class_display($c).' · '.normalize_class_period_label((string)$c['period_label']))?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn secondary" type="submit">Anzeigen</button>
  </form>

  <?php if (!$selectedClass): ?>
    <div class="alert">Keine Klasse verfügbar.</div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <table class="table">
        <tr><th>Schüler</th><th>Besuchte AGs (0-x)</th></tr>
        <?php foreach ($students as $s): $sid=(int)$s['id']; ?>
          <tr>
            <td><?=h((string)$s['last_name'].', '.(string)$s['first_name'])?></td>
            <td>
              <?php foreach ($ags as $ag): $aid=(int)$ag['id']; ?>
                <label style="display:inline-flex; gap:6px; margin:0 12px 6px 0;">
                  <input type="checkbox" name="ag[<?=h((string)$sid)?>][]" value="<?=h((string)$aid)?>" <?=!empty($selectedMap[$sid][$aid])?'checked':''?>>
                  <span><?=h((string)$ag['ag_name'])?></span>
                </label>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <button class="btn primary" type="submit">Speichern</button>
    </form>
  <?php endif; ?>
</div>
<?php render_teacher_footer();
