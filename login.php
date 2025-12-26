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
      $err = 'Login fehlgeschlagen.';
    } else {
      $cfg = app_config();
      $pepper = $cfg['app']['password_pepper'] ?? '';
      if ($pepper === '') throw new RuntimeException('password_pepper fehlt in config.php');

      $hash = (string)($u['password_hash'] ?? '');
      if ($hash === '' || !password_verify((string)$pass . $pepper, $hash)) {
        $err = 'Login fehlgeschlagen.';
      } else {
        session_regenerate_id(true);
        $_SESSION['user'] = [
          'id' => (int)$u['id'],
          'email' => $u['email'],
          'display_name' => $u['display_name'],
          'role' => $u['role'],
        ];
        audit('login', (int)$u['id']);
        if (($u['role'] ?? '') === 'admin') {
          redirect('admin/index.php');
        }
        redirect('teacher/index.php');
      }
    }
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
  <title><?=h($org)?> – Login</title>
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
        <div class="brand-subtitle">Login</div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1>Login</h1>

      <?php if ($err): ?>
        <div class="alert danger"><strong><?=h($err)?></strong></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label>E-Mail</label>
        <input name="email" type="email" value="<?=h((string)$email)?>" required>

        <label>Passwort</label>
        <input name="password" type="password" required>

        <div class="actions">
          <button class="btn primary" type="submit">Anmelden</button>
          <a class="btn secondary" href="<?=h(url('forgot_password.php'))?>">Passwort vergessen?</a>
        </div>
      </form>
    </div>

    <p class="muted">© <?=h($org)?> · <?=h(date('Y'))?></p>
  </div>
</body>
</html>
