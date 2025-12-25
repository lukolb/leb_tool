<?php
// teacher/export.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();

$st = $pdo->query("SELECT c.* FROM classes c WHERE c.is_active=1 ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC");
$classes = $st->fetchAll(PDO::FETCH_ASSOC);

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);

$csrf = csrf_token();
$debugPdf = (int)($_GET['debug_pdf'] ?? 0) === 1;

$pageTitle = 'PDF-Export';
$backUrl = url('teacher/index.php');
$exportApiUrl = url('teacher/ajax/export_api.php');

render_teacher_header($pageTitle);

require __DIR__ . '/../shared/export_page.php';

render_teacher_footer();
