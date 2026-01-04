<?php
// student/ajax/wizard_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/../../shared/value_history.php';
require_student();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

/**
 * Option-list templates: keep selections stable even if the *value* changes.
 * We store/resolve by option_list_items.id (option_item_id) and derive the current value from that.
 */
function option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function load_option_list_items(PDO $pdo, int $listId): array {
  if ($listId <= 0) return [];
  $st = $pdo->prepare(
    "SELECT id, value, label, label_en, icon_id
     FROM option_list_items
     WHERE list_id=?
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$listId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = [
      'option_item_id' => (int)$r['id'],
      'value' => (string)($r['value'] ?? ''),
      'label' => (string)($r['label'] ?? ''),
      'label_en' => (string)($r['label_en'] ?? ''),
      'icon_id' => $r['icon_id'] !== null ? (int)$r['icon_id'] : null,
    ];
  }
  return $out;
}

function resolve_option_value_text(PDO $pdo, array $meta, ?string $valueJsonRaw, ?string $valueTextRaw): array {
  // Returns ['text'=>?, 'json'=>?] with text resolved to the CURRENT option_list_items.value (if possible).
  $out = ['text' => $valueTextRaw, 'json' => $valueJsonRaw];
  $listId = option_list_id_from_meta($meta);
  if ($listId <= 0) return $out;

  $vj = null;
  if ($valueJsonRaw) {
    $tmp = json_decode($valueJsonRaw, true);
    if (is_array($tmp)) $vj = $tmp;
  }

  $optId = is_array($vj) && isset($vj['option_item_id']) ? (int)$vj['option_item_id'] : 0;
  if ($optId > 0) {
    $st = $pdo->prepare("SELECT id, value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['text'] = (string)($row['value'] ?? '');
      return $out;
    }
  }

  // Backward compatibility: try to map by old value_text.
  $vt = $valueTextRaw !== null ? trim((string)$valueTextRaw) : '';
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT id, value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $new = ['option_item_id' => (int)$row['id']];
      $out['json'] = json_encode($new, JSON_UNESCAPED_UNICODE);
      $out['text'] = (string)($row['value'] ?? $vt);
      return $out;
    }
  }

  return $out;
}

function group_key_from_meta(array $meta): string {
  $g = (string)($meta['group'] ?? '');
  $g = trim($g);

  // Normalize: ignore suffix after '-' (e.g. "Ger1-T" -> "Ger1")
  if ($g !== '' && strpos($g, '-') !== false) {
    $g = explode('-', $g, 2)[0];
    $g = trim($g);
  }

  return $g !== '' ? $g : 'Allgemein';
}

function student_wizard_display_mode_from_class(array $classRow): string {
  $mode = (string)($classRow['student_wizard_display'] ?? 'groups');
  $mode = strtolower(trim($mode));
  return in_array($mode, ['groups','items'], true) ? $mode : 'groups';
}

function label_for_lang(?string $labelDe, ?string $labelEn, string $lang, string $fallback=''): string {
  $de = trim((string)$labelDe);
  $en = trim((string)$labelEn);
  if ($lang === 'en' && $en !== '') return $en;
  if ($de !== '') return $de;
  return $fallback;
}

function group_title_override_lang(string $groupKey, string $lang): string {
  $cfg = app_config();
  $bucket = ($lang === 'en') ? 'group_titles_en' : 'group_titles';
  $map = $cfg['student'][$bucket] ?? [];
  if (!is_array($map)) return $groupKey;
  $t = $map[$groupKey] ?? null;
  $t = is_string($t) ? trim($t) : '';
  return $t !== '' ? $t : $groupKey;
}

function group_title_from_meta(array $meta, string $groupKey, string $lang): string {
  if ($lang === 'en') {
    $t = (string)($meta['group_title_en'] ?? '');
    $t = trim($t);
    if ($t !== '') return $t;
  }
  return group_title_override_lang($groupKey, $lang);
}

function get_student_and_class(PDO $pdo, int $studentId): array {
  $st = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.class_id,
            c.school_year, c.grade_level, c.label, c.name AS class_name,
            c.template_id AS class_template_id,
            c.student_wizard_display AS student_wizard_display
     FROM students s
     LEFT JOIN classes c ON c.id=s.class_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Schüler nicht gefunden.');
  return $row;
}

function class_display(array $row): string {
  $label = (string)($row['label'] ?? '');
  $grade = isset($row['grade_level']) ? (int)$row['grade_level'] : null;
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  $name = (string)($row['class_name'] ?? '');
  return $name !== '' ? $name : '—';
}

function child_intro_file_abs(): string {
  $cfg = app_config();
  $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
  $rootAbs = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  return rtrim($rootAbs, '/\\') . '/' . trim($uploadsRel, '/\\') . '/child_intro.html';
}

function sanitize_intro_html(string $html): string {
  $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
  return trim($html);
}

function render_intro_placeholders(string $html, array $studentRow): string {
  $cfg = app_config();
  $brand = $cfg['app']['brand'] ?? [];
  $orgName = (string)($brand['org_name'] ?? 'LEB Tool');

  $first = (string)($studentRow['first_name'] ?? '');
  $last  = (string)($studentRow['last_name'] ?? '');
  $studentName = trim($first . ' ' . $last);
  $class = class_display($studentRow);
  $schoolYear = (string)($studentRow['school_year'] ?? '');

  $rep = [
    '{{org_name}}'     => htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'),
    '{{student_name}}' => htmlspecialchars($studentName !== '' ? $studentName : $first, ENT_QUOTES, 'UTF-8'),
    '{{first_name}}'   => htmlspecialchars($first, ENT_QUOTES, 'UTF-8'),
    '{{last_name}}'    => htmlspecialchars($last, ENT_QUOTES, 'UTF-8'),
    '{{class}}'        => htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
    '{{school_year}}'  => htmlspecialchars($schoolYear, ENT_QUOTES, 'UTF-8'),
  ];

  return str_replace(array_keys($rep), array_values($rep), $html);
}

/**
 * IMPORTANT: template is assigned per class (classes.template_id).
 * If no template is assigned -> student cannot proceed.
 */
function template_for_student(PDO $pdo, int $studentId): array {
  $st = $pdo->prepare(
    "SELECT c.id AS class_id, c.template_id, t.id AS tid, t.name, t.template_version, t.is_active
     FROM students s
     INNER JOIN classes c ON c.id=s.class_id
     LEFT JOIN templates t ON t.id=c.template_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Schüler nicht gefunden.');

  $tid = (int)($row['tid'] ?? 0);
  if ($tid <= 0) {
    throw new RuntimeException('Für deine Klasse wurde noch keine Vorlage zugeordnet. Bitte wende dich an deine Lehrkraft.');
  }
  if ((int)($row['is_active'] ?? 0) !== 1) {
    throw new RuntimeException('Die Vorlage deiner Klasse ist aktuell inaktiv. Bitte wende dich an deine Lehrkraft.');
  }

  return [
    'id' => $tid,
    'name' => (string)($row['name'] ?? ''),
    'template_version' => (int)($row['template_version'] ?? 0),
  ];
}

function find_or_create_class_report_instance(PDO $pdo, int $templateId, int $classId, string $schoolYear): int {
  $st = $pdo->prepare(
    "SELECT id
     FROM report_instances
     WHERE template_id=? AND student_id=0 AND school_year=? AND period_label='__class__'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  return -1;
}

function load_class_lookup(PDO $pdo, int $templateId, int $classReportId): array {
  // NOTE: include label_en so label_for_lang can work
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.help_text, tf.field_type,
            fv.value_text
     FROM template_fields tf
     LEFT JOIN field_values fv
       ON fv.template_field_id=tf.id AND fv.report_instance_id=?
     WHERE tf.template_id=?
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$classReportId, $templateId]);
  $out = [];
  $lang = ui_lang();
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($r['field_name'] ?? '');
    if ($name === '') continue;
    $out[$name] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => $name,
      'type' => (string)($r['field_type'] ?? 'text'),
      'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, $name),
      'help' => (string)($r['help_text'] ?? ''),
      'value' => (string)($r['value_text'] ?? ''),
    ];
  }
  return $out;
}

function find_or_create_report_instance(PDO $pdo, int $studentId, int $templateId, string $schoolYear): array {
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id=? AND school_year=? AND period_label='Standard'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId, $schoolYear]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);

  if ($ri) {
    return [
      'report_instance_id' => (int)$ri['id'],
      'status' => (string)$ri['status'],
    ];
  }

  return [
      'report_instance_id' => -1,
      'status' => 'locked',
    ];
}

function ensure_editable_or_throw(PDO $pdo, int $reportId): void {
  $st = $pdo->prepare("SELECT status FROM report_instances WHERE id=? LIMIT 1");
  $st->execute([$reportId]);
  $status = (string)($st->fetchColumn() ?: '');
  if ($status !== 'draft') throw new RuntimeException('Abgabe bereits erfolgt oder gesperrt.');
}

function get_report_status(PDO $pdo, int $reportId): string {
  $st = $pdo->prepare("SELECT status FROM report_instances WHERE id=? LIMIT 1");
  $st->execute([$reportId]);
  $s = (string)($st->fetchColumn() ?: '');
  return $s !== '' ? $s : 'draft';
}

function load_all_fields_lookup(PDO $pdo, int $templateId, int $reportId): array {
  // Lookup for ALL template fields (child + teacher), keyed by field_name
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.help_text, tf.field_type,
            fv.value_text
     FROM template_fields tf
     LEFT JOIN field_values fv
       ON fv.template_field_id=tf.id AND fv.report_instance_id=?
     WHERE tf.template_id=?
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId, $templateId]);
  $out = [];
  $lang = ui_lang();
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($r['field_name'] ?? '');
    if ($name === '') continue;
    $out[$name] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => $name,
      'type' => (string)($r['field_type'] ?? 'text'),
      'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, $name),
      'help' => (string)($r['help_text'] ?? ''),
      'value' => (string)($r['value_text'] ?? ''),
    ];
  }
  return $out;
}

function load_child_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, label_en, help_text, is_multiline, options_json, meta_json, sort_order
     FROM template_fields
     WHERE template_id=? AND can_child_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_child_values(PDO $pdo, int $reportInstanceId): array {
  $st = $pdo->prepare(
    "SELECT template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id=? AND source='child'"
  );
  $st->execute([$reportInstanceId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fid = (int)$r['template_field_id'];
    $out[$fid] = [
      'text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'json' => $r['value_json'] !== null ? $r['value_json'] : null,
    ];
  }
  return $out;
}

function resolve_icon_urls(PDO $pdo, array $iconIds): array {
  $iconIds = array_values(array_unique(array_filter(array_map('intval', $iconIds), fn($x)=>$x>0)));
  if (!$iconIds) return [];
  $in = implode(',', array_fill(0, count($iconIds), '?'));
  $st = $pdo->prepare("SELECT id, storage_path FROM icon_library WHERE id IN ($in)");
  $st->execute($iconIds);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[(int)$r['id']] = url((string)$r['storage_path']);
  }
  return $map;
}

function all_child_fields_filled(PDO $pdo, int $templateId, int $reportId): bool {
  $fields = load_child_fields($pdo, $templateId);
  if (!$fields) return true;

  $vals = load_child_values($pdo, $reportId);
  foreach ($fields as $f) {
    $fid = (int)$f['id'];
    $v = $vals[$fid]['text'] ?? null;
    if (trim((string)$v) === '') return false;
  }
  return true;
}

try {
  $pdo = db();
  $studentId = (int)($_SESSION['student']['id'] ?? 0);
  $lang = ui_lang();
  if ($studentId <= 0) throw new RuntimeException('Nicht eingeloggt.');

  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $action = (string)($data['action'] ?? '');
  if (!in_array($action, ['bootstrap','save_value','submit'], true)) {
    throw new RuntimeException('Ungültige Aktion.');
  }

  $tpl = template_for_student($pdo, $studentId);
  $templateId = (int)$tpl['id'];

  $studentRow = get_student_and_class($pdo, $studentId);
  $schoolYear = (string)($studentRow['school_year'] ?? '');
  if ($schoolYear === '') {
    $cfg = app_config();
    $schoolYear = (string)($cfg['app']['default_school_year'] ?? '');
  }
  if ($schoolYear === '') throw new RuntimeException('Schuljahr konnte nicht ermittelt werden.');

  $ctx = find_or_create_report_instance($pdo, $studentId, $templateId, $schoolYear);
  
  if ((int)$ctx['report_instance_id'] < 0) throw new RuntimeException('Kein Bericht verfügbar.');
  
  $reportId = (int)$ctx['report_instance_id'];

  $status = get_report_status($pdo, $reportId);
  $childCanEdit = ($status === 'draft');

  if ($action === 'bootstrap') {
    $introAbs = child_intro_file_abs();
    $introHtml = '';
    if (is_file($introAbs)) {
      $introHtml = sanitize_intro_html((string)file_get_contents($introAbs));
      $introHtml = render_intro_placeholders($introHtml, $studentRow);
    }

    $fieldsRaw = load_child_fields($pdo, $templateId);
    $values = load_child_values($pdo, $reportId);

    $fieldLookup = load_all_fields_lookup($pdo, $templateId, $reportId);

    // Merge class-wide values into field_lookup so placeholders can resolve even if the student has no value.
    $classId = (int)($studentRow['class_id'] ?? 0);
    if ($classId > 0 && $schoolYear !== '') {
      $classReportId = find_or_create_class_report_instance($pdo, $templateId, $classId, $schoolYear);
      $classLookup = load_class_lookup($pdo, $templateId, $classReportId);

      foreach ($classLookup as $k => $v) {
        if (!isset($fieldLookup[$k])) {
          $fieldLookup[$k] = $v;
          continue;
        }
        $sv = (string)($fieldLookup[$k]['value'] ?? '');
        $cv = (string)($v['value'] ?? '');
        if (trim($sv) === '' && trim($cv) !== '') {
          $fieldLookup[$k]['value'] = $cv;
        }
      }
    }

    $groups = [];
    $iconIds = [];
    $optCache = []; // listId => options array

    foreach ($fieldsRaw as $r) {
      $fid = (int)$r['id'];
      $meta = meta_read($r['meta_json'] ?? null);

      $gKey = group_key_from_meta($meta);
      $gTitle = group_title_from_meta($meta, $gKey, $lang);

      if (!isset($groups[$gKey])) {
        $groups[$gKey] = ['key' => $gKey, 'title' => $gTitle, 'fields' => []];
      } else {
        $groups[$gKey]['title'] = $gTitle;
      }

      $opts = [];
      $listId = option_list_id_from_meta($meta);

      if ($listId > 0) {
        if (!isset($optCache[$listId])) $optCache[$listId] = load_option_list_items($pdo, $listId);
        $opts = $optCache[$listId];
      } elseif (!empty($r['options_json'])) {
        // legacy / manual options_json
        $oj = json_decode((string)$r['options_json'], true);
        if (is_array($oj) && isset($oj['options']) && is_array($oj['options'])) {
          // allow optional label_en in JSON; pass through as-is
          $opts = $oj['options'];
        }
      }

      foreach ($opts as $o) {
        $iid = (int)($o['icon_id'] ?? 0);
        if ($iid > 0) $iconIds[] = $iid;
      }

      $raw = $values[$fid] ?? ['text' => null, 'json' => null];
      $val = resolve_option_value_text($pdo, $meta, $raw['json'] ?? null, $raw['text'] ?? null);

      $groups[$gKey]['fields'][] = [
        'id' => $fid,
        'name' => (string)$r['field_name'],
        'type' => (string)$r['field_type'],
        'label_raw' => (string)($r['label'] ?? $r['field_name']),
        'label_en_raw' => (string)($r['label_en'] ?? ''),
        'help_raw' => (string)($r['help_text'] ?? ''),
        'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, (string)$r['field_name']),
        'help' => (string)($r['help_text'] ?? ''),
        'required' => true,
        'multiline' => (int)($r['is_multiline'] ?? 0) === 1,
        'group' => $gKey,
        'options' => $opts,     // includes label_en now (for option-list templates)
        'value' => $val,
      ];
    }

    $iconMap = resolve_icon_urls($pdo, $iconIds);

    foreach ($groups as $gKey => $gData) {
      foreach ($gData['fields'] as $i => $f) {
        if (!empty($f['options']) && is_array($f['options'])) {
          foreach ($f['options'] as $k => $o) {
            $iid = (int)($o['icon_id'] ?? 0);
            $groups[$gKey]['fields'][$i]['options'][$k]['icon_url'] = ($iid > 0 && isset($iconMap[$iid])) ? $iconMap[$iid] : null;
          }
        }
      }
    }

    $steps = [];
    $steps[] = [
      'key' => 'intro',
      'title' => 'Start',
      'is_intro' => true,
      'intro_html' => $introHtml,
      'fields' => [],
    ];

    foreach ($groups as $gKey => $gData) {
      $steps[] = [
        'key' => $gKey,
        'title' => (string)$gData['title'],
        'fields' => $gData['fields'],
      ];
    }

    json_out([
      'ok' => true,
      'template' => [
        'id' => $templateId,
        'name' => (string)$tpl['name'],
        'version' => (int)$tpl['template_version'],
      ],
      'report_instance_id' => $reportId,
      'report_status' => $status,
      'child_can_edit' => $childCanEdit,
      'steps' => $steps,
      'field_lookup' => $fieldLookup,
      'ui' => [
        'display_mode' => student_wizard_display_mode_from_class($studentRow),
      ],
      'ui_lang' => $lang,
      'translations' => ui_translations(),
    ]);
  }

  if ($action === 'save_value') {
    ensure_editable_or_throw($pdo, $reportId);

    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($fieldId <= 0) throw new RuntimeException('template_field_id fehlt.');

    $st = $pdo->prepare(
      "SELECT id, field_type, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_child_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $type = (string)$frow['field_type'];
    $meta = meta_read($frow['meta_json'] ?? null);
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    $valueJson = null;

    if (in_array($type, ['radio','select','grade'], true)) {
      $valueText = $valueText !== null ? trim($valueText) : '';
      if ($valueText === '') $valueText = null;

      $listId = option_list_id_from_meta($meta);
      if ($listId > 0 && $valueText !== null) {
        $st2 = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
        $st2->execute([$listId, $valueText]);
        $optId = (int)($st2->fetchColumn() ?: 0);
        if ($optId > 0) {
          $valueJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
        }
      }
    } elseif ($type === 'checkbox') {
      $v = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
      $valueText = $v;
    } else {
      $valueText = $valueText !== null ? trim($valueText) : null;
      if ($valueText === '') $valueText = null;
    }

    $up = $pdo->prepare(
      "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_student_id, updated_at)
       VALUES (?, ?, ?, ?, 'child', ?, NOW())
       ON DUPLICATE KEY UPDATE
         value_text=VALUES(value_text),
         value_json=VALUES(value_json),
         source='child',
         updated_by_student_id=VALUES(updated_by_student_id),
         updated_at=NOW()"
    );
    $up->execute([$reportId, $fieldId, $valueText, $valueJson, $studentId]);

    record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'child', null, $studentId);

    json_out(['ok' => true]);
  }

  // submit
  ensure_editable_or_throw($pdo, $reportId);
  if (!all_child_fields_filled($pdo, $templateId, $reportId)) {
    throw new RuntimeException('Bitte fülle zuerst alle Felder aus.');
  }

  $pdo->prepare(
    "UPDATE report_instances
     SET status='submitted', locked_by_user_id=NULL, locked_at=NULL
     WHERE id=?"
  )->execute([$reportId]);

  audit('student_submit', null, ['student_id'=>$studentId,'report_instance_id'=>$reportId]);

  json_out(['ok' => true]);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
