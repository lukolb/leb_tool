<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$err = '';
$ok  = '';
$email = $_POST['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Bitte gültige E-Mail eingeben.');

    $pdo = db();
    $q = $pdo->prepare("SELECT id, display_name, is_active, deleted_at FROM users WHERE email=? LIMIT 1");
    $q->execute([$email]);
    $u = $q->fetch();

    // Security: Immer "ok" melden (kein User enumeration)
    if ($u && $u['deleted_at'] === null && (int)$u['is_active'] === 1) {
      $rawToken = create_password_reset_token((int)$u['id'], 60, true);
      $link = absolute_url('reset_password.php?token=' . $rawToken);

      $sent = send_email(
        $email,
        'LEG Tool – Passwort zurücksetzen',
        build_reset_link_email((string)$u['display_name'], $email, $link)
      );

      audit('forgot_password', (int)$u['id'], ['mail_sent'=>$sent]);
    }

    $ok = 'Wenn diese E-Mail existiert, wurde ein Reset-Link versendet.';
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
  <title><?=h($org)?> – Passwort vergessen</title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($b['primary'] ?? '#0b57d0')?>;--secondary:<?=h($b['secondary'] ?? '#111111')?>;}</style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle">Passwort zurücksetzen</div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1>Passwort vergessen</h1>

      <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label>E-Mail</label>
        <input name="email" type="email" value="<?=h((string)$email)?>" required>
        <div class="actions">
          <button class="btn primary" type="submit">Reset-Link senden</button>
          <a class="btn secondary" href="<?=h(url('login.php'))?>">Zurück zum Login</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>

