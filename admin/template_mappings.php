<?php
// admin/template_mappings.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$customStudentFields = list_student_custom_fields($pdo);

$templateId = (int)($_GET['template_id'] ?? ($_POST['template_id'] ?? 0));
$previewStudentId = (int)($_GET['preview_student_id'] ?? 0);

$err = '';
$ok  = '';

$tx = [
  'title' => t('admin.template_mappings.title', 'Stammdaten-Mapping'),
  'back_templates' => t('admin.template_mappings.back_templates', '← zurück zu den Templates'),
  'description' => t('admin.template_mappings.description', 'Hier legst du fest, wie Stammdaten (z.B. Vor- und Nachname, Klasse) automatisch in PDF-Felder übernommen werden. Pro PDF-Feld kannst du einen Text mit Platzhaltern definieren. Platzhalter werden als <code>{{student.first_name}}</code> geschrieben.'),
  'template_label' => t('admin.template_mappings.template_label', 'Template'),
  'template_select' => t('admin.template_mappings.template_select', '— Template auswählen —'),
  'fields_edit' => t('admin.template_mappings.fields_edit', 'Felder bearbeiten'),
  'mapping_for' => t('admin.template_mappings.mapping_for', 'Mapping für: {template} (v{version})'),
  'tip' => t('admin.template_mappings.tip', 'Tipp: Du kannst frei Text schreiben und Platzhalter einfügen – z.B. <code>{{student.first_name}} {{student.last_name}}</code>. So sind auch Kombinationen möglich, und du kannst denselben Wert in mehrere Felder schreiben, indem du mehrere Felder mit demselben Platzhalter belegst. Gespeichert wird in <code>template_fields.meta_json.system_binding_tpl</code> (und bei einem einzelnen Platzhalter zusätzlich in <code>system_binding</code> für Abwärtskompatibilität).'),
  'placeholder_label' => t('admin.template_mappings.placeholder_label', 'Platzhalter einfügen'),
  'insert_btn' => t('admin.template_mappings.insert_btn', 'In das aktive Feld einfügen'),
  'bulk_label' => t('admin.template_mappings.bulk_label', 'Bulk-Template (für markierte Felder)'),
  'bulk_placeholder' => t('admin.template_mappings.bulk_placeholder', '{{student.first_name}} {{student.last_name}}'),
  'bulk_apply' => t('admin.template_mappings.bulk_apply', 'Auf markierte Felder anwenden'),
  'bulk_clear' => t('admin.template_mappings.bulk_clear', 'Markierte leeren'),
  'table_field' => t('admin.template_mappings.table_field', 'PDF-Formfeld'),
  'table_template' => t('admin.template_mappings.table_template', 'Binding-Template'),
  'table_preview' => t('admin.template_mappings.table_preview', 'Vorschau'),
  'placeholder_hint' => t('admin.template_mappings.placeholder_hint', 'Platzhalter: <code>{{student.first_name}}</code>, <code>{{student.last_name}}</code>, <code>{{class.display}}</code> …'),
  'save' => t('admin.template_mappings.save', 'Speichern'),
  'preview_title' => t('admin.template_mappings.preview_title', 'Vorschau'),
  'preview_hint' => t('admin.template_mappings.preview_hint', 'Wähle einen Schüler aus, um die Platzhalter-Ersetzungen zu prüfen.'),
  'preview_student_label' => t('admin.template_mappings.preview_student_label', 'Schüler'),
  'preview_select' => t('admin.template_mappings.preview_select', '— Vorschau-Schüler auswählen —'),
  'preview_reset' => t('admin.template_mappings.preview_reset', 'Zurücksetzen'),
  'preview_table_placeholder' => t('admin.template_mappings.preview_table_placeholder', 'Platzhalter'),
  'preview_table_value' => t('admin.template_mappings.preview_table_value', 'Wert'),
  'template_inactive' => t('admin.template_mappings.template_inactive', '[inaktiv]'),
  'system_student_first' => t('admin.template_mappings.system.student_first_name', 'Schüler: Vorname'),
  'system_student_last' => t('admin.template_mappings.system.student_last_name', 'Schüler: Nachname'),
  'system_student_dob' => t('admin.template_mappings.system.student_dob', 'Schüler: Geburtsdatum'),
  'system_class_year' => t('admin.template_mappings.system.class_school_year', 'Klasse: Schuljahr'),
  'system_class_grade' => t('admin.template_mappings.system.class_grade_level', 'Klasse: Klassenstufe'),
  'system_class_label' => t('admin.template_mappings.system.class_label', 'Klasse: Bezeichnung (a/b/...)'),
  'system_class_display' => t('admin.template_mappings.system.class_display', 'Klasse: Anzeige (z.B. 1a)'),
  'system_student_prefix' => t('admin.template_mappings.system.student_prefix', 'Schüler'),
  'ok_saved' => t('admin.template_mappings.ok_saved', 'Mapping gespeichert.'),
  'error_invalid_action' => t('admin.template_mappings.error_invalid_action', 'Ungültige Aktion.'),
  'error_template_missing' => t('admin.template_mappings.error_template_missing', 'template_id fehlt.'),
];

// IMPORTANT: Keys must match resolve_system_binding_value() in bootstrap.php
$SYSTEM_KEYS = [
  'student.first_name'    => $tx['system_student_first'],
  'student.last_name'     => $tx['system_student_last'],
  'student.date_of_birth' => $tx['system_student_dob'],
  'class.school_year'     => $tx['system_class_year'],
  'class.grade_level'     => $tx['system_class_grade'],
  'class.label'           => $tx['system_class_label'],
  'class.display'         => $tx['system_class_display'],
];
foreach ($customStudentFields as $cf) {
  $key = trim((string)($cf['field_key'] ?? ''));
  if ($key === '') continue;
  $SYSTEM_KEYS['student.custom.' . $key] = $tx['system_student_prefix'] . ': ' . trim((string)($cf['label'] ?? $key));
}

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

/**
 * Returns [template_field_id => binding_template_string]
 *
 * - New storage: meta_json.system_binding_tpl
 * - Backward compatibility:
 *   - If only meta_json.system_binding is set, we represent it as "{{key}}".
 */
function current_binding_templates_map(array $fields): array {
  $out = [];
  foreach ($fields as $f) {
    $meta = meta_read_map($f['meta_json'] ?? null);

    $tpl = (string)($meta['system_binding_tpl'] ?? '');
    if ($tpl === '') {
      $legacy = (string)($meta['system_binding'] ?? '');
      if ($legacy !== '') $tpl = '{{' . $legacy . '}}';
    }

    if ($tpl !== '') $out[(int)$f['id']] = $tpl;
  }
  return $out;
}

function get_student_preview_map(PDO $pdo, int $studentId): ?array {
  $st = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.date_of_birth,
            c.school_year AS class_school_year, c.grade_level AS class_grade_level, c.label AS class_label, c.name AS class_name
     FROM students s
     LEFT JOIN classes c ON c.id=s.class_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  $row['custom_values'] = student_custom_value_map($pdo, $studentId);
  return $row;
}

function preview_value_map(string $systemKey, array $row): string {
  if (strpos($systemKey, 'student.custom.') === 0) {
    $k = substr($systemKey, strlen('student.custom.'));
    if ($k === '') return '';
    $custom = $row['custom_values'] ?? [];
    return (string)($custom[$k] ?? '');
  }

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

/**
 * Resolve a binding template like:
 *   "{{student.first_name}} {{student.last_name}} ({{class.display}})"
 */
function resolve_binding_template(string $tpl, array $previewRow): string {
  // Replace {{ key }} placeholders
  $out = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function($m) use ($previewRow) {
    $key = (string)$m[1];
    return preview_value_map($key, $previewRow);
  }, $tpl);

  if ($out === null) return '';
  return $out;
}

/**
 * If the template is exactly one placeholder (optional surrounding whitespace),
 * return that key. Otherwise return ''.
 */
function template_to_single_key(string $tpl): string {
  $t = trim($tpl);
  if (preg_match('/^\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}$/', $t, $m)) {
    return (string)$m[1];
  }
  return '';
}

// POST: save binding templates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_bindings') throw new RuntimeException($tx['error_invalid_action']);

    $templateId = (int)($_POST['template_id'] ?? 0);
    if ($templateId <= 0) throw new RuntimeException($tx['error_template_missing']);

    $tpls = $_POST['tpl'] ?? [];
    if (!is_array($tpls)) $tpls = [];

    $fields = list_template_fields_map($pdo, $templateId);
    $fieldIds = array_map(fn($f) => (int)$f['id'], $fields);
    $fieldIdSet = array_fill_keys($fieldIds, true);

    $pdo->beginTransaction();

    foreach ($fields as $f) {
      $fid = (int)$f['id'];
      $rawTpl = isset($tpls[(string)$fid]) ? (string)$tpls[(string)$fid] : '';
      $rawTpl = trim($rawTpl);

      $meta = meta_read_map($f['meta_json'] ?? null);

      // Clear legacy + new keys first
      if (array_key_exists('system_binding', $meta)) unset($meta['system_binding']);
      if (array_key_exists('system_binding_tpl', $meta)) unset($meta['system_binding_tpl']);

      if ($rawTpl !== '') {
        // Store new template key
        $meta['system_binding_tpl'] = $rawTpl;

        // Backward compatibility: also store legacy system_binding if template is a single placeholder
        $single = template_to_single_key($rawTpl);
        if ($single !== '') $meta['system_binding'] = $single;
      }

      $pdo->prepare("UPDATE template_fields SET meta_json=? WHERE id=?")
          ->execute([meta_write_map($meta), $fid]);
    }

    $pdo->commit();

    audit('admin_template_system_bindings_save', (int)(current_user()['id'] ?? 0), [
      'template_id' => $templateId,
      'mode' => 'tpl',
    ]);

    $ok = $tx['ok_saved'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$templates = list_templates_map($pdo);
$template  = $templateId > 0 ? load_template_map($pdo, $templateId) : null;
$fields    = $template ? list_template_fields_map($pdo, (int)$template['id']) : [];
$currentTpl = current_binding_templates_map($fields);

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

render_admin_header($tx['title']);
?>

<div class="card">
    <div class="row-actions" style="float: right;">
        <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>"><?=h($tx['back_templates'])?></a>
    </div>
  <h1><?=h($tx['title'])?></h1>
  <p class="muted"><?= $tx['description'] ?></p>
</div>

<div class="card">
  <form method="get" class="row-actions" style="align-items:flex-end;">
    <div style="min-width:340px;">
      <label for="template_id" class="muted" style="display:block;margin-bottom:6px;"><?=h($tx['template_label'])?></label>
      <select name="template_id" id="template_id" onchange="this.closest('form').submit()">
        <option value="0"><?=h($tx['template_select'])?></option>
        <?php foreach ($templates as $t):
          $tid = (int)$t['id'];
          $active = (int)($t['is_active'] ?? 0) === 1;
          $name = (string)$t['name'];
          $ver  = (string)($t['template_version'] ?? '');
        ?>
          <option value="<?=h((string)$tid)?>" <?=$tid===$templateId?'selected':''?>>
            <?=h($name)?><?= $ver!=='' ? ' (v'.h($ver).')' : '' ?><?= $active ? '' : ' ' . h($tx['template_inactive']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <?php if ($template): ?>
        <a class="btn secondary" href="<?=h(url('admin/template_fields.php?template_id='.(int)$template['id']))?>"><?=h($tx['fields_edit'])?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

  <?php if ($err): ?>
    <div class="alert error"><?=h($err)?></div>
  <?php elseif ($ok): ?>
    <div class="alert success"><?=h($ok)?></div>
  <?php endif; ?>

<?php if ($template): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?=h(strtr($tx['mapping_for'], [
      '{template}' => (string)$template['name'],
      '{version}' => (string)$template['template_version'],
    ]))?></h2>

    <p class="muted" style="margin-top:-4px;">
      <?= $tx['tip'] ?>
    </p>

    <div class="row-actions" style="align-items:flex-end; gap:10px; flex-wrap:wrap;">
      <div>
        <label class="muted" style="display:block;margin-bottom:6px;"><?=h($tx['placeholder_label'])?></label>
        <select id="phSelect">
          <?php foreach ($SYSTEM_KEYS as $k => $lab): ?>
            <option value="<?=h('{{'.$k.'}}')?>"><?=h($lab)?> — <?=h($k)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="actions" style="justify-content:flex-start;">
        <button type="button" class="btn secondary" id="phInsertBtn"><?=h($tx['insert_btn'])?></button>
      </div>

      <div style="flex:1; min-width:260px;">
        <label class="muted" style="display:block;margin-bottom:6px;"><?=h($tx['bulk_label'])?></label>
        <input type="text" id="bulkTpl" class="input" placeholder="<?=h($tx['bulk_placeholder'])?>" />
      </div>

      <div class="actions" style="justify-content:flex-start;">
        <button type="button" class="btn secondary" id="bulkApplyBtn"><?=h($tx['bulk_apply'])?></button>
        <button type="button" class="btn secondary" id="bulkClearBtn"><?=h($tx['bulk_clear'])?></button>
      </div>
    </div>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="save_bindings">
      <input type="hidden" name="template_id" value="<?=h((string)$template['id'])?>">

      <table class="table">
        <thead>
          <tr>
            <th style="width:40px;"><input type="checkbox" id="checkAll"></th>
            <th style="width:360px;"><?=h($tx['table_field'])?></th>
            <th><?=h($tx['table_template'])?></th>
            <th style="width:220px;"><?=h($tx['table_preview'])?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $f):
            $fid = (int)$f['id'];
            $fname = (string)$f['field_name'];
            $lab = trim((string)($f['label'] ?? ''));
            $tpl = (string)($currentTpl[$fid] ?? '');
            $previewVal = ($preview && $tpl !== '') ? resolve_binding_template($tpl, $preview) : '';
          ?>
            <tr>
              <td>
                <input type="checkbox" class="rowCheck" data-fid="<?=h((string)$fid)?>">
              </td>
              <td>
                <div style="font-weight:700;"><?=h($fname)?></div>
                <?php if ($lab !== ''): ?>
                  <div class="muted"><?=h($lab)?></div>
                <?php endif; ?>
                <div class="muted"><code>#<?=h((string)$fid)?></code></div>
              </td>
              <td>
                <input
                  type="text"
                  class="input tplInput"
                  data-fid="<?=h((string)$fid)?>"
                  name="tpl[<?=h((string)$fid)?>]"
                  value="<?=h($tpl)?>"
                  placeholder="<?=h(t('admin.template_mappings.template_placeholder', 'z.B. {{student.first_name}} {{student.last_name}}'))?>"
                  autocomplete="off"
                >
                <div class="muted" style="margin-top:6px;">
                  <?= $tx['placeholder_hint'] ?>
                </div>
              </td>
              <td>
                <?php if (!$preview): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <?= $tpl !== '' ? h($previewVal) : '<span class="muted">—</span>' ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions" style="justify-content:flex-start;">
        <button class="btn primary" type="submit"><?=h($tx['save'])?></button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?=h($tx['preview_title'])?></h2>
    <p class="muted" style="margin-top:-4px;"><?=h($tx['preview_hint'])?></p>

    <form method="get" class="row-actions" style="align-items:flex-end;">
      <input type="hidden" name="template_id" value="<?=h((string)$templateId)?>">
      <div style="min-width:340px;">
        <label class="muted" style="display:block;margin-bottom:6px;"><?=h($tx['preview_student_label'])?></label>
        <select name="preview_student_id" onchange="this.closest('form').submit()">
          <option value="0"><?=h($tx['preview_select'])?></option>
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
          <a class="btn secondary" href="<?=h(url('admin/template_mappings.php?template_id='.(int)$templateId))?>"><?=h($tx['preview_reset'])?></a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($preview): ?>
      <table class="table" style="margin-top:12px;">
        <thead>
          <tr>
            <th><?=h($tx['preview_table_placeholder'])?></th>
            <th><?=h($tx['preview_table_value'])?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($SYSTEM_KEYS as $sysKey => $label): ?>
            <tr>
              <td><?=h($label)?> <span class="muted"><code><?=h($sysKey)?></code></span></td>
              <td><?=h(preview_value_map($sysKey, $preview))?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <script>
    (function(){
      let activeInput = null;

      function insertAtCursor(el, text) {
        if (!el) return;
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        const before = el.value.substring(0, start);
        const after = el.value.substring(end);
        el.value = before + text + after;
        const pos = start + text.length;
        el.focus();
        el.setSelectionRange(pos, pos);
      }

      document.querySelectorAll('.tplInput').forEach(function(inp){
        inp.addEventListener('focus', function(){ activeInput = inp; });
        inp.addEventListener('click', function(){ activeInput = inp; });
      });

      const phSelect = document.getElementById('phSelect');
      const phInsertBtn = document.getElementById('phInsertBtn');
      if (phInsertBtn) {
        phInsertBtn.addEventListener('click', function(){
          const ph = phSelect ? phSelect.value : '';
          if (!ph) return;
          insertAtCursor(activeInput, ph);
        });
      }

      const checkAll = document.getElementById('checkAll');
      if (checkAll) {
        checkAll.addEventListener('change', function(){
          document.querySelectorAll('.rowCheck').forEach(function(cb){
            cb.checked = checkAll.checked;
          });
        });
      }

      function selectedFieldIds() {
        const ids = [];
        document.querySelectorAll('.rowCheck').forEach(function(cb){
          if (cb.checked) ids.push(cb.getAttribute('data-fid'));
        });
        return ids;
      }

      const bulkTpl = document.getElementById('bulkTpl');
      const bulkApplyBtn = document.getElementById('bulkApplyBtn');
      const bulkClearBtn = document.getElementById('bulkClearBtn');

      if (bulkApplyBtn) {
        bulkApplyBtn.addEventListener('click', function(){
          const ids = selectedFieldIds();
          if (!ids.length) return;
          const t = (bulkTpl ? bulkTpl.value : '') || '';
          ids.forEach(function(fid){
            const inp = document.querySelector('.tplInput[data-fid="'+fid+'"]');
            if (inp) inp.value = t;
          });
        });
      }

      if (bulkClearBtn) {
        bulkClearBtn.addEventListener('click', function(){
          const ids = selectedFieldIds();
          if (!ids.length) return;
          ids.forEach(function(fid){
            const inp = document.querySelector('.tplInput[data-fid="'+fid+'"]');
            if (inp) inp.value = '';
          });
        });
      }
    })();
  </script>
<?php endif; ?>

<?php render_admin_footer(); ?>
