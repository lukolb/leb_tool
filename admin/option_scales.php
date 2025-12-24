<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok = '';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function text_to_options(string $text): array {
  $out = [];
  $lines = preg_split("/\r\n|\n|\r/", trim($text));
  if (!$lines) return [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // value|label|icon (icon optional)
    $parts = explode('|', $line);
    $value = trim($parts[0] ?? '');
    $label = trim($parts[1] ?? $value);
    $icon  = trim($parts[2] ?? '');

    if ($value === '') continue;
    $row = ['value'=>$value,'label'=>($label!==''?$label:$value)];
    if ($icon !== '') $row['icon'] = $icon;
    $out[] = $row;
  }
  return $out;
}

function options_to_text(array $options): string {
  $lines = [];
  foreach ($options as $o) {
    if (!is_array($o)) continue;
    $v = (string)($o['value'] ?? '');
    $l = (string)($o['label'] ?? $v);
    $ic = (string)($o['icon'] ?? '');
    if ($v === '') continue;
    $line = $v;
    if ($l !== '' && $l !== $v) $line .= '|' . $l;
    else $line .= '|' . $v;
    if ($ic !== '') $line .= '|' . $ic;
    $lines[] = $line;
  }
  return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      $applies = (string)($_POST['applies_to'] ?? 'any');
      if (!in_array($applies, ['radio','select','grade','any'], true)) $applies = 'any';

      $text = (string)($_POST['options_text'] ?? '');
      $opts = text_to_options($text);
      if ($name === '') throw new RuntimeException('Name fehlt.');
      if (!$opts) throw new RuntimeException('Bitte mindestens eine Option angeben.');

      $json = json_encode(['options'=>$opts], JSON_UNESCAPED_UNICODE);

      $st = $pdo->prepare("INSERT INTO option_scales (name, applies_to, options_json, is_active) VALUES (?, ?, ?, 1)");
      $st->execute([$name, $applies, $json]);

      audit('scale_create', (int)current_user()['id'], ['name'=>$name,'applies_to'=>$applies]);
      $ok = 'Skala angelegt.';
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID fehlt.');
      $name = trim((string)($_POST['name'] ?? ''));
      $applies = (string)($_POST['applies_to'] ?? 'any');
      if (!in_array($applies, ['radio','select','grade','any'], true)) $applies = 'any';

      $text = (string)($_POST['options_text'] ?? '');
      $opts = text_to_options($text);
      if ($name === '') throw new RuntimeException('Name fehlt.');
      if (!$opts) throw new RuntimeException('Bitte mindestens eine Option angeben.');

      $json = json_encode(['options'=>$opts], JSON_UNESCAPED_UNICODE);

      $st = $pdo->prepare("UPDATE option_scales SET name=?, applies_to=?, options_json=? WHERE id=?");
      $st->execute([$name, $applies, $json, $id]);

      audit('scale_update', (int)current_user()['id'], ['id'=>$id]);
      $ok = 'Skala aktualisiert.';
    }

    if ($action === 'disable') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID fehlt.');
      $pdo->prepare("UPDATE option_scales SET is_active=0 WHERE id=?")->execute([$id]);
      audit('scale_disable', (int)current_user()['id'], ['id'=>$id]);
      $ok = 'Skala deaktiviert.';
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$scales = $pdo->query("SELECT id, name, applies_to, options_json, is_active, created_at FROM option_scales ORDER BY created_at DESC")->fetchAll();

render_admin_header('Admin – Skalen');
?>
<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">← Templates</a>
    <a class="btn secondary" href="<?=h(url('admin/settings.php'))?>">Settings</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2>Neue Skala anlegen</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">

    <div class="grid">
      <div>
        <label>Name</label>
        <input name="name" required placeholder="z.B. Sozialverhalten A-D">
      </div>
      <div>
        <label>Gilt für</label>
        <select name="applies_to">
          <option value="any">any</option>
          <option value="radio">radio</option>
          <option value="select">select</option>
          <option value="grade">grade</option>
        </select>
      </div>
    </div>

    <label>Optionen (eine Zeile: value|label|icon)</label>
    <textarea name="options_text" rows="8" placeholder="A|Excellent|⭐
B|Good|🙂
C|Needs Improvement|⚠️"></textarea>
    <div class="muted">Icon kann Emoji sein (z.B. ⭐ 🙂 ⚠️) oder später eine Icon-ID/URL.</div>

    <div class="actions" style="margin-top:12px;">
      <button class="btn primary" type="submit">Anlegen</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Vorhandene Skalen</h2>
  <?php if (!$scales): ?>
    <p class="muted">Noch keine Skalen.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Name</th><th>Gilt für</th><th>Aktiv</th><th>Aktion</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($scales as $s): ?>
        <?php
          $opts = json_decode((string)($s['options_json'] ?? ''), true);
          $txt = '';
          if (is_array($opts) && isset($opts['options']) && is_array($opts['options'])) $txt = options_to_text($opts['options']);
        ?>
        <tr>
          <td><?=h((string)$s['id'])?></td>
          <td><?=h($s['name'])?></td>
          <td><?=h($s['applies_to'])?></td>
          <td><?=h((string)$s['is_active'])?></td>
          <td>
            <?php if ((int)$s['is_active'] === 1): ?>
              <button class="btn secondary js-edit" type="button"
                data-id="<?=h((string)$s['id'])?>"
                data-name="<?=h($s['name'])?>"
                data-applies="<?=h($s['applies_to'])?>"
                data-text="<?=h($txt)?>">Bearbeiten</button>

              <form method="post" style="display:inline;" onsubmit="return confirm('Skala deaktivieren?');">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="disable">
                <input type="hidden" name="id" value="<?=h((string)$s['id'])?>">
                <button class="btn secondary" type="submit">Deaktivieren</button>
              </form>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" id="editCard" style="display:none;">
  <h2>Skala bearbeiten</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="editId" value="">

    <div class="grid">
      <div>
        <label>Name</label>
        <input name="name" id="editName" required>
      </div>
      <div>
        <label>Gilt für</label>
        <select name="applies_to" id="editApplies">
          <option value="any">any</option>
          <option value="radio">radio</option>
          <option value="select">select</option>
          <option value="grade">grade</option>
        </select>
      </div>
    </div>

    <label>Optionen (value|label|icon)</label>
    <textarea name="options_text" id="editText" rows="8"></textarea>

    <div class="actions" style="margin-top:12px;">
      <button class="btn primary" type="submit">Speichern</button>
      <button class="btn secondary" type="button" id="btnCancelEdit">Abbrechen</button>
    </div>
  </form>
</div>

<script>
const editCard = document.getElementById('editCard');
const editId = document.getElementById('editId');
const editName = document.getElementById('editName');
const editApplies = document.getElementById('editApplies');
const editText = document.getElementById('editText');
document.querySelectorAll('.js-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    editId.value = btn.dataset.id || '';
    editName.value = btn.dataset.name || '';
    editApplies.value = btn.dataset.applies || 'any';
    editText.value = btn.dataset.text || '';
    editCard.style.display = 'block';
    editCard.scrollIntoView({behavior:'smooth', block:'start'});
  });
});
document.getElementById('btnCancelEdit').addEventListener('click', () => {
  editCard.style.display = 'none';
});
</script>

<?php render_admin_footer(); ?>
