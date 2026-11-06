<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/bootstrap.php');
$install = file_get_contents($root . '/install.php');
$teacherStudents = file_get_contents($root . '/teacher/students.php');
$adminStudents = file_get_contents($root . '/admin/students.php');
if ($bootstrap === false || $install === false || $teacherStudents === false || $adminStudents === false) {
  fwrite(STDERR, "Could not read architecture sources\n");
  exit(1);
}

function architecture_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

foreach ([$bootstrap, $install] as $schemaSource) {
  architecture_assert(strpos($schemaSource, 'student_class_assignments') !== false, 'schema must contain the student/class junction table');
  architecture_assert(strpos($schemaSource, 'identity_hash') !== false, 'schema must contain a canonical student identity');
  architecture_assert(strpos($schemaSource, 'uq_student_class_assignment') !== false, 'a student/class pair must be unique');
  architecture_assert(strpos($schemaSource, 'uq_students_identity_hash') !== false, 'resolved identities must be unique');
}

architecture_assert(strpos($bootstrap, "function student_identity_hash") !== false, 'identity helper must exist');
architecture_assert(strpos($bootstrap, "function assign_student_to_class") !== false, 'assignment helper must exist');
architecture_assert(strpos($bootstrap, "(string)(\$row['first_name'] ?? '')") !== false, 'duplicate lookup must compare first name');
architecture_assert(strpos($bootstrap, "(string)(\$row['last_name'] ?? '')") !== false, 'duplicate lookup must compare last name');
architecture_assert(strpos($bootstrap, 'WHERE date_of_birth=?') !== false, 'duplicate lookup must compare date of birth');
architecture_assert(strpos($bootstrap, 'hash_equals($hash, $candidateHash)') !== false, 'duplicate lookup must use the same normalized identity hash');
architecture_assert(strpos($bootstrap, 'Mehrere unabhängige Schüler') !== false, 'ambiguous legacy duplicates must be refused');

foreach ([$teacherStudents, $adminStudents] as $studentSource) {
  architecture_assert(strpos($studentSource, 'find_student_by_identity') !== false, 'student creation/import must check canonical identity');
  architecture_assert(strpos($studentSource, 'student_identity_hash') !== false, 'new students must store the identity hash');
  architecture_assert(strpos($studentSource, 'assign_student_to_class') !== false, 'new students must be assigned through the junction table');
  architecture_assert(strpos($studentSource, 'duplicate_confirmation') !== false, 'duplicates must be refused pending explicit confirmation');
}
architecture_assert(strpos($teacherStudents, "confirm_duplicate_assignment") !== false, 'duplicate assignment must require a separate confirmation action');
architecture_assert(strpos($teacherStudents, "pending_student_assignment_confirmations") !== false, 'duplicate confirmations must be scoped and short-lived');

$runtimeFiles = [
  'admin/index.php',
  'admin/templates.php',
  'student/index.php',
  'student/login.php',
  'teacher/index.php',
  'teacher/ags.php',
  'teacher/students.php',
];
foreach ($runtimeFiles as $runtimeFile) {
  $runtimeSource = file_get_contents($root . '/' . $runtimeFile);
  architecture_assert($runtimeSource !== false, "could not read {$runtimeFile}");
  architecture_assert(strpos($runtimeSource, 's.class_id') === false, "{$runtimeFile} must not use the legacy one-class student pointer");
}

fwrite(STDOUT, "student_class_assignment_architecture_test: OK\n");
