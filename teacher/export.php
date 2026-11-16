<?php
// teacher/export.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$role = (string)($u['role'] ?? '');
$classId = (int)($_GET['class_id'] ?? 0);
$requestedStudentId = max(0, (int)($_GET['student_id'] ?? 0));

// Admin: allow all classes (teacher-area links can land admins here)
if ($role === 'admin') {
  $st = $pdo->prepare(
    "SELECT c.*
     FROM classes c
     WHERE c.is_active=1 OR c.id=?
     ORDER BY c.is_active DESC, c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$classId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Teachers: assigned classes, active class grants, and an explicitly linked
  // historical class for a student-wide grant.
  $st = $pdo->prepare(
    "SELECT DISTINCT c.*
     FROM classes c
     WHERE (c.is_active=1 OR c.id=?)
       AND (
         EXISTS (
           SELECT 1 FROM user_class_assignments uca
           WHERE uca.class_id=c.id AND uca.user_id=?
         )
         OR EXISTS (
           SELECT 1 FROM report_access_requests class_grant
           WHERE class_grant.class_id=c.id
             AND class_grant.requester_user_id=?
             AND class_grant.scope_type='class'
             AND class_grant.status='approved'
             AND (class_grant.expires_at IS NULL OR class_grant.expires_at>=NOW())
         )
         OR (
           c.id=? AND ? > 0
           AND EXISTS (
             SELECT 1 FROM student_class_assignments sca
             WHERE sca.class_id=c.id AND sca.student_id=?
           )
           AND EXISTS (
             SELECT 1 FROM report_access_requests student_grant
             WHERE student_grant.requester_user_id=?
               AND student_grant.scope_type='student'
               AND student_grant.student_id=?
               AND student_grant.status='approved'
               AND (student_grant.expires_at IS NULL OR student_grant.expires_at>=NOW())
           )
         )
       )
     ORDER BY c.is_active DESC, c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([
    $classId,
    $userId,
    $userId,
    $classId,
    $requestedStudentId,
    $requestedStudentId,
    $userId,
    $requestedStudentId,
  ]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);

$csrf = csrf_token();
$debugPdf = (int)($_GET['debug_pdf'] ?? 0) === 1;

$pageTitle = t('teacher.export.title', 'PDF-Export');
$backUrl = url('teacher/index.php');
$exportApiUrl = url('teacher/ajax/export_api.php');

render_teacher_header($pageTitle);

require __DIR__ . '/../shared/export_page.php';

render_teacher_footer();
