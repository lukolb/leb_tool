<?php
// teacher/ajax/export_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

$u = current_user();

// Teacher: restrict to own classes
$EXPORT_ENFORCE_CLASS_ACCESS = true;
$EXPORT_USER_ID = (int)($u['id'] ?? 0);

require __DIR__ . '/../../shared/export_api_core.php';
