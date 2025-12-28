<?php
// admin/ajax/templates_replace_pdf.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function ensure_dir(string $p): void {
  if (!is_dir($p)) @mkdir($p, 0755, true);
}

try {
  csrf_verify();

  $templateId = (int)($_POST['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException('template_id fehlt/ungültig.');

  if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Bitte eine PDF auswählen.');
  }

  $tmp = (string)($_FILES['pdf']['tmp_name'] ?? '');
  $origName = (string)($_FILES['pdf']['name'] ?? 'template.pdf');
  if ($origName === '' || !preg_match('/\.pdf$/i', $origName)) {
    throw new RuntimeException('Datei ist keine PDF (.pdf).');
  }

  $pdo = db();
  $exists = $pdo->prepare('SELECT id FROM templates WHERE id=? LIMIT 1');
  $exists->execute([$templateId]);
  if (!$exists->fetch()) throw new RuntimeException('Template nicht gefunden.');

  $sha = hash_file('sha256', $tmp) ?: null;

  $cfg = app_config();
  $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
  $rootAbs = realpath(__DIR__ . '/../..');
  if (!$rootAbs) throw new RuntimeException('Root-Pfad konnte nicht ermittelt werden.');
  $uploadsAbs = $rootAbs . '/' . $uploadsRel;

  ensure_dir($uploadsAbs);
  ensure_dir($uploadsAbs . '/templates');

  $tplDirAbs = $uploadsAbs . '/templates/' . $templateId;
  ensure_dir($tplDirAbs);

  $safeBase = preg_replace('/[^a-z0-9._-]+/i', '_', pathinfo($origName, PATHINFO_FILENAME));
  if ($safeBase === '' || $safeBase === '_') $safeBase = 'template';

  $destAbs = $tplDirAbs . '/' . $safeBase . '_repl_' . date('Ymd_His') . '.pdf';
  $destRel = $uploadsRel . '/templates/' . $templateId . '/' . basename($destAbs);

  if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException('PDF konnte nicht gespeichert werden.');

  $upd = $pdo->prepare('UPDATE templates SET pdf_storage_path=?, pdf_original_filename=?, pdf_sha256=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
  $upd->execute([$destRel, $origName, $sha, $templateId]);

  audit('template_pdf_replace', (int)current_user()['id'], ['template_id' => $templateId]);

  $pdfUrl = url('admin/file.php?template_id=' . $templateId);
  echo json_encode(['ok' => true, 'pdf_url' => $pdfUrl, 'template_id' => $templateId, 'filename' => $origName], JSON_UNESCAPED_UNICODE);
  exit;
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
