<?php
declare(strict_types=1);

function nav_is_active(array $files): bool {
  $current = basename(parse_url((string)($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH));
  return in_array($current, $files, true);
}

function render_admin_header(string $title): void {
  $b = brand();
  $org = $b['org_name'] ?? 'LEG Tool';
  $logo = $b['logo_path'] ?? '';
  $primary = $b['primary'] ?? '#0b57d0';
  $secondary = $b['secondary'] ?? '#111111';

  $vars = "--primary:" . h($primary) . ";--secondary:" . h($secondary) . ";";
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=h($title)?></title>
    <?php render_favicons(); ?>
    <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
    <style>:root{<?= $vars ?>}</style>
  </head>
  <body class="page">
    <div class="topbar">
      <div class="brand">
        <?php if ($logo): ?>
          <img src="<?=h(url($logo))?>" alt="<?=h($org)?>">
        <?php endif; ?>
        <div>
          <div class="brand-title"><?=h($org)?></div>
          <div class="brand-subtitle"><?=h($title)?></div>
        </div>
      </div>
    </div>
    <div class="menu-bar">
      <div class="nav-shell">
        <nav class="nav-menu" aria-label="Admin Navigation">
          <?php
          $items = [
            ['Dashboard', 'admin/index.php', ['index.php']],
            ['Klassen', 'admin/classes.php', ['classes.php']],
            ['Nutzer', 'admin/users.php', ['users.php']],
            ['Schüler', 'admin/students.php', ['students.php']],
            ['Templates', 'admin/templates.php', ['templates.php']],
            ['Felder', 'admin/template_fields.php', ['template_fields.php']],
            ['Mappings', 'admin/template_mappings.php', ['template_mappings.php']],
            ['Optionen', 'admin/option_scales.php', ['option_scales.php']],
            ['Export', 'admin/export.php', ['export.php']],
          ];
          foreach ($items as [$label, $href, $files]):
            $active = nav_is_active($files) ? 'active' : '';
          ?>
            <a class="nav-link <?=$active?>" href="<?=h(url($href))?>"><?=h($label)?></a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>
    <div class="container">
  <?php
}

function render_admin_footer(): void {
  echo "</div></body></html>";
}
