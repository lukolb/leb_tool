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
    $token = create_password_reset_token($id, 48, true);
    $link = absolute_url('reset_password.php?token=' . urlencode($token));
    $html = build_set_password_email($name, $email, $link);
    send_email($email, t('admin.users.email.create_subject'), $html);
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

      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('admin.users.error.email_invalid'));
      if ($name === '') throw new RuntimeException(t('admin.users.error.name_missing'));

      // prevent duplicates
      $q = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
      $q->execute([$email]);
      if ($q->fetch()) throw new RuntimeException(t('admin.users.error.user_exists'));

      $id = create_user($pdo, $email, $name, $role, true);
      audit('admin_user_create', $currentId, ['user_id'=>$id,'email'=>$email,'role'=>$role]);
      $ok = t('admin.users.status.created');
    }

    elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException(t('admin.users.error.id_missing'));

      $name = normalize_name((string)($_POST['name'] ?? ''));
      $role = normalize_role((string)($_POST['role'] ?? 'teacher'));
      $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

      if ($name === '') throw new RuntimeException(t('admin.users.error.name_missing'));

      // do not allow self-disable
      if ($id === $currentId && $active !== 1) throw new RuntimeException(t('admin.users.error.self_disable'));

      $pdo->prepare("UPDATE users SET display_name=?, role=?, is_active=? WHERE id=?")
          ->execute([$name, $role, $active, $id]);
      audit('admin_user_update', $currentId, ['user_id'=>$id,'role'=>$role,'is_active'=>$active]);
      $ok = t('admin.users.status.updated');
    }

    elseif ($action === 'send_invite') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException(t('admin.users.error.id_missing'));

      $st = $pdo->prepare("SELECT id, email, display_name FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$id]);
      $usr = $st->fetch();
      if (!$usr) throw new RuntimeException(t('admin.users.error.not_found'));

      $token = create_password_reset_token($id, 60, true);
      $link = absolute_url('reset_password.php?token=' . urlencode($token));
      $html = build_set_password_email((string)$usr['display_name'], (string)$usr['email'], $link);
      send_email((string)$usr['email'], t('admin.users.email.reset_subject'), $html);

      audit('admin_user_invite', $currentId, ['user_id'=>$id]);
      $ok = t('admin.users.status.invite_sent');
    }

    elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException(t('admin.users.error.id_missing'));
      if ($id === $currentId) throw new RuntimeException(t('admin.users.error.self_delete'));

      // soft delete
      $pdo->prepare("UPDATE users SET deleted_at=NOW(), is_active=0 WHERE id=?")->execute([$id]);

      // remove class assignments
      $pdo->prepare("DELETE FROM user_class_assignments WHERE user_id=?")->execute([$id]);

      audit('admin_user_delete', $currentId, ['user_id'=>$id]);
      $ok = t('admin.users.status.deleted');
    }

    elseif ($action === 'bulk_import') {
      if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new RuntimeException(t('admin.users.error.csv_missing'));
      }
      $tmp = $_FILES['csv']['tmp_name'];
      $fh = fopen($tmp, 'r');
      if (!$fh) throw new RuntimeException(t('admin.users.error.csv_unreadable'));

      $header = fgetcsv($fh);
      if (!$header) throw new RuntimeException(t('admin.users.error.csv_empty'));

      $map = [];
      foreach ($header as $i => $col) {
        $map[strtolower(trim((string)$col))] = $i;
      }

      foreach (['email','name','role'] as $req) {
        if (!isset($map[$req])) {
          throw new RuntimeException(str_replace('{column}', $req, t('admin.users.error.column_missing')));
        }
      }

      $created = 0;
      $skipped = 0;
      $errors = [];

      while (($row = fgetcsv($fh)) !== false) {
        $email = normalize_email((string)($row[$map['email']] ?? ''));
        $name  = normalize_name((string)($row[$map['name']] ?? ''));
        $role  = normalize_role((string)($row[$map['role']] ?? 'teacher'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
          $errors[] = str_replace('{row}', implode(',', $row), t('admin.users.error.invalid_row'));
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

render_admin_header(t('admin.users.title'));
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
  <h1><?=h(t('admin.users.title'))?></h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <?php if ($bulk): ?>
    <div class="alert">
      <?=h(str_replace(['{created}', '{skipped}'], [ (string)$bulk['created'], (string)$bulk['skipped'] ], t('admin.users.bulk.summary')))?>
      <?php if (!empty($bulk['errors'])): ?>
        <details style="margin-top:8px;">
          <summary><?=h(str_replace('{count}', (string)count($bulk['errors']), t('admin.users.bulk.show_errors')))?></summary>
          <ul>
            <?php foreach ($bulk['errors'] as $e): ?><li><?=h((string)$e)?></li><?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="grid" style="grid-template-columns: 1fr; gap:14px;">
    <div class="panel" style="border-bottom: solid lightgray; padding-bottom: 20px;">
      <h2 style="margin-top:0;"><?=h(t('admin.users.create_heading'))?></h2>
      <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 140px auto; gap:12px; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create">

        <div>
          <label><?=h(t('admin.users.label.email'))?></label>
          <input name="email" type="email" required>
        </div>
        <div>
          <label><?=h(t('admin.users.label.name'))?></label>
          <input name="name" type="text" required>
        </div>
        <div>
          <label><?=h(t('admin.users.label.role'))?></label>
          <select name="role">
            <option value="teacher"><?=h(t('admin.users.role.teacher'))?></option>
            <option value="admin"><?=h(t('admin.users.role.admin'))?></option>
          </select>
        </div>
        <div class="actions" style="justify-content:flex-start;">
          <button class="btn primary" type="submit"><?=h(t('admin.users.action.create'))?></button>
        </div>
      </form>
      <div class="muted" style="margin-top:8px;"><?=h(t('admin.users.create_hint'))?></div>
    </div>

    <div class="panel">
      <h2 style="margin-top:0;"><?=h(t('admin.users.bulk.heading'))?></h2>
      <div class="muted"><?=t('admin.users.bulk.columns')?></div>
      <div class="actions" style="justify-content:flex-start; margin:10px 0;">
        <a class="btn secondary" href="<?=h(url('admin/users.php?download=csv_template'))?>"><?=h(t('admin.users.bulk.download_template'))?></a>
      </div>
      <form method="post" enctype="multipart/form-data" id="bulkImportForm">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="bulk_import">

        <div class="actions actions-row">
          <input class="file-input" type="file" name="csv" accept=".csv,text/csv" required>

          <a href="#"
             class="btn primary"
             onclick="this.closest('form').submit(); return false;">
             <?=h(t('admin.users.bulk.import_start'))?>
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('admin.users.list_heading'))?></h2>

  <div class="alert">
    <?=str_replace('{link}', '<a href="' . h(url('admin/classes.php')) . '">' . h(t('admin.users.classes_link')) . '</a>', t('admin.users.classes_hint'))?>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th><?=h(t('admin.users.table.name'))?></th>
        <th><?=h(t('admin.users.table.email'))?></th>
        <th><?=h(t('admin.users.table.role'))?></th>
        <th><?=h(t('admin.users.table.active'))?></th>
        <th><?=h(t('admin.users.table.actions'))?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $usr): ?>
      <tr>
        <td><?=h((string)$usr['display_name'])?></td>
        <td><?=h((string)$usr['email'])?></td>
        <td><?=h((string)$usr['role'])?></td>
        <td><?=((int)$usr['is_active']===1) ? '<span class="badge">' . h(t('admin.users.badge.yes')) . '</span>' : '<span class="badge">' . h(t('admin.users.badge.no')) . '</span>'?></td>
        <td>
          <details>
            <summary id="userEditBtn" class="btn secondary" style="display:inline-block; cursor:pointer;"><?=h(t('admin.users.action.edit'))?></summary>
            <div class="panel" style="margin-top:10px;">
              <form method="post" class="grid" style="grid-template-columns: 1fr 140px 140px auto; gap:10px; align-items:end;">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">

                <div>
                  <label><?=h(t('admin.users.label.name'))?></label>
                  <input name="name" type="text" value="<?=h((string)$usr['display_name'])?>" required>
                </div>

                <div>
                  <label><?=h(t('admin.users.label.role'))?></label>
                  <select name="role">
                    <option value="teacher" <?=((string)$usr['role']==='teacher')?'selected':''?>><?=h(t('admin.users.role.teacher'))?></option>
                    <option value="admin" <?=((string)$usr['role']==='admin')?'selected':''?>><?=h(t('admin.users.role.admin'))?></option>
                  </select>
                </div>

                <div>
                  <label><?=h(t('admin.users.label.active'))?></label>
                  <select name="is_active">
                    <option value="1" <?=((int)$usr['is_active']===1)?'selected':''?>><?=h(t('admin.users.badge.yes'))?></option>
                    <option value="0" <?=((int)$usr['is_active']===0)?'selected':''?>><?=h(t('admin.users.badge.no'))?></option>
                  </select>
                </div>

                <div class="actions" style="justify-content:flex-start;">
                  <button class="btn primary" type="submit"><?=h(t('admin.users.action.save'))?></button>
                </div>
              </form>

              <div class="actions" style="justify-content:flex-start; margin-top:10px;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="send_invite">
                  <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">
                  <button class="btn secondary" type="submit"><?=h(t('admin.users.action.send_link'))?></button>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('<?=h(t('admin.users.confirm_delete'))?>');">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=h((string)$usr['id'])?>">
                  <button class="btn danger" type="submit"><?=h(t('admin.users.action.delete'))?></button>
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
