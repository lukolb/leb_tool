<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/latex_template_packages.php';

function latex_package_data_assert(bool $condition, string $message): void {
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

latex_package_data_assert(
    latex_template_package_main_includes_data('\\input{data.tex}'),
    'a direct data.tex input must be detected'
);
latex_package_data_assert(
    latex_template_package_main_includes_data("\\providecommand{\\LEBDataFile}{data.tex}\n\\input{\\LEBDataFile}"),
    'the package data.tex indirection must be detected'
);
latex_package_data_assert(
    !latex_template_package_main_includes_data("% \\input{data.tex}\n\\input{layout.tex}"),
    'commented or missing data.tex inputs must not be detected'
);

fwrite(STDOUT, "latex_template_package_dynamic_data_test: OK\n");
