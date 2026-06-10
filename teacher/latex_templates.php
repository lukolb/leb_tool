<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/latex_template_packages.php';
require_teacher();

$pdo = db();
$activeLatexPackages = get_latex_template_packages($pdo, true);

render_teacher_header(t('latex.title'));
$latexBuildUrl = url('teacher/pdf_preview.php');
$allowTeacherTemplateSubmission = true;
$allowLatexTemplatePackageSelection = true;
$latexTemplatePackages = $activeLatexPackages;
require __DIR__ . '/../shared/latex_page.php';
render_teacher_footer();
