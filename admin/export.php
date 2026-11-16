<?php
// admin/export.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();

$classId = (int)($_GET['class_id'] ?? 0);
$st = $pdo->query(
  "SELECT c.*
   FROM classes c
   ORDER BY c.is_active DESC, c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
);
$classes = $st->fetchAll(PDO::FETCH_ASSOC);

if ($classId <= 0 && $classes) $classId = (int)($classes[0]['id'] ?? 0);
$groupExportClassesByArchive = true;

$csrf = csrf_token();
$debugPdf = (int)($_GET['debug_pdf'] ?? 0) === 1;

$pageTitle = 'PDF-Export';
$backUrl = url('admin/index.php');
$exportApiUrl = url('admin/ajax/export_api.php');

render_admin_header($pageTitle);

require __DIR__ . '/../shared/export_page.php';

render_admin_footer();
