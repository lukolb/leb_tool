<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$token = $_GET['token'] ?? '';
$err = '';
$ok  = '';
$showForm = false;
$userId = null;
$userEmail = '';
$userName = '';

function load_token_user(string $rawToken): array {
  $pdo = db();
  $hash = hash('sha256', $rawToken);

  $q = $pdo->prepare(
    "SELECT prt.id AS token_id, prt.user_id, prt.expires_at, prt.used_at,
            u.email, u.display_name, u.is_active, u.deleted_at
     FROM password_reset_tokens prt
     JOIN users u ON u.id = prt.user_id
     WHERE prt.token_hash=? LIMIT 1"
  );
  $q->execute([$hash]);
  $row = $q->fetch();
  if (!$row) throw new RuntimeException('Ungültiger oder abgelaufener Link.');
  if ($row['used_at'] !== null) throw new RuntimeException('Dieser Link wurde bereits verwendet.');
  if (strtotime((string)$row['expires_at']) < time()) throw new RuntimeException('Dieser Link ist abgelaufen.');
  if ($row['deleted_at'] !== null || (int)$row['is_active'] !== 1) throw new RuntimeException('Konto ist deaktiviert oder gelöscht.');
  return $row;
}

if (!is_string($token) || strlen($token) < 20) {
  $err = 'Ungültiger Link.';
} else {
  try {
    $row = load_token_user($token);
    $showForm = true;
    $userId = (int)$row['user_id'];
    $userEmail = (string)$row['email'];
    $userName = (string)$row['display_name'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      csrf_verify();
      $p1 = (string)($_POST['password'] ?? '');
      $p2 = (string)($_POST['password2'] ?? '');

      if (strlen($p1) < 10) throw new RuntimeException('Passwort muss mindestens 10 Zeichen haben.');
      if ($p1 !== $p2) throw new RuntimeException('Passwörter stimmen nicht überein.');

      $cfg = app_config();
      $pepper = $cfg['app']['password_pepper'] ?? '';
      if ($pepper === '') throw new RuntimeException('Konfiguration (password_pepper) fehlt.');

      $hash = password_hash($p1 . $pepper, PASSWORD_DEFAULT);
      $pdo = db();

      $pdo->beginTransaction();
      try {
        // mark token as used
        $pdo->prepare("UPDATE password_reset_tokens SET used_at=NOW() WHERE token_hash=? AND used_at IS NULL")->execute([hash('sha256', $token)]);

        // set password
        $pdo->prepare("UPDATE users SET password_hash=?, password_set_at=NOW(), must_change_password=0 WHERE id=?")->execute([$hash, $userId]);

        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }

      audit('password_set', $userId);
      $ok = 'Passwort wurde gesetzt. Du kannst dich jetzt anmelden.';
      $showForm = false;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
    $showForm = false;
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
  <title><?=h($org)?> – Passwort setzen</title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($b['primary'] ?? '#0b57d0')?>;--secondary:<?=h($b['secondary'] ?? '#111111')?>;}</style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle">Passwort setzen</div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1>Passwort setzen</h1>

      <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

      <?php if ($showForm): ?>
        <p class="muted">
          Konto: <strong><?=h($userEmail)?></strong>
        </p>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <label>Neues Passwort (min. 10 Zeichen)</label>
          <input name="password" type="password" required>

          <label>Passwort wiederholen</label>
          <input name="password2" type="password" required>

          <div class="actions">
            <button class="btn primary" type="submit">Speichern</button>
            <a class="btn secondary" href="<?=h(url('login.php'))?>">Abbrechen</a>
          </div>
        </form>
      <?php else: ?>
        <div class="actions">
          <a class="btn primary" href="<?=h(url('login.php'))?>">Zum Login</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
