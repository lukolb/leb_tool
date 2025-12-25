<?php
// admin/ajax/option_lists_api.php
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

try {
  // CSRF: Header oder JSON-field
  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $action = (string)($data['action'] ?? '');

  if ($action === 'list_templates') {
    $rows = $pdo->query("
      SELECT id, name, description, is_active, created_at, updated_at
      FROM option_list_templates
      WHERE is_active=1
      ORDER BY created_at DESC, id DESC
    ")->fetchAll();

    echo json_encode(['ok'=>true, 'templates'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'create_template') {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Name fehlt.');

    $stmt = $pdo->prepare("
      INSERT INTO option_list_templates (name, description, is_active, created_by_user_id)
      VALUES (?, '', 1, ?)
    ");
    $stmt->execute([$name, (int)current_user()['id']]);
    $id = (int)$pdo->lastInsertId();

    audit('option_list_create', (int)current_user()['id'], ['list_id'=>$id, 'name'=>$name]);

    echo json_encode(['ok'=>true, 'list_id'=>$id], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'get_template') {
    $id = (int)($data['list_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('list_id fehlt.');

    $st = $pdo->prepare("SELECT id, name, description, is_active FROM option_list_templates WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $tpl = $st->fetch();
    if (!$tpl) throw new RuntimeException('Vorlage nicht gefunden.');

    $it = $pdo->prepare("
      SELECT id, list_id, value, label, icon_id, sort_order
      FROM option_list_items
      WHERE list_id=?
      ORDER BY sort_order ASC, id ASC
    ");
    $it->execute([$id]);
    $items = $it->fetchAll();

    echo json_encode(['ok'=>true, 'template'=>$tpl, 'items'=>$items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_template') {
    $id = (int)($data['list_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('list_id fehlt.');

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Name fehlt.');
    $desc = trim((string)($data['description'] ?? ''));

    $items = $data['items'] ?? [];
    if (!is_array($items)) throw new RuntimeException('items ungültig.');

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE option_list_templates SET name=?, description=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$name, $desc, $id]);

    // existing items
    $existing = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=?");
    $existing->execute([$id]);
    $existingIds = array_map('intval', array_column($existing->fetchAll(), 'id'));
    $keepIds = [];

    $ins = $pdo->prepare("
      INSERT INTO option_list_items (list_id, value, label, icon_id, sort_order, meta_json)
      VALUES (?, ?, ?, ?, ?, NULL)
    ");
    $upd = $pdo->prepare("
      UPDATE option_list_items
      SET value=?, label=?, icon_id=?, sort_order=?, updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND list_id=?
    ");

    $i = 0;
    foreach ($items as $row) {
      if (!is_array($row)) { $i++; continue; }

      $itemId = isset($row['id']) ? (int)$row['id'] : 0;
      $value = trim((string)($row['value'] ?? ''));
      $label = trim((string)($row['label'] ?? ''));
      $iconId = isset($row['icon_id']) && $row['icon_id'] !== null && $row['icon_id'] !== '' ? (int)$row['icon_id'] : null;
      $sort = (int)($row['sort_order'] ?? $i);

      if ($value === '' || $label === '') { $i++; continue; }

      if ($itemId > 0 && in_array($itemId, $existingIds, true)) {
        $upd->execute([$value, $label, $iconId, $sort, $itemId, $id]);
        $keepIds[] = $itemId;
      } else {
        $ins->execute([$id, $value, $label, $iconId, $sort]);
        $keepIds[] = (int)$pdo->lastInsertId();
      }

      $i++;
    }

    // delete removed items
    $toDelete = array_diff($existingIds, $keepIds);
    if ($toDelete) {
      $in = implode(',', array_fill(0, count($toDelete), '?'));
      $del = $pdo->prepare("DELETE FROM option_list_items WHERE list_id=? AND id IN ($in)");
      $del->execute(array_merge([$id], array_values($toDelete)));
    }

    $pdo->commit();

    // --- propagate updated options to linked template_fields (meta.option_list_template_id == $id)
    $optionsObj = ['options' => []];
    $stItems = $pdo->prepare("SELECT value, label, icon_id, sort_order FROM option_list_items WHERE list_id=? ORDER BY sort_order ASC, id ASC");
    $stItems->execute([$id]);
    $dbItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dbItems as $it) {
      $val = trim((string)($it['value'] ?? ''));
      $lab = trim((string)($it['label'] ?? ''));
      if ($val === '' || $lab === '') continue;
      $optionsObj['options'][] = [
        'value' => $val,
        'label' => $lab,
        'icon_id' => ($it['icon_id'] !== null && $it['icon_id'] !== '') ? (int)$it['icon_id'] : null,
      ];
    }
    $optionsJson = json_encode($optionsObj, JSON_UNESCAPED_UNICODE);

    $cand = $pdo->query("SELECT id, meta_json FROM template_fields WHERE meta_json IS NOT NULL AND meta_json LIKE '%option_list_template_id%'");
    $updFieldOpt = $pdo->prepare("UPDATE template_fields SET options_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $updFieldMeta = $pdo->prepare("UPDATE template_fields SET meta_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $updatedCount = 0;

    foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $meta = json_decode((string)$r['meta_json'], true);
      if (!is_array($meta)) continue;
      if (!array_key_exists('option_list_template_id', $meta)) continue;
      if ((string)$meta['option_list_template_id'] !== (string)$id) continue;

      // Update options_json (the canonical place for choices)
      $updFieldOpt->execute([$optionsJson, (int)$r['id']]);
      $updatedCount++;

      // If the project previously cached options inside meta, keep it in sync too (backwards compatibility).
      $changedMeta = false;
      if (array_key_exists('options', $meta) && is_array($meta['options'])) {
        $meta['options'] = $optionsObj['options'];
        $changedMeta = true;
      }
      if (array_key_exists('options_cache', $meta) && is_array($meta['options_cache'])) {
        $meta['options_cache'] = $optionsObj['options'];
        $changedMeta = true;
      }
      if ($changedMeta) {
        $updFieldMeta->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
      }
    }

    audit('option_list_save', (int)current_user()['id'], ['list_id'=>$id, 'items_saved'=>count($keepIds), 'template_fields_updated'=>$updatedCount]);
    echo json_encode(['ok'=>true, 'template_fields_updated'=>$updatedCount], JSON_UNESCAPED_UNICODE);
    exit;
  }
if ($action === 'duplicate_template') {
    $id = (int)($data['list_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('list_id fehlt.');

    $st = $pdo->prepare("SELECT name, description FROM option_list_templates WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $src = $st->fetch();
    if (!$src) throw new RuntimeException('Vorlage nicht gefunden.');

    $pdo->beginTransaction();

    $newName = (string)$src['name'] . ' (Copy)';
    $pdo->prepare("INSERT INTO option_list_templates (name, description, is_active, created_by_user_id) VALUES (?, ?, 1, ?)")
        ->execute([$newName, (string)($src['description'] ?? ''), (int)current_user()['id']]);
    $newId = (int)$pdo->lastInsertId();

    $items = $pdo->prepare("SELECT value, label, icon_id, sort_order, meta_json FROM option_list_items WHERE list_id=? ORDER BY sort_order ASC, id ASC");
    $items->execute([$id]);
    $rows = $items->fetchAll();

    $ins = $pdo->prepare("INSERT INTO option_list_items (list_id, value, label, icon_id, sort_order, meta_json) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
      $ins->execute([
        $newId,
        (string)$r['value'],
        (string)$r['label'],
        $r['icon_id'] !== null ? (int)$r['icon_id'] : null,
        (int)$r['sort_order'],
        $r['meta_json']
      ]);
    }

    $pdo->commit();

    audit('option_list_duplicate', (int)current_user()['id'], ['from'=>$id, 'to'=>$newId]);

    echo json_encode(['ok'=>true, 'new_list_id'=>$newId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'delete_template') {
    $id = (int)($data['list_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('list_id fehlt.');

    // hard delete (FK cascade removes items)
    $pdo->prepare("DELETE FROM option_list_templates WHERE id=?")->execute([$id]);
    audit('option_list_delete', (int)current_user()['id'], ['list_id'=>$id]);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new RuntimeException('Unbekannte Aktion.');

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
