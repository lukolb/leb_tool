<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$preview = file_get_contents($root . '/shared/report_preview_page.php');
$teacherFile = file_get_contents($root . '/teacher/report_template_file.php');
if ($preview === false || $teacherFile === false) {
  fwrite(STDERR, "Could not read report preview sources\n");
  exit(1);
}

function report_preview_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

report_preview_assert(strpos($preview, "\$_GET['report_id']") !== false, 'a saved report must be selected by its immutable report id');
report_preview_assert(strpos($preview, "name=\"period\"") === false, 'the page must not treat a global class period as the report selector');
report_preview_assert(strpos($preview, 'rp-report-list') !== false, 'each student must expose a list of their saved reports');
report_preview_assert(strpos($preview, 'COALESCE(NULLIF(s.master_student_id, 0), s.id)=?') !== false, 'reports must be collected across all aliases of the canonical student');
report_preview_assert(strpos($preview, 'apply_system_bindings') === false, 'opening a historical preview must never recalculate or overwrite stored values');
report_preview_assert(strpos($preview, "CASE fv.source WHEN 'teacher' THEN 30 WHEN 'child' THEN 20 WHEN 'system' THEN 10") !== false, 'stored values must use deterministic source precedence');
report_preview_assert(strpos($preview, 'array_key_exists($fieldName, $valuesByName)') !== false, 'lower-priority values must not overwrite the selected stored value');
report_preview_assert(strpos($preview, "class_report_period_label(\$selectedClassId, \$selectedPeriodLabel)") !== false, 'class-scoped values must use the class belonging to the selected report period');
report_preview_assert(strpos($preview, 'name="q"') !== false && strpos($preview, 'name="class_id"') !== false, 'the student list must support name search and current-class filtering');
report_preview_assert(strpos($preview, "'class_id'=>\$selectedClassFilter") !== false && strpos($preview, "'q'=>\$studentSearch") !== false, 'report and student navigation must retain active filters');
report_preview_assert(strpos($preview, "teacher.report_preview.active_students_group") !== false, 'current students must be presented as a distinct group');
report_preview_assert(strpos($preview, "teacher.report_preview.archived_students_group") !== false, 'archived students must be presented as a distinct group');
report_preview_assert(strpos($preview, '$filteredArchivedStudents = $isAdmin ?') !== false, 'fully archived students must only be exposed to administrators');
report_preview_assert(strpos($preview, 'c.id=sca.class_id AND c.is_active=1') !== false && strpos($preview, 'sca.is_active=1 AND uca.user_id=?') !== false, 'teachers must only receive students from active assignments in active classes');
report_preview_assert(strpos($preview, 'id="rpDownload"') !== false && strpos($preview, "new Blob([renderedBytes], { type: 'application/pdf' })") !== false, 'the exact rendered report must be exportable as a PDF');
report_preview_assert(strpos($preview, 'name="confirm_ack"') !== false && strpos($preview, "hash_equals(\$mustMatch, \$confirmText)") !== false, 'report deletion must require both an acknowledgement and exact confirmation text');
report_preview_assert(strpos($preview, 'admin_report_delete') !== false && strpos($preview, 'DELETE FROM report_instances WHERE id=? AND student_id IS NOT NULL') !== false, 'an administrator must be able to delete exactly one student report with an audit record');
report_preview_assert(strpos($preview, 'DELETE FROM students') === false, 'deleting a report must never delete the student profile');

$mainQueryStart = strpos($teacherFile, 'SELECT ri.id, ri.template_id, ri.school_year, ri.period_label');
$permissionQueryStart = strpos($teacherFile, 'SELECT c.id', $mainQueryStart !== false ? $mainQueryStart : 0);
report_preview_assert($mainQueryStart !== false && $permissionQueryStart !== false && $permissionQueryStart > $mainQueryStart, 'teacher PDF access must load the exact report before resolving its historical class permission');
$mainQuery = substr($teacherFile, $mainQueryStart, $permissionQueryStart - $mainQueryStart);
report_preview_assert(strpos($mainQuery, 'student_class_assignments') === false, 'the exact report PDF lookup must not depend on a current class join');
report_preview_assert(strpos($teacherFile, 'c.school_year=? AND c.period_label=?') !== false, 'teacher permission must be checked against the report period');

fwrite(STDOUT, "report_preview_history_test: OK\n");
