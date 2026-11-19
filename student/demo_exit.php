<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$user = current_user();
$demo = current_student_demo();
if ($user && $demo) {
  audit('student_demo_exit', (int)($user['id'] ?? 0), [
    'class_id' => (int)($demo['class_id'] ?? 0),
    'display_mode' => (string)($demo['display_mode'] ?? ''),
  ]);
}
unset($_SESSION['student_demo']);

if ($user) redirect('teacher/student_demo.php');
redirect('student/login.php');
