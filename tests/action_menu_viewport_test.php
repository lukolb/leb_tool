<?php
declare(strict_types=1);

$phpSource = file_get_contents(dirname(__DIR__) . '/admin/classes.php');
$cssSource = file_get_contents(dirname(__DIR__) . '/assets/app.css');
if ($phpSource === false || $cssSource === false) {
  fwrite(STDERR, "Could not read action menu sources\n");
  exit(1);
}

function menu_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

menu_assert(strpos($cssSource, '.action-dropdown-menu') !== false, 'action menu styles must exist');
menu_assert(strpos($cssSource, 'position:fixed') !== false, 'action menu must be positioned relative to the viewport');
menu_assert(strpos($cssSource, 'overflow-y:auto') !== false, 'oversized action menus must remain scrollable');
menu_assert(strpos($phpSource, 'const spaceBelow') !== false, 'available space below the button must be measured');
menu_assert(strpos($phpSource, 'const spaceAbove') !== false, 'available space above the button must be measured');
menu_assert(strpos($phpSource, "menu.dataset.placement = openUpward ? 'top' : 'bottom'") !== false, 'bottom-row menus must open upward');
menu_assert(strpos($phpSource, 'viewportHeight - margin - renderedHeight') !== false, 'menu bottom edge must be clamped to the viewport');

fwrite(STDOUT, "action_menu_viewport_test: OK\n");
