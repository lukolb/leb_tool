<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$templateId = (int)($_GET['template_id'] ?? 0);
$err = '';
$ok = '';

$allowedTypes = ['text','multiline','date','number','grade','checkbox','radio','select','signature','other'];
$dateFormats = [
  'MM/DD/YYYY' => 'MM/DD/YYYY (US, z.B. 12/21/2025)',
  'DD.MM.YYYY' => 'DD.MM.YYYY (DE, z.B. 21.12.2025)',
  'YYYY-MM-DD' => 'YYYY-MM-DD (ISO, z.B. 2025-12-21)',
  'DD. MMMM YYYY' => 'DD. MMMM YYYY (DE, z.B. 21. Dezember 2025)',
];

function decode_json(?string $s): array {
  if (!$s) return [];
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}

function options_to_text(array $options): string {
  // options: [ ['value'=>'..','label'=>'..'], ...]
  $lines = [];
  foreach ($options as $o) {
    if (!is_array($o)) continue;
    $v = (string)($o['value'] ?? '');
    $l = (string)($o['label'] ?? $v);
    if ($v === '' && $l === '') continue;
    if ($v === $l || $l === '') $lines[] = $v;
    else $lines[] = $v . '|' . $l;
  }
  return implode("\n", $lines);
}

function text_to_options(string $text): array {
  // Format: one per line:
  // - "value|label" or
  // - "label" (then value=label)
  $out = [];
  $lines = preg_split("/\r\n|\n|\r/", trim($text));
  if (!$lines) return [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $parts = explode('|', $line, 2);
    $value = trim($parts[0] ?? '');
    $label = trim($parts[1] ?? $value);
    if ($value === '') continue;
    $out[] = ['value' => $value, 'label' => ($label !== '' ? $label : $value)];
  }
  return $out;
}

if ($templateId <= 0) {
  render_admin_header('Feld-Editor');
  echo '<div class="alert danger"><strong>template_id fehlt.</strong></div>';
  echo '<div class="card"><a class="btn secondary" href="'.h(url('admin/templates.php')).'">← Templates</a></div>';
  render_admin_footer();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $rows = $_POST['rows'] ?? null;
    if (!is_array($rows)) throw new RuntimeException('Ungültige Daten.');

    $pdo->beginTransaction();
    $upd = $pdo->prepare("
      UPDATE template_fields
      SET field_type=?,
          label=?,
          can_child_edit=?,
          can_teacher_edit=?,
          is_multiline=?,
          options_json=?,
          meta_json=?,
          updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND template_id=?
    ");

    $n = 0;
    foreach ($rows as $id => $r) {
      $id = (int)$id;
      if ($id <= 0 || !is_array($r)) continue;

      $type = (string)($r['field_type'] ?? 'other');
      if (!in_array($type, $allowedTypes, true)) $type = 'other';

      $label = trim((string)($r['label'] ?? ''));
      $child = !empty($r['can_child_edit']) ? 1 : 0;
      $teacher = !empty($r['can_teacher_edit']) ? 1 : 0;

      // multiline flag bleibt kompatibel – aber für type=multiline erzwingen wir es.
      $ml = !empty($r['is_multiline']) ? 1 : 0;
      if ($type === 'multiline') $ml = 1;

      // options_json
      $optText = (string)($r['options_text'] ?? '');
      $optionsJson = null;

      if (in_array($type, ['radio','select','grade'], true)) {
        $opts = text_to_options($optText);

        // Grade: wenn leer, setze default 1..6
        if ($type === 'grade' && count($opts) === 0) {
          $opts = [];
          for ($g = 1; $g <= 6; $g++) $opts[] = ['value'=>(string)$g,'label'=>(string)$g];
        }

        $optionsJson = json_encode(['options' => $opts], JSON_UNESCAPED_UNICODE);
      } else {
        $optionsJson = null;
      }

      // meta_json (date_format etc.)
      $meta = [];
      if ($type === 'date') {
        $df = (string)($r['date_format'] ?? 'MM/DD/YYYY');
        $meta['date_format'] = $df;
      }

      // Du kannst hier später weitere Regeln ergänzen:
      // - number: decimals, min/max
      // - text: max_length
      // - etc.

      $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

      // Optional harte Regel: Typ darf nicht other sein
      // if ($type === 'other') throw new RuntimeException('Bitte Typ setzen (kein other).');

      $upd->execute([$type, $label, $child, $teacher, $ml, $optionsJson, $metaJson, $id, $templateId]);
      $n++;
    }

    $pdo->commit();
    audit('template_fields_update', (int)current_user()['id'], ['template_id'=>$templateId,'count'=>$n]);
    $ok = "Gespeichert ($n Felder).";

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$tpl = $pdo->prepare("SELECT id, name, template_version FROM templates WHERE id=?");
$tpl->execute([$templateId]);
$template = $tpl->fetch(PDO::FETCH_ASSOC);

$fields = $pdo->prepare("
  SELECT id, field_name, field_type, label, is_multiline, can_child_edit, can_teacher_edit, options_json, meta_json
  FROM template_fields
  WHERE template_id=?
  ORDER BY sort_order ASC, field_name ASC
");
$fields->execute([$templateId]);
$list = $fields->fetchAll(PDO::FETCH_ASSOC);

render_admin_header('Feld-Editor');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">← Templates</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2>Template #<?=h((string)$templateId)?> – <?=h(($template['name'] ?? ''))?> v<?=h((string)($template['template_version'] ?? ''))?></h2>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Feldname</th>
          <th>Typ</th>
          <th>Label</th>
          <th>Optionen (radio/select/grade)</th>
          <th>Datumformat</th>
          <th>Kind</th>
          <th>Lehrer</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $f): ?>
          <?php
            $type = (string)$f['field_type'];
            $opt = decode_json($f['options_json'] ?? null);
            $optText = '';
            if (isset($opt['options']) && is_array($opt['options'])) $optText = options_to_text($opt['options']);

            $meta = decode_json($f['meta_json'] ?? null);
            $df = (string)($meta['date_format'] ?? 'MM/DD/YYYY');
          ?>
          <tr>
            <td><?=h((string)$f['id'])?></td>
            <td><?=h($f['field_name'])?></td>

            <td>
              <select name="rows[<?=h((string)$f['id'])?>][field_type]">
                <?php foreach ($allowedTypes as $t): ?>
                  <option value="<?=h($t)?>" <?=($type===$t?'selected':'')?>><?=h($t)?></option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <input name="rows[<?=h((string)$f['id'])?>][label]"
                     value="<?=h($f['label'] ?? '')?>"
                     placeholder="<?=h($f['field_name'])?>">
            </td>

            <td>
              <?php if (in_array($type, ['radio','select','grade'], true)): ?>
                <textarea name="rows[<?=h((string)$f['id'])?>][options_text]"
                          rows="3"
                          placeholder="Eine Option pro Zeile&#10;value|label oder nur label"><?=h($optText)?></textarea>
              <?php else: ?>
                <span class="muted">–</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($type === 'date'): ?>
                <select name="rows[<?=h((string)$f['id'])?>][date_format]">
                  <?php foreach ($dateFormats as $k => $label): ?>
                    <option value="<?=h($k)?>" <?=($df===$k?'selected':'')?>><?=h($label)?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <span class="muted">–</span>
              <?php endif; ?>
            </td>

            <td style="text-align:center;">
              <input type="checkbox" name="rows[<?=h((string)$f['id'])?>][can_child_edit]" value="1"
                     <?=((int)$f['can_child_edit']===1?'checked':'')?>>
            </td>
            <td style="text-align:center;">
              <input type="checkbox" name="rows[<?=h((string)$f['id'])?>][can_teacher_edit]" value="1"
                     <?=((int)$f['can_teacher_edit']===1?'checked':'')?>>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions" style="margin-top:12px;">
      <button class="btn primary" type="submit">Speichern</button>
    </div>

    <p class="muted" style="margin-top:10px;">
      Optionen-Format: <code>value|label</code> oder nur <code>label</code> (dann ist value=label).
      Empfehlung für PDF-Radio: value sollte exakt den Export-Werten im PDF entsprechen.
    </p>
  </form>
</div>

<?php render_admin_footer(); ?>
