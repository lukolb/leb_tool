<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/student_list_reconciliation.php';

function reconciliation_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$labels = [
  'first_name' => 'Vorname',
  'last_name' => 'Nachname',
  'date_of_birth' => 'Geburtsdatum',
  'email_student' => 'E-Mail Kind',
  'is_active' => 'Status',
  'status_active' => 'aktiv',
  'status_inactive' => 'inaktiv',
];

$existing = [
  ['id'=>1, 'first_name'=>'Ada', 'last_name'=>'Lovelace', 'date_of_birth'=>'2015-12-10', 'email_student'=>'ada@old.test', 'is_active'=>1, 'custom'=>['bus'=>'A']],
  ['id'=>2, 'first_name'=>'Grace', 'last_name'=>'Hopper', 'date_of_birth'=>'2015-09-12', 'email_student'=>'grace@test.invalid', 'is_active'=>1, 'custom'=>[]],
  ['id'=>3, 'first_name'=>'Katherine', 'last_name'=>'Johnson', 'date_of_birth'=>'2015-08-26', 'email_student'=>'', 'is_active'=>0, 'custom'=>[]],
];
$uploaded = [
  ['row_key'=>'0', 'first_name'=>'Ada', 'last_name'=>'Lovelace', 'date_of_birth'=>'2015-12-10', 'email_student'=>'ada@new.test', 'present_fields'=>['first_name','last_name','date_of_birth','email_student','custom:bus'], 'custom'=>['bus'=>'B'], 'custom_labels'=>['bus'=>'Bus']],
  ['row_key'=>'1', 'first_name'=>'Katherine', 'last_name'=>'Johnson', 'date_of_birth'=>'2015-08-27', 'email_student'=>'', 'present_fields'=>['first_name','last_name','date_of_birth'], 'custom'=>[]],
  ['row_key'=>'2', 'first_name'=>'Dorothy', 'last_name'=>'Vaughan', 'date_of_birth'=>'2015-09-20', 'email_student'=>'', 'present_fields'=>['first_name','last_name','date_of_birth'], 'custom'=>[]],
];

$plan = build_student_list_reconciliation($existing, $uploaded, $labels);
reconciliation_assert(count($plan['matched']) === 2, 'two existing students should match');
reconciliation_assert(count($plan['new']) === 1, 'one student should be marked new');
reconciliation_assert(count($plan['missing']) === 1 && (int)$plan['missing'][0]['id'] === 2, 'missing class member should be identified');

$adaDiff = $plan['matched'][0]['diff'];
reconciliation_assert(count($adaDiff) === 2, 'email and custom field changes should be reported');
reconciliation_assert($adaDiff[0]['field'] === 'email_student', 'email difference should be first');
reconciliation_assert($adaDiff[1]['field'] === 'custom:bus', 'custom difference should be reported');

$katherineDiffFields = array_column($plan['matched'][1]['diff'], 'field');
reconciliation_assert(in_array('date_of_birth', $katherineDiffFields, true), 'changed birth date should match by unique name and be reported');
reconciliation_assert(in_array('is_active', $katherineDiffFields, true), 'inactive assignment should be reported as a change');

reconciliation_assert(student_list_period_is_at_or_after('2025/26', 'Standard', '2025/26', 'Standard'), 'current half-year should be included');
reconciliation_assert(student_list_period_is_at_or_after('2025/26', 'H2', '2025/26', 'Standard'), 'later half-year in same school year should be included');
reconciliation_assert(student_list_period_is_at_or_after('2026/27', 'Standard', '2025/26', 'H2'), 'future school year should be included');
reconciliation_assert(!student_list_period_is_at_or_after('2024/25', 'H2', '2025/26', 'Standard'), 'past assignments must be preserved');
reconciliation_assert(!student_list_period_is_at_or_after('2025/26', 'Standard', '2025/26', 'H2'), 'earlier half-year must be preserved');

$teacherSource = file_get_contents(__DIR__ . '/../teacher/students.php');
reconciliation_assert(is_string($teacherSource), 'teacher students source must be readable');
reconciliation_assert(strpos($teacherSource, 'COALESCE(NULLIF(s.master_student_id, 0), s.id)=s.id') !== false, 'import preview must compare against all canonical database students');
reconciliation_assert(strpos($teacherSource, 'student_list_remove_assignments_from_period') !== false, 'removal must be scoped from the current half-year onward');
reconciliation_assert(strpos($teacherSource, "'assignments_removed'") !== false, 'removed assignments must be audited separately');

echo "student_list_reconciliation_test: ok\n";
