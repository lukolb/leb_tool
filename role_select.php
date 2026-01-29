<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_login();

if (!is_actual_admin()) {
  redirect('teacher/index.php');
}

$err = '';
$selected = $_POST['role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    if (!in_array((string)$selected, ['admin', 'teacher'], true)) {
      throw new RuntimeException(t('auth.role_select.error_required'));
    }
    $_SESSION['user']['role'] = (string)$selected;
    audit('role_select', (int)($_SESSION['user']['id'] ?? null), ['role' => (string)$selected]);
    if ((string)$selected === 'admin') {
      redirect('admin/index.php');
    }
    redirect('teacher/index.php');
  } catch (Throwable $e) {
    $err = t('auth.error_prefix', 'Fehler: ') . $e->getMessage();
  }
}

$b = brand();
$org = $b['org_name'] ?? 'LEG Tool';
$logo = $b['logo_path'] ?? '';
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($org)?> – <?=h(t('auth.role_select.title'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>
    :root{--primary:<?=h($b['primary'] ?? '#0b57d0')?>;--secondary:<?=h($b['secondary'] ?? '#111111')?>;}
    .role-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }
    .role-actions .btn {
      min-width: 200px;
      padding: 14px 18px;
      font-size: 1.05rem;
      background: #f5f7ff;
      color: #1f3a8a;
      border: 1px solid #d5dcff;
    }
    .role-actions .btn:hover {
      background: #eaf0ff;
    }
  </style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle"><?=h(t('auth.role_select.brand_subtitle'))?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1><?=h(t('auth.role_select.heading'))?></h1>

      <?php if ($err): ?>
        <div class="alert danger"><strong><?=h($err)?></strong></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label><?=h(t('auth.role_select.label'))?></label>

        <div class="actions role-actions">
          <button class="btn" type="submit" name="role" value="admin">🛠️ <?=h(t('auth.role_select.admin'))?></button>
          <button class="btn" type="submit" name="role" value="teacher">👩‍🏫 <?=h(t('auth.role_select.teacher'))?></button>
        </div>
      </form>
    </div>

    <p class="muted">© <?=h($org)?> · <?=h(date('Y'))?></p>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
