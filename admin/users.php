<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok  = '';

$currentId = (int)current_user()['id'];

// CSV Template download
if (($_GET['download'] ?? '') === 'csv_template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="legtool_users_template.csv"');
  echo "email,name,role,school_year,class\n";
  echo "max.mustermann@gisny.org,Max Mustermann,teacher,2025/26,4a\n";
  echo "admin.user@gisny.org,Admin User,admin,2025/26,\n";
  exit;
}

// Flash bulk result
$bulk = $_SESSION['bulk_import_result'] ?? null;
if ($bulk) unset($_SESSION['bulk_import_result']);

function normalize_school_year(string $s): string {
  $s = trim($s);
  return $s;
}
function normalize_class_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

function find_or_create_class(PDO $pdo, string $schoolYear, string $className): int {
  $schoolYear = normalize_school_year($schoolYear);
  $className  = normalize_class_name($className);
  if ($schoolYear === '' || $className === '') {
    throw new RuntimeException('school_year oder class fehlt.');
  }

  $q = $pdo->prepare("SELECT id FROM classes WHERE school_year=? AND name=? LIMIT 1");
  $q->execute([$schoolYear, $className]);
  $row = $q->fetch();
  if ($row) return (int)$row['id'];

  $ins = $pdo->prepare("INSERT INTO classes (school_year, name) VALUES (?, ?)");
  $ins->execute([$schoolYear, $className]);
  return (int)$pdo->lastInsertId();
}

function assign_user_to_class(PDO $pdo, int $userId, int $classId): void {
  $pdo->prepare(
    "INSERT IGNORE INTO user_class_assignments (user_id, class_id) VALUES (?, ?)"
  )->execute([$userId, $classId]);
}

function unassign_user_from_class(PDO $pdo, int $userId, int $classId): void {
  $pdo->prepare("DELETE FROM user_class_assignments WHERE user_id=? AND class_id=?")->execute([$userId, $classId]);
}

// ==========================
// POST actions
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  try {
    // CREATE / RESTORE (Option A)
    if ($action === 'create') {
      $email = trim($_POST['email'] ?? '');
      $name  = trim($_POST['name'] ?? '');
      $role  = strtolower(trim($_POST['role'] ?? 'teacher'));

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Bitte gültige E-Mail eingeben.');
      if ($name === '') throw new RuntimeException('Name fehlt.');
      if (!in_array($role, ['admin','teacher'], true)) $role = 'teacher';

      $cfg = app_config();
      $pepper = $cfg['app']['password_pepper'] ?? '';
      if ($pepper === '') throw new RuntimeException('Konfiguration (password_pepper) fehlt.');

      $check = $pdo->prepare("SELECT id, deleted_at FROM users WHERE email=? LIMIT 1");
      $check->execute([$email]);
      $existing = $check->fetch();

      $random = bin2hex(random_bytes(16));
      $hash = password_hash($random . $pepper, PASSWORD_DEFAULT);

      if (!$existing) {
        $stmt = $pdo->prepare(
          "INSERT INTO users (email, password_hash, password_set_at, display_name, role, is_active, must_change_password, deleted_at)
           VALUES (?, ?, NULL, ?, ?, 1, 0, NULL)"
        );
        $stmt->execute([$email, $hash, $name, $role]);
        $userId = (int)$pdo->lastInsertId();
        audit('user_create', $currentId, ['user_id'=>$userId,'email'=>$email,'role'=>$role]);
      } else {
        $userId = (int)$existing['id'];
        if ($existing['deleted_at'] === null) throw new RuntimeException('Diese E-Mail existiert bereits.');

        $upd = $pdo->prepare(
          "UPDATE users
           SET display_name=?,
               role=?,
               is_active=1,
               deleted_at=NULL,
               password_hash=?,
               password_set_at=NULL,
               must_change_password=0
           WHERE id=?"
        );
        $upd->execute([$name, $role, $hash, $userId]);
        audit('user_restore', $currentId, ['user_id'=>$userId,'email'=>$email,'role'=>$role]);
      }

      $rawToken = create_password_reset_token($userId, 1440, true);
      $link = absolute_url('reset_password.php?token=' . $rawToken);

      $sent = send_email($email, 'Dein LEG Tool Konto – Passwort setzen', build_set_password_email($name, $email, $link));

      $ok = $sent
        ? 'Nutzer wurde angelegt bzw. reaktiviert und per E-Mail benachrichtigt.'
        : "Nutzer wurde angelegt/reaktiviert, aber E-Mail konnte nicht versendet werden.\nLink:\n{$link}";
    }

    // UPDATE ROLE
    elseif ($action === 'update_role') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $newRole = strtolower(trim($_POST['role'] ?? 'teacher'));
      if ($userId <= 0) throw new RuntimeException('Ungültige user_id.');
      if (!in_array($newRole, ['admin','teacher'], true)) throw new RuntimeException('Ungültige Rolle.');

      if ($userId === $currentId && $newRole !== 'admin') {
        throw new RuntimeException('Du kannst deine eigene Admin-Rolle nicht entfernen.');
      }

      $q = $pdo->prepare("SELECT deleted_at FROM users WHERE id=? LIMIT 1");
      $q->execute([$userId]);
      $u = $q->fetch();
      if (!$u) throw new RuntimeException('Nutzer nicht gefunden.');
      if ($u['deleted_at'] !== null) throw new RuntimeException('Nutzer ist gelöscht.');

      $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newRole, $userId]);
      audit('user_update_role', $currentId, ['user_id'=>$userId,'role'=>$newRole]);
      $ok = 'Rolle wurde geändert.';
    }

    // TOGGLE ACTIVE
    elseif ($action === 'toggle_active') {
      $userId = (int)($_POST['user_id'] ?? 0);
      if ($userId <= 0) throw new RuntimeException('Ungültige user_id.');
      if ($userId === $currentId) throw new RuntimeException('Du kannst dich nicht selbst deaktivieren.');

      $q = $pdo->prepare("SELECT is_active, deleted_at FROM users WHERE id=? LIMIT 1");
      $q->execute([$userId]);
      $u = $q->fetch();
      if (!$u) throw new RuntimeException('Nutzer nicht gefunden.');
      if ($u['deleted_at'] !== null) throw new RuntimeException('Nutzer ist gelöscht.');

      $new = ((int)$u['is_active'] === 1) ? 0 : 1;
      $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new, $userId]);
      audit('user_toggle_active', $currentId, ['user_id'=>$userId,'is_active'=>$new]);
      $ok = $new ? 'Nutzer wurde reaktiviert.' : 'Nutzer wurde deaktiviert.';
    }

    // SOFT DELETE
    elseif ($action === 'soft_delete') {
      $userId = (int)($_POST['user_id'] ?? 0);
      if ($userId <= 0) throw new RuntimeException('Ungültige user_id.');
      if ($userId === $currentId) throw new RuntimeException('Du kannst dich nicht selbst löschen.');

      $q = $pdo->prepare("SELECT deleted_at FROM users WHERE id=? LIMIT 1");
      $q->execute([$userId]);
      $u = $q->fetch();
      if (!$u) throw new RuntimeException('Nutzer nicht gefunden.');
      if ($u['deleted_at'] !== null) throw new RuntimeException('Nutzer ist bereits gelöscht.');

      $pdo->prepare("UPDATE users SET deleted_at=NOW(), is_active=0 WHERE id=?")->execute([$userId]);
      audit('user_soft_delete', $currentId, ['user_id'=>$userId]);
      $ok = 'Nutzer wurde gelöscht (soft-delete).';
    }

    // SEND RESET LINK
    elseif ($action === 'send_reset_link') {
      $userId = (int)($_POST['user_id'] ?? 0);
      if ($userId <= 0) throw new RuntimeException('Ungültige user_id.');

      $q = $pdo->prepare("SELECT id,email,display_name,is_active,deleted_at FROM users WHERE id=? LIMIT 1");
      $q->execute([$userId]);
      $u = $q->fetch();
      if (!$u) throw new RuntimeException('Nutzer nicht gefunden.');
      if ($u['deleted_at'] !== null) throw new RuntimeException('Nutzer ist gelöscht.');
      if ((int)$u['is_active'] !== 1) throw new RuntimeException('Nutzer ist deaktiviert.');

      $rawToken = create_password_reset_token($userId, 60, true);
      $link = absolute_url('reset_password.php?token=' . $rawToken);

      $sent = send_email($u['email'], 'LEG Tool – Passwort zurücksetzen', build_reset_link_email($u['display_name'], $u['email'], $link));
      audit('user_send_reset_link', $currentId, ['user_id'=>$userId,'mail_sent'=>$sent]);

      $ok = $sent ? 'Reset-Link wurde per E-Mail versendet.' : "Reset-Link konnte nicht per Mail versendet werden.\nLink:\n{$link}";
    }

    // ASSIGN CLASS
    elseif ($action === 'assign_class') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $schoolYear = trim($_POST['school_year'] ?? '');
      $className  = trim($_POST['class_name'] ?? '');

      if ($userId <= 0) throw new RuntimeException('Ungültige user_id.');
      if ($schoolYear === '' || $className === '') throw new RuntimeException('Schuljahr und Klasse sind erforderlich.');

      $classId = find_or_create_class($pdo, $schoolYear, $className);
      assign_user_to_class($pdo, $userId, $classId);

      audit('user_assign_class', $currentId, ['user_id'=>$userId,'class_id'=>$classId,'school_year'=>$schoolYear,'class'=>$className]);
      $ok = 'Klasse wurde zugeordnet.';
    }

    // UNASSIGN CLASS
    elseif ($action === 'unassign_class') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($userId <= 0 || $classId <= 0) throw new RuntimeException('Ungültige Parameter.');

      unassign_user_from_class($pdo, $userId, $classId);
      audit('user_unassign_class', $currentId, ['user_id'=>$userId,'class_id'=>$classId]);
      $ok = 'Klasse wurde entfernt.';
    }

    // BULK IMPORT (CSV) with class
    elseif ($action === 'bulk_import') {
      if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('CSV Upload fehlgeschlagen.');
      }

      $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
      $updateExisting = isset($_POST['update_existing']) && $_POST['update_existing'] === '1';
      $onlySendIfNotSet = isset($_POST['only_send_if_not_set']) && $_POST['only_send_if_not_set'] === '1';
      $assignClass = isset($_POST['assign_class']) && $_POST['assign_class'] === '1';

      $cfg = app_config();
      $pepper = $cfg['app']['password_pepper'] ?? '';
      if ($pepper === '') throw new RuntimeException('Konfiguration (password_pepper) fehlt.');
      $defaultYear = trim((string)($cfg['app']['default_school_year'] ?? ''));

      $tmp = $_FILES['csv_file']['tmp_name'];
      $fh = fopen($tmp, 'rb');
      if (!$fh) throw new RuntimeException('Konnte CSV nicht lesen.');

      $firstLine = fgets($fh);
      if ($firstLine === false) throw new RuntimeException('CSV ist leer.');
      $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
      rewind($fh);

      $results = [
        'created' => 0,
        'restored' => 0,
        'updated_existing' => 0,
        'skipped_existing' => 0,
        'errors' => 0,
        'mail_sent' => 0,
        'mail_failed' => 0,
        'mail_skipped' => 0,
        'class_assigned' => 0,
        'rows' => [],
        'dry_run' => $dryRun,
        'update_existing' => $updateExisting,
        'only_send_if_not_set' => $onlySendIfNotSet,
        'assign_class' => $assignClass,
      ];

      $rowNum = 0;
      $header = null;

      if (!$dryRun) $pdo->beginTransaction();
      try {
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
          $rowNum++;
          if (count($row) === 1 && trim((string)$row[0]) === '') continue;

          if ($rowNum === 1 && isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
          }

          if ($rowNum === 1) {
            $maybe = array_map(fn($x) => strtolower(trim((string)$x)), $row);
            if (in_array('email', $maybe, true)) {
              $header = $maybe;
              continue;
            }
          }

          $email = $name = $role = $schoolYear = $className = '';
          $role = 'teacher';

          if ($header) {
            $map = [];
            foreach ($header as $idx => $key) $map[$key] = $idx;

            $email = trim((string)($row[$map['email']] ?? ''));
            $name  = trim((string)($row[$map['name']] ?? ''));
            $role  = trim((string)($row[$map['role']] ?? '')) ?: 'teacher';
            $schoolYear = trim((string)($row[$map['school_year']] ?? ''));
            $className  = trim((string)($row[$map['class']] ?? ''));
          } else {
            // email,name,role,school_year,class
            $email = trim((string)($row[0] ?? ''));
            $name  = trim((string)($row[1] ?? ''));
            $role  = trim((string)($row[2] ?? '')) ?: 'teacher';
            $schoolYear = trim((string)($row[3] ?? ''));
            $className  = trim((string)($row[4] ?? ''));
          }

          $role = strtolower($role);
          if (!in_array($role, ['admin','teacher'], true)) $role = 'teacher';

          if ($schoolYear === '' && $defaultYear !== '') $schoolYear = $defaultYear;

          $entry = [
            'row' => $rowNum,
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'school_year' => $schoolYear,
            'class' => $className,
            'status' => 'ok',
            'action' => '',
            'mail' => '',
            'class_assign' => '',
            'link' => '',
            'error' => '',
          ];

          try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige E-Mail.');
            if ($name === '') throw new RuntimeException('Name fehlt.');

            $check = $pdo->prepare("SELECT id, deleted_at, password_set_at FROM users WHERE email=? LIMIT 1");
            $check->execute([$email]);
            $existing = $check->fetch();

            $random = bin2hex(random_bytes(16));
            $hash = password_hash($random . $pepper, PASSWORD_DEFAULT);

            $userId = 0;
            $passwordAlreadySet = false;

            if (!$existing) {
              $entry['action'] = 'create';
              if (!$dryRun) {
                $stmt = $pdo->prepare(
                  "INSERT INTO users (email, password_hash, password_set_at, display_name, role, is_active, must_change_password, deleted_at)
                   VALUES (?, ?, NULL, ?, ?, 1, 0, NULL)"
                );
                $stmt->execute([$email, $hash, $name, $role]);
                $userId = (int)$pdo->lastInsertId();
              }
              $results['created']++;
            } else {
              $userId = (int)$existing['id'];
              $passwordAlreadySet = ($existing['password_set_at'] !== null);

              if ($existing['deleted_at'] !== null) {
                $entry['action'] = 'restore';
                if (!$dryRun) {
                  $upd = $pdo->prepare(
                    "UPDATE users
                     SET display_name=?, role=?, is_active=1, deleted_at=NULL,
                         password_hash=?, password_set_at=NULL, must_change_password=0
                     WHERE id=?"
                  );
                  $upd->execute([$name, $role, $hash, $userId]);
                }
                $results['restored']++;
                $passwordAlreadySet = false;
              } else {
                if ($updateExisting) {
                  $entry['action'] = 'update_existing';
                  if (!$dryRun) {
                    $pdo->prepare("UPDATE users SET display_name=?, role=? WHERE id=?")->execute([$name, $role, $userId]);
                  }
                  $results['updated_existing']++;
                } else {
                  $entry['action'] = 'skip_existing';
                  $results['skipped_existing']++;
                  $results['rows'][] = $entry;
                  continue;
                }
              }
            }

            // assign class if enabled and class provided
            if ($assignClass && $className !== '') {
              if ($schoolYear === '') {
                throw new RuntimeException('Klasse angegeben, aber school_year fehlt (oder default_school_year ist leer).');
              }
              if (!$dryRun) {
                $classId = find_or_create_class($pdo, $schoolYear, $className);
                assign_user_to_class($pdo, $userId, $classId);
              }
              $results['class_assigned']++;
              $entry['class_assign'] = 'assigned';
            } else {
              $entry['class_assign'] = ($className !== '' ? 'skipped' : '');
            }

            // mail logic
            if ($dryRun) {
              $entry['mail'] = 'dry_run';
            } else {
              if ($onlySendIfNotSet && $passwordAlreadySet) {
                $entry['mail'] = 'skipped_pw_already_set';
                $results['mail_skipped']++;
              } else {
                $rawToken = create_password_reset_token($userId, 1440, true);
                $link = absolute_url('reset_password.php?token=' . $rawToken);
                $entry['link'] = $link;

                $sent = send_email($email, 'Dein LEG Tool Konto – Passwort setzen', build_set_password_email($name, $email, $link));
                if ($sent) {
                  $entry['mail'] = 'sent';
                  $results['mail_sent']++;
                } else {
                  $entry['mail'] = 'failed';
                  $results['mail_failed']++;
                }
              }
            }

          } catch (Throwable $rowE) {
            $results['errors']++;
            $entry['status'] = 'error';
            $entry['error'] = $rowE->getMessage();
          }

          $results['rows'][] = $entry;
        }

        fclose($fh);

        if (!$dryRun) {
          audit('user_bulk_import', $currentId, [
            'created' => $results['created'],
            'restored' => $results['restored'],
            'updated_existing' => $results['updated_existing'],
            'skipped_existing' => $results['skipped_existing'],
            'class_assigned' => $results['class_assigned'],
            'errors' => $results['errors'],
            'mail_sent' => $results['mail_sent'],
            'mail_failed' => $results['mail_failed'],
            'mail_skipped' => $results['mail_skipped'],
          ]);
          $pdo->commit();
        }
      } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }

      $_SESSION['bulk_import_result'] = $results;
      $bulk = $results;

      $ok = $dryRun
        ? "Dry-run: {$results['created']} neu, {$results['restored']} restored, {$results['updated_existing']} updated, {$results['skipped_existing']} skip, {$results['errors']} Fehler."
        : "Import: {$results['created']} neu, {$results['restored']} restored, {$results['updated_existing']} updated, {$results['skipped_existing']} skip, {$results['errors']} Fehler. Klassen: {$results['class_assigned']}. Mails: {$results['mail_sent']} ok, {$results['mail_failed']} fail, {$results['mail_skipped']} skipped.";
    }

    else {
      throw new RuntimeException('Unbekannte Aktion.');
    }

  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

// Users list (with assigned classes)
$users = $pdo->query(
  "SELECT id, email, display_name, role, is_active, deleted_at, password_set_at, created_at
   FROM users
   ORDER BY created_at DESC"
)->fetchAll();

// Classes for assignment UI
$classes = $pdo->query(
  "SELECT id, school_year, name FROM classes ORDER BY school_year DESC, name ASC"
)->fetchAll();

// Preload assignments map
$assignRows = $pdo->query(
  "SELECT uca.user_id, c.id AS class_id, c.school_year, c.name
   FROM user_class_assignments uca
   JOIN classes c ON c.id = uca.class_id"
)->fetchAll();

$assignMap = [];
foreach ($assignRows as $r) {
  $uid = (int)$r['user_id'];
  $assignMap[$uid] ??= [];
  $assignMap[$uid][] = [
    'class_id' => (int)$r['class_id'],
    'school_year' => $r['school_year'],
    'name' => $r['name'],
  ];
}

render_admin_header('Admin – Nutzer');

?>
<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?>
  <div class="alert danger"><strong><?=h($err)?></strong></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alert success"><strong><?=h($ok)?></strong></div>
<?php endif; ?>

<?php if ($bulk): ?>
  <div class="card">
    <h2>Bulk-Import Ergebnis</h2>
    <p class="muted">
      Neu: <?=h((string)$bulk['created'])?> · Restored: <?=h((string)$bulk['restored'])?> · Updated: <?=h((string)$bulk['updated_existing'])?> ·
      Skipped: <?=h((string)$bulk['skipped_existing'])?> · Fehler: <?=h((string)$bulk['errors'])?><br>
      Klassen zugeordnet: <?=h((string)$bulk['class_assigned'])?><br>
      Mail OK: <?=h((string)$bulk['mail_sent'])?> · Mail Fail: <?=h((string)$bulk['mail_failed'])?> · Mail Skipped: <?=h((string)$bulk['mail_skipped'])?>
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Zeile</th><th>Email</th><th>Name</th><th>Rolle</th><th>SY</th><th>Klasse</th>
            <th>Aktion</th><th>Mail</th><th>Klasse</th><th>Link (Fail)</th><th>Fehler</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bulk['rows'] as $r): ?>
            <tr>
              <td><?=h((string)$r['row'])?></td>
              <td><?=h($r['email'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['role'])?></td>
              <td><?=h($r['school_year'] ?? '')?></td>
              <td><?=h($r['class'] ?? '')?></td>
              <td><?=h($r['action'])?></td>
              <td><?=h($r['mail'])?></td>
              <td><?=h($r['class_assign'] ?? '')?></td>
              <td style="max-width:300px; word-break:break-all;">
                <?php if (($r['mail'] ?? '') === 'failed' && ($r['link'] ?? '')): ?>
                  <a href="<?=h($r['link'])?>" target="_blank" rel="noreferrer">Link</a>
                <?php endif; ?>
              </td>
              <td><?=h($r['error'] ?? '')?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Neuen Nutzer anlegen / wiederherstellen</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">

    <div class="grid">
      <div>
        <label>E-Mail</label>
        <input name="email" type="email" required>
      </div>
      <div>
        <label>Name</label>
        <input name="name" required>
      </div>
    </div>

    <label>Rolle</label>
    <select name="role">
      <option value="teacher">Teacher</option>
      <option value="admin">Admin</option>
    </select>

    <div class="actions">
      <button class="btn primary" type="submit">Anlegen / Reaktivieren</button>
    </div>

    <p class="muted">Bei soft-delete wird der Nutzer beim Anlegen automatisch wiederhergestellt.</p>
  </form>
</div>

<div class="card">
  <h2>Bulk-Import (CSV)</h2>
  <p class="muted">
    Spalten: <code>email</code>, <code>name</code>, optional <code>role</code>, optional <code>school_year</code>, optional <code>class</code>.<br>
    Trennzeichen: Komma oder Semikolon. Erste Zeile darf Header sein.
  </p>
  <div class="actions">
    <a class="btn secondary" href="<?=h(url('admin/users.php?download=csv_template'))?>">⬇️ CSV-Template</a>
  </div>

  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="bulk_import">

    <label>CSV-Datei</label>
    <input type="file" name="csv_file" accept=".csv,text/csv" required>

    <label><input type="checkbox" name="dry_run" value="1">Nur testen (dry-run)</label>
    <label><input type="checkbox" name="update_existing" value="1">Bestehende Nutzer aktualisieren (Name/Rolle)</label>
    <label><input type="checkbox" name="only_send_if_not_set" value="1" checked>Passwort-Link nur senden, wenn Passwort noch nicht gesetzt ist</label>
    <label><input type="checkbox" name="assign_class" value="1" checked>Klassen-Zuordnung aus CSV übernehmen (auto-create Klasse)</label>

    <div class="actions">
      <button class="btn primary" type="submit">Import starten</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Klassen-Zuordnung (manuell)</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="assign_class">

    <div class="grid">
      <div>
        <label>Nutzer</label>
        <select name="user_id" required>
          <option value="">Bitte wählen…</option>
          <?php foreach ($users as $u): ?>
            <?php if ($u['deleted_at'] !== null) continue; ?>
            <option value="<?=h((string)$u['id'])?>"><?=h($u['display_name'])?> (<?=h($u['email'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Schuljahr</label>
        <input name="school_year" placeholder="z.B. 2025/26" required>
      </div>
      <div>
        <label>Klasse</label>
        <input name="class_name" placeholder="z.B. 4a" required>
      </div>
    </div>
    <div class="actions">
      <button class="btn primary" type="submit">Zuordnen</button>
    </div>
    <p class="muted">Wenn die Klasse noch nicht existiert, wird sie automatisch angelegt.</p>
  </form>
</div>

<div class="card">
  <h2>Vorhandene Nutzer</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Passwort</th><th>Klassen</th><th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $uid = (int)$u['id'];
          $deleted = $u['deleted_at'] !== null;
          $active  = (int)$u['is_active'] === 1;
          $pwSet   = $u['password_set_at'] !== null;
          $isSelf  = ($uid === $currentId);
          $assigned = $assignMap[$uid] ?? [];
        ?>
        <tr>
          <td><?=h((string)$u['id'])?></td>
          <td><?=h($u['display_name'])?></td>
          <td><?=h($u['email'])?></td>
          <td>
            <?php if ($deleted): ?>
              <?=h($u['role'])?>
            <?php else: ?>
              <form method="post" class="row-actions">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="user_id" value="<?=h((string)$uid)?>">
                <select name="role" style="width:auto">
                  <option value="teacher" <?= $u['role']==='teacher'?'selected':'' ?>>Teacher</option>
                  <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                </select>
                <button class="btn secondary" type="submit">Speichern</button>
              </form>
              <?php if ($isSelf): ?><div class="muted small">Du selbst (Self-demote gesperrt)</div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($deleted): ?><span class="pill">gelöscht</span>
            <?php elseif ($active): ?><span class="pill">aktiv</span>
            <?php else: ?><span class="pill">inaktiv</span>
            <?php endif; ?>
          </td>
          <td><?= $pwSet ? '<span class="pill">gesetzt</span>' : '<span class="pill">nicht gesetzt</span>' ?></td>
          <td>
            <?php if (!$assigned): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <?php foreach ($assigned as $c): ?>
                <div class="row-actions" style="margin-bottom:6px;">
                  <span class="pill"><?=h($c['school_year'])?> · <?=h($c['name'])?></span>
                  <?php if (!$deleted): ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="unassign_class">
                      <input type="hidden" name="user_id" value="<?=h((string)$uid)?>">
                      <input type="hidden" name="class_id" value="<?=h((string)$c['class_id'])?>">
                      <button class="btn danger" type="submit" onclick="return confirm('Zuordnung entfernen?');">Entfernen</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($deleted): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <div class="row-actions">
                <?php if (!$isSelf): ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?=h((string)$uid)?>">
                    <button class="btn secondary" type="submit"><?= $active ? 'Deaktivieren' : 'Reaktivieren' ?></button>
                  </form>
                <?php endif; ?>

                <?php if ($active): ?>
                  <form method="post" onsubmit="return confirm('Reset-Link wirklich senden?');">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="send_reset_link">
                    <input type="hidden" name="user_id" value="<?=h((string)$uid)?>">
                    <button class="btn secondary" type="submit">Reset-Link</button>
                  </form>
                <?php endif; ?>

                <?php if (!$isSelf): ?>
                  <form method="post" onsubmit="return confirm('Nutzer wirklich löschen (soft-delete)?');">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="soft_delete">
                    <input type="hidden" name="user_id" value="<?=h((string)$uid)?>">
                    <button class="btn danger" type="submit">Löschen</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_admin_footer(); ?>
