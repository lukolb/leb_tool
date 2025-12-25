<?php
// admin/ajax/export_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

// Admin: no class restriction
$EXPORT_ENFORCE_CLASS_ACCESS = false;
$EXPORT_USER_ID = 0;

require __DIR__ . '/../../shared/export_api_core.php';
