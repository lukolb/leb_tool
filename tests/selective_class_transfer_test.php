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

$prepareStart = strpos($source, "elseif (\$action === 'prepare_switch_period')");
$prepareEnd = $prepareStart !== false ? strpos($source, "elseif (\$action === 'cancel_switch_period')", $prepareStart) : false;
transfer_assert($prepareStart !== false && $prepareEnd !== false && $prepareEnd > $prepareStart, 'prepare action must exist as a bounded handler');
$prepareHandler = substr($source, $prepareStart, $prepareEnd - $prepareStart);
transfer_assert(strpos($prepareHandler, "\$_POST['class_ids']") !== false, 'prepare action must accept an explicit class selection');
transfer_assert(strpos($prepareHandler, "\$_POST['source_school_year']") !== false, 'prepare action must distinguish the source school year');
transfer_assert(strpos($prepareHandler, "\$_POST['school_year']") !== false, 'prepare action must accept a target school year');
transfer_assert(strpos($prepareHandler, "pending_class_transfers") !== false, 'reviewed class ids must be frozen in the session');

$start = strpos($source, "elseif (\$action === 'switch_period')");
$end = $start !== false ? strpos($source, "elseif (\$action === 'set_active_period')", $start) : false;
transfer_assert($start !== false && $end !== false && $end > $start, 'switch_period action must exist as a bounded handler');
$handler = substr($source, $start, $end - $start);
transfer_assert(strpos($handler, "\$_POST['class_ids']") === false, 'confirmed transfer must not trust raw class ids');
transfer_assert(strpos($handler, "pending_class_transfers") !== false, 'confirmed transfer must use the frozen reviewed selection');
transfer_assert(strpos($handler, "load_selected_transfer_classes") !== false, 'confirmed selection must be revalidated and locked');
transfer_assert(strpos($source, 'c.id IN ($placeholders)') !== false, 'only selected class ids may be loaded');
transfer_assert(strpos($source, "if (\$forUpdate) \$sql .= ' FOR UPDATE'") !== false, 'selected source classes must be locked');
transfer_assert(strpos($handler, 'student_class_assignments') !== false, 'existing students must be linked through assignments');
transfer_assert(strpos($handler, 'INSERT INTO students') === false, 'term transfer must never duplicate student profiles');
transfer_assert(strpos($handler, '$sourceSchoolYear !== $targetSchoolYear ? 1 : 0') !== false, 'grade increment must occur only for a new school year');
transfer_assert(strpos($handler, '$sourceGradeLevel + $gradeIncrement') !== false, 'target grade must include the school-year increment');
transfer_assert(strpos($handler, 'WHERE class_id=?') !== false, 'inactive and active source assignments must both be transferred');

transfer_assert(strpos($source, 'name="class_ids[]"') !== false, 'class selection checkboxes must be rendered');
transfer_assert(strpos($source, 'data-school-year=') !== false, 'source classes must be filterable by school year');
transfer_assert(strpos($source, "t('teacher.classes.status.inactive'") !== false, 'inactive classes must be visible in the selector');
transfer_assert(strpos($source, 'input[name="class_ids[]"]:checked:not(:disabled)') !== false, 'the browser must require at least one selected class');
transfer_assert(strpos($source, 'Genau diese {count} Klassen übertragen') !== false, 'server-side preview must show the exact confirmation count');
transfer_assert(strpos($source, 'name="transfer_token"') !== false, 'confirmation must submit only the reviewed transfer token');
transfer_assert(strpos($source, 'admin.classes.switch.selection_collision') !== false, 'multiple source classes must not collapse into one target class');

fwrite(STDOUT, "selective_class_transfer_test: OK\n");
