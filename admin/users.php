<?php
// admin/users.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok  = '';

$currentId = (int)(current_user()['id'] ?? 0);

// CSV Template download
if (($_GET['download'] ?? '') === 'csv_template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="lebtool_users_template.csv"');
  echo "email,name,role\n";
  echo "max.mustermann@gisny.org,Max Mustermann,teacher\n";
  echo "admin.user@gisny.org,Admin User,admin\n";
  exit;
}

// Flash bulk result
$bulk = $_SESSION['bulk_import_result'] ?? null;
if ($bulk) unset($_SESSION['bulk_import_result']);

function normalize_email(string $s): string {
  return strtolower(trim($s));
}
function normalize_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}
function normalize_role(string $s): string {
  $s = strtolower(trim($s));
  return in_array($s, ['admin','teacher'], true) ? $s : 'teacher';
}

function create_user(PDO $pdo, string $email, string $name, string $role, bool $sendInvite = true): int {
  // users can be created without password, they set it via email token
  $pdo->prepare("INSERT INTO users (email, display_name, role, is_active) VALUES (?, ?, ?, 1)")
      ->execute([$email, $name, $role]);
  $id = (int)$pdo->lastInsertId();

  if ($sendInvite) {
    $token = create_password_reset_token($id, 60, true);
    $link = absolute_url('reset_password.php?token=' . urlencode($token));
    $html = build_set_password_email($name, $email, $link);
    send_email($email, 'Konto erstellen – Passwort setzen', $html);
  }
  return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create') {
      $email = normalize_email((string)($_POST['email'] ?? ''));
      $name  = normalize_name((string)($_POST['name'] ?? ''));
      $role  = normalize_role((string)($_POST['role'] ?? 'teacher'));

      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-Mail ungültig.');
      if ($name === '') throw new RuntimeException('Name fehlt.');

      // prevent duplicates
      $q = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
      $q->execute([$email]);
      if ($q->fetch()) throw new RuntimeException('User existiert bereits.');

      $id = create_user($pdo, $email, $name, $role, true);
      audit('admin_user_create', $currentId, ['user_id'=>$id,'email'=>$email,'role'=>$role]);
      $ok = 'User angelegt (Einladungs-Mail wurde gesendet).';
    }

    elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID fehlt.');

      $name = normalize_name((string)($_POST['name'] ?? ''));
      $role = normalize_role((string)($_POST['role'] ?? 'teacher'));
      $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

      if ($name === '') throw new RuntimeException('Name fehlt.');

      // do not allow self-disable
      if ($id === $currentId && $active !== 1) throw new RuntimeException('Du kannst dich nicht selbst deaktivieren.');

      $pdo->prepare("UPDATE users SET display_name=?, role=?, is_active=? WHERE id=?")
          ->execute([$name, $role, $active, $id]);
      audit('admin_user_update', $currentId, ['user_id'=>$id,'role'=>$role,'is_active'=>$active]);
      $ok = 'User aktualisiert.';
    }

    elseif ($action === 'send_invite') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID fehlt.');

      $st = $pdo->prepare("SELECT id, email, display_name FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$id]);
      $usr = $st->fetch();
      if (!$usr) throw new RuntimeException('User nicht gefunden.');

      $token = create_password_reset_token($id, 60, true);
      $link = absolute_url('reset_password.php?token=' . urlencode($token));
      $html = build_set_password_email((string)$usr['display_name'], (string)$usr['email'], $link);
      send_email((string)$usr['email'], 'Passwort setzen', $html);

      audit('admin_user_invite', $currentId, ['user_id'=>$id]);
      $ok = 'Einladungs-Mail wurde gesendet.';
    }

    elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID fehlt.');
      if ($id === $currentId) throw new RuntimeException('Du kannst dich nicht selbst löschen.');

      // soft delete
      $pdo->prepare("UPDATE users SET deleted_at=NOW(), is_active=0 WHERE id=?")->execute([$id]);

      // remove class assignments
      $pdo->prepare("DELETE FROM user_class_assignments WHERE user_id=?")->execute([$id]);

      audit('admin_user_delete', $currentId, ['user_id'=>$id]);
      $ok = 'User gelöscht.';
    }

    elseif ($action === 'bulk_import') {
      if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new RuntimeException('CSV fehlt.');
      }
      $tmp = $_FILES['csv']['tmp_name'];
      $fh = fopen($tmp, 'r');
      if (!$fh) throw new RuntimeException('CSV kann nicht gelesen werden.');

      $header = fgetcsv($fh);
      if (!$header) throw new RuntimeException('CSV ist leer.');

      $map = [];
      foreach ($header as $i => $col) {
        $map[strtolower(trim((string)$col))] = $i;
      }

      foreach (['email','name','role'] as $req) {
        if (!isset($map[$req])) throw new RuntimeException("Spalte fehlt: {$req}");
      }

      $created = 0;
      $skipped = 0;
      $errors = [];

      while (($row = fgetcsv($fh)) !== false) {
        $email = normalize_email((string)($row[$map['email']] ?? ''));
        $name  = normalize_name((string)($row[$map['name']] ?? ''));
        $role  = normalize_role((string)($row[$map['role']] ?? 'teacher'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
          $errors[] = "Ungültige Zeile: " . implode(',', $row);
          continue;
        }

        $q = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
        $q->execute([$email]);
        if ($q->fetch()) { $skipped++; continue; }

        try {
          create_user($pdo, $email, $name, $role, true);
          $created++;
        } catch (Throwable $e) {
          $errors[] = "{$email}: " . $e->getMessage();
        }
      }
      fclose($fh);

      audit('admin_user_bulk_import', $currentId, ['created'=>$created,'skipped'=>$skipped,'errors'=>count($errors)]);
      $_SESSION['bulk_import_result'] = ['created'=>$created,'skipped'=>$skipped,'errors'=>$errors];
      redirect('admin/users.php');
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Users list
$users = $pdo->query(
  "SELECT id, email, display_name, role, is_active, created_at
   FROM users
   WHERE deleted_at IS NULL
   ORDER BY role DESC, display_name ASC"
)->fetchAll();

render_admin_header('User');
?>

<style>
    .actions-row {
        display: flex;
        align-items: center;
        gap: 10px;
      }

      .actions-row .file-input {
        max-width: 260px; /* optional */
      }
</style>

<div class="card">
  <h1>User</h1>
</div>

<div class="card">
  <?php if ($bulk): ?>
    <div class="alert">
      Bulk-Import: erstellt <?=h((string)$bulk['created'])?>, übersprungen <?=h((string)$bulk['skipped'])?>.
      <?php if (!empty($bulk['errors'])): ?>
        <details style="margin-top:8px;">
          <summary>Fehler anzeigen (<?=count($bulk['errors'])?>)</summary>
          <ul>
            <?php foreach ($bulk['errors'] as $e): ?><li><?=h((string)$e)?></li><?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="grid" style="grid-template-columns: 1fr; gap:14px;">
    <div class="panel" style="border-bottom: solid lightgray; padding-bottom: 20px;">
      <h2 style="margin-top:0;">Neuen User anlegen</h2>
      <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 140px auto; gap:12px; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create">

        <div>
          <label>E-Mail</label>
          <input name="email" type="email" required>
        </div>
        <div>
          <label>Name</label>
          <input name="name" type="text" required>
        </div>
        <div>
          <label>Rolle</label>
          <select name="role">
            <option value="teacher">teacher</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div class="actions" style="justify-content:flex-start;">
          <button class="btn primary" type="submit">Anlegen</button>
        </div>
      </form>
      <div class="muted" style="margin-top:8px;">Nach dem Anlegen wird automatisch eine E-Mail zum Setzen des Passworts gesendet.</div>
    </div>

    <div class="panel">
      <h2 style="margin-top:0;">Bulk Import (CSV)</h2>
      <div class="muted">Spalten: <code>email,name,role</code></div>
      <div class="actions" style="justify-content:flex-start; margin:10px 0;">
        <a class="btn secondary" href="<?=h(url('admin/users.php?download=csv_template'))?>">CSV-Template herunterladen</a>
      </div>
      <form method="post" enctype="multipart/form-data" id="bulkImportForm">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="bulk_import">

        <div class="actions actions-row">
          <input class="file-input" type="file" name="csv" accept=".csv,text/csv" required>

          <a href="#"
             class="btn primary"
             onclick="this.parentNode.submit(); return false;">
             Import starten
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;">Bestehende User</h2>

  <div class="alert">
    Klassen-Zuordnungen werden in <a href="<?=h(url('admin/classes.php'))?>">Klassen</a> verwaltet.
  </div>

  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>E-Mail</th>
        <th>Rolle</th>
        <th>Aktiv</th>
        <th>Aktionen</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $usr): ?>
      <tr>
        <td><?=h((string)$usr['display_name'])?></td>
        <td><?=h((string)$usr['email'])?></td>
        <td><?=h((string)$usr['role'])?></td>
        <td><?=((int)$usr['is_active']===1) ? '<span class="badge">ja</span>' : '<span class="badge">nein</span>'?></td>
        <td>
          <details>
            <summary id="userEditBtn" class="btn secondary" style="display:inline-block; cursor:pointer;">Bearbeiten</summary>
            <div class="panel" style="margin-top:10px;">
              <form method="post" class="grid" style="grid-template-columns: 1fr 140px 140px auto; gap:10px; align-items:end;">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">

                <div>
                  <label>Name</label>
                  <input name="name" type="text" value="<?=h((string)$usr['display_name'])?>" required>
                </div>

                <div>
                  <label>Rolle</label>
                  <select name="role">
                    <option value="teacher" <?=((string)$usr['role']==='teacher')?'selected':''?>>teacher</option>
                    <option value="admin" <?=((string)$usr['role']==='admin')?'selected':''?>>admin</option>
                  </select>
                </div>

                <div>
                  <label>Aktiv</label>
                  <select name="is_active">
                    <option value="1" <?=((int)$usr['is_active']===1)?'selected':''?>>ja</option>
                    <option value="0" <?=((int)$usr['is_active']===0)?'selected':''?>>nein</option>
                  </select>
                </div>

                <div class="actions" style="justify-content:flex-start;">
                  <button class="btn primary" type="submit">Speichern</button>
                </div>
              </form>

              <div class="actions" style="justify-content:flex-start; margin-top:10px;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="send_invite">
                  <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">
                  <button class="btn secondary" type="submit">Passwort-Link senden</button>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('User wirklich löschen? (Zuordnungen werden entfernt)');">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">
                  <button class="btn danger" type="submit">Löschen</button>
                </form>
              </div>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
render_admin_footer();
