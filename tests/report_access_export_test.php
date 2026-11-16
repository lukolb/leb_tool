<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  'admin_export' => 'admin/export.php',
  'teacher_export' => 'teacher/export.php',
  'export_page' => 'shared/export_page.php',
  'export_core' => 'shared/export_api_core.php',
  'template_file' => 'template_file.php',
];
$source = [];
foreach ($paths as $key => $path) {
  $source[$key] = file_get_contents($root . '/' . $path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function report_access_export_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

report_access_export_assert(strpos($source['admin_export'], 'WHERE c.is_active=1') === false && strpos($source['admin_export'], '$groupExportClassesByArchive = true') !== false, 'admin export must list and group active and archived classes');
report_access_export_assert(strpos($source['teacher_export'], "student_grant.scope_type='student'") !== false, 'teacher export must list a directly requested historical class covered by a student grant');
report_access_export_assert(strpos($source['teacher_export'], "class_grant.scope_type='class'") !== false, 'teacher export must list classes covered by a class grant');
report_access_export_assert(strpos($source['export_page'], 'const INITIAL_STUDENT_ID') !== false, 'the single export page must preserve the student id from the preview link');
report_access_export_assert(strpos($source['export_page'], 'previewRequest.student_id = initialStudentId') !== false, 'a granted single-student export must not fetch the rest of the class');
report_access_export_assert(strpos($source['export_core'], 'enforce_class_access($pdo, $classId, $onlyStudentId') !== false, 'export authorization must receive the selected student id');
report_access_export_assert(strpos($source['export_core'], 'user_can_view_student_report(') !== false, 'export authorization must accept active report access grants');
report_access_export_assert(strpos($source['export_page'], "'classes_archived'") !== false && strpos($source['export_page'], '<optgroup') !== false, 'archived admin classes must be visually separated from current classes');
report_access_export_assert(strpos($source['export_core'], '$includeInactiveAssignments ||') !== false && strpos($source['export_core'], "class_is_active") !== false, 'archived class exports and single historical exports must accept inactive historical assignments');
report_access_export_assert(strpos($source['template_file'], "\$_GET['student_id']") !== false && strpos($source['template_file'], 'user_can_view_student_report(') !== false, 'the PDF template endpoint must enforce the same granted student scope');

fwrite(STDOUT, "report_access_export_test: OK\n");
