<?php
declare(strict_types=1);

function render_teacher_header(string $title): void {
  $b = brand();
  $org = (string)($b['org_name'] ?? 'LEG Tool');
  $logo = (string)($b['logo_path'] ?? '');
  $primary = (string)($b['primary'] ?? '#0b57d0');
  $secondary = (string)($b['secondary'] ?? '#111111');

  $vars = "--primary:" . h($primary) . ";--secondary:" . h($secondary) . ";";
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=h($title)?></title>
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
    <div class="container">
  <?php
}

function render_teacher_footer(): void {
  echo "</div></body></html>";
}
