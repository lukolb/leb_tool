<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/admin/classes.php');
if ($source === false) {
  fwrite(STDERR, "Could not read admin/classes.php\n");
  exit(1);
}

function class_delete_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$start = strpos($source, 'function delete_class_with_all_data');
$end = $start !== false ? strpos($source, '// Teachers list', $start) : false;
class_delete_assert($start !== false && $end !== false && $end > $start, 'class deletion helper must exist');
$handler = substr($source, $start, $end - $start);

class_delete_assert(strpos($handler, 'class_delete_blockers') !== false, 'important dependencies must be checked before deletion');
class_delete_assert(strpos($handler, 'DELETE FROM class_group_delegations') !== false, 'delegation scaffolding must be removed');
class_delete_assert(strpos($handler, 'DELETE FROM class_child_group_unlocks') !== false, 'unlock scaffolding must be removed');
class_delete_assert(strpos($handler, 'DELETE FROM user_class_assignments') !== false, 'teacher assignments must be removed');
class_delete_assert(strpos($handler, 'DELETE FROM student_class_assignments') !== false, 'student assignments must be removed');
class_delete_assert(strpos($handler, 'DELETE FROM classes') !== false, 'the empty class itself must be removed');
class_delete_assert(strpos($handler, 'DELETE FROM students') === false, 'central student profiles must never be deleted with a class');
class_delete_assert(strpos($handler, 'DELETE FROM report_instances') === false, 'reports must never be deleted with a class');

foreach (['report_instances', 'student_period_absences', 'student_ag_assignments', 'parent_meeting_feedback'] as $dependency) {
  class_delete_assert(strpos($source, $dependency) !== false, "{$dependency} must participate in safety checks");
}

fwrite(STDOUT, "class_delete_safety_test: OK\n");
