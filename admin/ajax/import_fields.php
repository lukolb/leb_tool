<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function clamp01(int $v): int {
  return $v === 1 ? 1 : 0;
}

function read_input_data(): array {
  // 1) JSON body
  $raw = file_get_contents('php://input');
  if (is_string($raw) && trim($raw) !== '') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
      $d = json_decode($raw, true);
      if (is_array($d)) return $d;
    }
  }

  // 2) form-data / x-www-form-urlencoded
  $data = $_POST;

  // fields kann als JSON-String kommen
  if (isset($data['fields']) && is_string($data['fields'])) {
    $decoded = json_decode($data['fields'], true);
    if (is_array($decoded)) $data['fields'] = $decoded;
  }

  return is_array($data) ? $data : [];
}

function map_field_type(string $type, array $f): string {
  // erlaubte Zieltypen (kein "other")
  $allowed = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];

  $t = strtolower(trim($type));
  if ($t === '') $t = 'radio';

  // pdf.js typische Typen
  $u = strtoupper($t);
  if ($u === 'TX') $t = (!empty($f['multiline']) ? 'multiline' : 'text');
  if ($u === 'CH') $t = 'select';
  if ($u === 'SIG') $t = 'signature';
  if ($u === 'BTN') $t = 'checkbox';

  // multiline → in field_type abbilden
  if (!empty($f['multiline']) && $t === 'text') $t = 'multiline';

  if (!in_array($t, $allowed, true)) {
    // default wie gewünscht: radio
    $t = 'radio';
  }
  return $t;
}

try {
  // CSRF
  $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!isset($_POST['csrf_token'])) $_POST['csrf_token'] = (string)$csrf;
  if (function_exists('csrf_verify')) csrf_verify();

  $data = read_input_data();

  $templateId = (int)($data['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  $fields = $data['fields'] ?? null;
  if (!is_array($fields)) throw new RuntimeException('fields fehlt/ungültig.');

  $pdo = db();

  // Template existiert?
  $stmt = $pdo->prepare("SELECT id FROM templates WHERE id=? LIMIT 1");
  $stmt->execute([$templateId]);
  if (!$stmt->fetch()) throw new RuntimeException('Template nicht gefunden.');

  $pdo->beginTransaction();

  $ins = $pdo->prepare("
    INSERT INTO template_fields
      (template_id, field_name, field_type, label, help_text, is_multiline, meta_json, sort_order, can_child_edit, can_teacher_edit)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      field_type = VALUES(field_type),
      label = VALUES(label),
      help_text = VALUES(help_text),
      is_multiline = VALUES(is_multiline),
      meta_json = VALUES(meta_json),
      sort_order = VALUES(sort_order),
      can_child_edit = VALUES(can_child_edit),
      can_teacher_edit = VALUES(can_teacher_edit),
      updated_at = CURRENT_TIMESTAMP
  ");

  $count = 0;

  foreach ($fields as $i => $f) {
    if (!is_array($f)) continue;

    $name = trim((string)($f['name'] ?? ''));
    if ($name === '') continue;

    // Typ
    $typeRaw = (string)($f['type'] ?? 'radio');
    $mappedType = map_field_type($typeRaw, $f);

    // label/help
    $label = trim((string)($f['label'] ?? ''));
    if ($label === '') $label = $name;

    $help = trim((string)($f['help_text'] ?? ''));

    // multiline Flag (zusätzlich zu field_type)
    $isMultiline = ($mappedType === 'multiline') ? 1 : (!empty($f['multiline']) ? 1 : 0);

    // Rechte: NICHT mit empty()/truthy arbeiten!
    // Standard: Kind 0, Lehrer 1
    $canChild = 0;
    if (array_key_exists('can_child_edit', $f)) $canChild = clamp01((int)$f['can_child_edit']);

    $canTeacher = 1;
    if (array_key_exists('can_teacher_edit', $f)) $canTeacher = clamp01((int)$f['can_teacher_edit']);

    // Meta
    $meta = $f['meta'] ?? [];
    if (!is_array($meta)) $meta = [];
    // Optional: "type" / "multiline" reinspiegeln
    $meta['detectedType'] = $meta['detectedType'] ?? ($f['meta']['type'] ?? null);
    $meta['multiline'] = $meta['multiline'] ?? (bool)$isMultiline;

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if ($metaJson === false) $metaJson = null;

    $sort = isset($f['sort']) ? (int)$f['sort'] : (int)$i;

    $ins->execute([
      $templateId,
      $name,
      $mappedType,
      $label,
      $help,
      $isMultiline,
      $metaJson,
      $sort,
      $canChild,
      $canTeacher,
    ]);

    $count++;
  }

  $pdo->commit();

  audit('template_fields_import', (int)current_user()['id'], [
    'template_id' => $templateId,
    'count' => $count
  ]);

  json_out(['ok' => true, 'imported' => $count]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
