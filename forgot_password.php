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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('auth.forgot.invalid_email'));

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
        t('auth.forgot.mail_subject'),
        build_reset_link_email((string)$u['display_name'], $email, $link)
      );

      audit('forgot_password', (int)$u['id'], ['mail_sent'=>$sent]);
    }

    $ok = t('auth.forgot.ok');
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
  <title><?=h($org)?> – <?=h(t('auth.forgot.title'))?></title>
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
        <div class="brand-subtitle"><?=h(t('auth.forgot.brand_subtitle'))?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1><?=h(t('auth.forgot.heading'))?></h1>

      <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <label><?=h(t('auth.forgot.email_label'))?></label>
        <input name="email" type="email" value="<?=h((string)$email)?>" required>
        <div class="actions">
          <button class="btn primary" type="submit"><?=h(t('auth.forgot.submit'))?></button>
          <a class="btn secondary" href="<?=h(url('login.php'))?>"><?=h(t('auth.forgot.back_to_login'))?></a>
        </div>
      </form>
    </div>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
