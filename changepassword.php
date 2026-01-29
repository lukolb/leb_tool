<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$pdo = db();
$cfg = app_config();
$pepper = $cfg['app']['password_pepper'] ?? '';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $p1 = (string)($_POST['p1'] ?? '');
    $p2 = (string)($_POST['p2'] ?? '');
    if (mb_strlen($p1) < 10) throw new RuntimeException(t('auth.change.password_too_short'));
    if ($p1 !== $p2) throw new RuntimeException(t('auth.change.password_mismatch'));

    $hash = password_hash($p1 . $pepper, PASSWORD_DEFAULT);
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) throw new RuntimeException(t('auth.change.invalid_session'));

    $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
    $st->execute([$hash, $uid]);

    $ok = t('auth.change.ok');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t('auth.change.title'))?></title>
  <?php render_favicons(); ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:24px; max-width:520px;}
    .card{border:1px solid #ddd; border-radius:12px; padding:18px; margin:16px 0;}
    label{display:block; margin:10px 0 6px;}
    input{width:100%; padding:10px; border:1px solid #ccc; border-radius:10px;}
    button{padding:10px 12px; border-radius:10px; border:1px solid #333; background:#111; color:#fff; cursor:pointer;}
    .err{background:#ffe8e8; border:1px solid #ffb3b3; padding:12px; border-radius:10px;}
    .ok{background:#e9ffe8; border:1px solid #b7ffb3; padding:12px; border-radius:10px;}
  </style>
</head>
<body>
  <h1><?=h(t('auth.change.heading'))?></h1>

  <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
  <?php if ($ok): ?><div class="ok"><?=h($ok)?> <a href="<?= h(url('admin/index.php')) ?>"><?=h(t('auth.change.continue'))?></a></div><?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <label><?=h(t('auth.change.password_label'))?></label>
      <input type="password" name="p1" required>
      <label><?=h(t('auth.change.password_repeat_label'))?></label>
      <input type="password" name="p2" required>
      <div style="margin-top:14px;">
        <button type="submit"><?=h(t('auth.change.save'))?></button>
      </div>
    </form>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
