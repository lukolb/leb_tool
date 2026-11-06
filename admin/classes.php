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
function ensure_school_period(PDO $pdo, string $schoolYear, string $periodLabel, int $createdByUserId = 0): bool {
  $schoolYear = normalize_school_year($schoolYear);
  $periodLabel = normalize_period_label($periodLabel);
  if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));

  $st = $pdo->prepare(
    "INSERT IGNORE INTO school_periods (school_year, period_label, is_active, created_by_user_id)
     VALUES (?, ?, 0, ?)"
  );
  $st->execute([$schoolYear, $periodLabel, $createdByUserId > 0 ? $createdByUserId : null]);
  $created = $st->rowCount() > 0;

  $hasActivePeriod = (int)$pdo->query("SELECT COUNT(*) FROM school_periods WHERE is_active=1")->fetchColumn() > 0;
  if (!$hasActivePeriod) {
    $pdo->prepare("UPDATE school_periods SET is_active=1 WHERE school_year=? AND period_label=?")
      ->execute([$schoolYear, $periodLabel]);
  }
  return $created;
}
function mark_school_period_active(PDO $pdo, string $schoolYear, string $periodLabel, int $createdByUserId = 0): void {
  ensure_school_period($pdo, $schoolYear, $periodLabel, $createdByUserId);
  $pdo->prepare(
    "UPDATE school_periods
     SET is_active = CASE WHEN school_year=? AND period_label=? THEN 1 ELSE 0 END"
  )->execute([$schoolYear, $periodLabel]);
}
function school_period_dependency_counts(PDO $pdo, string $schoolYear, string $periodLabel): array {
  $schoolYear = normalize_school_year($schoolYear);
  $periodLabel = normalize_period_label($periodLabel);
  $counts = [];

  $directChecks = [
    'classes' => 'classes',
    'reports' => 'report_instances',
    'deadlines' => 'submission_deadlines',
    'absences' => 'student_period_absences',
    'ag_terms' => 'ag_catalog_semester',
    'ag_assignments' => 'student_ag_assignments',
    'delegations' => 'class_group_delegations',
    'student_unlocks' => 'class_child_group_unlocks',
    'legacy_ag_entries' => 'ag_catalog',
  ];
  foreach ($directChecks as $key => $table) {
    if (!db_has_table($pdo, $table) || !db_has_column($pdo, $table, 'school_year') || !db_has_column($pdo, $table, 'period_label')) {
      $counts[$key] = 0;
      continue;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE school_year=? AND period_label=?");
    $st->execute([$schoolYear, $periodLabel]);
    $counts[$key] = (int)$st->fetchColumn();
  }

  $counts['parent_feedback'] = 0;
  if (
    db_has_table($pdo, 'parent_meeting_feedback') &&
    db_has_column($pdo, 'parent_meeting_feedback', 'school_year') &&
    db_has_column($pdo, 'parent_meeting_feedback', 'class_id')
  ) {
    $st = $pdo->prepare(
      "SELECT COUNT(*)
       FROM parent_meeting_feedback pmf
       INNER JOIN classes c ON c.id=pmf.class_id
       WHERE pmf.school_year=? AND c.school_year=? AND c.period_label=?"
    );
    $st->execute([$schoolYear, $schoolYear, $periodLabel]);
    $counts['parent_feedback'] = (int)$st->fetchColumn();
  }

  return $counts;
}
function school_period_dependency_labels(): array {
  return [
    'classes' => t('admin.classes.period_delete.dep.classes', 'Klassen'),
    'reports' => t('admin.classes.period_delete.dep.reports', 'Berichte'),
    'deadlines' => t('admin.classes.period_delete.dep.deadlines', 'Fristen'),
    'absences' => t('admin.classes.period_delete.dep.absences', 'Fehlzeiten'),
    'ag_terms' => t('admin.classes.period_delete.dep.ag_terms', 'AG-Aktivierungen'),
    'ag_assignments' => t('admin.classes.period_delete.dep.ag_assignments', 'AG-Zuordnungen'),
    'delegations' => t('admin.classes.period_delete.dep.delegations', 'Delegationen'),
    'student_unlocks' => t('admin.classes.period_delete.dep.student_unlocks', 'Schülerfreigaben'),
    'legacy_ag_entries' => t('admin.classes.period_delete.dep.legacy_ag_entries', 'Ältere AG-Einträge'),
    'parent_feedback' => t('admin.classes.period_delete.dep.parent_feedback', 'Eltern-Feedbacks'),
  ];
}
function school_period_blockers(array $counts): array {
  return array_filter($counts, static fn($count): bool => (int)$count > 0);
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
function ensure_master_id(PDO $pdo, int $studentId): int {
  if ($studentId <= 0) return 0;
  $q = $pdo->prepare("SELECT id, master_student_id FROM students WHERE id=? LIMIT 1");
  $q->execute([$studentId]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) return $studentId;

  $master = $row['master_student_id'] !== null ? (int)$row['master_student_id'] : 0;
  if ($master > 0) return $master;

  $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?")->execute([$studentId, $studentId]);
  return $studentId;
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
  $st = $pdo->prepare("SELECT student_id FROM student_class_assignments WHERE class_id=?");
  $st->execute([$classId]);
  $studentIds = array_map(fn($r)=>(int)$r['student_id'], $st->fetchAll(PDO::FETCH_ASSOC));

  $pdo->beginTransaction();

  // Remove class relationships, never the central student profiles or their
  // historical reports. A student may belong to additional classes.
  $pdo->prepare("DELETE FROM user_class_assignments WHERE class_id=?")->execute([$classId]);
  $pdo->prepare("DELETE FROM student_class_assignments WHERE class_id=?")->execute([$classId]);
  $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([$classId]);

  $pdo->commit();

  return ['students_deleted' => 0, 'students_unassigned' => count($studentIds)];
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
    if ($action === 'create_period') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));

      $created = ensure_school_period($pdo, $schoolYear, $periodLabel, $userId);
      audit('admin_school_period_create', $userId, [
        'school_year' => $schoolYear,
        'period_label' => $periodLabel,
        'created' => $created,
      ]);
      $ok = $created
        ? strtr(t('admin.classes.period_create.ok', 'Schulhalbjahr angelegt: {year} · {period}.'), [
            '{year}' => $schoolYear,
            '{period}' => period_label_options()[$periodLabel] ?? $periodLabel,
          ])
        : t('admin.classes.period_create.exists', 'Dieses Schulhalbjahr ist bereits angelegt.');
    }

    elseif ($action === 'delete_period') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      $confirmation = trim((string)($_POST['confirm_text'] ?? ''));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      if ($confirmation !== 'DELETE') {
        throw new RuntimeException(t('admin.classes.period_delete.confirm_failed', 'Bitte zur Bestätigung exakt „DELETE“ eingeben.'));
      }

      $pdo->beginTransaction();
      $stPeriod = $pdo->prepare(
        "SELECT id, is_active
         FROM school_periods
         WHERE school_year=? AND period_label=?
         LIMIT 1
         FOR UPDATE"
      );
      $stPeriod->execute([$schoolYear, $periodLabel]);
      $periodRow = $stPeriod->fetch(PDO::FETCH_ASSOC);
      if (!$periodRow) {
        throw new RuntimeException(t('admin.classes.period_delete.not_found', 'Schulhalbjahr nicht gefunden.'));
      }
      if ((int)($periodRow['is_active'] ?? 0) === 1) {
        throw new RuntimeException(t('admin.classes.period_delete.active_blocked', 'Das aktive Schulhalbjahr kann nicht gelöscht werden.'));
      }

      $dependencyCounts = school_period_dependency_counts($pdo, $schoolYear, $periodLabel);
      $blockers = school_period_blockers($dependencyCounts);
      if ($blockers) {
        $labels = school_period_dependency_labels();
        $details = [];
        foreach ($blockers as $key => $count) {
          $details[] = ($labels[$key] ?? (string)$key) . ': ' . (int)$count;
        }
        throw new RuntimeException(str_replace(
          '{details}',
          implode(', ', $details),
          t('admin.classes.period_delete.dependencies_blocked', 'Löschen blockiert, weil noch Daten vorhanden sind: {details}.')
        ));
      }

      $stDelete = $pdo->prepare("DELETE FROM school_periods WHERE id=? AND is_active=0");
      $stDelete->execute([(int)$periodRow['id']]);
      if ($stDelete->rowCount() !== 1) {
        throw new RuntimeException(t('admin.classes.period_delete.failed', 'Das Schulhalbjahr konnte nicht sicher gelöscht werden.'));
      }
      $pdo->commit();

      audit('admin_school_period_delete', $userId, [
        'school_year' => $schoolYear,
        'period_label' => $periodLabel,
        'dependency_counts' => $dependencyCounts,
      ]);
      $ok = strtr(t('admin.classes.period_delete.ok', 'Leeres Schulhalbjahr entfernt: {year} · {period}.'), [
        '{year}' => $schoolYear,
        '{period}' => period_label_options()[$periodLabel] ?? $periodLabel,
      ]);
    }

    elseif ($action === 'create_single') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $gradeLevel = (int)($_POST['grade_level'] ?? 0);
      $label = normalize_label((string)($_POST['label'] ?? ''));
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      if ($gradeLevel <= 0) throw new RuntimeException(t('admin.classes.error.grade_missing'));
      if ($label === '') throw new RuntimeException(t('admin.classes.error.label_missing'));
      ensure_school_period($pdo, $schoolYear, $periodLabel, $userId);

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
      ensure_school_period($pdo, $schoolYear, $periodLabel, $userId);

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
          $q = $pdo->prepare("SELECT id FROM classes WHERE school_year=? AND period_label=? AND grade_level=? AND label=? LIMIT 1");
          $q->execute([$schoolYear, $periodLabel, $g, $lab]);
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

    elseif ($action === 'switch_period') {
      $sourceSchoolYear = normalize_school_year((string)($_POST['source_school_year'] ?? ''));
      $targetSchoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $targetPeriod = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      $selectedClassIds = $_POST['class_ids'] ?? [];
      if (!is_array($selectedClassIds)) $selectedClassIds = [];
      $selectedClassIds = array_values(array_unique(array_filter(
        array_map('intval', $selectedClassIds),
        static fn(int $id): bool => $id > 0
      )));
      $templateOverrideRaw = (int)($_POST['template_id'] ?? 0);
      $templateOverride = $templateOverrideRaw > 0 ? assert_template_selectable($pdo, $templateOverrideRaw, null) : null;

      if ($sourceSchoolYear === '' || $targetSchoolYear === '') {
        throw new RuntimeException(t('admin.classes.error.school_year_missing'));
      }
      if (!$selectedClassIds) {
        throw new RuntimeException(t('admin.classes.switch.selection_required', 'Bitte mindestens eine Quellklasse auswählen.'));
      }

      $pdo->beginTransaction();
      ensure_school_period($pdo, $targetSchoolYear, $targetPeriod, $userId);

      $classPlaceholders = implode(',', array_fill(0, count($selectedClassIds), '?'));
      $stClasses = $pdo->prepare(
        "SELECT id, school_year, period_label, grade_level, label, name, template_id, student_wizard_display, student_intro_html, tts_enabled
         FROM classes
         WHERE school_year=?
           AND NOT (school_year=? AND period_label=?)
           AND id IN ($classPlaceholders)
         FOR UPDATE"
      );
      $stClasses->execute(array_merge([$sourceSchoolYear, $targetSchoolYear, $targetPeriod], $selectedClassIds));
      $classesToSwitch = $stClasses->fetchAll(PDO::FETCH_ASSOC);
      if (count($classesToSwitch) !== count($selectedClassIds)) {
        throw new RuntimeException(t('admin.classes.switch.selection_invalid', 'Mindestens eine ausgewählte Quellklasse ist ungültig oder gehört bereits zum Zielhalbjahr.'));
      }

      $created = 0;
      $updated = 0;
      $archived = 0;
      $studentsCopied = 0;
      $teachersCopied = 0;
      $gradeIncrement = $sourceSchoolYear !== $targetSchoolYear ? 1 : 0;

      foreach ($classesToSwitch as $c) {
        $sourceGradeLevel = (int)($c['grade_level'] ?? 0);
        $targetGradeLevel = $sourceGradeLevel > 0 ? $sourceGradeLevel + $gradeIncrement : $sourceGradeLevel;
        $classLabel = (string)($c['label'] ?? '');
        $targetName = ($targetGradeLevel > 0 && $classLabel !== '')
          ? computed_name($targetGradeLevel, $classLabel)
          : (string)($c['name'] ?? '');

        $stExisting = $pdo->prepare(
          "SELECT id, template_id
           FROM classes
           WHERE school_year=? AND period_label=? AND grade_level=? AND label=?
           LIMIT 1"
        );
        $stExisting->execute([
          $targetSchoolYear,
          $targetPeriod,
          $targetGradeLevel,
          $classLabel,
        ]);
        $target = $stExisting->fetch(PDO::FETCH_ASSOC);

        $targetClassId = 0;
        $newTemplateId = $templateOverride ?? (int)($c['template_id'] ?? 0);

        if ($target) {
          $targetClassId = (int)$target['id'];
          if ($templateOverride !== null) {
            $pdo->prepare("UPDATE classes SET template_id=? WHERE id=?")->execute([$newTemplateId, $targetClassId]);
          }
          $pdo->prepare("UPDATE classes SET is_active=1, inactive_at=NULL WHERE id=?")->execute([$targetClassId]);
          $updated++;
        } else {
          $pdo->prepare(
            "INSERT INTO classes (school_year, period_label, grade_level, label, name, template_id, student_wizard_display, student_intro_html, tts_enabled, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
          )->execute([
            $targetSchoolYear,
            $targetPeriod,
            $targetGradeLevel,
            $classLabel,
            $targetName,
            $newTemplateId > 0 ? $newTemplateId : null,
            (string)($c['student_wizard_display'] ?? 'groups'),
            $c['student_intro_html'] ?? null,
            (int)($c['tts_enabled'] ?? 0),
          ]);
          $targetClassId = (int)$pdo->lastInsertId();
          $created++;
        }

        // Copy teacher assignments
        $stTeachers = $pdo->prepare("SELECT user_id FROM user_class_assignments WHERE class_id=?");
        $stTeachers->execute([(int)$c['id']]);
        $teacherIds = array_map(fn($r)=>(int)$r['user_id'], $stTeachers->fetchAll(PDO::FETCH_ASSOC));
        foreach ($teacherIds as $tid) {
          if ($tid <= 0) continue;
          $insertTeacher = $pdo->prepare("INSERT IGNORE INTO user_class_assignments (user_id, class_id) VALUES (?, ?)");
          $insertTeacher->execute([$tid, $targetClassId]);
          if ($insertTeacher->rowCount() > 0) $teachersCopied++;
        }

        // Link the existing canonical students to the target class. No student
        // profile is copied or created during a term transfer.
        $stStudents = $pdo->prepare(
          "SELECT student_id, is_active
           FROM student_class_assignments
           WHERE class_id=?"
        );
        $stStudents->execute([(int)$c['id']]);
        $students = $stStudents->fetchAll(PDO::FETCH_ASSOC);

        $insAssignment = $pdo->prepare(
          "INSERT INTO student_class_assignments (student_id, class_id, is_active)
           VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), updated_at=CURRENT_TIMESTAMP"
        );

        foreach ($students as $s) {
          $sid = (int)($s['student_id'] ?? 0);
          if ($sid <= 0) continue;
          $insAssignment->execute([$sid, $targetClassId, (int)($s['is_active'] ?? 1)]);
          if ($insAssignment->rowCount() > 0) $studentsCopied++;
        }

        $pdo->prepare("UPDATE classes SET is_active=0, inactive_at=NOW() WHERE id=?")->execute([(int)$c['id']]);
        $archived++;
      }

      mark_school_period_active($pdo, $targetSchoolYear, $targetPeriod, $userId);

      $pdo->commit();

      audit('admin_classes_switch_period', $userId, [
        'source_school_year' => $sourceSchoolYear,
        'target_school_year' => $targetSchoolYear,
        'target_period' => $targetPeriod,
        'grade_increment' => $gradeIncrement,
        'source_class_ids' => $selectedClassIds,
        'selected' => count($selectedClassIds),
        'template_id' => $templateOverride,
        'created' => $created,
        'updated' => $updated,
        'archived' => $archived,
        'students_copied' => $studentsCopied,
        'teachers_copied' => $teachersCopied,
      ]);

      $ok = strtr(t('admin.classes.switch.ok', 'Übertragung abgeschlossen: {selected} ausgewählt, {created} neu, {updated} aktualisiert, {archived} archiviert.'), [
        '{selected}' => (string)count($selectedClassIds),
        '{created}' => (string)$created,
        '{updated}' => (string)$updated,
        '{archived}' => (string)$archived,
      ]);
    }

    elseif ($action === 'set_active_period') {
      $schoolYear = normalize_school_year((string)($_POST['school_year'] ?? ''));
      $periodLabel = normalize_period_label((string)($_POST['period_label'] ?? 'Standard'));
      if ($schoolYear === '') throw new RuntimeException(t('admin.classes.error.school_year_missing'));

      $pdo->beginTransaction();
      mark_school_period_active($pdo, $schoolYear, $periodLabel, $userId);
      $pdo->prepare(
        "UPDATE classes
         SET is_active = CASE WHEN school_year=? AND period_label=? THEN 1 ELSE 0 END,
             inactive_at = CASE WHEN school_year=? AND period_label=? THEN NULL ELSE COALESCE(inactive_at, NOW()) END"
      )->execute([$schoolYear, $periodLabel, $schoolYear, $periodLabel]);

      $stCount = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_year=? AND period_label=?");
      $stCount->execute([$schoolYear, $periodLabel]);
      $targetCount = (int)$stCount->fetchColumn();
      $pdo->commit();

      audit('admin_classes_set_active_period', $userId, [
        'school_year' => $schoolYear,
        'period_label' => $periodLabel,
        'active_classes' => $targetCount,
      ]);

      $ok = strtr(
        t('admin.classes.ok.active_period_set', 'Aktives Schuljahr/Halbjahr gesetzt: {year} · {period} ({count} Klassen aktiv).'),
        [
          '{year}' => $schoolYear,
          '{period}' => period_label_options()[$periodLabel] ?? $periodLabel,
          '{count}' => (string)$targetCount,
        ]
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
      ensure_school_period($pdo, $schoolYear, $periodLabel, $userId);

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
        (string)$stats['students_unassigned'],
        t('admin.classes.ok.deleted')
      );
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$years = $pdo->query("SELECT school_year FROM school_periods GROUP BY school_year ORDER BY school_year DESC")
  ->fetchAll(PDO::FETCH_COLUMN);

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
          (SELECT COUNT(*) FROM student_class_assignments sca WHERE sca.class_id=c.id) AS student_count,
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

// All source classes are eligible for selective transfer, including classes
// that are currently inactive. The target-period filter is applied in the UI
// and repeated authoritatively in the POST handler.
$switchableClasses = $pdo->query(
  "SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name, c.is_active,
          (SELECT COUNT(*) FROM student_class_assignments sca WHERE sca.class_id=c.id) AS student_count
   FROM classes c
   ORDER BY c.school_year DESC,
            CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC,
            c.grade_level DESC, c.label ASC, c.name ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sourceSchoolYears = [];
foreach ($switchableClasses as $switchableClass) {
  $year = trim((string)($switchableClass['school_year'] ?? ''));
  if ($year !== '' && !in_array($year, $sourceSchoolYears, true)) $sourceSchoolYears[] = $year;
}

$periodOverview = $pdo->query(
  "SELECT sp.school_year, sp.period_label, sp.is_active AS period_is_active,
          COUNT(c.id) AS total_count,
          SUM(CASE WHEN c.is_active=1 THEN 1 ELSE 0 END) AS active_count
   FROM school_periods sp
   LEFT JOIN classes c
     ON c.school_year=sp.school_year AND c.period_label=sp.period_label
   GROUP BY sp.id, sp.school_year, sp.period_label, sp.is_active
   ORDER BY sp.school_year ASC,
            CASE sp.period_label
              WHEN 'H2' THEN 2
              ELSE 1
            END ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$currentActiveKey = '';
foreach ($periodOverview as $row) {
  if ((int)($row['period_is_active'] ?? 0) === 1) {
    $currentActiveKey = trim((string)$row['school_year']) . '|' . normalize_period_label((string)($row['period_label'] ?? 'Standard'));
    break;
  }
}
if ($currentActiveKey === '' && $periodOverview) {
  $fallbackPeriod = $periodOverview[count($periodOverview) - 1];
  $currentActiveKey = trim((string)($fallbackPeriod['school_year'] ?? '')) . '|' .
    normalize_period_label((string)($fallbackPeriod['period_label'] ?? 'Standard'));
}
$periodDeletionChecks = [];
foreach ($periodOverview as $row) {
  $schoolYear = trim((string)($row['school_year'] ?? ''));
  $periodLabel = normalize_period_label((string)($row['period_label'] ?? 'Standard'));
  $key = $schoolYear . '|' . $periodLabel;
  $counts = school_period_dependency_counts($pdo, $schoolYear, $periodLabel);
  $periodDeletionChecks[$key] = [
    'counts' => $counts,
    'blockers' => school_period_blockers($counts),
  ];
}

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
  <h2 style="margin-top:0;"><?=h(t('admin.classes.period_create.heading', 'Schulhalbjahr anlegen'))?></h2>
  <p class="muted"><?=h(t('admin.classes.period_create.desc', 'Legt ein Schulhalbjahr unabhängig von Klassen an. Klassen können später einzeln erstellt oder aus einem anderen Halbjahr übertragen werden.'))?></p>

  <form method="post" class="row" style="gap:10px; align-items:flex-end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create_period">

    <div>
      <label><?=h(t('admin.classes.school_year_label'))?></label>
      <input name="school_year" type="text" value="<?=h($defaultSchoolYear)?>" placeholder="<?=h(t('admin.classes.school_year_placeholder'))?>" required>
    </div>
    <div>
      <label><?=h(t('admin.classes.period_label', 'Halbjahr'))?></label>
      <select name="period_label">
        <?php foreach (period_label_options() as $val => $lbl): ?>
          <option value="<?=h($val)?>"><?=h($lbl)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn primary" type="submit"><?=h(t('admin.classes.period_create.button', 'Schulhalbjahr anlegen'))?></button>
    </div>
  </form>
</div>

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
  <h2 style="margin-top:0;"><?=h(t('admin.classes.switch_heading', 'Klassen ins nächste Halbjahr übertragen'))?></h2>
  <p class="muted"><?=h(t('admin.classes.switch_desc', 'Überträgt nur die ausgewählten aktiven oder inaktiven Klassen mit ihren Schülern und Lehrkräften. Nicht ausgewählte Klassen bleiben unverändert.'))?></p>

  <form method="post" class="grid" id="selectivePeriodTransferForm" style="grid-template-columns: 1fr 1fr 160px 1fr; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="switch_period">

    <div>
      <label><?=h(t('admin.classes.switch_source_school_year', 'Quellschuljahr'))?></label>
      <select name="source_school_year" required>
        <option value=""><?=h(t('admin.classes.switch_school_year_placeholder', '— Schuljahr wählen —'))?></option>
        <?php foreach ($sourceSchoolYears as $y): ?>
          <option value="<?=h((string)$y)?>"><?=h((string)$y)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label><?=h(t('admin.classes.switch_target_school_year', 'Zielschuljahr'))?></label>
      <select name="school_year" required>
        <option value=""><?=h(t('admin.classes.switch_target_school_year_placeholder', '— Zielschuljahr wählen —'))?></option>
        <?php foreach ($years as $y): ?>
          <option value="<?=h((string)$y)?>"><?=h((string)$y)?></option>
        <?php endforeach; ?>
      </select>
      <div class="muted"><?=h(t('admin.classes.switch_target_school_year_hint', 'Bei einem anderen Zielschuljahr wird die Klassenstufe automatisch um eins erhöht.'))?></div>
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
      <label><?=h(t('admin.classes.switch_template_label', 'Vorlage für neues Halbjahr'))?></label>
      <select name="template_id">
        <option value="0"><?=h(t('admin.classes.switch_template_keep', 'Vorlage der bisherigen Klasse behalten'))?></option>
        <?php foreach ($templates as $tpl): $tid=(int)$tpl['id']; $inactive=((int)($tpl['is_active'] ?? 0)!==1); ?>
          <option value="<?=h((string)$tid)?>" <?=($inactive ? 'disabled' : '')?>>
            <?=h((string)$tpl['name'])?>
            <?=((int)$tpl['template_version']>0 ? ' (v'.h((string)$tpl['template_version']).')' : '')?>
            <?=($inactive ? ' – ' . h(t('admin.classes.template_inactive_badge')) : '')?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="muted"><?=h(t('admin.classes.switch_template_hint', 'Optional: kann später pro Klasse angepasst werden.'))?></div>
    </div>

    <fieldset style="grid-column:1/-1; border:1px solid var(--border); border-radius:10px; padding:12px;">
      <legend style="font-weight:700; padding:0 6px;"><?=h(t('admin.classes.switch.classes_label', 'Quellklassen auswählen'))?></legend>
      <p class="muted" style="margin-top:0;"><?=h(t('admin.classes.switch.classes_hint', 'Aktive und inaktive Klassen können übertragen werden. Klassen aus dem gewählten Zielhalbjahr werden nicht angeboten.'))?></p>

      <div class="actions" style="justify-content:flex-start; margin-bottom:10px;">
        <button class="btn secondary" type="button" id="selectAllTransferClasses"><?=h(t('admin.classes.switch.select_all', 'Alle sichtbaren auswählen'))?></button>
        <button class="btn secondary" type="button" id="clearTransferClasses"><?=h(t('admin.classes.switch.clear', 'Auswahl aufheben'))?></button>
      </div>

      <div id="transferClassChooseYear" class="muted"><?=h(t('admin.classes.switch.choose_year', 'Bitte zuerst ein Schuljahr auswählen.'))?></div>
      <div id="transferClassEmpty" class="muted" hidden><?=h(t('admin.classes.switch.no_classes', 'Für diese Auswahl sind keine Quellklassen vorhanden.'))?></div>
      <div id="transferClassList" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:8px; max-height:280px; overflow:auto;">
        <?php foreach ($switchableClasses as $sourceClass): ?>
          <?php
            $sourcePeriod = normalize_period_label((string)($sourceClass['period_label'] ?? 'Standard'));
            $sourceActive = (int)($sourceClass['is_active'] ?? 0) === 1;
          ?>
          <label class="transfer-class-option" data-school-year="<?=h((string)$sourceClass['school_year'])?>" data-period-label="<?=h($sourcePeriod)?>" hidden style="border:1px solid var(--border); border-radius:8px; padding:9px 10px; cursor:pointer;">
            <input type="checkbox" name="class_ids[]" value="<?=h((string)$sourceClass['id'])?>" disabled>
            <strong><?=h(class_display($sourceClass))?></strong>
            <span class="muted"> · <?=h(period_label_options()[$sourcePeriod] ?? $sourcePeriod)?></span>
            <span class="badge"><?=h($sourceActive ? t('teacher.classes.status.active', 'Aktiv') : t('teacher.classes.status.inactive', 'Inaktiv'))?></span>
            <span class="muted"> · <?=h((string)($sourceClass['student_count'] ?? 0))?> <?=h(t('admin.classes.table.students', 'Schüler'))?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="actions" style="grid-column:1/-1; justify-content:flex-start;">
      <button class="btn primary" type="submit"><?=h(t('admin.classes.switch_button', 'Ausgewählte Klassen übertragen'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.classes.active_period.heading', 'Aktives Schuljahr/Halbjahr'))?></h2>
  <p class="muted"><?=h(t('admin.classes.active_period.desc', 'Hier wählst du das aktuell aktive Schuljahr/Halbjahr. Klassen außerhalb der Auswahl werden inaktiv gesetzt.'))?></p>

  <form method="post" class="row" style="gap:10px; align-items:flex-end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="set_active_period">

    <div>
      <label><?=h(t('admin.classes.active_period.select', 'Schuljahr/Halbjahr'))?></label>
      <select name="active_key" class="input" onchange="(function(sel){ const v=String(sel.value||''); const p=v.split('|'); const f=sel.form; if (f){ f.school_year.value=p[0]||''; f.period_label.value=p[1]||'Standard'; } })(this)">
        <?php if (!$periodOverview): ?>
          <option value=""><?=h(t('admin.classes.active_period.none', '— Noch kein Schulhalbjahr angelegt —'))?></option>
        <?php endif; ?>
        <?php foreach ($periodOverview as $row):
          $sy = trim((string)($row['school_year'] ?? ''));
          $pl = normalize_period_label((string)($row['period_label'] ?? 'Standard'));
          $k = $sy . '|' . $pl;
          $lbl = ($sy !== '' ? $sy : '—') . ' · ' . (period_label_options()[$pl] ?? $pl);
          $counts = ' (' . (int)($row['active_count'] ?? 0) . '/' . (int)($row['total_count'] ?? 0) . ')';
        ?>
          <option value="<?=h($k)?>" <?= $k === $currentActiveKey ? 'selected' : '' ?>><?=h($lbl . $counts)?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="school_year" value="<?=h(explode('|', $currentActiveKey)[0] ?? '')?>">
      <input type="hidden" name="period_label" value="<?=h(explode('|', $currentActiveKey)[1] ?? 'Standard')?>">
    </div>

    <div class="actions" style="justify-content:flex-start;">
      <button class="btn primary" type="submit" <?=!$periodOverview ? 'disabled' : ''?>><?=h(t('admin.classes.active_period.apply', 'Als aktiv setzen'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.classes.period_delete.heading', 'Leere Schulhalbjahre entfernen'))?></h2>
  <p class="muted"><?=h(t('admin.classes.period_delete.desc', 'Ein Schulhalbjahr kann nur entfernt werden, wenn es nicht aktiv ist und keinerlei abhängige Daten enthält. Es werden niemals Klassen, Berichte oder andere Inhalte automatisch mitgelöscht.'))?></p>

  <?php if (!$periodOverview): ?>
    <div class="alert"><?=h(t('admin.classes.period_delete.none', 'Keine Schulhalbjahre vorhanden.'))?></div>
  <?php else: ?>
    <?php $dependencyLabels = school_period_dependency_labels(); ?>
    <table class="table">
      <thead>
        <tr>
          <th><?=h(t('admin.classes.active_period.select', 'Schuljahr/Halbjahr'))?></th>
          <th><?=h(t('admin.classes.period_delete.status', 'Schutzprüfung'))?></th>
          <th><?=h(t('admin.classes.period_delete.action', 'Entfernen'))?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($periodOverview as $row): ?>
        <?php
          $schoolYear = trim((string)($row['school_year'] ?? ''));
          $periodLabel = normalize_period_label((string)($row['period_label'] ?? 'Standard'));
          $periodKey = $schoolYear . '|' . $periodLabel;
          $isActivePeriod = (int)($row['period_is_active'] ?? 0) === 1;
          $blockers = $periodDeletionChecks[$periodKey]['blockers'] ?? [];
          $blockerParts = [];
          foreach ($blockers as $dependencyKey => $count) {
            $blockerParts[] = ($dependencyLabels[$dependencyKey] ?? (string)$dependencyKey) . ': ' . (int)$count;
          }
          $canDeletePeriod = !$isActivePeriod && !$blockers;
        ?>
        <tr>
          <td>
            <strong><?=h($schoolYear)?> · <?=h(period_label_options()[$periodLabel] ?? $periodLabel)?></strong>
            <?php if ($isActivePeriod): ?>
              <span class="badge"><?=h(t('teacher.classes.status.active', 'Aktiv'))?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isActivePeriod): ?>
              <span class="muted"><?=h(t('admin.classes.period_delete.active_blocked', 'Das aktive Schulhalbjahr kann nicht gelöscht werden.'))?></span>
            <?php elseif ($blockerParts): ?>
              <span class="muted"><?=h(str_replace(
                '{details}',
                implode(', ', $blockerParts),
                t('admin.classes.period_delete.dependencies_short', 'Geschützt durch vorhandene Daten: {details}.')
              ))?></span>
            <?php else: ?>
              <span class="badge"><?=h(t('admin.classes.period_delete.safe', 'Leer – kann sicher entfernt werden'))?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($canDeletePeriod): ?>
              <form method="post" class="row" style="gap:8px; align-items:center; flex-wrap:wrap;" onsubmit="return confirm(<?=h(json_encode(t('admin.classes.period_delete.confirm_modal', 'Dieses leere Schulhalbjahr wirklich entfernen?'), JSON_UNESCAPED_UNICODE))?>);">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete_period">
                <input type="hidden" name="school_year" value="<?=h($schoolYear)?>">
                <input type="hidden" name="period_label" value="<?=h($periodLabel)?>">
                <input name="confirm_text" type="text" placeholder="DELETE" aria-label="<?=h(t('admin.classes.period_delete.confirm_label', 'Zur Bestätigung DELETE eingeben'))?>" required style="max-width:130px;">
                <button class="btn danger" type="submit"><?=h(t('admin.classes.period_delete.button', 'Leeres Halbjahr entfernen'))?></button>
              </form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
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
  const form = document.getElementById('selectivePeriodTransferForm');
  if (!form) return;

  const sourceYear = form.querySelector('select[name="source_school_year"]');
  const targetYear = form.querySelector('select[name="school_year"]');
  const targetPeriod = form.querySelector('select[name="period_label"]');
  const options = Array.from(form.querySelectorAll('.transfer-class-option'));
  const chooseYear = document.getElementById('transferClassChooseYear');
  const empty = document.getElementById('transferClassEmpty');
  const selectAll = document.getElementById('selectAllTransferClasses');
  const clear = document.getElementById('clearTransferClasses');
  const selectionRequired = <?=json_encode(t('admin.classes.switch.selection_required', 'Bitte mindestens eine Quellklasse auswählen.'), JSON_UNESCAPED_UNICODE)?>;
  const confirmation = <?=json_encode(t('admin.classes.switch_confirm', 'Die ausgewählten Klassen jetzt übertragen?'), JSON_UNESCAPED_UNICODE)?>;

  const refreshOptions = () => {
    const source = String(sourceYear ? sourceYear.value : '');
    const target = String(targetYear ? targetYear.value : '');
    const period = String(targetPeriod ? targetPeriod.value : 'Standard');
    let visible = 0;

    options.forEach((row) => {
      const input = row.querySelector('input[type="checkbox"]');
      const sameSourceYear = source !== '' && row.dataset.schoolYear === source;
      const alreadyInTarget = target !== '' && source === target && row.dataset.periodLabel === period;
      const eligible = sameSourceYear && !alreadyInTarget;
      row.hidden = !eligible;
      if (input) {
        input.disabled = !eligible;
        if (!eligible) input.checked = false;
      }
      if (eligible) visible++;
    });

    if (chooseYear) chooseYear.hidden = source !== '';
    if (empty) empty.hidden = source === '' || visible > 0;
    if (selectAll) selectAll.disabled = visible === 0;
    if (clear) clear.disabled = visible === 0;
  };

  [sourceYear, targetYear, targetPeriod].forEach((element) => {
    if (element) element.addEventListener('change', refreshOptions);
  });
  if (selectAll) {
    selectAll.addEventListener('click', () => {
      options.forEach((row) => {
        const input = row.querySelector('input[type="checkbox"]');
        if (input && !input.disabled) input.checked = true;
      });
    });
  }
  if (clear) {
    clear.addEventListener('click', () => {
      options.forEach((row) => {
        const input = row.querySelector('input[type="checkbox"]');
        if (input) input.checked = false;
      });
    });
  }
  form.addEventListener('submit', function (event) {
    const selected = form.querySelectorAll('input[name="class_ids[]"]:checked:not(:disabled)').length;
    if (selected < 1) {
      event.preventDefault();
      window.alert(selectionRequired);
      return;
    }
    if (!window.confirm(confirmation)) event.preventDefault();
  });

  refreshOptions();
});
</script>

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
