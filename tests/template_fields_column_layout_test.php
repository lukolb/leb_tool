<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  'editor' => 'admin/template_fields.php',
  'translations' => 'shared/translations.php',
  'handbook' => 'docs/leb_tool_benutzerhandbuch.tex',
];
$source = [];
foreach ($paths as $key => $path) {
  $source[$key] = file_get_contents($root . '/' . $path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function template_fields_columns_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$editor = $source['editor'];

template_fields_columns_assert(
  strpos($editor, 'id="columnSettings"') !== false
    && strpos($editor, 'id="columnList"') !== false
    && strpos($editor, 'id="btnShowAllColumns"') !== false
    && strpos($editor, 'id="btnResetColumns"') !== false,
  'the field editor must expose column visibility and reset controls'
);
template_fields_columns_assert(
  substr_count($editor, '<th data-column-key=') >= 14
    && strpos($editor, 'data-column-key="select"') !== false
    && strpos($editor, 'data-column-key="field_name"') !== false,
  'every table header must have a stable column key'
);
template_fields_columns_assert(
  strpos($editor, 'template_fields_columns_v1:${currentUserId}') !== false
    && strpos($editor, 'function loadColumnLayout()') !== false
    && strpos($editor, 'function persistColumnLayout()') !== false,
  'column visibility and widths must persist separately for each administrator'
);
template_fields_columns_assert(
  strpos($editor, "handle.addEventListener('pointerdown'") !== false
    && strpos($editor, "event.key !== 'ArrowLeft' && event.key !== 'ArrowRight'") !== false
    && strpos($editor, "handle.addEventListener('dblclick'") !== false,
  'columns must support pointer resizing, keyboard resizing and width reset'
);
template_fields_columns_assert(
  strpos($editor, 'function applyColumnVisibility()') !== false
    && strpos($editor, 'function applyColumnWidths()') !== false
    && strpos($editor, "columnCells.forEach(([cell, key]) => setColumnCell(cell, key));") !== false,
  'column preferences must be applied to headers and dynamically rendered rows'
);
template_fields_columns_assert(
  substr_count($source['translations'], "'admin.template_fields.columns_title'") === 2
    && substr_count($source['translations'], "'admin.template_fields.columns_hint'") === 2
    && substr_count($source['translations'], "'admin.template_fields.columns_reset'") === 2,
  'column controls must be translated in German and English'
);
template_fields_columns_assert(
  strpos($source['handbook'], '\\enquote{Spalten} nicht benötigte Tabellenspalten ausblenden') !== false
    && strpos($source['handbook'], 'Ansicht zurücksetzen') !== false,
  'the handbook must explain column visibility, resizing and reset behavior'
);

fwrite(STDOUT, "template_fields_column_layout_test: OK\n");
