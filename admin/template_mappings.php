<?php
// admin/template_mappings.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();

$templateId = (int)($_GET['template_id'] ?? ($_POST['template_id'] ?? 0));
$previewStudentId = (int)($_GET['preview_student_id'] ?? 0);

$err = '';
$ok  = '';

// IMPORTANT: Keys must match resolve_system_binding_value() in bootstrap.php
$SYSTEM_KEYS = [
  'student.first_name'    => 'Schüler: Vorname',
  'student.last_name'     => 'Schüler: Nachname',
  'student.date_of_birth' => 'Schüler: Geburtsdatum',
  'class.school_year'     => 'Klasse: Schuljahr',
  'class.grade_level'     => 'Klasse: Klassenstufe',
  'class.label'           => 'Klasse: Bezeichnung (a/b/...)',
  'class.display'         => 'Klasse: Anzeige (z.B. 1a)',
];

function meta_read_map(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function meta_write_map(array $meta): string {
  return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function load_template_map(PDO $pdo, int $templateId): ?array {
  $st = $pdo->prepare("SELECT * FROM templates WHERE id=? LIMIT 1");
  $st->execute([$templateId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  return $t ?: null;
}

function list_templates_map(PDO $pdo): array {
  return $pdo->query(
    "SELECT id, name, template_version, is_active, created_at
     FROM templates
     ORDER BY created_at DESC, id DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
}

function list_template_fields_map(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, label, field_type, meta_json
     FROM template_fields
     WHERE template_id=?
     ORDER BY sort_order ASC, field_name ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function current_bindings_map(array $fields): array {
  // Returns [system_key => field_name]
  $map = [];
  foreach ($fields as $f) {
    $meta = meta_read_map($f['meta_json'] ?? null);
    $sys = (string)($meta['system_binding'] ?? '');
    if ($sys !== '') $map[$sys] = (string)$f['field_name'];
  }
  return $map;
}

function get_student_preview_map(PDO $pdo, int $studentId): ?array {
  $st = $pdo->prepare(
    "SELECT s.*, c.school_year AS class_school_year, c.name AS class_name, c.grade_level AS class_grade_level, c.label AS class_label
     FROM students s
     LEFT JOIN classes c ON c.id = s.class_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function preview_value_map(string $systemKey, array $row): string {
  switch ($systemKey) {
    case 'student.first_name': return (string)($row['first_name'] ?? '');
    case 'student.last_name': return (string)($row['last_name'] ?? '');
    case 'student.date_of_birth': return (string)($row['date_of_birth'] ?? '');
    case 'class.school_year': return (string)($row['class_school_year'] ?? '');
    case 'class.grade_level': return (string)($row['class_grade_level'] ?? '');
    case 'class.label': return (string)($row['class_label'] ?? '');
    case 'class.display':
      $g = $row['class_grade_level'] !== null ? (int)$row['class_grade_level'] : null;
      $l = (string)($row['class_label'] ?? '');
      $n = (string)($row['class_name'] ?? '');
      if ($g !== null && $l !== '') return (string)$g . $l;
      return $n;
    default:
      return '';
  }
}

// POST: save bindings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_bindings') throw new RuntimeException('Ungültige Aktion.');

    $templateId = (int)($_POST['template_id'] ?? 0);
    if ($templateId <= 0) throw new RuntimeException('template_id fehlt.');

    $bindings = $_POST['binding'] ?? [];
    if (!is_array($bindings)) $bindings = [];

    $fields = list_template_fields_map($pdo, $templateId);

    $pdo->beginTransaction();

    // Clear all existing system_binding keys for this template
    foreach ($fields as $f) {
      $meta = meta_read_map($f['meta_json'] ?? null);
      if (array_key_exists('system_binding', $meta)) {
        unset($meta['system_binding']);
        $pdo->prepare("UPDATE template_fields SET meta_json=? WHERE id=?")
            ->execute([meta_write_map($meta), (int)$f['id']]);
      }
    }

    // Apply selections
    foreach ($bindings as $sysKey => $fieldIdRaw) {
      $sysKey = (string)$sysKey;
      if (!array_key_exists($sysKey, $SYSTEM_KEYS)) continue;
      $fieldId = (int)$fieldIdRaw;
      if ($fieldId <= 0) continue;

      $st = $pdo->prepare("SELECT meta_json FROM template_fields WHERE id=? LIMIT 1");
      $st->execute([$fieldId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) continue;

      $meta = meta_read_map($row['meta_json'] ?? null);
      $meta['system_binding'] = $sysKey;
      $pdo->prepare("UPDATE template_fields SET meta_json=? WHERE id=?")
          ->execute([meta_write_map($meta), $fieldId]);
    }

    $pdo->commit();

    audit('admin_template_system_bindings_save', (int)(current_user()['id'] ?? 0), [
      'template_id' => $templateId,
      'bindings' => $bindings,
    ]);

    $ok = 'Mapping gespeichert.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$templates = list_templates_map($pdo);
$template  = $templateId > 0 ? load_template_map($pdo, $templateId) : null;
$fields    = $template ? list_template_fields_map($pdo, (int)$template['id']) : [];
$currentBindings = current_bindings_map($fields);

$studentsForPreview = [];
if ($template) {
  $studentsForPreview = $pdo->query(
    "SELECT s.id, s.first_name, s.last_name, c.school_year, c.name AS class_name
     FROM students s
     LEFT JOIN classes c ON c.id=s.class_id
     ORDER BY s.last_name ASC, s.first_name ASC
     LIMIT 250"
  )->fetchAll(PDO::FETCH_ASSOC);
}
$preview = $previewStudentId > 0 ? get_student_preview_map($pdo, $previewStudentId) : null;

render_admin_header('Stammdaten-Mapping');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">&larr; Templates</a>
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">Dashboard</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
  <h1 style="margin-top:0;">Stammdaten &rarr; PDF-Felder mappen</h1>

  <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

  <form method="get" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
    <div>
      <label>Template auswählen</label>
      <select name="template_id" onchange="this.form.submit()">
        <option value="0">— bitte wählen —</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?=h((string)$t['id'])?>" <?=((int)$t['id']===$templateId)?'selected':''?>>
            #<?=h((string)$t['id'])?> · <?=h((string)$t['name'])?> · v<?=h((string)$t['template_version'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <?php if ($template): ?>
        <a class="btn secondary" href="<?=h(url('admin/template_fields.php?template_id='.(int)$template['id']))?>">Felder bearbeiten</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($template): ?>
  <div class="card">
    <h2 style="margin-top:0;">Mapping für: <?=h((string)$template['name'])?> (v<?=h((string)$template['template_version'])?>)</h2>
    <p class="muted">
      Gespeichert wird als <code>template_fields.meta_json.system_binding</code>.
      Die Werte werden später automatisch via <code>apply_system_bindings()</code> in <code>field_values</code> geschrieben.
    </p>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="save_bindings">
      <input type="hidden" name="template_id" value="<?=h((string)$template['id'])?>">

      <table class="table">
        <thead>
          <tr>
            <th style="width:320px;">Stammdatum</th>
            <th>PDF-Formfeld</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($SYSTEM_KEYS as $sysKey => $label): ?>
            <tr>
              <td>
                <div style="font-weight:700;"><?=h($label)?></div>
                <div class="muted"><code><?=h($sysKey)?></code></div>
              </td>
              <td>
                <select name="binding[<?=h($sysKey)?>]">
                  <option value="0">— nicht zuordnen —</option>
                  <?php foreach ($fields as $f):
                    $fid = (int)$f['id'];
                    $fname = (string)$f['field_name'];
                    $lab = (string)($f['label'] ?? '');
                    $selected = isset($currentBindings[$sysKey]) && $currentBindings[$sysKey] === $fname;
                  ?>
                    <option value="<?=h((string)$fid)?>" <?=$selected?'selected':''?>>
                      <?=h($fname)?><?= $lab ? ' — ' . h($lab) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions" style="justify-content:flex-start;">
        <button class="btn primary" type="submit">Speichern</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Vorschau</h2>
    <p class="muted">Wähle ein Kind, um die Werte zu sehen.</p>

    <form method="get" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
      <input type="hidden" name="template_id" value="<?=h((string)$templateId)?>">
      <div>
        <label>Kind auswählen</label>
        <select name="preview_student_id" onchange="this.form.submit()">
          <option value="0">— bitte wählen —</option>
          <?php foreach ($studentsForPreview as $s):
            $sid = (int)$s['id'];
            $t = trim((string)$s['last_name'] . ', ' . (string)$s['first_name']);
            $c = trim((string)$s['school_year'] . ' ' . (string)$s['class_name']);
          ?>
            <option value="<?=h((string)$sid)?>" <?=$sid===$previewStudentId?'selected':''?>>
              <?=h($t)?><?= $c ? ' · ' . h($c) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <?php if ($previewStudentId > 0): ?>
          <a class="btn secondary" href="<?=h(url('admin/template_mappings.php?template_id='.(int)$templateId))?>">Zurücksetzen</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($preview): ?>
      <table class="table" style="margin-top:12px;">
        <thead>
          <tr>
            <th>Stammdatum</th>
            <th>Wert</th>
            <th>Ziel-Feld</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($SYSTEM_KEYS as $sysKey => $label):
            $val = preview_value_map($sysKey, $preview);
            $target = $currentBindings[$sysKey] ?? '';
          ?>
            <tr>
              <td><?=h($label)?> <span class="muted"><code><?=h($sysKey)?></code></span></td>
              <td><?=h($val)?></td>
              <td><?= $target ? h($target) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php render_admin_footer(); ?>
