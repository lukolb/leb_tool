<?php
// admin/student_fields.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok  = '';

function normalize_field_key(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', '_', $s);
  return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create') {
      $key = normalize_field_key((string)($_POST['field_key'] ?? ''));
      $label = trim((string)($_POST['label'] ?? ''));
      $labelEn = trim((string)($_POST['label_en'] ?? ''));
      $default = (string)($_POST['default_value'] ?? '');
      $sort = (int)($_POST['sort_order'] ?? 0);

      if ($key === '') throw new RuntimeException('Feldschlüssel darf nicht leer sein.');
      if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) throw new RuntimeException('Nur Buchstaben, Zahlen, Punkt, Bindestrich, Unterstrich erlaubt.');
      if ($label === '') throw new RuntimeException('Label (DE) wird benötigt.');

      $dup = $pdo->prepare('SELECT id FROM student_fields WHERE field_key=? LIMIT 1');
      $dup->execute([$key]);
      if ($dup->fetch()) throw new RuntimeException('Schlüssel bereits vorhanden.');

      $ins = $pdo->prepare(
        'INSERT INTO student_fields (field_key, label, label_en, default_value, sort_order) VALUES (?, ?, ?, ?, ?)'
      );
      $ins->execute([$key, $label, $labelEn, $default === '' ? null : $default, $sort]);
      $ok = 'Feld angelegt.';
    }
    elseif ($action === 'update') {
      $id = (int)($_POST['field_id'] ?? 0);
      $key = normalize_field_key((string)($_POST['field_key'] ?? ''));
      $label = trim((string)($_POST['label'] ?? ''));
      $labelEn = trim((string)($_POST['label_en'] ?? ''));
      $default = (string)($_POST['default_value'] ?? '');
      $sort = (int)($_POST['sort_order'] ?? 0);

      if ($id <= 0) throw new RuntimeException('field_id fehlt.');
      if ($key === '') throw new RuntimeException('Feldschlüssel darf nicht leer sein.');
      if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) throw new RuntimeException('Nur Buchstaben, Zahlen, Punkt, Bindestrich, Unterstrich erlaubt.');
      if ($label === '') throw new RuntimeException('Label (DE) wird benötigt.');

      $dup = $pdo->prepare('SELECT id FROM student_fields WHERE field_key=? AND id<>? LIMIT 1');
      $dup->execute([$key, $id]);
      if ($dup->fetch()) throw new RuntimeException('Schlüssel bereits vorhanden.');

      $upd = $pdo->prepare(
        'UPDATE student_fields SET field_key=?, label=?, label_en=?, default_value=?, sort_order=? WHERE id=?'
      );
      $upd->execute([$key, $label, $labelEn, $default === '' ? null : $default, $sort, $id]);
      $ok = 'Feld gespeichert.';
    }
    elseif ($action === 'delete') {
      $id = (int)($_POST['field_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('field_id fehlt.');

      $pdo->prepare('DELETE FROM student_field_values WHERE field_id=?')->execute([$id]);
      $pdo->prepare('DELETE FROM student_fields WHERE id=?')->execute([$id]);
      $ok = 'Feld gelöscht.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$fields = $pdo->query(
  'SELECT id, field_key, label, label_en, default_value, sort_order, created_at, updated_at FROM student_fields ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

render_admin_header('Schüler-Felder');
?>

<div class="card">
  <h1>Schüler-Felder</h1>
  <p class="muted">Zusätzliche Felder für Schüler-Stammdaten. Platzhalter: <code>{{student.custom.&lt;schlüssel&gt;}}</code>.</p>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2>Neues Feld anlegen</h2>
  <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 1fr 120px; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">

    <div>
      <label>Schlüssel</label>
      <input type="text" name="field_key" required placeholder="z.B. allergie_hinweis">
    </div>
    <div>
      <label>Label (DE)</label>
      <input type="text" name="label" required>
    </div>
    <div>
      <label>Label (EN)</label>
      <input type="text" name="label_en" placeholder="optional">
    </div>
    <div>
      <label>Sortierung</label>
      <input type="number" name="sort_order" value="0">
    </div>
    <div style="grid-column: 1 / span 4;">
      <label>Standardwert (optional)</label>
      <textarea name="default_value" class="input" rows="2" placeholder="Wert bei Neuerstellung"></textarea>
    </div>
    <div class="actions" style="grid-column: 1 / span 4; justify-content:flex-start;">
      <button class="btn primary" type="submit">Anlegen</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Bestehende Felder</h2>
  <?php if (!$fields): ?>
    <p class="muted">Noch keine Felder angelegt.</p>
  <?php else: ?>
    <div class="muted" style="margin-bottom:12px;">Hinweis: Änderungen wirken sich auf neue Schüler direkt aus. Für vorhandene Schüler können Standardwerte nachträglich übernommen werden, indem Werte manuell gesetzt werden.</div>
    <table class="table">
      <thead>
        <tr>
          <th style="width:140px;">Schlüssel</th>
          <th>Label (DE)</th>
          <th>Label (EN)</th>
          <th>Standardwert</th>
          <th style="width:120px;">Sortierung</th>
          <th style="width:200px;">Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $f): ?>
          <tr>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="field_id" value="<?=h((string)$f['id'])?>">
              <td><input type="text" name="field_key" value="<?=h((string)$f['field_key'])?>" required></td>
              <td><input type="text" name="label" value="<?=h((string)$f['label'])?>" required></td>
              <td><input type="text" name="label_en" value="<?=h((string)($f['label_en'] ?? ''))?>"></td>
              <td><textarea name="default_value" class="input" rows="2" placeholder="Kein Standardwert"><?=h((string)($f['default_value'] ?? ''))?></textarea></td>
              <td><input type="number" name="sort_order" value="<?=h((string)$f['sort_order'])?>"></td>
              <td>
                <div class="actions" style="justify-content:flex-start;">
                  <button class="btn secondary" type="submit">Speichern</button>
                </div>
            </form>
                <form method="post" onsubmit="return confirm('Feld löschen? Werte gehen verloren.');" style="margin-top:6px;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="field_id" value="<?=h((string)$f['id'])?>">
                  <button class="btn danger" type="submit">Löschen</button>
                </form>
              </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
