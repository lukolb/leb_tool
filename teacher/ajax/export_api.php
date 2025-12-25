<?php
// teacher/ajax/export_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

$u = current_user();

// Teacher endpoint enforces class access by default.
// Admins may land here via teacher-area links, but must be able to export all classes/students.
$role = (string)($u['role'] ?? '');

$GLOBALS['EXPORT_ENFORCE_CLASS_ACCESS'] = ($role !== 'admin');
$GLOBALS['EXPORT_USER_ID'] = (int)($u['id'] ?? 0);

require __DIR__ . '/../../shared/export_api_core.php';
