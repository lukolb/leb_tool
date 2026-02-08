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
function normalize_period_label(string $s): string {
  $s = normalize_class_period_label($s);
  return in_array($s, ['Standard', 'H2'], true) ? $s : 'Standard';
}
function period_label_options(): array {
  return [
    'Standard' => t('admin.classes.period.h1', '1. Halbjahr'),
    'H2' => t('admin.classes.period.h2', '2. Halbjahr'),
  ];
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
 * NEW: Wizard display normalize helper (per-class column classes.student_wizard_display)
 */
function normalize_wizard_display(string $v): string {
  $v = strtolower(trim($v));
  return in_array($v, ['groups','items','beginner'], true) ? $v : 'groups';
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

  throw new RuntimeException(t('admin.classes.error.template_inactive'));
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
$teachers = $pdo->query("SELECT id, display_name, email FROM users WHERE role IN ('teacher','admin') AND deleted_at IS NULL ORDER BY display_name ASC")->fetchAll(PDO::FETCH_ASSOC);

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
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      if ($gradeLevel <= 0) throw new RuntimeException(t('admin.classes.error.grade_missing'));
      if ($label === '') throw new RuntimeException(t('admin.classes.error.label_missing'));

      $name = computed_name($gradeLevel, $label);

      // template assignment (optional) -> darf nicht inaktiv sein
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, null);

      // NEW: student wizard display per class
      $wizardDisplay = normalize_wizard_display((string)($_POST['student_wizard_display'] ?? 'groups'));

      $pdo->prepare("INSERT INTO classes (school_year, period_label, grade_level, label, name, template_id, student_wizard_display, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
          ->execute([$schoolYear, $periodLabel, $gradeLevel, $label, $name, $templateId, $wizardDisplay]);
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
        'period_label'=>$periodLabel,
        'template_id'=>$templateId,
        'student_wizard_display'=>$wizardDisplay
      ]);
      $ok = t('admin.classes.ok.created');
    }

    elseif ($action === 'create_bulk') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeFrom = (int)($_POST['grade_from'] ?? 0);
      $gradeTo   = (int)($_POST['grade_to'] ?? 0);
      $labelsRaw = (string)($_POST['labels'] ?? '');
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      if ($gradeFrom <= 0 || $gradeTo <= 0) throw new RuntimeException(t('admin.classes.error.grade_range_missing'));
      if ($gradeTo < $gradeFrom) [$gradeFrom, $gradeTo] = [$gradeTo, $gradeFrom];
      $labels = parse_labels($labelsRaw);
      if (!$labels) throw new RuntimeException(t('admin.classes.error.labels_missing'));

      $teacherIds = $_POST['teacher_ids'] ?? [];
      if (!is_array($teacherIds)) $teacherIds = [];
      $teacherIds = array_values(array_filter(array_map('intval', $teacherIds), fn($x)=>$x>0));

      // template assignment (optional) - applied to all new classes -> darf nicht inaktiv sein
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, null);

      // NEW: student wizard display per class (applied to all new classes)
      $wizardDisplay = normalize_wizard_display((string)($_POST['student_wizard_display'] ?? 'groups'));

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
          $pdo->prepare("INSERT INTO classes (school_year, period_label, grade_level, label, name, template_id, student_wizard_display, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
              ->execute([$schoolYear, $periodLabel, $g, $lab, $name, $templateId, $wizardDisplay]);
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
        'period_label'=>$periodLabel,
        'created'=>$created,
        'skipped'=>$skipped,
        'template_id'=>$templateId,
        'student_wizard_display'=>$wizardDisplay
      ]);
      $ok = str_replace(
        ['{created}', '{skipped}'],
        [(string)$created, (string)$skipped],
        t('admin.classes.ok.bulk_created')
      );
    }

    elseif ($action === 'update_class') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException(t('admin.classes.error.class_id_missing'));

      // aktuelles Template der Klasse laden (für "inaktiv aber bereits zugeordnet" Regel)
      $stCur = $pdo->prepare("SELECT template_id FROM classes WHERE id=? LIMIT 1");
      $stCur->execute([$classId]);
      $curRow = $stCur->fetch(PDO::FETCH_ASSOC);
      if (!$curRow) throw new RuntimeException(t('admin.classes.error.class_not_found'));
      $currentTemplateId = (int)($curRow['template_id'] ?? 0);

      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeLevel = (int)($_POST['grade_level'] ?? 0);
      $label = normalize_label((string)($_POST['label'] ?? ''));
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;

      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      if ($gradeLevel <= 0) throw new RuntimeException(t('admin.classes.error.grade_missing'));
      if ($label === '') throw new RuntimeException(t('admin.classes.error.label_missing'));

      $name = computed_name($gradeLevel, $label);

      // template assignment (optional)
      $templateIdRaw = (int)($_POST['template_id'] ?? 0);
      $templateId = assert_template_selectable($pdo, $templateIdRaw, $currentTemplateId);

      // NEW: student wizard display per class (editable)
      $wizardDisplay = normalize_wizard_display((string)($_POST['student_wizard_display'] ?? 'groups'));

      $pdo->prepare("UPDATE classes SET school_year=?, period_label=?, grade_level=?, label=?, name=?, template_id=?, student_wizard_display=?, is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
          ->execute([$schoolYear, $periodLabel, $gradeLevel, $label, $name, $templateId, $wizardDisplay, $isActive, $isActive, $classId]);

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
        'template_id'=>$templateId,
        'student_wizard_display'=>$wizardDisplay,
        'period_label'=>$periodLabel
      ]);
      $ok = t('admin.classes.ok.updated');
    }

    elseif ($action === 'toggle_active') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException(t('admin.classes.error.class_id_missing'));
      $st = $pdo->prepare("SELECT is_active FROM classes WHERE id=?");
      $st->execute([$classId]);
      $row = $st->fetch();
      if (!$row) throw new RuntimeException(t('admin.classes.error.class_not_found'));
      $new = ((int)$row['is_active']===1) ? 0 : 1;
      $pdo->prepare("UPDATE classes SET is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
          ->execute([$new, $new, $classId]);
      audit('admin_class_toggle_active', $userId, ['class_id'=>$classId,'is_active'=>$new]);
      $ok = $new ? t('admin.classes.ok.activated') : t('admin.classes.ok.deactivated');
    }

    elseif ($action === 'delete_class') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException(t('admin.classes.error.class_id_missing'));

      $confirm = (string)($_POST['confirm_text'] ?? '');
      $must = (string)($_POST['must_match'] ?? '');
      if ($confirm === '' || $must === '' || $confirm !== $must) {
        throw new RuntimeException(t('admin.classes.error.confirm_failed'));
      }

      $stats = delete_class_with_all_data($pdo, $classId);
      audit('admin_class_delete', $userId, ['class_id'=>$classId] + $stats);
      $ok = str_replace(
        '{count}',
        (string)$stats['students_deleted'],
        t('admin.classes.ok.deleted')
      );
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

render_admin_header(t('admin.classes.title'));
?>

<style>
    #editClass {
        scroll-margin-top: 250px;
      }
</style>

<div class="card">
  <h1><?=h(t('admin.classes.heading'))?></h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.classes.create_heading'))?></h2>

  <div class="grid" style="grid-template-columns: 1fr; gap:14px;">
    <div class="panel" style="border-bottom: solid lightgray; padding-bottom: 20px;">
      <h3 style="margin-top:0;"><?=h(t('admin.classes.create_single_heading'))?></h3>
      <form method="post" id="createClassSingle" class="grid" style="grid-template-columns: 1fr 120px 120px 160px 1fr 1fr 1fr; gap:12px;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create_single">

        <div>
          <label><?=h(t('admin.classes.school_year_label'))?></label>
          <input name="school_year" type="text" value="<?=h($defaultSchoolYear)?>" placeholder="<?=h(t('admin.classes.school_year_placeholder'))?>" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.grade_label'))?></label>
          <input name="grade_level" type="number" min="1" max="13" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.label_label'))?></label>
          <input name="label" type="text" placeholder="<?=h(t('admin.classes.label_placeholder'))?>" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.period_label', 'Halbjahr'))?></label>
          <?php $periodOptions = period_label_options(); ?>
          <select name="period_label">
            <?php foreach ($periodOptions as $val => $lbl): ?>
              <option value="<?=h($val)?>"><?=h($lbl)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label><?=h(t('admin.classes.template_label'))?></label>
          <select name="template_id">
            <option value="0"><?=h(t('admin.classes.template_none_option'))?></option>
            <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
              <option value="<?=h((string)$tid)?>" <?=($inactive ? 'disabled' : '')?>>
                <?=h((string)$tpl['name'])?>
                <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
                <?=($inactive ? ' – ' . h(t('admin.classes.template_inactive_badge')) : '')?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted"><?=h(t('admin.classes.template_empty_hint'))?></div>
        </div>

        <!-- NEW: wizard display selection -->
        <div>
          <label><?=h(t('admin.classes.wizard_label'))?></label>
          <select name="student_wizard_display">
            <option value="groups" selected><?=h(t('teacher.classes.wizard.groups'))?></option>
            <option value="items"><?=h(t('teacher.classes.wizard.items'))?></option>
            <option value="beginner"><?=h(t('teacher.classes.wizard.beginner'))?></option>
          </select>
          <div class="muted"><?=h(t('admin.classes.wizard_class_hint'))?></div>
        </div>

        <div>
          <label><?=h(t('admin.classes.teachers_label'))?></label>
          <select name="teacher_ids[]" multiple size="4">
            <?php foreach ($teachers as $t): ?>
              <option value="<?=h((string)$t['id'])?>"><?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="muted"><?=h(t('admin.classes.teachers_hint'))?></div>
        </div>

        <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
          <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h(t('admin.classes.create_button'))?></a>
        </div>
      </form>
    </div>

    <div class="panel">
      <h3 style="margin-top:0;"><?=h(t('admin.classes.create_bulk_heading'))?></h3>
      <form id="createClassBulk" method="post" class="grid" style="grid-template-columns: 1fr 160px 160px 1fr 160px 1fr 1fr; gap:12px;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create_bulk">

        <div>
          <label><?=h(t('admin.classes.school_year_label'))?></label>
          <input name="school_year" type="text" value="<?=h($defaultSchoolYear)?>" placeholder="<?=h(t('admin.classes.school_year_placeholder'))?>" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.grade_from_label'))?></label>
          <input name="grade_from" type="number" min="1" max="13" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.grade_to_label'))?></label>
          <input name="grade_to" type="number" min="1" max="13" required>
        </div>
        <div>
          <label><?=h(t('admin.classes.labels_label'))?></label>
          <input name="labels" type="text" placeholder="<?=h(t('admin.classes.labels_placeholder'))?>" required>
          <div class="muted" style="margin-top:6px;"><?=h(t('admin.classes.labels_examples'))?>: <code>a-b</code>, <code>a,b</code></div>
        </div>
        <div>
          <label><?=h(t('admin.classes.period_label', 'Halbjahr'))?></label>
          <?php $periodOptions = period_label_options(); ?>
          <select name="period_label">
            <?php foreach ($periodOptions as $val => $lbl): ?>
              <option value="<?=h($val)?>"><?=h($lbl)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label><?=h(t('admin.classes.template_label'))?></label>
          <select name="template_id">
            <option value="0"><?=h(t('admin.classes.template_none_option'))?></option>
            <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
              <option value="<?=h((string)$tid)?>" <?=($inactive ? 'disabled' : '')?>>
                <?=h((string)$tpl['name'])?>
                <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
                <?=($inactive ? ' – ' . h(t('admin.classes.template_inactive_badge')) : '')?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted"><?=h(t('admin.classes.template_bulk_hint'))?></div>
        </div>

        <!-- NEW: wizard display selection -->
        <div>
          <label><?=h(t('admin.classes.wizard_label'))?></label>
          <select name="student_wizard_display">
            <option value="groups" selected><?=h(t('teacher.classes.wizard.groups'))?></option>
            <option value="items"><?=h(t('teacher.classes.wizard.items'))?></option>
            <option value="beginner"><?=h(t('teacher.classes.wizard.beginner'))?></option>
          </select>
          <div class="muted"><?=h(t('admin.classes.wizard_bulk_hint'))?></div>
        </div>

        <div style="grid-column:1/-1;">
          <label><?=h(t('admin.classes.teachers_bulk_label'))?></label>
          <select name="teacher_ids[]" multiple size="4">
            <?php foreach ($teachers as $t): ?>
              <option value="<?=h((string)$t['id'])?>"><?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="muted"><?=h(t('admin.classes.teachers_hint'))?></div>
        </div>

        <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
          <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h(t('admin.classes.create_bulk_button'))?></a>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.classes.list_heading'))?></h2>

  <div class="actions" style="justify-content:flex-start;">
    <?php if ($showInactive): ?>
      <a class="btn secondary" href="<?=h(url('admin/classes.php'))?>"><?=h(t('admin.classes.hide_inactive'))?></a>
    <?php else: ?>
      <a class="btn secondary" href="<?=h(url('admin/classes.php?show_inactive=1'))?>"><?=h(t('admin.classes.show_inactive'))?></a>
    <?php endif; ?>
  </div>

  <?php if (!$grouped): ?>
    <div class="alert"><?=h(t('admin.classes.none'))?></div>
  <?php else: ?>
    <?php foreach ($grouped as $year => $items): ?>
      <details open style="margin-bottom:10px;">
        <summary style="cursor:pointer; font-weight:700; padding:10px 0;">
          <?=h($year)?> (<?=count($items)?>)
        </summary>

        <table class="table">
          <thead>
            <tr>
              <th><?=h(t('admin.classes.table.class'))?></th>
              <th><?=h(t('admin.classes.table.template'))?></th>
              <th><?=h(t('admin.classes.period_label', 'Halbjahr'))?></th>
              <th><?=h(t('admin.classes.table.teachers'))?></th>
              <th><?=h(t('admin.classes.table.students'))?></th>
              <th><?=h(t('admin.classes.table.wizard'))?></th>
              <th><?=h(t('admin.classes.table.status'))?></th>
              <th><?=h(t('admin.classes.table.actions'))?></th>
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
                    if ($tplAct !== 1) echo ' <span class="badge">' . h(t('admin.classes.template_inactive_badge')) . '</span>';
                  }
                ?>
              </td>
              <td><?=h(period_label_options()[normalize_period_label((string)($c['period_label'] ?? 'Standard'))] ?? (string)($c['period_label'] ?? ''))?></td>
              <td><?=h((string)($c['teacher_names'] ?? '—'))?></td>
              <td><?=h((string)$c['student_count'])?></td>
              <td>
                <?php
                  $wiz = normalize_wizard_display((string)($c['student_wizard_display'] ?? 'groups'));
                  echo $wiz === 'items'
                    ? '<span class="badge">' . h(t('teacher.classes.wizard.items')) . '</span>'
                    : '<span class="badge">' . h(t('teacher.classes.wizard.groups')) . '</span>';
                ?>
              </td>
              <td><?=((int)$c['is_active']===1) ? '<span class="badge">' . h(t('teacher.classes.status.active')) . '</span>' : '<span class="badge">' . h(t('teacher.classes.status.inactive')) . '</span>'?></td>
              <td>
                <div class="action-menu">
                  <button class="btn secondary action-menu-toggle" type="button" aria-haspopup="menu" aria-expanded="false">
                    <?=h(t('admin.classes.table.actions'))?> <span aria-hidden="true">▾</span>
                  </button>
                  <template class="action-menu-template">
                    <a class="btn primary" href="<?=h(url('admin/classes.php?edit='.(int)$c['id']))?>#editClass"><?=h(t('admin.classes.action.edit'))?></a>
                    <a class="btn primary" href="<?=h(url('teacher/students.php?class_id='.(int)$c['id']))?>"><?=h(t('admin.classes.action.students'))?></a>
                    <a class="btn secondary" href="<?=h(url('admin/export.php?class_id='.(int)$c['id']))?>"><?=h(t('admin.classes.action.export'))?></a>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                      <a class="btn secondary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h((int)$c['is_active']===1 ? t('admin.classes.action.deactivate') : t('admin.classes.action.activate'))?></a>
                    </form>
                  </template>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div id="rowActionMenu" class="action-dropdown-menu hidden" role="menu" aria-hidden="true"></div>

<?php if ($editClass): ?>
  <div class="card" id="editClass">
    <h2 style="margin-top:0;"><?=h(str_replace('{class}', (string)$editClass['school_year'] . ' · ' . class_display($editClass), t('admin.classes.edit_heading')))?></h2>

    <form id="classEditForm" method="post" class="grid" style="grid-template-columns: 1fr 120px 120px 160px 1fr 1fr 1fr 1fr; gap:12px;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="update_class">
      <input type="hidden" name="class_id" value="<?=h((string)$editClass['id'])?>">

      <div>
        <label><?=h(t('admin.classes.school_year_label'))?></label>
        <input name="school_year" type="text" value="<?=h((string)$editClass['school_year'])?>" required>
      </div>
      <div>
        <label><?=h(t('admin.classes.grade_label'))?></label>
        <input name="grade_level" type="number" min="1" max="13" value="<?=h((string)($editClass['grade_level'] ?? ''))?>" required>
      </div>
      <div>
        <label><?=h(t('admin.classes.label_label'))?></label>
        <input name="label" type="text" value="<?=h((string)($editClass['label'] ?? ''))?>" required>
      </div>
      <div>
        <label><?=h(t('admin.classes.period_label', 'Halbjahr'))?></label>
        <?php $curPeriod = normalize_period_label((string)($editClass['period_label'] ?? 'Standard')); ?>
        <?php $periodOptions = period_label_options(); ?>
        <select name="period_label">
          <?php foreach ($periodOptions as $val => $lbl): ?>
            <option value="<?=h($val)?>" <?=$curPeriod===$val?'selected':''?>><?=h($lbl)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label><?=h(t('admin.classes.status_label'))?></label>
        <select name="is_active">
          <option value="1" <?=((int)($editClass['is_active'] ?? 1)===1)?'selected':''?>><?=h(t('teacher.classes.status.active'))?></option>
          <option value="0" <?=((int)($editClass['is_active'] ?? 1)===0)?'selected':''?>><?=h(t('teacher.classes.status.inactive'))?></option>
        </select>
      </div>

      <div>
        <label><?=h(t('admin.classes.template_label'))?></label>
        <?php $curTplId = (int)($editClass['template_id'] ?? 0); ?>
        <select name="template_id">
          <option value="0"><?=h(t('admin.classes.template_none_option'))?></option>
          <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
            <option value="<?=h((string)$tid)?>"
              <?=($tid===$curTplId ? 'selected' : '')?>
              <?=($inactive && $tid!==$curTplId ? 'disabled' : '')?>>
              <?=h((string)$tpl['name'])?>
              <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
              <?=($inactive ? ' – ' . h(t('admin.classes.template_inactive_badge')) : '')?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="muted"><?=h(t('admin.classes.template_edit_hint'))?></div>
      </div>

      <!-- NEW: wizard display in edit -->
      <div>
        <label><?=h(t('admin.classes.wizard_label'))?></label>
        <?php $curWiz = normalize_wizard_display((string)($editClass['student_wizard_display'] ?? 'groups')); ?>
        <select name="student_wizard_display">
          <option value="groups" <?=$curWiz==='groups'?'selected':''?>><?=h(t('teacher.classes.wizard.groups'))?></option>
          <option value="items" <?=$curWiz==='items'?'selected':''?>><?=h(t('teacher.classes.wizard.items'))?></option>
          <option value="beginner" <?=$curWiz==='beginner'?'selected':''?>><?=h(t('teacher.classes.wizard.beginner'))?></option>
        </select>
        <div class="muted"><?=h(t('admin.classes.wizard_edit_hint'))?> <code>classes.student_wizard_display</code></div>
      </div>

      <div>
        <label><?=h(t('admin.classes.teachers_label'))?></label>
        <select name="teacher_ids[]" multiple size="6">
          <?php foreach ($teachers as $t): $tid=(int)$t['id']; ?>
            <option value="<?=h((string)$tid)?>" <?=in_array($tid, $editTeacherIds, true) ? 'selected' : ''?>>
              <?=h((string)$t['display_name'])?> (<?=h((string)$t['email'])?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
        <a class="btn secondary" href="<?=h(url('admin/classes.php'))?>"><?=h(t('admin.classes.action.cancel'))?></a>
        <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h(t('admin.classes.action.save'))?></a>
      </div>
    </form>

    <hr style="margin:18px 0; border:none; border-top:1px solid var(--border);">

    <h3 style="margin:0 0 8px 0;"><?=h(t('admin.classes.delete_heading'))?></h3>
    <div class="alert danger">
      <strong><?=h(t('admin.classes.delete_warning_title'))?></strong> <?=h(t('admin.classes.delete_warning_text'))?>
    </div>

    <?php $must = (string)$editClass['school_year'] . ' ' . class_display($editClass); ?>

    <form method="post" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items: end;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="delete_class">
      <input type="hidden" name="class_id" value="<?=h((string)$editClass['id'])?>">
      <input type="hidden" name="must_match" value="<?=h($must)?>">

      <div class="muted" style="grid-column:1/-1;"><?=h(t('admin.classes.delete_confirm_hint'))?> <code><?=h($must)?></code></div>

      <div>
        <label><?=h(t('admin.classes.delete_confirm_label'))?></label>
        <input name="confirm_text" type="text" placeholder="<?=h($must)?>" required>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <button class="btn danger" type="submit"><?=h(t('admin.classes.delete_confirm_button'))?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const menu = document.getElementById('rowActionMenu');
  if (!menu) return;

  let currentButton = null;

  const closeMenu = () => {
    if (!currentButton) return;
    currentButton.setAttribute('aria-expanded', 'false');
    currentButton = null;
    menu.classList.add('hidden');
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    menu.innerHTML = '';
  };

  const positionMenu = () => {
    if (!currentButton) return;
    const rect = currentButton.getBoundingClientRect();
    const scrollX = window.scrollX || window.pageXOffset;
    const scrollY = window.scrollY || window.pageYOffset;
    const maxLeft = scrollX + document.documentElement.clientWidth - menu.offsetWidth - 8;
    const left = Math.max(scrollX + 8, Math.min(rect.right + scrollX - menu.offsetWidth, maxLeft));
    menu.style.left = `${left}px`;
    menu.style.top = `${rect.bottom + scrollY}px`;
  };

  const openMenu = (button, template) => {
    if (currentButton === button) {
      closeMenu();
      return;
    }
    if (currentButton) {
      currentButton.setAttribute('aria-expanded', 'false');
    }
    menu.innerHTML = '';
    if (template && template.content) {
      menu.appendChild(template.content.cloneNode(true));
    }
    menu.classList.remove('hidden');
    menu.classList.add('open');
    menu.setAttribute('aria-hidden', 'false');
    button.setAttribute('aria-expanded', 'true');
    currentButton = button;
    positionMenu();
  };

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.action-menu-toggle');
    if (button) {
      event.preventDefault();
      const wrapper = button.closest('.action-menu');
      const template = wrapper ? wrapper.querySelector('.action-menu-template') : null;
      openMenu(button, template);
      return;
    }
    if (menu.contains(event.target)) return;
    closeMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);

  document.addEventListener('mousedown', function (e) {
    const option = e.target;

    if (
      option.tagName === 'OPTION' &&
      option.parentElement &&
      option.parentElement.matches("select[multiple]")
    ) {
      e.preventDefault();
      option.selected = !option.selected;
      option.parentElement.focus();
    }
  });
});
</script>

<?php render_admin_footer(); ?>
