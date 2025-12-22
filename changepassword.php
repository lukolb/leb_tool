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
  csrf_verify();
  $p1 = $_POST['p1'] ?? '';
  $p2 = $_POST['p2'] ?? '';

  if ($pepper === '') $err = 'Konfiguration fehlt.';
  elseif (strlen($p1) < 10) $err = 'Passwort muss mindestens 10 Zeichen haben.';
  elseif ($p1 !== $p2) $err = 'Passwörter stimmen nicht überein.';
  else {
    $hash = password_hash($p1 . $pepper, PASSWORD_DEFAULT);
    $uid = (int)current_user()['id'];

    $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
    $stmt->execute([$hash, $uid]);

    audit('user_change_password', $uid);
    $ok = 'Passwort wurde geändert.';
  }
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Passwort ändern</title>
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
  <h1>Passwort ändern</h1>

  <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
  <?php if ($ok): ?><div class="ok"><?=h($ok)?> <a href="<?= h(url('admin/index.php')) ?>">Weiter</a></div><?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <label>Neues Passwort (min. 10 Zeichen)</label>
      <input type="password" name="p1" required>
      <label>Neues Passwort wiederholen</label>
      <input type="password" name="p2" required>
      <div style="margin-top:14px;">
        <button type="submit">Speichern</button>
      </div>
    </form>
  </div>
</body>
</html>

