<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
  // ---- CSRF: akzeptiere Header oder JSON Body oder Form ----
  $csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  $raw = file_get_contents('php://input') ?: '';

  $data = null;

  // 1) JSON Body versuchen
  if ($raw !== '') {
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false || $raw[0] === '{' || $raw[0] === '[') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $data = $decoded;
    }
  }

  // 2) Fallback auf normale Form-POSTs
  if ($data === null) {
    $data = $_POST;
    if (isset($data['fields']) && is_string($data['fields'])) {
      $decoded = json_decode($data['fields'], true);
      if (is_array($decoded)) $data['fields'] = $decoded;
    }
  }

  // CSRF Token aus Body oder POST oder Header
  $csrf = (string)($data['csrf_token'] ?? ($_POST['csrf_token'] ?? $csrfHeader));

  // csrf_verify() erwartet i.d.R. $_POST['csrf_token'] – wir setzen ihn für den Call
  if (!isset($_POST['csrf_token'])) {
    $_POST['csrf_token'] = $csrf;
  }
  if (function_exists('csrf_verify')) {
    csrf_verify();
  }

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
      (template_id, field_name, field_type, label, is_multiline, meta_json, sort_order, can_child_edit, can_teacher_edit)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      field_type = VALUES(field_type),
      label = VALUES(label),
      is_multiline = VALUES(is_multiline),
      meta_json = VALUES(meta_json),
      sort_order = VALUES(sort_order),
      can_child_edit = VALUES(can_child_edit),
      can_teacher_edit = VALUES(can_teacher_edit),
      updated_at = CURRENT_TIMESTAMP
  ");

  $i = 0;
  foreach ($fields as $idx => $f) {
    if (!is_array($f)) continue;

    $name = trim((string)($f['name'] ?? ''));
    if ($name === '') continue;

    $type = (string)($f['type'] ?? 'other');
    $label = trim((string)($f['label'] ?? ''));
    $isMultiline = !empty($f['multiline']) ? 1 : 0;

    // Typ normalisieren
    $mappedType = map_field_type($type, $f);

    $meta = $f['meta'] ?? [];
    if (!is_array($meta)) $meta = [];

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if ($metaJson === false) $metaJson = null;

    $sort = isset($f['sort']) ? (int)$f['sort'] : (int)$idx;

    // Flags aus dem Import übernehmen (Defaults: child=0, teacher=1)
    $childEdit = !empty($f['can_child_edit']) ? 1 : 0;
    $teacherEdit = array_key_exists('can_teacher_edit', $f) ? (!empty($f['can_teacher_edit']) ? 1 : 0) : 1;

    $ins->execute([
      $templateId,
      $name,
      $mappedType,
      $label !== '' ? $label : $name,
      $isMultiline,
      $metaJson,
      $sort,
      $childEdit,
      $teacherEdit
    ]);

    $i++;
  }

  $pdo->commit();

  audit('template_fields_import', (int)current_user()['id'], [
    'template_id' => $templateId,
    'count' => $i
  ]);

  echo json_encode(['ok' => true, 'imported' => $i], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

function map_field_type(string $type, array $f): string {
  // PDF.js liefert oft: Tx, Btn, Ch, Sig (oder wir bekommen schon "text" aus unserem Mapping)
  $t = strtoupper(trim($type));

  // Wenn unser templates.php bereits "text" liefert:
  if ($t === 'TEXT') return 'text';
  if ($t === 'CHECKBOX') return 'checkbox';
  if ($t === 'RADIO') return 'radio';
  if ($t === 'SELECT') return 'select';
  if ($t === 'SIGNATURE') return 'signature';

  if ($t === 'TX') return 'text';
  if ($t === 'SIG') return 'signature';
  if ($t === 'CH') return 'select';

  if ($t === 'BTN') {
    $meta = $f['meta'] ?? [];
    if (!is_array($meta)) $meta = [];

    $ff = (int)($meta['fieldFlags'] ?? 0);

    if (!empty($meta['isRadio']) || (!empty($meta['radio']) && $meta['radio'] === true)) return 'radio';
    if (!empty($meta['isCheckbox']) || (!empty($meta['checkbox']) && $meta['checkbox'] === true)) return 'checkbox';

    // PDF Spec heuristic
    if (($ff & 32768) === 32768) return 'radio';

    return 'checkbox';
  }

  return 'other';
}
