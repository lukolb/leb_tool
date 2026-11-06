<?php
declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/admin/classes.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
  fwrite(STDERR, "Could not read admin/classes.php\n");
  exit(1);
}

function assert_true(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$start = strpos($source, "elseif (\$action === 'delete_period')");
$end = $start !== false ? strpos($source, "elseif (\$action === 'create_single')", $start) : false;
assert_true($start !== false && $end !== false && $end > $start, 'delete_period action must exist as a bounded handler');

$handler = substr($source, $start, $end - $start);
assert_true(strpos($handler, 'FOR UPDATE') !== false, 'period row must be locked before deletion');
assert_true(strpos($handler, "is_active'] ?? 0") !== false, 'active period guard must be present');
assert_true(strpos($handler, 'school_period_dependency_counts') !== false, 'dependency counts must be recalculated server-side');
assert_true(strpos($handler, 'school_period_blockers') !== false, 'dependency blockers must be enforced server-side');
assert_true(strpos($handler, 'DELETE FROM school_periods') !== false, 'handler must delete only the empty period record');

foreach ([
  'DELETE FROM classes',
  'DELETE FROM students',
  'DELETE FROM report_instances',
  'DELETE FROM submission_deadlines',
  'DELETE FROM student_period_absences',
  'DELETE FROM student_ag_assignments',
  'DELETE FROM class_group_delegations',
] as $forbiddenDelete) {
  assert_true(strpos($handler, $forbiddenDelete) === false, "handler must not contain {$forbiddenDelete}");
}

$lockPos = strpos($handler, 'FOR UPDATE');
$activeGuardPos = strpos($handler, "is_active'] ?? 0");
$dependencyPos = strpos($handler, 'school_period_dependency_counts');
$deletePos = strpos($handler, 'DELETE FROM school_periods');
assert_true($lockPos < $activeGuardPos, 'row lock must happen before the active-period guard');
assert_true($activeGuardPos < $dependencyPos, 'active-period guard must happen before dependency checks');
assert_true($dependencyPos < $deletePos, 'dependency checks must happen before deletion');

assert_true(strpos($handler, "\$confirmation !== 'DELETE'") !== false, 'typed DELETE confirmation must be required');
assert_true(strpos($source, 'name="action" value="delete_period"') !== false, 'protected delete form must be rendered');

fwrite(STDOUT, "school_period_delete_safety_test: OK\n");
