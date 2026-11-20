<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/group_keys.php';

function delegated_self_assessment_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "delegated_self_assessment_test: {$message}\n");
  exit(1);
}

$subgroupMeta = ['group' => 'Mathematik', 'subgroup' => 'Geometrie'];
delegated_self_assessment_assert(
  app_child_field_unlocked(['Mathematik' => true], $subgroupMeta),
  'a released main group must include its subgroup fields'
);
delegated_self_assessment_assert(
  !app_child_field_unlocked(['Mathematik' => true, 'Mathematik::Geometrie' => false], $subgroupMeta),
  'an explicit subgroup lock must override a released main group'
);
delegated_self_assessment_assert(
  app_child_field_unlocked(['Mathematik' => false, 'Mathematik::Geometrie' => true], $subgroupMeta),
  'an explicit subgroup release must override a locked main group'
);
delegated_self_assessment_assert(
  !app_child_field_unlocked(['Deutsch' => true], $subgroupMeta),
  'an unrelated release must not expose a delegated field'
);

$root = dirname(__DIR__);
$sources = [];
foreach ([
  'bootstrap' => 'bootstrap.php',
  'install' => 'install.php',
  'students' => 'teacher/students.php',
  'delegations' => 'teacher/delegations.php',
  'entry' => 'teacher/entry.php',
  'classes' => 'teacher/classes.php',
  'intro' => 'teacher/class_intro.php',
  'student_source' => 'teacher/students_source_api.php',
  'wizard' => 'student/ajax/wizard_api.php',
  'translations' => 'shared/translations.php',
] as $key => $path) {
  $sources[$key] = file_get_contents($root . '/' . $path);
  delegated_self_assessment_assert(is_string($sources[$key]), "could not read {$path}");
}

delegated_self_assessment_assert(
  str_contains($sources['bootstrap'], 'function user_can_manage_class')
    && str_contains($sources['students'], 'if (!user_can_manage_class($pdo, $userId, $classId))')
    && str_contains($sources['students'], 'render_delegate_self_assessment_page'),
  'delegate access must branch into a restricted view before student management executes'
);
delegated_self_assessment_assert(
  str_contains($sources['students'], "['delegate_self_assessment_prepare', 'delegate_self_assessment_lock', 'delegate_self_assessment_scopes', 'delegate_self_assessment_display', 'delegate_self_assessment_display_demo', 'delegate_generate_login']")
    && str_contains($sources['students'], 'delegate_generate_missing_student_logins')
    && str_contains($sources['students'], "\$token = trim((string)(\$student['qr_token'] ?? ''))")
    && str_contains($sources['students'], "\$code = trim((string)(\$student['login_code'] ?? ''))")
    && str_contains($sources['students'], 'ensure_reports_for_class($pdo, $templateId, $classId, $schoolYear, $periodLabel, $userId, false)')
    && str_contains($sources['students'], "url('teacher/qr_print.php?class_id=")
    && str_contains($sources['students'], "redirect('teacher/student_demo.php?class_id="),
  'the delegate workflow must provide safe preparation, codes, QR printing, and a student-view test'
);
delegated_self_assessment_assert(
  str_contains($sources['students'], "\$action === 'delegate_self_assessment_lock'")
    && str_contains($sources['students'], "save_delegate_child_scope_unlocks(\$pdo, \$classId, \$templateId, \$schoolYear, \$periodLabel, \$userId, \$scopes, [])")
    && str_contains($sources['students'], "lock_or_unlock_class(\$pdo, \$templateId, \$classId, \$schoolYear, \$periodLabel, \$userId, 'lock')")
    && str_contains($sources['students'], "audit('delegate_self_assessment_lock'")
    && str_contains($sources['students'], 'value="delegate_self_assessment_lock"'),
  'delegates must be able to close their scopes and lock student entry for the whole class'
);
delegated_self_assessment_assert(
  str_contains($sources['students'], 'normalize_delegate_student_display')
    && str_contains($sources['students'], "UPDATE classes SET student_wizard_display=?, syllable_coloring_enabled=? WHERE id=?")
    && str_contains($sources['students'], "audit('delegate_self_assessment_display'")
    && str_contains($sources['students'], "name=\"student_wizard_display\"")
    && str_contains($sources['students'], "name=\"syllable_coloring_enabled\"")
    && str_contains($sources['students'], "value=\"delegate_self_assessment_display_demo\"")
    && str_contains($sources['students'], "&display_mode=' . rawurlencode(\$displayMode)")
    && str_contains($sources['students'], "&syllable_coloring_enabled=' . (\$syllableColoring === 1 ? '1' : '0')")
    && substr_count($sources['students'], "t('teacher.delegate_self_assessment.demo'") === 1
    && str_contains(file_get_contents($root . '/teacher/student_demo.php'), "\$_GET['display_mode']"),
  'delegates must be able to save and test the independent class-wide view and syllable setting with one demo button'
);
delegated_self_assessment_assert(
  substr_count($sources['students'], 'setInterval(refresh, 5000)') === 1
    && substr_count($sources['students'], 'setInterval(refreshStudentProgress, 5000)') === 1
    && !str_contains($sources['students'], 'if(document.hidden)refresh()')
    && !str_contains($sources['students'], 'if (document.hidden) return;'),
  'student progress must keep polling in the background without a page reload'
);
delegated_self_assessment_assert(
  str_contains($sources['students'], 'function requested_student_canonical_map')
    && str_contains($sources['students'], '$canonicalByRequested = requested_student_canonical_map($pdo, $studentIds)')
    && str_contains($sources['students'], "'report_student_id' => (int)(\$r['report_student_id'] ?? 0)")
    && str_contains($sources['students'], "if ((int)\$candidate['report_student_id'] === (int)\$requestedId)"),
  'progress and child status must map reports back to the concrete student record used by the current class'
);
delegated_self_assessment_assert(
  str_contains($sources['students'], "'items' => \$items")
    && str_contains($sources['students'], 'data-student-item=')
    && str_contains($sources['students'], 'data-item-progress-summary=')
    && str_contains($sources['students'], 'Array.isArray(row.items)')
    && substr_count($sources['translations'], "'teacher.students.items_summary'") === 2,
  'progress must expose, render, and live-update every individual released item'
);
delegated_self_assessment_assert(
  str_contains($sources['classes'], 'user_can_manage_class($pdo, $userId, $classId)')
    && !str_contains($sources['classes'], 'user_can_access_class($pdo, $userId, $classId)')
    && str_contains($sources['intro'], 'user_can_manage_class($pdo, $userId, $classId)')
    && str_contains($sources['student_source'], 'user_can_manage_class($pdo, $userId, $sourceClassId)'),
  'delegation must not grant class settings, intro, or source-student management rights'
);
delegated_self_assessment_assert(
  str_contains($sources['wizard'], 'load_child_group_unlocks($pdo, $classId, $schoolYear, $periodLabel)')
    && str_contains($sources['wizard'], 'app_child_field_unlocked($groupUnlocks')
    && str_contains($sources['wizard'], "all_child_fields_filled(\$pdo, \$templateId, \$reportId, \$lockedFieldIds, \$groupUnlocks['active'] ? \$groupUnlocks['map'] : null)"),
  'student bootstrap, save, and submit must enforce the current period and field-level subgroup releases'
);
delegated_self_assessment_assert(
  str_contains($sources['delegations'], 'baseSelfAssessment')
    && str_contains($sources['entry'], "t('teacher.delegations.self_assessment'")
    && substr_count($sources['translations'], "'teacher.delegate_self_assessment.title'") === 2,
  'the translated workflow must be reachable from both delegation entry points'
);
delegated_self_assessment_assert(
  str_contains($sources['install'], '`group_key` varchar(190)')
    && str_contains($sources['bootstrap'], 'class_child_group_unlocks MODIFY COLUMN group_key VARCHAR(190)'),
  'subgroup release keys must fit both fresh installs and upgraded databases'
);

fwrite(STDOUT, "delegated_self_assessment_test: OK\n");
