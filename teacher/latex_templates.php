<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

render_teacher_header(t('latex.title', 'Kompetenz-PDF erstellen'));
$latexBuildUrl = url('teacher/pdf_preview.php');
$allowTeacherTemplateSubmission = true;
require __DIR__ . '/../shared/latex_page.php';
render_teacher_footer();
