<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$err = '';
$email = $_POST['email'] ?? '';
$pass  = $_POST['password'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, password_hash, display_name, role, is_active, deleted_at FROM users WHERE email=? LIMIT 1");
    $stmt->execute([trim((string)$email)]);
    $u = $stmt->fetch();

    if (!$u || $u['deleted_at'] !== null || (int)$u['is_active'] !== 1) {
      $err = t('auth.login.failed');
    } else {
      $cfg = app_config();
      $pepper = $cfg['app']['password_pepper'] ?? '';
      if ($pepper === '') throw new RuntimeException(t('auth.login.pepper_missing'));

      $hash = (string)($u['password_hash'] ?? '');
      if ($hash === '' || !password_verify((string)$pass . $pepper, $hash)) {
        $err = t('auth.login.failed');
      } else {
        session_regenerate_id(true);
        $actualRole = (string)($u['role'] ?? '');
        $_SESSION['user'] = [
          'id' => (int)$u['id'],
          'email' => $u['email'],
          'display_name' => $u['display_name'],
          'role' => $actualRole,
          'actual_role' => $actualRole,
        ];
        audit('login', (int)$u['id']);
        if ($actualRole === 'admin') {
          redirect('role_select.php');
        }
        redirect('teacher/index.php');
      }
    }
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
  <title><?=h($org)?> – <?=h(t('auth.login.title'))?></title>
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
        <div class="brand-subtitle"><?=h(t('auth.login.brand_subtitle'))?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1><?=h(t('auth.login.heading'))?></h1>

      <?php if ($err): ?>
        <div class="alert danger"><strong><?=h($err)?></strong></div>
      <?php endif; ?>

      <form id="loginForm" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label><?=h(t('auth.login.email_label'))?></label>
        <input name="email" type="email" value="<?=h((string)$email)?>" required>

        <label><?=h(t('auth.login.password_label'))?></label>
        <input name="password" type="password" required>

        <div class="actions">
            <a class="btn primary" type="submit" onclick="document.getElementById('loginForm').submit(); return false;"><?=h(t('auth.login.submit'))?></a>
          <a class="btn secondary" href="<?=h(url('forgot_password.php'))?>"><?=h(t('auth.login.forgot'))?></a>
        </div>
        <div class="alt-login">
          <a href="<?=h(url('student/login.php'))?>"><?=h(t('auth.login.student'))?></a>
        </div>
      </form>
    </div>

    <p class="muted">© <?=h($org)?> · <?=h(date('Y'))?></p>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
