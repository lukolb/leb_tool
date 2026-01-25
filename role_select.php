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
      throw new RuntimeException('Bitte eine Rolle auswählen.');
    }
    $_SESSION['user']['role'] = (string)$selected;
    audit('role_select', (int)($_SESSION['user']['id'] ?? null), ['role' => (string)$selected]);
    if ((string)$selected === 'admin') {
      redirect('admin/index.php');
    }
    redirect('teacher/index.php');
  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

$b = brand();
$org = $b['org_name'] ?? 'LEG Tool';
$logo = $b['logo_path'] ?? '';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($org)?> – Rolle wählen</title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($b['primary'] ?? '#0b57d0')?>;--secondary:<?=h($b['secondary'] ?? '#111111')?>;}</style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle">Rolle wählen</div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1>Als Rolle fortfahren</h1>

      <?php if ($err): ?>
        <div class="alert danger"><strong><?=h($err)?></strong></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label>Bitte wählen</label>

        <div class="actions">
          <button class="btn primary" type="submit" name="role" value="admin">🛠️ Admin</button>
          <button class="btn secondary" type="submit" name="role" value="teacher">👩‍🏫 Lehrkraft</button>
        </div>
      </form>
    </div>

    <p class="muted">© <?=h($org)?> · <?=h(date('Y'))?></p>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
