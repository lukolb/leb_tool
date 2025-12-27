<?php
declare(strict_types=1);

function nav_is_active(array $files): bool {
  $current = basename(parse_url((string)($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH));
  return in_array($current, $files, true);
}

function nav_items_for_role(string $role): array {
  if ($role === 'admin') {
    return [
      ['Dashboard', 'admin/index.php', ['index.php']],
      ['Klassen', 'admin/classes.php', ['classes.php']],
      ['Schüler', 'admin/students.php', ['students.php']],
      ['Templates', 'admin/templates.php', ['templates.php']],
      ['Options-Listen', 'admin/icon_library.php', ['icon_library.php']],
      ['Export', 'admin/export.php', ['export.php']],
      ['Nutzer', 'admin/users.php', ['users.php']],
      ['Einstellungen', 'admin/settings.php', ['settings.php']],
      ['Abmelden', 'logout.php', ['logout.php']],
    ];
  }

  return [
    ['Dashboard', 'teacher/index.php', ['index.php']],
    ['Klassen', 'teacher/classes.php', ['classes.php', 'students.php']],
    ['Eingaben', 'teacher/entry.php', ['entry.php']],
    ['Delegationen', 'teacher/delegations.php', ['delegations.php']],
    ['Export', 'teacher/export.php', ['export.php']],
    ['Abmelden', 'logout.php', ['logout.php']],
  ];
}

function render_role_header(string $title): void {
  $role = get_role();

  $b = brand();
  $org = (string)($b['org_name'] ?? 'LEG Tool');
  $logo = (string)($b['logo_path'] ?? '');
  $primary = (string)($b['primary'] ?? '#0b57d0');
  $secondary = (string)($b['secondary'] ?? '#111111');

  $vars = "--primary:" . h($primary) . ";--secondary:" . h($secondary) . ";";
  $aria = $role === 'admin' ? 'Admin Navigation' : 'Lehrkraft Navigation';
  $navItems = nav_items_for_role($role);
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
      <div class="fixedHeader">
    <div class="topbar">
      <div class="brand">
        <?php if ($logo): ?>
          <img src="<?=h(url($logo))?>" alt="<?=h($org)?>">
        <?php endif; ?>
        <div>
          <div class="brand-title"><?=h($org)?></div>
          <div class="brand-subtitle"><?=h($title)?></div>
        </div>

        <?php if ($role !== 'admin'): ?>
          <?php $lang = ui_lang(); ?>
          <div class="lang-switch" aria-label="Sprache wechseln">
            <a class="lang <?= $lang==='de' ? 'active' : '' ?>" href="<?=h(url_with_lang('de'))?>" title="Deutsch">🇩🇪</a>
            <a class="lang <?= $lang==='en' ? 'active' : '' ?>" href="<?=h(url_with_lang('en'))?>" title="English">🇬🇧</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="menu-bar">
      <div class="nav-shell">
        <nav class="nav-menu" aria-label="<?=h($aria)?>">
          <?php foreach ($navItems as [$label, $href, $files]):
            $active = nav_is_active($files) ? 'active' : '';
          ?>
            <a class="nav-link <?=$active?>" href="<?=h(url($href))?>"><?=h($label)?></a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>
      </div>
          <div class="container">
  <?php
}

function render_role_footer(): void {
  echo "</div></body></html>";
}

function render_admin_header(string $title): void { render_role_header($title); }
function render_admin_footer(): void { render_role_footer(); }
function render_teacher_header(string $title): void { render_role_header($title); }
function render_teacher_footer(): void { render_role_footer(); }
