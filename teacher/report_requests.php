<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user() ?: [];
$isAdmin = false;

render_teacher_header(t('report_requests.title', 'Berichtsanfragen'));
?>
<div class="container"><h1><?=h(t('report_requests.title', 'Berichtsanfragen'))?></h1><?php require __DIR__ . '/../shared/report_access_requests_page.php'; ?></div>
<?php render_teacher_footer(); ?>
