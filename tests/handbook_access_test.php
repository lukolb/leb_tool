<?php
declare(strict_types=1);

function handbook_access_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/shared/_layout.php');
$translations = file_get_contents($root . '/shared/translations.php');
$handbook = file_get_contents($root . '/docs/leb_tool_benutzerhandbuch.tex');

handbook_access_assert(
  is_string($layout) && is_string($translations) && is_string($handbook),
  'handbook access sources must be readable'
);
handbook_access_assert(
  str_contains($layout, "url('docs/leb_tool_benutzerhandbuch.pdf')")
    && str_contains($layout, 'class="lang handbook-link"')
    && str_contains($layout, 'target="_blank"')
    && str_contains($layout, 'rel="noopener"')
    && str_contains($layout, "t('nav.handbook')"),
  'the shared authenticated header must expose a safe handbook icon link'
);
handbook_access_assert(
  substr_count($translations, "'nav.handbook'") === 2
    && substr_count($translations, "'ui.header_actions_aria'") === 2,
  'the handbook link and header action group must be translated in German and English'
);
handbook_access_assert(
  str_contains($handbook, 'Das kleine Buchsymbol in der Kopfzeile öffnet dieses Benutzerhandbuch'),
  'the handbook must explain how to open itself from the tool'
);

fwrite(STDOUT, "handbook_access_test: OK\n");
