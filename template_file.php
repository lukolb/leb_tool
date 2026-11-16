<?php
// template_file.php
// Serves the PDF template assigned to a class (teacher+admin access).
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classId = (int)($_GET['class_id'] ?? 0);
$studentId = max(0, (int)($_GET['student_id'] ?? 0));
if ($classId <= 0) {
  http_response_code(400);
  echo "Bad Request";
  exit;
}

if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  $stGrant = $pdo->prepare(
    "SELECT c.school_year, c.period_label
     FROM classes c
     INNER JOIN student_class_assignments sca ON sca.class_id=c.id AND sca.student_id=?
     WHERE c.id=?
     LIMIT 1"
  );
  $stGrant->execute([$studentId, $classId]);
  $grantClass = $stGrant->fetch(PDO::FETCH_ASSOC);
  $hasReportGrant = $studentId > 0 && $grantClass && user_can_view_student_report(
    $pdo,
    $userId,
    $studentId,
    (string)($grantClass['school_year'] ?? ''),
    (string)($grantClass['period_label'] ?? 'H1')
  );
  if (!$hasReportGrant) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}

$st = $pdo->prepare(
  "SELECT c.id, c.template_id, t.pdf_storage_path, t.pdf_original_filename
   FROM classes c
   JOIN templates t ON t.id=c.template_id
   WHERE c.id=?
   LIMIT 1"
);
$st->execute([$classId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || (int)($row['template_id'] ?? 0) <= 0) {
  http_response_code(404);
  echo "Not Found";
  exit;
}

$rel = (string)($row['pdf_storage_path'] ?? '');
$abs = realpath(__DIR__ . '/' . ltrim($rel, '/'));

if (!$abs || !is_file($abs)) {
  http_response_code(404);
  echo "File Not Found";
  exit;
}

// Security: must be inside uploads dir
$uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
$uploadsAbs = realpath(__DIR__ . '/' . $uploadsDirRel);
if (!$uploadsAbs || !str_starts_with($abs, $uploadsAbs)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$filename = (string)($row['pdf_original_filename'] ?? 'template.pdf');
if ($filename === '') $filename = 'template.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename=\"' . rawurlencode($filename) . '\"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

readfile($abs);
exit;
