<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

render_admin_header(t('latex.title', 'Kompetenz-PDF erstellen'));
$latexBuildUrl = url('admin/build.php');
require __DIR__ . '/../shared/latex_page.php';
render_admin_footer();
