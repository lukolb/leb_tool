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

      if ($key === '') throw new RuntimeException(t('admin.student_fields.error.key_required'));
      if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) throw new RuntimeException(t('admin.student_fields.error.key_invalid'));
      if ($label === '') throw new RuntimeException(t('admin.student_fields.error.label_required'));

      $dup = $pdo->prepare('SELECT id FROM student_fields WHERE field_key=? LIMIT 1');
      $dup->execute([$key]);
      if ($dup->fetch()) throw new RuntimeException(t('admin.student_fields.error.key_exists'));

      $ins = $pdo->prepare(
        'INSERT INTO student_fields (field_key, label, label_en, default_value, sort_order) VALUES (?, ?, ?, ?, ?)'
      );
      $ins->execute([$key, $label, $labelEn, $default === '' ? null : $default, $sort]);
      $ok = t('admin.student_fields.status.created');
    }
    elseif ($action === 'update') {
      $id = (int)($_POST['field_id'] ?? 0);
      $key = normalize_field_key((string)($_POST['field_key'] ?? ''));
      $label = trim((string)($_POST['label'] ?? ''));
      $labelEn = trim((string)($_POST['label_en'] ?? ''));
      $default = (string)($_POST['default_value'] ?? '');
      $sort = (int)($_POST['sort_order'] ?? 0);

      if ($id <= 0) throw new RuntimeException(t('admin.student_fields.error.id_missing'));
      if ($key === '') throw new RuntimeException(t('admin.student_fields.error.key_required'));
      if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) throw new RuntimeException(t('admin.student_fields.error.key_invalid'));
      if ($label === '') throw new RuntimeException(t('admin.student_fields.error.label_required'));

      $dup = $pdo->prepare('SELECT id FROM student_fields WHERE field_key=? AND id<>? LIMIT 1');
      $dup->execute([$key, $id]);
      if ($dup->fetch()) throw new RuntimeException(t('admin.student_fields.error.key_exists'));

      $upd = $pdo->prepare(
        'UPDATE student_fields SET field_key=?, label=?, label_en=?, default_value=?, sort_order=? WHERE id=?'
      );
      $upd->execute([$key, $label, $labelEn, $default === '' ? null : $default, $sort, $id]);
      $ok = t('admin.student_fields.status.saved');
    }
    elseif ($action === 'delete') {
      $id = (int)($_POST['field_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException(t('admin.student_fields.error.id_missing'));

      $pdo->prepare('DELETE FROM student_field_values WHERE field_id=?')->execute([$id]);
      $pdo->prepare('DELETE FROM student_fields WHERE id=?')->execute([$id]);
      $ok = t('admin.student_fields.status.deleted');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$fields = $pdo->query(
  'SELECT id, field_key, label, label_en, default_value, sort_order, created_at, updated_at FROM student_fields ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

render_admin_header(t('admin.student_fields.title'));
?>

<div class="card">
  <h1><?=h(t('admin.student_fields.title'))?></h1>
  <p class="muted"><?=t('admin.student_fields.intro')?></p>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2><?=h(t('admin.student_fields.new_heading'))?></h2>
  <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 1fr 120px; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">

    <div>
      <label><?=h(t('admin.student_fields.label.key'))?></label>
      <input type="text" name="field_key" required placeholder="<?=h(t('admin.student_fields.placeholder.key'))?>">
    </div>
    <div>
      <label><?=h(t('admin.student_fields.label.label_de'))?></label>
      <input type="text" name="label" required>
    </div>
    <div>
      <label><?=h(t('admin.student_fields.label.label_en'))?></label>
      <input type="text" name="label_en" placeholder="<?=h(t('admin.student_fields.placeholder.label_en'))?>">
    </div>
    <div>
      <label><?=h(t('admin.student_fields.label.sort'))?></label>
      <input type="number" name="sort_order" value="0">
    </div>
    <div style="grid-column: 1 / span 4;">
      <label><?=h(t('admin.student_fields.label.default_value'))?></label>
      <textarea name="default_value" class="input" rows="2" placeholder="<?=h(t('admin.student_fields.placeholder.default_value'))?>"></textarea>
    </div>
    <div class="actions" style="grid-column: 1 / span 4; justify-content:flex-start;">
      <button class="btn primary" type="submit"><?=h(t('admin.student_fields.action.create'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.student_fields.list_heading'))?></h2>
  <?php if (!$fields): ?>
    <p class="muted"><?=h(t('admin.student_fields.list_empty'))?></p>
  <?php else: ?>
    <div class="muted" style="margin-bottom:12px;"><?=h(t('admin.student_fields.list_hint'))?></div>
    <table class="table">
      <thead>
        <tr>
          <th style="width:140px;"><?=h(t('admin.student_fields.table.key'))?></th>
          <th><?=h(t('admin.student_fields.table.label_de'))?></th>
          <th><?=h(t('admin.student_fields.table.label_en'))?></th>
          <th><?=h(t('admin.student_fields.table.default_value'))?></th>
          <th style="width:120px;"><?=h(t('admin.student_fields.table.sort'))?></th>
          <th style="width:200px;"><?=h(t('admin.student_fields.table.actions'))?></th>
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
              <td><textarea name="default_value" class="input" rows="2" placeholder="<?=h(t('admin.student_fields.placeholder.no_default'))?>"><?=h((string)($f['default_value'] ?? ''))?></textarea></td>
              <td><input type="number" name="sort_order" value="<?=h((string)$f['sort_order'])?>"></td>
              <td>
                <div class="actions" style="justify-content:flex-start;">
                  <button class="btn secondary" type="submit"><?=h(t('admin.student_fields.action.save'))?></button>
                </div>
            </form>
                <form method="post" onsubmit="return confirm('<?=h(t('admin.student_fields.confirm_delete'))?>');" style="margin-top:6px;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="field_id" value="<?=h((string)$f['id'])?>">
                  <button class="btn danger" type="submit"><?=h(t('admin.student_fields.action.delete'))?></button>
                </form>
              </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
