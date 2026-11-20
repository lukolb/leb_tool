<?php
declare(strict_types=1);

function student_submission_cycle_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "student_submission_cycle_test: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$students = (string)file_get_contents($root . '/teacher/students.php');
$wizard = (string)file_get_contents($root . '/student/ajax/wizard_api.php');
$student = (string)file_get_contents($root . '/student/index.php');
$translations = (string)file_get_contents($root . '/shared/translations.php');
$handbook = (string)file_get_contents($root . '/docs/leb_tool_benutzerhandbuch.tex');

student_submission_cycle_assert(
  str_contains($wizard, '$allCategoriesFilled = $isDemo')
    && str_contains($wizard, 'all_child_fields_filled($pdo, $templateId, $reportId, $lockedFieldIds, null)')
    && str_contains($wizard, "if (\$allCategoriesFilled) {")
    && str_contains($wizard, "'status' => 'draft', 'all_categories_complete' => false")
    && str_contains($wizard, "audit('student_submit_partial'"),
  'a partial category run must stay draft and only a complete overall self-assessment may become submitted'
);

student_submission_cycle_assert(
  str_contains($student, 'const submitResult = await api(\'submit\', {});')
    && str_contains($student, 'submitResult?.all_categories_complete === false')
    && substr_count($translations, "'student.js.submit_partial'") === 2,
  'the student must receive a clear completion message for a partial category run'
);

student_submission_cycle_assert(
  str_contains($students, 'function reopen_incomplete_submitted_reports_for_class')
    && str_contains($students, "['active' => false, 'map' => []]")
    && str_contains($students, "WHERE id IN (\$in) AND status='submitted'")
    && str_contains($students, "if (\$mode === 'unlock') {")
    && str_contains($students, 'reopen_incomplete_submitted_reports_for_class($pdo, $templateId, $classId, $schoolYear, $periodLabel)'),
  'starting another category run must automatically reopen only incomplete submitted reports while respecting per-student n/a exclusions'
);

student_submission_cycle_assert(
  str_contains($students, 'function reset_submitted_reports_for_class')
    && str_contains($students, "\$action === 'child_reset_submitted_class'")
    && str_contains($students, 'value="child_reset_submitted_class"')
    && str_contains($students, "ri.status='submitted'")
    && str_contains($students, "\$counts['resettable_submitted']")
    && !str_contains($students, "ri.status IN ('submitted','final')")
    && substr_count($translations, "'teacher.students.child_reset_submitted'") === 2,
  'class teachers must have an explicit submitted-to-draft reset that never reopens final reports'
);

student_submission_cycle_assert(
  str_contains($handbook, 'Abgegeben-Status zurücksetzen')
    && str_contains($handbook, 'weitere Kategorien'),
  'the handbook must explain status reset and multi-category continuation'
);

fwrite(STDOUT, "student_submission_cycle_test: OK\n");
