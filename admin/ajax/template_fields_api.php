<?php
// admin/ajax/template_fields_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function decode_json(?string $s): array {
  if (!$s) return [];
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}

try {
  $data = read_json_body();
  // CSRF: Header oder JSON-field
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $action = (string)($data['action'] ?? ($_GET['action'] ?? 'list'));
  $templateId = (int)($data['template_id'] ?? ($_GET['template_id'] ?? 0));
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  $allowedTypes = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];

  // --- list
  if ($action === 'list') {
    $tpl = $pdo->prepare("SELECT id, name, template_version FROM templates WHERE id=? LIMIT 1");
    $tpl->execute([$templateId]);
    $template = $tpl->fetch(PDO::FETCH_ASSOC);
    if (!$template) throw new RuntimeException('Template nicht gefunden.');

    $st = $pdo->prepare("
      SELECT id, field_name, field_type, label, label_en, help_text, is_multiline, is_required,
             can_child_edit, can_teacher_edit, options_json, meta_json, sort_order
      FROM template_fields
      WHERE template_id=?
      ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$templateId]);

    $fields = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $fields[] = [
        'id' => (int)$r['id'],
        'name' => (string)$r['field_name'],
        'type' => (string)$r['field_type'],
        'label' => (string)($r['label'] ?? ''),
        'label_en' => (string)($r['label_en'] ?? ''),
        'help_text' => (string)($r['help_text'] ?? ''),
        'multiline' => (int)$r['is_multiline'] === 1 ? 1 : 0,
        'required' => (int)$r['is_required'] === 1 ? 1 : 0,
        'can_child_edit' => (int)$r['can_child_edit'] === 1 ? 1 : 0,
        'can_teacher_edit' => (int)$r['can_teacher_edit'] === 1 ? 1 : 0,
        'sort_order' => (int)$r['sort_order'],
        'options' => decode_json($r['options_json'] ?? null),
        'meta' => decode_json($r['meta_json'] ?? null),
      ];
    }

    echo json_encode(['ok'=>true, 'template'=>$template, 'fields'=>$fields], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- save (full or partial updates)
  if ($action === 'save') {
    $updates = $data['updates'] ?? null;
    if (!is_array($updates)) throw new RuntimeException('updates fehlt/ungültig.');

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
      UPDATE template_fields
      SET field_type=?,
          label=?,
          label_en=?,
          help_text=?,
          is_multiline=?,
          is_required=?,
          can_child_edit=?,
          can_teacher_edit=?,
          options_json=?,
          meta_json=?,
          sort_order=?,
          updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND template_id=?
    ");

    $count = 0;
    foreach ($updates as $u) {
      if (!is_array($u)) continue;
      $id = (int)($u['id'] ?? 0);
      if ($id <= 0) continue;

      $type = (string)($u['type'] ?? 'radio');
      if (!in_array($type, $allowedTypes, true)) throw new RuntimeException('Ungültiger Feldtyp: '.$type);

      $label = trim((string)($u['label'] ?? ''));
      if ($label === '') $label = ' ';

      $labelEn = trim((string)($u['label_en'] ?? ''));
      if ($labelEn === '') $labelEn = null;
      $help = trim((string)($u['help_text'] ?? ''));
      if ($help === '') $help = null;

      $ml = !empty($u['multiline']) ? 1 : 0;
      if ($type === 'multiline') $ml = 1;

      $req = !empty($u['required']) ? 1 : 0;
      $child = !empty($u['can_child_edit']) ? 1 : 0;

      $teacher = isset($u['can_teacher_edit']) ? (!empty($u['can_teacher_edit']) ? 1 : 0) : 1;

      $sort = (int)($u['sort_order'] ?? 0);

      $options = $u['options'] ?? null;
      $optionsJson = null;
      if (is_array($options) && $options) {
        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
      }

      $meta = $u['meta'] ?? null;
      $metaJson = null;
      if (is_array($meta) && $meta) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
      }

      $upd->execute([$type, $label, $labelEn, $help, $ml, $req, $child, $teacher, $optionsJson, $metaJson, $sort, $id, $templateId]);
      $count++;
    }

    $pdo->commit();
    audit('template_fields_save', (int)current_user()['id'], ['template_id'=>$templateId,'count'=>$count]);
    echo json_encode(['ok'=>true,'saved'=>$count], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- bulk_patch: apply patch to many ids
  if ($action === 'bulk_patch') {
    $ids = $data['ids'] ?? null;
    $patch = $data['patch'] ?? null;
    if (!is_array($ids) || !is_array($patch)) throw new RuntimeException('ids/patch fehlt.');

    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("
      SELECT id, field_type, label, help_text, is_multiline, is_required, can_child_edit, can_teacher_edit, options_json, meta_json, sort_order
      FROM template_fields
      WHERE template_id=? AND id IN ($in)
    ");
    $params = array_merge([$templateId], array_map('intval', $ids));
    $q->execute($params);

    $updates = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $u = [
        'id' => (int)$r['id'],
        'type' => (string)$r['field_type'],
        'label' => (string)($r['label'] ?? ''),
        'help_text' => (string)($r['help_text'] ?? ''),
        'multiline' => (int)$r['is_multiline'],
        'required' => (int)$r['is_required'],
        'can_child_edit' => (int)$r['can_child_edit'],
        'can_teacher_edit' => (int)$r['can_teacher_edit'],
        'sort_order' => (int)$r['sort_order'],
        'options' => decode_json($r['options_json'] ?? null),
        'meta' => decode_json($r['meta_json'] ?? null),
      ];

      foreach ($patch as $k => $v) {
        // Protect existing meta/options unless the caller explicitly intends to overwrite.
        // Rationale: bulk patch UI often sends empty/null keys; we must not wipe e.g. meta.group accidentally.
        if ($k === 'meta') {
          if ($v === null || $v === '' || $v === false) continue;
          if (is_array($v)) $u['meta'] = $v;
          continue;
        }
        if ($k === 'options') {
          if ($v === null || $v === '' || $v === false) continue;
          if (is_array($v)) $u['options'] = $v;
          continue;
        }

        if ($k === 'meta_merge' && is_array($v)) {
          $u['meta'] = array_merge($u['meta'] ?? [], $v);
          continue;
        }
        if ($k === 'options_set' && is_array($v)) {
          $u['options'] = $v;
          continue;
        }

        $u[$k] = $v;
      }

      if (!in_array((string)$u['type'], $allowedTypes, true)) $u['type'] = 'radio';

      $updates[] = $u;
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("
      UPDATE template_fields
      SET field_type=?, label=?, label_en=?, help_text=?, is_multiline=?, is_required=?,
          can_child_edit=?, can_teacher_edit=?, options_json=?, meta_json=?, sort_order=?, updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND template_id=?
    ");
    $count = 0;
    foreach ($updates as $u) {
      $type = (string)$u['type'];
      $label = trim((string)$u['label']); if ($label === '') $label = ' ';
      $help = trim((string)($u['help_text'] ?? '')); if ($help === '') $help = null;
      $ml = !empty($u['multiline']) ? 1 : 0; if ($type === 'multiline') $ml = 1;
      $req = !empty($u['required']) ? 1 : 0;
      $child = !empty($u['can_child_edit']) ? 1 : 0;
      $teacher = isset($u['can_teacher_edit']) ? (!empty($u['can_teacher_edit']) ? 1 : 0) : 1;
      $sort = (int)($u['sort_order'] ?? 0);

      $optionsJson = (is_array($u['options'] ?? null) && ($u['options'] ?? null))
        ? json_encode($u['options'], JSON_UNESCAPED_UNICODE) : null;

      $metaJson = (is_array($u['meta'] ?? null) && ($u['meta'] ?? null))
        ? json_encode($u['meta'], JSON_UNESCAPED_UNICODE) : null;

      $upd->execute([$type, $label, $help, $ml, $req, $child, $teacher, $optionsJson, $metaJson, $sort, (int)$u['id'], $templateId]);
      $count++;
    }
    $pdo->commit();

    audit('template_fields_bulk_patch', (int)current_user()['id'], ['template_id'=>$templateId,'count'=>$count]);
    echo json_encode(['ok'=>true,'patched'=>$count], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
