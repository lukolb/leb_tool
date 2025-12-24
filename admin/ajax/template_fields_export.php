<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
  $templateId = (int)($_GET['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  $pdo = db();

  $stmt = $pdo->prepare("SELECT id FROM templates WHERE id=? LIMIT 1");
  $stmt->execute([$templateId]);
  if (!$stmt->fetch()) throw new RuntimeException('Template nicht gefunden.');

  $rows = $pdo->prepare("
    SELECT
      field_name,
      field_type,
      label,
      help_text,
      is_multiline,
      can_child_edit,
      can_teacher_edit,
      meta_json
    FROM template_fields
    WHERE template_id=?
    ORDER BY sort_order ASC, id ASC
  ");
  $rows->execute([$templateId]);

  $out = [];
  foreach ($rows->fetchAll() as $r) {
    $meta = [];
    if (!empty($r['meta_json'])) {
      $decoded = json_decode((string)$r['meta_json'], true);
      if (is_array($decoded)) $meta = $decoded;
    }

    $out[] = [
      'name' => (string)$r['field_name'],
      'type' => (string)$r['field_type'],
      'label' => (string)($r['label'] ?? ''),
      'help_text' => (string)($r['help_text'] ?? ''),
      'multiline' => (int)$r['is_multiline'] === 1,
      'can_child_edit' => (int)$r['can_child_edit'] === 1 ? 1 : 0,
      'can_teacher_edit' => (int)$r['can_teacher_edit'] === 1 ? 1 : 0,
      'meta' => $meta,
    ];
  }

  echo json_encode(['ok' => true, 'fields' => $out], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
