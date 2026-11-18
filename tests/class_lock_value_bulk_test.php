<?php
declare(strict_types=1);

function class_lock_value_bulk_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$entryPage = file_get_contents($root . '/teacher/entry.php');
$entryApi = file_get_contents($root . '/teacher/ajax/entry_api.php');
$translations = file_get_contents($root . '/shared/translations.php');
$handbook = file_get_contents($root . '/docs/leb_tool_benutzerhandbuch.tex');

class_lock_value_bulk_assert(
  is_string($entryPage) && is_string($entryApi) && is_string($translations) && is_string($handbook),
  'bulk lock-value sources must be readable'
);

class_lock_value_bulk_assert(
  str_contains($entryApi, 'SELECT id, value, label, label_en, icon_id, meta_json')
    && str_contains($entryApi, "'lock_child' => !empty(\$optionMeta['lock_child']) ? 1 : 0"),
  'the entry API must expose only the option-list lock metadata needed by the UI'
);

class_lock_value_bulk_assert(
  str_contains($entryPage, "Number(option?.lock_child || 0) === 1")
    && str_contains($entryPage, "api('apply_lock_value_to_class'")
    && str_contains($entryPage, "window.confirm(tfmtEntry('bulk_lock_confirm'"),
  'the grades overview must offer a confirmed class action only for marked lock values'
);

class_lock_value_bulk_assert(
  str_contains($entryApi, "if (\$action === 'apply_lock_value_to_class')")
    && str_contains($entryApi, "empty(\$optionMeta['lock_child'])")
    && str_contains($entryApi, "=== 'final'")
    && str_contains($entryApi, "audit('teacher_class_lock_value_apply'"),
  'the server must validate the lock option, preserve final reports and audit the class action'
);

class_lock_value_bulk_assert(
  substr_count($translations, "'teacher.entry.bulk_lock_all'") === 2
    && substr_count($translations, "'teacher.entry.bulk_lock_confirm'") === 2
    && substr_count($translations, "'teacher.entry.bulk_lock_applied'") === 2,
  'the class lock-value action must be translated in German and English'
);

class_lock_value_bulk_assert(
  str_contains($handbook, '\\index{Sperrwert!für ganze Klasse}')
    && str_contains($handbook, '\\enquote{Für alle: Sperrwert}')
    && str_contains($handbook, 'abgeschlossene Berichte bleiben unverändert'),
  'the handbook must explain the class-wide lock-value action and its safeguards'
);

fwrite(STDOUT, "class_lock_value_bulk_test: OK\n");
