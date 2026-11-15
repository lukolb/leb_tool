<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
  'bootstrap' => $root . '/bootstrap.php',
  'install' => $root . '/install.php',
  'admin_classes' => $root . '/admin/classes.php',
  'teacher_classes' => $root . '/teacher/classes.php',
  'entry_api' => $root . '/teacher/ajax/entry_api.php',
  'entry' => $root . '/teacher/entry.php',
  'students' => $root . '/teacher/students.php',
  'export' => $root . '/shared/export_api_core.php',
  'parent_portal' => $root . '/parent/portal.php',
];
$source = [];
foreach ($files as $key => $path) {
  $source[$key] = file_get_contents($path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function finalization_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

finalization_assert(strpos($source['install'], "enum('draft','submitted','locked','final')") !== false, 'fresh installations must support the completed report status');
finalization_assert(strpos($source['bootstrap'], "ENUM('draft','submitted','locked','final')") !== false, 'existing installations must migrate to the completed report status');
finalization_assert(strpos($source['bootstrap'], 'function finalize_reports_for_class') !== false, 'class archival needs a central report finalization helper');
finalization_assert(strpos($source['bootstrap'], 'function finalize_reports_for_archived_classes') !== false, 'already archived classes and terms need a backfill helper');
finalization_assert(strpos($source['bootstrap'], 'sp.is_active=0') !== false, 'inactive school terms must be included in the backfill');
finalization_assert(strpos($source['bootstrap'], 'active_sca.is_active=1') !== false, 'a student still assigned to another active class in the same term must remain editable');
finalization_assert(substr_count($source['admin_classes'], 'finalize_reports_for_class(') >= 3, 'all administrator class archival paths must finalize reports');
finalization_assert(strpos($source['admin_classes'], 'finalize_reports_for_archived_classes(') !== false, 'changing the active term must finalize older reports');
finalization_assert(strpos($source['teacher_classes'], 'finalize_reports_for_class(') !== false, 'teacher class archival must finalize reports');
finalization_assert(substr_count($source['entry_api'], "=== 'final'") >= 4, 'all report mutation endpoints must reject completed reports');
finalization_assert(strpos($source['entry'], "['locked', 'final']") !== false, 'completed reports must render read-only in the entry UI');
finalization_assert(strpos($source['export'], "ri.status IN ('submitted','final')") !== false, 'completed reports must remain part of submitted-only exports');
finalization_assert(strpos($source['export'], "!== 'final' && function_exists('apply_system_bindings')") !== false, 'exports must not recalculate stored completed-report values');
finalization_assert(strpos($source['parent_portal'], "!== 'final'") !== false, 'parent previews must not recalculate stored completed-report values');
finalization_assert(strpos($source['students'], "if (\$status === 'final') \$status = 'submitted';") !== false, 'completed reports must be counted as handed in');

fwrite(STDOUT, "class_report_finalization_test: OK\n");
