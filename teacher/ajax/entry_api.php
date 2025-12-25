<?php
// teacher/ajax/entry_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

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

function group_title_override(string $groupKey): string {
  $cfg = app_config();
  $map = $cfg['student']['group_titles'] ?? [];
  if (!is_array($map)) return $groupKey;
  $t = $map[$groupKey] ?? null;
  $t = is_string($t) ? trim($t) : '';
  return $t !== '' ? $t : $groupKey;
}

function base_field_key(string $fieldName): string {
  $s = strtolower(trim($fieldName));
  // ignore suffix like "-T" etc.
  $s = explode('-', $s, 2)[0];
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return trim($s);
}

function is_system_bound(array $meta): bool {
  // Admin mapping (admin/template_mappings.php) stores bindings here.
  $tpl = $meta['system_binding_tpl'] ?? null;
  if (is_string($tpl) && trim($tpl) !== '') return true;
  $one = $meta['system_binding'] ?? null;
  if (is_string($one) && trim($one) !== '') return true;
  return false;
}

/**
 * options_json can be:
 *  - {"options":[{value,label,...}, ...]}   (project format)
 *  - [{value,label,...}, ...]              (fallback)
 */
function decode_options(?string $json): array {
  if (!$json) return [];
  $j = json_decode($json, true);
  if (!is_array($j)) return [];
  if (isset($j['options']) && is_array($j['options'])) return $j['options'];
  if (array_is_list($j)) return $j;
  return [];
}

/**
 * IMPORTANT: Templates are assigned per class by admin (classes.template_id).
 * If no template is assigned, teacher must see an error message.
 */
function template_for_class(PDO $pdo, int $classId): array {
  $st = $pdo->prepare(
    "SELECT t.id, t.name, t.template_version
     FROM classes c
     LEFT JOIN templates t ON t.id=c.template_id
     WHERE c.id=?
     LIMIT 1"
  );
  $st->execute([$classId]);
  $tpl = $st->fetch(PDO::FETCH_ASSOC);

  if (!$tpl || (int)($tpl['id'] ?? 0) <= 0) {
    throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
  }

  // Ensure assigned template is active
  $st2 = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
  $st2->execute([(int)$tpl['id']]);
  if ((int)$st2->fetchColumn() !== 1) {
    throw new RuntimeException('Die zugeordnete Vorlage ist inaktiv.');
  }

  return $tpl;
}

function find_or_create_report_instance_for_student(PDO $pdo, int $templateId, int $studentId): array {
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
    return ['id' => (int)$ri['id'], 'status' => (string)$ri['status']];
  }

  $pdo->prepare(
    "INSERT INTO report_instances (template_id, student_id, status, created_by_user_id, created_at, updated_at)
     VALUES (?, ?, 'draft', NULL, NOW(), NOW())"
  )->execute([$templateId, $studentId]);
  $rid = (int)$pdo->lastInsertId();
  return ['id' => $rid, 'status' => 'draft'];
}

function load_teacher_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, help_text, is_multiline, options_json, meta_json, sort_order
     FROM template_fields
     WHERE template_id=? AND can_teacher_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_child_fields_for_pairing(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, options_json
     FROM template_fields
     WHERE template_id=? AND can_child_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_values(PDO $pdo, array $reportIds, array $fieldIds, string $source): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds, [$source]);

  $st = $pdo->prepare(
    "SELECT report_instance_id, template_field_id, value_text
     FROM field_values
     WHERE report_instance_id IN ($inR)
       AND template_field_id IN ($inF)
       AND source=?"
  );
  $st->execute($params);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (string)(int)$r['template_field_id'];
    if (!isset($out[$rid])) $out[$rid] = [];
    $out[$rid][$fid] = ($r['value_text'] !== null) ? (string)$r['value_text'] : '';
  }
  return $out;
}

try {
  $data = read_json_body();

  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);

  $action = (string)($data['action'] ?? '');
  if ($action === '') throw new RuntimeException('action fehlt.');

  if ($action === 'load') {
    $classId = (int)($data['class_id'] ?? 0);
    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    // IMPORTANT: Use template assigned to this class (classes.template_id)
    $tpl = template_for_class($pdo, $classId);
    $templateId = (int)$tpl['id'];

    // students in class
    $st = $pdo->prepare(
      "SELECT id, first_name, last_name
       FROM students
       WHERE class_id=? AND is_active=1
       ORDER BY last_name ASC, first_name ASC, id ASC"
    );
    $st->execute([$classId]);
    $studentsRaw = $st->fetchAll(PDO::FETCH_ASSOC);

    $students = [];
    $reportIds = [];
    foreach ($studentsRaw as $s) {
      $sid = (int)$s['id'];
      $name = trim((string)$s['last_name'] . ', ' . (string)$s['first_name']);
      $ri = find_or_create_report_instance_for_student($pdo, $templateId, $sid);
      $students[] = [
        'id' => $sid,
        'name' => $name,
        'report_instance_id' => (int)$ri['id'],
        'status' => (string)$ri['status'],
      ];
      $reportIds[] = (int)$ri['id'];
    }

    // child fields -> map by base key
    $childFields = load_child_fields_for_pairing($pdo, $templateId);
    $childByBase = []; // base => ['id'=>int,'field_type'=>string,'options'=>array]
    foreach ($childFields as $cf) {
      $base = base_field_key((string)$cf['field_name']);
      if ($base === '') continue;
      if (isset($childByBase[$base])) continue; // first wins
      $childByBase[$base] = [
        'id' => (int)$cf['id'],
        'field_type' => (string)($cf['field_type'] ?? ''),
        'options' => decode_options($cf['options_json'] ?? null),
      ];
    }

    // teacher fields -> groups with paired child
    $teacherFields = load_teacher_fields($pdo, $templateId);
    $groups = [];

    foreach ($teacherFields as $f) {
      $meta = meta_read($f['meta_json'] ?? null);
      // Fields that are pre-filled by admin/system bindings should not be editable here.
      if (is_system_bound($meta)) {
        continue;
      }
      $gKey = group_key_from_meta($meta);

      if (!isset($groups[$gKey])) {
        $groups[$gKey] = [
          'key' => $gKey,
          'title' => group_title_override($gKey),
          'fields' => [],
        ];
      }

      $optsTeacher = decode_options($f['options_json'] ?? null);
      if (!$optsTeacher && (string)$f['field_type'] === 'grade') {
        // Fallback: common German grades 1..6 if no scale configured.
        $optsTeacher = [
          ['value'=>'1','label'=>'1'],
          ['value'=>'2','label'=>'2'],
          ['value'=>'3','label'=>'3'],
          ['value'=>'4','label'=>'4'],
          ['value'=>'5','label'=>'5'],
          ['value'=>'6','label'=>'6'],
        ];
      }

      $base = base_field_key((string)$f['field_name']);
      $child = ($base !== '' && isset($childByBase[$base])) ? $childByBase[$base] : null;

      $groups[$gKey]['fields'][] = [
        'id' => (int)$f['id'],
        'field_name' => (string)$f['field_name'],
        'field_type' => (string)$f['field_type'],
        'label' => (string)($f['label'] ?? ''),
        'help_text' => (string)($f['help_text'] ?? ''),
        'is_multiline' => (int)($f['is_multiline'] ?? 0),
        'options' => $optsTeacher,
        'child' => $child ? [
          'id' => (int)$child['id'],
          'field_type' => (string)$child['field_type'],
          'options' => $child['options'],
        ] : null,
      ];
    }

    $groupsList = array_values($groups);

    // values
    // NOTE: load_values() uses field ids, so we must pass ids of all teacher fields,
    // even if some are filtered out for display; harmless to include all.
    $teacherFieldIds = array_map(fn($x)=>(int)$x['id'], $teacherFields);
    $childFieldIds = array_values(array_unique(array_filter(array_map(
      fn($x)=> (int)($x['id'] ?? 0),
      array_values($childByBase)
    ), fn($x)=>$x>0)));

    $valuesTeacher = load_values($pdo, $reportIds, $teacherFieldIds, 'teacher');
    $valuesChild = load_values($pdo, $reportIds, $childFieldIds, 'child');

    json_out([
      'ok' => true,
      'template' => [
        'id' => $templateId,
        'name' => (string)$tpl['name'],
        'version' => (int)$tpl['template_version'],
      ],
      'students' => $students,
      'groups' => $groupsList,
      'values_teacher' => $valuesTeacher,
      'values_child' => $valuesChild,
    ]);
  }

  if ($action === 'save') {
    $reportId = (int)($data['report_instance_id'] ?? 0);
    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($reportId <= 0 || $fieldId <= 0) throw new RuntimeException('report_instance_id/template_field_id fehlt.');

    // access check via report->student->class
    $st = $pdo->prepare(
      "SELECT ri.id, ri.status, ri.template_id, s.class_id, c.template_id AS class_template_id
       FROM report_instances ri
       INNER JOIN students s ON s.id=ri.student_id
       INNER JOIN classes c ON c.id=s.class_id
       WHERE ri.id=? LIMIT 1"
    );
    $st->execute([$reportId]);
    $ri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ri) throw new RuntimeException('Report nicht gefunden.');

    $classId = (int)($ri['class_id'] ?? 0);
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $status = (string)($ri['status'] ?? 'draft');
    if ($status === 'locked') throw new RuntimeException('Report ist gesperrt.');

    // IMPORTANT: Prevent saving into a report instance with a template that is not the one assigned to the class.
    $riTemplateId = (int)($ri['template_id'] ?? 0);
    $classTemplateId = (int)($ri['class_template_id'] ?? 0);
    if ($classTemplateId <= 0) {
      throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
    }
    if ($riTemplateId !== $classTemplateId) {
      throw new RuntimeException('Vorlagenkonflikt: Der Bericht gehört zu einer anderen Vorlage als der Klasse zugeordnet ist.');
    }

    $templateId = $riTemplateId;

    $st = $pdo->prepare(
      "SELECT id, field_type, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_teacher_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $meta = meta_read($frow['meta_json'] ?? null);
    if (is_system_bound($meta)) {
      throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');
    }

    $type = (string)$frow['field_type'];
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    if (in_array($type, ['radio','select','grade'], true)) {
      $valueText = $valueText !== null ? trim($valueText) : '';
      if ($valueText === '') $valueText = null;
    } elseif ($type === 'checkbox') {
      $valueText = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
    } else {
      $valueText = $valueText !== null ? trim($valueText) : null;
      if ($valueText === '') $valueText = null;
    }

    $up = $pdo->prepare(
      "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
       VALUES (?, ?, ?, NULL, 'teacher', ?, NOW())
       ON DUPLICATE KEY UPDATE
         value_text=VALUES(value_text),
         value_json=NULL,
         source='teacher',
         updated_by_user_id=VALUES(updated_by_user_id),
         updated_at=NOW()"
    );
    $up->execute([$reportId, $fieldId, $valueText, $userId]);

    audit('teacher_value_save', $userId, ['report_instance_id'=>$reportId,'template_field_id'=>$fieldId]);
    json_out(['ok' => true]);
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
