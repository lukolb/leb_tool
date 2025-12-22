<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
  $templateId = (int)($_GET['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT field_name, field_type, label, is_multiline, can_child_edit, can_teacher_edit
    FROM template_fields
    WHERE template_id=?
  ");
  $stmt->execute([$templateId]);

  $map = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $map[$r['field_name']] = [
      'field_type' => $r['field_type'],
      'label' => $r['label'],
      'is_multiline' => (int)$r['is_multiline'],
      'can_child_edit' => (int)$r['can_child_edit'],
      'can_teacher_edit' => (int)$r['can_teacher_edit'],
    ];
  }

  echo json_encode(['ok'=>true,'map'=>$map], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

