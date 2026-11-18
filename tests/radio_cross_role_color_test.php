<?php
declare(strict_types=1);

function radio_cross_role_color_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$settings = file_get_contents($root . '/admin/settings.php');
$export = file_get_contents($root . '/shared/export_page.php');
$preview = file_get_contents($root . '/shared/report_preview_page.php');
$parent = file_get_contents($root . '/parent/portal.php');
$translations = file_get_contents($root . '/shared/translations.php');
$sample = file_get_contents($root . '/config.sample.php');
$handbook = file_get_contents($root . '/docs/leb_tool_benutzerhandbuch.tex');

foreach ([$settings, $export, $preview, $parent, $translations, $sample, $handbook] as $source) {
  radio_cross_role_color_assert(is_string($source), 'radio cross color sources must be readable');
}

radio_cross_role_color_assert(
  str_contains($settings, 'name="export_radio_cross_color_student"')
    && str_contains($settings, 'name="export_radio_cross_color_teacher"')
    && str_contains($settings, "\$cfg['export']['radio_cross_color_student']")
    && str_contains($settings, "\$cfg['export']['radio_cross_color_teacher']")
    && str_contains($settings, "\$cfg['export']['radio_cross_color'] = strtoupper(\$radioCrossColorTeacher)"),
  'admin settings must save separate student and teacher colors while retaining the legacy fallback key'
);

foreach (['export' => $export, 'preview' => $preview, 'parent' => $parent] as $name => $source) {
  radio_cross_role_color_assert(
    str_contains($source, 'RADIO_CROSS_COLOR_STUDENT_HEX')
      && str_contains($source, 'RADIO_CROSS_COLOR_TEACHER_HEX')
      && str_contains($source, '/[-_]S$/i.test(fieldName)')
      && !str_contains($source, 'RADIO_CROSS_COLOR_HEX'),
    "{$name} PDF renderer must choose the fixed cross color from the radio field role"
  );
}

radio_cross_role_color_assert(
  substr_count($translations, "'admin.settings.export.radio_cross_color_student'") === 2
    && substr_count($translations, "'admin.settings.export.radio_cross_color_teacher'") === 2
    && str_contains($sample, "'radio_cross_color_student' => '#0b57d0'")
    && str_contains($sample, "'radio_cross_color_teacher' => '#0b57d0'"),
  'separate cross-color settings must be documented and translated in German and English'
);

radio_cross_role_color_assert(
  str_contains($handbook, '\\index{Radio-Kreuzfarbe!Schüler}')
    && str_contains($handbook, 'zwei getrennte Hex-Felder für Schüler- und Lehrkraftkreuze')
    && str_contains($handbook, 'Berichtvorschau, Export und Eltern-PDF'),
  'the handbook must explain role-specific colors in every PDF output path'
);

fwrite(STDOUT, "radio_cross_role_color_test: OK\n");
