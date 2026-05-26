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
  $st = $pdo->prepare('SELECT id, field_name, label, label_en, meta_json FROM template_fields WHERE template_id=?');
  $st->execute([$templateId]);
  $dbRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $byName = [];
  foreach ($dbRows as $r) $byName[(string)$r['field_name']] = $r;

  $stats = ['read'=>0,'matched'=>0,'updated'=>0,'ignored'=>0,'skipped'=>0];
  $upd = $pdo->prepare('UPDATE template_fields SET label=?, label_en=?, meta_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
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
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) $meta = [];
    $meta['rcff'] = [
      'type' => (string)($f['type'] ?? 'unknown'),
      'label_de' => $labelDe,
      'label_en' => $labelEn,
      'category_de' => (string)($f['category_de'] ?? ''),
      'category_en' => (string)($f['category_en'] ?? ''),
      'subcategory_de' => (string)($f['subcategory_de'] ?? ''),
      'subcategory_en' => (string)($f['subcategory_en'] ?? ''),
      'competency_code' => (string)($f['competency_code'] ?? ''),
      'role' => (string)($f['role'] ?? ''),
    ];
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $upd->execute([$label, $labelEnOut, $metaJson, (int)$row['id']]);
    $stats['updated']++;
  }
  audit('template_fields_rcff_import', (int)current_user()['id'], ['template_id'=>$templateId,'stats'=>$stats]);
  echo json_encode(['ok'=>true,'stats'=>$stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  fail_rcff($e->getMessage());
}
