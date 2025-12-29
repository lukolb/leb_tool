<?php
declare(strict_types=1);

function nav_is_active(array $files): bool {
  $current = basename(parse_url((string)($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH));
  return in_array($current, $files, true);
}

function nav_items_for_role(string $role): array {
  if ($role === 'admin') {
    return [
      ['label'=>t('nav.dashboard'), 'href'=>'admin/index.php', 'files'=>['index.php']],

      // Stammdaten
      ['label'=>t('nav.school'), 'href'=>'admin/classes.php', 'files'=>['classes.php','students.php'], 'children'=>[
        ['label'=>t('nav.classes'),  'href'=>'admin/classes.php',  'files'=>['classes.php']],
        ['label'=>t('nav.students'), 'href'=>'admin/students.php', 'files'=>['students.php']],
      ]],

      // Vorlagen & Inhalte
      ['label'=>t('nav.reports'), 'href'=>'admin/templates.php', 'files'=>[
        'templates.php','template_fields.php','template_mappings.php','icon_library.php','student_fields.php','text_snippets.php'
      ], 'children'=>[
        ['label'=>t('nav.templates'),      'href'=>'admin/templates.php',      'files'=>['templates.php','template_fields.php','template_mappings.php']],
        ['label'=>t('nav.option_lists'),   'href'=>'admin/icon_library.php',   'files'=>['icon_library.php']],
        ['label'=>t('nav.student_fields'), 'href'=>'admin/student_fields.php', 'files'=>['student_fields.php']],
        ['label'=>t('nav.text_snippets'),  'href'=>'admin/text_snippets.php',  'files'=>['text_snippets.php']],
      ]],

      ['label'=>t('nav.export'), 'href'=>'admin/export.php', 'files'=>['export.php']],
      ['label'=>t('nav.parent_requests'), 'href'=>'admin/parent_requests.php', 'files'=>['parent_requests.php']],
      ['label'=>t('nav.users'), 'href'=>'admin/users.php', 'files'=>['users.php']],
      ['label'=>t('nav.settings'), 'href'=>'admin/settings.php', 'files'=>['settings.php']],
      ['label'=>t('nav.logout'), 'href'=>'logout.php', 'files'=>['logout.php']],
    ];
  }

  return [
    ['label'=>t('nav.dashboard'), 'href'=>'teacher/index.php', 'files'=>['index.php']],
    ['label'=>t('nav.classes'),  'href'=>'teacher/classes.php',  'files'=>['classes.php']],
    ['label'=>t('nav.entries'), 'href'=>'teacher/entry.php', 'files'=>['entry.php']],
    ['label'=>t('nav.parent_links'), 'href'=>'teacher/parents.php', 'files'=>['parents.php']],
    ['label'=>t('nav.delegations'), 'href'=>'teacher/delegations.php', 'files'=>['delegations.php']],
    ['label'=>t('nav.export'), 'href'=>'teacher/export.php', 'files'=>['export.php']],
    ['label'=>t('nav.logout'), 'href'=>'logout.php', 'files'=>['logout.php']],
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
  $aria = $role === 'admin' ? t('aria.admin_nav') : t('aria.teacher_nav');
  $navItems = nav_items_for_role($role);
  ?>
  <!doctype html>
  <html lang="<?=h(ui_lang())?>">
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
            <?php foreach ($navItems as $item):
              $label = (string)($item['label'] ?? '');
              $href  = (string)($item['href'] ?? '');
              $files = (array)($item['files'] ?? []);
              $children = $item['children'] ?? null;

              $isActive = nav_is_active($files);
              if (is_array($children)) {
                foreach ($children as $ch) {
                  if (nav_is_active((array)($ch['files'] ?? []))) { $isActive = true; break; }
                }
              }

              $activeClass = $isActive ? 'active' : '';
              $hasChildren = is_array($children) && count($children) > 0;
            ?>
              <?php if ($hasChildren): ?>
                <div class="nav-item has-children <?=$activeClass?>">
                  <a class="nav-link <?=$activeClass?>" href="<?=h(url($href))?>">
                    <?=h($label)?> <span class="nav-caret" aria-hidden="true">▾</span>
                  </a>

                  <div class="nav-dropdown" role="menu">
                    <?php foreach ($children as $ch):
                      $chLabel = (string)($ch['label'] ?? '');
                      $chHref  = (string)($ch['href'] ?? '');
                      $chFiles = (array)($ch['files'] ?? []);
                      $chActive = nav_is_active($chFiles) ? 'active' : '';
                    ?>
                      <a class="nav-dd-link <?=$chActive?>" role="menuitem" href="<?=h(url($chHref))?>">
                        <?=h($chLabel)?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <a class="nav-link <?=$activeClass?>" href="<?=h(url($href))?>"><?=h($label)?></a>
              <?php endif; ?>
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
