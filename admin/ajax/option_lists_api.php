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

function json_decode_assoc(?string $s): array {
  if (!$s) return [];
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}

/**
 * Find template_field IDs that reference an option_list_template via meta.option_list_template_id.
 * Uses a broad LIKE filter and validates by decoding meta_json.
 */
function template_field_ids_for_option_list(PDO $pdo, int $listId): array {
  $cand = $pdo->query("SELECT id, meta_json FROM template_fields WHERE meta_json IS NOT NULL AND meta_json LIKE '%option_list_template_id%'");
  $ids = [];
  foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $meta = json_decode_assoc($r['meta_json'] ?? null);
    if (!$meta) continue;
    if (!array_key_exists('option_list_template_id', $meta)) continue;
    if ((string)$meta['option_list_template_id'] !== (string)$listId) continue;
    $ids[] = (int)$r['id'];
  }
  return $ids;
}

function options_object_from_db(PDO $pdo, int $listId): array {
  $optionsObj = ['options' => []];
  // NEW: label_en
  $stItems = $pdo->prepare("SELECT id, value, label, label_en, icon_id, sort_order FROM option_list_items WHERE list_id=? ORDER BY sort_order ASC, id ASC");
  $stItems->execute([$listId]);
  foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $val = trim((string)($it['value'] ?? ''));
    $lab = trim((string)($it['label'] ?? ''));
    $labEn = trim((string)($it['label_en'] ?? ''));
    if ($val === '' || $lab === '') continue;

    $optionsObj['options'][] = [
      'id' => (int)$it['id'],
      'value' => $val,
      'label' => $lab,
      'label_en' => $labEn, // NEW (may be empty)
      'icon_id' => ($it['icon_id'] !== null && $it['icon_id'] !== '') ? (int)$it['icon_id'] : null,
    ];
  }
  return $optionsObj;
}

/**
 * When option list item values change, update existing saved values in field_values so exports/UI remain consistent.
 *
 * Strategy:
 *  - If fv.value_json contains {option_item_id}, we can always update fv.value_text to the new current item value.
 *  - If no option_item_id is stored (legacy data), we try a safe fallback by matching fv.value_text to the *old*
 *    item value, but only when that old value uniquely maps to a single item id.
 */
function update_field_values_for_option_list_changes(PDO $pdo, array $templateFieldIds, int $listId, array $oldIdToValue, array $newIdToValue): array {
  $out = ['updated_value_text' => 0, 'updated_value_json' => 0];
  if (!$templateFieldIds) return $out;

  // Build helper maps for legacy fallback
  $oldValueToId = [];
  $oldValueDup = [];
  foreach ($oldIdToValue as $oid => $oval) {
    $k = (string)$oval;
    if ($k === '') continue;
    if (isset($oldValueToId[$k])) { $oldValueDup[$k] = true; continue; }
    $oldValueToId[$k] = (int)$oid;
  }

  $in = implode(',', array_fill(0, count($templateFieldIds), '?'));

  // 1) Update rows with option_item_id stored in value_json
  $sel1 = $pdo->prepare("SELECT id, value_text, value_json FROM field_values WHERE template_field_id IN ($in) AND value_json IS NOT NULL AND value_json <> '' AND value_json LIKE '%option_item_id%'");
  $sel1->execute($templateFieldIds);
  $upd1 = $pdo->prepare("UPDATE field_values SET value_text=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");

  foreach ($sel1->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $vj = json_decode_assoc($r['value_json'] ?? null);
    $optId = isset($vj['option_item_id']) ? (int)$vj['option_item_id'] : 0;
    if ($optId <= 0) continue;
    if (!isset($newIdToValue[$optId])) continue;
    $newVal = (string)$newIdToValue[$optId];
    $curVal = $r['value_text'] !== null ? (string)$r['value_text'] : '';
    if ($curVal === $newVal) continue;
    $upd1->execute([$newVal, (int)$r['id']]);
    $out['updated_value_text']++;
  }

  // 2) Legacy: no option_item_id in value_json (or value_json empty)
  $sel2 = $pdo->prepare("SELECT id, value_text, value_json FROM field_values WHERE template_field_id IN ($in) AND (value_json IS NULL OR value_json='') AND value_text IS NOT NULL AND value_text <> ''");
  $sel2->execute($templateFieldIds);
  $upd2 = $pdo->prepare("UPDATE field_values SET value_text=?, value_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");

  foreach ($sel2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oldVal = trim((string)($r['value_text'] ?? ''));
    if ($oldVal === '') continue;
    if (isset($oldValueDup[$oldVal])) continue; // ambiguous mapping; do nothing
    if (!isset($oldValueToId[$oldVal])) continue;
    $optId = (int)$oldValueToId[$oldVal];
    if (!isset($newIdToValue[$optId])) continue;
    $newVal = (string)$newIdToValue[$optId];
    if ($newVal === $oldVal) continue;
    $newJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
    $upd2->execute([$newVal, $newJson, (int)$r['id']]);
    $out['updated_value_text']++;
    $out['updated_value_json']++;
  }

  return $out;
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

    // NEW: label_en
    $it = $pdo->prepare("
      SELECT id, list_id, value, label, label_en, icon_id, sort_order
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

    // Snapshot old mapping (id -> value) BEFORE changes so we can migrate saved field_values later.
    $stOld = $pdo->prepare("SELECT id, value FROM option_list_items WHERE list_id=? ORDER BY id ASC");
    $stOld->execute([$id]);
    $oldIdToValue = [];
    foreach ($stOld->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $oldIdToValue[(int)$r['id']] = (string)($r['value'] ?? '');
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE option_list_templates SET name=?, description=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$name, $desc, $id]);

    // existing items
    $existing = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=?");
    $existing->execute([$id]);
    $existingIds = array_map('intval', array_column($existing->fetchAll(), 'id'));
    $keepIds = [];

    // NEW: label_en
    $ins = $pdo->prepare("
      INSERT INTO option_list_items (list_id, value, label, label_en, icon_id, sort_order, meta_json)
      VALUES (?, ?, ?, ?, ?, ?, NULL)
    ");
    $upd = $pdo->prepare("
      UPDATE option_list_items
      SET value=?, label=?, label_en=?, icon_id=?, sort_order=?, updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND list_id=?
    ");

    $i = 0;
    foreach ($items as $row) {
      if (!is_array($row)) { $i++; continue; }

      $itemId = isset($row['id']) ? (int)$row['id'] : 0;
      $value = trim((string)($row['value'] ?? ''));
      $label = trim((string)($row['label'] ?? ''));
      $labelEn = trim((string)($row['label_en'] ?? '')); // NEW (optional)
      $iconId = isset($row['icon_id']) && $row['icon_id'] !== null && $row['icon_id'] !== '' ? (int)$row['icon_id'] : null;
      $sort = (int)($row['sort_order'] ?? $i);

      // Require value + DE label
      if ($value === '' || $label === '') { $i++; continue; }

      if ($itemId > 0 && in_array($itemId, $existingIds, true)) {
        $upd->execute([$value, $label, $labelEn, $iconId, $sort, $itemId, $id]);
        $keepIds[] = $itemId;
      } else {
        $ins->execute([$id, $value, $label, $labelEn, $iconId, $sort]);
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
    $optionsObjWithIds = options_object_from_db($pdo, $id);

    // IMPORTANT: options_json stored in template_fields must now contain per-language labels
    $optionsObj = ['options' => []];
    foreach ($optionsObjWithIds['options'] as $o) {
      $optionsObj['options'][] = [
        'value' => (string)($o['value'] ?? ''),
        'label' => (string)($o['label'] ?? ''),
        'label_en' => (string)($o['label_en'] ?? ''), // NEW
        'icon_id' => ($o['icon_id'] ?? null),
      ];
    }
    $optionsJson = json_encode($optionsObj, JSON_UNESCAPED_UNICODE);

    $templateFieldIds = template_field_ids_for_option_list($pdo, $id);
    $updFieldOpt = $pdo->prepare("UPDATE template_fields SET options_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $updFieldMeta = $pdo->prepare("UPDATE template_fields SET meta_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $updatedCount = 0;
    foreach ($templateFieldIds as $tfId) {
      $updFieldOpt->execute([$optionsJson, $tfId]);
      $updatedCount++;
    }

    // Re-run meta cache sync in one pass with proper fetch
    if ($templateFieldIds) {
      $in2 = implode(',', array_fill(0, count($templateFieldIds), '?'));
      $st = $pdo->prepare("SELECT id, meta_json FROM template_fields WHERE id IN ($in2)");
      $st->execute($templateFieldIds);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $meta = json_decode_assoc($r['meta_json'] ?? null);
        if (!$meta) continue;
        $changedMeta = false;

        // Keep meta caches in sync too
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
    }

    // --- update already stored field_values (migrate old value_text to new current values)
    $newIdToValue = [];
    foreach ($optionsObjWithIds['options'] as $o) {
      $newIdToValue[(int)$o['id']] = (string)($o['value'] ?? '');
    }

    // Only run migration when at least one value actually changed.
    $valueChanged = false;
    foreach ($oldIdToValue as $oid => $oval) {
      if (!isset($newIdToValue[$oid])) continue;
      if ((string)$oval !== (string)$newIdToValue[$oid]) { $valueChanged = true; break; }
    }

    $fieldValueStats = ['updated_value_text'=>0, 'updated_value_json'=>0];
    if ($valueChanged && $templateFieldIds) {
      $pdo->beginTransaction();
      $fieldValueStats = update_field_values_for_option_list_changes($pdo, $templateFieldIds, $id, $oldIdToValue, $newIdToValue);
      $pdo->commit();
    }

    audit('option_list_save', (int)current_user()['id'], [
      'list_id'=>$id,
      'items_saved'=>count($keepIds),
      'template_fields_updated'=>$updatedCount,
      'field_values_updated_value_text'=>(int)$fieldValueStats['updated_value_text'],
      'field_values_updated_value_json'=>(int)$fieldValueStats['updated_value_json'],
    ]);

    echo json_encode([
      'ok'=>true,
      'template_fields_updated'=>$updatedCount,
      'field_values_updated_value_text'=>(int)$fieldValueStats['updated_value_text'],
      'field_values_updated_value_json'=>(int)$fieldValueStats['updated_value_json'],
    ], JSON_UNESCAPED_UNICODE);
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

    // NEW: label_en
    $items = $pdo->prepare("SELECT value, label, label_en, icon_id, sort_order, meta_json FROM option_list_items WHERE list_id=? ORDER BY sort_order ASC, id ASC");
    $items->execute([$id]);
    $rows = $items->fetchAll();

    // NEW: label_en
    $ins = $pdo->prepare("INSERT INTO option_list_items (list_id, value, label, label_en, icon_id, sort_order, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
      $ins->execute([
        $newId,
        (string)$r['value'],
        (string)$r['label'],
        (string)($r['label_en'] ?? ''),
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
