<?php
declare(strict_types=1);

$phpSource = file_get_contents(dirname(__DIR__) . '/admin/classes.php');
$studentSource = file_get_contents(dirname(__DIR__) . '/teacher/students.php');
$templateSource = file_get_contents(dirname(__DIR__) . '/admin/templates.php');
$cssSource = file_get_contents(dirname(__DIR__) . '/assets/app.css');
if ($phpSource === false || $studentSource === false || $templateSource === false || $cssSource === false) {
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
menu_assert(strpos($phpSource, "menu.style.position = 'fixed'") !== false, 'inline positioning must override an older cached stylesheet');
menu_assert(strpos($phpSource, "menu.dataset.placement = openUpward ? 'top' : 'bottom'") !== false, 'bottom-row menus must open upward');
menu_assert(strpos($phpSource, 'viewportHeight - margin - renderedHeight') !== false, 'menu bottom edge must be clamped to the viewport');
menu_assert(strpos($studentSource, "menu.style.position = 'fixed'") !== false, 'student action menus must use viewport-relative fixed positioning');
menu_assert(strpos($studentSource, 'const spaceBelow') !== false && strpos($studentSource, 'const spaceAbove') !== false, 'student action menus must choose the visible side of the trigger');
menu_assert(strpos($studentSource, 'viewportHeight - margin - renderedHeight') !== false, 'student action menus must be clamped to the viewport');
menu_assert(strpos($studentSource, 'rect.bottom + scrollY') === false, 'fixed student action menus must not add the document scroll offset');
menu_assert(strpos($templateSource, "menu.style.position = 'fixed'") !== false, 'template action menus must use viewport-relative fixed positioning');
menu_assert(strpos($templateSource, 'const spaceBelow') !== false && strpos($templateSource, 'const spaceAbove') !== false, 'template action menus must choose the visible side of the trigger');
menu_assert(strpos($templateSource, "menu.dataset.placement = openUpward ? 'top' : 'bottom'") !== false, 'bottom template rows must open their action menus upward');
menu_assert(strpos($templateSource, 'viewportHeight - margin - renderedHeight') !== false, 'template action menus must be clamped to the viewport');
menu_assert(strpos($templateSource, 'rect.bottom + scrollY') === false, 'fixed template action menus must not add the document scroll offset');
menu_assert(strpos($templateSource, 'name="action" value="rename"') !== false, 'template action menus must offer renaming');
menu_assert(strpos($templateSource, 'UPDATE templates SET name=?') !== false, 'template renaming must update the stored template name');

fwrite(STDOUT, "action_menu_viewport_test: OK\n");
