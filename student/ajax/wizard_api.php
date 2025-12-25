<?php
// student/ajax/wizard_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
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

function group_key_from_meta(array $meta): string {
  $g = (string)($meta['group'] ?? '');
  $g = trim($g);
  return $g !== '' ? $g : 'Allgemein';
}

function student_wizard_display_mode(): string {
  $cfg = app_config();
  $mode = (string)($cfg['student']['wizard_display'] ?? 'groups');
  $mode = strtolower(trim($mode));
  if (!in_array($mode, ['groups','items'], true)) $mode = 'groups';
  return $mode;
}

function group_title_override(string $groupKey): string {
  $cfg = app_config();
  $map = $cfg['student']['group_titles'] ?? [];
  if (!is_array($map)) return $groupKey;
  $t = $map[$groupKey] ?? null;
  $t = is_string($t) ? trim($t) : '';
  return $t !== '' ? $t : $groupKey;
}

function get_student_and_class(PDO $pdo, int $studentId): array {
  $st = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.class_id,
            c.school_year, c.grade_level, c.label, c.name AS class_name,
            c.template_id AS class_template_id
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

  // exact tokens (intentionally simple and predictable)
  return str_replace(array_keys($rep), array_values($rep), $html);
}

/**
 * IMPORTANT: template is assigned per class (classes.template_id).
 * If no template is assigned -> student cannot proceed (teacher must fix in admin/classes.php).
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

function find_or_create_report_instance(PDO $pdo, int $studentId, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id=?
     ORDER BY id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);

  if ($ri) {
    return [
      'report_instance_id' => (int)$ri['id'],
      'status' => (string)$ri['status'],
    ];
  }

  $pdo->prepare(
    "INSERT INTO report_instances (template_id, student_id, status, created_at, updated_at)
     VALUES (?, ?, 'draft', NOW(), NOW())"
  )->execute([$templateId, $studentId]);
  $rid = (int)$pdo->lastInsertId();

  audit('student_report_instance_create', null, ['student_id'=>$studentId,'report_instance_id'=>$rid,'template_id'=>$templateId]);

  return [
    'report_instance_id' => $rid,
    'status' => 'draft',
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
  // Returns lookup for ALL template fields (child + teacher), keyed by field_name
  // Used for placeholder resolution in labels/help_text (e.g. {{field:Mat-text-1}})
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.help_text, tf.field_type,
            fv.value_text
     FROM template_fields tf
     LEFT JOIN field_values fv
       ON fv.template_field_id=tf.id AND fv.report_instance_id=?
     WHERE tf.template_id=?
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId, $templateId]);
  $out = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($r['field_name'] ?? '');
    if ($name === '') continue;
    $out[$name] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => $name,
      'type' => (string)($r['field_type'] ?? 'text'),
      'label' => (string)($r['label'] ?? $name),
      'help' => (string)($r['help_text'] ?? ''),
      'value' => (string)($r['value_text'] ?? ''),
    ];
  }
  return $out;
}

function load_child_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, help_text, is_multiline, options_json, meta_json, sort_order
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
  if ($studentId <= 0) throw new RuntimeException('Nicht eingeloggt.');

  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $action = (string)($data['action'] ?? '');
  if (!in_array($action, ['bootstrap','save_value','submit'], true)) {
    throw new RuntimeException('Ungültige Aktion.');
  }

  // IMPORTANT: use template assigned to the student's class
  $tpl = template_for_student($pdo, $studentId);
  $templateId = (int)$tpl['id'];

  $ctx = find_or_create_report_instance($pdo, $studentId, $templateId);
  $reportId = (int)$ctx['report_instance_id'];

  $status = get_report_status($pdo, $reportId);
  $childCanEdit = ($status === 'draft');

  if ($action === 'bootstrap') {
    $studentRow = get_student_and_class($pdo, $studentId);

    // Defensive: if class has no template, template_for_student already throws,
    // but keep the studentRow for placeholder rendering.
    $introAbs = child_intro_file_abs();
    $introHtml = '';
    if (is_file($introAbs)) {
      $introHtml = sanitize_intro_html((string)file_get_contents($introAbs));
      $introHtml = render_intro_placeholders($introHtml, $studentRow);
    }

    $fieldsRaw = load_child_fields($pdo, $templateId);
    $values = load_child_values($pdo, $reportId);

    $fieldLookup = load_all_fields_lookup($pdo, $templateId, $reportId);


    // groups: key => ['key'=>..., 'title'=>..., 'fields'=>...]
    $groups = [];
    $iconIds = [];

    foreach ($fieldsRaw as $r) {
      $fid = (int)$r['id'];
      $meta = meta_read($r['meta_json'] ?? null);

      $gKey = group_key_from_meta($meta);
      $gTitle = group_title_override($gKey);

      if (!isset($groups[$gKey])) {
        $groups[$gKey] = ['key' => $gKey, 'title' => $gTitle, 'fields' => []];
      } else {
        $groups[$gKey]['title'] = $gTitle;
      }

      $opts = [];
      if (!empty($r['options_json'])) {
        $oj = json_decode((string)$r['options_json'], true);
        if (is_array($oj) && isset($oj['options']) && is_array($oj['options'])) {
          $opts = $oj['options'];
        }
      }
      foreach ($opts as $o) {
        $iid = (int)($o['icon_id'] ?? 0);
        if ($iid > 0) $iconIds[] = $iid;
      }

      $val = $values[$fid] ?? ['text' => null, 'json' => null];

      $groups[$gKey]['fields'][] = [
        'id' => $fid,
        'name' => (string)$r['field_name'],
        'type' => (string)$r['field_type'],
        'label_raw' => (string)($r['label'] ?? $r['field_name']),
        'help_raw' => (string)($r['help_text'] ?? ''),
        'label' => (string)($r['label'] ?? $r['field_name']),
        'help' => (string)($r['help_text'] ?? ''),
        'required' => true,
        'multiline' => (int)($r['is_multiline'] ?? 0) === 1,
        'group' => $gKey, // internal key (stable)
        'options' => $opts,
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
        'display_mode' => student_wizard_display_mode(),
      ],
    ]);
  }

  if ($action === 'save_value') {
    ensure_editable_or_throw($pdo, $reportId);

    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($fieldId <= 0) throw new RuntimeException('template_field_id fehlt.');

    $st = $pdo->prepare(
      "SELECT id, field_type
       FROM template_fields
       WHERE id=? AND template_id=? AND can_child_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $type = (string)$frow['field_type'];
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    if (in_array($type, ['radio','select','grade'], true)) {
      $valueText = $valueText !== null ? trim($valueText) : '';
      if ($valueText === '') $valueText = null;
    } elseif ($type === 'checkbox') {
      $v = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
      $valueText = $v;
    } else {
      $valueText = $valueText !== null ? trim($valueText) : null;
      if ($valueText === '') $valueText = null;
    }

    $up = $pdo->prepare(
      "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_student_id, updated_at)
       VALUES (?, ?, ?, NULL, 'child', ?, NOW())
       ON DUPLICATE KEY UPDATE
         value_text=VALUES(value_text),
         value_json=NULL,
         source='child',
         updated_by_student_id=VALUES(updated_by_student_id),
         updated_at=NOW()"
    );
    $up->execute([$reportId, $fieldId, $valueText, $studentId]);

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
