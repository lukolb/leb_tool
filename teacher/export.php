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

// Admin: allow all classes (teacher-area links can land admins here)
if ($role === 'admin') {
  $st = $pdo->query(
    "SELECT c.*
     FROM classes c
     WHERE c.is_active=1
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Teacher: only assigned classes
  $st = $pdo->prepare(
    "SELECT c.*
     FROM classes c
     INNER JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE c.is_active=1 AND uca.user_id=?
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);

$csrf = csrf_token();
$debugPdf = (int)($_GET['debug_pdf'] ?? 0) === 1;

$pageTitle = t('teacher.export.title', 'PDF-Export');
$backUrl = url('teacher/index.php');
$exportApiUrl = url('teacher/ajax/export_api.php');

render_teacher_header($pageTitle);

require __DIR__ . '/../shared/export_page.php';

render_teacher_footer();
