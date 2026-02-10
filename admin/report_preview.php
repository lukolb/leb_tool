<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$u = current_user() ?: [];
$isAdmin = true;

render_admin_header(t('teacher.report_preview.title', 'Berichtsvorschau'));
require __DIR__ . '/../shared/report_preview_page.php';
render_admin_footer();
