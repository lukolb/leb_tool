<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/admin/classes.php');
if ($source === false) {
  fwrite(STDERR, "Could not read admin/classes.php\n");
  exit(1);
}

function transfer_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$start = strpos($source, "elseif (\$action === 'switch_period')");
$end = $start !== false ? strpos($source, "elseif (\$action === 'set_active_period')", $start) : false;
transfer_assert($start !== false && $end !== false && $end > $start, 'switch_period action must exist as a bounded handler');
$handler = substr($source, $start, $end - $start);

transfer_assert(strpos($handler, "\$_POST['class_ids']") !== false, 'handler must accept an explicit class selection');
transfer_assert(strpos($handler, "\$_POST['source_school_year']") !== false, 'handler must distinguish the source school year');
transfer_assert(strpos($handler, "\$_POST['school_year']") !== false, 'handler must accept a target school year');
transfer_assert(strpos($handler, 'id IN ($classPlaceholders)') !== false, 'only selected class ids may be loaded');
transfer_assert(strpos($handler, 'FOR UPDATE') !== false, 'selected source classes must be locked');
transfer_assert(strpos($handler, 'student_class_assignments') !== false, 'existing students must be linked through assignments');
transfer_assert(strpos($handler, 'INSERT INTO students') === false, 'term transfer must never duplicate student profiles');
transfer_assert(strpos($handler, '$sourceSchoolYear !== $targetSchoolYear ? 1 : 0') !== false, 'grade increment must occur only for a new school year');
transfer_assert(strpos($handler, '$sourceGradeLevel + $gradeIncrement') !== false, 'target grade must include the school-year increment');
transfer_assert(strpos($handler, 'WHERE class_id=?') !== false, 'inactive and active source assignments must both be transferred');

transfer_assert(strpos($source, 'name="class_ids[]"') !== false, 'class selection checkboxes must be rendered');
transfer_assert(strpos($source, 'data-school-year=') !== false, 'source classes must be filterable by school year');
transfer_assert(strpos($source, "t('teacher.classes.status.inactive'") !== false, 'inactive classes must be visible in the selector');
transfer_assert(strpos($source, 'input[name="class_ids[]"]:checked:not(:disabled)') !== false, 'the browser must require at least one selected class');

fwrite(STDOUT, "selective_class_transfer_test: OK\n");
