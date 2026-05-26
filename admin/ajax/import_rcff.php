<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

function fail_rcff(string $msg, int $code = 400): never { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
  csrf_verify();
  $templateId = (int)($_POST['template_id'] ?? 0);
  if ($templateId <= 0) fail_rcff('template_id fehlt.');
  if (!isset($_FILES['rcff']) || (int)($_FILES['rcff']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail_rcff('RCFF-Datei fehlt.');
  $file = $_FILES['rcff'];
  $name = (string)($file['name'] ?? '');
  if (!preg_match('/\.rcff$/i', $name)) fail_rcff('Nur .rcff-Dateien erlaubt.');
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) fail_rcff('Ungültige Upload-Datei.');
  if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) fail_rcff('RCFF-Datei zu groß (max. 2MB).');
  $json = (string)file_get_contents($tmp);
  $data = json_decode($json, true);
  if (!is_array($data)) fail_rcff('Ungültiges JSON.');
  if (($data['format'] ?? '') !== 'rcff') fail_rcff('Ungültiges RCFF-Format.');
  if ((int)($data['version'] ?? 0) !== 1) fail_rcff('Nicht unterstützte RCFF-Version.');
  if (!is_array($data['fields'] ?? null)) fail_rcff('RCFF fields fehlen.');

  $pdo = db();
  $cols = $pdo->query('SHOW COLUMNS FROM template_fields')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $columnNames = [];
  foreach ($cols as $c) { $columnNames[(string)($c['Field'] ?? '')] = true; }
  $hasGroupLabel = isset($columnNames['group_label']);
  $hasGroupLabelEn = isset($columnNames['group_label_en']);
  $hasSubgroupLabel = isset($columnNames['subgroup_label']);
  $hasSubgroupLabelEn = isset($columnNames['subgroup_label_en']);

  $selectFields = 'id, field_name, label, label_en, meta_json';
  if ($hasGroupLabel) $selectFields .= ', group_label';
  if ($hasGroupLabelEn) $selectFields .= ', group_label_en';
  if ($hasSubgroupLabel) $selectFields .= ', subgroup_label';
  if ($hasSubgroupLabelEn) $selectFields .= ', subgroup_label_en';

  $st = $pdo->prepare("SELECT $selectFields FROM template_fields WHERE template_id=?");
  $st->execute([$templateId]);
  $dbRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $byName = [];
  foreach ($dbRows as $r) $byName[(string)$r['field_name']] = $r;

  $stats = ['read'=>0,'matched'=>0,'updated'=>0,'labels_updated'=>0,'groups_updated'=>0,'subgroups_updated'=>0,'meta_updated'=>0,'ignored'=>0,'skipped'=>0];
  foreach ($data['fields'] as $f) {
    $stats['read']++;
    if (!is_array($f)) { $stats['skipped']++; continue; }
    $fname = trim((string)($f['field_name'] ?? ''));
    if ($fname === '') { $stats['skipped']++; continue; }
    if (!isset($byName[$fname])) { $stats['ignored']++; continue; }
    $stats['matched']++;
    $row = $byName[$fname];
    $labelDe = trim((string)($f['label_de'] ?? ''));
    $labelEn = trim((string)($f['label_en'] ?? ''));
    $label = $labelDe !== '' ? $labelDe : ((string)$row['label']);
    $labelEnOut = $labelEn !== '' ? $labelEn : ((string)$row['label_en']);
    $categoryDe = trim((string)($f['category_de'] ?? ''));
    $categoryEn = trim((string)($f['category_en'] ?? ''));
    $subcategoryDe = trim((string)($f['subcategory_de'] ?? ''));
    $subcategoryEn = trim((string)($f['subcategory_en'] ?? ''));
    $labelChanged = ($label !== (string)$row['label']) || ($labelEnOut !== (string)$row['label_en']);
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) $meta = [];
    $metaBefore = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $meta['rcff'] = [
      'type' => (string)($f['type'] ?? 'unknown'),
      'category_id' => isset($f['category_id']) ? (int)$f['category_id'] : 0,
      'label_de' => $labelDe,
      'label_en' => $labelEn,
      'category_de' => $categoryDe,
      'category_en' => $categoryEn,
      'subcategory_id' => isset($f['subcategory_id']) ? (int)$f['subcategory_id'] : 0,
      'subcategory_de' => $subcategoryDe,
      'subcategory_en' => $subcategoryEn,
      'competency_code' => (string)($f['competency_code'] ?? ''),
      'competency_id' => isset($f['competency_id']) ? (int)$f['competency_id'] : 0,
      'role' => (string)($f['role'] ?? ''),
    ];
    $groupPath = '';
    if ($categoryDe !== '' && $subcategoryDe !== '') $groupPath = $categoryDe . ' / ' . $subcategoryDe;
    elseif ($categoryDe !== '') $groupPath = $categoryDe;
    if ($groupPath !== '') $meta['group'] = $groupPath;
    $groupTitleEn = $categoryEn;
    $subgroupTitleEn = $subcategoryEn;
    if ($groupTitleEn !== '') $meta['group_title_en'] = $groupTitleEn;
    if ($subgroupTitleEn !== '') $meta['subgroup_title_en'] = $subgroupTitleEn;

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $metaChanged = ($metaJson !== $metaBefore);
    $groupChanged = false;
    $subgroupChanged = false;
    $sql = 'UPDATE template_fields SET label=?, label_en=?, meta_json=?';
    $params = [$label, $labelEnOut, $metaJson];
    if ($hasGroupLabel) {
      $groupValue = $categoryDe !== '' ? $categoryDe : (string)($row['group_label'] ?? '');
      $groupChanged = $groupChanged || ($groupValue !== (string)($row['group_label'] ?? ''));
      $sql .= ', group_label=?';
      $params[] = $groupValue;
    } elseif ($categoryDe !== '' || $categoryEn !== '' || $groupPath !== '') {
      $groupChanged = true;
    }
    if ($hasGroupLabelEn) {
      $groupValueEn = $categoryEn !== '' ? $categoryEn : (string)($row['group_label_en'] ?? '');
      $groupChanged = $groupChanged || ($groupValueEn !== (string)($row['group_label_en'] ?? ''));
      $sql .= ', group_label_en=?';
      $params[] = $groupValueEn;
    }
    if ($hasSubgroupLabel) {
      $subgroupValue = $subcategoryDe !== '' ? $subcategoryDe : (string)($row['subgroup_label'] ?? '');
      $subgroupChanged = $subgroupChanged || ($subgroupValue !== (string)($row['subgroup_label'] ?? ''));
      $sql .= ', subgroup_label=?';
      $params[] = $subgroupValue;
    } elseif ($subcategoryDe !== '' || $subcategoryEn !== '' || isset($f['subcategory_id'])) {
      $subgroupChanged = true;
    }
    if ($hasSubgroupLabelEn) {
      $subgroupValueEn = $subcategoryEn !== '' ? $subcategoryEn : (string)($row['subgroup_label_en'] ?? '');
      $subgroupChanged = $subgroupChanged || ($subgroupValueEn !== (string)($row['subgroup_label_en'] ?? ''));
      $sql .= ', subgroup_label_en=?';
      $params[] = $subgroupValueEn;
    }
    $sql .= ', updated_at=CURRENT_TIMESTAMP WHERE id=?';
    $params[] = (int)$row['id'];
    $upd = $pdo->prepare($sql);
    $upd->execute($params);
    $stats['updated']++;
    if ($labelChanged) $stats['labels_updated']++;
    if ($groupChanged) $stats['groups_updated']++;
    if ($subgroupChanged) $stats['subgroups_updated']++;
    if ($metaChanged) $stats['meta_updated']++;
  }
  audit('template_fields_rcff_import', (int)current_user()['id'], ['template_id'=>$templateId,'stats'=>$stats]);
  echo json_encode(['ok'=>true,'stats'=>$stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  fail_rcff($e->getMessage());
}
