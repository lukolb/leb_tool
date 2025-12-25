<?php
// admin/classes.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$userId = (int)(current_user()['id'] ?? 0);

$cfg = app_config();
$defaultSchoolYear = (string)($cfg['app']['default_school_year'] ?? '');

$err = '';
$ok = '';

function normalize_school_year(string $s): string { return trim($s); }
function normalize_label(string $s): string {
  $s = trim($s);
  $s = strtolower($s);
  $s = preg_replace('/\s+/', '', $s);
  return $s;
}
function computed_name(?int $grade, string $label): string {
  $label = normalize_label($label);
  if ($grade === null || $grade <= 0 || $label === '') return trim((string)$grade . $label);
  return (string)$grade . $label;
}
function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}
function parse_labels(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  // a-b
  if (preg_match('/^([a-z])\s*-\s*([a-z])$/i', $raw, $m)) {
    $a = ord(strtolower($m[1]));
    $b = ord(strtolower($m[2]));
    if ($b < $a) [$a, $b] = [$b, $a];
    $out = [];
    for ($c = $a; $c <= $b; $c++) $out[] = chr($c);
    return $out;
  }
  // a,b,c
  $parts = array_filter(array_map('trim', explode(',', $raw)));
  $out = [];
  foreach ($parts as $p) {
    $p = normalize_label($p);
    if ($p !== '') $out[] = $p;
  }
  return array_values(array_unique($out));
}

/**
 * Template-Status prüfen (true wenn aktiv).
 */
function is_template_active(PDO $pdo, int $templateId): bool {
  if ($templateId <= 0) return false;
  $st = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
  $st->execute([$templateId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? ((int)($row['is_active'] ?? 0) === 1) : false;
}

/**
 * Stellt sicher, dass ein Template ausgewählt werden darf.
 * - erlaubt: null/0 (keine Vorlage)
 * - erlaubt: aktives Template
 * - erlaubt: inaktives Template nur, wenn es bereits zugeordnet ist (edit scenario)
 */
function assert_template_selectable(PDO $pdo, ?int $newTemplateId, ?int $currentTemplateId = null): ?int {
  $tid = $newTemplateId ?? 0;
  if ($tid <= 0) return null;

  if (is_template_active($pdo, $tid)) return $tid;

  // inaktiv: nur zulassen, wenn es bereits der Klasse zugeordnet ist
  if ($currentTemplateId !== null && $currentTemplateId > 0 && $tid === (int)$currentTemplateId) {
    return $tid;
  }

  throw new RuntimeException('Die ausgewählte Vorlage ist inaktiv und kann nicht neu zugeordnet werden.');
}

function delete_class_with_all_data(PDO $pdo, int $classId): array {
  // gather students
  $st = $pdo->prepare("SELECT id FROM students WHERE class_id=?");
  $st->execute([$classId]);
  $studentIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));

  $pdo->beginTransaction();

  // remove assignments
  $pdo->prepare("DELETE FROM user_class_assignments WHERE class_id=?")->execute([$classId]);

  if ($studentIds) {
    $in = implode(',', array_fill(0, count($studentIds), '?'));

    // report instances for these students
    $ri = $pdo->prepare("SELECT id FROM report_instances WHERE student_id IN ($in)");
    $ri->execute($studentIds);
    $reportIds = array_map(fn($r)=>(int)$r['id'], $ri->fetchAll(PDO::FETCH_ASSOC));

    if ($reportIds) {
      $in2 = implode(',', array_fill(0, count($reportIds), '?'));
      $pdo->prepare("DELETE FROM field_values WHERE report_instance_id IN ($in2)")->execute($reportIds);
      $pdo->prepare("DELETE FROM report_instances WHERE id IN ($in2)")->execute($reportIds);
    }

    $pdo->prepare("DELETE FROM students WHERE id IN ($in)")->execute($studentIds);
  }

  $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([$classId]);

  $pdo->commit();

  return ['students_deleted' => count($studentIds)];
}

// Teachers list
$teachers = $pdo->query("SELECT id, display_name, email FROM users WHERE role='teacher' AND deleted_at IS NULL ORDER BY display_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Templates list (for class assignment)
$templates = $pdo->query(
  "SELECT id, name, template_version, is_active
   FROM templates
   ORDER BY is_active DESC, template_version DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create_single') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeLevel = (int)($_POST['grade_level'] ?? 0);
      $label = normalize_label((string)($_POST['label'] ?? ''));
      if ($schoolYear === '') throw new RuntimeException('Schuljahr fehlt.');
      if ($gradeLevel <= 0) throw new RuntimeException('Klassenstufe fehlt.');
      if ($label === '') throw new RuntimeException('Bezeichnung fehlt.');

      $name = computed_name($gradeLevel, $label);

      // template assignment (optional) -> darf nicht inaktiv sein
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, null);

      $pdo->prepare("INSERT INTO classes (school_year, grade_level, label, name, template_id, is_active) VALUES (?, ?, ?, ?, ?, 1)")
          ->execute([$schoolYear, $gradeLevel, $label, $name, $templateId]);
      $classId = (int)$pdo->lastInsertId();

      $teacherIds = $_POST['teacher_ids'] ?? [];
      if (!is_array($teacherIds)) $teacherIds = [];
      foreach ($teacherIds as $tid) {
        $tid = (int)$tid;
        if ($tid > 0) {
          $pdo->prepare("INSERT IGNORE INTO user_class_assignments (user_id, class_id) VALUES (?, ?)")
              ->execute([$tid, $classId]);
        }
      }

      audit('admin_class_create', $userId, [
        'class_id'=>$classId,
        'school_year'=>$schoolYear,
        'grade_level'=>$gradeLevel,
        'label'=>$label,
        'template_id'=>$templateId
      ]);
      $ok = 'Klasse wurde angelegt.';
    }

    elseif ($action === 'create_bulk') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeFrom = (int)($_POST['grade_from'] ?? 0);
      $gradeTo   = (int)($_POST['grade_to'] ?? 0);
      $labelsRaw = (string)($_POST['labels'] ?? '');
      if ($schoolYear === '') throw new RuntimeException('Schuljahr fehlt.');
      if ($gradeFrom <= 0 || $gradeTo <= 0) throw new RuntimeException('Stufenbereich fehlt.');
      if ($gradeTo < $gradeFrom) [$gradeFrom, $gradeTo] = [$gradeTo, $gradeFrom];
      $labels = parse_labels($labelsRaw);
      if (!$labels) throw new RuntimeException('Bezeichnungen fehlen (z.B. a-b oder a,b).');

      $teacherIds = $_POST['teacher_ids'] ?? [];
      if (!is_array($teacherIds)) $teacherIds = [];
      $teacherIds = array_values(array_filter(array_map('intval', $teacherIds), fn($x)=>$x>0));

      // template assignment (optional) - applied to all new classes -> darf nicht inaktiv sein
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, null);

      $created = 0;
      $skipped = 0;

      $pdo->beginTransaction();

      for ($g = $gradeFrom; $g <= $gradeTo; $g++) {
        foreach ($labels as $lab) {
          $lab = normalize_label($lab);
          if ($lab === '') continue;

          // Skip if exists
          $q = $pdo->prepare("SELECT id FROM classes WHERE school_year=? AND grade_level=? AND label=? LIMIT 1");
          $q->execute([$schoolYear, $g, $lab]);
          if ($q->fetch()) { $skipped++; continue; }

          $name = computed_name($g, $lab);
          $pdo->prepare("INSERT INTO classes (school_year, grade_level, label, name, template_id, is_active) VALUES (?, ?, ?, ?, ?, 1)")
              ->execute([$schoolYear, $g, $lab, $name, $templateId]);
          $cid = (int)$pdo->lastInsertId();

          foreach ($teacherIds as $tid) {
            $pdo->prepare("INSERT IGNORE INTO user_class_assignments (user_id, class_id) VALUES (?, ?)")
                ->execute([$tid, $cid]);
          }
          $created++;
        }
      }

      $pdo->commit();
      audit('admin_class_create_bulk', $userId, [
        'school_year'=>$schoolYear,
        'grade_from'=>$gradeFrom,
        'grade_to'=>$gradeTo,
        'labels'=>$labels,
        'created'=>$created,
        'skipped'=>$skipped,
        'template_id'=>$templateId
      ]);
      $ok = "Bulk erstellt: {$created} (übersprungen: {$skipped})";
    }

    elseif ($action === 'update_class') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      // aktuelles Template der Klasse laden (für "inaktiv aber bereits zugeordnet" Regel)
      $stCur = $pdo->prepare("SELECT template_id FROM classes WHERE id=? LIMIT 1");
      $stCur->execute([$classId]);
      $curRow = $stCur->fetch(PDO::FETCH_ASSOC);
      if (!$curRow) throw new RuntimeException('Klasse nicht gefunden.');
      $currentTemplateId = (int)($curRow['template_id'] ?? 0);

      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeLevel = (int)($_POST['grade_level'] ?? 0);
      $label = normalize_label((string)($_POST['label'] ?? ''));
      $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;

      if ($schoolYear === '') throw new RuntimeException('Schuljahr fehlt.');
      if ($gradeLevel <= 0) throw new RuntimeException('Klassenstufe fehlt.');
      if ($label === '') throw new RuntimeException('Bezeichnung fehlt.');

      $name = computed_name($gradeLevel, $label);

      // template assignment (optional)
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, $currentTemplateId);

      $pdo->prepare("UPDATE classes SET school_year=?, grade_level=?, label=?, name=?, template_id=?, is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
          ->execute([$schoolYear, $gradeLevel, $label, $name, $templateId, $isActive, $isActive, $classId]);

      // Update assignments
      $teacherIds = $_POST['teacher_ids'] ?? [];
      if (!is_array($teacherIds)) $teacherIds = [];
      $teacherIds = array_values(array_filter(array_map('intval', $teacherIds), fn($x)=>$x>0));

      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM user_class_assignments WHERE class_id=?")->execute([$classId]);
      foreach ($teacherIds as $tid) {
        $pdo->prepare("INSERT IGNORE INTO user_class_assignments (user_id, class_id) VALUES (?, ?)")->execute([$tid, $classId]);
      }
      $pdo->commit();

      audit('admin_class_update', $userId, [
        'class_id'=>$classId,
        'is_active'=>$isActive,
        'teacher_ids'=>$teacherIds,
        'template_id'=>$templateId
      ]);
      $ok = 'Klasse wurde aktualisiert.';
    }

    elseif ($action === 'toggle_active') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
      $st = $pdo->prepare("SELECT is_active FROM classes WHERE id=?");
      $st->execute([$classId]);
      $row = $st->fetch();
      if (!$row) throw new RuntimeException('Klasse nicht gefunden.');
      $new = ((int)$row['is_active']===1) ? 0 : 1;
      $pdo->prepare("UPDATE classes SET is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
          ->execute([$new, $new, $classId]);
      audit('admin_class_toggle_active', $userId, ['class_id'=>$classId,'is_active'=>$new]);
      $ok = $new ? 'Klasse wurde aktiviert.' : 'Klasse wurde inaktiv gesetzt.';
    }

    elseif ($action === 'delete_class') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      $confirm = (string)($_POST['confirm_text'] ?? '');
      $must = (string)($_POST['must_match'] ?? '');
      if ($confirm === '' || $must === '' || $confirm !== $must) {
        throw new RuntimeException('Sicherheitsabfrage fehlgeschlagen. Bitte exakt den angezeigten Text eingeben.');
      }

      $stats = delete_class_with_all_data($pdo, $classId);
      audit('admin_class_delete', $userId, ['class_id'=>$classId] + $stats);
      $ok = "Klasse gelöscht (Schüler gelöscht: {$stats['students_deleted']}).";
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

// View options
$showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

// Edit class?
$editId = (int)($_GET['edit'] ?? 0);
$editClass = null;
$editTeacherIds = [];
if ($editId > 0) {
  $st = $pdo->prepare("SELECT * FROM classes WHERE id=? LIMIT 1");
  $st->execute([$editId]);
  $editClass = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($editClass) {
    $st2 = $pdo->prepare("SELECT user_id FROM user_class_assignments WHERE class_id=?");
    $st2->execute([$editId]);
    $editTeacherIds = array_map(fn($r)=>(int)$r['user_id'], $st2->fetchAll(PDO::FETCH_ASSOC));
  }
}

// Classes list with teacher names + template
$where = $showInactive ? "" : "WHERE c.is_active=1";
$classes = $pdo->query(
  "SELECT c.*,
          (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS student_count,
          GROUP_CONCAT(DISTINCT u.display_name ORDER BY u.display_name SEPARATOR ', ') AS teacher_names,
          GROUP_CONCAT(DISTINCT u.id ORDER BY u.id SEPARATOR ',') AS teacher_ids,
          t.name AS template_name,
          t.template_version AS template_version,
          t.is_active AS template_is_active
   FROM classes c
   LEFT JOIN user_class_assignments uca ON uca.class_id=c.id
   LEFT JOIN users u ON u.id=uca.user_id AND u.deleted_at IS NULL
   LEFT JOIN templates t ON t.id=c.template_id
   $where
   GROUP BY c.id
   ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Group by school_year
$grouped = [];
foreach ($classes as $c) {
  $y = (string)$c['school_year'];
  if (!isset($grouped[$y])) $grouped[$y] = [];
  $grouped[$y][] = $c;
}

render_admin_header('Klassen');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('admin/users.php'))?>">User</a>
    <a class="btn secondary" href="<?=h(url('admin/students.php'))?>">Schüler</a>
  </div>

  <h1 style="margin-top:0;">Klassenverwaltung</h1>

  <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

  <div class="actions" style="justify-content:flex-start;">
    <?php if ($showInactive): ?>
      <a class="btn secondary" href="<?=h(url('admin/classes.php'))?>">Inaktive ausblenden</a>
    <?php else: ?>
      <a class="btn secondary" href="<?=h(url('admin/classes.php?show_inactive=1'))?>">Inaktive anzeigen</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Klassen anlegen</h2>

  <div class="grid" style="grid-template-columns: 1fr; gap:14px;">
    <div class="panel">
      <h3 style="margin-top:0;">Einzeln</h3>
      <form method="post" class="grid" style="grid-template-columns: 1fr 120px 120px 1fr 1fr; gap:12px; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create_single">

        <div>
          <label>Schuljahr</label>
          <input name="school_year" type="text" value="<?=h($defaultSchoolYear)?>" placeholder="2025/26" required>
        </div>
        <div>
          <label>Stufe</label>
          <input name="grade_level" type="number" min="1" max="13" required>
        </div>
        <div>
          <label>Bezeichnung</label>
          <input name="label" type="text" placeholder="a" required>
        </div>

        <div>
          <label>Vorlage</label>
          <select name="template_id">
            <option value="0">— Keine —</option>
            <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
              <option value="<?=h((string)$tid)?>" <?=($inactive ? 'disabled' : '')?>>
                <?=h((string)$tpl['name'])?>
                <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
                <?=($inactive ? ' – inaktiv' : '')?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted">Wenn leer: Lehrkraft sieht Hinweis „keine Vorlage zugeordnet“.</div>
        </div>

        <div>
          <label>Lehrkräfte</label>
          <select name="teacher_ids[]" multiple size="4">
            <?php foreach ($teachers as $t): ?>
              <option value="<?=h((string)$t['id'])?>"><?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="muted">Wenn leer: keine automatische Zuordnung.</div>
        </div>

        <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
          <button class="btn primary" type="submit">Anlegen</button>
        </div>
      </form>
    </div>

    <div class="panel">
      <h3 style="margin-top:0;">Bulk</h3>
      <form method="post" class="grid" style="grid-template-columns: 1fr 160px 160px 1fr 1fr; gap:12px; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create_bulk">

        <div>
          <label>Schuljahr</label>
          <input name="school_year" type="text" value="<?=h($defaultSchoolYear)?>" placeholder="2025/26" required>
        </div>
        <div>
          <label>Stufe von</label>
          <input name="grade_from" type="number" min="1" max="13" required>
        </div>
        <div>
          <label>Stufe bis</label>
          <input name="grade_to" type="number" min="1" max="13" required>
        </div>
        <div>
          <label>Bezeichnungen</label>
          <input name="labels" type="text" placeholder="a-b oder a,b,c" required>
          <div class="muted" style="margin-top:6px;">Beispiele: <code>a-b</code>, <code>a,b</code></div>
        </div>

        <div>
          <label>Vorlage</label>
          <select name="template_id">
            <option value="0">— Keine —</option>
            <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
              <option value="<?=h((string)$tid)?>" <?=($inactive ? 'disabled' : '')?>>
                <?=h((string)$tpl['name'])?>
                <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
                <?=($inactive ? ' – inaktiv' : '')?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted">Wird auf alle neu angelegten Klassen angewendet.</div>
        </div>

        <div style="grid-column:1/-1;">
          <label>Lehrkräfte (werden allen neu angelegten Klassen zugeordnet)</label>
          <select name="teacher_ids[]" multiple size="4">
            <?php foreach ($teachers as $t): ?>
              <option value="<?=h((string)$t['id'])?>"><?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="muted">Wenn leer: keine automatische Zuordnung.</div>
        </div>

        <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
          <button class="btn primary" type="submit">Bulk anlegen</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Klassen (nach Schuljahr)</h2>

  <?php if (!$grouped): ?>
    <div class="alert">Keine Klassen gefunden.</div>
  <?php else: ?>
    <?php foreach ($grouped as $year => $items): ?>
      <details open style="margin-bottom:10px;">
        <summary style="cursor:pointer; font-weight:700; padding:10px 0;">
          <?=h($year)?> (<?=count($items)?>)
        </summary>

        <table class="table">
          <thead>
            <tr>
              <th>Klasse</th>
              <th>Vorlage</th>
              <th>Lehrkräfte</th>
              <th>Schüler</th>
              <th>Status</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $c): ?>
            <tr>
              <td><?=h(class_display($c))?></td>
              <td>
                <?php
                  $tplName = (string)($c['template_name'] ?? '');
                  $tplVer  = (int)($c['template_version'] ?? 0);
                  $tplAct  = (int)($c['template_is_active'] ?? 0);
                  if ($tplName === '') {
                    echo '<span class="muted">—</span>';
                  } else {
                    echo h($tplName);
                    if ($tplVer > 0) echo ' <span class="muted">(v' . h((string)$tplVer) . ')</span>';
                    if ($tplAct !== 1) echo ' <span class="badge">inaktiv</span>';
                  }
                ?>
              </td>
              <td><?=h((string)($c['teacher_names'] ?? '—'))?></td>
              <td><?=h((string)$c['student_count'])?></td>
              <td><?=((int)$c['is_active']===1) ? '<span class="badge">aktiv</span>' : '<span class="badge">inaktiv</span>'?></td>
              <td style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn secondary" href="<?=h(url('admin/classes.php?edit='.(int)$c['id']))?>">Bearbeiten</a>
                <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id='.(int)$c['id']))?>">Schüler</a>
                <a class="btn secondary" href="<?=h(url('admin/export.php?class_id='.(int)$c['id']))?>">Export</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                  <button class="btn secondary" type="submit"><?=((int)$c['is_active']===1)?'Inaktiv setzen':'Aktivieren'?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($editClass): ?>
  <div class="card">
    <h2 style="margin-top:0;">Klasse bearbeiten: <?=h((string)$editClass['school_year'])?> · <?=h(class_display($editClass))?></h2>

    <form method="post" class="grid" style="grid-template-columns: 1fr 120px 120px 160px 1fr 1fr; gap:12px; align-items:end;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="update_class">
      <input type="hidden" name="class_id" value="<?=h((string)$editClass['id'])?>">

      <div>
        <label>Schuljahr</label>
        <input name="school_year" type="text" value="<?=h((string)$editClass['school_year'])?>" required>
      </div>
      <div>
        <label>Stufe</label>
        <input name="grade_level" type="number" min="1" max="13" value="<?=h((string)($editClass['grade_level'] ?? ''))?>" required>
      </div>
      <div>
        <label>Bezeichnung</label>
        <input name="label" type="text" value="<?=h((string)($editClass['label'] ?? ''))?>" required>
      </div>
      <div>
        <label>Status</label>
        <select name="is_active">
          <option value="1" <?=((int)($editClass['is_active'] ?? 1)===1)?'selected':''?>>aktiv</option>
          <option value="0" <?=((int)($editClass['is_active'] ?? 1)===0)?'selected':''?>>inaktiv</option>
        </select>
      </div>

      <div>
        <label>Vorlage</label>
        <?php $curTplId = (int)($editClass['template_id'] ?? 0); ?>
        <select name="template_id">
          <option value="0">— Keine —</option>
          <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
            <option value="<?=h((string)$tid)?>"
              <?=($tid===$curTplId ? 'selected' : '')?>
              <?=($inactive && $tid!==$curTplId ? 'disabled' : '')?>>
              <?=h((string)$tpl['name'])?>
              <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
              <?=($inactive ? ' – inaktiv' : '')?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="muted">Ohne Vorlage: Lehrkraft/Schüler sehen Hinweis, dass keine Vorlage zugeordnet ist.</div>
      </div>

      <div>
        <label>Lehrkräfte</label>
        <select name="teacher_ids[]" multiple size="6">
          <?php foreach ($teachers as $t): $tid=(int)$t['id']; ?>
            <option value="<?=h((string)$tid)?>" <?=in_array($tid, $editTeacherIds, true) ? 'selected' : ''?>>
              <?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
        <button class="btn primary" type="submit">Speichern</button>
        <a class="btn secondary" href="<?=h(url('admin/classes.php'))?>">Zurück</a>
      </div>
    </form>

    <hr style="margin:18px 0; border:none; border-top:1px solid var(--border);">

    <h3 style="margin:0 0 8px 0;">Klasse löschen</h3>
    <div class="alert danger">
      <strong>Achtung:</strong> Löscht die Klasse <em>und alle dazugehörigen Daten</em> (Schüler inkl. Berichte/Feldwerte).
      Das ist nicht rückgängig zu machen.
    </div>

    <?php $must = (string)$editClass['school_year'] . ' ' . class_display($editClass); ?>

    <form method="post" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="delete_class">
      <input type="hidden" name="class_id" value="<?=h((string)$editClass['id'])?>">
      <input type="hidden" name="must_match" value="<?=h($must)?>">

      <div class="muted" style="grid-column:1/-1;">Tippe zur Bestätigung exakt: <code><?=h($must)?></code></div>

      <div>
        <label>Bestätigung</label>
        <input name="confirm_text" type="text" placeholder="<?=h($must)?>" required>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <button class="btn danger" type="submit">Endgültig löschen</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php render_admin_footer(); ?>
